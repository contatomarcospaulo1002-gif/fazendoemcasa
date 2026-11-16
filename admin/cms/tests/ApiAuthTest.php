<?php
declare(strict_types=1);

/**
 * Testes autenticados da API de projetos.
 *
 * Cria um admin temporário no banco de TESTE, faz login, e valida:
 *  - CSRF obrigatório em POST
 *  - criação de projeto
 *  - isolamento (project_id forjado)
 *  - path traversal via API
 *
 * Uso:
 *   php admin/cms/tests/ApiAuthTest.php --base=http://127.0.0.1:8099
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/HttpSession.php';

use FazendoEmCasa\Cms\Core\Db;

$base = null;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--base=')) {
        $base = rtrim(substr($a, 7), '/');
    }
}
if ($base === null) {
    fwrite(STDERR, "Uso: php ApiAuthTest.php --base=http://127.0.0.1:8099\n");
    exit(2);
}

$dbName = (string) (getenv('DB_DATABASE') ?: '');
if (!str_contains($dbName, 'test')) {
    fwrite(STDERR, "ABORTADO: DB_DATABASE deve conter 'test'.\n");
    exit(2);
}
if (!Db::connected()) {
    fwrite(STDERR, "Sem conexão com o banco de teste.\n");
    exit(2);
}

$passed = 0;
$failed = 0;
$failures = [];

function ok(string $label, bool $cond, string $detail = ''): void
{
    global $passed, $failed, $failures;
    if ($cond) {
        $passed++;
        echo "  OK   $label\n";
        return;
    }
    $failed++;
    $failures[] = $label . ($detail !== '' ? " — $detail" : '');
    echo "  FAIL $label" . ($detail !== '' ? " — $detail" : '') . "\n";
}

// --- Prepara admin temporário ---
$login = 'cms_test_admin';
$password = 'TesteForte123!';
Db::exec('DELETE FROM admin_users WHERE login = :l', ['l' => $login]);
Db::exec(
    'INSERT INTO admin_users (nome, login, email, password_hash, ativo)
     VALUES (:n, :l, :e, :h, 1)',
    [
        'n' => 'CMS Test',
        'l' => $login,
        'e' => 'cms-test@example.test',
        'h' => password_hash($password, PASSWORD_DEFAULT),
    ]
);

// --- Fixture de projetos em disco ---
// Caminho fixo para que o servidor (outro processo) possa ser iniciado
// com CMS_PROJECTS_ROOT apontando para cá.
$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cms-api-fixture';
if (!is_dir($root)) {
    mkdir($root, 0777, true);
}
foreach (['proj-x', 'proj-y'] as $d) {
    if (!is_dir($root . '/' . $d)) {
        mkdir($root . '/' . $d, 0777, true);
    }
    file_put_contents($root . '/' . $d . '/index.html', "<h1>$d</h1>");
}

$envRoot = (string) (getenv('CMS_PROJECTS_ROOT') ?: '');
if ($envRoot === '' || realpath($envRoot) !== realpath($root)) {
    fwrite(STDERR, "ABORTADO: inicie o servidor com CMS_PROJECTS_ROOT=$root\n");
    fwrite(STDERR, "Valor atual: '$envRoot'\n");
    exit(2);
}

$s = new HttpSession($base);

echo "\n== Login ==\n";
$r = $s->request('/admin/api/login.php', 'POST', ['login' => $login, 'password' => $password]);
ok('login retorna 200', $r['code'] === 200, 'code=' . $r['code'] . ' body=' . substr($r['body'], 0, 120));

$r = $s->request('/admin/api/session.php');
ok('sessão autenticada', ($r['json']['authenticated'] ?? false) === true);

echo "\n== CSRF ==\n";
$r = $s->request('/admin/api/cms-projects.php', 'POST', ['slug' => 'sem-token', 'name' => 'X', 'rel_path' => 'proj-x']);
ok('POST sem token CSRF é rejeitado', $r['code'] === 403, 'code=' . $r['code']);

$r = $s->request('/admin/api/cms-projects.php', 'POST', ['slug' => 'token-errado', 'name' => 'X', 'rel_path' => 'proj-x'], ['X-CSRF-Token: ' . str_repeat('a', 64)]);
ok('POST com token inválido é rejeitado', $r['code'] === 403, 'code=' . $r['code']);

echo "\n== Listagem autenticada ==\n";
$r = $s->request('/admin/api/cms-projects.php');
ok('GET lista retorna 200', $r['code'] === 200, 'code=' . $r['code']);
$token = (string) ($r['json']['csrfToken'] ?? '');
ok('csrfToken presente', strlen($token) === 64, 'len=' . strlen($token));

echo "\n== Criação com CSRF válido ==\n";
$r = $s->request(
    '/admin/api/cms-projects.php',
    'POST',
    ['slug' => 'proj-x', 'name' => 'Projeto X', 'rel_path' => 'proj-x'],
    ['X-CSRF-Token: ' . $token]
);
ok('criação retorna 200', $r['code'] === 200, 'code=' . $r['code'] . ' body=' . substr($r['body'], 0, 160));
$idX = (int) ($r['json']['project']['id'] ?? 0);
ok('projeto criado tem id', $idX > 0);

echo "\n== Path traversal via API ==\n";
$r = $s->request(
    '/admin/api/cms-projects.php',
    'POST',
    ['slug' => 'traversal', 'name' => 'T', 'rel_path' => '../../etc'],
    ['X-CSRF-Token: ' . $token]
);
ok('rel_path com .. é rejeitado', $r['code'] === 422, 'code=' . $r['code']);

$r = $s->request(
    '/admin/api/cms-projects.php',
    'POST',
    ['slug' => 'absoluto', 'name' => 'A', 'rel_path' => '/etc/passwd'],
    ['X-CSRF-Token: ' . $token]
);
ok('rel_path absoluto é rejeitado', $r['code'] === 422, 'code=' . $r['code']);

echo "\n== Isolamento ==\n";
$r = $s->request('/admin/api/cms-projects.php?id=999999');
ok('id inexistente retorna 404', $r['code'] === 404, 'code=' . $r['code']);

$r = $s->request(
    '/admin/api/cms-projects.php?id=999999',
    'POST',
    ['name' => 'Hack'],
    ['X-CSRF-Token: ' . $token]
);
// 403 (sem permissão) ou 404/422 (não encontrado) — todos recusam.
ok('atualizar id inexistente é recusado', in_array($r['code'], [403, 404, 422], true), 'code=' . $r['code']);

echo "\n== Descoberta autenticada ==\n";
$r = $s->request('/admin/api/cms-projects.php?discover=1');
ok('discover retorna 200', $r['code'] === 200, 'code=' . $r['code']);
ok('discover retorna lista', is_array($r['json']['candidates'] ?? null));

echo "\n== Dados internos exigem sessão ==\n";
$r = $s->request('/admin/api/content.php');
ok('content.php autenticado retorna 200', $r['code'] === 200, 'code=' . $r['code']);
ok('content.php devolve conteúdo', is_array($r['json']['content'] ?? null));

$r = $s->request('/admin/api/site-map.php');
ok('site-map.php autenticado retorna 200', $r['code'] === 200, 'code=' . $r['code']);
ok('site-map.php devolve mapa', is_array($r['json']['map'] ?? null));

// Sem sessão: novo cliente, sem cookies.
$anon = new HttpSession($base);
$r = $anon->request('/admin/api/content.php');
ok('content.php sem sessão retorna 401', $r['code'] === 401, 'code=' . $r['code']);
ok('content.php sem sessão não vaza conteúdo', !isset($r['json']['content']));

$r = $anon->request('/admin/api/site-map.php');
ok('site-map.php sem sessão retorna 401', $r['code'] === 401, 'code=' . $r['code']);
ok('site-map.php sem sessão não vaza mapa', !isset($r['json']['map']));

// --- Limpeza ---
Db::exec('DELETE FROM cms_projects');
Db::exec('DELETE FROM admin_users WHERE login = :l', ['l' => $login]);

echo "\n";
if ($failed === 0) {
    echo "OK $passed/" . ($passed + $failed) . "\n";
    exit(0);
}
echo "FALHAS: $failed de " . ($passed + $failed) . "\n";
foreach ($failures as $f) {
    echo "  - $f\n";
}
exit(1);
