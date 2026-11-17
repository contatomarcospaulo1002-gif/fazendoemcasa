<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_deny-direct.php';

if (!isset($projectId) || (int) $projectId <= 0) {
    http_response_code(500);
    echo 'Contexto não definido.';
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
<title><?= $projectName ?> — Histórico</title>
<link rel="stylesheet" href="/admin/assets/cms.css?v=20261116g">
</head>
<body class="cms-body">
<div class="cms-shell">

  <header class="cms-top">
    <div>
      <p class="cms-crumb"><?= $projectPath ?></p>
      <h1 class="cms-title">Histórico, backups e auditoria</h1>
    </div>
    <div class="cms-top-actions">
      <a class="cms-btn cms-btn-ghost" href="/admin/cms-project.php?id=<?= (int) $projectId ?>">← Projeto</a>
    </div>
  </header>

  <div id="cms-alert" class="cms-alert" hidden></div>

  <nav class="cms-tabs" role="tablist">
    <button type="button" class="cms-tab is-active" data-tab="publications">Publicações</button>
    <button type="button" class="cms-tab" data-tab="backups">Backups</button>
    <button type="button" class="cms-tab" data-tab="audit">Auditoria</button>
  </nav>

  <section id="tab-publications" class="cms-tab-panel" hidden>
    <div class="cms-table-wrap">
      <table class="cms-table">
        <thead>
          <tr><th>#</th><th>Página</th><th>Estado</th><th>Elementos</th><th>Data</th></tr>
        </thead>
        <tbody id="pub-body"><tr><td colspan="5">A carregar…</td></tr></tbody>
      </table>
    </div>
  </section>

  <section id="tab-backups" class="cms-tab-panel" hidden>
    <div class="cms-table-wrap">
      <table class="cms-table">
        <thead>
          <tr><th>#</th><th>Ficheiro</th><th>Motivo</th><th>Tamanho</th><th>Data</th><th></th></tr>
        </thead>
        <tbody id="bak-body"><tr><td colspan="6">A carregar…</td></tr></tbody>
      </table>
    </div>
  </section>

  <section id="tab-audit" class="cms-tab-panel" hidden>
    <div class="cms-table-wrap">
      <table class="cms-table">
        <thead>
          <tr><th>#</th><th>Usuário</th><th>Ação</th><th>Alvo</th><th>Detalhes</th><th>Data</th></tr>
        </thead>
        <tbody id="aud-body"><tr><td colspan="6">A carregar…</td></tr></tbody>
      </table>
    </div>
  </section>

</div>

<div id="cms-modal" class="cms-modal" hidden>
  <div class="cms-modal-box cms-modal-box-wide">
    <header class="cms-modal-head">
      <h2 id="modal-title">Detalhe</h2>
      <button type="button" class="cms-modal-close" id="modal-close" aria-label="Fechar">×</button>
    </header>
    <div id="modal-body" class="cms-modal-body"></div>
  </div>
</div>

<script>
  window.CMS_PROJECT_ID = <?= (int) $projectId ?>;
</script>
<script src="/admin/assets/cms-history.js?v=20261116a"></script>
</body>
</html>
