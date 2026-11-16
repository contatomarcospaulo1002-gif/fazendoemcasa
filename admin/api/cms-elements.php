<?php
declare(strict_types=1);

/**
 * API de elementos de conteúdo (FASE 3 — Content Registry).
 *
 * GET  ?project=N                    → contagem por nível + adaptadores
 * GET  ?project=N&page=M             → elementos de uma página
 * GET  ?project=N&page=M&level=L     → filtra por nível de editabilidade
 * POST ?project=N&action=classify    → reclassifica projeto (CSRF)
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
use FazendoEmCasa\Cms\Registry\AdapterRegistry;
use FazendoEmCasa\Cms\Registry\Editability;
use FazendoEmCasa\Cms\Registry\ElementRegistry;
use FazendoEmCasa\Cms\Registry\RegistryService;
use FazendoEmCasa\Cms\Scanner\ScanException;
use FazendoEmCasa\Cms\Scanner\ScanService;

Auth::startSession();

if (!Auth::check()) {
    Http::json(['ok' => false, 'error' => 'Não autenticado.', 'authenticated' => false], 401);
}

if (!Db::connected()) {
    Http::error('Base de dados indisponível.', 503);
}
if (!Db::tableExists('cms_elements')) {
    Http::error('Migrations não aplicadas.', 503, ['hint' => 'php admin/cms/bin/migrate.php']);
}

$projectId = isset($_GET['project']) ? (int) $_GET['project'] : 0;
if ($projectId <= 0) {
    Http::error('Parâmetro project é obrigatório.', 400);
}

$config = Config::load();
$guard = new PathGuard($config->projectsRoot());
$repo = new ProjectRepository($guard);
$scanner = new ScanService($repo);
$registry = new ElementRegistry();
$adapters = new AdapterRegistry();
$service = new RegistryService($repo, $scanner);

$method = Http::requireMethod('GET', 'POST');

// Valida o nível antes de qualquer ramo: um filtro inválido nunca deve
// ser silenciosamente ignorado.
$level = isset($_GET['level']) ? (string) $_GET['level'] : null;
if ($level !== null && $level !== '' && !Editability::isValid($level)) {
    Http::error('Nível inválido.', 400);
}

/** Garante que a página pertence ao projeto. */
$assertPageOfProject = static function (int $pageId, int $pid): void {
    $row = Db::one(
        'SELECT id FROM cms_pages WHERE id = :pg AND project_id = :p LIMIT 1',
        ['pg' => $pageId, 'p' => $pid]
    );
    if ($row === null) {
        Http::error('Página não encontrada neste projeto.', 404);
    }
};

try {
    if ($method === 'POST') {
        Csrf::requireValid();
        if (!Auth::canEditProject($projectId)) {
            Http::error('Sem permissão para este projeto.', 403);
        }
        $action = (string) ($_GET['action'] ?? '');
        if ($action !== 'classify') {
            Http::error('Ação inválida.', 400);
        }
        $result = $service->classifyProject($projectId);
        Http::ok([
            'result' => $result,
            'counts' => $registry->countsByLevel($projectId),
            'csrfToken' => Csrf::token(),
        ]);
    }

    if (isset($_GET['page'])) {
        $pageId = (int) $_GET['page'];
        $assertPageOfProject($pageId, $projectId);

        $elements = $registry->forPage($pageId, $level);

        // Enriquecer com rótulo legível — a UI não recalcula regras.
        foreach ($elements as $i => $el) {
            $elements[$i]['editability_label'] = Editability::label((string) $el['editability']);
            $elements[$i]['writable'] = Editability::isWritable((string) $el['editability']);
        }

        Http::ok([
            'pageId' => $pageId,
            'elements' => $elements,
            'total' => count($elements),
            'csrfToken' => Csrf::token(),
        ]);
    }

    $capabilities = [];
    foreach ($adapters->all() as $adapter) {
        $cap = $adapter->capability();
        $capabilities[] = [
            'name' => $adapter->name(),
            'can_write' => (bool) $cap['canWrite'],
            'strategy' => (string) $cap['writeStrategy'],
            'limitation' => $cap['limitation'],
        ];
    }

    Http::ok([
        'counts' => $registry->countsByLevel($projectId),
        'levels' => array_map(
            static fn (string $l): array => ['key' => $l, 'label' => Editability::label($l)],
            Editability::ALL
        ),
        'adapters' => $capabilities,
        'csrfToken' => Csrf::token(),
    ]);
} catch (ScanException | PathException $e) {
    Http::error($e->getMessage(), 422);
} catch (\RuntimeException $e) {
    Http::error($e->getMessage(), 404);
} catch (\Throwable $e) {
    error_log('cms-elements: ' . $e->getMessage());
    Http::error('Erro interno.', 500);
}
