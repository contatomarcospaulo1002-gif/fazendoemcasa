<?php
declare(strict_types=1);

/**
 * Publisher: backup obrigatório, escrita atómica, verificação pós-escrita.
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
    fwrite(STDERR, "ABORTADO: DB_DATABASE deve conter 'test'.\n");
    exit(2);
}

(new Migrator(dirname(__DIR__) . '/Migrations'))->migrate();
Db::exec('DELETE FROM cms_projects');

$base = sys_get_temp_dir() . '/cms-pub-' . bin2hex(random_bytes(4));
mkdir($base . '/site', 0777, true);

$original = '<!DOCTYPE html><html><head><title>Pub</title></head><body>'
    . '<h1 data-cms-id="titulo">Antes</h1>'
    . '<img data-cms-id="foto" src="/img/a.png" alt="A">'
    . '<a data-cms-id="cta" href="/velho">Ir</a>'
    . '<footer>Rodape</footer>'
    . '</body></html>';
file_put_contents($base . '/site/index.html', $original);

$repo = new ProjectRepository(new PathGuard($base));
$scanner = new ScanService($repo);
$registry = new RegistryService($repo, $scanner);
$drafts = new DraftService();
$publisher = new Publisher($repo, $drafts);

$project = $repo->create(['slug' => 'site', 'name' => 'Site', 'rel_path' => 'site']);
$pid = (int) $project['id'];
$scanner->scanProject($pid);
$registry->classifyProject($pid);

$pageId = (int) Db::one('SELECT id FROM cms_pages WHERE project_id = :p LIMIT 1', ['p' => $pid])['id'];
$els = [];
foreach (Db::all('SELECT id, cms_id FROM cms_elements WHERE page_id = :pg', ['pg' => $pageId]) as $e) {
    if (!empty($e['cms_id'])) {
        $els[(string) $e['cms_id']] = (int) $e['id'];
    }
}

$t->group('Publicação — sem rascunho');
$res = $publisher->publish($pid, $pageId);
$t->ok('recusa', !$res['ok']);
$t->ok('erro menciona rascunho', str_contains((string) $res['error'], 'rascunho'));

$t->group('Publicação — fluxo completo');
$drafts->save($pid, $pageId, [$els['titulo'] => 'Depois']);
$res = $publisher->publish($pid, $pageId);
$t->ok('publicou', $res['ok']);
$t->same('status applied', 'applied', $res['status']);
$t->same('1 alteração', 1, $res['changed']);

$t->group('Publicação — disco mudou');
$disk = (string) file_get_contents($base . '/site/index.html');
$t->ok('tem o novo valor', str_contains($disk, 'Depois'));
$t->ok('não tem o antigo', !str_contains($disk, '>Antes<'));
$t->ok('rodapé preservado', str_contains($disk, 'Rodape'));
$t->ok('doctype preservado', str_contains($disk, '<!DOCTYPE html>'));

$t->group('Publicação — backup criado');
$backup = Db::one(
    'SELECT * FROM cms_backups WHERE page_id = :pg ORDER BY id DESC LIMIT 1',
    ['pg' => $pageId]
);
$t->ok('existe backup', $backup !== null);
$t->same('backup tem o original', $original, (string) $backup['content']);
$t->same('hash do original', hash('sha256', $original), (string) $backup['content_hash']);

$t->group('Publicação — registo auditável');
$pub = Db::one(
    'SELECT * FROM cms_publications WHERE page_id = :pg ORDER BY id DESC LIMIT 1',
    ['pg' => $pageId]
);
$t->ok('existe registo', $pub !== null);
$t->same('status applied', 'applied', (string) $pub['status']);
$t->same('hash_after bate', $pub['hash_after'], hash('sha256', (string) file_get_contents($base . '/site/index.html')));
$t->ok('hash_before era o original', $pub['hash_before'] === hash('sha256', $original));

$t->group('Publicação — rascunho consumido');
$t->same('sem rascunho', [], $drafts->patchFor($pageId));

$t->group('Publicação — segunda rodada com três tipos');
$drafts->save($pid, $pageId, [
    $els['titulo'] => 'Terceiro',
    $els['foto'] => '/img/nova.png',
    $els['cta'] => '/novo',
]);
$res = $publisher->publish($pid, $pageId);
$t->ok('publicou de novo', $res['ok']);
$disk = (string) file_get_contents($base . '/site/index.html');
$t->ok('texto aplicado', str_contains($disk, 'Terceiro'));
$t->ok('imagem aplicada', str_contains($disk, 'src="/img/nova.png"'));
$t->ok('link aplicado', str_contains($disk, 'href="/novo"'));
$t->ok('texto do link preservado', str_contains($disk, 'Ir'));

$t->group('Publicação — página inexistente');
$res = $publisher->publish($pid, 999999);
$t->ok('recusa', !$res['ok']);

$t->group('Publicação — projeto errado para a página');
mkdir($base . '/outro', 0777, true);
$other = $repo->create(['slug' => 'outro', 'name' => 'Outro', 'rel_path' => 'outro']);
$res = $publisher->publish((int) $other['id'], $pageId);
$t->ok('recusa', !$res['ok']);

Db::exec('DELETE FROM cms_projects');
@unlink($base . '/site/index.html');
@rmdir($base . '/site');
@rmdir($base . '/outro');
@rmdir($base);

exit($t->summary());
