<?php
declare(strict_types=1);

namespace FazendoEmCasa\Cms\Editor;

require_once dirname(__DIR__) . '/_deny-direct.php';

use FazendoEmCasa\Cms\Core\Db;
use FazendoEmCasa\Cms\Core\Json;

/**
 * Rascunhos por página.
 *
 * Um rascunho guarda apenas o delta (`element_id => novo_valor`). Cada
 * página tem no máximo UM rascunho `open`; editar de novo atualiza esse
 * rascunho em vez de criar outro. Isso evita rascunhos concorrentes
 * silenciosos.
 *
 * Nada aqui escreve em disco. O rascunho só vira ficheiro na FASE 6,
 * com backup e AtomicWriter.
 */
final class DraftService
{
    public function __construct(private readonly PatchValidator $validator = new PatchValidator())
    {
    }

    /**
     * Abre ou atualiza o rascunho da página com o patch dado.
     *
     * @param array<string, mixed> $patch
     * @return array{ok: bool, errors: list<string>, draft: array<string, mixed>|null, changed: int}
     */
    public function save(int $projectId, int $pageId, array $patch, ?int $userId = null): array
    {
        $elements = $this->pageElements($pageId, $projectId);
        if ($elements === null) {
            return ['ok' => false, 'errors' => ['Página não encontrada neste projeto.'], 'draft' => null, 'changed' => 0];
        }

        $result = $this->validator->validate($patch, $elements);
        if (!$result['ok']) {
            return ['ok' => false, 'errors' => $result['errors'], 'draft' => null, 'changed' => 0];
        }

        $draft = $this->openDraft($pageId);

        // Mescla com o patch já existente: editar um campo não descarta
        // as alterações anteriores de outros campos.
        $merged = $draft !== null ? $this->decodePatch((string) $draft['patch_json']) : [];
        foreach ($result['clean'] as $id => $value) {
            $merged[$id] = $value;
        }

        $json = Json::encode($merged);

        if ($draft === null) {
            Db::exec(
                'INSERT INTO cms_drafts (project_id, page_id, user_id, patch_json, status, created_at, updated_at)
                 VALUES (:pid, :pg, :u, :patch, \'open\', NOW(), NOW())',
                ['pid' => $projectId, 'pg' => $pageId, 'u' => $userId, 'patch' => $json]
            );
        } else {
            Db::exec(
                'UPDATE cms_drafts SET patch_json = :patch, updated_at = NOW() WHERE id = :id',
                ['patch' => $json, 'id' => (int) $draft['id']]
            );
        }

        return [
            'ok' => true,
            'errors' => [],
            'draft' => $this->openDraft($pageId),
            'changed' => count($merged),
        ];
    }

    /**
     * Remove um elemento do rascunho (descartar apenas esse campo).
     */
    public function discardElement(int $pageId, int $elementId): bool
    {
        $draft = $this->openDraft($pageId);
        if ($draft === null) {
            return false;
        }

        $merged = $this->decodePatch((string) $draft['patch_json']);
        if (!isset($merged[$elementId])) {
            return false;
        }

        unset($merged[$elementId]);

        if ($merged === []) {
            $this->discardPage($pageId);
            return true;
        }

        Db::exec(
            'UPDATE cms_drafts SET patch_json = :patch, updated_at = NOW() WHERE id = :id',
            ['patch' => Json::encode($merged), 'id' => (int) $draft['id']]
        );
        return true;
    }

    /** Descarta o rascunho inteiro da página. */
    public function discardPage(int $pageId): bool
    {
        return Db::exec(
            'DELETE FROM cms_drafts WHERE page_id = :pg AND status = \'open\'',
            ['pg' => $pageId]
        ) > 0;
    }

    /** @return array<string, mixed>|null */
    public function openDraft(int $pageId): ?array
    {
        return Db::one(
            'SELECT * FROM cms_drafts WHERE page_id = :pg AND status = \'open\'
             ORDER BY id DESC LIMIT 1',
            ['pg' => $pageId]
        );
    }

    /**
     * Patch decodificado do rascunho aberto, indexado por element_id.
     *
     * @return array<int, string>
     */
    public function patchFor(int $pageId): array
    {
        $draft = $this->openDraft($pageId);
        if ($draft === null) {
            return [];
        }
        return $this->decodePatch((string) $draft['patch_json']);
    }

    /**
     * Resumo de rascunhos por página, para a UI listar pendências.
     *
     * @return array<int, array{count:int, updated_at:string}> indexado por page_id
     */
    public function openDraftsByPage(int $projectId): array
    {
        $out = [];
        foreach (Db::all(
            'SELECT page_id, patch_json, updated_at FROM cms_drafts
             WHERE project_id = :p AND status = \'open\'',
            ['p' => $projectId]
        ) as $row) {
            $patch = $this->decodePatch((string) $row['patch_json']);
            $out[(int) $row['page_id']] = [
                'count' => count($patch),
                'updated_at' => (string) $row['updated_at'],
            ];
        }
        return $out;
    }

    /**
     * Elementos da página, indexados por id, validando o projeto.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function pageElements(int $pageId, int $projectId): ?array
    {
        $page = Db::one(
            'SELECT id FROM cms_pages WHERE id = :pg AND project_id = :p LIMIT 1',
            ['pg' => $pageId, 'p' => $projectId]
        );
        if ($page === null) {
            return null;
        }

        $indexed = [];
        foreach (Db::all(
            'SELECT id, editability, type, selector, cms_id FROM cms_elements WHERE page_id = :pg',
            ['pg' => $pageId]
        ) as $row) {
            $indexed[(int) $row['id']] = $row;
        }
        return $indexed;
    }

    /** @return array<int, string> */
    private function decodePatch(string $raw): array
    {
        $decoded = Json::decodeObject($raw);
        if ($decoded === null) {
            return [];
        }
        $out = [];
        foreach ($decoded as $key => $value) {
            $out[(int) $key] = (string) $value;
        }
        return $out;
    }
}
