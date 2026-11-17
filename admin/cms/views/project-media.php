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
<title><?= $projectName ?> — Mídia</title>
<link rel="stylesheet" href="/admin/assets/cms.css?v=20261116g">
</head>
<body class="cms-body">
<div class="cms-shell">

  <header class="cms-top">
    <div>
      <p class="cms-crumb"><?= $projectPath ?></p>
      <h1 class="cms-title">Biblioteca de mídia</h1>
    </div>
    <div class="cms-top-actions">
      <a class="cms-btn cms-btn-ghost" href="/admin/cms-project.php?id=<?= (int) $projectId ?>">← Projeto</a>
      <button type="button" class="cms-btn cms-btn-ghost" id="btn-reindex">Reindexar usos</button>
      <label class="cms-btn cms-btn-primary" for="file-input">Enviar imagem</label>
      <input type="file" id="file-input" accept="image/*" multiple hidden>
    </div>
  </header>

  <div id="cms-alert" class="cms-alert" hidden></div>

  <div class="cms-media-toolbar">
    <input type="search" id="media-filter" class="cms-input" placeholder="Filtrar por nome…">
    <label class="cms-check">
      <input type="checkbox" id="only-unused"> Apenas não utilizadas
    </label>
    <span class="cms-media-count" id="media-count"></span>
  </div>

  <div id="media-grid" class="cms-media-grid">
    <p class="cms-empty">A carregar…</p>
  </div>

</div>

<div id="media-modal" class="cms-modal" hidden>
  <div class="cms-modal-box">
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
<script src="/admin/assets/cms-media-core.js?v=20261116g"></script>
<script src="/admin/assets/cms-media-grid.js?v=20261116g"></script>
</body>
</html>
