<?php
declare(strict_types=1);

/**
 * Entrypoint público do conteúdo de uma página.
 *
 * GET /admin/cms-content.php?id=N&page=M
 */

require __DIR__ . '/cms/bootstrap.php';

use FazendoEmCasa\Cms\Core\Auth;
use FazendoEmCasa\Cms\Core\Config;
use FazendoEmCasa\Cms\Core\Db;
use FazendoEmCasa\Cms\Core\PathGuard;
use FazendoEmCasa\Cms\Projects\ProjectRepository;

Auth::startSession();

if (!Auth::check()) {
    header('Location: /admin/', true, 302);
    exit;
}

if (!Db::connected() || !Db::tableExists('cms_projects')) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="pt-PT"><head><meta charset="utf-8"><title>Configuração pendente</title></head><body>';
    echo '<h1>Configuração pendente</h1><p>Corre <code>php admin/cms/bin/migrate.php</code> no servidor.</p>';
    echo '</body></html>';
    exit;
}

$projectId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$pageId = isset($_GET['page']) ? (int) $_GET['page'] : 0;

if ($projectId <= 0 || $pageId <= 0) {
    header('Location: /admin/cms-ui.php', true, 302);
    exit;
}

$config = Config::load();
$guard = new PathGuard($config->projectsRoot());
$repo = new ProjectRepository($guard);

$project = $repo->find($projectId);
if ($project === null) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="pt-PT"><head><meta charset="utf-8"><title>Projeto não encontrado</title></head><body>';
    echo '<h1>Projeto não encontrado</h1><p><a href="/admin/cms-ui.php">Voltar à Central</a></p>';
    echo '</body></html>';
    exit;
}

// A página tem de pertencer a este projeto — nunca confiar no par do request.
$page = Db::one(
    'SELECT id, rel_path, kind, title FROM cms_pages WHERE id = :pg AND project_id = :p LIMIT 1',
    ['pg' => $pageId, 'p' => $projectId]
);

if ($page === null) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="pt-PT"><head><meta charset="utf-8"><title>Página não encontrada</title></head><body>';
    echo '<h1>Página não encontrada</h1><p><a href="/admin/cms-project.php?id=' . (int) $projectId . '">Voltar ao projeto</a></p>';
    echo '</body></html>';
    exit;
}

$projectName = (string) $project['name'];
$projectPath = (string) $project['rel_path'];
$pagePath = (string) $page['rel_path'];
$pageTitle = (string) ($page['title'] ?? '');

require __DIR__ . '/cms/views/page-content.php';
