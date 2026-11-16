<?php
declare(strict_types=1);

/**
 * Integração do Registry com banco: extração, deduplicação e persistência.
 *
 * Requer banco de teste (DB_DATABASE contendo 'test').
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/TestRunner.php';

use FazendoEmCasa\Cms\Core\Db;
use FazendoEmCasa\Cms\Core\Migrator;
use FazendoEmCasa\Cms\Core\PathGuard;
use FazendoEmCasa\Cms\Projects\ProjectRepository;
use FazendoEmCasa\Cms\Registry\Editability;
use FazendoEmCasa\Cms\Registry\ElementRegistry;
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

$base = sys_get_temp_dir() . '/cms-reg-' . bin2hex(random_bytes(4));
mkdir($base . '/site', 0777, true);

file_put_contents(
    $base . '/site/index.html',
    '<html><head><title>Site</title></head><body>'
    . '<h1 data-cms-id="titulo" data-cms-type="text">Ola mundo</h1>'
    . '<p data-cms-id="texto">Paragrafo</p>'
    . '<p>Sem marcacao</p>'
    . '<img data-cms-id="foto" src="a.webp" alt="A">'
    . '</body></html>'
);
file_put_contents($base . '/site/estilo.css', 'body{background:url(bg.png)}');

$guard = new PathGuard($base);
$repo = new ProjectRepository($guard);
$scanner = new ScanService($repo);
$registry = new RegistryService($repo, $scanner);

$project = $repo->create(['slug' => 'site', 'name' => 'Site', 'rel_path' => 'site']);
$pid = (int) $project['id'];
$scanner->scanProject($pid);

$t->group('Registry — classificação do projeto');
$r = $registry->classifyProject($pid);
$t->ok('pelo menos 2 páginas', $r['pages'] >= 2);
$t->ok('elementos encontrados', $r['elements'] >= 4);
$t->ok('inseriu elementos', $r['inserted'] >= 4);
$t->same('nada atualizado na 1a vez', 0, $r['updated']);
$t->ok('tem contagem por nível', isset($r['byLevel'][Editability::EDITABLE]));

$t->group('Registry — elementos persistidos');
$page = Db::one(
    'SELECT id FROM cms_pages WHERE project_id = :p AND rel_path = :r',
    ['p' => $pid, 'r' => 'site/index.html']
);
$pageId = (int) $page['id'];
$elements = (new ElementRegistry())->forPage($pageId);
$t->ok('tem elementos na página', count($elements) > 0);

$byId = [];
foreach ($elements as $e) {
    $byId[(string) $e['cms_id']] = $e;
}

$t->ok('titulo persistido', isset($byId['titulo']));
$t->same('titulo editable', Editability::EDITABLE, $byId['titulo']['editability']);
$t->same('valor do titulo', 'Ola mundo', $byId['titulo']['value_current']);
$t->same('adapter do titulo', 'html', $byId['titulo']['adapter']);

$t->group('Registry — deduplicação html/component');
$titulo = Db::one(
    'SELECT COUNT(*) AS n FROM cms_elements WHERE page_id = :pg AND selector = :s',
    ['pg' => $pageId, 's' => '[data-cms-id="titulo"]']
);
$t->same('selector não duplicado', 1, (int) $titulo['n']);

$semId = Db::all(
    'SELECT * FROM cms_elements WHERE page_id = :pg AND cms_id IS NULL',
    ['pg' => $pageId]
);
$t->ok('elemento sem data-cms-id persistido', count($semId) >= 1);
$t->same('sem id é needs_adapter', Editability::NEEDS_ADAPTER, $semId[0]['editability']);

$t->group('Registry — contagem por nível');
$counts = (new ElementRegistry())->countsByLevel($pid);
$t->ok('tem editáveis', $counts[Editability::EDITABLE] >= 2);
$t->ok('tem needs_adapter', $counts[Editability::NEEDS_ADAPTER] >= 1);
$totalDb = Db::one('SELECT COUNT(*) AS n FROM cms_elements WHERE project_id = :p', ['p' => $pid]);
$t->same('soma confere com o banco', (int) $totalDb['n'], array_sum($counts));

$t->group('Registry — reclassificação estável');
$r2 = $registry->classifyProject($pid);
$t->same('nada inserido na 2a vez', 0, $r2['inserted']);
$t->same('nada atualizado na 2a vez', 0, $r2['updated']);
$t->same('nada removido na 2a vez', 0, $r2['pruned']);
$t->same('mesmo total de elementos', $r['elements'], $r2['elements']);

$t->group('Registry — remove elemento desaparecido');
file_put_contents(
    $base . '/site/index.html',
    '<html><head><title>Site</title></head><body>'
    . '<h1 data-cms-id="titulo" data-cms-type="text">Ola mundo</h1>'
    . '</body></html>'
);
$scanner->scanProject($pid);
$r3 = $registry->classifyProject($pid);
$t->ok('removeu elementos', $r3['pruned'] >= 3);
$restantes = (new ElementRegistry())->forPage($pageId);
$ids = array_filter(array_column($restantes, 'cms_id'));
$t->same('só titulo com cms_id', ['titulo'], array_values($ids));

$t->group('Registry — projeto inexistente');
$t->throws(
    'classifyProject(999999) falha',
    fn () => $registry->classifyProject(999999),
    \RuntimeException::class
);

$t->group('Registry — análise com erro preserva o registro');
// JSON inválido: o analisador devolve erro e zero elementos. O prune
// NÃO pode apagar o que já se sabia sobre a página.
file_put_contents($base . '/site/config.json', json_encode(['hero' => ['title' => 'Ola']]));
$scanner->scanProject($pid);
$registry->classifyProject($pid);

$jsonPage = Db::one(
    "SELECT id FROM cms_pages WHERE project_id = :p AND kind = 'json'",
    ['p' => $pid]
);
$t->ok('página json indexada', $jsonPage !== null);
$jsonPageId = (int) ($jsonPage['id'] ?? 0);
$antes = count((new ElementRegistry())->forPage($jsonPageId));
$t->ok('json gerou elementos', $antes >= 1);

// Corrompe o JSON e reclassifica.
file_put_contents($base . '/site/config.json', '{ isto nao e json valido ');
$scanner->scanProject($pid);
$r4 = $registry->classifyProject($pid);
$depois = count((new ElementRegistry())->forPage($jsonPageId));
$t->same('elementos preservados', $antes, $depois);
$t->same('nada removido', 0, $r4['pruned']);
$t->ok('página contabilizada como falha', $r4['failed'] >= 1);

Db::exec('DELETE FROM cms_projects');
foreach (glob($base . '/site/*') as $f) {
    @unlink($f);
}
@rmdir($base . '/site');
@rmdir($base);

exit($t->summary());
