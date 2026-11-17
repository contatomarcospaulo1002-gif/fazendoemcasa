<?php
declare(strict_types=1);

/**
 * ReferenceIndex + MediaReplacer: alcance real e escopo explícito.
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/TestRunner.php';

use FazendoEmCasa\Cms\Core\Db;
use FazendoEmCasa\Cms\Core\Migrator;
use FazendoEmCasa\Cms\Core\PathGuard;
use FazendoEmCasa\Cms\Editor\DraftService;
use FazendoEmCasa\Cms\Media\MediaReplacer;
use FazendoEmCasa\Cms\Media\MediaStorage;
use FazendoEmCasa\Cms\Media\ReferenceIndex;
use FazendoEmCasa\Cms\Projects\ProjectRepository;
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

$base = sys_get_temp_dir() . '/cms-ref-' . bin2hex(random_bytes(4));
mkdir($base . '/site', 0777, true);
mkdir($base . '/site/uploads', 0777, true);

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
file_put_contents($base . '/site/uploads/comum.png', $png);

file_put_contents($base . '/site/index.html', '<!DOCTYPE html><html><body>'
    . '<img data-cms-id="foto" src="uploads/comum.png" alt="A">'
    . '<img data-cms-id="foto2" src="uploads/comum.png" alt="B">'
    . '</body></html>');
file_put_contents($base . '/site/sobre.html', '<!DOCTYPE html><html><body>'
    . '<img data-cms-id="foto3" src="uploads/comum.png" alt="C">'
    . '</body></html>');

$repo = new ProjectRepository(new PathGuard($base));
$scanner = new ScanService($repo);
$registry = new RegistryService($repo, $scanner);
$index = new ReferenceIndex();
$drafts = new DraftService();
$replacer = new MediaReplacer($index, $drafts);

$project = $repo->create(['slug' => 'site', 'name' => 'Site', 'rel_path' => 'site']);
$pid = (int) $project['id'];
$scanner->scanProject($pid);
$registry->classifyProject($pid);

$storage = new MediaStorage($repo);
$tmpFile = $base . '/tmp.png';
file_put_contents($tmpFile, $png);
$stored = $storage->store($pid, $tmpFile, 'comum.png');
$mediaId = (int) $stored['media']['id'];
$mediaPath = (string) $stored['media']['rel_path'];

// Faz os elementos apontarem para o caminho da mídia da biblioteca.
$uploadedRel = $mediaPath;
Db::exec(
    "UPDATE cms_elements SET value_current = :v WHERE type = 'image'",
    ['v' => $uploadedRel]
);

$t->group('Referências — índice construído');
$n = $index->rebuild($pid);
$t->same('3 referências', 3, $n);

$refs = $index->forMedia($mediaId);
$t->same('3 usos', 3, count($refs));
$t->ok('cobre 2 páginas', count(array_unique(array_column($refs, 'page_id'))) === 2);

$t->group('Referências — contagem de usos');
$counts = $index->usageCounts($pid);
$t->same('3 usos para a mídia', 3, $counts[$mediaId] ?? 0);

$t->group('Referências — mídia usada não é "não utilizada"');
$t->same('nenhuma não utilizada', 0, count($index->unused($pid)));

$t->group('Substituição — escopo single');
$oneRef = $refs[0];
file_put_contents($base . '/site/uploads/nova.png', $png);
$res = $replacer->replace($pid, $mediaId, 'uploads/nova.png', 'single', null, $oneRef['element_id']);
$t->ok('ok', $res['ok']);
$t->same('afetou 1', 1, $res['affected']);

$patch = $drafts->patchFor($oneRef['page_id']);
$t->ok('rascunho criado', isset($patch[$oneRef['element_id']]));
$t->same('valor novo', 'uploads/nova.png', $patch[$oneRef['element_id']]);

$t->group('Substituição — single não afeta os outros');
$otherPages = array_filter($refs, static fn (array $r): bool => $r['element_id'] !== $oneRef['element_id']);
foreach ($otherPages as $r) {
    $p = $drafts->patchFor($r['page_id']);
    $t->ok('elemento ' . $r['element_id'] . ' intocado', !isset($p[$r['element_id']]));
}

$t->group('Substituição — escopo page');
$secondPage = (int) $refs[count($refs) - 1]['page_id'];
$res = $replacer->replace($pid, $mediaId, 'uploads/pagina.png', 'page', $secondPage);
$t->ok('ok', $res['ok']);
$t->ok('afetou >= 1', $res['affected'] >= 1);

$t->group('Substituição — escopo all');
$res = $replacer->replace($pid, $mediaId, 'uploads/geral.png', 'all');
$t->ok('ok', $res['ok']);
$t->same('afetou os 3', 3, $res['affected']);
$t->same('2 páginas', 2, count($res['pages']));

$t->group('Substituição — escopo inválido');
$res = $replacer->replace($pid, $mediaId, 'x.png', 'inventado');
$t->ok('rejeitado', !$res['ok']);

$t->group('Substituição — mídia de outro projeto');
$res = $replacer->replace(999999, $mediaId, 'x.png', 'all');
$t->ok('rejeitado', !$res['ok']);

$t->group('Substituição — não escreve no disco');
$disk = (string) file_get_contents($base . '/site/index.html');
$t->ok('html intocado', str_contains($disk, 'uploads/comum.png'));
$t->ok('sem valor novo no disco', !str_contains($disk, 'geral.png'));

Db::exec('DELETE FROM cms_projects');
array_map('unlink', glob($base . '/site/uploads/*'));
@unlink($base . '/site/index.html');
@unlink($base . '/site/sobre.html');
@rmdir($base . '/site/uploads');
@rmdir($base . '/site');
@rmdir($base);

exit($t->summary());
