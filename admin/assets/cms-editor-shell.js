/* Central CMS — editor: shell, preview e ações. */
(function (global) {
  'use strict';

  var E = global.CmsEditor;
  var state = E.state;

  var alertBox = null;
  var badge = null;
  var btnSave = null;
  var btnDiscard = null;
  var btnPublish = null;
  var frame = null;
  var note = null;
  var device = 'desktop';

  var DEVICE_WIDTH = { desktop: '100%', tablet: '820px', mobile: '390px' };

  function showAlert(message, kind) {
    if (!alertBox) { return; }
    alertBox.textContent = message;
    alertBox.className = 'cms-alert cms-editor-alert' + (kind ? ' cms-alert-' + kind : '');
    alertBox.hidden = false;
  }

  function hideAlert() {
    if (alertBox) { alertBox.hidden = true; }
  }

  function refreshDirty() {
    var n = E.dirtyCount();
    if (badge) {
      badge.textContent = n + (n === 1 ? ' alteração' : ' alterações');
      badge.hidden = n === 0;
    }
    if (btnSave) { btnSave.disabled = n === 0; }
    if (btnDiscard) { btnDiscard.disabled = n === 0 && Object.keys(state.patch).length === 0; }
    if (btnPublish) { btnPublish.disabled = Object.keys(state.patch).length === 0; }
  }

  function publish() {
    var pending = Object.keys(state.patch).length;
    if (pending === 0) {
      showAlert('Nenhuma alteração pendente para publicar.', 'error');
      return;
    }
    if (!global.confirm(
      'Publicar ' + pending + ' alteração(ões) no site? '
      + 'O ficheiro atual será copiado para backup antes de escrever.'
    )) {
      return;
    }

    btnPublish.disabled = true;
    btnPublish.textContent = 'A publicar…';

    fetch('/admin/api/cms-publish.php?project=' + encodeURIComponent(E.projectId)
      + '&page=' + encodeURIComponent(E.pageId) + '&action=publish', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-CSRF-Token': state.csrfToken, 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'publish', confirm: true })
    }).then(function (res) {
      return res.json().then(function (data) {
        if (!res.ok || !data.ok) {
          var err = new Error(data.error || 'Falha na publicação.');
          err.payload = data;
          throw err;
        }
        return data;
      });
    }).then(function (data) {
      state.csrfToken = data.csrfToken || state.csrfToken;
      state.patch = {};
      state.dirty = {};
      showAlert('Publicado: ' + data.changed + ' alteração(ões).', 'success');
      global.CmsEditorTree.render();
      global.CmsEditorProps.render();
      refreshDirty();
      renderPreview();
    }).catch(function (err) {
      var detail = err.payload && err.payload.restored
        ? ' O backup foi restaurado — nenhuma alteração ficou no disco.'
        : '';
      showAlert(err.message + detail, 'error');
    }).finally(function () {
      btnPublish.textContent = 'Publicar';
      refreshDirty();
    });
  }

  function applyDevice() {
    var wrap = document.getElementById('preview-wrap');
    if (!wrap) { return; }
    wrap.style.maxWidth = DEVICE_WIDTH[device] || '100%';
    Array.prototype.forEach.call(document.querySelectorAll('.cms-device'), function (b) {
      b.classList.toggle('is-active', b.getAttribute('data-device') === device);
    });
  }

  function renderPreview() {
    if (!frame) { return; }
    E.loadPreview().then(function (data) {
      frame.srcdoc = data.html || '';
      if (note) {
        note.textContent = data.applied > 0
          ? 'Pré-visualização com ' + data.applied + ' alteração(ões) em memória — o ficheiro não foi tocado.'
          : 'Pré-visualização do ficheiro original — nenhuma alteração pendente.';
      }
    }).catch(function (err) {
      if (note) { note.textContent = err.message; }
    });
  }

  function save() {
    var patch = {};
    Object.keys(state.dirty).forEach(function (id) {
      patch[id] = state.dirty[id];
    });

    if (Object.keys(patch).length === 0) {
      showAlert('Nenhuma alteração para guardar.', 'error');
      return;
    }

    btnSave.disabled = true;
    btnSave.textContent = 'A guardar…';

    E.postAction('save', { patch: patch }).then(function (data) {
      state.csrfToken = data.csrfToken || state.csrfToken;
      state.patch = data.patch || {};
      state.dirty = {};
      showAlert('Rascunho guardado: ' + (data.changed || 0) + ' campo(s) pendente(s).', 'success');
      global.CmsEditorTree.render();
      global.CmsEditorProps.render();
      refreshDirty();
      renderPreview();
    }).catch(function (err) {
      var detail = err.payload && err.payload.errors ? ' ' + err.payload.errors.join(' ') : '';
      showAlert(err.message + detail, 'error');
    }).finally(function () {
      btnSave.textContent = 'Guardar rascunho';
      refreshDirty();
    });
  }

  function discardAll() {
    if (!global.confirm('Descartar todas as alterações pendentes desta página?')) { return; }
    E.postAction('discard', {}).then(function (data) {
      state.csrfToken = data.csrfToken || state.csrfToken;
      state.patch = {};
      state.dirty = {};
      showAlert('Rascunho descartado.', 'success');
      global.CmsEditorTree.render();
      global.CmsEditorProps.render();
      refreshDirty();
      renderPreview();
    }).catch(function (err) {
      showAlert(err.message, 'error');
    });
  }

  function revert(elementId) {
    E.postAction('discard-element', { elementId: elementId }).then(function (data) {
      state.csrfToken = data.csrfToken || state.csrfToken;
      state.patch = data.patch || {};
      delete state.dirty[String(elementId)];
      showAlert('Campo revertido.', 'success');
      global.CmsEditorTree.render();
      global.CmsEditorProps.render();
      refreshDirty();
      renderPreview();
    }).catch(function (err) {
      showAlert(err.message, 'error');
    });
  }

  function boot() {
    alertBox = document.getElementById('cms-alert');
    badge = document.getElementById('draft-badge');
    btnSave = document.getElementById('btn-save');
    btnDiscard = document.getElementById('btn-discard');
    btnPublish = document.getElementById('btn-publish');
    frame = document.getElementById('preview-frame');
    note = document.getElementById('preview-note');

    if (btnSave) { btnSave.addEventListener('click', save); }
    if (btnDiscard) { btnDiscard.addEventListener('click', discardAll); }
    if (btnPublish) { btnPublish.addEventListener('click', publish); }

    var filter = document.getElementById('tree-filter');
    if (filter) { filter.addEventListener('input', global.CmsEditorTree.render); }

    Array.prototype.forEach.call(document.querySelectorAll('.cms-device'), function (b) {
      b.addEventListener('click', function () {
        device = b.getAttribute('data-device') || 'desktop';
        applyDevice();
      });
    });

    E.load().then(function () {
      global.CmsEditorTree.render();
      global.CmsEditorProps.render();
      refreshDirty();
      applyDevice();
      renderPreview();
    }).catch(function (err) {
      showAlert(err.message, 'error');
    });
  }

  global.CmsEditorShell = {
    refreshDirty: refreshDirty,
    revert: revert,
    renderPreview: renderPreview
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})(window);
