<?php
declare(strict_types=1);

/**
 * API de páginas e varredura de um projeto.
 *
 * GET  ?project=N              → lista páginas + resumo + última varredura
 * GET  ?project=N&page=M       → detalhe de uma página
 * POST ?project=N&action=scan  → executa varredura
 */

require dirname(__DIR__) . '/cms/bootstrap.php';

use FazendoEmCasa\Cms\Core\Auth;
use FazendoEmCasa\Cms\Core\Config;
use FazendoEmCasa\Cms\Core\Csrf;
use FazendoEmCasa\Cms\Core\Db;
use FazendoEmCasa\Cms\Core\Http;
use FazendoEmCasa\Cms\Core\PathException;
use FazendoEmCasa\Cms\Core\PathGuard;
use FazendoEmCasa\Cms\Projects\ProjectRepository;
use FazendoEmCasa\Cms\Scanner\ScanException;
use FazendoEmCasa\Cms\Scanner\ScanService;

Auth::startSession();

if (!Auth::check()) {
    Http::json(['ok' => false, 'error' => 'Não autenticado.', 'authenticated' => false], 401);
}

if (!Db::connected()) {
    Http::error('Base de dados indisponível.', 503);
}
if (!Db::tableExists('cms_pages')) {
    Http::error('Migrations não aplicadas.', 503, ['hint' => 'php admin/cms/bin/migrate.php']);
}

$projectId = isset($_GET['project']) ? (int) $_GET['project'] : 0;
if ($projectId <= 0) {
    Http::error('Parâmetro project é obrigatório.', 400);
}

$config = Config::load();
$guard = new PathGuard($config->projectsRoot());
$repo = new ProjectRepository($guard);
$service = new ScanService($repo);

$method = Http::requireMethod('GET', 'POST');

try {
    if ($method === 'POST') {
        Csrf::requireValid();
        if (!Auth::canEditProject($projectId)) {
            Http::error('Sem permissão para este projeto.', 403);
        }
        $action = (string) ($_GET['action'] ?? '');
        if ($action !== 'scan') {
            Http::error('Ação inválida.', 400);
        }
        $result = $service->scanProject($projectId);
        Http::ok([
            'scan' => $result,
            'summary' => $service->summary($projectId),
            'pages' => $service->pages($projectId),
        ]);
    }

    if (isset($_GET['page'])) {
        $page = $service->page($projectId, (int) $_GET['page']);
        if ($page === null) {
            Http::error('Página não encontrada.', 404);
        }
        Http::ok(['page' => $page, 'csrfToken' => Csrf::token()]);
    }

    $kind = isset($_GET['kind']) ? (string) $_GET['kind'] : null;
    Http::ok([
        'pages' => $service->pages($projectId, $kind),
        'summary' => $service->summary($projectId),
        'lastScan' => $service->lastScan($projectId),
        'csrfToken' => Csrf::token(),
    ]);
} catch (ScanException | PathException $e) {
    Http::error($e->getMessage(), 422);
} catch (\Throwable $e) {
    error_log('cms-pages: ' . $e->getMessage());
    Http::error('Erro interno.', 500);
}
