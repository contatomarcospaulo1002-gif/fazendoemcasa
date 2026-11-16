/* Central CMS — conteúdo de uma página (FASE 3). */
(function () {
  'use strict';

  var projectId = window.CMS_PROJECT_ID;
  var pageId = window.CMS_PAGE_ID;
  var csrfToken = '';
  var allElements = [];

  var alertBox = document.getElementById('cms-alert');
  var listBox = document.getElementById('elements-list');
  var sub = document.getElementById('content-sub');
  var levelFilter = document.getElementById('level-filter');
  var textFilter = document.getElementById('el-filter');
  var btnClassify = document.getElementById('btn-classify');

  var LEVEL_LABEL = {
    editable: 'Editável',
    needs_adapter: 'Requer adaptador',
    read_only: 'Somente leitura',
    unconfigured: 'Não configurado'
  };

  function showAlert(message, kind) {
    if (!alertBox) { return; }
    alertBox.textContent = message;
    alertBox.className = 'cms-alert' + (kind ? ' cms-alert-' + kind : '');
    alertBox.hidden = false;
  }

  function hideAlert() {
    if (alertBox) { alertBox.hidden = true; }
  }

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
            throw new Error(data.error || 'Falha na requisição.');
          }
          return data;
        });
      });
  }

  function setStats(counts) {
    var map = {
      'l-editable': counts.editable || 0,
      'l-needs': counts.needs_adapter || 0,
      'l-readonly': counts.read_only || 0,
      'l-unconf': counts.unconfigured || 0
    };
    Object.keys(map).forEach(function (id) {
      var el = document.getElementById(id);
      if (el) { el.textContent = map[id]; }
    });
  }

  function render() {
    var level = levelFilter ? levelFilter.value : '';
    var term = textFilter ? textFilter.value.trim().toLowerCase() : '';

    var rows = allElements.filter(function (el) {
      if (level && el.editability !== level) { return false; }
      if (!term) { return true; }
      var hay = (el.selector + ' ' + (el.value_current || '') + ' ' + (el.name || '')).toLowerCase();
      return hay.indexOf(term) !== -1;
    });

    if (!rows.length) {
      listBox.innerHTML = '<p class="cms-empty">Nenhum elemento corresponde ao filtro.</p>';
      return;
    }

    var html = '<table class="cms-table"><thead><tr>'
      + '<th>Selector</th><th>Tipo</th><th>Valor atual</th>'
      + '<th>Adaptador</th><th>Nível</th><th>Motivo</th>'
      + '</tr></thead><tbody>';

    rows.forEach(function (el) {
      var levelKey = el.editability || 'unconfigured';
      html += '<tr>'
        + '<td><code>' + esc(el.selector) + '</code></td>'
        + '<td>' + esc(el.type) + '</td>'
        + '<td class="cms-cell-value">' + esc(el.value_current || '—') + '</td>'
        + '<td>' + esc(el.adapter) + '</td>'
        + '<td><span class="cms-badge cms-badge-' + esc(levelKey) + '">'
        + esc(LEVEL_LABEL[levelKey] || levelKey) + '</span></td>'
        + '<td class="cms-cell-reason">' + esc(el.editability_reason || '') + '</td>'
        + '</tr>';
    });

    html += '</tbody></table>';
    listBox.innerHTML = html;
  }

  function load() {
    hideAlert();
    api('/admin/api/cms-elements.php?project=' + encodeURIComponent(projectId)
      + '&page=' + encodeURIComponent(pageId))
      .then(function (data) {
        allElements = data.elements || [];
        csrfToken = data.csrfToken || csrfToken;

        var counts = { editable: 0, needs_adapter: 0, read_only: 0, unconfigured: 0 };
        allElements.forEach(function (el) {
          if (counts[el.editability] !== undefined) { counts[el.editability]++; }
        });
        setStats(counts);

        if (sub) {
          sub.textContent = allElements.length + ' elemento(s) nesta página.';
        }
        render();
      })
      .catch(function (err) {
        listBox.innerHTML = '<p class="cms-empty">Não foi possível carregar.</p>';
        showAlert(err.message, 'error');
      });
  }

  function classify() {
    if (!csrfToken) {
      showAlert('Token de segurança ausente. Recarregue a página.', 'error');
      return;
    }
    btnClassify.disabled = true;
    btnClassify.textContent = 'Classificando…';

    api('/admin/api/cms-elements.php?project=' + encodeURIComponent(projectId)
      + '&action=classify', {
      method: 'POST',
      headers: { 'X-CSRF-Token': csrfToken }
    })
      .then(function (data) {
        csrfToken = data.csrfToken || csrfToken;
        var r = data.result || {};
        showAlert(
          'Projeto classificado: ' + (r.elements || 0) + ' elemento(s) em ' + (r.pages || 0) + ' página(s) — '
          + (r.inserted || 0) + ' novo(s), ' + (r.updated || 0) + ' atualizado(s), '
          + (r.pruned || 0) + ' removido(s). Esta página tem agora ' + allElements.length + '.',
          'success'
        );
        load();
      })
      .catch(function (err) {
        showAlert(err.message, 'error');
      })
      .finally(function () {
        btnClassify.disabled = false;
        btnClassify.textContent = 'Classificar conteúdo';
      });
  }

  if (levelFilter) { levelFilter.addEventListener('change', render); }
  if (textFilter) { textFilter.addEventListener('input', render); }
  if (btnClassify) { btnClassify.addEventListener('click', classify); }

  load();
})();
