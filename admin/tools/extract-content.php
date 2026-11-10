<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$mapPath = dirname(__DIR__) . '/site-map.json';
$indexPath = $root . '/index.html';
$outPath = dirname(__DIR__) . '/content.json';

$map = json_decode((string) file_get_contents($mapPath), true);
$html = (string) file_get_contents($indexPath);
$dom = new DOMDocument();
libxml_use_internal_errors(true);
$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
libxml_clear_errors();

$content = ['_meta' => ['source' => 'extract-content.php', 'updatedAt' => gmdate('c')]];

foreach ($map['groups'] as $group) {
    foreach ($group['fields'] as $field) {
        $id = $field['id'];
        $type = $field['type'];
        $sel = $field['selector'];
        $node = querySelector($dom, $sel);
        if (!$node) {
            continue;
        }
        if ($type === 'text' || $type === 'html') {
            $content[$id] = $type === 'html' ? innerHtml($node) : textContent($node);
        } elseif ($type === 'attr') {
            $attr = $field['attribute'] ?? 'href';
            if ($node instanceof DOMAttr) {
                $content[$id] = $node->value;
            } elseif ($node instanceof DOMElement && $node->hasAttribute($attr)) {
                $content[$id] = $node->getAttribute($attr);
            }
        } elseif ($type === 'image') {
            $content[$id] = [
                'src' => $node instanceof DOMElement ? $node->getAttribute('src') : '',
                'alt' => $node instanceof DOMElement ? $node->getAttribute('alt') : '',
            ];
            if (!empty($field['lightboxSelector'])) {
                $lb = querySelector($dom, $field['lightboxSelector']);
                if ($lb instanceof DOMElement) {
                    $content[$id]['lightbox'] = $lb->getAttribute('data-lightbox-src');
                }
            }
        } elseif ($type === 'image-slot') {
            $content[$id] = [
                'src' => $node instanceof DOMElement ? ($node->getAttribute('data-cms-src') ?: '') : '',
                'label' => $node instanceof DOMElement ? ($node->getAttribute('data-label') ?: '') : '',
                'note' => '',
            ];
            if ($node instanceof DOMElement) {
                $note = querySelector($dom, $sel . ' .slot-note');
                if ($note) {
                    $content[$id]['note'] = textContent($note);
                }
            }
        }
    }
}

file_put_contents($outPath, json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo "OK: $outPath\n";

function textContent(DOMNode $node): string
{
    return trim(preg_replace('/\s+/u', ' ', $node->textContent ?? ''));
}

function innerHtml(DOMNode $node): string
{
    $doc = $node->ownerDocument;
    if (!$doc) {
        return '';
    }
    $html = '';
    foreach ($node->childNodes as $child) {
        $html .= $doc->saveHTML($child);
    }
    return trim($html);
}

/** Subconjunto de seletores CSS usados no site-map */
function querySelector(DOMDocument $dom, string $selector): ?DOMNode
{
    $xpath = cssToXPath($selector);
    if ($xpath === null) {
        return null;
    }
    $xp = new DOMXPath($dom);
    $list = $xp->query($xpath);
    if (!$list || $list->length === 0) {
        return null;
    }
    return $list->item(0);
}

function cssToXPath(string $selector): ?string
{
    $selector = trim($selector);
    if ($selector === '') {
        return null;
    }
    if ($selector === 'title') {
        return '//title';
    }
    if (preg_match('#^meta\[name="([^"]+)"\]$#', $selector, $m)) {
        return '//meta[@name="' . $m[1] . '"]';
    }
    $parts = preg_split('/\s+/', $selector);
    $xp = '.';
    foreach ($parts as $part) {
        if (preg_match('/^#([\w-]+)$/', $part, $m)) {
            $xp .= '//*[@id="' . $m[1] . '"]';
            continue;
        }
        if (preg_match('#^\.([\w-]+)$#', $part, $m)) {
            $xp .= '//*[contains(concat(" ", normalize-space(@class), " "), " ' . $m[1] . ' ")]';
            continue;
        }
        if (preg_match('#^([\w-]+)$#', $part, $m)) {
            $xp .= '//' . $m[1];
            continue;
        }
        if (preg_match('#^([\w-]+):nth-child\((\d+)\)$#', $part, $m)) {
            $xp .= '/' . $m[1] . '[' . $m[2] . ']';
            continue;
        }
        if (preg_match('#^\[([\w-]+)="([^"]*)"\]$#', $part, $m)) {
            $xp .= '//*[@' . $m[1] . '="' . $m[2] . '"]';
            continue;
        }
        if (preg_match('#^\.([\w-]+):nth-child\((\d+)\)$#', $part, $m)) {
            $xp .= '//*[contains(concat(" ", normalize-space(@class), " "), " ' . $m[1] . ' ")][position()=' . $m[2] . ']';
            continue;
        }
        return null;
    }
    return $xp === '.' ? null : $xp;
}
