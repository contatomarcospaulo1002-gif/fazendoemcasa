<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('fec_member_sess');
    session_start();
}

$projectRoot = dirname(__DIR__, 2);
$configPath = $projectRoot . '/admin/config.local.php';
if (!is_file($configPath)) {
    $configPath = $projectRoot . '/admin/config.example.php';
}
/** @var array $config */
$config = require $configPath;

require_once $projectRoot . '/admin/api/database.php';
require_once __DIR__ . '/members.php';

function member_json(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function member_require_db(): void
{
    if (!admin_use_database() || !admin_db_connected()) {
        member_json(['ok' => false, 'error' => 'Base de dados indisponível.'], 503);
    }
}

function member_require_auth(): void
{
    $id = member_session_id();
    if ($id === null) {
        member_json(['ok' => false, 'error' => 'Não autenticado.'], 401);
    }
}

function member_session_id(): ?int
{
    if (empty($_SESSION['member_user_id'])) {
        return null;
    }
    $id = (int) $_SESSION['member_user_id'];
    return $id > 0 ? $id : null;
}
