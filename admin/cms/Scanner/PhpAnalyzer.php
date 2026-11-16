<?php
declare(strict_types=1);

namespace FazendoEmCasa\Cms\Scanner;

require_once dirname(__DIR__) . '/_deny-direct.php';

/**
 * Analisador estático de PHP.
 *
 * Usa token_get_all() — NUNCA inclui, executa ou avalia o arquivo.
 * Extrai apenas metadados: includes, echo de literais, variáveis de
 * template e se há HTML embutido.
 */
final class PhpAnalyzer
{
    /** @var list<string> */
    private array $warnings = [];

    public function analyze(string $absPath, string $relPath): AnalysisResult
    {
        $this->warnings = [];

        if (!is_file($absPath) || !is_readable($absPath)) {
            return $this->failure($relPath, 'Arquivo ilegível.');
        }

        $size = (int) filesize($absPath);
        $raw = @file_get_contents($absPath);
        if ($raw === false) {
            return $this->failure($relPath, 'Falha ao ler o arquivo.');
        }

        $hash = hash('sha256', $raw);

        try {
            $tokens = token_get_all($raw);
        } catch (\Throwable $e) {
            return $this->failure($relPath, 'Falha ao tokenizar: ' . $e->getMessage(), $hash, $size);
        }

        $includes = [];
        $literals = [];
        $variables = [];
        $hasInlineHtml = false;
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                continue;
            }
            [$id, $text] = $token;

            if ($id === T_INLINE_HTML) {
                $hasInlineHtml = true;
                continue;
            }

            if (in_array($id, [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE], true)) {
                $target = $this->nextStringLiteral($tokens, $i);
                if ($target !== null) {
                    $includes[] = $target;
                }
                continue;
            }

            if ($id === T_VARIABLE) {
                $name = substr($text, 1);
                if ($name !== '' && !in_array($name, $variables, true)) {
                    $variables[] = $name;
                }
                continue;
            }

            if ($id === T_CONSTANT_ENCAPSED_STRING) {
                $value = $this->unquote($text);
                if ($value !== '' && mb_strlen($value) > 2 && !str_contains($value, '<?')) {
                    $literals[] = $value;
                }
            }
        }

        if ($hasInlineHtml) {
            $this->warnings[] = 'Arquivo mistura PHP e HTML; edição exige adaptador.';
        }
        if ($includes !== []) {
            $this->warnings[] = 'Inclui ' . count($includes) . ' arquivo(s); conteúdo pode vir de fora.';
        }

        $elements = [];
        foreach (array_slice($literals, 0, 50) as $index => $literal) {
            $elements[] = [
                'cms_id' => 'php-literal-' . $index,
                'type' => 'text',
                'tag' => 'php',
                'editability' => 'needs_adapter',
                'preview' => mb_strlen($literal) > 120 ? mb_substr($literal, 0, 117) . '…' : $literal,
                'has_src' => false,
            ];
        }

        return new AnalysisResult(
            relPath: $relPath,
            kind: 'php',
            contentHash: $hash,
            sizeBytes: $size,
            title: null,
            elements: $elements,
            images: [],
            links: [],
            warnings: $this->warnings,
        );
    }

    /**
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    private function nextStringLiteral(array $tokens, int $from): ?string
    {
        $limit = min(count($tokens), $from + 8);
        for ($i = $from + 1; $i < $limit; $i++) {
            $t = $tokens[$i];
            if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
                return $this->unquote($t[1]);
            }
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (is_string($t) && $t === '(') {
                continue;
            }
            break;
        }
        return null;
    }

    private function unquote(string $raw): string
    {
        $raw = trim($raw);
        if (strlen($raw) >= 2) {
            $first = $raw[0];
            $last = $raw[strlen($raw) - 1];
            if (($first === "'" && $last === "'") || ($first === '"' && $last === '"')) {
                return substr($raw, 1, -1);
            }
        }
        return $raw;
    }

    private function failure(string $relPath, string $message, string $hash = '', int $size = 0): AnalysisResult
    {
        return new AnalysisResult(
            relPath: $relPath,
            kind: 'php',
            contentHash: $hash,
            sizeBytes: $size,
            error: $message,
        );
    }
}
