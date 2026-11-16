<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_deny-direct.php';

/**
 * Central de Projetos — tela inicial do CMS multi-projeto.
 *
 * Servida por admin/cms-ui.php (único entrypoint público da UI).
 */
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Central de Projetos</title>
<link rel="stylesheet" href="/admin/assets/cms.css?v=20261116b">
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
      <a href="/admin/cms-ui.php" class="is-active">Visão geral</a>
      <a href="/admin/cms-ui.php#projects">Meus projetos</a>
      <button type="button" class="cms-nav-action" id="btn-open-add">+ Adicionar projeto</button>

      <p class="cms-nav-label">Controle</p>
      <a href="#" class="is-disabled" title="FASE 7">Histórico</a>
      <a href="#" class="is-disabled" title="FASE 6">Publicações</a>
      <a href="#" class="is-disabled" title="FASE 7">Backups</a>
      <a href="#" class="is-disabled" title="FASE 7">Auditoria</a>
    </nav>

    <div class="cms-side-foot">
      <a href="/admin/" class="cms-link-quiet">← Painel antigo</a>
    </div>
  </aside>

  <main class="cms-main">

    <header class="cms-top">
      <div>
        <h1>Central de Projetos</h1>
        <p class="cms-sub">Administra todos os sites, landing pages e produtos digitais num só lugar.</p>
      </div>
      <div class="cms-top-actions">
        <button type="button" class="cms-btn cms-btn-ghost" id="btn-discover">Descobrir projetos</button>
        <button type="button" class="cms-btn cms-btn-primary" id="btn-open-add-2">+ Adicionar projeto</button>
      </div>
    </header>

    <div id="cms-alert" class="cms-alert" hidden></div>

    <section class="cms-stats" id="cms-stats">
      <div class="cms-stat">
        <span class="cms-stat-label">Projetos</span>
        <strong id="stat-projects">—</strong>
      </div>
      <div class="cms-stat">
        <span class="cms-stat-label">Ativos</span>
        <strong id="stat-active">—</strong>
      </div>
      <div class="cms-stat">
        <span class="cms-stat-label">Desativados</span>
        <strong id="stat-disabled">—</strong>
      </div>
      <div class="cms-stat">
        <span class="cms-stat-label">Mapeados</span>
        <strong id="stat-scanned">—</strong>
      </div>
    </section>

    <section id="projects">
      <div class="cms-section-head">
        <h2>Meus projetos</h2>
        <div class="cms-section-tools">
          <input type="search" id="project-filter" class="cms-input" placeholder="Filtrar por nome…">
        </div>
      </div>

      <div id="projects-grid" class="cms-projects">
        <p class="cms-empty">A carregar…</p>
      </div>
    </section>

    <section id="discover-panel" class="cms-card is-hidden">
      <div class="cms-card-head">
        <h2>Projetos encontrados</h2>
        <button type="button" class="cms-btn cms-btn-ghost cms-btn-sm" id="btn-close-discover">Fechar</button>
      </div>
      <p class="cms-hint">Pastas com um arquivo de entrada (<code>index.html</code> ou <code>index.php</code>) na raiz autorizada. Nada é cadastrado automaticamente.</p>
      <div id="discover-list"></div>
    </section>

  </main>
</div>

<!-- Modal: adicionar/editar projeto -->
<div id="modal-project" class="cms-modal" hidden>
  <div class="cms-modal-backdrop" data-close></div>
  <div class="cms-modal-card" role="dialog" aria-modal="true" aria-labelledby="modal-project-title">
    <h2 id="modal-project-title">Adicionar projeto</h2>
    <form id="form-project">
      <input type="hidden" id="fp-id">
      <label class="cms-field">
        <span>Nome</span>
        <input type="text" id="fp-name" required maxlength="160" placeholder="Loja Infantil">
      </label>
      <label class="cms-field">
        <span>Pasta (relativa à raiz)</span>
        <input type="text" id="fp-path" required maxlength="255" placeholder="loja-infantil" pattern="[A-Za-z0-9._\-/]+">
        <small>Sem barras no início. Exemplo: <code>loja-infantil</code> ou <code>lp/curso</code></small>
      </label>
      <label class="cms-field">
        <span>URL pública</span>
        <input type="url" id="fp-url" maxlength="255" placeholder="https://fazendoemcasa.com/loja-infantil/">
      </label>
      <label class="cms-field">
        <span>Descrição</span>
        <textarea id="fp-desc" rows="3" maxlength="500" placeholder="Loja de produtos infantis"></textarea>
      </label>
      <p id="fp-error" class="cms-field-error" hidden></p>
      <div class="cms-modal-actions">
        <button type="button" class="cms-btn cms-btn-ghost" data-close>Cancelar</button>
        <button type="submit" class="cms-btn cms-btn-primary" id="fp-submit">Guardar</button>
      </div>
    </form>
  </div>
</div>

<script src="/admin/assets/cms.js?v=20261116b"></script>
</body>
</html>
