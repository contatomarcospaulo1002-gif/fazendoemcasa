<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

admin_json([
    'ok' => true,
    'authenticated' => !empty($_SESSION['admin_ok']),
    'php' => true,
]);
