<?php
declare(strict_types=1);

/** Configuração pública mínima de checkout e pixel para uma landing page. */
require __DIR__ . '/bootstrap.php';

$slug = (string) ($_GET['slug'] ?? '');
if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
    admin_json(['ok' => false, 'error' => 'Produto não encontrado.'], 404);
}
if (!admin_db_connected()) {
    admin_json(['ok' => false, 'error' => 'Configuração temporariamente indisponível.'], 503);
}
try {
    $pdo = admin_pdo();
    $stmt = $pdo->prepare(
        'SELECT slug, checkout_provider, checkout_url, meta_pixel_id
         FROM cms_products WHERE slug = :slug AND status = \'active\' LIMIT 1'
    );
    $stmt->execute(['slug' => $slug]);
    $product = $stmt->fetch();
    if (!$product) {
        admin_json(['ok' => false, 'error' => 'Produto não encontrado.'], 404);
    }
    admin_json(['ok' => true, 'product' => $product]);
} catch (Throwable $e) {
    error_log('product-config: ' . $e->getMessage());
    admin_json(['ok' => false, 'error' => 'Configuração temporariamente indisponível.'], 503);
}
