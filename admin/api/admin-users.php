<?php
declare(strict_types=1);

function admin_accounts_table_exists(): bool
{
    $pdo = admin_pdo();
    if (!$pdo) {
        return false;
    }
    try {
        $pdo->query('SELECT 1 FROM admin_users LIMIT 1');
        return true;
    } catch (PDOException) {
        return false;
    }
}

function admin_accounts_enabled(): bool
{
    if (!admin_use_database() || !admin_db_connected() || !admin_accounts_table_exists()) {
        return false;
    }
    $pdo = admin_pdo();
    if (!$pdo) {
        return false;
    }
    $n = (int) $pdo->query('SELECT COUNT(*) FROM admin_users WHERE ativo = 1')->fetchColumn();
    return $n > 0;
}

function admin_session_user_id(): ?int
{
    if (empty($_SESSION['admin_user_id'])) {
        return null;
    }
    $id = (int) $_SESSION['admin_user_id'];
    return $id > 0 ? $id : null;
}

/** @return array<string, mixed>|null */
function admin_find_by_login(string $login): ?array
{
    $pdo = admin_pdo();
    if (!$pdo) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT * FROM admin_users WHERE login = :login AND ativo = 1 LIMIT 1'
    );
    $stmt->execute(['login' => $login]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/** @return array<string, mixed>|null */
function admin_find_by_id(int $id): ?array
{
    $pdo = admin_pdo();
    if (!$pdo) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM admin_users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function admin_verify_password(array $row, string $password): bool
{
    $hash = (string) ($row['password_hash'] ?? '');
    if ($hash === '' || $password === '') {
        return false;
    }
    return password_verify($password, $hash);
}

/** @return array<string, mixed> */
function admin_public_profile(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'nome' => (string) $row['nome'],
        'login' => (string) $row['login'],
        'email' => (string) $row['email'],
        'ativo' => (int) ($row['ativo'] ?? 0) === 1,
    ];
}
