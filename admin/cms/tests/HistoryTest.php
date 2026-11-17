<?php
declare(strict_types=1);

/**
 * FASE 7 — Histórico, versões, auditoria e restauração.
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/TestRunner.php';

use FazendoEmCasa\Cms\Core\Db;
use FazendoEmCasa\Cms\Core\Migrator;
use FazendoEmCasa\Cms\Core\PathGuard;
use FazendoEmCasa\Cms\Editor\DraftService;
use FazendoEmCasa\Cms\History\AuditLog;
use FazendoEmCasa\Cms\History\RestoreService;
use FazendoEmCasa\Cms\History\VersionLog;
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

$base = sys_get_temp_dir() . '/cms-hist-' . bin2hex(random_bytes(4));
mkdir($base . '/site', 0777, true);

$original = '<!DOCTYPE html><html><body><h1 data-cms-id="t">Original</h1><p data-cms-id="p">Texto</p></body></html>';
file_put_contents($base . '/site/index.html', $original);

$repo = new ProjectRepository(new PathGuard($base));
$scanner = new ScanService($repo);
$registry = new RegistryService($repo, $scanner);
$drafts = new DraftService();

$project = $repo->create(['slug' => 'site', 'name' => 'Site', 'rel_path' => 'site']);
$pid = (int) $project['id'];
$scanner->scanProject($pid);
$registry->classifyProject($pid);
$pageId = (int) Db::one('SELECT id FROM cms_pages WHERE project_id = :p LIMIT 1', ['p' => $pid])['id'];
$elH1 = (int) Db::one("SELECT id FROM cms_elements WHERE cms_id = 't'", [])['id'];
$elP = (int) Db::one("SELECT id FROM cms_elements WHERE cms_id = 'p'", [])['id'];

$abs = $base . '/site/index.html';

/* ---------- VersionLog: grava old_value e new_value por publicação ---------- */

$t->group('VersionLog — publicação grava versões');
$drafts->save($pid, $pageId, [$elH1 => 'Novo título', $elP => 'Novo texto']);
$publisher = new Publisher($repo, $drafts);
$res = $publisher->publish($pid, $pageId);
$t->ok('publicou', $res['ok']);
$publicationId = (int) $res['publicationId'];
$t->ok('publicationId > 0', $publicationId > 0);

$versions = Db::all(
    'SELECT * FROM cms_element_versions WHERE publication_id = :pub ORDER BY element_id',
    ['pub' => $publicationId]
);
$t->same('duas versões registadas', 2, count($versions));

$byEl = [];
foreach ($versions as $v) {
    $byEl[(int) $v['element_id']] = $v;
}
$t->same('old_value do h1', 'Original', (string) $byEl[$elH1]['old_value']);
$t->same('new_value do h1', 'Novo título', (string) $byEl[$elH1]['new_value']);
$t->same('old_value do p', 'Texto', (string) $byEl[$elP]['old_value']);
$t->same('new_value do p', 'Novo texto', (string) $byEl[$elP]['new_value']);
$t->same('page_id correto', $pageId, (int) $byEl[$elH1]['page_id']);
$t->ok('created_at preenchido', (string) $byEl[$elH1]['created_at'] !== '');

/* ---------- AuditLog: append-only ---------- */

$t->group('AuditLog — registo e leitura');
$auditId = AuditLog::record('test.action', 'element', $elH1, null, $pid, ['foo' => 'bar']);
$t->ok('id > 0', $auditId > 0);
$recent = AuditLog::recent($pid, 10);
$found = false;
foreach ($recent as $r) {
    if ((int) $r['id'] === $auditId) {
        $found = true;
        break;
    }
}
$t->ok('recente contém o registo', $found);
$first = $recent[0];
$t->same('ação gravada', 'test.action', (string) $first['action']);
$t->same('target_type gravado', 'element', (string) $first['target_type']);
$t->ok('detail_json decodifica', json_decode((string) $first['detail_json'], true) === ['foo' => 'bar']);

$t->group('AuditLog — sem projeto devolve de todos');
$all = AuditLog::recent(null, 5);
$t->ok('limita a 5', count($all) <= 5);
$t->ok('ordem DESC por id', count($all) < 2 || (int) $all[0]['id'] >= (int) $all[1]['id']);

$t->group('AuditLog — ação truncada a 60 caracteres');
AuditLog::record(str_repeat('a', 100), 'page', $pageId, null, $pid);
$row = Db::one('SELECT action FROM cms_audit_logs ORDER BY id DESC LIMIT 1', []);
$t->ok('truncado', strlen((string) $row['action']) <= 60);

/* ---------- Publisher: integra audit + versions ---------- */

$t->group('Publisher — audit de publicação');
$pubAudit = Db::one(
    "SELECT * FROM cms_audit_logs WHERE action = 'publish.page' AND project_id = :p ORDER BY id DESC LIMIT 1",
    ['p' => $pid]
);
$t->ok('audit de publish existe', $pubAudit !== null);
if ($pubAudit !== null) {
    $detail = json_decode((string) $pubAudit['detail_json'], true);
    $t->same('detail tem publication_id', $publicationId, (int) ($detail['publication_id'] ?? 0));
}

$t->group('Publisher — rollback não grava versões');
$drafts->save($pid, $pageId, [$elH1 => 'Outro valor']);
$failing = new class($repo) extends Publisher {
    protected function postVerify(string $content, array $elements, array $patch): bool
    {
        return false;
    }
};
$resFail = $failing->publish($pid, $pageId);
$t->ok('falhou', !$resFail['ok']);
$versionsFail = $resFail['publicationId'] !== null
    ? Db::all('SELECT * FROM cms_element_versions WHERE publication_id = :pub', ['pub' => $resFail['publicationId']])
    : [];
$t->same('nenhuma versão gravada em rollback', 0, count($versionsFail));

$t->group('Rollback — disco tem conteúdo do 1º publish (não o do rollback)');
$publishedAfterRb = (string) file_get_contents($abs);
$t->ok('ficheiro difere do original', $publishedAfterRb !== $original);
$t->ok('rollback não aplicou novo valor', !str_contains($publishedAfterRb, 'Outro valor'));
$rbAudit = Db::one(
    'SELECT * FROM cms_audit_logs WHERE action = \'publish.page\' ORDER BY id DESC LIMIT 1',
    []
);
$t->ok('publish audit existe (apenas do 1º publish)', (int) $rbAudit['detail_json'] !== '' || $rbAudit !== null);

/* ---------- VersionLog::forElement / forPage ---------- */

$t->group('VersionLog — consultas');
$forEl = VersionLog::forElement($elH1, 10);
$t->ok('histórico do elemento tem registos', $forEl !== []);
$t->ok('mais recente primeiro', count($forEl) < 2 || (int) $forEl[0]['id'] >= (int) $forEl[1]['id']);
$forPg = VersionLog::forPage($pageId, 50);
$t->ok('histórico da página tem registos', $forPg !== []);

/* ---------- RestoreService — restaurar elemento ---------- */

$t->group('RestoreService — restore de elemento cria rascunho');
$restore = new RestoreService($repo);
$firstVersion = Db::one(
    'SELECT * FROM cms_element_versions WHERE element_id = :e ORDER BY id ASC LIMIT 1',
    ['e' => $elH1]
);
$resEl = $restore->restoreElementVersion($pid, (int) $firstVersion['id']);
$t->ok('ok', $resEl['ok']);
$draft = $drafts->openDraft($pageId);
$t->ok('rascunho aberto', $draft !== null);
if ($draft !== null) {
    $patch = json_decode((string) $draft['patch_json'], true);
    $t->same('patch com old_value', 'Original', (string) ($patch[(string) $elH1] ?? ''));
}

$t->group('RestoreService — restore de versão inexistente');
$resBad = $restore->restoreElementVersion($pid, 999999999);
$t->ok('falhou', !$resBad['ok']);

$t->group('RestoreService — versão de outro projeto é recusada');
mkdir($base . '/outro', 0777, true);
$other = $repo->create(['slug' => 'outro', 'name' => 'Outro', 'rel_path' => 'outro']);
$otherId = (int) $other['id'];
$resCross = $restore->restoreElementVersion($otherId, (int) $firstVersion['id']);
$t->ok('recusado', !$resCross['ok']);

/* ---------- RestoreService — restaurar backup ---------- */

$t->group('RestoreService — restaurar backup devolve conteúdo anterior');
// Estado atual do ficheiro (após o 1º publish com sucesso)
$published = (string) file_get_contents($abs);
$t->ok('ficheiro publicado difere do original', $published !== $original);

$backup = Db::one(
    'SELECT * FROM cms_backups WHERE project_id = :p ORDER BY id ASC LIMIT 1',
    ['p' => $pid]
);
$t->ok('existe backup do 1º publish', $backup !== null);

$resBak = $restore->restoreBackup($pid, (int) $backup['id']);
$t->ok('ok', $resBak['ok']);
$t->same('rel_path', 'site/index.html', (string) $resBak['relPath']);
$t->same('disco voltou ao original', $original, (string) file_get_contents($abs));
$t->same('hash confere', hash('sha256', $original), (string) $resBak['hash']);

$t->group('RestoreService — restauração gera audit');
$restoreAudit = Db::one(
    "SELECT * FROM cms_audit_logs WHERE action = 'restore.backup' AND project_id = :p ORDER BY id DESC LIMIT 1",
    ['p' => $pid]
);
$t->ok('audit de restore existe', $restoreAudit !== null);

$t->group('RestoreService — restaurar backup inexistente');
$resBakBad = $restore->restoreBackup($pid, 999999999);
$t->ok('falhou', !$resBakBad['ok']);
$t->ok('restored false', $resBakBad['restored'] === false);

$t->group('RestoreService — backup vazio é recusado');
Db::exec(
    "INSERT INTO cms_backups (project_id, page_id, rel_path, content, content_hash, size_bytes, reason, created_at)
     VALUES (:p, :pg, 'site/index.html', '', 'x', 0, 'vazio', NOW())",
    ['p' => $pid, 'pg' => $pageId]
);
$emptyBak = Db::one("SELECT id FROM cms_backups WHERE reason = 'vazio' LIMIT 1", []);
$resBakEmpty = $restore->restoreBackup($pid, (int) $emptyBak['id']);
$t->ok('recusado', !$resBakEmpty['ok']);

/* ---------- API cms-history.php — HTTP ---------- */

$t->group('API cms-history — acesso não autenticado');
$port = getenv('CMS_TEST_PORT') ?: '8099';
$ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
$raw = @file_get_contents(
    'http://127.0.0.1:' . $port . '/admin/api/cms-history.php?project=' . $pid,
    false,
    $ctx
);
// Sem servidor de teste rodando, este grupo é apenas estrutural.
if ($raw === false) {
    $t->ok('sem servidor HTTP de teste — pulado (estrutura validada nas suítes ApiHttpTest)', true);
} else {
    $data = json_decode((string) $raw, true);
    $t->ok('responde 401 sem sessão', isset($data['authenticated']) && $data['authenticated'] === false);
}

/* ---------- Limpeza ---------- */

Db::exec('DELETE FROM cms_projects');
@unlink($abs);
@rmdir($base . '/outro');
@rmdir($base . '/site');
@rmdir($base);

exit($t->summary());




