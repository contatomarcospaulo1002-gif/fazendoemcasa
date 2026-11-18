<?php
declare(strict_types=1);

/**
 * Produtos — catálogo comercial do CMS.
 *
 * Servida por admin/cms-products.php, que valida sessão e garante que
 * a migration cms_products foi aplicada. A lista e a edição falam com
 * /admin/api/cms-products.php.
 */

use FazendoEmCasa\Cms\Core\Auth;
use FazendoEmCasa\Cms\Core\Db;

require_once dirname(__DIR__) . '/bootstrap.php';

Auth::startSession();
if (!Auth::check()) {
    header('Location: /admin/', true, 302);
    exit;
}
if (!Db::connected() || !Db::tableExists('cms_products')) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="pt-PT"><head><meta charset="utf-8"><title>Produtos — configuração pendente</title>';
    echo '<style>body{font-family:system-ui,sans-serif;max-width:640px;margin:80px auto;padding:0 24px}code{background:#f3f3f3;padding:2px 6px;border-radius:4px}</style></head><body>';
    echo '<h1>Configuração pendente</h1><p>Corre no servidor:</p><pre><code>php admin/cms/bin/migrate.php</code></pre>';
    echo '</body></html>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Produtos — Central CMS</title>
<link rel="stylesheet" href="/admin/assets/cms.css?v=20261118b">
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
      <a href="#" class="is-active">Produtos</a>
    </nav>
    <div class="cms-side-foot">
      <a href="/admin/cms-ui.php" class="cms-link-quiet">← Visão geral</a>
    </div>
  </aside>

  <main class="cms-main">
    <header class="cms-top">
      <div>
        <h1>Produtos</h1>
        <p class="cms-sub">Onde cada oferta vende: checkout, pixel e estado. A página de vendas continua a ser editada no projeto dela.</p>
      </div>
      <div class="cms-top-actions">
        <button type="button" class="cms-btn cms-btn-primary" id="btn-new-product">+ Novo produto</button>
      </div>
    </header>

    <div id="cms-alert" class="cms-alert" hidden></div>

    <section class="cms-card cms-card-flush">
      <div class="cms-table-wrap" style="border:none">
        <table class="cms-table" id="products-table">
          <thead>
            <tr>
              <th>Produto</th>
              <th>Página</th>
              <th>Checkout</th>
              <th>Pixel Meta</th>
              <th>Estado</th>
              <th></th>
            </tr>
          </thead>
          <tbody id="products-tbody">
            <tr><td colspan="6" class="cms-empty">A carregar…</td></tr>
          </tbody>
        </table>
      </div>
    </section>
  </main>
</div>

<!-- Modal: editar produto -->
<div id="modal-product" class="cms-modal" hidden>
  <div class="cms-modal-backdrop" data-close></div>
  <div class="cms-modal-card" role="dialog" aria-modal="true" aria-labelledby="modal-product-title" style="max-width:520px">
    <h2 id="modal-product-title">Editar produto</h2>
    <form id="form-product">
      <input type="hidden" id="mp-id">
      <label class="cms-field">
        <span>Nome</span>
        <input type="text" id="mp-name" required maxlength="160">
      </label>
      <label class="cms-field">
        <span>Plataforma de checkout</span>
        <select id="mp-provider">
          <option value="hotmart">Hotmart</option>
          <option value="cakto">Cakto</option>
          <option value="kiwify">Kiwify</option>
          <option value="custom">Próprio / outro</option>
        </select>
        <small>Para onde o botão Comprar desta oferta deve levar. O checkout próprio (Mercado Pago) entra aqui mais tarde.</small>
      </label>
      <label class="cms-field">
        <span>Link de checkout</span>
        <input type="url" id="mp-checkout" maxlength="500" placeholder="https://pay.hotmart.com/…">
        <small>Tem de começar por <code>https://</code>. Testa o link antes de publicar.</small>
      </label>
      <label class="cms-field">
        <span>ID do pixel Meta</span>
        <input type="text" id="mp-pixel" inputmode="numeric" maxlength="32" placeholder="2111475296169479">
        <small>Só números. Deixa vazio se este produto não tiver pixel.</small>
      </label>
      <label class="cms-field">
        <span>Estado</span>
        <select id="mp-status">
          <option value="active">Ativo</option>
          <option value="disabled">Pausado</option>
        </select>
        <small>Pausar não altera a página no ar; só marca o produto na Central.</small>
      </label>
      <p id="mp-error" class="cms-field-error" hidden></p>
      <div class="cms-modal-actions">
        <button type="button" class="cms-btn cms-btn-ghost" data-close>Cancelar</button>
        <button type="submit" class="cms-btn cms-btn-primary" id="mp-submit">Guardar</button>
      </div>
    </form>
  </div>
</div>

<script>
  window.CMS_PRODUCTS_CFG = { api: "/admin/api/cms-products.php" };
</script>
<script src="/admin/assets/cms-products.js?v=20261118b"></script>
</body>
</html>
