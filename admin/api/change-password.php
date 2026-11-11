<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (!admin_accounts_enabled()) {
    admin_json(['ok' => false, 'error' => 'Contas de administrador não ativas.'], 503);
}

admin_require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    admin_json(['ok' => false, 'error' => 'Método inválido.'], 405);
}

$userId = admin_session_user_id();
if ($userId === null) {
    admin_json(['ok' => false, 'error' => 'Não autenticado.'], 401);
}

$row = admin_find_by_id($userId);
if ($row === null) {
    admin_json(['ok' => false, 'error' => 'Utilizador não encontrado.'], 404);
}

$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) {
    admin_json(['ok' => false, 'error' => 'JSON inválido.'], 400);
}

$current = (string) ($body['currentPassword'] ?? '');
$next = (string) ($body['newPassword'] ?? '');

if ($current === '' || $next === '') {
    admin_json(['ok' => false, 'error' => 'Senha atual e nova senha são obrigatórias.'], 400);
}

if (strlen($next) < 8) {
    admin_json(['ok' => false, 'error' => 'A nova senha deve ter pelo menos 8 caracteres.'], 400);
}

if (!admin_verify_password($row, $current)) {
    admin_json(['ok' => false, 'error' => 'Senha atual incorreta.'], 403);
}

$hash = password_hash($next, PASSWORD_DEFAULT);
$pdo = admin_pdo();
if (!$pdo) {
    admin_json(['ok' => false, 'error' => 'Base de dados indisponível.'], 503);
}

$stmt = $pdo->prepare('UPDATE admin_users SET password_hash = :hash WHERE id = :id');
$stmt->execute(['hash' => $hash, 'id' => $userId]);

admin_json(['ok' => true]);
