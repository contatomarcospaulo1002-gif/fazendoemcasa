<?php
declare(strict_types=1);

namespace FazendoEmCasa\Cms\Scanner;

require_once dirname(__DIR__) . '/_deny-direct.php';

/**
 * Varredura de um projeto.
 *
 * Percorre somente o diretório resolvido pelo PathGuard. Não segue
 * symlink para fora, não entra em pastas ignoradas, não executa nada.
 */
final class ProjectScanner
{
    /** Extensões analisadas. */
    private const KINDS = [
        'html' => 'html',
        'htm' => 'html',
        'php' => 'php',
        'css' => 'css',
        'js' => 'js',
        'json' => 'json',
    ];

    /** Pastas ignoradas em qualquer nível. */
    private const SKIP_DIRS = [
        '.git', 'node_modules', 'vendor', '__pycache__', '.idea', '.vscode',
        'backups', 'tmp', 'cache', '.cache',
    ];

    /** Tamanho máximo analisado por arquivo (bytes). */
    private const MAX_BYTES = 8 * 1024 * 1024;

    public function __construct(
        private readonly HtmlAnalyzer $html = new HtmlAnalyzer(),
        private readonly PhpAnalyzer $php = new PhpAnalyzer(),
        private readonly AssetAnalyzer $assets = new AssetAnalyzer(),
    ) {
    }

    /**
     * Varre o diretório e devolve os resultados, na ordem de caminho.
     *
     * @return list<AnalysisResult>
     */
    public function scan(string $projectDir, string $projectRelPath): array
    {
        $results = [];
        $base = rtrim($projectDir, DIRECTORY_SEPARATOR);
        $prefix = trim($projectRelPath, '/');

        $it = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
                function (\SplFileInfo $file): bool {
                    if ($file->isDir()) {
                        return !in_array(strtolower($file->getFilename()), self::SKIP_DIRS, true);
                    }
                    return true;
                }
            )
        );

        foreach ($it as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            // Não segue symlink que aponte para fora do projeto.
            if ($file->isLink()) {
                $real = realpath($file->getPathname());
                if ($real === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
                    continue;
                }
            }

            $ext = strtolower($file->getExtension());
            if (!isset(self::KINDS[$ext])) {
                continue;
            }
            if ($file->getSize() > self::MAX_BYTES) {
                continue;
            }

            $abs = $file->getPathname();
            $relInside = str_replace('\\', '/', substr($abs, strlen($base) + 1));
            $relPath = $prefix === '' ? $relInside : $prefix . '/' . $relInside;

            $results[] = $this->analyzeOne($abs, $relPath, self::KINDS[$ext]);
        }

        usort($results, static fn (AnalysisResult $a, AnalysisResult $b): int => strcmp($a->relPath, $b->relPath));
        return $results;
    }

    private function analyzeOne(string $abs, string $relPath, string $kind): AnalysisResult
    {
        try {
            return match ($kind) {
                'html' => $this->html->analyze($abs, $relPath),
                'php' => $this->php->analyze($abs, $relPath),
                'css' => $this->assets->analyzeCss($abs, $relPath),
                'js' => $this->assets->analyzeJs($abs, $relPath),
                'json' => $this->assets->analyzeJson($abs, $relPath),
                default => new AnalysisResult($relPath, $kind, '', 0, error: 'Tipo não suportado.'),
            };
        } catch (\Throwable $e) {
            error_log('cms scanner ' . $relPath . ': ' . $e->getMessage());
            return new AnalysisResult($relPath, $kind, '', 0, error: 'Falha na análise.');
        }
    }
}
