<?php
declare(strict_types=1);

namespace FazendoEmCasa\Cms\Scanner;

require_once dirname(__DIR__) . '/_deny-direct.php';

use FazendoEmCasa\Cms\Core\Db;
use FazendoEmCasa\Cms\Projects\ProjectRepository;

/**
 * Fachada do scanner: resolve o projeto, varre e persiste.
 *
 * É o único ponto que a API e a UI chamam.
 */
final class ScanService
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly ProjectScanner $scanner = new ProjectScanner(),
        private readonly ScanPersister $persister = new ScanPersister(),
    ) {
    }

    /**
     * @return array{found:int, changed:int, removed:int, unchanged:int, scan_id:int}
     */
    public function scanProject(int $projectId): array
    {
        $project = $this->projects->find($projectId);
        if ($project === null) {
            throw new ScanException('Projeto não encontrado.');
        }

        // resolveProjectDir valida isolamento e status ativo.
        $dir = $this->projects->resolveProjectDir($projectId);
        $rel = (string) $project['rel_path'];

        $results = $this->scanner->scan($dir, $rel);
        return $this->persister->persist($projectId, $results);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pages(int $projectId, ?string $kind = null): array
    {
        $sql = 'SELECT * FROM cms_pages WHERE project_id = :p';
        $params = ['p' => $projectId];
        if ($kind !== null && $kind !== '') {
            $sql .= ' AND kind = :k';
            $params['k'] = $kind;
        }
        $sql .= ' ORDER BY is_entrypoint DESC, rel_path ASC';
        return Db::all($sql, $params);
    }

    /** @return array<string, mixed>|null */
    public function page(int $projectId, int $pageId): ?array
    {
        return Db::one(
            'SELECT * FROM cms_pages WHERE id = :id AND project_id = :p LIMIT 1',
            ['id' => $pageId, 'p' => $projectId]
        );
    }

    /** @return array<string, mixed>|null */
    public function lastScan(int $projectId): ?array
    {
        return Db::one(
            'SELECT * FROM cms_scans WHERE project_id = :p ORDER BY id DESC LIMIT 1',
            ['p' => $projectId]
        );
    }

    /**
     * Resumo agregado para os cards da Central.
     *
     * @return array{pages:int, elements:int, images:int, last_scan_at:?string}
     */
    public function summary(int $projectId): array
    {
        $row = Db::one(
            'SELECT COUNT(*) AS pages,
                    COALESCE(SUM(element_count), 0) AS elements,
                    COALESCE(SUM(image_count), 0) AS images
             FROM cms_pages WHERE project_id = :p',
            ['p' => $projectId]
        );
        $project = Db::one('SELECT last_scan_at FROM cms_projects WHERE id = :p', ['p' => $projectId]);

        return [
            'pages' => (int) ($row['pages'] ?? 0),
            'elements' => (int) ($row['elements'] ?? 0),
            'images' => (int) ($row['images'] ?? 0),
            'last_scan_at' => $project['last_scan_at'] ?? null,
        ];
    }
}
