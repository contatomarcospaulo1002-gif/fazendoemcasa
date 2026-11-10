<?php
declare(strict_types=1);

$path = dirname(__DIR__) . '/content.json';
$content = json_decode((string) file_get_contents($path), true);

$content['video.como.title'] = '';
$content['video.como.embed'] = '';
$content['video.como.mp4'] = '';
$content['video.hero.title'] = '';
$content['video.hero.embed'] = '';
$content['video.hero.mp4'] = '';

file_put_contents($path, json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo "OK\n";
