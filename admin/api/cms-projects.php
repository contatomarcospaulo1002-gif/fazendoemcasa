<?php
declare(strict_types=1);

/**
 * Entrypoint HTTP da Central de Projetos.
 *
 * Vive em admin/api/ (acessível), mas toda a lógica está em admin/cms/
 * (bloqueado na web). Este arquivo só faz ponte.
 *
 * GET  api/cms-projects.php            → lista + csrfToken
 * GET  api/cms-projects.php?discover=1 → candidatos do discovery
 * GET  api/cms-projects.php?id=N       → detalhe
 * POST api/cms-projects.php            → criar
 * POST api/cms-projects.php?id=N       → atualizar
 */

require dirname(__DIR__) . '/cms/bootstrap.php';

use FazendoEmCasa\Cms\Core\Auth;
use FazendoEmCasa\Cms\Core\Config;
use FazendoEmCasa\Cms\Core\Csrf;
use FazendoEmCasa\Cms\Core\Db;
use FazendoEmCasa\Cms\Core\Http;
use FazendoEmCasa\Cms\Core\PathException;
use FazendoEmCasa\Cms\Core\PathGuard;
use FazendoEmCasa\Cms\Projects\DiscoveryEngine;
use FazendoEmCasa\Cms\Projects\ProjectException;
use FazendoEmCasa\Cms\Projects\ProjectRepository;

Auth::startSession();

if (!Auth::check()) {
    Http::json(['ok' => false, 'error' => 'Não autenticado.', 'authenticated' => false], 401);
}
if (!Db::connected()) {
    Http::error('Base de dados indisponível.', 503);
}
if (!Db::tableExists('cms_projects')) {
    Http::error('Migrations não aplicadas.', 503, ['hint' => 'php admin/cms/bin/migrate.php']);
}

$config = Config::load();
$guard = new PathGuard($config->projectsRoot());
$repo = new ProjectRepository($guard);

$method = Http::requireMethod('GET', 'POST');

// Estatísticas agregadas (páginas/elementos) por projeto, se a FASE 2 estiver aplicada.
$stats = [];
if (Db::tableExists('cms_pages')) {
    foreach (Db::all(
        'SELECT project_id, COUNT(*) AS pages,
                COALESCE(SUM(element_count),0) AS elements,
                COALESCE(SUM(image_count),0) AS images
         FROM cms_pages GROUP BY project_id'
    ) as $row) {
        $stats[(int) $row['project_id']] = [
            'pages' => (int) $row['pages'],
            'elements' => (int) $row['elements'],
            'images' => (int) $row['images'],
        ];
    }
}

try {
    if ($method === 'GET') {
        if (isset($_GET['discover'])) {
            $engine = new DiscoveryEngine($guard, $config->discoveryExclusions());
            Http::ok(['candidates' => $engine->scan($repo)]);
        }

        if (isset($_GET['id'])) {
            $project = $repo->find((int) $_GET['id']);
            if ($project === null) {
                Http::error('Projeto não encontrado.', 404);
            }
            Http::ok(['project' => $project, 'csrfToken' => Csrf::token()]);
        }

        Http::ok([
            'projects' => $repo->listAll(),
            'stats' => $stats,
            'csrfToken' => Csrf::token(),
        ]);
    }

    Csrf::requireValid();
    $body = Http::jsonBody();

    if (isset($_GET['id'])) {
        $id = (int) $_GET['id'];
        if (!Auth::canEditProject($id)) {
            Http::error('Sem permissão para este projeto.', 403);
        }
        Http::ok(['project' => $repo->update($id, $body)]);
    }

    Http::ok(['project' => $repo->create($body)]);
} catch (ProjectException | PathException $e) {
    Http::error($e->getMessage(), 422);
} catch (\Throwable $e) {
    error_log('cms-projects: ' . $e->getMessage());
    Http::error('Erro interno.', 500);
}
