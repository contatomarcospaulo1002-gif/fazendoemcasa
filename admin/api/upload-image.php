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
$ext = (string) ($body['ext'] ?? 'webp');

if ($fieldId === '' || $dataUrl === '') {
    admin_json(['ok' => false, 'error' => 'fieldId ou imagem em falta.'], 400);
}

if (!in_array($ext, ['webp', 'jpg', 'jpeg', 'png'], true)) {
    $ext = 'webp';
}

if (!preg_match('#^data:image/(webp|jpeg|jpg|png);base64,#i', $dataUrl, $m)) {
    admin_json(['ok' => false, 'error' => 'Formato de imagem inválido.'], 400);
}

$raw = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $dataUrl), true);
if ($raw === false) {
    admin_json(['ok' => false, 'error' => 'Base64 inválido.'], 400);
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$detected = $finfo->buffer($raw);
$allowedMimes = [
    'webp' => ['image/webp'],
    'jpg' => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png' => ['image/png'],
];
$normExt = $ext === 'jpeg' ? 'jpg' : $ext;
if (!isset($allowedMimes[$normExt]) || !in_array($detected, $allowedMimes[$normExt], true)) {
    admin_json(['ok' => false, 'error' => 'O conteúdo não corresponde a uma imagem válida.'], 400);
}

$maxBytes = 8 * 1024 * 1024;
if (strlen($raw) > $maxBytes) {
    admin_json([
        'ok' => false,
        'error' => 'Imagem maior que 8 MB. Reduz a largura máx. ou a qualidade no painel.',
    ], 400);
}

$filename = $fieldId . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
$full = $paths['imgCms'] . '/' . $filename;
if (!admin_write_bytes($full, $raw)) {
    admin_json([
        'ok' => false,
        'error' => 'Não foi possível gravar a imagem. A pasta img/cms não deixou substituir o ficheiro.',
    ], 500);
}

$version = (string) time() . bin2hex(random_bytes(2));
$publicPath = 'img/cms/' . $filename . '?v=' . $version;
try {
    cms_log_media($fieldId, $publicPath, strlen($raw), 'image/webp');
} catch (Throwable) {
    // A imagem já ficou em disco. Falha no registo não deve anular o upload.
}
admin_json(['ok' => true, 'path' => $publicPath, 'bytes' => strlen($raw)]);
