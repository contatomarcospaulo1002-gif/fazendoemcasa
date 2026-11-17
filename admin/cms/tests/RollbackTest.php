<?php
declare(strict_types=1);

/**
 * Rollback: falha na verificação pós-escrita restaura o backup.
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/TestRunner.php';

use FazendoEmCasa\Cms\Core\Db;
use FazendoEmCasa\Cms\Core\Migrator;
use FazendoEmCasa\Cms\Core\PathGuard;
use FazendoEmCasa\Cms\Editor\DraftService;
use FazendoEmCasa\Cms\Publisher\AtomicWriter;
use FazendoEmCasa\Cms\Publisher\BackupService;
use FazendoEmCasa\Cms\Publisher\Publisher;
use FazendoEmCasa\Cms\Projects\ProjectRepository;
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

$base = sys_get_temp_dir() . '/cms-rb-' . bin2hex(random_bytes(4));
mkdir($base . '/site', 0777, true);

$original = '<!DOCTYPE html><html><body><h1 data-cms-id="t">Original</h1></body></html>';
file_put_contents($base . '/site/index.html', $original);

$repo = new ProjectRepository(new PathGuard($base));
$scanner = new ScanService($repo);
$registry = new RegistryService($repo, $scanner);
$drafts = new DraftService();
$backups = new BackupService();

$project = $repo->create(['slug' => 'site', 'name' => 'Site', 'rel_path' => 'site']);
$pid = (int) $project['id'];
$scanner->scanProject($pid);
$registry->classifyProject($pid);
$pageId = (int) Db::one('SELECT id FROM cms_pages WHERE project_id = :p LIMIT 1', ['p' => $pid])['id'];

$t->group('AtomicWriter — verificação rejeita');
$writer = new AtomicWriter();
$abs = $base . '/site/index.html';
$res = $writer->write($abs, '<html>novo</html>', fn (string $c): bool => false);
$t->ok('falhou', !$res['ok']);
$t->ok('erro de verificação', str_contains((string) $res['error'], 'Verificação'));
$t->same('disco intocado', $original, (string) file_get_contents($abs));
$t->ok('sem temporário', glob($base . '/site/*cms-tmp*') === []);

$t->group('AtomicWriter — temporário é limpo em falha de rename');
$readonly = $base . '/site/bloqueado.html';
file_put_contents($readonly, 'x');
chmod($readonly, 0444);
chmod($base . '/site', 0555);
$res = $writer->write($readonly, 'novo conteúdo');
chmod($base . '/site', 0777);
chmod($readonly, 0644);
$t->ok('falhou sem quebrar', !$res['ok']);
$t->same('conteúdo intacto', 'x', (string) file_get_contents($readonly));

$t->group('Rollback — verificação pós-escrita falha e restaura');
// Publicador com verificador que sempre falha: simula disco inconsistente
// após o rename (ex.: servidor de ficheiros reagindo mal).
$failing = new class($repo) extends Publisher {
    public mixed $capturedWrite = null;
    protected function postVerify(string $content, array $elements, array $patch): bool
    {
        return false;
    }
};

$drafts->save($pid, $pageId, [
    (int) Db::one("SELECT id FROM cms_elements WHERE cms_id = 't'", [])['id'] => 'Alterado',
]);
$res = $failing->publish($pid, $pageId);
$t->ok('falhou', !$res['ok']);
$t->same('status rolled_back', 'rolled_back', $res['status']);
$t->ok('restaurado', $res['restored']);

$t->group('Rollback — disco voltou ao original');
$t->same('conteúdo idêntico ao original', $original, (string) file_get_contents($abs));

$t->group('Rollback — registo auditável');
$pub = Db::one(
    'SELECT * FROM cms_publications WHERE page_id = :pg ORDER BY id DESC LIMIT 1',
    ['pg' => $pageId]
);
$t->same('status rolled_back', 'rolled_back', (string) $pub['status']);
$t->ok('tem backup_id', (int) $pub['backup_id'] > 0);
$t->ok('erro registado', (string) $pub['error_message'] !== '');

$t->group('Rollback — rascunho preservado para nova tentativa');
$t->ok('rascunho ainda existe', $drafts->openDraft($pageId) !== null);

$t->group('BackupService — ficheiro novo não tem backup');
$res = $backups->snapshot($pid, $pageId, $base . '/site/inexistente.html', 'site/inexistente.html');
$t->ok('sem backup', $res['id'] === null);
$t->ok('sem hash', $res['hash'] === null);

Db::exec('DELETE FROM cms_projects');
@unlink($abs);
@unlink($readonly);
@rmdir($base . '/site');
@rmdir($base);

exit($t->summary());
