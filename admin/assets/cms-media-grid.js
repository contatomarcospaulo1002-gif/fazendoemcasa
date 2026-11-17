/* Central CMS — biblioteca de mídia: grade e detalhe. */
(function (global) {
  'use strict';

  var M = global.CmsMedia;
  var state = M.state;
  var alertBox = null;

  function showAlert(message, kind) {
    if (!alertBox) { return; }
    alertBox.textContent = message;
    alertBox.className = 'cms-alert' + (kind ? ' cms-alert-' + kind : '');
    alertBox.hidden = false;
  }

  function visible() {
    var term = state.filter.trim().toLowerCase();
    return state.media.filter(function (m) {
      if (!term) { return true; }
      return (m.original_name + ' ' + m.rel_path + ' ' + (m.category || '')).toLowerCase().indexOf(term) !== -1;
    });
  }

  function renderGrid() {
    var grid = document.getElementById('media-grid');
    var count = document.getElementById('media-count');
    if (!grid) { return; }

    var rows = visible();
    if (count) {
      count.textContent = rows.length + (rows.length === 1 ? ' ficheiro' : ' ficheiros');
    }

    if (!rows.length) {
      grid.innerHTML = '<p class="cms-empty">Nenhuma mídia'
        + (state.onlyUnused ? ' não utilizada' : '') + '.</p>';
      return;
    }

    var html = '';
    rows.forEach(function (m) {
      var uses = Number(m.usage_count) || 0;
      html += '<article class="cms-media-card" data-id="' + Number(m.id) + '">'
        + '<div class="cms-media-thumb">'
        + '<img src="' + M.esc(m.url || ('/' + m.rel_path)) + '" alt="' + M.esc(m.alt_text || m.original_name) + '" loading="lazy">'
        + '</div>'
        + '<div class="cms-media-meta">'
        + '<p class="cms-media-name" title="' + M.esc(m.original_name) + '">' + M.esc(m.original_name) + '</p>'
        + '<p class="cms-media-sub">' + M.esc(m.mime) + ' · ' + M.formatSize(m.size_bytes)
        + (m.width ? ' · ' + m.width + '×' + m.height : '') + '</p>'
        + '<p class="cms-media-uses ' + (uses === 0 ? 'is-unused' : '') + '">'
        + (uses === 0 ? 'Não utilizada' : uses + (uses === 1 ? ' uso' : ' usos'))
        + '</p>'
        + '</div>'
        + '</article>';
    });

    grid.innerHTML = html;

    Array.prototype.forEach.call(grid.querySelectorAll('.cms-media-card'), function (card) {
      card.addEventListener('click', function () {
        openDetail(Number(card.getAttribute('data-id')));
      });
    });
  }

  function openDetail(mediaId) {
    var modal = document.getElementById('media-modal');
    var body = document.getElementById('modal-body');
    var title = document.getElementById('modal-title');
    if (!modal || !body) { return; }

    body.innerHTML = '<p class="cms-empty">A carregar…</p>';
    modal.hidden = false;

    M.api(M.baseUrl() + '&media=' + encodeURIComponent(mediaId)).then(function (data) {
      var m = data.media;
      if (data.csrfToken) { state.csrfToken = data.csrfToken; }
      if (title) { title.textContent = m.original_name; }

      var refs = data.references || [];
      var html = '<div class="cms-media-detail">'
        + '<img class="cms-media-detail-img" src="' + M.esc(m.url || ('/' + m.rel_path)) + '" alt="' + M.esc(m.original_name) + '">'
        + '<dl class="cms-media-facts">'
        + '<dt>Caminho</dt><dd><code>' + M.esc(m.rel_path) + '</code></dd>'
        + '<dt>Tipo</dt><dd>' + M.esc(m.mime) + '</dd>'
        + '<dt>Tamanho</dt><dd>' + M.formatSize(m.size_bytes) + '</dd>'
        + (m.width ? '<dt>Dimensões</dt><dd>' + m.width + ' × ' + m.height + '</dd>' : '')
        + '<dt>Categoria</dt><dd>' + M.esc(m.category) + '</dd>'
        + '</dl>';

      if (refs.length) {
        html += '<h3 class="cms-media-h3">Usada em ' + refs.length + ' local(is)</h3>'
          + '<ul class="cms-ref-list">';
        refs.forEach(function (r) {
          html += '<li><code>' + M.esc(r.page_path) + '</code> — elemento ' + r.element_id + '</li>';
        });
        html += '</ul>';
        html += '<p class="cms-field-hint">Substituir afeta todos estes locais. '
          + 'A alteração fica em rascunho até publicar.</p>';
      } else {
        html += '<p class="cms-media-unused-note">Não é usada em nenhuma página.</p>';
        html += '<button type="button" class="cms-btn cms-btn-danger cms-btn-sm" id="btn-delete">'
          + 'Remover da biblioteca</button>';
      }

      html += '</div>';
      body.innerHTML = html;

      var del = document.getElementById('btn-delete');
      if (del) {
        del.addEventListener('click', function () {
          if (!global.confirm('Remover esta mídia da biblioteca?')) { return; }
          M.postAction('delete', { mediaId: mediaId }).then(function () {
            closeModal();
            showAlert('Mídia removida.', 'success');
            refresh();
          }).catch(function (err) {
            showAlert(err.message, 'error');
          });
        });
      }
    }).catch(function (err) {
      body.innerHTML = '<p class="cms-empty">' + M.esc(err.message) + '</p>';
    });
  }

  function closeModal() {
    var modal = document.getElementById('media-modal');
    if (modal) { modal.hidden = true; }
  }

  function refresh() {
    return M.load().then(renderGrid).catch(function (err) {
      showAlert(err.message, 'error');
    });
  }

  function boot() {
    alertBox = document.getElementById('cms-alert');

    var filter = document.getElementById('media-filter');
    if (filter) {
      filter.addEventListener('input', function () {
        state.filter = filter.value;
        renderGrid();
      });
    }

    var unused = document.getElementById('only-unused');
    if (unused) {
      unused.addEventListener('change', function () {
        state.onlyUnused = unused.checked;
        refresh();
      });
    }

    var fileInput = document.getElementById('file-input');
    if (fileInput) {
      fileInput.addEventListener('change', function () {
        var files = fileInput.files;
        showAlert('A enviar…', '');
        M.upload(files).then(function (res) {
          fileInput.value = '';
          if (res.errors && res.errors.length) {
            showAlert(res.uploaded + ' enviado(s). Falhas: ' + res.errors.join('; '), 'error');
          } else {
            showAlert(res.uploaded + ' ficheiro(s) enviado(s).', 'success');
          }
          refresh();
        });
      });
    }

    var reindex = document.getElementById('btn-reindex');
    if (reindex) {
      reindex.addEventListener('click', function () {
        M.postAction('reindex', {}).then(function (data) {
          showAlert('Índice reconstruído: ' + data.references + ' referência(s).', 'success');
          refresh();
        }).catch(function (err) {
          showAlert(err.message, 'error');
        });
      });
    }

    var close = document.getElementById('modal-close');
    if (close) { close.addEventListener('click', closeModal); }

    var modal = document.getElementById('media-modal');
    if (modal) {
      modal.addEventListener('click', function (e) {
        if (e.target === modal) { closeModal(); }
      });
    }

    refresh();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})(window);
