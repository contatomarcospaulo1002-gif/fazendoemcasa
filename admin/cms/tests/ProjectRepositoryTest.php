<?php
declare(strict_types=1);

/**
 * Testes do ProjectRepository contra MySQL real.
 *
 * Usa um banco de teste isolado (fec_cms_test), NUNCA o de produção.
 * As credenciais vêm de variáveis de ambiente.
 *
 * Uso:
 *   $env:DB_DATABASE='fec_cms_test'; $env:DB_USERNAME='fec_test';
 *   $env:DB_PASSWORD='fec_test_pw'; php admin/cms/tests/ProjectRepositoryTest.php
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/TestRunner.php';

use FazendoEmCasa\Cms\Core\Db;
use FazendoEmCasa\Cms\Core\Migrator;
use FazendoEmCasa\Cms\Core\PathGuard;
use FazendoEmCasa\Cms\Projects\ProjectException;
use FazendoEmCasa\Cms\Projects\ProjectRepository;

$t = new TestRunner();

$dbName = (string) (getenv('DB_DATABASE') ?: '');
if (!str_contains($dbName, 'test')) {
    fwrite(STDERR, "ABORTADO: DB_DATABASE deve conter 'test'. Atual: '$dbName'\n");
    fwrite(STDERR, "Este teste cria e apaga tabelas. Nunca roda em produção.\n");
    exit(2);
}
if (!Db::connected()) {
    fwrite(STDERR, "Sem conexão com MySQL de teste.\n");
    exit(2);
}

// Aplica migrations (idempotente).
$migrator = new Migrator(dirname(__DIR__) . '/Migrations');
$migrator->migrate();

// Limpa estado anterior.
Db::exec('DELETE FROM cms_projects');

// Fixture em disco.
$base = sys_get_temp_dir() . '/cms-repo-' . bin2hex(random_bytes(4));
foreach (['loja-a', 'loja-b', 'loja-c'] as $d) {
    mkdir($base . '/' . $d, 0777, true);
    file_put_contents($base . '/' . $d . '/index.html', "<h1>$d</h1>");
}

$guard = new PathGuard($base);
$repo = new ProjectRepository($guard);

$t->group('Repository — criar projeto');

$a = $repo->create([
    'slug' => 'loja-a',
    'name' => 'Loja A',
    'rel_path' => 'loja-a',
    'description' => 'primeira',
]);
$t->ok('criou com id', (int) $a['id'] > 0);
$t->same('slug gravado', 'loja-a', $a['slug']);
$t->same('status padrão active', 'active', $a['status']);

$b = $repo->create(['slug' => 'loja-b', 'name' => 'Loja B', 'rel_path' => 'loja-b']);
$t->ok('segundo projeto tem id diferente', (int) $b['id'] !== (int) $a['id']);

$t->group('Repository — unicidade');

$t->throws(
    'rejeita slug duplicado',
    fn () => $repo->create(['slug' => 'loja-a', 'name' => 'X', 'rel_path' => 'loja-c']),
    ProjectException::class
);
$t->throws(
    'rejeita diretório duplicado',
    fn () => $repo->create(['slug' => 'loja-x', 'name' => 'X', 'rel_path' => 'loja-a']),
    ProjectException::class
);
$t->throws(
    'rejeita diretório inexistente',
    fn () => $repo->create(['slug' => 'loja-y', 'name' => 'Y', 'rel_path' => 'nao-existe']),
    \Throwable::class
);
$t->throws(
    'rejeita path traversal no rel_path',
    fn () => $repo->create(['slug' => 'loja-z', 'name' => 'Z', 'rel_path' => '../fora']),
    \Throwable::class
);

$t->group('Repository — resolveProjectDir (isolamento)');

$dirA = $repo->resolveProjectDir((int) $a['id']);
$dirB = $repo->resolveProjectDir((int) $b['id']);
$t->same('dir de A correto', realpath($base . '/loja-a'), $dirA);
$t->same('dir de B correto', realpath($base . '/loja-b'), $dirB);
$t->ok('dirs são distintos', $dirA !== $dirB);
$t->ok('dir de A não contém dir de B', !str_starts_with($dirB, $dirA . DIRECTORY_SEPARATOR));

$t->group('Repository — id inexistente');

$t->throws('resolveProjectDir(999999) falha', fn () => $repo->resolveProjectDir(999999), ProjectException::class);
$t->same('find(999999) é null', null, $repo->find(999999));

$t->group('Repository — desativar projeto');

$repo->setStatus((int) $b['id'], 'disabled');
$t->throws(
    'projeto desativado não resolve diretório',
    fn () => $repo->resolveProjectDir((int) $b['id']),
    ProjectException::class
);
$t->same('A continua ativo', 'active', (string) $repo->find((int) $a['id'])['status']);

$t->group('Repository — listagem');

$ativos = $repo->listAll(['status' => 'active']);
$t->same('1 projeto ativo', 1, count($ativos));
$todos = $repo->listAll();
$t->same('2 projetos no total', 2, count($todos));

$t->group('Repository — atualizar');

$upd = $repo->update((int) $a['id'], ['name' => 'Loja A Renomeada', 'description' => 'nova']);
$t->same('nome atualizado', 'Loja A Renomeada', $upd['name']);
$t->same('descrição atualizada', 'nova', $upd['description']);
$t->throws(
    'status inválido é rejeitado',
    fn () => $repo->update((int) $a['id'], ['status' => 'hackeado']),
    ProjectException::class
);

$t->group('Repository — slug normalizado');

$n = $repo->create(['slug' => 'Loja   C!!!', 'name' => 'Loja C', 'rel_path' => 'loja-c']);
$t->same('slug normalizado', 'loja-c', $n['slug']);

// Limpeza.
Db::exec('DELETE FROM cms_projects');
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($it as $f) {
    $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
}
@rmdir($base);

exit($t->summary());
