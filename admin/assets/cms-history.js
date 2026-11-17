/* Central CMS — histórico, backups e auditoria (FASE 7). */
(function (global) {
  'use strict';

  var projectId = global.CMS_PROJECT_ID;
  var state = { csrfToken: '', data: null, tab: 'publications' };

  function esc(value) {
    return String(value === null || value === undefined ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function api(url, options) {
    return fetch(url, Object.assign({ credentials: 'same-origin' }, options || {}))
      .then(function (res) {
        return res.json().then(function (data) {
          if (!res.ok || !data.ok) {
            var err = new Error(data.error || 'Falha na requisição.');
            err.payload = data;
            throw err;
          }
          return data;
        });
      });
  }

  function baseUrl() {
    return '/admin/api/cms-history.php?project=' + encodeURIComponent(projectId);
  }

  function postAction(action, body) {
    return api(baseUrl() + '&action=' + encodeURIComponent(action), {
      method: 'POST',
      headers: { 'X-CSRF-Token': state.csrfToken, 'Content-Type': 'application/json' },
      body: JSON.stringify(body || {})
    });
  }

  function fmtDate(value) {
    if (!value) { return '—'; }
    return String(value).replace('T', ' ').slice(0, 16);
  }

  function fmtSize(bytes) {
    var n = parseInt(bytes, 10);
    if (!isFinite(n)) { return '—'; }
    if (n < 1024) { return n + ' B'; }
    if (n < 1024 * 1024) { return (n / 1024).toFixed(1) + ' KB'; }
    return (n / 1024 / 1024).toFixed(1) + ' MB';
  }

  function alertMsg(message, isError) {
    var box = document.getElementById('cms-alert');
    if (!box) { return; }
    box.textContent = message;
    box.classList.toggle('cms-alert-error', !!isError);
    box.hidden = false;
    global.setTimeout(function () { box.hidden = true; }, 6000);
  }

  function load() {
    return api(baseUrl()).then(function (data) {
      state.csrfToken = data.csrfToken || state.csrfToken;
      state.data = data;
      render();
    }).catch(function (err) {
      alertMsg(err.message, true);
    });
  }

  /* ---------- Tabs ---------- */

  function bindTabs() {
    Array.prototype.forEach.call(document.querySelectorAll('.cms-tab'), function (btn) {
      btn.addEventListener('click', function () {
        state.tab = btn.getAttribute('data-tab');
        Array.prototype.forEach.call(document.querySelectorAll('.cms-tab'), function (b) {
          b.classList.toggle('is-active', b === btn);
        });
        Array.prototype.forEach.call(document.querySelectorAll('.cms-tab-panel'), function (p) {
          p.hidden = p.id !== 'tab-' + state.tab;
        });
      });
    });
  }

  /* ---------- Render ---------- */

  function render() {
    renderPublications();
    renderBackups();
    renderAudit();
  }

  function renderPublications() {
    var body = document.getElementById('pub-body');
    if (!body) { return; }
    var rows = (state.data && state.data.publications) || [];
    if (!rows.length) {
      body.innerHTML = '<tr><td colspan="5" class="cms-empty">Nenhuma publicação registada.</td></tr>';
      return;
    }
    body.innerHTML = rows.map(function (row) {
      var status = esc(row.status);
      var cls = row.status === 'applied' ? 'cms-badge-ok' : (row.status === 'rolled_back' ? 'cms-badge-warn' : 'cms-badge-err');
      return '<tr>'
        + '<td>' + (row.id || '—') + '</td>'
        + '<td>Página #' + (row.page_id || '—') + '</td>'
        + '<td><span class="cms-badge ' + cls + '">' + status + '</span></td>'
        + '<td>' + (row.elements_changed || 0) + '</td>'
        + '<td>' + esc(fmtDate(row.created_at)) + '</td>'
        + '</tr>';
    }).join('');
  }

  function renderBackups() {
    var body = document.getElementById('bak-body');
    if (!body) { return; }
    var rows = (state.data && state.data.backups) || [];
    if (!rows.length) {
      body.innerHTML = '<tr><td colspan="6" class="cms-empty">Nenhum backup.</td></tr>';
      return;
    }
    body.innerHTML = rows.map(function (row) {
      return '<tr>'
        + '<td>' + (row.id || '—') + '</td>'
        + '<td class="cms-mono">' + esc(row.rel_path) + '</td>'
        + '<td>' + esc(row.reason || '—') + '</td>'
        + '<td>' + fmtSize(row.size_bytes) + '</td>'
        + '<td>' + esc(fmtDate(row.created_at)) + '</td>'
        + '<td><button type="button" class="cms-btn cms-btn-ghost cms-btn-sm" data-restore="' + row.id + '">Restaurar</button></td>'
        + '</tr>';
    }).join('');

    Array.prototype.forEach.call(body.querySelectorAll('[data-restore]'), function (btn) {
      btn.addEventListener('click', function () { confirmRestore(parseInt(btn.getAttribute('data-restore'), 10)); });
    });
  }

  function renderAudit() {
    var body = document.getElementById('aud-body');
    if (!body) { return; }
    var rows = (state.data && state.data.audit) || [];
    if (!rows.length) {
      body.innerHTML = '<tr><td colspan="6" class="cms-empty">Nenhum evento registado.</td></tr>';
      return;
    }
    body.innerHTML = rows.map(function (row) {
      var detail = '';
      try {
        if (row.detail_json) { detail = JSON.stringify(JSON.parse(row.detail_json)); }
      } catch (e) { detail = String(row.detail_json || ''); }
      return '<tr>'
        + '<td>' + (row.id || '—') + '</td>'
        + '<td>' + esc(row.user_name || 'sistema') + '</td>'
        + '<td class="cms-mono">' + esc(row.action) + '</td>'
        + '<td>' + esc(row.target_type) + (row.target_id ? ' #' + row.target_id : '') + '</td>'
        + '<td class="cms-mono cms-detail">' + esc(detail.length > 80 ? detail.slice(0, 80) + '…' : detail) + '</td>'
        + '<td>' + esc(fmtDate(row.created_at)) + '</td>'
        + '</tr>';
    }).join('');
  }

  /* ---------- Restauração ---------- */

  function confirmRestore(backupId) {
    if (!global.confirm('Restaurar este backup? O conteúdo atual do ficheiro será substituído (é feito backup antes).')) {
      return;
    }
    postAction('restore', { backupId: backupId })
      .then(function (data) {
        alertMsg('Backup restaurado: ' + (data.relPath || 'ficheiro') + '.');
        load();
      })
      .catch(function (err) {
        alertMsg(err.message, true);
        load();
      });
  }

  bindTabs();
  load();
})(window);
