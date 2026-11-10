<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$content = null;
$source = 'file';

if (admin_db_connected()) {
    $content = cms_load_document_from_db();
    $source = 'database';
}

if ($content === null) {
    $paths = admin_paths();
    if (is_file($paths['content'])) {
        $raw = file_get_contents($paths['content']);
        $decoded = json_decode((string) $raw, true);
        $content = is_array($decoded) ? $decoded : [];
        $source = 'file';
    } else {
        $content = [];
    }
}

admin_json([
    'ok' => true,
    'content' => $content,
    'source' => $source,
    'database' => admin_db_connected(),
]);
