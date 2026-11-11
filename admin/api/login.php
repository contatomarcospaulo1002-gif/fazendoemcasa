<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    admin_json(['ok' => false, 'error' => 'Método inválido.'], 405);
}

$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) {
    admin_json(['ok' => false, 'error' => 'JSON inválido.'], 400);
}

if (admin_accounts_enabled()) {
    $login = trim((string) ($body['login'] ?? ''));
    $password = (string) ($body['password'] ?? '');

    if ($login === '' || $password === '') {
        admin_json(['ok' => false, 'error' => 'Login e senha são obrigatórios.'], 400);
    }

    $row = admin_find_by_login($login);
    if ($row === null || !admin_verify_password($row, $password)) {
        admin_json(['ok' => false, 'error' => 'Login ou senha incorretos.'], 403);
    }

    $_SESSION['admin_user_id'] = (int) $row['id'];
    unset($_SESSION['admin_ok']);

    admin_json([
        'ok' => true,
        'profile' => admin_public_profile($row),
    ]);
}

$pin = isset($body['pin']) ? (string) $body['pin'] : '';
if ($pin === '' || !hash_equals((string) ($config['pin'] ?? ''), $pin)) {
    admin_json(['ok' => false, 'error' => 'PIN incorreto.'], 403);
}

$_SESSION['admin_ok'] = true;
admin_json(['ok' => true]);
