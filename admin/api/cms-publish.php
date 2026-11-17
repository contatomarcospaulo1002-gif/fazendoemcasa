<?php
declare(strict_types=1);

/**
 * API de publicação (FASE 6).
 *
 * POST ?project=N&page=M&action=publish — publica o rascunho aberto.
 * POST ?project=N&page=M&action=discard — descarta o rascunho.
 *
 * Publicar é irreversível no sentido de que altera o site vivo. Por isso
 * exige Auth + permissão de edição + CSRF — não há modo automático.
 */

require dirname(__DIR__) . '/cms/bootstrap.php';

use FazendoEmCasa\Cms\Core\Auth;
use FazendoEmCasa\Cms\Core\Config;
use FazendoEmCasa\Cms\Core\Csrf;
use FazendoEmCasa\Cms\Core\Db;
use FazendoEmCasa\Cms\Core\Http;
use FazendoEmCasa\Cms\Core\PathGuard;
use FazendoEmCasa\Cms\Editor\DraftService;
use FazendoEmCasa\Cms\Projects\ProjectRepository;
use FazendoEmCasa\Cms\Publisher\Publisher;

Auth::startSession();

if (!Auth::check()) {
    Http::json(['ok' => false, 'error' => 'Não autenticado.', 'authenticated' => false], 401);
}
if (!Db::connected()) {
    Http::error('Base de dados indisponível.', 503);
}
if (!Db::tableExists('cms_publications')) {
    Http::error('Migrations não aplicadas.', 503);
}

$projectId = isset($_GET['project']) ? (int) $_GET['project'] : 0;
$pageId = isset($_GET['page']) ? (int) $_GET['page'] : 0;
if ($projectId <= 0 || $pageId <= 0) {
    Http::error('Parâmetros project e page são obrigatórios.', 400);
}

$method = Http::requireMethod('POST');
Csrf::requireValid();
if (!Auth::canEditProject($projectId)) {
    Http::error('Sem permissão para publicar neste projeto.', 403);
}

$page = Db::one(
    'SELECT id FROM cms_pages WHERE id = :pg AND project_id = :p LIMIT 1',
    ['pg' => $pageId, 'p' => $projectId]
);
if ($page === null) {
    Http::error('Página não encontrada neste projeto.', 404);
}

$body = Http::jsonBody();
$action = (string) ($body['action'] ?? 'publish');
if (!in_array($action, ['publish', 'discard'], true)) {
    Http::error('Ação inválida.', 400);
}
$drafts = new DraftService();

if ($action === 'discard') {
    $drafts->discardPage($pageId);
    Http::ok(['discarded' => true, 'csrfToken' => Csrf::token()]);
}

if ($action !== 'publish') {
    Http::error('Ação inválida.', 400);
}

// Confirmação explícita: o cliente tem de enviar confirm=true.
if (($body['confirm'] ?? false) !== true) {
    Http::error('Confirmação explícita obrigatória.', 400);
}

$config = Config::load();
$repo = new ProjectRepository(new PathGuard($config->projectsRoot()));
$publisher = new Publisher($repo, $drafts);

$result = $publisher->publish($projectId, $pageId, Auth::userId());

if (!$result['ok']) {
    Http::json([
        'ok' => false,
        'error' => $result['error'],
        'status' => $result['status'],
        'publicationId' => $result['publicationId'],
        'restored' => $result['restored'],
        'csrfToken' => Csrf::token(),
    ], 422);
}

Http::ok([
    'status' => $result['status'],
    'publicationId' => $result['publicationId'],
    'changed' => $result['changed'],
    'csrfToken' => Csrf::token(),
]);
