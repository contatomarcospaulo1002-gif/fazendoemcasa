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

// Concede permissão de owner ao admin de teste (o modelo exige linha
// explícita quando cms_users_permissions existe).
if (Db::tableExists('cms_users_permissions')) {
    $adminRow = Db::one('SELECT id FROM admin_users WHERE login = :l', ['l' => $login]);
    if ($adminRow !== null) {
        Db::exec(
            'INSERT INTO cms_users_permissions (user_id, project_id, role)
             VALUES (:u, NULL, :r)',
            ['u' => (int) $adminRow['id'], 'r' => 'owner']
        );
    }
}

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

echo "\n== Registry de elementos (FASE 3) ==\n";
$r = $s->request('/admin/api/cms-elements.php?project=' . $idX);
ok('GET elementos retorna 200', $r['code'] === 200, 'code=' . $r['code'] . ' body=' . substr($r['body'], 0, 160));
ok('devolve contagem por nível', is_array($r['json']['counts'] ?? null));
ok('devolve os 4 níveis', count($r['json']['levels'] ?? []) === 4);
ok('devolve adaptadores', is_array($r['json']['adapters'] ?? null));

$adapters = $r['json']['adapters'] ?? [];
$byName = [];
foreach ($adapters as $a) {
    $byName[(string) ($a['name'] ?? '')] = $a;
}
ok('adaptador html declara escrita', ($byName['html']['can_write'] ?? null) === true);
ok('adaptador php NÃO declara escrita', ($byName['php']['can_write'] ?? null) === false);
ok('adaptador css NÃO declara escrita', ($byName['css']['can_write'] ?? null) === false);
ok('adaptador json declara escrita', ($byName['json']['can_write'] ?? null) === true);

$r = $s->request('/admin/api/cms-elements.php?project=' . $idX . '&action=classify', 'POST', []);
ok('classify sem CSRF é rejeitado', $r['code'] === 403, 'code=' . $r['code']);

$r = $s->request('/admin/api/cms-elements.php?project=' . $idX . '&action=classify', 'POST', [], ['X-CSRF-Token: ' . $token]);
ok('classify com CSRF retorna 200', $r['code'] === 200, 'code=' . $r['code'] . ' body=' . substr($r['body'], 0, 200));
ok('classify devolve resultado', is_array($r['json']['result'] ?? null));

$r = $s->request('/admin/api/cms-elements.php?project=' . $idX . '&page=999999');
ok('página de outro projeto retorna 404', $r['code'] === 404, 'code=' . $r['code']);

$r = $s->request('/admin/api/cms-elements.php?project=' . $idX . '&level=inventado');
ok('nível inválido retorna 400', $r['code'] === 400, 'code=' . $r['code']);

$r = $anon->request('/admin/api/cms-elements.php?project=' . $idX);
ok('elementos sem sessão retorna 401', $r['code'] === 401, 'code=' . $r['code']);
ok('elementos sem sessão não vaza dados', !isset($r['json']['counts']));

echo "\n== Editor visual (FASE 4) ==\n";
$r = $s->request('/admin/api/cms-editor.php?project=' . $idX . '&page=999999');
ok('página inexistente retorna 404', $r['code'] === 404, 'code=' . $r['code']);

$r = $s->request('/admin/api/cms-editor.php?project=' . $idX);
ok('sem page retorna 400', $r['code'] === 400, 'code=' . $r['code']);

$r = $anon->request('/admin/api/cms-editor.php?project=' . $idX . '&page=1');
ok('editor sem sessão retorna 401', $r['code'] === 401, 'code=' . $r['code']);

$r = $s->request('/admin/api/cms-editor.php?project=' . $idX . '&page=1&action=save', 'POST', ['patch' => [1 => 'x']]);
ok('save sem CSRF é rejeitado', $r['code'] === 403, 'code=' . $r['code']);

$r = $s->request('/admin/api/cms-editor.php?project=' . $idX . '&page=1&action=save', 'POST', ['patch' => [1 => 'x']], ['X-CSRF-Token: ' . $token]);
ok('save com CSRF em página inexistente retorna 404', $r['code'] === 404, 'code=' . $r['code']);

$r = $s->request('/admin/api/cms-editor.php?project=' . $idX . '&page=1&action=inventada', 'POST', [], ['X-CSRF-Token: ' . $token]);
ok('ação inválida em página inexistente retorna 404', $r['code'] === 404, 'code=' . $r['code']);

// Página real do projeto para validar a ação inválida.
// O projeto de fixture precisa de inventário: dispara a varredura.
$s->request('/admin/api/cms-pages.php?project=' . $idX . '&action=scan', 'POST', [], ['X-CSRF-Token: ' . $token]);
$realPage = Db::one('SELECT id FROM cms_pages WHERE project_id = :p LIMIT 1', ['p' => $idX]);
if ($realPage !== null) {
    $realPageId = (int) $realPage['id'];
    $r = $s->request('/admin/api/cms-editor.php?project=' . $idX . '&page=' . $realPageId . '&action=inventada', 'POST', [], ['X-CSRF-Token: ' . $token]);
    ok('ação inválida retorna 400', $r['code'] === 400, 'code=' . $r['code']);

    $r = $s->request('/admin/api/cms-editor.php?project=' . $idX . '&page=' . $realPageId);
    ok('GET editor retorna 200', $r['code'] === 200, 'code=' . $r['code']);
    ok('GET editor devolve elementos', is_array($r['json']['elements'] ?? null));
    ok('GET editor devolve patch', is_array($r['json']['patch'] ?? null));
    ok('GET editor devolve csrfToken', strlen((string) ($r['json']['csrfToken'] ?? '')) === 64);

    $r = $s->request('/admin/api/cms-editor.php?project=' . $idX . '&page=' . $realPageId . '&preview=1');
    ok('preview retorna 200', $r['code'] === 200, 'code=' . $r['code']);
    ok('preview devolve html', is_string($r['json']['html'] ?? null));
}

echo "\n== Biblioteca de mídia (FASE 5) ==\n";
$r = $anon->request('/admin/api/cms-media.php?project=' . $idX);
ok('mídia sem sessão retorna 401', $r['code'] === 401, 'code=' . $r['code']);

$r = $s->request('/admin/api/cms-media.php');
ok('mídia sem project retorna 400', $r['code'] === 400, 'code=' . $r['code']);

$r = $s->request('/admin/api/cms-media.php?project=999999');
ok('projeto inexistente retorna 404', $r['code'] === 404, 'code=' . $r['code']);

$r = $s->request('/admin/api/cms-media.php?project=' . $idX);
ok('lista de mídia retorna 200', $r['code'] === 200, 'code=' . $r['code']);
ok('lista devolve array', is_array($r['json']['media'] ?? null));
ok('lista devolve csrfToken', strlen((string) ($r['json']['csrfToken'] ?? '')) === 64);

$r = $s->request('/admin/api/cms-media.php?project=' . $idX . '&action=upload', 'POST', []);
ok('upload sem CSRF é rejeitado', $r['code'] === 403, 'code=' . $r['code']);

$r = $s->request('/admin/api/cms-media.php?project=' . $idX . '&action=upload', 'POST', [], ['X-CSRF-Token: ' . $token]);
ok('upload sem ficheiro retorna 400', $r['code'] === 400, 'code=' . $r['code']);

$r = $s->request('/admin/api/cms-media.php?project=' . $idX . '&action=replace', 'POST', ['mediaId' => 1, 'newPath' => 'x.png', 'scope' => 'all'], ['X-CSRF-Token: ' . $token]);
ok('replace de mídia inexistente retorna 422', $r['code'] === 422, 'code=' . $r['code']);

$r = $s->request('/admin/api/cms-media.php?project=' . $idX . '&action=delete', 'POST', ['mediaId' => 999999], ['X-CSRF-Token: ' . $token]);
ok('delete de mídia inexistente retorna 404', $r['code'] === 404, 'code=' . $r['code']);

$r = $s->request('/admin/api/cms-media.php?project=' . $idX . '&action=inventada', 'POST', [], ['X-CSRF-Token: ' . $token]);
ok('ação inválida retorna 400', $r['code'] === 400, 'code=' . $r['code']);

echo "\n== Publicação (FASE 6) ==\n";
$r = $anon->request('/admin/api/cms-publish.php?project=' . $idX . '&page=1&action=publish', 'POST', ['confirm' => true]);
ok('publicar sem sessão retorna 401', $r['code'] === 401, 'code=' . $r['code']);

$r = $s->request('/admin/api/cms-publish.php?project=' . $idX . '&page=1&action=publish', 'POST', ['confirm' => true]);
ok('publicar sem CSRF é rejeitado', $r['code'] === 403, 'code=' . $r['code']);

$r = $s->request('/admin/api/cms-publish.php?project=' . $idX . '&page=1&action=publish', 'POST', ['confirm' => true], ['X-CSRF-Token: ' . $token]);
ok('página inexistente retorna 404', $r['code'] === 404, 'code=' . $r['code']);

$s->request('/admin/api/cms-pages.php?project=' . $idX . '&action=scan', 'POST', [], ['X-CSRF-Token: ' . $token]);
$realPage = Db::one('SELECT id FROM cms_pages WHERE project_id = :p LIMIT 1', ['p' => $idX]);
if ($realPage !== null) {
    $rp = (int) $realPage['id'];
    $r = $s->request('/admin/api/cms-publish.php?project=' . $idX . '&page=' . $rp . '&action=publish', 'POST', [], ['X-CSRF-Token: ' . $token]);
    ok('sem confirmação retorna 400', $r['code'] === 400, 'code=' . $r['code']);

    $r = $s->request('/admin/api/cms-publish.php?project=' . $idX . '&page=' . $rp . '&action=publish', 'POST', ['confirm' => true], ['X-CSRF-Token: ' . $token]);
    ok('sem rascunho retorna 422', $r['code'] === 422, 'code=' . $r['code']);

    $r = $s->request('/admin/api/cms-publish.php?project=' . $idX . '&page=' . $rp, 'POST', ['action' => 'inventada'], ['X-CSRF-Token: ' . $token]);
    ok('ação inválida retorna 400', $r['code'] === 400, 'code=' . $r['code']);
}

// --- Limpeza ---
Db::exec('DELETE FROM cms_projects');
if (Db::tableExists('cms_users_permissions')) {
    Db::exec('DELETE FROM cms_users_permissions WHERE user_id NOT IN (SELECT id FROM admin_users)');
}
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
