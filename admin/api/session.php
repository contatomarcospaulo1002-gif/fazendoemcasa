<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (!admin_pin_required()) {
    $_SESSION['admin_ok'] = true;
}

admin_json([
    'ok' => true,
    'authenticated' => !empty($_SESSION['admin_ok']),
    'php' => true,
    'pinRequired' => admin_pin_required(),
    'database' => admin_db_connected(),
    'useDatabase' => admin_use_database(),
]);
