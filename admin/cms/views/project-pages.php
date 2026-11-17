<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_deny-direct.php';

/**
 * Páginas de um projeto.
 *
 * Servida por admin/cms-project.php, que valida sessão e resolve o id.
 * Espera $projectId definido pelo entrypoint.
 */

if (!isset($projectId) || (int) $projectId <= 0) {
    http_response_code(500);
    echo 'Projeto não definido.';
    exit;
}

$projectName = htmlspecialchars((string) ($projectName ?? 'Projeto'), ENT_QUOTES, 'UTF-8');
$projectPath = htmlspecialchars((string) ($projectPath ?? ''), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $projectName ?> — Páginas</title>
<link rel="stylesheet" href="/admin/assets/cms.css?v=20261116d">
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
      <a href="#" class="is-active">Páginas</a>
      <a href="#" class="is-disabled" title="FASE 3">Conteúdo</a>
      <a href="#" class="is-disabled" title="FASE 4">Editor</a>
      <a href="#" class="is-disabled" title="FASE 5">Mídia</a>
      <a href="#" class="is-disabled" title="FASE 6">Publicações</a>
    </nav>
    <div class="cms-side-foot">
      <a href="/admin/cms-ui.php" class="cms-link-quiet">← Todos os projetos</a>
    </div>
  </aside>

  <main class="cms-main">
    <header class="cms-top">
      <div>
        <p class="cms-crumb"><?= $projectPath ?>/</p>
        <h1><?= $projectName ?></h1>
        <p class="cms-sub" id="pages-sub">A carregar inventário…</p>
      </div>
      <div class="cms-top-actions">
        <a class="cms-btn cms-btn-ghost" href="/admin/cms-media.php?id=<?= (int) $projectId ?>">Mídia</a>
        <button type="button" class="cms-btn cms-btn-primary" id="btn-scan">Analisar projeto</button>
      </div>
    </header>

    <div id="cms-alert" class="cms-alert" hidden></div>

    <section class="cms-stats">
      <div class="cms-stat"><span class="cms-stat-label">Páginas</span><strong id="s-pages">—</strong></div>
      <div class="cms-stat"><span class="cms-stat-label">Elementos</span><strong id="s-elements">—</strong></div>
      <div class="cms-stat"><span class="cms-stat-label">Imagens</span><strong id="s-images">—</strong></div>
      <div class="cms-stat"><span class="cms-stat-label">Última análise</span><strong id="s-scan" class="cms-stat-sm">—</strong></div>
    </section>

    <section>
      <div class="cms-section-head">
        <h2>Páginas e arquivos</h2>
        <div class="cms-section-tools">
          <select id="kind-filter" class="cms-input cms-select">
            <option value="">Todos os tipos</option>
            <option value="html">HTML</option>
            <option value="php">PHP</option>
            <option value="css">CSS</option>
            <option value="js">JS</option>
            <option value="json">JSON</option>
          </select>
          <input type="search" id="page-filter" class="cms-input" placeholder="Filtrar por caminho…">
        </div>
      </div>
      <div class="cms-card cms-card-flush">
        <div id="pages-list"><p class="cms-empty">A carregar…</p></div>
      </div>
    </section>
  </main>
</div>

<script>
  window.CMS_PROJECT_ID = <?= (int) $projectId ?>;
</script>
<script src="/admin/assets/cms-pages.js?v=20261116d"></script>
</body>
</html>
