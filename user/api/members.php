<?php
declare(strict_types=1);

/** @return array<string, mixed>|null */
function member_find_by_login(string $login): ?array
{
    $pdo = admin_pdo();
    if (!$pdo) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT * FROM member_users WHERE login = :login AND ativo = 1 LIMIT 1'
    );
    $stmt->execute(['login' => $login]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/** @return array<string, mixed>|null */
function member_find_by_id(int $id): ?array
{
    $pdo = admin_pdo();
    if (!$pdo) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM member_users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function member_verify_password(array $row, string $password): bool
{
    $hash = (string) ($row['password_hash'] ?? '');
    if ($hash === '' || $password === '') {
        return false;
    }
    return password_verify($password, $hash);
}

function member_plan_active(array $row): bool
{
    if ((int) ($row['ativo'] ?? 0) !== 1) {
        return false;
    }
    $ate = (string) ($row['plano_valido_ate'] ?? '');
    if ($ate === '') {
        return false;
    }
    $today = gmdate('Y-m-d');
    return $ate >= $today;
}

/** Dados seguros para o cliente (sem password). */
function member_public_profile(array $row): array
{
    $planoAtivo = member_plan_active($row);
    return [
        'id' => (int) $row['id'],
        'nome' => (string) $row['nome'],
        'login' => (string) $row['login'],
        'email' => (string) $row['email'],
        'valor_pago' => (float) $row['valor_pago'],
        'plano_valido_desde' => $row['plano_valido_desde'] !== null ? (string) $row['plano_valido_desde'] : null,
        'plano_valido_ate' => (string) $row['plano_valido_ate'],
        'plano_ativo' => $planoAtivo,
        'ativo' => (int) $row['ativo'] === 1,
    ];
}
