(function () {
  var PARAMS = "utm_source utm_medium utm_campaign utm_content utm_term fbclid src sck".split(" ");
  var incoming = new URLSearchParams(window.location.search);
  var toAppend = new URLSearchParams();
  PARAMS.forEach(function (key) {
    var value = incoming.get(key);
    if (value) toAppend.set(key, value);
  });
  if (!toAppend.toString()) return;
  document.querySelectorAll("a[data-checkout]").forEach(function (link) {
    var href = link.getAttribute("href") || "";
    var base = href.split("?")[0];
    var existing = href.indexOf("?") >= 0
      ? new URLSearchParams(href.slice(href.indexOf("?") + 1))
      : new URLSearchParams();
    toAppend.forEach(function (value, key) {
      if (!existing.has(key)) existing.set(key, value);
    });
    var qs = existing.toString();
    link.setAttribute("href", base + (qs ? "?" + qs : ""));
  });
})();
