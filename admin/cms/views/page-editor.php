<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_deny-direct.php';

/**
 * Editor visual de uma página.
 *
 * Servida por admin/cms-editor.php. Espera $projectId, $pageId,
 * $projectName, $projectPath e $pagePath.
 */

if (!isset($projectId, $pageId) || (int) $projectId <= 0 || (int) $pageId <= 0) {
    http_response_code(500);
    echo 'Contexto não definido.';
    exit;
}

$projectName = htmlspecialchars((string) ($projectName ?? 'Projeto'), ENT_QUOTES, 'UTF-8');
$projectPath = htmlspecialchars((string) ($projectPath ?? ''), ENT_QUOTES, 'UTF-8');
$pagePath = htmlspecialchars((string) ($pagePath ?? ''), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $projectName ?> — Editor</title>
<link rel="stylesheet" href="/admin/assets/cms.css?v=20261116f">
</head>
<body class="cms-editor-body">
<div class="cms-editor">

  <header class="cms-editor-top">
    <div class="cms-editor-top-left">
      <a href="/admin/cms-content.php?id=<?= (int) $projectId ?>&page=<?= (int) $pageId ?>" class="cms-link-quiet">← Conteúdo</a>
      <div>
        <p class="cms-crumb"><?= $projectPath ?> / <?= $pagePath ?></p>
        <h1 class="cms-editor-title">Editor</h1>
      </div>
    </div>
    <div class="cms-editor-top-right">
      <span class="cms-draft-badge" id="draft-badge" hidden>0 alterações</span>
      <div class="cms-device-switch" role="group" aria-label="Pré-visualização">
        <button type="button" class="cms-device is-active" data-device="desktop" title="Desktop">▭</button>
        <button type="button" class="cms-device" data-device="tablet" title="Tablet">▯</button>
        <button type="button" class="cms-device" data-device="mobile" title="Celular">▮</button>
      </div>
      <button type="button" class="cms-btn cms-btn-ghost" id="btn-discard" disabled>Descartar</button>
      <button type="button" class="cms-btn cms-btn-primary" id="btn-save" disabled>Guardar rascunho</button>
    </div>
  </header>

  <div id="cms-alert" class="cms-alert cms-editor-alert" hidden></div>

  <div class="cms-editor-grid">

    <aside class="cms-editor-tree">
      <div class="cms-editor-pane-head">
        <h2>Elementos</h2>
        <input type="search" id="tree-filter" class="cms-input cms-input-sm" placeholder="Filtrar…">
      </div>
      <div id="tree-list" class="cms-tree-list">
        <p class="cms-empty">A carregar…</p>
      </div>
    </aside>

    <main class="cms-editor-preview">
      <div class="cms-preview-frame-wrap" id="preview-wrap">
        <iframe id="preview-frame" class="cms-preview-frame" sandbox=""
                title="Pré-visualização da página"></iframe>
      </div>
      <p class="cms-preview-note" id="preview-note">Pré-visualização em memória — nada foi gravado no ficheiro.</p>
    </main>

    <aside class="cms-editor-props">
      <div class="cms-editor-pane-head">
        <h2>Propriedades</h2>
      </div>
      <div id="props-panel" class="cms-props-panel">
        <p class="cms-empty">Selecione um elemento na lista.</p>
      </div>
    </aside>

  </div>
</div>

<script>
  window.CMS_PROJECT_ID = <?= (int) $projectId ?>;
  window.CMS_PAGE_ID = <?= (int) $pageId ?>;
</script>
<script src="/admin/assets/cms-editor-core.js?v=20261116f"></script>
<script src="/admin/assets/cms-editor-tree.js?v=20261116f"></script>
<script src="/admin/assets/cms-editor-props.js?v=20261116f"></script>
<script src="/admin/assets/cms-editor-shell.js?v=20261116f"></script>
</body>
</html>
