<?php
declare(strict_types=1);

$path = dirname(__DIR__) . '/site-map.json';
$map = json_decode((string) file_get_contents($path), true);

$fields = [
    [
        'id' => 'video.como.title',
        'label' => 'Título do vídeo (secção Como funciona)',
        'where' => 'Acima do vídeo, antes das 4 semanas',
        'selector' => '#como-video-block .video-embed-heading',
        'type' => 'text',
        'hint' => 'Ex.: Vê o guia em 90 segundos',
    ],
    [
        'id' => 'video.como.embed',
        'label' => 'Vídeo principal — código embed',
        'where' => 'Secção «Como funcionam as 4 semanas» (recomendado)',
        'selector' => '#como-video-block .video-embed-slot',
        'type' => 'video-embed',
        'hint' => 'Cola o iframe do Facebook, YouTube ou Vimeo. Preferível a upload direto.',
    ],
    [
        'id' => 'video.como.mp4',
        'label' => 'Vídeo principal — ficheiro MP4 (alternativa)',
        'where' => 'Substitui o embed se não houver iframe',
        'selector' => '#como-video-block .video-native',
        'type' => 'video-file',
        'hint' => 'Máx. 12 MB. Só para clipes curtos.',
    ],
    [
        'id' => 'video.hero.title',
        'label' => 'Título do vídeo no hero',
        'where' => 'Sob os dados de confiança, lado esquerdo',
        'selector' => '#hero-video-block .video-embed-title',
        'type' => 'text',
    ],
    [
        'id' => 'video.hero.embed',
        'label' => 'Vídeo curto no hero — embed',
        'where' => 'Abertura (opcional, não sobrecarrega)',
        'selector' => '#hero-video-block .video-embed-slot',
        'type' => 'video-embed',
    ],
    [
        'id' => 'video.hero.mp4',
        'label' => 'Vídeo curto no hero — MP4',
        'where' => 'Alternativa ao embed no hero',
        'selector' => '#hero-video-block .video-native',
        'type' => 'video-file',
    ],
];

$group = [
    'id' => 'videos',
    'title' => 'Vídeos',
    'where' => 'Embed (Facebook/YouTube/Vimeo) ou MP4 curto — secções Hero e Como funciona',
    'fields' => $fields,
];

$out = [];
$inserted = false;
foreach ($map['groups'] as $g) {
    if ($g['id'] === 'galeria-fotos' && !$inserted) {
        $out[] = $group;
        $inserted = true;
    }
    if ($g['id'] === 'videos') {
        continue;
    }
    $out[] = $g;
}
if (!$inserted) {
    $out[] = $group;
}

$map['groups'] = $out;
file_put_contents($path, json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo "OK\n";
