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

if (!is_dir($paths['imgCms'])) {
    mkdir($paths['imgCms'], 0755, true);
}

$filename = $fieldId . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
$full = $paths['imgCms'] . '/' . $filename;
if (file_put_contents($full, $raw) === false) {
    admin_json(['ok' => false, 'error' => 'Não foi possível gravar a imagem.'], 500);
}

$publicPath = 'img/cms/' . $filename;
cms_log_media($fieldId, $publicPath, strlen($raw), 'image/webp');
admin_json(['ok' => true, 'path' => $publicPath, 'bytes' => strlen($raw)]);
