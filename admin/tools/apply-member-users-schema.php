<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$projectRoot = dirname($root);
$sqlFile = $projectRoot . '/scripts/vps/03-member-users.sql';

$configPath = $root . '/config.local.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "Falta admin/config.local.php\n");
    exit(1);
}

/** @var array $config */
$config = require $configPath;
require_once $root . '/api/database.php';

if (!admin_db_connected()) {
    fwrite(STDERR, "MySQL indisponível.\n");
    exit(1);
}

if (!is_file($sqlFile)) {
    fwrite(STDERR, "SQL em falta: $sqlFile\n");
    exit(1);
}

$sql = trim((string) file_get_contents($sqlFile));
if ($sql === '') {
    fwrite(STDERR, "SQL vazio.\n");
    exit(1);
}

$pdo = admin_pdo();
if (!$pdo) {
    exit(1);
}

try {
    $pdo->exec($sql);
} catch (PDOException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, "OK member_users\n");
