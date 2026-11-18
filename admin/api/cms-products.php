<?php
declare(strict_types=1);

require dirname(__DIR__) . '/cms/bootstrap.php';

use FazendoEmCasa\Cms\Core\Auth;
use FazendoEmCasa\Cms\Core\Csrf;
use FazendoEmCasa\Cms\Core\Db;
use FazendoEmCasa\Cms\Core\Http;

Auth::startSession();
if (!Auth::check()) {
    Http::json(['ok' => false, 'error' => 'Não autenticado.', 'authenticated' => false], 401);
}
if (!Db::connected()) {
    Http::error('Base de dados indisponível.', 503);
}
if (!Db::tableExists('cms_products')) {
    Http::error('Migrations não aplicadas.', 503, ['hint' => 'php admin/cms/bin/migrate.php']);
}

$method = Http::requireMethod('GET', 'POST');
if ($method === 'GET') {
    Http::ok(['products' => Db::all('SELECT * FROM cms_products ORDER BY name ASC'), 'csrfToken' => Csrf::token()]);
}
Csrf::requireValid();
$body = Http::jsonBody();
$id = (int) ($_GET['id'] ?? 0);

try {
    $allowedProviders = ['hotmart', 'cakto', 'kiwify', 'custom'];
    if ($id > 0) {
        $current = Db::one('SELECT * FROM cms_products WHERE id = :id LIMIT 1', ['id' => $id]);
        if ($current === null) {
            Http::error('Produto não encontrado.', 404);
        }
        $name = trim((string) ($body['name'] ?? $current['name']));
        $provider = (string) ($body['checkout_provider'] ?? $current['checkout_provider']);
        $checkout = trim((string) ($body['checkout_url'] ?? $current['checkout_url']));
        $pixel = trim((string) ($body['meta_pixel_id'] ?? $current['meta_pixel_id']));
        $status = (string) ($body['status'] ?? $current['status']);
        if ($name === '' || !in_array($provider, $allowedProviders, true) || !in_array($status, ['active', 'disabled'], true)) {
            Http::error('Confere o nome, a plataforma e o estado do produto.', 422);
        }
        if ($checkout !== '' && !filter_var($checkout, FILTER_VALIDATE_URL)) {
            Http::error('O link de checkout tem de ser um URL válido.', 422);
        }
        if ($checkout !== '' && !in_array(strtolower((string) parse_url($checkout, PHP_URL_SCHEME)), ['https'], true)) {
            Http::error('O link de checkout deve usar HTTPS.', 422);
        }
        if ($pixel !== '' && !preg_match('/^\d{5,32}$/', $pixel)) {
            Http::error('O ID do pixel deve conter apenas números (5 a 32 dígitos).', 422);
        }
        Db::exec(
            'UPDATE cms_products SET name=:name, checkout_provider=:provider, checkout_url=:checkout,
             meta_pixel_id=:pixel, status=:status, updated_at=:updated WHERE id=:id',
            ['name'=>$name, 'provider'=>$provider, 'checkout'=>$checkout, 'pixel'=>$pixel, 'status'=>$status, 'updated'=>date('Y-m-d H:i:s'), 'id'=>$id]
        );
        Http::ok(['product' => Db::one('SELECT * FROM cms_products WHERE id=:id', ['id'=>$id])]);
    }

    $name = trim((string) ($body['name'] ?? ''));
    $slug = strtolower(trim((string) ($body['slug'] ?? '')));
    $path = trim((string) ($body['rel_path'] ?? ''), '/');
    $url = trim((string) ($body['base_url'] ?? ''));
    $provider = (string) ($body['checkout_provider'] ?? 'hotmart');
    $checkout = trim((string) ($body['checkout_url'] ?? ''));
    $pixel = trim((string) ($body['meta_pixel_id'] ?? ''));
    if ($name === '' || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) || !in_array($provider, $allowedProviders, true)) {
        Http::error('Nome, slug ou plataforma inválidos.', 422);
    }
    if ($checkout !== '' && (!filter_var($checkout, FILTER_VALIDATE_URL) || strtolower((string) parse_url($checkout, PHP_URL_SCHEME)) !== 'https')) {
        Http::error('O link de checkout deve ser um URL HTTPS válido.', 422);
    }
    if ($pixel !== '' && !preg_match('/^\d{5,32}$/', $pixel)) {
        Http::error('O ID do pixel deve conter apenas números (5 a 32 dígitos).', 422);
    }
    Db::exec(
        'INSERT INTO cms_products (name,slug,rel_path,base_url,checkout_provider,checkout_url,meta_pixel_id,created_at,updated_at)
         VALUES (:name,:slug,:path,:url,:provider,:checkout,:pixel,:created,:updated)',
        ['name'=>$name, 'slug'=>$slug, 'path'=>$path, 'url'=>$url, 'provider'=>$provider, 'checkout'=>$checkout, 'pixel'=>$pixel, 'created'=>date('Y-m-d H:i:s'), 'updated'=>date('Y-m-d H:i:s')]
    );
    Http::ok(['product' => Db::one('SELECT * FROM cms_products WHERE id=:id', ['id'=>Db::lastId()])]);
} catch (\PDOException $e) {
    if ((string) $e->getCode() === '23000') {
        Http::error('Já existe um produto com este slug ou pasta.', 409);
    }
    throw $e;
}
