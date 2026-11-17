<?php
declare(strict_types=1);

/**
 * Lock de publicação: exclusão mútua entre processos.
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/TestRunner.php';

use FazendoEmCasa\Cms\Core\Db;
use FazendoEmCasa\Cms\Core\Migrator;
use FazendoEmCasa\Cms\Core\PathGuard;
use FazendoEmCasa\Cms\Editor\DraftService;
use FazendoEmCasa\Cms\Projects\ProjectRepository;
use FazendoEmCasa\Cms\Publisher\Publisher;
use FazendoEmCasa\Cms\Registry\RegistryService;
use FazendoEmCasa\Cms\Scanner\ScanService;

$t = new TestRunner();

$dbName = (string) (getenv('DB_DATABASE') ?: '');
if (!str_contains($dbName, 'test')) {
    fwrite(STDERR, "ABORTADO.\n");
    exit(2);
}

(new Migrator(dirname(__DIR__) . '/Migrations'))->migrate();
Db::exec('DELETE FROM cms_projects');

$base = sys_get_temp_dir() . '/cms-lock-' . bin2hex(random_bytes(4));
mkdir($base . '/site', 0777, true);
file_put_contents($base . '/site/index.html',
    '<!DOCTYPE html><html><body><h1 data-cms-id="t">Original</h1></body></html>');

$repo = new ProjectRepository(new PathGuard($base));
$scanner = new ScanService($repo);
$registry = new RegistryService($repo, $scanner);

$project = $repo->create(['slug' => 'site', 'name' => 'Site', 'rel_path' => 'site']);
$pid = (int) $project['id'];
$scanner->scanProject($pid);
$registry->classifyProject($pid);
$pageId = (int) Db::one('SELECT id FROM cms_pages WHERE project_id = :p LIMIT 1', ['p' => $pid])['id'];

$t->group('Lock — lock livre é adquirido');
$publisher = new Publisher($repo, new DraftService());
$drafts = new DraftService();
$elId = (int) Db::one("SELECT id FROM cms_elements WHERE cms_id = 't' LIMIT 1")['id'];
$drafts->save($pid, $pageId, [$elId => 'Versao 1']);
$res = $publisher->publish($pid, $pageId);
$t->ok('publicou', $res['ok']);

$t->group('Lock — release liberta de facto');
$lock = Db::one(
    "SELECT setting_value FROM cms_project_settings
     WHERE project_id = :p AND setting_key = 'publish_lock'",
    ['p' => $pid]
);
$t->same('lock vazio após release', '', (string) ($lock['setting_value'] ?? '?'));

$t->group('Lock — segunda publicação funciona após release');
$drafts->save($pid, $pageId, [$elId => 'Versao 2']);
$res = $publisher->publish($pid, $pageId);
$t->ok('publicou de novo', $res['ok']);

$t->group('Lock — lock preso bloqueia');
// Simula lock preso por outro processo (token diferente, sem expiração).
Db::exec(
    "UPDATE cms_project_settings
     SET setting_value = :val, updated_at = NOW()
     WHERE project_id = :p AND setting_key = 'publish_lock'",
    ['val' => $pageId . ':token-alheio:' . time(), 'p' => $pid]
);

$drafts->save($pid, $pageId, [$elId => 'Versao 3']);
$res = $publisher->publish($pid, $pageId);
$t->ok('recusado', !$res['ok']);
$t->ok('erro menciona lock', str_contains((string) $res['error'], 'em curso'));

$t->group('Lock — disco não mudou durante lock preso');
$t->ok('ainda Versao 2', str_contains((string) file_get_contents($base . '/site/index.html'), 'Versao 2'));

$t->group('Lock — expiração automática');
// Envelhece o lock além do timeout.
Db::exec(
    "UPDATE cms_project_settings
     SET setting_value = :val
     WHERE project_id = :p AND setting_key = 'publish_lock'",
    ['val' => $pageId . ':token-alheio:' . (time() - 120), 'p' => $pid]
);
$res = $publisher->publish($pid, $pageId);
$t->ok('lock expirado tomado, publicou', $res['ok']);
$t->ok('disco tem Versao 3', str_contains((string) file_get_contents($base . '/site/index.html'), 'Versao 3'));

Db::exec('DELETE FROM cms_projects');
@unlink($base . '/site/index.html');
@rmdir($base . '/site');
@rmdir($base);

exit($t->summary());
