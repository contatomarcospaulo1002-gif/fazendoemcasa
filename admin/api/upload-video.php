<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
admin_require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    admin_json(['ok' => false, 'error' => 'Método inválido.'], 405);
}

$paths = admin_paths();
$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) {
    admin_json(['ok' => false, 'error' => 'JSON inválido.'], 400);
}

$fieldId = preg_replace('/[^a-z0-9._-]/i', '', (string) ($body['fieldId'] ?? ''));
$dataUrl = (string) ($body['dataUrl'] ?? '');

if ($fieldId === '' || $dataUrl === '') {
    admin_json(['ok' => false, 'error' => 'fieldId ou vídeo em falta.'], 400);
}

if (!preg_match('#^data:video/mp4;base64,#i', $dataUrl)) {
    admin_json(['ok' => false, 'error' => 'Só MP4 é suportado para upload direto.'], 400);
}

$raw = base64_decode(preg_replace('#^data:video/mp4;base64,#i', '', $dataUrl), true);
if ($raw === false) {
    admin_json(['ok' => false, 'error' => 'Base64 inválido.'], 400);
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$detected = $finfo->buffer($raw);
if ($detected !== 'video/mp4') {
    admin_json(['ok' => false, 'error' => 'O ficheiro não é um vídeo MP4 válido.'], 400);
}

$maxBytes = 12 * 1024 * 1024;
if (strlen($raw) > $maxBytes) {
    admin_json(['ok' => false, 'error' => 'Vídeo maior que 12 MB. Usa embed (YouTube/Facebook/Vimeo).'], 400);
}

$filename = $fieldId . '.mp4';
$full = $paths['project'] . '/video/cms/' . $filename;
if (!admin_write_bytes($full, $raw)) {
    admin_json(['ok' => false, 'error' => 'Não foi possível gravar o vídeo.'], 500);
}

admin_json(['ok' => true, 'path' => 'video/cms/' . $filename, 'bytes' => strlen($raw)]);
