<?php
declare(strict_types=1);

/**
 * Criar administrador do painel /admin.
 *
 *   php admin/tools/create-admin-user.php \
 *     --nome "Marcos" --login marcos --email email@exemplo.pt --senha 'SenhaForte'
 */

$root = dirname(__DIR__);
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

$opts = getopt('', ['nome:', 'login:', 'email:', 'senha:']);

$nome = trim((string) ($opts['nome'] ?? ''));
$login = trim((string) ($opts['login'] ?? ''));
$email = trim((string) ($opts['email'] ?? ''));
$senha = (string) ($opts['senha'] ?? '');

foreach (['nome' => $nome, 'login' => $login, 'email' => $email, 'senha' => $senha] as $k => $v) {
    if ($v === '') {
        fwrite(STDERR, "Parâmetro obrigatório: --$k\n");
        exit(1);
    }
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "E-mail inválido.\n");
    exit(1);
}

$hash = password_hash($senha, PASSWORD_DEFAULT);
$pdo = admin_pdo();
if (!$pdo) {
    exit(1);
}

$sql = 'INSERT INTO admin_users (nome, login, email, password_hash, ativo)
        VALUES (:nome, :login, :email, :hash, 1)';

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'nome' => $nome,
        'login' => $login,
        'email' => $email,
        'hash' => $hash,
    ]);
} catch (PDOException $e) {
    if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
        fwrite(STDERR, "Login ou e-mail já existe.\n");
    } else {
        fwrite(STDERR, $e->getMessage() . "\n");
        fwrite(STDERR, "Tabela admin_users? Corre apply-admin-users-schema.php\n");
    }
    exit(1);
}

fwrite(STDOUT, "OK id=" . $pdo->lastInsertId() . " login=$login\n");
