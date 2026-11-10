<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
admin_require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    admin_json(['ok' => false, 'error' => 'Método inválido.'], 405);
}

$paths = admin_paths();
$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body) || !isset($body['content']) || !is_array($body['content'])) {
    admin_json(['ok' => false, 'error' => 'JSON inválido.'], 400);
}

$content = $body['content'];
$content['_meta'] = array_merge(
    is_array($content['_meta'] ?? null) ? $content['_meta'] : [],
    ['updatedAt' => gmdate('c')]
);

$json = json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    admin_json(['ok' => false, 'error' => 'Não foi possível serializar o conteúdo.'], 500);
}

if (file_put_contents($paths['content'], $json) === false) {
    admin_json(['ok' => false, 'error' => 'Não foi possível gravar content.json.'], 500);
}

admin_json(['ok' => true, 'path' => 'admin/content.json']);
