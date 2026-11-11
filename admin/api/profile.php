<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (!admin_accounts_enabled()) {
    admin_json(['ok' => false, 'error' => 'Contas de administrador não ativas.'], 503);
}

admin_require_auth();

$userId = admin_session_user_id();
if ($userId === null) {
    admin_json(['ok' => false, 'error' => 'Não autenticado.'], 401);
}

$row = admin_find_by_id($userId);
if ($row === null || (int) ($row['ativo'] ?? 0) !== 1) {
    admin_json(['ok' => false, 'error' => 'Utilizador não encontrado.'], 404);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    admin_json(['ok' => true, 'profile' => admin_public_profile($row)]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    admin_json(['ok' => false, 'error' => 'Método inválido.'], 405);
}

$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) {
    admin_json(['ok' => false, 'error' => 'JSON inválido.'], 400);
}

$nome = trim((string) ($body['nome'] ?? ''));
$email = trim((string) ($body['email'] ?? ''));

if ($nome === '' || $email === '') {
    admin_json(['ok' => false, 'error' => 'Nome e e-mail são obrigatórios.'], 400);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    admin_json(['ok' => false, 'error' => 'E-mail inválido.'], 400);
}

$pdo = admin_pdo();
if (!$pdo) {
    admin_json(['ok' => false, 'error' => 'Base de dados indisponível.'], 503);
}

try {
    $stmt = $pdo->prepare(
        'UPDATE admin_users SET nome = :nome, email = :email WHERE id = :id'
    );
    $stmt->execute(['nome' => $nome, 'email' => $email, 'id' => $userId]);
} catch (PDOException $e) {
    if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
        admin_json(['ok' => false, 'error' => 'Este e-mail já está em uso.'], 400);
    }
    admin_json(['ok' => false, 'error' => 'Não foi possível atualizar o perfil.'], 500);
}

$row = admin_find_by_id($userId);
admin_json(['ok' => true, 'profile' => admin_public_profile($row ?: [])]);
