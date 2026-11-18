/**
 * product-runtime.js — aplica a configuração do produto (CMS) à página.
 *
 * Busca /admin/api/product-config.php?slug=<slug> e:
 *  - troca o destino dos botões [data-checkout] pelo checkout_url atual;
 *  - reinicia o pixel Meta com o meta_pixel_id atual (respeitando consentimento).
 *
 * Sem resposta da API (rede/BD fora, produto pausado), nada muda: a página
 * continua com o link e o pixel escritos nela — comportamento de hoje.
 *
 * Config esperada (antes deste script):
 *   window.PRODUCT_PAGE = { slug: "kit-activites-tea" };
 */
(function () {
  var cfg = window.PRODUCT_PAGE || {};
  var slug = String(cfg.slug || "");
  var links = document.querySelectorAll("a[data-checkout]");
  if (!slug || !links.length) return;

  var remoteCheckout = "";
  function decorateCheckout(href) {
    if (!remoteCheckout) return href;
    var base;
    try { base = new URL(remoteCheckout, window.location.href); } catch (e) { return href; }
    var old;
    try { old = new URL(href, window.location.href); } catch (e) { old = null; }
    if (old) {
      old.searchParams.forEach(function (value, key) {
        if (!base.searchParams.has(key)) base.searchParams.set(key, value);
      });
    }
    return base.href;
  }
  function applyCheckout(url) {
    if (!url) return;
    remoteCheckout = url;
    links.forEach(function (link) {
      var href = link.getAttribute("href") || "";
      // Conserva os UTMs/defaults do script atual e troca só o destino base.
      link.setAttribute("href", decorateCheckout(href));
    });
  }

  function applyPixel(pixelId) {
    if (!/^\d{5,32}$/.test(String(pixelId || ""))) return;
    window.__PRODUCT_PIXEL_ID = pixelId;
    document.dispatchEvent(new CustomEvent("product:pixel", { detail: { pixelId: pixelId } }));
  }

  // A leitura é síncrona de propósito: este script tem de correr DEPOIS do
  // checkout/UTM legados (que escrevem os hrefs) e ANTES do cookies.js (que
  // lê window.__PRODUCT_PIXEL_ID e inicializa o pixel). Uma promessa não
  // garante essa ordem. O pedido é pequeno, no mesmo servidor; se falhar,
  // a página mantém os valores escritos nela (comportamento de hoje).
  try {
    var xhr = new XMLHttpRequest();
    xhr.open("GET", "/admin/api/product-config.php?slug=" + encodeURIComponent(slug), false);
    xhr.send(null);
    if (xhr.status >= 200 && xhr.status < 300) {
      var data = JSON.parse(xhr.responseText);
      if (data && data.ok && data.product) {
        if (data.product.checkout_url) applyCheckout(data.product.checkout_url);
        applyPixel(data.product.meta_pixel_id);
      }
    }
  } catch (e) {
    /* fallback silencioso: página mantém valores escritos */
  }

  // Segurança extra: se algum script legado reescrever os hrefs depois
  // (ordem alterada no futuro), reaplica o destino do catálogo no load.
  window.addEventListener("load", function () {
    if (remoteCheckout) applyCheckout(remoteCheckout);
  });
})();
