<?php
declare(strict_types=1);

namespace FazendoEmCasa\Cms\Editor;

require_once dirname(__DIR__) . '/_deny-direct.php';

use FazendoEmCasa\Cms\Core\Db;
use FazendoEmCasa\Cms\Projects\ProjectRepository;

/**
 * Monta a pré-visualização de uma página com o rascunho aplicado.
 *
 * Lê o ficheiro original, aplica o patch em memória e devolve o HTML.
 * NUNCA escreve no disco. O caminho vem do banco e é validado contra a
 * raiz do projeto antes de qualquer leitura.
 */
final class PreviewService
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly DraftService $drafts = new DraftService(),
        private readonly PreviewRenderer $renderer = new PreviewRenderer(),
    ) {
    }

    /**
     * @return array{ok: bool, html: string, error: ?string, applied: int, kind: string}
     */
    public function preview(int $projectId, int $pageId): array
    {
        $page = Db::one(
            'SELECT id, rel_path, kind FROM cms_pages WHERE id = :pg AND project_id = :p LIMIT 1',
            ['pg' => $pageId, 'p' => $projectId]
        );

        if ($page === null) {
            return ['ok' => false, 'html' => '', 'error' => 'Página não encontrada.', 'applied' => 0, 'kind' => ''];
        }

        $kind = (string) $page['kind'];
        if ($kind !== 'html') {
            return [
                'ok' => false,
                'html' => '',
                'error' => 'Pré-visualização disponível apenas para páginas HTML.',
                'applied' => 0,
                'kind' => $kind,
            ];
        }

        $abs = $this->resolvePageFile($projectId, (string) $page['rel_path']);
        if ($abs === null) {
            return ['ok' => false, 'html' => '', 'error' => 'Ficheiro inacessível.', 'applied' => 0, 'kind' => $kind];
        }

        $raw = @file_get_contents($abs);
        if ($raw === false) {
            return ['ok' => false, 'html' => '', 'error' => 'Falha ao ler o ficheiro.', 'applied' => 0, 'kind' => $kind];
        }

        $patch = $this->drafts->patchFor($pageId);
        if ($patch === []) {
            return ['ok' => true, 'html' => $raw, 'error' => null, 'applied' => 0, 'kind' => $kind];
        }

        $elements = [];
        foreach (Db::all(
            'SELECT id, cms_id, type FROM cms_elements WHERE page_id = :pg',
            ['pg' => $pageId]
        ) as $row) {
            $elements[(int) $row['id']] = $row;
        }

        $html = $this->renderer->render($raw, $patch, $elements);

        return [
            'ok' => true,
            'html' => $html,
            'error' => null,
            'applied' => count($patch),
            'kind' => $kind,
        ];
    }

    /**
     * Resolve o caminho absoluto do ficheiro da página, garantindo que
     * fica dentro do diretório do projeto.
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
