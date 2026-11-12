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
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
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

/**
 * Substitui um ficheiro mesmo quando o destino existe e não é gravável
 * (por exemplo, dono root e modo 644 depois de um deploy). A pasta tem de
 * ser gravável pelo PHP. No Linux, rename() troca o nome mesmo sem permissão
 * de escrita no ficheiro antigo.
 */
function admin_write_bytes(string $path, string $contents): bool
{
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }

    $tmp = $dir . DIRECTORY_SEPARATOR . '.tmp-' . bin2hex(random_bytes(6)) . '-' . basename($path);
    if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
        return false;
    }
    @chmod($tmp, 0644);

    if (@rename($tmp, $path)) {
        @chmod($path, 0644);
        return true;
    }

    if (is_file($path)) {
        @chmod($path, 0644);
        if (!@unlink($path)) {
            @unlink($tmp);
            return file_put_contents($path, $contents, LOCK_EX) !== false;
        }
    }

    if (@rename($tmp, $path)) {
        @chmod($path, 0644);
        return true;
    }

    $ok = file_put_contents($path, $contents, LOCK_EX) !== false;
    @unlink($tmp);
    if ($ok) {
        @chmod($path, 0644);
    }
    return $ok;
}

/**
 * Tipo real dos bytes. O PHP de produção pode não ter a extensão fileinfo.
 */
function admin_detect_mime(string $raw): string
{
    if (class_exists('finfo')) {
        $detected = (new finfo(FILEINFO_MIME_TYPE))->buffer($raw);
        if (is_string($detected) && $detected !== '' && $detected !== 'application/octet-stream') {
            return $detected;
        }
    }
    if (str_starts_with($raw, "\x89PNG\r\n\x1a\n")) {
        return 'image/png';
    }
    if (str_starts_with($raw, "\xFF\xD8\xFF")) {
        return 'image/jpeg';
    }
    if (strlen($raw) >= 12 && str_starts_with($raw, 'RIFF') && substr($raw, 8, 4) === 'WEBP') {
        return 'image/webp';
    }
    if (strlen($raw) >= 12 && substr($raw, 4, 4) === 'ftyp') {
        return 'video/mp4';
    }
    return '';
}
