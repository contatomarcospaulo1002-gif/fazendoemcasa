(function () {
  var cfg = window.FR49 || {};
  var base = (cfg.checkoutUrl || "").trim();
  var link = document.querySelector("a[data-checkout]");
  if (!link) return;
  if (!base) {
    link.setAttribute("href", "#checkout-pending-funil-b3");
    link.setAttribute("aria-disabled", "true");
    link.title = "Checkout Hotmart en attente (Funil B3)";
    return;
  }
  var params = new URLSearchParams(cfg.defaultUtm || {});
  var incoming = new URLSearchParams(window.location.search);
  ["utm_source", "utm_medium", "utm_campaign", "utm_content", "utm_term", "fbclid"].forEach(function (key) {
    var v = incoming.get(key);
    if (v && !params.has(key)) params.set(key, v);
  });
  var qs = params.toString();
  link.setAttribute("href", base + (qs ? (base.indexOf("?") >= 0 ? "&" : "?") + qs : ""));
})();