<?php
declare(strict_types=1);

/**
 * API de histórico, backups e auditoria (FASE 7).
 *
 * GET  ?project=N                 → últimos backups + publicações + auditoria
 * GET  ?project=N&page=M          → histórico de versões da página
 * GET  ?project=N&element=E       → histórico de versões do elemento
 * GET  ?project=N&backup=B        → conteúdo do backup (para diff/visualização)
 * POST ?project=N&action=restore  → restaura um backup (CSRF)
 * POST ?project=N&action=audit    → (GET-only info) — nada aqui
 */

require dirname(__DIR__) . '/cms/bootstrap.php';

use FazendoEmCasa\Cms\Core\Auth;
use FazendoEmCasa\Cms\Core\Config;
use FazendoEmCasa\Cms\Core\Csrf;
use FazendoEmCasa\Cms\Core\Db;
use FazendoEmCasa\Cms\Core\Http;
use FazendoEmCasa\Cms\Core\PathGuard;
use FazendoEmCasa\Cms\History\AuditLog;
use FazendoEmCasa\Cms\History\RestoreService;
use FazendoEmCasa\Cms\History\VersionLog;
use FazendoEmCasa\Cms\Projects\ProjectRepository;
use FazendoEmCasa\Cms\Publisher\BackupService;

Auth::startSession();

if (!Auth::check()) {
    Http::json(['ok' => false, 'error' => 'Não autenticado.', 'authenticated' => false], 401);
}

if (!Db::connected()) {
    Http::error('Base de dados indisponível.', 503);
}
if (!Db::tableExists('cms_backups') || !Db::tableExists('cms_audit_logs')) {
    Http::error('Migrations não aplicadas.', 503, ['hint' => 'php admin/cms/bin/migrate.php']);
}

$projectId = isset($_GET['project']) ? (int) $_GET['project'] : 0;
if ($projectId <= 0) {
    Http::error('Parâmetro project é obrigatório.', 400);
}

$config = Config::load();
$repo = new ProjectRepository(new PathGuard($config->projectsRoot()));

if ($repo->find($projectId) === null) {
    Http::error('Projeto não encontrado.', 404);
}

$method = Http::requireMethod('GET', 'POST');

if ($method === 'POST') {
    Csrf::requireValid();
    if (!Auth::canEditProject($projectId)) {
        Http::error('Sem permissão para este projeto.', 403);
    }

    $action = (string) ($_GET['action'] ?? '');

    if ($action === 'restore') {
        $body = Http::jsonBody();
        $backupId = (int) ($body['backupId'] ?? 0);
        if ($backupId <= 0) {
            Http::error('backupId é obrigatório.', 400);
        }

        $service = new RestoreService($repo);
        $result = $service->restoreBackup($projectId, $backupId, Auth::userId());
        if (!$result['ok']) {
            Http::json(['ok' => false, 'error' => $result['error'], 'restored' => $result['restored']], 422);
        }

        Http::ok([
            'restored' => true,
            'relPath' => $result['relPath'],
            'hash' => $result['hash'],
            'csrfToken' => Csrf::token(),
        ]);
    }

    if ($action === 'restore_element') {
        $body = Http::jsonBody();
        $versionId = (int) ($body['versionId'] ?? 0);
        if ($versionId <= 0) {
            Http::error('versionId é obrigatório.', 400);
        }

        $service = new RestoreService($repo);
        $result = $service->restoreElementVersion($projectId, $versionId, Auth::userId());
        if (!$result['ok']) {
            Http::json(['ok' => false, 'error' => $result['error']], 422);
        }

        Http::ok(['restored' => true, 'csrfToken' => Csrf::token()]);
    }

    Http::error('Ação inválida.', 400);
}

// ---------- GET ----------

$backupId = isset($_GET['backup']) ? (int) $_GET['backup'] : 0;
if ($backupId > 0) {
    $backup = Db::one(
        'SELECT id, rel_path, content_hash, size_bytes, reason, created_at
         FROM cms_backups WHERE id = :id AND project_id = :p LIMIT 1',
        ['id' => $backupId, 'p' => $projectId]
    );
    if ($backup === null) {
        Http::error('Backup não encontrado.', 404);
    }

    // O conteúdo só vai à UI sob permissão de edição; leitura de HTML
    // publicado por sessão sem permissão é desnecessária.
    if (!Auth::canEditProject($projectId)) {
        Http::error('Sem permissão para este projeto.', 403);
    }

    $content = Db::one(
        'SELECT content FROM cms_backups WHERE id = :id LIMIT 1',
        ['id' => $backupId]
    );

    Http::ok(['backup' => $backup, 'content' => (string) ($content['content'] ?? ''), 'csrfToken' => Csrf::token()]);
}

$pageId = isset($_GET['page']) ? (int) $_GET['page'] : 0;
if ($pageId > 0) {
    $page = Db::one(
        'SELECT id FROM cms_pages WHERE id = :pg AND project_id = :p LIMIT 1',
        ['pg' => $pageId, 'p' => $projectId]
    );
    if ($page === null) {
        Http::error('Página não encontrada neste projeto.', 404);
    }

    Http::ok([
        'versions' => VersionLog::forPage($pageId),
        'csrfToken' => Csrf::token(),
    ]);
}

$elementId = isset($_GET['element']) ? (int) $_GET['element'] : 0;
if ($elementId > 0) {
    $element = Db::one(
        'SELECT id FROM cms_elements WHERE id = :e AND page_id IN (SELECT id FROM cms_pages WHERE project_id = :p) LIMIT 1',
        ['e' => $elementId, 'p' => $projectId]
    );
    if ($element === null) {
        Http::error('Elemento não encontrado neste projeto.', 404);
    }

    Http::ok(['versions' => VersionLog::forElement($elementId), 'csrfToken' => Csrf::token()]);
}

Http::ok([
    'backups' => Db::all(
        'SELECT id, page_id, rel_path, content_hash, size_bytes, reason, created_at
         FROM cms_backups WHERE project_id = :p ORDER BY id DESC LIMIT 100',
        ['p' => $projectId]
    ),
    'publications' => Db::all(
        'SELECT id, page_id, status, elements_changed, error_message, created_at
         FROM cms_publications WHERE project_id = :p ORDER BY id DESC LIMIT 100',
        ['p' => $projectId]
    ),
    'audit' => AuditLog::recent($projectId, 100),
    'csrfToken' => Csrf::token(),
]);
