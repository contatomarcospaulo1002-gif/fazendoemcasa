<?php
declare(strict_types=1);

/**
 * Mapa de campos do site (leitura protegida).
 *
 * Substitui o acesso direto a admin/site-map.json, que passou a ser
 * bloqueado pelo Apache. Exige sessão autenticada.
 */

require __DIR__ . '/bootstrap.php';

admin_require_auth();

$paths = admin_paths();
$raw = is_file($paths['siteMap']) ? file_get_contents($paths['siteMap']) : false;

if ($raw === false) {
    admin_json(['ok' => false, 'error' => 'Mapa de campos não encontrado.'], 404);
}

$map = json_decode((string) $raw, true);
if (!is_array($map)) {
    admin_json(['ok' => false, 'error' => 'Mapa de campos inválido.'], 500);
}

admin_json([
    'ok' => true,
    'map' => $map,
    'updatedAt' => gmdate('c', (int) filemtime($paths['siteMap'])),
]);
