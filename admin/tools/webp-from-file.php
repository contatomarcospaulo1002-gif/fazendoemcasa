<?php
declare(strict_types=1);

/** Uso: php webp-from-file.php <entrada> <saida.webp> [larguraMax] [qualidade0-100] */
if ($argc < 3) {
    fwrite(STDERR, "Uso: php webp-from-file.php entrada.jpg saida.webp [1200] [82]\n");
    exit(1);
}

$in = $argv[1];
$out = $argv[2];
$maxW = isset($argv[3]) ? (int) $argv[3] : 1200;
$quality = isset($argv[4]) ? (int) $argv[4] : 82;

if (!is_file($in)) {
    fwrite(STDERR, "Ficheiro não encontrado: $in\n");
    exit(1);
}

$info = @getimagesize($in);
if ($info === false) {
    fwrite(STDERR, "Imagem inválida\n");
    exit(1);
}

switch ($info[2]) {
    case IMAGETYPE_JPEG:
        $src = imagecreatefromjpeg($in);
        break;
    case IMAGETYPE_PNG:
        $src = imagecreatefrompng($in);
        break;
    case IMAGETYPE_WEBP:
        $src = imagecreatefromwebp($in);
        break;
    default:
        fwrite(STDERR, "Formato não suportado\n");
        exit(1);
}

if ($src === false) {
    fwrite(STDERR, "Não foi possível ler a imagem\n");
    exit(1);
}

$w = imagesx($src);
$h = imagesy($src);
$scale = min(1.0, $maxW / max(1, $w));
$nw = (int) round($w * $scale);
$nh = (int) round($h * $scale);

$dst = imagecreatetruecolor($nw, $nh);
imagealphablending($dst, false);
imagesavealpha($dst, true);
imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

$dir = dirname($out);
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}

if (!imagewebp($dst, $out, $quality)) {
    fwrite(STDERR, "Falha ao gravar WebP\n");
    exit(1);
}

imagedestroy($src);
imagedestroy($dst);
fwrite(STDOUT, "OK $out (" . filesize($out) . " bytes)\n");
