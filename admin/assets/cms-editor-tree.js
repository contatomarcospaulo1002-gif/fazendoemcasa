/* Central CMS — editor: árvore de elementos. */
(function (global) {
  'use strict';

  var E = global.CmsEditor;
  var state = E.state;

  var LEVEL_LABEL = {
    editable: 'Editável',
    needs_adapter: 'Adaptador',
    read_only: 'Leitura',
    unconfigured: 'Não config.'
  };

  function renderTree() {
    var box = document.getElementById('tree-list');
    if (!box) { return; }

    var term = (document.getElementById('tree-filter') || {}).value || '';
    term = term.trim().toLowerCase();

    var rows = state.elements.filter(function (el) {
      if (!term) { return true; }
      var hay = (el.selector + ' ' + (el.type || '') + ' ' + (el.name || '')).toLowerCase();
      return hay.indexOf(term) !== -1;
    });

    if (!rows.length) {
      box.innerHTML = '<p class="cms-empty">Nenhum elemento.</p>';
      return;
    }

    var html = '';
    rows.forEach(function (el) {
      var id = Number(el.id);
      var level = el.editability || 'unconfigured';
      var writable = el.writable === true;
      var classes = ['cms-tree-item'];
      if (id === state.selectedId) { classes.push('is-selected'); }
      if (!writable) { classes.push('is-locked'); }
      if (E.hasDraft(el)) { classes.push('is-draft'); }

      html += '<button type="button" class="' + classes.join(' ') + '" data-id="' + id + '">'
        + '<span class="cms-tree-dot cms-dot-' + E.esc(level) + '"></span>'
        + '<span class="cms-tree-main">'
        + '<span class="cms-tree-selector">' + E.esc(el.selector) + '</span>'
        + '<span class="cms-tree-value">' + E.esc(E.currentValue(el) || '—') + '</span>'
        + '</span>'
        + '<span class="cms-tree-level">' + E.esc(LEVEL_LABEL[level] || level) + '</span>'
        + '</button>';
    });

    box.innerHTML = html;

    Array.prototype.forEach.call(box.querySelectorAll('.cms-tree-item'), function (btn) {
      btn.addEventListener('click', function () {
        state.selectedId = Number(btn.getAttribute('data-id'));
        renderTree();
        if (global.CmsEditorProps) { global.CmsEditorProps.render(); }
      });
    });
  }

  function selected() {
    for (var i = 0; i < state.elements.length; i++) {
      if (Number(state.elements[i].id) === state.selectedId) { return state.elements[i]; }
    }
    return null;
  }

  global.CmsEditorTree = {
    render: renderTree,
    selected: selected,
    LEVEL_LABEL: LEVEL_LABEL
  };
})(window);
