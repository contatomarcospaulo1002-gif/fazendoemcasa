"use strict";

/**
 * Páginas de um projeto — front-end.
 */
(function () {
  var API = "/admin/api/cms-pages.php";
  var PROJECT = window.CMS_PROJECT_ID || 0;
  var state = { pages: [], csrf: "", filter: "", kind: "" };

  function $(id) { return document.getElementById(id); }

  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  function alertMsg(msg, kind) {
    var box = $("cms-alert");
    if (!box) return;
    if (!msg) { box.hidden = true; return; }
    box.hidden = false;
    box.className = "cms-alert is-" + (kind || "error");
    box.textContent = msg;
  }

  function api(query, options) {
    options = options || {};
    var opts = {
      method: options.method || "GET",
      credentials: "same-origin",
      headers: options.headers || {}
    };
    if (opts.method !== "GET" && state.csrf) {
      opts.headers["X-CSRF-Token"] = state.csrf;
    }
    return fetch(API + query, opts).then(function (r) {
      return r.json().then(function (data) {
        if (!r.ok) {
          var e = new Error(data.error || "Erro " + r.status);
          e.status = r.status;
          throw e;
        }
        return data;
      });
    });
  }

  function fmtDate(iso) {
    if (!iso) return "—";
    var d = new Date(String(iso).replace(" ", "T") + (String(iso).indexOf("T") < 0 ? "Z" : ""));
    if (isNaN(d.getTime())) return "—";
    return d.toLocaleString("pt-PT", {
      day: "2-digit", month: "2-digit", year: "numeric",
      hour: "2-digit", minute: "2-digit"
    });
  }

  function fmtBytes(n) {
    n = Number(n) || 0;
    if (n < 1024) return n + " B";
    if (n < 1024 * 1024) return (n / 1024).toFixed(1) + " KB";
    return (n / 1024 / 1024).toFixed(2) + " MB";
  }

  var KIND_LABEL = { html: "HTML", php: "PHP", css: "CSS", js: "JS", json: "JSON", other: "Outro" };

  // Só estes tipos têm árvore de conteúdo no Registry.
  var contentKinds = ["html", "php", "css", "json"];

  function rowHtml(p) {
    var kind = String(p.kind || "other");
    var title = p.title ? esc(p.title) : "";
    return (
      '<div class="cms-page-row" data-id="' + esc(p.id) + '">' +
        '<span class="cms-kind cms-kind-' + esc(kind) + '">' + esc(KIND_LABEL[kind] || kind) + "</span>" +
        '<div class="cms-page-main">' +
          '<div class="cms-page-path">' + esc(p.rel_path) + (p.is_entrypoint == 1 ? ' <span class="cms-badge is-scan">entrada</span>' : "") + "</div>" +
          (title ? '<div class="cms-page-title">' + title + "</div>" : "") +
        "</div>" +
        '<div class="cms-page-metrics">' +
          '<span title="Elementos editáveis">' + (Number(p.element_count) || 0) + " el.</span>" +
          '<span title="Imagens">' + (Number(p.image_count) || 0) + " img</span>" +
          '<span title="Tamanho">' + fmtBytes(p.size_bytes) + "</span>" +
        "</div>" +
        (p.status === "error"
          ? '<span class="cms-badge is-new" title="' + esc(p.error_message || "") + '">erro</span>'
          : '<span class="cms-badge is-active">ok</span>') +
        (contentKinds.indexOf(kind) >= 0
          ? '<a class="cms-btn cms-btn-ghost cms-btn-xs" href="/admin/cms-content.php?id='
            + encodeURIComponent(window.CMS_PROJECT_ID) + '&page=' + encodeURIComponent(p.id)
            + '">Conteúdo</a>'
          : "") +
      "</div>"
    );
  }

  function render() {
    var list = $("pages-list");
    if (!list) return;

    var rows = state.pages.filter(function (p) {
      if (state.kind && String(p.kind) !== state.kind) return false;
      if (!state.filter) return true;
      var q = state.filter.toLowerCase();
      return String(p.rel_path).toLowerCase().indexOf(q) >= 0 ||
             String(p.title || "").toLowerCase().indexOf(q) >= 0;
    });

    if (!state.pages.length) {
      list.innerHTML = '<p class="cms-empty">Nenhuma página analisada ainda. Usa <strong>Analisar projeto</strong>.</p>';
    } else if (!rows.length) {
      list.innerHTML = '<p class="cms-empty">Nenhum resultado para o filtro.</p>';
    } else {
      list.innerHTML = rows.map(rowHtml).join("");
    }
  }

  function renderSummary(sum, lastScan) {
    if ($("s-pages")) $("s-pages").textContent = sum ? sum.pages : "—";
    if ($("s-elements")) $("s-elements").textContent = sum ? sum.elements : "—";
    if ($("s-images")) $("s-images").textContent = sum ? sum.images : "—";
    if ($("s-scan")) $("s-scan").textContent = lastScan ? fmtDate(lastScan.finished_at) : "nunca";

    var sub = $("pages-sub");
    if (sub) {
      if (lastScan && lastScan.finished_at) {
        sub.textContent = "Última análise: " + fmtDate(lastScan.finished_at) +
          " · " + (Number(lastScan.pages_found) || 0) + " arquivos encontrados";
      } else {
        sub.textContent = "Este projeto ainda não foi analisado.";
      }
    }
  }

  function load() {
    return api("?project=" + PROJECT).then(function (data) {
      state.pages = data.pages || [];
      state.csrf = data.csrfToken || "";
      render();
      renderSummary(data.summary, data.lastScan);
    }).catch(function (e) {
      if (e.status === 401) { window.location.href = "/admin/"; return; }
      alertMsg(e.message, "error");
      var list = $("pages-list");
      if (list) list.innerHTML = '<p class="cms-empty">Não foi possível carregar.</p>';
    });
  }

  function scan() {
    var btn = $("btn-scan");
    if (!btn) return;
    btn.disabled = true;
    btn.textContent = "A analisar…";
    alertMsg("");

    api("?project=" + PROJECT + "&action=scan", { method: "POST" }).then(function (data) {
      state.pages = data.pages || [];
      render();
      renderSummary(data.summary, null);
      var s = data.scan || {};
      alertMsg(
        "Análise concluída: " + (s.found || 0) + " arquivos · " +
        (s.changed || 0) + " novos ou alterados · " + (s.removed || 0) + " removidos.",
        "ok"
      );
      return load();
    }).catch(function (e) {
      alertMsg(e.message, "error");
    }).then(function () {
      btn.disabled = false;
      btn.textContent = "Analisar projeto";
    });
  }

  function init() {
    var btn = $("btn-scan");
    if (btn) btn.addEventListener("click", scan);

    var pf = $("page-filter");
    if (pf) pf.addEventListener("input", function () {
      state.filter = pf.value.trim();
      render();
    });

    var kf = $("kind-filter");
    if (kf) kf.addEventListener("change", function () {
      state.kind = kf.value;
      render();
    });

    load();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
