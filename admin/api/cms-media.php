<?php
declare(strict_types=1);

/**
 * API da biblioteca de mídia (FASE 5).
 *
 * GET  ?project=N                    → lista de mídias + usos
 * GET  ?project=N&unused=1           → apenas não utilizadas
 * GET  ?project=N&media=M            → detalhe + referências
 * POST ?project=N&action=upload      → upload multipart (CSRF)
 * POST ?project=N&action=replace     → substituição com escopo (CSRF)
 * POST ?project=N&action=reindex     → reconstrói o índice (CSRF)
 * POST ?project=N&action=delete      → remove mídia não usada (CSRF)
 */

require dirname(__DIR__) . '/cms/bootstrap.php';

use FazendoEmCasa\Cms\Core\Auth;
use FazendoEmCasa\Cms\Core\Config;
use FazendoEmCasa\Cms\Core\Csrf;
use FazendoEmCasa\Cms\Core\Db;
use FazendoEmCasa\Cms\Core\Http;
use FazendoEmCasa\Cms\Core\PathGuard;
use FazendoEmCasa\Cms\Media\MediaReplacer;
use FazendoEmCasa\Cms\Media\MediaStorage;
use FazendoEmCasa\Cms\Media\ReferenceIndex;
use FazendoEmCasa\Cms\Projects\ProjectRepository;

Auth::startSession();

if (!Auth::check()) {
    Http::json(['ok' => false, 'error' => 'Não autenticado.', 'authenticated' => false], 401);
}

if (!Db::connected()) {
    Http::error('Base de dados indisponível.', 503);
}
if (!Db::tableExists('cms_media')) {
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

$storage = new MediaStorage($repo);
$index = new ReferenceIndex();
$replacer = new MediaReplacer($index);

$method = Http::requireMethod('GET', 'POST');

if ($method === 'POST') {
    Csrf::requireValid();
    if (!Auth::canEditProject($projectId)) {
        Http::error('Sem permissão para este projeto.', 403);
    }

    $action = (string) ($_GET['action'] ?? '');

    if ($action === 'upload') {
        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || !isset($file['tmp_name'])) {
            Http::error('Nenhum ficheiro enviado.', 400);
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Http::error('Falha no upload (código ' . (int) $file['error'] . ').', 400);
        }

        $category = (string) ($_POST['category'] ?? 'geral');
        $result = $storage->store(
            $projectId,
            (string) $file['tmp_name'],
            (string) ($file['name'] ?? 'ficheiro'),
            Auth::userId(),
            $category
        );

        if (!$result['ok']) {
            Http::json(['ok' => false, 'error' => $result['error']], 422);
        }

        $index->rebuild($projectId);
        Http::ok(['media' => $result['media'], 'csrfToken' => Csrf::token()]);
    }

    if ($action === 'replace') {
        $body = Http::jsonBody();
        $mediaId = (int) ($body['mediaId'] ?? 0);
        $newPath = (string) ($body['newPath'] ?? '');
        $scope = (string) ($body['scope'] ?? '');
        $pageId = isset($body['pageId']) ? (int) $body['pageId'] : null;
        $elementId = isset($body['elementId']) ? (int) $body['elementId'] : null;

        if ($mediaId <= 0 || $newPath === '') {
            Http::error('mediaId e newPath são obrigatórios.', 400);
        }

        $result = $replacer->replace($projectId, $mediaId, $newPath, $scope, $pageId, $elementId);
        if (!$result['ok']) {
            Http::json(['ok' => false, 'error' => $result['error']], 422);
        }

        Http::ok([
            'affected' => $result['affected'],
            'pages' => $result['pages'],
            'csrfToken' => Csrf::token(),
        ]);
    }

    if ($action === 'reindex') {
        $n = $index->rebuild($projectId);
        Http::ok(['references' => $n, 'csrfToken' => Csrf::token()]);
    }

    if ($action === 'delete') {
        $body = Http::jsonBody();
        $mediaId = (int) ($body['mediaId'] ?? 0);
        if ($mediaId <= 0) {
            Http::error('mediaId é obrigatório.', 400);
        }

        $media = Db::one(
            'SELECT * FROM cms_media WHERE id = :m AND project_id = :p LIMIT 1',
            ['m' => $mediaId, 'p' => $projectId]
        );
        if ($media === null) {
            Http::error('Mídia não encontrada.', 404);
        }

        // Só remove mídia sem uso: apagar uma imagem em uso deixaria
        // páginas com referências quebradas.
        $refs = $index->forMedia($mediaId);
        if ($refs !== []) {
            Http::json([
                'ok' => false,
                'error' => 'Mídia em uso em ' . count($refs) . ' local(is). Substitua antes de remover.',
                'references' => $refs,
            ], 409);
        }

        Db::exec('DELETE FROM cms_media WHERE id = :m', ['m' => $mediaId]);
        Http::ok(['deleted' => true, 'csrfToken' => Csrf::token()]);
    }

    Http::error('Ação inválida.', 400);
}

$mediaId = isset($_GET['media']) ? (int) $_GET['media'] : 0;

if ($mediaId > 0) {
    $media = Db::one(
        'SELECT * FROM cms_media WHERE id = :m AND project_id = :p LIMIT 1',
        ['m' => $mediaId, 'p' => $projectId]
    );
    if ($media === null) {
        Http::error('Mídia não encontrada.', 404);
    }

    $project = $repo->find($projectId);
    $baseUrl = rtrim((string) ($project['base_url'] ?? ''), '/');
    $prefix = trim((string) ($project['rel_path'] ?? ''), '/');
    $rel = (string) $media['rel_path'];
    if ($prefix !== '' && str_starts_with($rel, $prefix . '/')) {
        $rel = substr($rel, strlen($prefix) + 1);
    }
    $media['url'] = $baseUrl !== '' ? $baseUrl . '/' . $rel : '/' . $rel;

    Http::ok([
        'media' => $media,
        'references' => $index->forMedia($mediaId),
        'csrfToken' => Csrf::token(),
    ]);
}

$rows = isset($_GET['unused'])
    ? $index->unused($projectId)
    : Db::all(
        'SELECT * FROM cms_media WHERE project_id = :p ORDER BY created_at DESC',
        ['p' => $projectId]
    );

$counts = $index->usageCounts($projectId);
$project = $repo->find($projectId);
$baseUrl = rtrim((string) ($project['base_url'] ?? ''), '/');
$prefix = trim((string) ($project['rel_path'] ?? ''), '/');

foreach ($rows as $i => $row) {
    $rows[$i]['usage_count'] = $counts[(int) $row['id']] ?? 0;

    // rel_path inclui o prefixo do projeto (ex.: "loja-demo/uploads/x.png").
    // A URL pública não inclui esse prefixo: o projeto É a raiz servida.
    $rel = (string) $row['rel_path'];
    if ($prefix !== '' && str_starts_with($rel, $prefix . '/')) {
        $rel = substr($rel, strlen($prefix) + 1);
    }
    $rows[$i]['url'] = $baseUrl !== '' ? $baseUrl . '/' . $rel : '/' . $rel;
}

Http::ok([
    'media' => $rows,
    'total' => count($rows),
    'csrfToken' => Csrf::token(),
]);
