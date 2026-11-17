<?php
declare(strict_types=1);

/** Entrypoint público do histórico. GET /admin/cms-history.php?id=N */

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
    echo '<h1>Configuração pendente</h1><p>Corre <code>php admin/cms/bin/migrate.php</code>.</p></body></html>';
    exit;
}

$projectId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($projectId <= 0) {
    header('Location: /admin/cms-ui.php', true, 302);
    exit;
}

$config = Config::load();
$repo = new ProjectRepository(new PathGuard($config->projectsRoot()));
$project = $repo->find($projectId);

if ($project === null) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="pt-PT"><head><meta charset="utf-8"><title>Projeto não encontrado</title></head><body>';
    echo '<h1>Projeto não encontrado</h1><p><a href="/admin/cms-ui.php">Voltar à Central</a></p></body></html>';
    exit;
}

$projectName = (string) $project['name'];
$projectPath = (string) $project['rel_path'];

require __DIR__ . '/cms/views/project-history.php';
