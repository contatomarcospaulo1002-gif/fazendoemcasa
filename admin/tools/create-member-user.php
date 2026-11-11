<?php
declare(strict_types=1);

/**
 * Criar utilizador da área /user (MySQL).
 *
 * Uso:
 *   php admin/tools/create-member-user.php \
 *     --nome "Maria Silva" --login maria --email maria@exemplo.pt \
 *     --senha 'SenhaForte123' --valor 14.90 --ate 2026-12-31 [--desde 2026-01-01]
 *
 * Alternativa manual: INSERT em member_users com password_hash gerado por:
 *   php -r "echo password_hash('SUA_SENHA', PASSWORD_DEFAULT), PHP_EOL;"
 */

$root = dirname(__DIR__);
$projectRoot = dirname($root);

$configPath = $root . '/config.local.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "Falta admin/config.local.php (use_database + db).\n");
    exit(1);
}

/** @var array $config */
$config = require $configPath;

require_once $root . '/api/database.php';

if (!admin_db_connected()) {
    fwrite(STDERR, "Não foi possível ligar ao MySQL. Verifica use_database e db.\n");
    exit(1);
}

$opts = getopt('', [
    'nome:',
    'login:',
    'email:',
    'senha:',
    'valor::',
    'desde::',
    'ate:',
    'inativo',
]);

$nome = trim((string) ($opts['nome'] ?? ''));
$login = trim((string) ($opts['login'] ?? ''));
$email = trim((string) ($opts['email'] ?? ''));
$senha = (string) ($opts['senha'] ?? '');
$valor = isset($opts['valor']) ? (float) $opts['valor'] : 14.9;
$desde = isset($opts['desde']) ? (string) $opts['desde'] : null;
$ate = trim((string) ($opts['ate'] ?? ''));
$ativo = isset($opts['inativo']) ? 0 : 1;

foreach (['nome' => $nome, 'login' => $login, 'email' => $email, 'senha' => $senha, 'ate' => $ate] as $k => $v) {
    if ($v === '') {
        fwrite(STDERR, "Parâmetro obrigatório em falta: --$k\n");
        exit(1);
    }
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "E-mail inválido.\n");
    exit(1);
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ate)) {
    fwrite(STDERR, "--ate deve ser YYYY-MM-DD\n");
    exit(1);
}

if ($desde !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) {
    fwrite(STDERR, "--desde deve ser YYYY-MM-DD\n");
    exit(1);
}

$hash = password_hash($senha, PASSWORD_DEFAULT);
$pdo = admin_pdo();
if (!$pdo) {
    exit(1);
}

$sql = 'INSERT INTO member_users (nome, login, email, password_hash, valor_pago, plano_valido_desde, plano_valido_ate, ativo)
        VALUES (:nome, :login, :email, :hash, :valor, :desde, :ate, :ativo)';

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'nome' => $nome,
        'login' => $login,
        'email' => $email,
        'hash' => $hash,
        'valor' => $valor,
        'desde' => $desde,
        'ate' => $ate,
        'ativo' => $ativo,
    ]);
} catch (PDOException $e) {
    if ((int) $e->errorInfo[1] === 1062) {
        fwrite(STDERR, "Login ou e-mail já existe.\n");
    } else {
        fwrite(STDERR, $e->getMessage() . "\n");
        fwrite(STDERR, "A tabela member_users existe? Corre scripts/vps/03-member-users.sql\n");
    }
    exit(1);
}

fwrite(STDOUT, "OK id=" . $pdo->lastInsertId() . " login=$login\n");
