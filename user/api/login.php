<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    member_json(['ok' => false, 'error' => 'Método inválido.'], 405);
}

member_require_db();

$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) {
    member_json(['ok' => false, 'error' => 'JSON inválido.'], 400);
}

$login = trim((string) ($body['login'] ?? ''));
$password = (string) ($body['password'] ?? '');

if ($login === '' || $password === '') {
    member_json(['ok' => false, 'error' => 'Login e senha são obrigatórios.'], 400);
}

$row = member_find_by_login($login);
if ($row === null || !member_verify_password($row, $password)) {
    member_json(['ok' => false, 'error' => 'Login ou senha incorretos.'], 403);
}

$_SESSION['member_user_id'] = (int) $row['id'];

member_json([
    'ok' => true,
    'profile' => member_public_profile($row),
]);
