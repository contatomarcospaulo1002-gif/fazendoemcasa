<?php
declare(strict_types=1);

namespace FazendoEmCasa\Cms\Registry;

require_once dirname(__DIR__) . '/_deny-direct.php';

use FazendoEmCasa\Cms\Core\Db;
use FazendoEmCasa\Cms\Projects\ProjectRepository;
use FazendoEmCasa\Cms\Scanner\ScanService;

/**
 * Orquestra a classificação de uma página.
 *
 * Fluxo: varre (ou reaproveita o inventário) → aplica adaptadores →
 * deduplica → persiste. A classificação é sempre recalculada a partir
 * do ficheiro, nunca copiada do banco.
 */
final class RegistryService
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly ScanService $scanner,
        private readonly AdapterRegistry $adapters = new AdapterRegistry(),
        private readonly ElementRegistry $registry = new ElementRegistry(),
    ) {
    }

    /**
     * Classifica todas as páginas de um projeto.
     *
     * @return array{pages:int, elements:int, inserted:int, updated:int, pruned:int, byLevel:array<string,int>}
     */
    public function classifyProject(int $projectId): array
    {
        $project = $this->projects->find($projectId);
        if ($project === null) {
            throw new \RuntimeException('Projeto não encontrado.');
        }

        $dir = $this->projects->resolveProjectDir($projectId);
        $rel = (string) $project['rel_path'];

        $pages = Db::all(
            'SELECT id, kind, rel_path FROM cms_pages WHERE project_id = :p ORDER BY id ASC',
            ['p' => $projectId]
        );

        $totals = ['inserted' => 0, 'updated' => 0, 'pruned' => 0, 'elements' => 0, 'failed' => 0];

        foreach ($pages as $page) {
            $pageId = (int) $page['id'];
            $kind = (string) $page['kind'];
            $relPath = (string) $page['rel_path'];

            $abs = $this->resolveInside($dir, $relPath, $rel);
            if ($abs === null) {
                continue;
            }

            $analysis = $this->analyze($abs, $relPath, $kind);
            if ($analysis === null) {
                continue;
            }

            // Análise com erro NÃO pode apagar o registro existente.
            // Um ficheiro momentaneamente ilegível ou um HTML inválido
            // produziria zero elementos, e o prune apagaria tudo o que
            // já se sabia sobre a página. Nesse caso preservamos o que
            // existe e apenas contabilizamos a página como não analisada.
            if (($analysis['error'] ?? null) !== null) {
                $totals['failed']++;
                continue;
            }

            $elements = $this->extractDeduplicated($kind, $analysis);
            $stats = $this->registry->upsertPage($projectId, $pageId, $elements, true, $relPath);

            $totals['inserted'] += $stats['inserted'];
            $totals['updated'] += $stats['updated'];
            $totals['pruned'] += $stats['pruned'];
            $totals['elements'] += $stats['total'];
        }

        return [
            'pages' => count($pages),
            'elements' => $totals['elements'],
            'inserted' => $totals['inserted'],
            'updated' => $totals['updated'],
            'pruned' => $totals['pruned'],
            'failed' => $totals['failed'],
            'byLevel' => $this->registry->countsByLevel($projectId),
        ];
    }

    /**
     * Aplica todos os adaptadores do tipo e deduplica por selector.
     *
     * Regra: quando dois adaptadores produzem o mesmo selector, fica o
     * resultado mais PERMISSIVO, mas apenas se ambos provarem escrita.
     * Se um deles declara canWrite: false, prevalece o mais restritivo.
     *
     * @param array<string, mixed> $analysis
     * @return list<Element>
     */
    public function extractDeduplicated(string $kind, array $analysis): array
    {
        $bySelector = [];

        foreach ($this->adapters->forKind($kind) as $adapter) {
            foreach ($adapter->extract($analysis) as $element) {
                $key = $element->selector;
                $current = $bySelector[$key] ?? null;

                if ($current === null) {
                    $bySelector[$key] = $element;
                    continue;
                }

                $bySelector[$key] = $this->mostRestrictive($current, $element);
            }
        }

        return array_values($bySelector);
    }

    /**
     * De dois elementos com o mesmo selector, devolve o mais restritivo.
     * Empate mantém o primeiro (ordem de registo dos adaptadores).
     */
    private function mostRestrictive(Element $a, Element $b): Element
    {
        $rank = [
            Editability::READ_ONLY => 0,
            Editability::UNCONFIGURED => 1,
            Editability::NEEDS_ADAPTER => 2,
            Editability::EDITABLE => 3,
        ];

        $rankA = $rank[$a->editability] ?? 0;
        $rankB = $rank[$b->editability] ?? 0;

        return $rankA <= $rankB ? $a : $b;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function analyze(string $absPath, string $relPath, string $kind): ?array
    {
        if (!is_file($absPath)) {
            return null;
        }

        $result = match ($kind) {
            'html' => (new \FazendoEmCasa\Cms\Scanner\HtmlAnalyzer())->analyze($absPath, $relPath),
            'php' => (new \FazendoEmCasa\Cms\Scanner\PhpAnalyzer())->analyze($absPath, $relPath),
            'css' => (new \FazendoEmCasa\Cms\Scanner\AssetAnalyzer())->analyzeCss($absPath, $relPath),
            'json' => (new \FazendoEmCasa\Cms\Scanner\AssetAnalyzer())->analyzeJson($absPath, $relPath),
            default => null,
        };

        if ($result === null) {
            return null;
        }

        $data = $result->toArray();

        // Os adaptadores recebem o caminho de origem para auditoria.
        $data['origin_path'] = $relPath;
        foreach ($data['elements'] as $i => $el) {
            if (is_array($el)) {
                $data['elements'][$i]['origin_path'] = $relPath;
            }
        }

        return $data;
    }

    private function resolveInside(string $projectDir, string $relPath, string $projectRel): ?string
    {
        $base = rtrim($projectDir, DIRECTORY_SEPARATOR);
        $prefix = trim($projectRel, '/');

        $inside = $relPath;
        if ($prefix !== '' && str_starts_with($relPath, $prefix . '/')) {
            $inside = substr($relPath, strlen($prefix) + 1);
        } elseif ($prefix !== '' && $relPath === $prefix) {
            $inside = '';
        }

        if ($inside === '') {
            return null;
        }

        $candidate = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $inside);
        $real = realpath($candidate);

        if ($real === false) {
            return null;
        }
        if (!str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $real;
    }
}
