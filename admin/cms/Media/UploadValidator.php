<?php
declare(strict_types=1);

namespace FazendoEmCasa\Cms\Media;

require_once dirname(__DIR__) . '/_deny-direct.php';

/**
 * Validação de ficheiros enviados.
 *
 * A regra central: NUNCA confiar no MIME declarado pelo browser nem na
 * extensão do nome. O tipo é determinado pelo conteúdo real (finfo) e
 * a extensão de destino é derivada desse tipo — não do que o ficheiro
 * diz ser.
 *
 * Um ficheiro cujo conteúdo não corresponda a um tipo permitido é
 * rejeitado mesmo que o nome seja `foto.png`.
 */
final class UploadValidator
{
    /** Extensão de destino derivada do MIME real (nunca do nome). */
    public const ALLOWED = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/svg+xml' => 'svg',
        'image/avif' => 'avif',
    ];

    /** Tamanho máximo aceite (bytes). */
    public const MAX_BYTES = 8 * 1024 * 1024;

    /**
     * @return array{ok: bool, error: ?string, mime: string, extension: string, size: int, width: ?int, height: ?int}
     */
    public function validate(string $tmpPath, ?string $declaredName = null): array
    {
        $fail = static fn (string $msg): array => [
            'ok' => false,
            'error' => $msg,
            'mime' => '',
            'extension' => '',
            'size' => 0,
            'width' => null,
            'height' => null,
        ];

        if (!is_file($tmpPath) || !is_readable($tmpPath)) {
            return $fail('Ficheiro temporário inacessível.');
        }

        $size = (int) filesize($tmpPath);
        if ($size <= 0) {
            return $fail('Ficheiro vazio.');
        }
        if ($size > self::MAX_BYTES) {
            return $fail('Ficheiro excede o limite de ' . (self::MAX_BYTES / 1048576) . ' MB.');
        }

        // Tipo real a partir do CONTEÚDO, não do nome nem do header.
        $mime = '';
        if (class_exists(\finfo::class)) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            // Normaliza separadores: caminhos mistos (C:\...\Temp/x) fazem
            // o finfo falhar no Windows.
            $probe = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $tmpPath);
            $detected = @$finfo->file($probe);
            $mime = is_string($detected) ? strtolower($detected) : '';
        }

        if ($mime === '' || !isset(self::ALLOWED[$mime])) {
            return $fail('Tipo de ficheiro não permitido.');
        }

        $extension = self::ALLOWED[$mime];

        // SVG é texto com capacidade de script: validado à parte.
        if ($mime === 'image/svg+xml' && !$this->svgIsSafe($tmpPath)) {
            return $fail('SVG contém conteúdo executável e foi rejeitado.');
        }

        [$width, $height] = $this->dimensions($tmpPath, $mime);

        // Um "image" que o getimagesize não consegue medir não é imagem.
        if ($mime !== 'image/svg+xml' && $width === null) {
            return $fail('Não foi possível ler as dimensões da imagem.');
        }

        return [
            'ok' => true,
            'error' => null,
            'mime' => $mime,
            'extension' => $extension,
            'size' => $size,
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * Rejeita SVG com conteúdo executável. Analisa com parser XML e
     * allowlist de elementos/atributos, em vez de procurar substrings —
     * `onload = "…"` com espaços escaparia à busca literal.
     */
    private function svgIsSafe(string $path): bool
    {
        $raw = (string) file_get_contents($path, false, null, 0, 512000);

        // DTD / entidades externas: vetor clássico de XXE.
        if (stripos($raw, '<!doctype') !== false || stripos($raw, '<!entity') !== false) {
            return false;
        }

        $doc = simplexml_load_string($raw);
        if ($doc === false) {
            // XML inválido não é um SVG que possamos confiar.
            return false;
        }

        $allowedElements = [
            'svg', 'g', 'defs', 'symbol', 'use', 'title', 'desc',
            'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
            'text', 'tspan', 'image', 'marker', 'pattern', 'mask', 'clippath',
            'lineargradient', 'radialgradient', 'stop', 'filter', 'view',
            'style', 'metadata', 'canvas',
        ];
        $allowedAttributes = [
            'id', 'class', 'style', 'd', 'x', 'y', 'x1', 'y1', 'x2', 'y2',
            'cx', 'cy', 'r', 'rx', 'ry', 'width', 'height', 'viewbox',
            'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin',
            'stroke-dasharray', 'stroke-opacity', 'fill-opacity', 'opacity',
            'transform', 'points', 'version', 'xmlns', 'href', 'xlink:href',
            'preserveaspectratio', 'offset', 'stop-color', 'stop-opacity',
            'gradientunits', 'spreadmethod', 'clip-path', 'mask', 'filter',
            'font-family', 'font-size', 'font-weight', 'text-anchor',
            'dominant-baseline', 'role', 'aria-label', 'aria-hidden',
            'data-name', 'overflow', 'patternunits', 'markerwidth', 'markerheight',
            'refx', 'refy', 'orient', 'markerunits', 'requiredfeatures',
        ];

        foreach ($this->svgWalk($doc) as [$element, $attributes]) {
            $name = strtolower((string) preg_replace('/^[^:]+:/', '', (string) $element->getName()));
            if ($name !== '' && !in_array($name, $allowedElements, true)) {
                return false;
            }

            foreach ($attributes as $attrName => $attrValue) {
                $clean = strtolower((string) preg_replace('/^[^:]+:/', '', (string) $attrName));
                // Qualquer atributo de evento (on*) é rejeitado, com ou
                // sem espaços — é o parser que o entrega, não a busca.
                if ($clean !== '' && (str_starts_with($clean, 'on') || !in_array($clean, $allowedAttributes, true))) {
                    return false;
                }
            }
        }

        // javascript: em qualquer href — o parser não normaliza o valor,
        // então verifica explicitamente.
        if (preg_match('/javascript\s*:/i', $raw) === 1) {
            return false;
        }

        return true;
    }

    /** @return list<array{0:\SimpleXMLElement, 1:array<string,string>}> */
    private function svgWalk(\SimpleXMLElement $node): array
    {
        // Iterar o atributos() diretamente; o cast (array) embute tudo
        // sob "@attributes" e perde os nomes.
        $attributes = [];
        foreach ($node->attributes() as $key => $value) {
            $attributes[(string) $key] = (string) $value;
        }
        foreach ($node->attributes('xlink', true) ?: [] as $k => $v) {
            $attributes['xlink:' . $k] = (string) $v;
        }

        $out = [[$node, $attributes]];

        foreach ($node->children() as $child) {
            foreach ($this->svgWalk($child) as $entry) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /** @return array{0: ?int, 1: ?int} */
    private function dimensions(string $path, string $mime): array
    {
        $info = @getimagesize($path);
        if ($info === false) {
            return [null, null];
        }
        return [(int) $info[0], (int) $info[1]];
    }
}
