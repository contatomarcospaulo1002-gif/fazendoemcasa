<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    admin_json(['ok' => false, 'error' => 'Método inválido.'], 405);
}

$body = json_decode((string) file_get_contents('php://input'), true);
$pin = isset($body['pin']) ? (string) $body['pin'] : '';

if ($pin === '' || !hash_equals((string) $config['pin'], $pin)) {
    admin_json(['ok' => false, 'error' => 'PIN incorreto.'], 403);
}

$_SESSION['admin_ok'] = true;
admin_json(['ok' => true]);
