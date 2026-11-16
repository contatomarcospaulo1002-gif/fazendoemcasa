<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_deny-direct.php';

/**
 * Conteúdo de uma página — árvore de elementos e níveis.
 *
 * Servida por admin/cms-content.php. Espera $projectId, $pageId,
 * $projectName e $projectPath definidos pelo entrypoint.
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
<title><?= $projectName ?> — Conteúdo</title>
<link rel="stylesheet" href="/admin/assets/cms.css?v=20261116e">
</head>
<body>
<div class="cms-shell">

  <aside class="cms-side">
    <div class="cms-brand">
      <span class="cms-brand-mark">◱</span>
      <div>
        <strong>Central CMS</strong>
        <small>Multi-projeto</small>
      </div>
    </div>
    <nav class="cms-nav">
      <p class="cms-nav-label">Central</p>
      <a href="/admin/cms-ui.php">Visão geral</a>
      <a href="/admin/cms-ui.php#projects">Meus projetos</a>

      <p class="cms-nav-label">Este projeto</p>
      <a href="/admin/cms-project.php?id=<?= (int) $projectId ?>">Páginas</a>
      <a href="#" class="is-active">Conteúdo</a>
      <a href="#" class="is-disabled" title="FASE 4">Editor</a>
      <a href="#" class="is-disabled" title="FASE 5">Mídia</a>
      <a href="#" class="is-disabled" title="FASE 6">Publicações</a>
    </nav>
    <div class="cms-side-foot">
      <a href="/admin/cms-project.php?id=<?= (int) $projectId ?>" class="cms-link-quiet">← Páginas</a>
    </div>
  </aside>

  <main class="cms-main">
    <header class="cms-top">
      <div>
        <p class="cms-crumb"><?= $projectPath ?> / <?= $pagePath ?></p>
        <h1>Conteúdo da página</h1>
        <p class="cms-sub" id="content-sub">A carregar elementos…</p>
      </div>
      <div class="cms-top-actions">
        <button type="button" class="cms-btn cms-btn-primary" id="btn-classify">Classificar conteúdo</button>
      </div>
    </header>

    <div id="cms-alert" class="cms-alert" hidden></div>

    <section class="cms-stats" id="level-stats">
      <div class="cms-stat"><span class="cms-stat-label">Editável</span><strong id="l-editable">—</strong></div>
      <div class="cms-stat"><span class="cms-stat-label">Requer adaptador</span><strong id="l-needs">—</strong></div>
      <div class="cms-stat"><span class="cms-stat-label">Somente leitura</span><strong id="l-readonly">—</strong></div>
      <div class="cms-stat"><span class="cms-stat-label">Não configurado</span><strong id="l-unconf">—</strong></div>
    </section>

    <section>
      <div class="cms-section-head">
        <h2>Elementos</h2>
        <div class="cms-section-tools">
          <select id="level-filter" class="cms-input cms-select">
            <option value="">Todos os níveis</option>
            <option value="editable">Editável</option>
            <option value="needs_adapter">Requer adaptador</option>
            <option value="read_only">Somente leitura</option>
            <option value="unconfigured">Não configurado</option>
          </select>
          <input type="search" id="el-filter" class="cms-input" placeholder="Filtrar por selector ou valor…">
        </div>
      </div>
      <div class="cms-card cms-card-flush">
        <div id="elements-list"><p class="cms-empty">A carregar…</p></div>
      </div>
    </section>
  </main>
</div>

<script>
  window.CMS_PROJECT_ID = <?= (int) $projectId ?>;
  window.CMS_PAGE_ID = <?= (int) $pageId ?>;
</script>
<script src="/admin/assets/cms-content.js?v=20261116e"></script>
</body>
</html>
