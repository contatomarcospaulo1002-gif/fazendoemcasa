<?php
declare(strict_types=1);

/**
 * API do editor visual (FASE 4).
 *
 * GET  ?project=N&page=M              → elementos + patch do rascunho
 * GET  ?project=N&page=M&preview=1    → HTML com o rascunho aplicado
 * POST ?project=N&page=M&action=save  → grava patch (CSRF)
 * POST ?project=N&page=M&action=discard       → descarta rascunho (CSRF)
 * POST ?project=N&page=M&action=discard-element → descarta 1 campo (CSRF)
 *
 * Nada aqui escreve em disco. O rascunho só vira ficheiro na FASE 6.
 */

require dirname(__DIR__) . '/cms/bootstrap.php';

use FazendoEmCasa\Cms\Core\Auth;
use FazendoEmCasa\Cms\Core\Config;
use FazendoEmCasa\Cms\Core\Csrf;
use FazendoEmCasa\Cms\Core\Db;
use FazendoEmCasa\Cms\Core\Http;
use FazendoEmCasa\Cms\Core\PathException;
use FazendoEmCasa\Cms\Core\PathGuard;
use FazendoEmCasa\Cms\Editor\DraftService;
use FazendoEmCasa\Cms\Editor\PreviewService;
use FazendoEmCasa\Cms\Projects\ProjectRepository;
use FazendoEmCasa\Cms\Registry\Editability;
use FazendoEmCasa\Cms\Registry\ElementRegistry;

Auth::startSession();

if (!Auth::check()) {
    Http::json(['ok' => false, 'error' => 'Não autenticado.', 'authenticated' => false], 401);
}

if (!Db::connected()) {
    Http::error('Base de dados indisponível.', 503);
}
if (!Db::tableExists('cms_drafts') || !Db::tableExists('cms_elements')) {
    Http::error('Migrations não aplicadas.', 503, ['hint' => 'php admin/cms/bin/migrate.php']);
}

$projectId = isset($_GET['project']) ? (int) $_GET['project'] : 0;
$pageId = isset($_GET['page']) ? (int) $_GET['page'] : 0;

if ($projectId <= 0) {
    Http::error('Parâmetro project é obrigatório.', 400);
}
if ($pageId <= 0) {
    Http::error('Parâmetro page é obrigatório.', 400);
}

$config = Config::load();
$guard = new PathGuard($config->projectsRoot());
$repo = new ProjectRepository($guard);
$drafts = new DraftService();
$preview = new PreviewService($repo, $drafts);
$registry = new ElementRegistry();

$method = Http::requireMethod('GET', 'POST');

// CSRF e permissão ANTES de qualquer consulta: um POST forjado não deve
// sequer revelar se a página existe.
if ($method === 'POST') {
    Csrf::requireValid();
    if (!Auth::canEditProject($projectId)) {
        Http::error('Sem permissão para este projeto.', 403);
    }
}

// A página tem de pertencer ao projeto — nunca confiar no par do request.
$page = Db::one(
    'SELECT id, rel_path, kind, title FROM cms_pages WHERE id = :pg AND project_id = :p LIMIT 1',
    ['pg' => $pageId, 'p' => $projectId]
);
if ($page === null) {
    Http::error('Página não encontrada neste projeto.', 404);
}

try {
    if ($method === 'POST') {
        $action = (string) ($_GET['action'] ?? '');

        if ($action === 'save') {
            $body = Http::jsonBody();
            $patch = $body['patch'] ?? null;
            if (!is_array($patch)) {
                Http::error('Campo patch é obrigatório.', 400);
            }

            $result = $drafts->save($projectId, $pageId, $patch, Auth::userId());
            if (!$result['ok']) {
                Http::json([
                    'ok' => false,
                    'error' => 'Patch rejeitado.',
                    'errors' => $result['errors'],
                ], 422);
            }

            Http::ok([
                'changed' => $result['changed'],
                'patch' => $drafts->patchFor($pageId),
                'csrfToken' => Csrf::token(),
            ]);
        }

        if ($action === 'discard') {
            $drafts->discardPage($pageId);
            Http::ok(['patch' => [], 'csrfToken' => Csrf::token()]);
        }

        if ($action === 'discard-element') {
            $body = Http::jsonBody();
            $elementId = (int) ($body['elementId'] ?? 0);
            if ($elementId <= 0) {
                Http::error('elementId é obrigatório.', 400);
            }
            $drafts->discardElement($pageId, $elementId);
            Http::ok(['patch' => $drafts->patchFor($pageId), 'csrfToken' => Csrf::token()]);
        }

        Http::error('Ação inválida.', 400);
    }

    if (isset($_GET['preview'])) {
        $pv = $preview->preview($projectId, $pageId);
        if (!$pv['ok']) {
            Http::error($pv['error'] ?? 'Pré-visualização indisponível.', 422);
        }
        // O HTML é devolvido como texto; a UI injeta em iframe com sandbox.
        Http::ok([
            'html' => $pv['html'],
            'applied' => $pv['applied'],
            'kind' => $pv['kind'],
        ]);
    }

    $elements = $registry->forPage($pageId);
    foreach ($elements as $i => $el) {
        $elements[$i]['editability_label'] = Editability::label((string) $el['editability']);
        $elements[$i]['writable'] = Editability::isWritable((string) $el['editability']);
    }

    Http::ok([
        'page' => [
            'id' => (int) $page['id'],
            'rel_path' => (string) $page['rel_path'],
            'kind' => (string) $page['kind'],
            'title' => (string) ($page['title'] ?? ''),
        ],
        'elements' => $elements,
        'patch' => $drafts->patchFor($pageId),
        'csrfToken' => Csrf::token(),
    ]);
} catch (PathException $e) {
    Http::error($e->getMessage(), 422);
} catch (\Throwable $e) {
    error_log('cms-editor: ' . $e->getMessage());
    Http::error('Erro interno.', 500);
}
