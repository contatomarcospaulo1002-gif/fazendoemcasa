/* Central CMS — editor: painel de propriedades. */
(function (global) {
  'use strict';

  var E = global.CmsEditor;
  var state = E.state;
  var panel = null;

  function editorFor(el, value) {
    var type = String(el.type || 'paragraph');

    if (type === 'image' || type === 'logo' || type === 'banner' || type === 'background') {
      return '<label class="cms-field-label" for="prop-input">Caminho da imagem</label>'
        + '<input type="text" id="prop-input" class="cms-input" value="' + E.esc(value)
        + '" placeholder="/img/arquivo.webp" autocomplete="off">'
        + '<p class="cms-field-hint">Caminho relativo ao site ou URL http(s).</p>';
    }

    if (type === 'link') {
      return '<label class="cms-field-label" for="prop-input">Destino do link</label>'
        + '<input type="text" id="prop-input" class="cms-input" value="' + E.esc(value)
        + '" placeholder="/pagina ou https://…" autocomplete="off">';
    }

    var length = String(value).length;
    return '<label class="cms-field-label" for="prop-input">Texto</label>'
      + '<textarea id="prop-input" class="cms-input cms-textarea" rows="6" '
      + 'maxlength="20000">' + E.esc(value) + '</textarea>'
      + '<p class="cms-field-hint"><span id="prop-count">' + length + '</span> caracteres</p>';
  }

  function render() {
    panel = document.getElementById('props-panel');
    if (!panel) { return; }

    var el = global.CmsEditorTree.selected();
    if (!el) {
      panel.innerHTML = '<p class="cms-empty">Selecione um elemento na lista.</p>';
      return;
    }

    var writable = el.writable === true;
    var value = E.currentValue(el);

    var html = '<div class="cms-prop-head">'
      + '<span class="cms-prop-type">' + E.esc(el.type) + '</span>'
      + '<span class="cms-badge cms-badge-' + E.esc(el.editability) + '">'
      + E.esc(el.editability_label || el.editability) + '</span>'
      + '</div>'
      + '<p class="cms-prop-selector"><code>' + E.esc(el.selector) + '</code></p>';

    if (!writable) {
      html += '<div class="cms-alert cms-alert-error cms-prop-block">'
        + E.esc(el.editability_reason || 'Este elemento não pode ser editado.')
        + '</div>';
      html += '<p class="cms-prop-readonly-value">' + E.esc(value || '—') + '</p>';
      panel.innerHTML = html;
      return;
    }

    html += editorFor(el, value);

    if (E.hasDraft(el)) {
      html += '<button type="button" class="cms-btn cms-btn-ghost cms-btn-xs" id="btn-revert">'
        + 'Reverter este campo</button>';
    }

    panel.innerHTML = html;

    var input = document.getElementById('prop-input');
    if (input) {
      input.addEventListener('input', function () {
        var id = String(el.id);
        if (input.value === (el.value_current || '')) {
          delete state.dirty[id];
        } else {
          state.dirty[id] = input.value;
        }
        var counter = document.getElementById('prop-count');
        if (counter) {
          counter.textContent = String(input.value.length);
        }
        if (global.CmsEditorShell) { global.CmsEditorShell.refreshDirty(); }
      });
    }

    var revert = document.getElementById('btn-revert');
    if (revert) {
      revert.addEventListener('click', function () {
        global.CmsEditorTree.render();
        if (global.CmsEditorShell) { global.CmsEditorShell.revert(el.id); }
      });
    }
  }

  global.CmsEditorProps = { render: render };
})(window);
