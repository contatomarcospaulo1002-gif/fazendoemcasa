/* Central CMS — editor visual (FASE 4), núcleo de estado e API. */
(function (global) {
  'use strict';

  var projectId = global.CMS_PROJECT_ID;
  var pageId = global.CMS_PAGE_ID;

  var state = {
    csrfToken: '',
    elements: [],
    patch: {},
    drafts: {},
    selectedId: 0,
    dirty: {}
  };

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
    return '/admin/api/cms-editor.php?project=' + encodeURIComponent(projectId)
      + '&page=' + encodeURIComponent(pageId);
  }

  function postAction(action, body) {
    return api(baseUrl() + '&action=' + encodeURIComponent(action), {
      method: 'POST',
      headers: { 'X-CSRF-Token': state.csrfToken, 'Content-Type': 'application/json' },
      body: JSON.stringify(body || {})
    });
  }

  function load() {
    return api(baseUrl()).then(function (data) {
      state.csrfToken = data.csrfToken || state.csrfToken;
      state.elements = data.elements || [];
      state.patch = data.patch || {};
      state.dirty = {};
      return data;
    });
  }

  function loadPreview() {
    return api(baseUrl() + '&preview=1').then(function (data) {
      return data;
    });
  }

  function dirtyCount() {
    return Object.keys(state.dirty).length;
  }

  function currentValue(el) {
    var id = String(el.id);
    if (Object.prototype.hasOwnProperty.call(state.dirty, id)) {
      return state.dirty[id];
    }
    if (Object.prototype.hasOwnProperty.call(state.patch, id)) {
      return state.patch[id];
    }
    return el.value_current || '';
  }

  function hasDraft(el) {
    return Object.prototype.hasOwnProperty.call(state.patch, String(el.id));
  }

  global.CmsEditor = {
    projectId: projectId,
    pageId: pageId,
    state: state,
    esc: esc,
    api: api,
    baseUrl: baseUrl,
    postAction: postAction,
    load: load,
    loadPreview: loadPreview,
    dirtyCount: dirtyCount,
    currentValue: currentValue,
    hasDraft: hasDraft
  };
})(window);
