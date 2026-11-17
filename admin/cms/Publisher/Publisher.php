<?php
declare(strict_types=1);

namespace FazendoEmCasa\Cms\Publisher;

require_once dirname(__DIR__) . '/_deny-direct.php';

use FazendoEmCasa\Cms\Core\Db;
use FazendoEmCasa\Cms\Editor\DraftService;
use FazendoEmCasa\Cms\Editor\PreviewRenderer;
use FazendoEmCasa\Cms\History\AuditLog;
use FazendoEmCasa\Cms\History\VersionLog;
use FazendoEmCasa\Cms\Projects\ProjectRepository;

/**
 * Publicação de rascunhos.
 *
 * Fluxo, na ordem exata:
 *   1. valida o patch do rascunho (nada é aplicado em parte);
 *   2. lê o ficheiro e aplica o patch em memória (mesmo mecanismo do
 *      preview — o que se vê é o que se publica);
 *   3. backup obrigatório do conteúdo atual;
 *   4. escrita atómica com verificação pós-escrita (hash + re-parse);
 *   5. qualquer falha restaura o backup automaticamente.
 *
 * Se o HTML pós-escrita não fizer parse, ou o hash não bater, a
 * publicação é revertida e registada como rolled_back.
 */
class Publisher
{
    /** Tempo máximo que um lock de publicação pode ficar preso (s). */
    private const LOCK_TIMEOUT = 30;

    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly DraftService $drafts = new DraftService(),
        private readonly PreviewRenderer $renderer = new PreviewRenderer(),
        private readonly BackupService $backups = new BackupService(),
        private readonly AtomicWriter $writer = new AtomicWriter(),
    ) {
    }

    /**
     * Publica o rascunho aberto da página.
     *
     * @return array{ok: bool, error: ?string, status: string, publicationId: ?int, changed: int, restored: bool}
     */
    public function publish(int $projectId, int $pageId, ?int $userId = null): array
    {
        $page = Db::one(
            'SELECT id, rel_path, kind FROM cms_pages WHERE id = :pg AND project_id = :p LIMIT 1',
            ['pg' => $pageId, 'p' => $projectId]
        );
        if ($page === null) {
            return $this->fail('Página não encontrada neste projeto.');
        }

        if ((string) $page['kind'] !== 'html') {
            return $this->fail('Publicação disponível apenas para páginas HTML.');
        }

        $patch = $this->drafts->patchFor($pageId);
        if ($patch === []) {
            return $this->fail('Nenhum rascunho aberto para esta página.');
        }

        // Lock de publicação: dois pedidos na mesma página publicariam
        // em paralelo e o último rename venceria, perdendo alterações
        // do primeiro. UPDATE condicional = exclusão mútua entre
        // processos, com expiração automática para locks órfãos.
        $lockToken = bin2hex(random_bytes(8));
        if (!$this->acquireLock($projectId, $pageId, $lockToken)) {
            return $this->fail('Outra publicação desta página está em curso. Tente de novo em instantes.');
        }

        try {
            return $this->publishLocked($projectId, $pageId, $userId, $patch);
        } finally {
            $this->releaseLock($projectId, $pageId, $lockToken);
        }
    }

    /**
     * Corpo da publicação, já sob lock.
     *
     * @param array<int, string> $patch
     * @return array{ok: bool, error: ?string, status: string, publicationId: ?int, changed: int, restored: bool}
     */
    private function publishLocked(int $projectId, int $pageId, ?int $userId, array $patch): array
    {
        $page = Db::one(
            'SELECT id, rel_path, kind FROM cms_pages WHERE id = :pg AND project_id = :p LIMIT 1',
            ['pg' => $pageId, 'p' => $projectId]
        );
        if ($page === null) {
            return $this->fail('Página não encontrada neste projeto.');
        }

        if ((string) $page['kind'] !== 'html') {
            return $this->fail('Publicação disponível apenas para páginas HTML.');
        }

        $abs = $this->resolvePageFile($projectId, (string) $page['rel_path']);
        if ($abs === null) {
            return $this->fail('Ficheiro da página inacessível.');
        }

        $original = (string) @file_get_contents($abs);

        // 1. O patch foi validado ao gravar; revalida com os elementos
        //    atuais para bloquear níveis que mudaram desde então.
        $elements = [];
        foreach (Db::all(
            'SELECT id, cms_id, type, editability, value_current FROM cms_elements WHERE page_id = :pg',
            ['pg' => $pageId]
        ) as $row) {
            $elements[(int) $row['id']] = $row;
        }

        $validator = new \FazendoEmCasa\Cms\Editor\PatchValidator();
        $check = $validator->validate($patch, $elements);
        if (!$check['ok']) {
            return $this->fail('Patch inválido: ' . implode(' ', $check['errors']));
        }
        $patch = $check['clean'];

        // 2. Render em memória — o mesmo mecanismo do preview.
        $rendered = $this->renderer->render($original, $patch, $elements);
        if ($rendered === $original) {
            return $this->fail('O patch não alterou nada no ficheiro.');
        }

        // 3. Backup obrigatório.
        $backup = $this->backups->snapshot(
            $projectId,
            $pageId,
            $abs,
            (string) $page['rel_path']
        );

        // 4. Verificação pós-escrita: o novo HTML tem de fazer parse e
        //    conter os elementos com os novos valores.
        $verify = function (string $content) use ($elements, $patch): bool {
            return $this->postVerify($content, $elements, $patch);
        };

        $write = $this->writer->write($abs, $rendered, $verify);
        $status = 'applied';
        $restored = false;
        $error = $write['error'];

        if (!$write['ok']) {
            // Se a falha ocorreu depois do rename (hash pós-escrita),
            // restaura imediatamente. Se era ficheiro novo, remove-o.
            if ($backup['id'] !== null) {
                $restored = $this->backups->restore((int) $backup['id'], $abs);
            } else {
                $restored = !is_file($abs) || @unlink($abs);
            }
            $status = $restored ? 'rolled_back' : 'failed';
        }

        Db::exec(
            'INSERT INTO cms_publications
                (project_id, page_id, draft_id, user_id, status, elements_changed,
                 backup_id, hash_before, hash_after, error_message, created_at)
             VALUES (:p, :pg, NULL, :u, :st, :changed, :bk, :hb, :ha, :err, NOW())',
            [
                'p' => $projectId,
                'pg' => $pageId,
                'u' => $userId,
                'st' => $status,
                'changed' => $write['ok'] ? count($patch) : 0,
                'bk' => $backup['id'],
                'hb' => $backup['hash'],
                'ha' => $write['hash'],
                'err' => $error,
            ]
        );
        $publicationId = (int) Db::lastId();

        if (!$write['ok']) {
            return [
                'ok' => false,
                'error' => $error ?? 'Falha na publicação.',
                'status' => $status,
                'publicationId' => $publicationId,
                'changed' => 0,
                'restored' => $restored,
            ];
        }

        // Histórico: uma linha por elemento alterado, com o valor que
        // estava ANTES da publicação. Falha aqui não desfaz a escrita —
        // o histórico é complementar, não bloqueante.
        try {
            VersionLog::recordPublication($publicationId, $projectId, $pageId, $userId, $patch, $elements);
            AuditLog::record('publish.page', 'page', $pageId, $userId, $projectId, [
                'publication_id' => $publicationId,
                'elements_changed' => count($patch),
                'backup_id' => $backup['id'],
            ]);
        } catch (\Throwable) {
            // ignorado deliberadamente: publicação já confirmada.
        }

        // Só depois de escrita e verificação aprovadas o rascunho é
        // removido. Se algo falhar antes, permanece para nova tentativa.
        $this->drafts->discardPage($pageId);

        return ['ok' => true, 'error' => null, 'status' => 'applied', 'publicationId' => $publicationId, 'changed' => count($patch), 'restored' => false];
    }

    /**
     * Verificação pós-escrita: re-parseia o conteúdo novo e confirma
     * que cada elemento do patch tem o valor publicado.
     *
     * @param array<int, array<string, mixed>> $elements
     * @param array<int, string> $patch
     */
    protected function postVerify(string $content, array $elements, array $patch): bool
    {
        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $loaded = @$doc->loadHTML(
            '<?xml encoding="utf-8" ?>' . $content,
            LIBXML_NONET | LIBXML_COMPACT
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return false;
        }

        $xpath = new \DOMXPath($doc);

        foreach ($patch as $elementId => $value) {
            $element = $elements[$elementId] ?? null;
            if ($element === null) {
                return false;
            }

            $cmsId = trim((string) ($element['cms_id'] ?? ''));
            $type = (string) ($element['type'] ?? 'paragraph');

            $node = $this->findByCmsId($xpath, $cmsId);
            if (!$node instanceof \DOMElement) {
                return false;
            }

            if ($type === 'background') {
                if (!$node->hasAttribute('src') && !str_contains($node->getAttribute('style'), $value)) {
                    return false;
                }
                continue;
            }

            if (in_array($type, ['image', 'logo', 'banner'], true)) {
                if ($node->getAttribute('src') !== $value) {
                    return false;
                }
                continue;
            }

            if ($type === 'link' && $node->hasAttribute('href')) {
                if ($node->getAttribute('href') !== $value) {
                    return false;
                }
                continue;
            }

            if ($node->textContent !== $value) {
                return false;
            }
        }

        return true;
    }

    private function findByCmsId(\DOMXPath $xpath, string $cmsId): ?\DOMElement
    {
        if ($cmsId === '') {
            return null;
        }
        foreach ($xpath->query('//*[@data-cms-id]') as $node) {
            if ($node instanceof \DOMElement
                && trim($node->getAttribute('data-cms-id')) === $cmsId) {
                return $node;
            }
        }
        return null;
    }

    /** @return array{ok: false, error: string, status: string, publicationId: null, changed: int, restored: bool} */
    private function fail(string $message): array
    {
        return ['ok' => false, 'error' => $message, 'status' => 'failed', 'publicationId' => null, 'changed' => 0, 'restored' => false];
    }

    /**
     * Tenta adquirir o lock de publicação da página.
     *
     * UPDATE condicional: só grava se o lock está livre OU expirou.
     * Se o valor retornado é 0, alguém saudável detém o lock.
     */
    private function acquireLock(int $projectId, int $pageId, string $token): bool
    {
        $key = $pageId . ':' . $token . ':' . time();

        // Garante a linha do lock para o projeto (idempotente).
        Db::exec(
            "INSERT IGNORE INTO cms_project_settings (project_id, setting_key, setting_value)
             VALUES (:p, 'publish_lock', '')",
            ['p' => $projectId]
        );

        // Lock livre OU expirado (crash no meio da publicação) pode ser
        // tomado. UPDATE condicional = exclusão mútua entre processos.
        Db::exec(
            "UPDATE cms_project_settings
             SET setting_value = :val, updated_at = NOW()
             WHERE project_id = :p AND setting_key = 'publish_lock'
               AND (setting_value = '' OR setting_value IS NULL
                    OR (setting_value LIKE CONCAT(:pageKey, '%')
                        AND CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(setting_value, ':', 3), ':', -1) AS UNSIGNED) < :now))",
            [
                'val' => $key,
                'p' => $projectId,
                'pageKey' => $pageId . ':',
                'now' => time() - self::LOCK_TIMEOUT,
            ]
        );

        $held = Db::one(
            "SELECT setting_value FROM cms_project_settings
             WHERE project_id = :p AND setting_key = 'publish_lock'",
            ['p' => $projectId]
        );

        return $held !== null && str_starts_with((string) $held['setting_value'], $pageId . ':' . $token . ':');
    }

    /** Liberta o lock se ainda somos o dono. */
    private function releaseLock(int $projectId, int $pageId, string $token): void
    {
        Db::exec(
            "UPDATE cms_project_settings
             SET setting_value = '', updated_at = NOW()
             WHERE project_id = :p AND setting_key = 'publish_lock'
               AND setting_value LIKE CONCAT(:pageKey, :token, ':%')",
            [
                'p' => $projectId,
                'pageKey' => $pageId . ':',
                'token' => $token,
            ]
        );
    }

    /**
     * Resolve o caminho absoluto do ficheiro da página, validado contra
     * a raiz do projeto (mesma regra do PreviewService).
     */
    private function resolvePageFile(int $projectId, string $relPath): ?string
    {
        $project = $this->projects->find($projectId);
        if ($project === null) {
            return null;
        }

        $dir = $this->projects->resolveProjectDir($projectId);
        $base = rtrim($dir, DIRECTORY_SEPARATOR);
        $prefix = trim((string) $project['rel_path'], '/');

        $inside = $relPath;
        if ($prefix !== '' && str_starts_with($relPath, $prefix . '/')) {
            $inside = substr($relPath, strlen($prefix) + 1);
        } elseif ($prefix !== '' && $relPath === $prefix) {
            return null;
        }

        if ($inside === '') {
            return null;
        }

        $candidate = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $inside);
        $real = realpath($candidate);

        if ($real === false || !is_file($real)) {
            return null;
        }
        if (!str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $real;
    }
}
