<?php
declare(strict_types=1);

$path = dirname(__DIR__) . '/site-map.json';
$map = json_decode((string) file_get_contents($path), true);

$photoFields = [
    ['id' => 'gallery.photos.title', 'label' => 'Título da galeria', 'where' => 'Cabeçalho (podes usar <span class="heading-accent">)', 'selector' => '#galeria-fotos .gallery-title-photos', 'type' => 'html'],
    ['id' => 'gallery.photos.lead', 'label' => 'Introdução', 'where' => 'Texto sob o título', 'selector' => '#galeria-fotos .gallery-lead-photos', 'type' => 'text'],
    ['id' => 'gallery.photos.cta.text', 'label' => 'Botão — texto', 'where' => 'CTA sob o carrossel', 'selector' => '#galeria-fotos .gallery-photos-cta', 'type' => 'html'],
    ['id' => 'gallery.photos.cta.href', 'label' => 'Botão — link', 'where' => 'URL Kiwify', 'selector' => '#galeria-fotos .gallery-photos-cta', 'type' => 'attr', 'attribute' => 'href'],
];

for ($i = 1; $i <= 6; $i++) {
    $photoFields[] = [
        'id' => "gallery.photos.s{$i}.before",
        'label' => "Slide {$i} — foto Antes",
        'where' => "Carrossel fotos, cartão {$i} (esquerda)",
        'selector' => "#galeria-fotos .ba-slide[data-index=\"{$i}\"] .ba-antes",
        'type' => 'image',
    ];
    $photoFields[] = [
        'id' => "gallery.photos.s{$i}.after",
        'label' => "Slide {$i} — foto Depois",
        'where' => "Carrossel fotos, cartão {$i} (direita)",
        'selector' => "#galeria-fotos .ba-slide[data-index=\"{$i}\"] .ba-depois",
        'type' => 'image',
    ];
    $photoFields[] = [
        'id' => "gallery.photos.s{$i}.caption",
        'label' => "Slide {$i} — legenda",
        'where' => "Sob as duas fotos do slide {$i}",
        'selector' => "#galeria-fotos .ba-slide[data-index=\"{$i}\"] .ba-caption",
        'type' => 'text',
    ];
}

$commentFields = [
    ['id' => 'gallery.comments.title', 'label' => 'Título da galeria', 'where' => 'Cabeçalho comentários', 'selector' => '#galeria-comentarios .gallery-title-comments', 'type' => 'html'],
    ['id' => 'gallery.comments.lead', 'label' => 'Introdução', 'where' => 'Texto sob o título', 'selector' => '#galeria-comentarios .gallery-lead-comments', 'type' => 'text'],
    ['id' => 'gallery.comments.cta.text', 'label' => 'Botão — texto', 'where' => 'CTA sob o carrossel', 'selector' => '#galeria-comentarios .gallery-comments-cta', 'type' => 'html'],
    ['id' => 'gallery.comments.cta.href', 'label' => 'Botão — link', 'where' => 'URL Kiwify', 'selector' => '#galeria-comentarios .gallery-comments-cta', 'type' => 'attr', 'attribute' => 'href'],
];

for ($i = 1; $i <= 6; $i++) {
    $commentFields[] = [
        'id' => "gallery.comments.s{$i}.image",
        'label' => "Slide {$i} — captura (imagem)",
        'where' => "Carrossel comentários, cartão {$i}",
        'selector' => "#galeria-comentarios .comment-slide[data-index=\"{$i}\"] .comment-img",
        'type' => 'image',
    ];
    $commentFields[] = [
        'id' => "gallery.comments.s{$i}.text",
        'label' => "Slide {$i} — texto (se não houver imagem)",
        'where' => "Alternativa à captura no slide {$i}",
        'selector' => "#galeria-comentarios .comment-slide[data-index=\"{$i}\"] .comment-fallback",
        'type' => 'text',
    ];
}

$groups = $map['groups'];
$insert = false;
$out = [];
foreach ($groups as $g) {
    if ($g['id'] === 'faq' && !$insert) {
        $out[] = [
            'id' => 'galeria-fotos',
            'title' => 'Galeria de fotos (carrossel)',
            'where' => 'Secção escura com Antes/Depois — só aparece com imagens no admin',
            'fields' => $photoFields,
        ];
        $out[] = [
            'id' => 'galeria-comentarios',
            'title' => 'Galeria de comentários (carrossel)',
            'where' => 'Capturas ou textos autorizados — mobile com swipe',
            'fields' => $commentFields,
        ];
        $insert = true;
    }
    $out[] = $g;
}
$map['groups'] = $out;

file_put_contents($path, json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo "OK\n";
