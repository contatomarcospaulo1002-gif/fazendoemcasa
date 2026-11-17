<?php
declare(strict_types=1);

/**
 * DraftService + PreviewService: rascunho por delta e preview em memória.
 *
 * Requer banco de teste (DB_DATABASE contendo 'test').
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/TestRunner.php';

use FazendoEmCasa\Cms\Core\Db;
use FazendoEmCasa\Cms\Core\Migrator;
use FazendoEmCasa\Cms\Core\PathGuard;
use FazendoEmCasa\Cms\Editor\DraftService;
use FazendoEmCasa\Cms\Editor\PreviewService;
use FazendoEmCasa\Cms\Projects\ProjectRepository;
use FazendoEmCasa\Cms\Registry\RegistryService;
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

$base = sys_get_temp_dir() . '/cms-edit-' . bin2hex(random_bytes(4));
mkdir($base . '/site', 0777, true);

$original = '<!DOCTYPE html><html><head><title>Editor</title></head><body>'
    . '<h1 data-cms-id="titulo">Original</h1>'
    . '<p data-cms-id="texto">Paragrafo</p>'
    . '<img data-cms-id="foto" src="/img/a.webp" alt="A">'
    . '<footer>Rodape</footer>'
    . '</body></html>';
file_put_contents($base . '/site/index.html', $original);

$guard = new PathGuard($base);
$repo = new ProjectRepository($guard);
$scanner = new ScanService($repo);
$registry = new RegistryService($repo, $scanner);
$drafts = new DraftService();
$preview = new PreviewService($repo, $drafts);

$project = $repo->create(['slug' => 'site', 'name' => 'Site', 'rel_path' => 'site']);
$pid = (int) $project['id'];
$scanner->scanProject($pid);
$registry->classifyProject($pid);

$page = Db::one('SELECT id FROM cms_pages WHERE project_id = :p LIMIT 1', ['p' => $pid]);
$pageId = (int) $page['id'];

$els = [];
foreach (Db::all('SELECT id, cms_id, type FROM cms_elements WHERE page_id = :pg', ['pg' => $pageId]) as $e) {
    if (!empty($e['cms_id'])) {
        $els[(string) $e['cms_id']] = (int) $e['id'];
    }
}

$t->group('Editor — elementos disponíveis');
$t->ok('tem titulo', isset($els['titulo']));
$t->ok('tem texto', isset($els['texto']));
$t->ok('tem foto', isset($els['foto']));

$t->group('Editor — rascunho vazio no início');
$t->same('sem patch', [], $drafts->patchFor($pageId));
$t->ok('sem rascunho aberto', $drafts->openDraft($pageId) === null);

$t->group('Editor — grava rascunho');
$res = $drafts->save($pid, $pageId, [$els['titulo'] => 'Novo titulo']);
$t->ok('salvou', $res['ok']);
$t->same('1 alteração', 1, $res['changed']);
$patch = $drafts->patchFor($pageId);
$t->same('patch tem 1 campo', 1, count($patch));
$t->same('valor correto', 'Novo titulo', $patch[$els['titulo']]);

$t->group('Editor — segundo campo não descarta o primeiro');
$drafts->save($pid, $pageId, [$els['texto'] => 'Novo texto']);
$patch = $drafts->patchFor($pageId);
$t->same('patch tem 2 campos', 2, count($patch));
$t->same('primeiro preservado', 'Novo titulo', $patch[$els['titulo']]);
$t->same('segundo gravado', 'Novo texto', $patch[$els['texto']]);

$t->group('Editor — um único rascunho aberto por página');
$count = Db::one(
    "SELECT COUNT(*) AS n FROM cms_drafts WHERE page_id = :pg AND status = 'open'",
    ['pg' => $pageId]
);
$t->same('apenas 1', 1, (int) $count['n']);

$t->group('Editor — descarta um campo');
$drafts->discardElement($pageId, $els['texto']);
$patch = $drafts->patchFor($pageId);
$t->same('sobrou 1 campo', 1, count($patch));
$t->same('o certo sobrou', 'Novo titulo', $patch[$els['titulo']]);

$t->group('Editor — rejeita patch inválido');
$res = $drafts->save($pid, $pageId, [999999 => 'x']);
$t->ok('rejeitou', !$res['ok']);
$t->ok('tem erro', count($res['errors']) > 0);
$t->same('patch não mudou', 1, count($drafts->patchFor($pageId)));

$t->group('Editor — isolamento de projeto');
$res = $drafts->save(999999, $pageId, [$els['titulo'] => 'Hack']);
$t->ok('projeto errado rejeitado', !$res['ok']);

$t->group('Preview — sem patch devolve original');
$drafts->discardPage($pageId);
$pv = $preview->preview($pid, $pageId);
$t->ok('preview ok', $pv['ok']);
$t->same('0 aplicados', 0, $pv['applied']);
$t->ok('html igual ao original', str_contains($pv['html'], '>Original<'));

$t->group('Preview — aplica o patch em memória');
$drafts->save($pid, $pageId, [$els['titulo'] => 'Titulo editado']);
$pv = $preview->preview($pid, $pageId);
$t->ok('preview ok', $pv['ok']);
$t->same('1 aplicado', 1, $pv['applied']);
$t->ok('mostra o novo', str_contains($pv['html'], 'Titulo editado'));
$t->ok('não mostra o antigo', !str_contains($pv['html'], '>Original<'));
$t->ok('rodapé preservado', str_contains($pv['html'], 'Rodape'));

$t->group('Preview — NÃO escreve no disco');
$onDisk = (string) file_get_contents($base . '/site/index.html');
$t->same('ficheiro intacto', $original, $onDisk);
$t->ok('disco ainda tem Original', str_contains($onDisk, '>Original<'));
$t->ok('disco NÃO tem o editado', !str_contains($onDisk, 'Titulo editado'));

$t->group('Preview — projeto errado');
$pv = $preview->preview(999999, $pageId);
$t->ok('recusado', !$pv['ok']);
$t->same('html vazio', '', $pv['html']);

$t->group('Editor — descarta rascunho inteiro');
$t->ok('descartou', $drafts->discardPage($pageId));
$t->same('patch vazio', [], $drafts->patchFor($pageId));
$t->ok('sem rascunho aberto', $drafts->openDraft($pageId) === null);

Db::exec('DELETE FROM cms_projects');
@unlink($base . '/site/index.html');
@rmdir($base . '/site');
@rmdir($base);

exit($t->summary());
