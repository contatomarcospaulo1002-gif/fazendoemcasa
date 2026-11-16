"use strict";

/**
 * Central de Projetos — front-end.
 * Todo dado vem da API /admin/api/cms-projects.php.
 * Nenhum caminho de arquivo é montado no cliente.
 */
(function () {
  var API = "/admin/api/cms-projects.php";
  var state = { projects: [], stats: {}, csrf: "", filter: "" };

  function $(id) { return document.getElementById(id); }

  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  function alertMsg(msg, kind) {
    var box = $("cms-alert");
    if (!box) return;
    if (!msg) { box.hidden = true; box.className = "cms-alert"; return; }
    box.hidden = false;
    box.className = "cms-alert is-" + (kind || "error");
    box.textContent = msg;
  }

  function api(path, options) {
    options = options || {};
    var opts = {
      method: options.method || "GET",
      credentials: "same-origin",
      headers: options.headers || {}
    };
    if (options.body !== undefined) {
      opts.headers["Content-Type"] = "application/json";
      opts.body = JSON.stringify(options.body);
    }
    if (opts.method !== "GET" && state.csrf) {
      opts.headers["X-CSRF-Token"] = state.csrf;
    }
    return fetch(API + path, opts).then(function (r) {
      return r.json().then(function (data) {
        if (!r.ok) {
          var err = new Error(data.error || "Erro " + r.status);
          err.status = r.status;
          err.data = data;
          throw err;
        }
        return data;
      });
    });
  }

  function initials(name) {
    var parts = String(name || "").trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return "?";
    if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
  }

  function fmtDate(iso) {
    if (!iso) return "—";
    var d = new Date(String(iso).replace(" ", "T") + (String(iso).indexOf("T") < 0 ? "Z" : ""));
    if (isNaN(d.getTime())) return "—";
    return d.toLocaleDateString("pt-PT", { day: "2-digit", month: "2-digit", year: "numeric" });
  }

  function cardHtml(p) {
    var active = p.status === "active";
    var scanned = p.last_scan_at ? "Mapeado" : "Não mapeado";
    var st = state.stats[Number(p.id)] || { pages: 0, elements: 0, images: 0 };
    return (
      '<article class="cms-project' + (active ? "" : " is-off") + '" data-id="' + p.id + '">' +
        '<div class="cms-project-head">' +
          '<div class="cms-project-logo">' + esc(initials(p.name)) + "</div>" +
          '<div class="cms-project-title">' +
            "<h3>" + esc(p.name) + "</h3>" +
            '<p class="cms-project-path">' + esc(p.rel_path) + "/</p>" +
          "</div>" +
          '<span class="cms-badge ' + (active ? "is-active" : "is-disabled") + '">' +
            '<span class="cms-badge-dot"></span>' + (active ? "Ativo" : "Desativado") +
          "</span>" +
        "</div>" +
        '<p class="cms-project-desc">' + esc(p.description || "Sem descrição.") + "</p>" +
        '<div class="cms-project-meta">' +
          "<div><span>Páginas</span><strong>" + st.pages + "</strong></div>" +
          "<div><span>Elementos</span><strong>" + st.elements + "</strong></div>" +
          "<div><span>Imagens</span><strong>" + st.images + "</strong></div>" +
          "<div><span>Mapeamento</span><strong>" + scanned + "</strong></div>" +
        "</div>" +
        '<div class="cms-project-actions">' +
          '<button type="button" class="cms-btn cms-btn-primary" data-act="manage">Gerir</button>' +
          (p.base_url ? '<a class="cms-btn cms-btn-ghost" href="' + esc(p.base_url) + '" target="_blank" rel="noopener">Ver site</a>' : "") +
          '<button type="button" class="cms-btn cms-btn-ghost" data-act="edit">Editar</button>' +
          '<button type="button" class="cms-btn cms-btn-ghost" data-act="toggle">' + (active ? "Desativar" : "Ativar") + "</button>" +
        "</div>" +
      "</article>"
    );
  }

  function render() {
    var grid = $("projects-grid");
    if (!grid) return;
    var list = state.projects.filter(function (p) {
      if (!state.filter) return true;
      var q = state.filter.toLowerCase();
      return String(p.name).toLowerCase().indexOf(q) >= 0 ||
             String(p.rel_path).toLowerCase().indexOf(q) >= 0;
    });

    if (!list.length) {
      grid.innerHTML = state.projects.length
        ? '<p class="cms-empty">Nenhum projeto corresponde ao filtro.</p>'
        : '<p class="cms-empty">Ainda não há projetos. Usa <strong>Descobrir projetos</strong> ou <strong>+ Adicionar projeto</strong>.</p>';
    } else {
      grid.innerHTML = list.map(cardHtml).join("");
    }
    renderStats();
  }

  function renderStats() {
    var total = state.projects.length;
    var active = state.projects.filter(function (p) { return p.status === "active"; }).length;
    var scanned = state.projects.filter(function (p) { return !!p.last_scan_at; }).length;
    if ($("stat-projects")) $("stat-projects").textContent = total;
    if ($("stat-active")) $("stat-active").textContent = active;
    if ($("stat-disabled")) $("stat-disabled").textContent = total - active;
    if ($("stat-scanned")) $("stat-scanned").textContent = scanned;
  }


  function load() {
    return api("").then(function (data) {
      state.projects = data.projects || [];
      state.stats = data.stats || {};
      state.csrf = data.csrfToken || "";
      render();
    }).catch(function (e) {
      if (e.status === 401) {
        window.location.href = "/admin/";
        return;
      }
      alertMsg(e.message, "error");
      var grid = $("projects-grid");
      if (grid) grid.innerHTML = '<p class="cms-empty">Não foi possível carregar os projetos.</p>';
    });
  }

  /* ---------- Modal ---------- */
  function openModal(project) {
    var modal = $("modal-project");
    if (!modal) return;
    $("modal-project-title").textContent = project ? "Editar projeto" : "Adicionar projeto";
    $("fp-id").value = project ? project.id : "";
    $("fp-name").value = project ? project.name : "";
    $("fp-path").value = project ? project.rel_path : "";
    $("fp-path").readOnly = !!project;
    $("fp-url").value = project ? (project.base_url || "") : "";
    $("fp-desc").value = project ? (project.description || "") : "";
    $("fp-error").hidden = true;
    modal.hidden = false;
    setTimeout(function () { $("fp-name").focus(); }, 30);
  }

  function closeModal() {
    var modal = $("modal-project");
    if (modal) modal.hidden = true;
  }

  function submitForm(ev) {
    ev.preventDefault();
    var id = $("fp-id").value;
    var payload = {
      name: $("fp-name").value.trim(),
      base_url: $("fp-url").value.trim(),
      description: $("fp-desc").value.trim()
    };
    if (!id) {
      payload.rel_path = $("fp-path").value.trim();
      payload.slug = payload.rel_path;
    }
    var btn = $("fp-submit");
    btn.disabled = true;
    var req = id
      ? api("?id=" + encodeURIComponent(id), { method: "POST", body: payload })
      : api("", { method: "POST", body: payload });

    req.then(function () {
      closeModal();
      alertMsg(id ? "Projeto atualizado." : "Projeto criado.", "ok");
      return load();
    }).catch(function (e) {
      var box = $("fp-error");
      box.textContent = e.message;
      box.hidden = false;
    }).then(function () {
      btn.disabled = false;
    });
  }

  /* ---------- Discovery ---------- */
  function runDiscover() {
    var panel = $("discover-panel");
    var list = $("discover-list");
    if (!panel || !list) return;
    panel.classList.remove("is-hidden");
    list.innerHTML = '<p class="cms-hint">A analisar a raiz autorizada…</p>';

    api("?discover=1").then(function (data) {
      var cands = data.candidates || [];
      if (!cands.length) {
        list.innerHTML = '<p class="cms-hint">Nenhuma pasta nova encontrada.</p>';
        return;
      }
      list.innerHTML = cands.map(function (c) {
        var tags = [];
        if (c.has_assets) tags.push('<span class="cms-badge is-scan">' + c.asset_count + " assets</span>");
        tags.push('<span class="cms-badge is-disabled">' + c.file_count + " arquivos</span>");
        if (c.already_registered) tags.push('<span class="cms-badge is-active">Já cadastrado</span>');
        else tags.push('<span class="cms-badge is-new">Novo</span>');

        return (
          '<div class="cms-discover-row">' +
            '<div class="cms-discover-info">' +
              "<strong>" + esc(c.name) + "</strong>" +
              "<small>" + esc(c.rel_path) + "/" + esc(c.entrypoint) + "</small>" +
              '<div class="cms-discover-tags">' + tags.join("") + "</div>" +
            "</div>" +
            (c.already_registered
              ? '<button type="button" class="cms-btn cms-btn-ghost cms-btn-sm" disabled>Cadastrado</button>'
              : '<button type="button" class="cms-btn cms-btn-primary cms-btn-sm" data-add="' + esc(c.rel_path) + '" data-name="' + esc(c.name) + '">Cadastrar</button>') +
          "</div>"
        );
      }).join("");
    }).catch(function (e) {
      list.innerHTML = '<p class="cms-hint">Erro: ' + esc(e.message) + "</p>";
    });
  }

  function addFromDiscovery(relPath, name) {
    api("", {
      method: "POST",
      body: { slug: relPath, name: name, rel_path: relPath, base_url: "" }
    }).then(function () {
      alertMsg("Projeto cadastrado: " + name, "ok");
      runDiscover();
      return load();
    }).catch(function (e) {
      alertMsg(e.message, "error");
    });
  }

  /* ---------- Ações do card ---------- */
  function onGridClick(ev) {
    var btn = ev.target.closest("[data-act]");
    if (!btn) return;
    var card = btn.closest(".cms-project");
    if (!card) return;
    var id = Number(card.getAttribute("data-id"));
    var project = state.projects.filter(function (p) { return Number(p.id) === id; })[0];
    if (!project) return;

    var act = btn.getAttribute("data-act");
    if (act === "edit") {
      openModal(project);
      return;
    }
    if (act === "manage") {
      window.location.href = "/admin/cms-project.php?id=" + encodeURIComponent(id);
      return;
    }
    if (act === "toggle") {
      var next = project.status === "active" ? "disabled" : "active";
      btn.disabled = true;
      api("?id=" + id, { method: "POST", body: { status: next } })
        .then(function () {
          alertMsg(next === "active" ? "Projeto ativado." : "Projeto desativado.", "ok");
          return load();
        })
        .catch(function (e) { alertMsg(e.message, "error"); btn.disabled = false; });
    }
  }

  /* ---------- Init ---------- */
  function init() {
    var openers = ["btn-open-add", "btn-open-add-2"];
    openers.forEach(function (id) {
      var el = $(id);
      if (el) el.addEventListener("click", function () { openModal(null); });
    });

    var form = $("form-project");
    if (form) form.addEventListener("submit", submitForm);

    document.querySelectorAll("[data-close]").forEach(function (el) {
      el.addEventListener("click", closeModal);
    });

    document.addEventListener("keydown", function (ev) {
      if (ev.key === "Escape") closeModal();
    });

    var disc = $("btn-discover");
    if (disc) disc.addEventListener("click", runDiscover);

    var closeDisc = $("btn-close-discover");
    if (closeDisc) closeDisc.addEventListener("click", function () {
      $("discover-panel").classList.add("is-hidden");
    });

    var list = $("discover-list");
    if (list) list.addEventListener("click", function (ev) {
      var b = ev.target.closest("[data-add]");
      if (!b) return;
      b.disabled = true;
      addFromDiscovery(b.getAttribute("data-add"), b.getAttribute("data-name"));
    });

    var grid = $("projects-grid");
    if (grid) grid.addEventListener("click", onGridClick);

    var filter = $("project-filter");
    if (filter) filter.addEventListener("input", function () {
      state.filter = filter.value.trim();
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
