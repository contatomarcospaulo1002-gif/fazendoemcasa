<?php
declare(strict_types=1);

namespace FazendoEmCasa\Cms\Scanner;

require_once dirname(__DIR__) . '/_deny-direct.php';

/**
 * Analisador de CSS, JS e JSON.
 *
 * Não executa nada. Faz apenas contagens e extração de URLs de assets,
 * que alimentam o mapa de dependências da FASE 5.
 */
final class AssetAnalyzer
{
    /** @var list<string> */
    private array $warnings = [];

    public function analyzeCss(string $absPath, string $relPath): AnalysisResult
    {
        $raw = $this->read($absPath);
        if ($raw === null) {
            return $this->failure($relPath, 'css');
        }

        preg_match_all('/url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)/i', $raw, $m);
        $urls = array_values(array_unique(array_map('trim', $m[1] ?? [])));

        $links = [];
        foreach ($urls as $url) {
            $links[] = ['href' => $url, 'text' => 'css asset'];
        }

        return new AnalysisResult(
            relPath: $relPath,
            kind: 'css',
            contentHash: hash('sha256', $raw),
            sizeBytes: strlen($raw),
            links: $links,
            warnings: $this->warnings,
        );
    }

    public function analyzeJs(string $absPath, string $relPath): AnalysisResult
    {
        $raw = $this->read($absPath);
        if ($raw === null) {
            return $this->failure($relPath, 'js');
        }

        $this->warnings[] = 'JS não é editável pelo CMS; apenas inventariado.';

        return new AnalysisResult(
            relPath: $relPath,
            kind: 'js',
            contentHash: hash('sha256', $raw),
            sizeBytes: strlen($raw),
            warnings: $this->warnings,
        );
    }

    public function analyzeJson(string $absPath, string $relPath): AnalysisResult
    {
        $raw = $this->read($absPath);
        if ($raw === null) {
            return $this->failure($relPath, 'json');
        }

        $hash = hash('sha256', $raw);
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new AnalysisResult(
                relPath: $relPath,
                kind: 'json',
                contentHash: $hash,
                sizeBytes: strlen($raw),
                error: 'JSON inválido: ' . json_last_error_msg(),
            );
        }

        return new AnalysisResult(
            relPath: $relPath,
            kind: 'json',
            contentHash: $hash,
            sizeBytes: strlen($raw),
            warnings: $this->warnings,
        );
    }

    private function read(string $absPath): ?string
    {
        if (!is_file($absPath) || !is_readable($absPath)) {
            return null;
        }
        $raw = @file_get_contents($absPath);
        return $raw === false ? null : $raw;
    }

    private function failure(string $relPath, string $kind): AnalysisResult
    {
        return new AnalysisResult(
            relPath: $relPath,
            kind: $kind,
            contentHash: '',
            sizeBytes: 0,
            error: 'Arquivo ilegível.',
        );
    }
}
