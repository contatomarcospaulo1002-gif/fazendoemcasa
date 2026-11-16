<?php
declare(strict_types=1);

namespace FazendoEmCasa\Cms\Projects;

require_once dirname(__DIR__) . '/_deny-direct.php';

use FazendoEmCasa\Cms\Core\PathGuard;

/**
 * Project Discovery Engine.
 *
 * Percorre APENAS a raiz autorizada, um nível de profundidade, e
 * identifica pastas que parecem projetos (têm um arquivo de entrada).
 *
 * Nunca:
 *  - sai da raiz autorizada;
 *  - segue symlink para fora;
 *  - entra em pastas da lista de exclusão;
 *  - cadastra nada automaticamente (só sugere).
 */
final class DiscoveryEngine
{
    /** Arquivos que caracterizam um projeto. */
    private const ENTRYPOINTS = ['index.html', 'index.php', 'index.htm'];

    /** @var list<string> */
    private array $exclusions;

    /**
     * @param list<string> $exclusions
     */
    public function __construct(
        private readonly PathGuard $guard,
        array $exclusions = []
    ) {
        $this->exclusions = array_map('strtolower', $exclusions);
    }

    /**
     * Varre a raiz e devolve candidatos a projeto.
     *
     * @return list<array{
     *   rel_path:string, name:string, entrypoint:string,
     *   has_assets:bool, asset_count:int, file_count:int,
     *   already_registered:bool, project_id:int|null
     * }>
     */
    public function scan(ProjectLookup $repo): array
    {
        $root = $this->guard->root();
        $entries = @scandir($root);
        if ($entries === false) {
            return [];
        }

        $candidates = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if ($this->isExcluded($entry)) {
                continue;
            }

            $abs = $root . DIRECTORY_SEPARATOR . $entry;
            if (!is_dir($abs)) {
                continue;
            }
            // Symlink que escapa da raiz é ignorado.
            if (is_link($abs)) {
                $real = realpath($abs);
                if ($real === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
                    continue;
                }
            }

            $entrypoint = $this->findEntrypoint($abs);
            if ($entrypoint === null) {
                continue;
            }

            $existing = $repo->findByPath($entry);
            $candidates[] = [
                'rel_path' => $entry,
                'name' => $this->humanize($entry),
                'entrypoint' => $entrypoint,
                'has_assets' => is_dir($abs . DIRECTORY_SEPARATOR . 'assets'),
                'asset_count' => $this->countFiles($abs . DIRECTORY_SEPARATOR . 'assets'),
                'file_count' => $this->countFiles($abs),
                'already_registered' => $existing !== null,
                'project_id' => $existing !== null ? (int) $existing['id'] : null,
            ];
        }

        usort($candidates, static fn (array $a, array $b): int => strcmp($a['rel_path'], $b['rel_path']));
        return $candidates;
    }

    private function isExcluded(string $name): bool
    {
        $lower = strtolower($name);
        if (str_starts_with($lower, '.')) {
            return true;
        }
        return in_array($lower, $this->exclusions, true);
    }

    private function findEntrypoint(string $dir): ?string
    {
        foreach (self::ENTRYPOINTS as $candidate) {
            if (is_file($dir . DIRECTORY_SEPARATOR . $candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    private function countFiles(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }
        $count = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $count++;
            }
        }
        return $count;
    }

    private function humanize(string $slug): string
    {
        $words = preg_split('/[-_]+/', $slug) ?: [];
        $words = array_filter($words, static fn (string $w): bool => $w !== '');
        $words = array_map(static fn (string $w): string => mb_convert_case($w, MB_CASE_TITLE, 'UTF-8'), $words);
        return implode(' ', $words);
    }
}
