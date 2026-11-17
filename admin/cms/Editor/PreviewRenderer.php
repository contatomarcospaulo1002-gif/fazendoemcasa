<?php
declare(strict_types=1);

namespace FazendoEmCasa\Cms\Editor;

require_once dirname(__DIR__) . '/_deny-direct.php';

/**
 * Pré-visualização em memória.
 *
 * Recebe o HTML original e o patch, e devolve o HTML com as alterações
 * aplicadas — SEM tocar em disco. É o mesmo mecanismo de patch que a
 * FASE 6 vai usar para escrever, o que garante que o preview mostra
 * exatamente o que será publicado.
 *
 * Elementos são localizados por `data-cms-id`. Um elemento do patch
 * que não exista no HTML é ignorado silenciosamente no preview (a
 * validação já aconteceu antes, no PatchValidator).
 */
final class PreviewRenderer
{
    /**
     * @param array<int, string> $patch element_id => novo valor
     * @param array<int, array<string, mixed>> $elements elementos indexados por id
     */
    public function render(string $html, array $patch, array $elements): string
    {
        if ($patch === [] || trim($html) === '') {
            return $html;
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $loaded = $doc->loadHTML(
            '<?xml encoding="utf-8" ?>' . $html,
            LIBXML_NONET | LIBXML_COMPACT
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return $html;
        }

        // A declaração injetada vira um nó de processamento que saveHTML
        // devolve no output. Removê-la antes de serializar garante que
        // o HTML publicado não contém lixo de encoding.
        $xpath0 = new \DOMXPath($doc);
        foreach ($xpath0->query('/processing-instruction("xml")') as $pi) {
            if ($pi->parentNode !== null) {
                $pi->parentNode->removeChild($pi);
            }
        }

        $xpath = new \DOMXPath($doc);
        $applied = 0;

        foreach ($patch as $elementId => $value) {
            $element = $elements[$elementId] ?? null;
            if ($element === null) {
                continue;
            }
            $cmsId = trim((string) ($element['cms_id'] ?? ''));
            if ($cmsId === '') {
                continue;
            }

            $type = (string) ($element['type'] ?? 'paragraph');
            $node = $this->findByCmsId($xpath, $cmsId);

            if (!$node instanceof \DOMElement) {
                continue;
            }

            if ($this->applyToNode($node, $type, $value)) {
                $applied++;
            }
        }

        if ($applied === 0) {
            return $html;
        }

        $out = $doc->saveHTML();
        if ($out === false) {
            return $html;
        }

        // Remove a declaração XML caso sobreviva ao patch (quando nada
        // mudou o original é devolvido antes deste ponto).
        return (string) preg_replace('/^<\?xml[^>]*\?>\s*/', '', $out);
    }

    private function applyToNode(\DOMElement $node, string $type, string $value): bool
    {
        $tag = strtolower($node->tagName);

        // Defesa em profundidade: mesmo que o PatchValidator falhe, um
        // esquema perigoso nunca chega a um atributo URL.
        if ($this->isDangerousUrl($value)) {
            return false;
        }

        // Imagens e origens de mídia trocam o atributo, não o texto.
        if ($type === 'image' || $type === 'logo' || $type === 'banner' || $tag === 'img') {
            if ($node->hasAttribute('src')) {
                $node->setAttribute('src', $value);
                return true;
            }
            return false;
        }

        if ($type === 'link' && $node->hasAttribute('href')) {
            // Para links, o valor é o destino; o texto é preservado.
            $node->setAttribute('href', $value);
            return true;
        }

        if ($type === 'background') {
            if ($node->hasAttribute('src')) {
                $node->setAttribute('src', $value);
                return true;
            }
            $current = $node->getAttribute('style');
            $style = (string) preg_replace('/background-image\s*:[^;]*;?/i', '', $current);
            $node->setAttribute('style', trim($style . ' background-image:url(' . $value . ');'));
            return true;
        }

        // Texto: substitui o conteúdo, preservando o elemento.
        while ($node->firstChild !== null) {
            $node->removeChild($node->firstChild);
        }
        $node->appendChild($node->ownerDocument->createTextNode($value));
        return true;
    }

    /**
     * Bloqueia esquemas que executam código. A verificação remove
     * espaços e caracteres de controlo antes de comparar, para que
     * "java\tscript:" não escape ao filtro.
     */
    private function isDangerousUrl(string $value): bool
    {
        $normalized = strtolower((string) preg_replace('/[\x00-\x20]+/', '', $value));
        return preg_match('/^(javascript|data|vbscript|file):/', $normalized) === 1;
    }

    /**
     * Localiza um nó por data-cms-id sem interpolar o valor no XPath.
     *
     * Percorrer os nós e comparar o atributo em PHP evita qualquer
     * problema de escape — um id com aspas ou colchetes não quebra a
     * consulta nem permite injeção de expressão XPath.
     */
    private function findByCmsId(\DOMXPath $xpath, string $cmsId): ?\DOMElement
    {
        foreach ($xpath->query('//*[@data-cms-id]') as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            if (trim($node->getAttribute('data-cms-id')) === $cmsId) {
                return $node;
            }
        }
        return null;
    }
}
