<?php
declare(strict_types=1);

namespace FazendoEmCasa\Cms\History;

require_once dirname(__DIR__) . '/_deny-direct.php';

use FazendoEmCasa\Cms\Core\Db;
use FazendoEmCasa\Cms\Projects\ProjectRepository;

/**
 * Restauração de estados anteriores.
 *
 * Dois níveis:
 *  - restoreBackup: volta o ficheiro inteiro ao conteúdo de um backup
 *    (usa o mesmo mecanismo do Publisher — backup antes, escrita
 *    atómica com verificação depois).
 *  - restoreElementVersion: aplica o old_value de uma versão como um
 *    rascunho novo — nunca escreve diretamente no site vivo.
 */
final class RestoreService
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly \FazendoEmCasa\Cms\Publisher\BackupService $backups = new \FazendoEmCasa\Cms\Publisher\BackupService(),
        private readonly \FazendoEmCasa\Cms\Publisher\AtomicWriter $writer = new \FazendoEmCasa\Cms\Publisher\AtomicWriter(),
    ) {
    }

    /** @return array{ok: bool, error: ?string, restored: bool, relPath: ?string, hash: ?string} */
    public function restoreBackup(int $projectId, int $backupId, ?int $userId = null): array
    {
        $backup = Db::one(
            'SELECT id, page_id, rel_path, content FROM cms_backups
             WHERE id = :id AND project_id = :p LIMIT 1',
            ['id' => $backupId, 'p' => $projectId]
        );
        if ($backup === null) {
            return ['ok' => false, 'error' => 'Backup não encontrado.', 'restored' => false, 'relPath' => null, 'hash' => null];
        }

        $relPath = (string) $backup['rel_path'];
        $abs = $this->resolveFile($projectId, $relPath);
        if ($abs === null) {
            return ['ok' => false, 'error' => 'Ficheiro original inacessível.', 'restored' => false, 'relPath' => null, 'hash' => null];
        }

        $content = (string) $backup['content'];
        if ($content === '') {
            return ['ok' => false, 'error' => 'Backup vazio.', 'restored' => false, 'relPath' => null, 'hash' => null];
        }

        // Snapshot antes de restaurar — nunca substituir sem rede de
        // segurança: se a restauração for um engano, há como voltar.
        $pre = $this->backups->snapshot($projectId, $backup['page_id'] !== null ? (int) $backup['page_id'] : null, $abs, $relPath, 'pre-restore');

        // Verificação pós-escrita: o conteúdo no disco tem de bater com
        // o hash do backup restaurado.
        $expectedHash = hash('sha256', $content);
        $verify = static fn (string $onDisk): bool => hash('sha256', $onDisk) === $expectedHash;

        $write = $this->writer->write($abs, $content, $verify);
        if (!$write['ok']) {
            // falha pós-rename → devolve o snapshot pré-restauração
            $restoredBack = $pre['id'] !== null
                ? $this->backups->restore((int) $pre['id'], $abs)
                : false;
            return [
                'ok' => false,
                'error' => $write['error'] ?? 'Falha ao restaurar.',
                'restored' => $restoredBack,
                'relPath' => $relPath,
                'hash' => null,
            ];
        }

        AuditLog::record('restore.backup', 'backup', $backupId, $userId, $projectId, [
            'rel_path' => $relPath,
            'hash' => $expectedHash,
            'pre_restore_backup_id' => $pre['id'],
        ]);

        return ['ok' => true, 'error' => null, 'restored' => true, 'relPath' => $relPath, 'hash' => $expectedHash];
    }

    /** @return array{ok: bool, error: ?string} */
    public function restoreElementVersion(int $projectId, int $versionId, ?int $userId = null): array
    {
        $version = Db::one(
            'SELECT v.*, p.project_id AS v_project
             FROM cms_element_versions v
             JOIN cms_pages p ON p.id = v.page_id
             WHERE v.id = :id LIMIT 1',
            ['id' => $versionId]
        );
        if ($version === null || (int) $version['v_project'] !== $projectId) {
            return ['ok' => false, 'error' => 'Versão não encontrada neste projeto.'];
        }

        $oldValue = (string) ($version['old_value'] ?? '');
        if ($oldValue === '') {
            return ['ok' => false, 'error' => 'Não há valor anterior registado nesta versão.'];
        }

        // Cria um rascunho com o valor antigo — o usuário revê e publica
        // pelo fluxo normal. Nunca escreve no site vivo diretamente.
        $drafts = new \FazendoEmCasa\Cms\Editor\DraftService();
        $drafts->save($projectId, (int) $version['page_id'], [(int) $version['element_id'] => $oldValue], $userId);

        AuditLog::record('restore.element', 'element_version', $versionId, $userId, $projectId, [
            'element_id' => (int) $version['element_id'],
            'page_id' => (int) $version['page_id'],
            'value' => $oldValue,
        ]);

        return ['ok' => true, 'error' => null];
    }

    private function resolveFile(int $projectId, string $relPath): ?string
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
