<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
admin_require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    admin_json(['ok' => false, 'error' => 'Método inválido.'], 405);
}

$paths = admin_paths();
$body = json_decode((string) file_get_contents('php://input'), true);
$html = isset($body['html']) ? (string) $body['html'] : '';

if ($html === '' || strpos($html, '<html') === false) {
    admin_json(['ok' => false, 'error' => 'HTML inválido.'], 400);
}

$backup = $paths['index'] . '.bak.' . date('Ymd-His');
if (is_file($paths['index'])) {
    copy($paths['index'], $backup);
}

if (!admin_write_bytes($paths['index'], $html)) {
    admin_json(['ok' => false, 'error' => 'Não foi possível gravar index.html.'], 500);
}

admin_json(['ok' => true, 'backup' => basename($backup)]);
