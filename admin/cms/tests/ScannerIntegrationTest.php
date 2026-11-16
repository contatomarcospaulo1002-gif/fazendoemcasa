<?php
declare(strict_types=1);

/**
 * Integração do scanner com o banco: varredura, incremental e remoção.
 *
 * Requer banco de teste (DB_DATABASE contendo 'test').
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/TestRunner.php';

use FazendoEmCasa\Cms\Core\Db;
use FazendoEmCasa\Cms\Core\Migrator;
use FazendoEmCasa\Cms\Core\PathGuard;
use FazendoEmCasa\Cms\Projects\ProjectRepository;
use FazendoEmCasa\Cms\Scanner\ScanService;

$t = new TestRunner();

$dbName = (string) (getenv('DB_DATABASE') ?: '');
if (!str_contains($dbName, 'test')) {
    fwrite(STDERR, "ABORTADO: DB_DATABASE deve conter 'test'.\n");
    exit(2);
}
if (!Db::connected()) {
    fwrite(STDERR, "Sem conexão com o banco de teste.\n");
    exit(2);
}

$migrator = new Migrator(dirname(__DIR__) . '/Migrations');
$migrator->migrate();

Db::exec('DELETE FROM cms_projects');

// Fixture.
$base = sys_get_temp_dir() . '/cms-scan-' . bin2hex(random_bytes(4));
mkdir($base . '/loja/assets', 0777, true);
mkdir($base . '/loja/paginas', 0777, true);
mkdir($base . '/loja/node_modules', 0777, true);

file_put_contents($base . '/loja/index.html', '<html><head><title>Loja</title></head><body>'
    . '<h1 data-cms-id="t" data-cms-type="text">Ola</h1>'
    . '<img src="assets/a.webp" alt="A"></body></html>');
file_put_contents($base . '/loja/paginas/sobre.html', '<html><head><title>Sobre</title></head><body>'
    . '<p data-cms-id="p">Texto</p></body></html>');
file_put_contents($base . '/loja/assets/style.css', 'body{background:url(bg.png)}');
file_put_contents($base . '/loja/assets/app.js', 'console.log(1);');
file_put_contents($base . '/loja/node_modules/lib.js', 'ignorar');
file_put_contents($base . '/loja/ignorar.txt', 'nao analisado');

$guard = new PathGuard($base);
$repo = new ProjectRepository($guard);
$service = new ScanService($repo);

$project = $repo->create(['slug' => 'loja', 'name' => 'Loja', 'rel_path' => 'loja']);
$pid = (int) $project['id'];

$t->group('Scanner — primeira varredura');
$r1 = $service->scanProject($pid);
$t->same('encontrou 4 arquivos', 4, $r1['found']);
$t->same('todos novos', 4, $r1['changed']);
$t->same('nada removido', 0, $r1['removed']);

$pages = $service->pages($pid);
$t->same('4 páginas no inventário', 4, count($pages));
$kinds = array_column($pages, 'kind');
$t->ok('tem html', in_array('html', $kinds, true));
$t->ok('tem css', in_array('css', $kinds, true));
$t->ok('tem js', in_array('js', $kinds, true));
$t->ok('não indexou node_modules', !in_array('loja/node_modules/lib.js', array_column($pages, 'rel_path'), true));
$t->ok('não indexou .txt', !in_array('loja/ignorar.txt', array_column($pages, 'rel_path'), true));

$t->group('Scanner — entrypoint');
$entry = null;
foreach ($pages as $p) {
    if ($p['rel_path'] === 'loja/index.html') {
        $entry = $p;
    }
}
$t->same('index.html é entrypoint', 1, (int) ($entry['is_entrypoint'] ?? 0));
$t->same('título gravado', 'Loja', $entry['title'] ?? '');
$t->same('1 elemento', 1, (int) ($entry['element_count'] ?? 0));
$t->same('1 imagem', 1, (int) ($entry['image_count'] ?? 0));

$t->group('Scanner — incremental');
$r2 = $service->scanProject($pid);
$t->same('nada mudou', 0, $r2['changed']);
$t->same('4 inalterados', 4, $r2['unchanged']);

$t->group('Scanner — detecta alteração');
file_put_contents($base . '/loja/index.html', '<html><head><title>Loja Nova</title></head><body>'
    . '<h1 data-cms-id="t">Novo</h1><h2 data-cms-id="t2">Mais</h2></body></html>');
$r3 = $service->scanProject($pid);
$t->same('1 arquivo mudou', 1, $r3['changed']);
$t->same('3 inalterados', 3, $r3['unchanged']);
$updated = null;
foreach ($service->pages($pid) as $p) {
    if ($p['rel_path'] === 'loja/index.html') {
        $updated = $p;
    }
}
$t->same('título atualizado', 'Loja Nova', $updated['title'] ?? '');
$t->same('agora 2 elementos', 2, (int) ($updated['element_count'] ?? 0));

$t->group('Scanner — remove arquivo apagado');
unlink($base . '/loja/paginas/sobre.html');
$r4 = $service->scanProject($pid);
$t->same('1 removido', 1, $r4['removed']);
$t->same('3 restantes', 3, $r4['found']);

$t->group('Scanner — resumo');
$sum = $service->summary($pid);
$t->same('3 páginas no resumo', 3, $sum['pages']);
$t->ok('elementos somados', $sum['elements'] >= 2);
$t->ok('last_scan_at preenchido', $sum['last_scan_at'] !== null);

$t->group('Scanner — isolamento entre projetos');
mkdir($base . '/outro', 0777, true);
file_put_contents($base . '/outro/index.html', '<html><head><title>Outro</title></head><body></body></html>');
$p2 = $repo->create(['slug' => 'outro', 'name' => 'Outro', 'rel_path' => 'outro']);
$service->scanProject((int) $p2['id']);
$t->same('projeto 1 continua com 3', 3, count($service->pages($pid)));
$t->same('projeto 2 tem 1', 1, count($service->pages((int) $p2['id'])));
$t->ok('páginas não se misturam',
    array_intersect(
        array_column($service->pages($pid), 'rel_path'),
        array_column($service->pages((int) $p2['id']), 'rel_path')
    ) === []);

$t->group('Scanner — projeto inexistente');
$t->throws('scanProject(999999) falha', fn () => $service->scanProject(999999), \Throwable::class);

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
