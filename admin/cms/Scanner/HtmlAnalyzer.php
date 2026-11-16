<?php
declare(strict_types=1);

namespace FazendoEmCasa\Cms\Scanner;

require_once dirname(__DIR__) . '/_deny-direct.php';

/**
 * Analisador de HTML.
 *
 * Usa DOMDocument em modo read-only. NUNCA executa scripts, NUNCA
 * resolve entidades externas, NUNCA escreve no arquivo.
 *
 * Convenção do projeto:
 *   data-cms-id="hero-title"   → identifica o elemento de forma estável
 *   data-cms-type="text"       → tipo de conteúdo (text, image, link, html)
 *
 * Elementos sem data-cms-id são registrados como `needs_adapter`.
 */
final class HtmlAnalyzer
{
    private const EDITABLE_TYPES = ['text', 'image', 'link', 'html', 'video'];

    /** @var list<string> */
    private array $warnings = [];

    public function analyze(string $absPath, string $relPath): AnalysisResult
    {
        $this->warnings = [];

        if (!is_file($absPath) || !is_readable($absPath)) {
            return $this->failure($relPath, 'html', 'Arquivo ilegível.');
        }

        $size = (int) filesize($absPath);
        $raw = @file_get_contents($absPath);
        if ($raw === false) {
            return $this->failure($relPath, 'html', 'Falha ao ler o arquivo.');
        }

        $hash = hash('sha256', $raw);
        $title = null;
        $elements = [];
        $candidates = [];
        $images = [];
        $links = [];

        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML(
            '<?xml encoding="utf-8" ?>' . $raw,
            LIBXML_NONET | LIBXML_COMPACT
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return $this->failure($relPath, 'html', 'HTML inválido.', $hash, $size);
        }

        $xpath = new \DOMXPath($doc);

        $titleNode = $xpath->query('//title')->item(0);
        if ($titleNode !== null) {
            $title = $this->clean($titleNode->textContent);
        }

        // Elementos marcados explicitamente como editáveis.
        foreach ($xpath->query('//*[@data-cms-id]') as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $id = trim($node->getAttribute('data-cms-id'));
            if ($id === '') {
                continue;
            }
            $type = $this->normalizeType($node->getAttribute('data-cms-type'), $node);
            $elements[] = [
                'cms_id' => $id,
                'type' => $type,
                'tag' => strtolower($node->tagName),
                'editability' => 'editable',
                'preview' => $this->preview($node, $type),
                'has_src' => $node->hasAttribute('src') || $node->hasAttribute('href'),
            ];
        }

        // Candidatos semânticos SEM data-cms-id.
        //
        // Ficam numa lista separada de `elements` para não alterar as
        // contagens já consumidas pelo inventário. O Registry lê esta
        // lista e classifica cada candidato como `needs_adapter`: a
        // estrutura é reconhecida, mas falta a marcação estável.
        foreach ($xpath->query('//h1|//h2|//h3|//h4|//p|//button|//label') as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            if ($node->hasAttribute('data-cms-id')) {
                continue;
            }
            $text = $this->clean($node->textContent);
            if ($text === '') {
                continue;
            }
            $candidates[] = [
                'tag' => strtolower($node->tagName),
                'preview' => mb_strlen($text) > 120 ? mb_substr($text, 0, 117) . '…' : $text,
                'has_src' => false,
            ];
        }

        // Imagens (com ou sem marcação).
        foreach ($xpath->query('//img[@src]') as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $src = trim($node->getAttribute('src'));
            if ($src === '') {
                continue;
            }
            $images[] = [
                'src' => $src,
                'alt' => trim($node->getAttribute('alt')),
                'width' => $node->getAttribute('width'),
                'height' => $node->getAttribute('height'),
                'cms_id' => $node->getAttribute('data-cms-id') ?: null,
            ];
        }

        // Links internos (para o grafo de navegação).
        foreach ($xpath->query('//a[@href]') as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $href = trim($node->getAttribute('href'));
            if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'mailto:')) {
                continue;
            }
            if (preg_match('#^(https?:)?//#i', $href) === 1) {
                continue;
            }
            $links[] = [
                'href' => $href,
                'text' => $this->clean($node->textContent),
            ];
        }

        if ($elements === [] && $images === []) {
            $this->warnings[] = 'Nenhum elemento editável nem imagem encontrada.';
        }

        return new AnalysisResult(
            relPath: $relPath,
            kind: 'html',
            contentHash: $hash,
            sizeBytes: $size,
            title: $title,
            elements: $elements,
            images: $images,
            links: $links,
            warnings: $this->warnings,
            candidates: $candidates,
        );
    }

    private function normalizeType(string $declared, \DOMElement $node): string
    {
        $declared = strtolower(trim($declared));
        if (in_array($declared, self::EDITABLE_TYPES, true)) {
            return $declared;
        }
        return match (strtolower($node->tagName)) {
            'img' => 'image',
            'a' => 'link',
            'video' => 'video',
            default => 'text',
        };
    }

    private function preview(\DOMElement $node, string $type): string
    {
        if ($type === 'image') {
            return $node->getAttribute('src');
        }
        $text = $this->clean($node->textContent);
        return mb_strlen($text) > 120 ? mb_substr($text, 0, 117) . '…' : $text;
    }

    private function clean(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private function failure(
        string $relPath,
        string $kind,
        string $message,
        string $hash = '',
        int $size = 0
    ): AnalysisResult {
        return new AnalysisResult(
            relPath: $relPath,
            kind: $kind,
            contentHash: $hash,
            sizeBytes: $size,
            error: $message,
        );
    }
}
