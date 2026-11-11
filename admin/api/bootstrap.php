<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$root = dirname(__DIR__);
$projectRoot = dirname($root);

$configPath = $root . '/config.local.php';
if (!is_file($configPath)) {
    $configPath = $root . '/config.example.php';
}
/** @var array{pin?:string,require_pin?:bool,use_database?:bool,db?:array} $config */
$config = require $configPath;

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/admin-users.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function admin_pin_required(): bool
{
    global $config;
    if (array_key_exists('require_pin', $config)) {
        return (bool) $config['require_pin'];
    }
    $pin = isset($config['pin']) ? (string) $config['pin'] : '';
    return $pin !== '';
}

function admin_json(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function admin_require_auth(): void
{
    if (admin_accounts_enabled()) {
        if (admin_session_user_id() === null) {
            admin_json(['ok' => false, 'error' => 'Não autenticado.'], 401);
        }
        return;
    }
    if (!admin_pin_required()) {
        return;
    }
    if (empty($_SESSION['admin_ok'])) {
        admin_json(['ok' => false, 'error' => 'Não autenticado.'], 401);
    }
}

function admin_paths(): array
{
    $root = dirname(__DIR__);
    return [
        'admin' => $root,
        'project' => dirname($root),
        'siteMap' => $root . '/site-map.json',
        'content' => $root . '/content.json',
        'index' => dirname($root) . '/index.html',
        'imgCms' => dirname($root) . '/img/cms',
    ];
}
