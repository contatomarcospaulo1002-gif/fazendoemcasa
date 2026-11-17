/* Central CMS — biblioteca de mídia: núcleo. */
(function (global) {
  'use strict';

  var projectId = global.CMS_PROJECT_ID;

  var state = {
    csrfToken: '',
    media: [],
    filter: '',
    onlyUnused: false
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
    return '/admin/api/cms-media.php?project=' + encodeURIComponent(projectId);
  }

  function postAction(action, body) {
    return api(baseUrl() + '&action=' + encodeURIComponent(action), {
      method: 'POST',
      headers: { 'X-CSRF-Token': state.csrfToken, 'Content-Type': 'application/json' },
      body: JSON.stringify(body || {})
    });
  }

  function load() {
    var url = baseUrl() + (state.onlyUnused ? '&unused=1' : '');
    return api(url).then(function (data) {
      state.csrfToken = data.csrfToken || state.csrfToken;
      state.media = data.media || [];
      return data;
    });
  }

  function upload(files) {
    var list = Array.prototype.slice.call(files || []);
    if (!list.length) { return Promise.resolve({ uploaded: 0 }); }

    var done = 0;
    var errors = [];

    function next() {
      if (!list.length) {
        return Promise.resolve({ uploaded: done, errors: errors });
      }
      var file = list.shift();
      var form = new FormData();
      form.append('file', file);

      return fetch(baseUrl() + '&action=upload', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-CSRF-Token': state.csrfToken },
        body: form
      }).then(function (res) {
        return res.json().then(function (data) {
          if (!res.ok || !data.ok) {
            errors.push(file.name + ': ' + (data.error || 'falhou'));
          } else {
            done++;
            if (data.csrfToken) { state.csrfToken = data.csrfToken; }
          }
          return next();
        });
      }).catch(function (err) {
        errors.push(file.name + ': ' + err.message);
        return next();
      });
    }

    return next();
  }

  function formatSize(bytes) {
    var n = Number(bytes) || 0;
    if (n < 1024) { return n + ' B'; }
    if (n < 1048576) { return (n / 1024).toFixed(0) + ' KB'; }
    return (n / 1048576).toFixed(1) + ' MB';
  }

  global.CmsMedia = {
    projectId: projectId,
    state: state,
    esc: esc,
    api: api,
    baseUrl: baseUrl,
    postAction: postAction,
    load: load,
    upload: upload,
    formatSize: formatSize
  };
})(window);
