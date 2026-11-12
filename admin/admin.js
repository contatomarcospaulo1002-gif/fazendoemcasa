(function () {
  "use strict";

  var state = {
    map: null,
    content: {},
    activeTab: "editar",
    activeGroupId: null,
    activeFieldId: null,
    php: false,
    database: false,
    authenticated: false,
    accounts: false,
    authMode: "none",
    profile: null,
    appBootstrapped: false,
    dirty: false,
    indexDoc: null,
    menuFilter: "all",
    openGroups: {},
  };

  var els = {};
  var SITE_PUBLIC = "https://fazendoemcasa.com";
  var STATIC_STORAGE_KEY = "fazendoemcasa-admin-draft";
  var STATIC_PENDING_KEY = "fazendoemcasa-admin-pending";
  var IMAGE_UPLOAD_MAX_BYTES = 8 * 1024 * 1024;
  var previewObjectUrl = null;

  function $(id) {
    return document.getElementById(id);
  }

  function confirmDialog(options) {
    options = options || {};
    return new Promise(function (resolve) {
      var root = $("adm-modal");
      var titleEl = $("adm-modal-title");
      var msgEl = $("adm-modal-msg");
      var btnOk = $("adm-modal-ok");
      var btnCancel = $("adm-modal-cancel");
      var backdrop = root && root.querySelector(".adm-modal-backdrop");
      if (!root || !btnOk || !btnCancel) {
        resolve(false);
        return;
      }
      if (titleEl) titleEl.textContent = options.title || "Confirmar";
      if (msgEl) msgEl.textContent = options.message || "";
      btnOk.textContent = options.confirmLabel || "Confirmar";
      btnCancel.textContent = options.cancelLabel || "Cancelar";
      btnOk.className = "btn " + (options.danger ? "danger" : "primary");

      function close(result) {
        root.classList.add("hidden");
        btnOk.onclick = null;
        btnCancel.onclick = null;
        if (backdrop) backdrop.onclick = null;
        document.removeEventListener("keydown", onKey);
        resolve(result);
      }
      function onKey(ev) {
        if (ev.key === "Escape") close(false);
      }
      btnOk.onclick = function () {
        close(true);
      };
      btnCancel.onclick = function () {
        close(false);
      };
      if (backdrop) backdrop.onclick = function () {
        close(false);
      };
      document.addEventListener("keydown", onKey);
      root.classList.remove("hidden");
      btnCancel.focus();
    });
  }

  function imageSrcFromValue(val) {
    if (!val) return "";
    if (typeof val === "object") return (val.src || "").trim();
    return String(val).trim();
  }

  function assetPathOnly(src) {
    return String(src || "").split("?")[0];
  }

  function cacheBustAsset(src) {
    var base = String(src || "");
    if (!base || /^(blob:|data:)/i.test(base)) return base;
    return base + (base.indexOf("?") >= 0 ? "&" : "?") + "t=" + Date.now();
  }

  function revokePreviewObjectUrl() {
    if (previewObjectUrl) {
      URL.revokeObjectURL(previewObjectUrl);
      previewObjectUrl = null;
    }
  }

  function probeAssetUrl(url) {
    return new Promise(function (resolve) {
      var img = new Image();
      var timer = setTimeout(function () {
        resolve(false);
      }, 15000);
      img.onload = function () {
        clearTimeout(timer);
        resolve(true);
      };
      img.onerror = function () {
        clearTimeout(timer);
        resolve(false);
      };
      img.src = url + (url.indexOf("?") >= 0 ? "&" : "?") + "t=" + Date.now();
    });
  }

  function mediaPreviewSection(src) {
    var has = !!src;
    var html = '<div class="media-preview-box">';
    html += '<p class="media-preview-title">Pré-visualização</p>';
    html += '<div class="media-preview-frame">';
    html +=
      '<img class="img-preview' +
      (has ? "" : " hidden") +
      '" id="img-prev" src="' +
      (has ? escapeAttr(cacheBustAsset("../" + src)) : "") +
      '" alt="">';
    html +=
      '<p class="media-preview-empty' +
      (has ? " hidden" : "") +
      '" id="img-prev-empty">Sem fotografia — escolhe um ficheiro abaixo.</p>';
    html += "</div>";
    html +=
      '<p class="media-preview-caption hidden" id="img-prev-caption"></p>';
    html +=
      '<p class="media-preview-tip">Mostra o <strong>teu ficheiro</strong> escolhido — o painel <strong>não faz captura de ecrã</strong>. Prefere foto só da pessoa (sem texto, logótipo ou moldura do site).</p>';
    html += '<div class="img-live-status" id="img-live-status" role="status" aria-live="polite">';
    html += '<p class="live-row live-pending"><span class="live-dot" aria-hidden="true"></span> A verificar…</p>';
    html += "</div>";
    if (has) {
      html +=
        '<p class="media-preview-links"><a href="' +
        escapeAttr(cacheBustAsset("../" + src)) +
        '" target="_blank" rel="noopener">Abrir ficheiro local</a></p>';
    }
    html += "</div>";
    return html;
  }

  function refreshImageLiveStatus(field) {
    if (!field) return;
    var box = $("img-live-status");
    if (!box) return;
    var src = imageSrcFromValue(state.content[field.id]);
    if (!src) {
      if (hasBlobPreview()) {
        box.innerHTML =
          '<p class="live-row live-warn"><span class="live-dot" aria-hidden="true"></span> Pré-visualização local — clica <strong>Salvar foto</strong>.</p>';
      } else {
        box.innerHTML =
          '<p class="live-row live-none"><span class="live-dot" aria-hidden="true"></span> Ainda não há foto neste campo.</p>';
      }
      updateRemovePhotoButton();
      return;
    }
    box.innerHTML =
      '<p class="live-row live-pending"><span class="live-dot" aria-hidden="true"></span> A verificar publicação…</p>';

    var pathOnly = assetPathOnly(src);
    Promise.all([
      loadIndexDoc(),
      probeAssetUrl("../" + pathOnly),
      probeAssetUrl(SITE_PUBLIC + "/" + pathOnly.replace(/^\//, "")),
    ]).then(function (parts) {
      if (!box.parentNode || state.activeFieldId !== field.id) return;
      var doc = parts[0];
      var fileLocal = parts[1];
      var fileOnline = parts[2];
      var indexSrcFull = imageSrcFromValue(readField(doc, field));
      var indexSrc = assetPathOnly(indexSrcFull);
      var onIndex = indexSrcFull === src && src !== "";
      var applyLabel =
        state.php && state.database
          ? "Publicar no site"
          : state.php
          ? "Atualizar index.html"
          : "Guardar e aplicar";
      var rows = "";

      if (fileLocal) {
        rows +=
          '<p class="live-row live-ok"><span class="live-dot" aria-hidden="true"></span> Ficheiro no projeto <code>' +
          escapeHtml(pathOnly) +
          "</code></p>";
      } else {
        rows +=
          '<p class="live-row live-warn"><span class="live-dot" aria-hidden="true"></span> Ficheiro não encontrado em <code>' +
          escapeHtml(pathOnly) +
          "</code> (upload no Laragon?)</p>";
      }

      if (onIndex) {
        rows +=
          '<p class="live-row live-ok"><span class="live-dot" aria-hidden="true"></span> Está no <strong>index.html</strong> (site local / pré-visualização).</p>';
      } else if (indexSrc) {
        rows +=
          '<p class="live-row live-warn"><span class="live-dot" aria-hidden="true"></span> O index ainda tem a imagem anterior — clica <strong>' +
          applyLabel +
          "</strong>.</p>";
      } else {
        rows +=
          '<p class="live-row live-warn"><span class="live-dot" aria-hidden="true"></span> Ainda não aplicaste ao index — <strong>' +
          applyLabel +
          "</strong>.</p>";
      }

      if (!state.php && fieldHasPendingImage(field.id)) {
        rows =
          '<p class="live-row live-warn"><span class="live-dot" aria-hidden="true"></span> <strong>Nova foto</strong> só neste browser — falta publicar no Git para substituir no site.</p>' +
          rows;
      }

      if (fileOnline) {
        rows +=
          '<p class="live-row live-ok"><span class="live-dot" aria-hidden="true"></span> <strong>No ar agora</strong> em <a href="' +
          escapeAttr(SITE_PUBLIC + "/" + pathOnly.replace(/^\//, "")) +
          '" target="_blank" rel="noopener">fazendoemcasa.com</a> (pode ser a versão anterior)</p>';
      } else if (fileLocal && onIndex) {
        rows +=
          '<p class="live-row live-warn"><span class="live-dot" aria-hidden="true"></span> Ainda não está no site público — faz <strong>git push</strong> depois de aplicar.</p>';
      } else {
        rows +=
          '<p class="live-row live-warn"><span class="live-dot" aria-hidden="true"></span> Não está em fazendoemcasa.com (normal até aplicares e publicares).</p>';
      }

      box.innerHTML = rows;
      updateRemovePhotoButton();
    });
  }

  function updateRemovePhotoButton() {
    var btn = $("btn-field-remove-photo");
    if (!btn) return;
    var field = state.activeFieldId ? fieldById(state.activeFieldId) : null;
    if (!field || !isImageField(field)) return;
    var canRemove = !!imageSrcFromValue(state.content[field.id]) || hasBlobPreview();
    btn.disabled = !canRemove;
  }

  function showLocalFilePreview(file) {
    var prev = $("img-prev");
    var empty = $("img-prev-empty");
    var cap = $("img-prev-caption");
    if (!prev || !file) return;
    revokePreviewObjectUrl();
    previewObjectUrl = URL.createObjectURL(file);
    prev.src = previewObjectUrl;
    prev.removeAttribute("hidden");
    prev.classList.remove("hidden");
    if (empty) empty.classList.add("hidden");
    if (cap) {
      cap.classList.remove("hidden");
      cap.textContent =
        "Ficheiro: " +
        file.name +
        " (" +
        Math.round(file.size / 1024) +
        " KB) — é este ficheiro que será convertido para WebP.";
      if (/captura|screenshot|print|ecr[aã]|tela|mockup|canva|post/i.test(file.name)) {
        cap.textContent +=
          " Parece arte/captura: no site ficará igual; para o hero, usa só a fotografia da pessoa.";
        cap.classList.add("preview-warn-shot");
      }
    }
    var probe = new Image();
    probe.onload = function () {
      if (!cap) return;
      if (probe.width >= 1600 && probe.height >= 900 && file.size > 350000) {
        cap.textContent =
          "⚠️ Este ficheiro parece captura de ecrã ou arte com texto (" +
          probe.width +
          "×" +
          probe.height +
          " px). Para o hero, escolhe só a fotografia da pessoa (JPG da câmara ou telemóvel).";
        cap.classList.add("preview-warn-shot");
      }
    };
    probe.src = previewObjectUrl;
    var fld = state.activeFieldId ? fieldById(state.activeFieldId) : null;
    if (fld) refreshImageLiveStatus(fld);
  }

  function hasBlobPreview() {
    var prev = $("img-prev");
    return !!(prev && prev.src && prev.src.indexOf("blob:") === 0);
  }

  function fieldHasPendingImage(fieldId) {
    return !!(state.content._pendingImages && state.content._pendingImages[fieldId]);
  }

  function restorePendingPreview(field) {
    if (!field || !isImageField(field)) return;
    var dataUrl = state.content._pendingImages && state.content._pendingImages[field.id];
    if (!dataUrl) return;
    var prev = $("img-prev");
    var empty = $("img-prev-empty");
    if (!prev) return;
    prev.src = dataUrl;
    prev.removeAttribute("hidden");
    prev.classList.remove("hidden");
    if (empty) empty.classList.add("hidden");
    var cap = $("img-prev-caption");
    if (cap) {
      cap.classList.remove("hidden");
      cap.textContent =
        "Versão nova (guardada no navegador). No site público ainda pode aparecer a imagem antiga até «Descarregar para Git» ou pedires publicação.";
    }
  }

  function imageUploadBannerHtml() {
    if (state.php && state.authenticated) {
      return '<p class="upload-banner"><strong>Upload:</strong> escolhe uma <strong>fotografia</strong> (não captura de ecrã) e clica <strong>Salvar foto</strong>.</p>';
    }
    return '<p class="upload-banner"><strong>Upload:</strong> escolhe a fotografia no disco do teu PC. A pré-visualização mostra esse ficheiro — depois <strong>Salvar foto</strong>.</p>';
  }

  function imageFieldActionsHtml(hasSavedSrc) {
    var canRemove = hasSavedSrc || hasBlobPreview();
    var html = '<div class="editor-field-actions" role="group" aria-label="Ações da fotografia">';
    html +=
      '<button type="button" class="btn primary" id="btn-field-save">Salvar foto</button>';
    html +=
      '<button type="button" class="btn danger" id="btn-field-remove-photo"' +
      (canRemove ? "" : " disabled") +
      ">Remover foto</button>";
    html += "</div>";
    var hint =
      state.php && state.authenticated
        ? "Escolhe a <strong>foto</strong> (não print do painel) → <strong>Salvar foto</strong> → <strong>Publicar no site</strong>."
        : "No site hospedado: <strong>Salvar foto</strong> guarda no navegador (sem descarga). Quando quiseres publicar, usa <strong>Descarregar para Git</strong> no topo.";
    html += '<p class="field-hint editor-field-actions-hint">' + hint + "</p>";
    return html;
  }

  function syncImageMetaFromForm(field) {
    if (field.type === "image") {
      var cur = state.content[field.id];
      if (!cur || typeof cur !== "object") cur = { src: "", alt: "", lightbox: "" };
      var altEl = $("img-alt");
      var lbEl = $("img-lb");
      if (altEl) cur.alt = altEl.value;
      if (lbEl) cur.lightbox = lbEl.value;
      state.content[field.id] = cur;
    } else if (field.type === "image-slot") {
      var slot = state.content[field.id];
      if (!slot || typeof slot !== "object") slot = { src: "", label: "", note: "" };
      var sl = $("slot-label");
      var sn = $("slot-note");
      var sm = $("slot-mode");
      if (sl) slot.label = sl.value;
      if (sn) slot.note = sn.value;
      if (sm) slot.mode = sm.value;
      state.content[field.id] = slot;
    }
    markDirty();
  }

  function withCacheBustSrc(path) {
    var p = String(path || "").trim();
    if (!p || /^(blob:|data:)/i.test(p)) return p;
    return cacheBustAsset(p);
  }

  function applyUploadedPathToField(field, path) {
    if (field.type === "image") {
      var cur = state.content[field.id];
      if (!cur || typeof cur !== "object") cur = { src: "", alt: "", lightbox: "" };
      cur.src = withCacheBustSrc(path);
      var altEl = $("img-alt");
      var lbEl = $("img-lb");
      if (altEl && altEl.value) cur.alt = altEl.value;
      if (lbEl && lbEl.value) cur.lightbox = lbEl.value;
      else if (!cur.lightbox) cur.lightbox = path;
      state.content[field.id] = cur;
    } else {
      var slot = state.content[field.id];
      if (!slot || typeof slot !== "object") slot = { src: "", label: "", note: "" };
      slot.src = withCacheBustSrc(path);
      var sl = $("slot-label");
      var sn = $("slot-note");
      var sm = $("slot-mode");
      if (sl) slot.label = sl.value;
      if (sn) slot.note = sn.value;
      if (sm) slot.mode = sm.value;
      if (slot.mode !== "image" && slot.mode !== "both") {
        slot.mode = "both";
        if (sm) sm.value = "both";
      }
      state.content[field.id] = slot;
    }
    markDirty();
  }

  function uploadImageFromFile(field, file) {
    var maxR = $("img-max");
    var qR = $("img-q");
    var maxW =
      field.type === "image-slot"
        ? maxR
          ? parseInt(maxR.value, 10)
          : 1200
        : maxR
        ? parseInt(maxR.value, 10)
        : 1400;
    var q = qR ? parseFloat(qR.value) : 0.82;
    var info = $("img-size-info");
    if (info) info.textContent = "A comprimir…";
    return compressImage(file, maxW, q)
      .then(function (blob) {
        return blobToDataUrl(blob).then(function (dataUrl) {
          return { blob: blob, dataUrl: dataUrl };
        });
      })
      .then(function (pack) {
        if (pack.blob.size > IMAGE_UPLOAD_MAX_BYTES) {
          throw new Error(
            "WebP maior que 8 MB — reduz largura máx. ou qualidade, ou escolhe outra foto."
          );
        }
        if (info) {
          info.textContent =
            "Original: " +
            Math.round(file.size / 1024) +
            " KB → WebP: " +
            Math.round(pack.blob.size / 1024) +
            " KB";
        }
        return uploadOrStoreImage(field.id, pack).then(function (path) {
          applyUploadedPathToField(field, path);
          return path;
        });
      });
  }

  function saveImageField(field) {
    syncImageMetaFromForm(field);
    var imgFile = $("img-file");
    var file = imgFile && imgFile.files && imgFile.files[0];
    var saveBtn = $("btn-field-save");
    if (saveBtn) {
      saveBtn.disabled = true;
      saveBtn.textContent = "A guardar…";
    }
    var work = file
      ? uploadImageFromFile(field, file).catch(function (err) {
          setStatus(err.message || "Erro no upload", "warn");
          throw err;
        })
      : Promise.resolve(null);

    return work
      .then(function () {
        if (file && !state.php) {
          markDirty();
          return null;
        }
        return saveContent();
      })
      .then(function () {
        if (!file) return;
        patchLivePreview(field);
        if (file && state.php && state.authenticated) {
          return applyToSite().then(function () {
            patchLivePreview(field);
            refreshImageLiveStatus(field);
          });
        }
        refreshLiveSiteFrame();
      })
      .then(function () {
        if (file && state.php && state.authenticated) {
          setStatus("Foto guardada e aplicada ao site. Atualiza o browser se ainda vês a antiga.", "ok");
        } else if (file && !state.php) {
          saveToBrowserStorage();
          setStatus("Foto guardada neste navegador. «Descarregar para Git» só quando fores publicar.", "ok");
        } else if (!file) {
          setStatus(state.php ? "Rótulos guardados no JSON." : "content.json descarregado.", "ok");
        }
        renderEditor();
      })
      .catch(function () {
        if (saveBtn) {
          saveBtn.disabled = false;
          saveBtn.textContent = "Salvar foto";
        }
      });
  }

  function removeImageField(field) {
    var hadSrc = !!imageSrcFromValue(state.content[field.id]);
    if (!hadSrc && !hasBlobPreview()) return;
    confirmDialog({
      title: "Remover fotografia?",
      message:
        "A imagem deixa de aparecer na pré-visualização e no conteúdo guardado (MySQL). " +
        "Para o site público ficar sem foto, clica depois «Publicar no site» no topo. " +
        "O ficheiro antigo em img/cms/ pode ficar no servidor — podes apagar manualmente se quiseres.",
      confirmLabel: "Sim, remover",
      cancelLabel: "Manter foto",
      danger: true,
    }).then(function (ok) {
      if (!ok) return;
      syncImageMetaFromForm(field);
      if (field.type === "image") {
        var cur = state.content[field.id] || { src: "", alt: "", lightbox: "" };
        cur.src = "";
        cur.lightbox = "";
        state.content[field.id] = cur;
      } else {
        var slot = state.content[field.id] || { src: "", label: "", note: "" };
        slot.src = "";
        state.content[field.id] = slot;
      }
      if (state.content._pendingImages && state.content._pendingImages[field.id]) {
        delete state.content._pendingImages[field.id];
      }
      var imgFile = $("img-file");
      if (imgFile) imgFile.value = "";
      revokePreviewObjectUrl();
      markDirty();
      return saveContent();
    }).then(function (saved) {
      if (saved === undefined) return;
      patchLivePreview(field);
      if (state.php && state.authenticated) {
        return applyToSite().then(function () {
          patchLivePreview(field);
          setStatus("Foto removida e site atualizado.", "ok");
          renderEditor();
        });
      }
      refreshLiveSiteFrame();
      setStatus("Foto removida na pré-visualização. Clica «Publicar no site» para o index.html.", "ok");
      renderEditor();
    });
  }

  function allFields() {
    if (!state.map) return [];
    return state.map.groups.reduce(function (acc, g) {
      return acc.concat(g.fields || []);
    }, []);
  }

  function fieldById(id) {
    return allFields().find(function (f) {
      return f.id === id;
    });
  }

  function typeLabel(type) {
    var map = {
      text: "Texto",
      html: "HTML",
      attr: "Atributo",
      image: "Imagem",
      "image-slot": "Foto (slot)",
      "video-embed": "Vídeo embed",
      "video-file": "Vídeo MP4",
    };
    return map[type] || type;
  }

  function isImageField(field) {
    return field.type === "image" || field.type === "image-slot";
  }

  function isVideoField(field) {
    return field.type === "video-embed" || field.type === "video-file";
  }

  function fieldPassesFilter(field) {
    if (state.menuFilter === "images") return isImageField(field);
    if (state.menuFilter === "videos") return isVideoField(field);
    return true;
  }

  function allowedEmbedHost(url) {
    try {
      var u = new URL(url);
      var h = u.hostname.toLowerCase().replace(/^www\./, "");
      if (h === "youtu.be" || h.indexOf("youtube.com") !== -1 || h.indexOf("youtube-nocookie.com") !== -1) return true;
      if (h.indexOf("facebook.com") !== -1 || h === "fb.watch") return true;
      if (h.indexOf("vimeo.com") !== -1) return true;
    } catch (e) {}
    return false;
  }

  function sanitizeVideoEmbed(raw) {
    raw = (raw || "").trim();
    if (!raw) return "";
    var iframe = raw.match(/<iframe[\s\S]*?<\/iframe>/i);
    if (iframe) {
      var srcMatch = iframe[0].match(/\ssrc=["']([^"']+)["']/i);
      if (srcMatch && allowedEmbedHost(srcMatch[1])) {
        return (
          '<iframe src="' +
          escapeAttr(srcMatch[1]) +
          '" title="Vídeo" allow="accelerometer; autoplay; clipboard-write; encrypted-media; picture-in-picture; web-share" allowfullscreen loading="lazy"></iframe>'
        );
      }
      return "";
    }
    var yt = raw.match(/(?:youtube\.com\/watch\?v=|youtu\.be\/)([\w-]+)/i);
    if (yt) {
      var yUrl = "https://www.youtube-nocookie.com/embed/" + yt[1];
      return (
        '<iframe src="' +
        yUrl +
        '" title="Vídeo YouTube" allowfullscreen loading="lazy"></iframe>'
      );
    }
    var vm = raw.match(/vimeo\.com\/(\d+)/i);
    if (vm) {
      var vUrl = "https://player.vimeo.com/video/" + vm[1];
      return (
        '<iframe src="' +
        vUrl +
        '" title="Vídeo Vimeo" allowfullscreen loading="lazy"></iframe>'
      );
    }
    if (allowedEmbedHost(raw)) {
      return (
        '<iframe src="' +
        escapeAttr(raw) +
        '" title="Vídeo" allowfullscreen loading="lazy"></iframe>'
      );
    }
    return "";
  }

  function setGroupOpen(groupId, open) {
    state.openGroups[groupId] = !!open;
  }

  function toggleGroup(groupId) {
    setGroupOpen(groupId, !state.openGroups[groupId]);
    renderMenu();
  }

  function api(path, options) {
    options = options || {};
    var opts = {
      method: options.method || "GET",
      credentials: "same-origin",
      headers: options.headers || {},
    };
    if (options.body !== undefined) {
      opts.headers["Content-Type"] = "application/json";
      opts.body = JSON.stringify(options.body);
    }
    return fetch("api/" + path, opts).then(function (r) {
      return r.json().then(function (data) {
        if (!r.ok) throw new Error(data.error || "Erro");
        return data;
      });
    });
  }

  function readField(doc, field) {
    var el = doc.querySelector(field.selector);
    if (!el) return null;
    if (field.type === "text") return (el.textContent || "").replace(/\s+/g, " ").trim();
    if (field.type === "html") return (el.innerHTML || "").trim();
    if (field.type === "attr") return el.getAttribute(field.attribute || "href") || "";
    if (field.type === "image") {
      var lb = field.lightboxSelector ? doc.querySelector(field.lightboxSelector) : null;
      return {
        src: el.getAttribute("src") || "",
        alt: el.getAttribute("alt") || "",
        lightbox: lb ? lb.getAttribute("data-lightbox-src") || "" : "",
      };
    }
    if (field.type === "image-slot") {
      var note = el.querySelector(".slot-note");
      var src = el.getAttribute("data-cms-src") || "";
      var mode = el.getAttribute("data-slot-mode") || "";
      if (mode !== "image" && mode !== "text" && mode !== "both") {
        mode = src ? "both" : "text";
      }
      return {
        src: src,
        label: el.getAttribute("data-label") || "",
        note: note ? (note.textContent || "").trim() : "",
        mode: mode,
      };
    }
    if (field.type === "choice") {
      return el.getAttribute(field.attribute || "data-value") || "";
    }
    if (field.type === "video-embed") {
      return (el.innerHTML || "").trim();
    }
    if (field.type === "video-file") {
      return el.getAttribute("src") || "";
    }
    return null;
  }

  function applyField(doc, field, value) {
    var nodes = field.all
      ? Array.prototype.slice.call(doc.querySelectorAll(field.selector))
      : [doc.querySelector(field.selector)].filter(Boolean);
    if (!nodes.length) return false;
    var ok = false;
    nodes.forEach(function (el) {
      if (applyFieldElement(doc, field, el, value)) ok = true;
    });
    return ok;
  }

  function applyFieldElement(doc, field, el, value) {
    if (field.type === "text") {
      el.textContent = value || "";
      return true;
    }
    if (field.type === "html") {
      el.innerHTML = value || "";
      return true;
    }
    if (field.type === "attr") {
      el.setAttribute(field.attribute || "href", value || "");
      return true;
    }
    if (field.type === "image") {
      if (!value || typeof value !== "object") return false;
      if (value.src) {
        el.setAttribute("src", value.src);
        el.removeAttribute("hidden");
      } else {
        el.removeAttribute("src");
        el.setAttribute("hidden", "");
      }
      if (value.alt !== undefined) el.setAttribute("alt", value.alt || "");
      if (field.lightboxSelector && value.lightbox) {
        var lb = doc.querySelector(field.lightboxSelector);
        if (lb) {
          lb.setAttribute("data-lightbox-src", value.lightbox);
          lb.setAttribute("data-lightbox-alt", value.alt || "");
        }
      }
      return true;
    }
    if (field.type === "image-slot") {
      applyImageSlot(el, value || {});
      return true;
    }
    if (field.type === "choice") {
      el.setAttribute(field.attribute || "data-value", value || "off");
      return true;
    }
    if (field.type === "video-embed") {
      el.innerHTML = sanitizeVideoEmbed(value || "");
      return true;
    }
    if (field.type === "video-file") {
      if (value) {
        el.setAttribute("src", value);
        el.removeAttribute("hidden");
      } else {
        el.removeAttribute("src");
        el.setAttribute("hidden", "");
      }
      return true;
    }
    return false;
  }

  function applyVideoBlocks(doc) {
    [
      { block: "como-video-block", prefix: "video.como" },
      { block: "hero-video-block", prefix: "video.hero" },
    ].forEach(function (cfg) {
      var block = doc.getElementById(cfg.block);
      if (!block) return;
      var embedRaw = state.content[cfg.prefix + ".embed"] || "";
      var mp4 = (state.content[cfg.prefix + ".mp4"] || "").trim();
      var embed = sanitizeVideoEmbed(embedRaw);
      var slot = block.querySelector(".video-embed-slot");
      var vid = block.querySelector(".video-native");
      if (mp4) {
        if (slot) slot.innerHTML = "";
        if (vid) {
          vid.setAttribute("src", mp4);
          vid.removeAttribute("hidden");
        }
        block.removeAttribute("hidden");
      } else if (embed) {
        if (slot) slot.innerHTML = embed;
        if (vid) {
          vid.removeAttribute("src");
          vid.setAttribute("hidden", "");
        }
        block.removeAttribute("hidden");
      } else {
        if (slot) slot.innerHTML = "";
        if (vid) {
          vid.removeAttribute("src");
          vid.setAttribute("hidden", "");
        }
        block.setAttribute("hidden", "");
      }
    });
  }

  function normalizeSlotMode(mode, hasSrc) {
    if (mode === "image" || mode === "text" || mode === "both") return mode;
    return hasSrc ? "both" : "text";
  }

  function applyImageSlot(el, val) {
    val = val || {};
    var src = (val.src || "").trim();
    var mode = normalizeSlotMode(val.mode, !!src);
    el.setAttribute("data-slot-mode", mode);
    el.classList.remove("slot-mode-text", "slot-mode-image", "slot-mode-both");
    el.classList.add("slot-mode-" + mode);
    if (val.label) el.setAttribute("data-label", val.label);
    var note = el.querySelector(".slot-note");
    if (note && val.note !== undefined) note.textContent = val.note;
    var showPhoto = mode !== "text" && !!src;
    if (src) el.setAttribute("data-cms-src", src);
    else el.removeAttribute("data-cms-src");
    el.classList.toggle("has-photo", showPhoto);
    var img = el.querySelector(".slot-photo");
    if (showPhoto) {
      if (!img) {
        img = el.ownerDocument.createElement("img");
        img.className = "slot-photo";
        el.insertBefore(img, el.firstChild);
      }
      img.setAttribute("src", withCacheBustSrc(src));
      img.setAttribute("alt", val.label || "");
      img.removeAttribute("hidden");
    } else if (img) {
      img.removeAttribute("src");
      img.setAttribute("hidden", "");
    }
  }

  function slideHasPhotoContent(i) {
    var b = state.content["gallery.photos.s" + i + ".before"];
    var a = state.content["gallery.photos.s" + i + ".after"];
    return (b && b.src) || (a && a.src);
  }

  function slideHasCommentContent(i) {
    var img = state.content["gallery.comments.s" + i + ".image"];
    var txt = (state.content["gallery.comments.s" + i + ".text"] || "").trim();
    return (img && img.src) || !!txt;
  }

  function applyCommentSlides(doc) {
    var i;
    for (i = 1; i <= 6; i++) {
      var slide = doc.querySelector('#galeria-comentarios .comment-slide[data-index="' + i + '"]');
      if (!slide) continue;
      var imgEl = slide.querySelector(".comment-img");
      var fb = slide.querySelector(".comment-fallback");
      var imgVal = state.content["gallery.comments.s" + i + ".image"];
      var txt = (state.content["gallery.comments.s" + i + ".text"] || "").trim();
      if (imgEl && imgVal && imgVal.src) {
        applyField(doc, { type: "image", selector: '#galeria-comentarios .comment-slide[data-index="' + i + '"] .comment-img' }, imgVal);
      }
      if (fb) {
        if (txt && !(imgVal && imgVal.src)) {
          fb.textContent = txt;
          fb.removeAttribute("hidden");
          if (imgEl) imgEl.setAttribute("hidden", "");
        } else if (txt && imgVal && imgVal.src) {
          fb.setAttribute("hidden", "");
        } else if (!txt) {
          fb.textContent = "";
          if (!(imgVal && imgVal.src)) fb.setAttribute("hidden", "");
        }
      }
    }
  }

  function updateGaleriasVisibility(doc) {
    var anyPhotos = false;
    var anyComments = false;
    var i;
    for (i = 1; i <= 6; i++) {
      var pSlide = doc.querySelector('#galeria-fotos .ba-slide[data-index="' + i + '"]');
      if (pSlide) {
        if (slideHasPhotoContent(i)) {
          pSlide.removeAttribute("hidden");
          anyPhotos = true;
        } else {
          pSlide.setAttribute("hidden", "");
        }
      }
      var cSlide = doc.querySelector('#galeria-comentarios .comment-slide[data-index="' + i + '"]');
      if (cSlide) {
        if (slideHasCommentContent(i)) {
          cSlide.removeAttribute("hidden");
          anyComments = true;
        } else {
          cSlide.setAttribute("hidden", "");
        }
      }
    }
    var secP = doc.getElementById("galeria-fotos");
    var secC = doc.getElementById("galeria-comentarios");
    if (secP) {
      if (anyPhotos) secP.removeAttribute("hidden");
      else secP.setAttribute("hidden", "");
    }
    if (secC) {
      if (anyComments) secC.removeAttribute("hidden");
      else secC.setAttribute("hidden", "");
    }
  }

  function applyFeaturePhotos(doc) {
    doc.querySelectorAll("#programa .feature-card").forEach(function (card) {
      var img = card.querySelector(".feature-photo");
      var src = img ? (img.getAttribute("src") || "").trim() : "";
      var show = !!src && !(img && img.hasAttribute("hidden"));
      card.classList.toggle("has-feature-photo", show);
    });
  }

  function programaMediaSrc(id) {
    var v = state.content[id];
    if (!v || typeof v !== "object") return "";
    return (v.src || "").trim();
  }

  function applyProgramaMedia(doc) {
    var root = doc.getElementById("programa-media");
    if (!root) return;
    var mode = String(state.content["programa.media.mode"] || root.getAttribute("data-view") || "off");
    if (mode !== "single" && mode !== "carousel") mode = "off";
    root.setAttribute("data-view", mode);
    var singleWrap = root.querySelector(".programa-single");
    var carousel = root.querySelector(".programa-carousel");
    var singleImg = root.querySelector(".programa-single-img");
    var count = 0;
    var i;
    for (i = 1; i <= 6; i++) {
      var slide = root.querySelector('.programa-slide[data-index="' + i + '"]');
      if (!slide) continue;
      var src = programaMediaSrc("programa.media.s" + i);
      var img = slide.querySelector("img");
      if (mode === "carousel" && src) {
        slide.removeAttribute("hidden");
        if (img) {
          img.setAttribute("src", src);
          img.setAttribute("alt", (state.content["programa.media.s" + i] && state.content["programa.media.s" + i].alt) || "");
          img.removeAttribute("hidden");
        }
        count++;
      } else {
        slide.setAttribute("hidden", "");
      }
    }
    var singleSrc = programaMediaSrc("programa.media.single");
    if (mode === "single" && singleSrc) {
      root.removeAttribute("hidden");
      if (singleWrap) singleWrap.removeAttribute("hidden");
      if (carousel) carousel.setAttribute("hidden", "");
      if (singleImg) {
        singleImg.setAttribute("src", singleSrc);
        singleImg.setAttribute("alt", (state.content["programa.media.single"] && state.content["programa.media.single"].alt) || "");
        singleImg.removeAttribute("hidden");
      }
      return;
    }
    if (mode === "carousel" && count > 0) {
      root.removeAttribute("hidden");
      if (singleWrap) singleWrap.setAttribute("hidden", "");
      if (carousel) {
        carousel.removeAttribute("hidden");
        carousel.classList.toggle("is-solo", count < 2);
        window.requestAnimationFrame(function () {
          if (carousel._carouselRefresh) carousel._carouselRefresh();
        });
      }
      return;
    }
    root.setAttribute("hidden", "");
  }

  function applyAutoCarousel(doc, rootId, prefix) {
    var root = doc.getElementById(rootId);
    if (!root) return;
    var count = 0;
    var i;
    for (i = 1; i <= 6; i++) {
      var slide = root.querySelector('.carousel-slide[data-index="' + i + '"]');
      if (!slide) continue;
      var val = state.content[prefix + i];
      var src = val && typeof val === "object" ? (val.src || "").trim() : "";
      var img = slide.querySelector("img");
      if (src) {
        slide.removeAttribute("hidden");
        if (img) {
          img.setAttribute("src", src);
          img.setAttribute("alt", val.alt || "");
          img.removeAttribute("hidden");
        }
        count++;
      } else {
        slide.setAttribute("hidden", "");
        if (img) {
          img.removeAttribute("src");
          img.setAttribute("hidden", "");
        }
      }
    }
    if (count > 0) {
      root.removeAttribute("hidden");
      root.classList.toggle("is-solo", count < 2);
      window.requestAnimationFrame(function () {
        if (root._carouselRefresh) root._carouselRefresh();
      });
    } else {
      root.setAttribute("hidden", "");
    }
  }

  function applyAutoCarousels(doc) {
    applyAutoCarousel(doc, "receitas-carousel", "receitas.slide.");
    applyAutoCarousel(doc, "paraquem-carousel", "paraquem.slide.");
  }

  function updateDepoimentosVisibility(doc) {
    var hasAny = false;
    [1, 2, 3].forEach(function (i) {
      var quoteId = "depoimentos.card" + i + ".quote";
      var quote = (state.content[quoteId] || "").trim();
      var card = doc.querySelector('#depoimentos .testimonial-card[data-index="' + i + '"]');
      if (card) {
        if (quote) {
          card.removeAttribute("hidden");
          hasAny = true;
        } else {
          card.setAttribute("hidden", "");
        }
      }
    });
    var empty = doc.querySelector("#testimonial-empty");
    var grid = doc.querySelector("#testimonial-grid");
    if (empty) {
      if (hasAny) empty.setAttribute("hidden", "");
      else empty.removeAttribute("hidden");
    }
    if (grid) {
      if (hasAny) grid.removeAttribute("hidden");
      else grid.setAttribute("hidden", "");
    }
  }

  function applyAllToDoc(doc) {
    allFields().forEach(function (field) {
      if (state.content[field.id] === undefined) return;
      applyField(doc, field, state.content[field.id]);
    });
    applyCommentSlides(doc);
    applyVideoBlocks(doc);
    updateDepoimentosVisibility(doc);
    updateGaleriasVisibility(doc);
    applyProgramaMedia(doc);
    applyFeaturePhotos(doc);
    applyAutoCarousels(doc);
  }

  function loadIndexDoc() {
    return fetch("../index.html", { cache: "no-store" })
      .then(function (r) {
        return r.text();
      })
      .then(function (html) {
        var parser = new DOMParser();
        state.indexDoc = parser.parseFromString(html, "text/html");
        return state.indexDoc;
      });
  }

  var liveFrameWired = false;

  function ensureVisualEditorShell() {
    var root = $("panel-editor");
    if (!root || root.querySelector("#live-site-frame")) return;
    root.innerHTML =
      '<div class="visual-toolbar" id="visual-toolbar">' +
      '<p class="visual-toolbar-hint" id="visual-toolbar-hint">Clica num <strong>texto</strong> ou <strong>imagem</strong> na página. SEO e campos invisíveis: escolhe no menu à esquerda.</p>' +
      '<div class="visual-toolbar-actions">' +
      '<button type="button" class="btn sm" id="btn-live-refresh">Atualizar vista</button>' +
      "</div></div>" +
      '<div class="visual-layout">' +
      '<div class="visual-frame-wrap"><iframe id="live-site-frame" class="live-site-frame" title="Página inicial"></iframe></div>' +
      '<aside id="visual-inspector" class="visual-inspector" aria-hidden="true">' +
      '<header class="visual-inspector-head">' +
      '<h3 id="visual-inspector-title">Editar</h3>' +
      '<button type="button" class="visual-inspector-close" id="visual-inspector-close" aria-label="Fechar">×</button>' +
      "</header>" +
      '<div id="visual-inspector-body" class="visual-inspector-body"></div>' +
      "</aside></div>";
    var btnRef = $("btn-live-refresh");
    if (btnRef) btnRef.addEventListener("click", refreshLiveSiteFrame);
    var btnClose = $("visual-inspector-close");
    if (btnClose) {
      btnClose.addEventListener("click", function () {
        state.activeFieldId = null;
        renderMenu();
        closeVisualInspector();
        clearVisualHighlights();
      });
    }
  }

  function closeVisualInspector() {
    var panel = $("visual-inspector");
    if (panel) {
      panel.classList.remove("is-open");
      panel.setAttribute("aria-hidden", "true");
    }
    var body = $("visual-inspector-body");
    if (body) body.innerHTML = "";
  }

  function openVisualInspector(field) {
    var panel = $("visual-inspector");
    var title = $("visual-inspector-title");
    if (title && field) title.textContent = field.label;
    if (panel) {
      panel.classList.add("is-open");
      panel.setAttribute("aria-hidden", "false");
    }
  }

  function clearVisualHighlights() {
    var frame = $("live-site-frame");
    if (!frame || !frame.contentDocument) return;
    frame.contentDocument.querySelectorAll(".cms-visual-active").forEach(function (el) {
      el.classList.remove("cms-visual-active");
    });
  }

  function highlightVisualTarget(fieldId) {
    clearVisualHighlights();
    var field = fieldById(fieldId);
    var frame = $("live-site-frame");
    if (!field || !frame || !frame.contentDocument) return;
    var el = frame.contentDocument.querySelector(field.selector);
    if (el) {
      el.classList.add("cms-visual-active");
      el.scrollIntoView({ behavior: "smooth", block: "center", inline: "nearest" });
    }
  }

  function scrollToFieldInFrame(field) {
    if (!field) return;
    highlightVisualTarget(field.id);
  }

  function injectVisualEditorStyles(doc) {
    if (!doc || doc.getElementById("cms-visual-editor-style")) return;
    var style = doc.createElement("style");
    style.id = "cms-visual-editor-style";
    style.textContent =
      ".cms-visual-target{cursor:pointer!important;transition:outline .15s,box-shadow .15s}" +
      ".cms-visual-target:hover{outline:2px dashed #1f5c45!important;outline-offset:3px}" +
      ".cms-visual-active{outline:3px solid #1f5c45!important;outline-offset:3px;box-shadow:0 0 0 6px rgba(31,92,69,.2)!important}";
    doc.head.appendChild(style);
  }

  function wireVisualEditor(frame) {
    if (!frame || !frame.contentDocument) return;
    var doc = frame.contentDocument;
    injectVisualEditorStyles(doc);
    doc.addEventListener(
      "click",
      function (ev) {
        var a = ev.target.closest("a");
        if (a) ev.preventDefault();
      },
      true
    );
    allFields().forEach(function (field) {
      if (field.selector === "title" || field.selector.indexOf("meta") === 0) return;
      var el = doc.querySelector(field.selector);
      if (!el) return;
      el.classList.add("cms-visual-target");
      el.setAttribute("data-cms-field", field.id);
      var tip =
        field.type === "image" || field.type === "image-slot"
          ? "📷 " + field.label
          : field.label;
      el.setAttribute("title", tip + " — clica para editar");
      el.addEventListener("click", function (ev) {
        ev.preventDefault();
        ev.stopPropagation();
        selectField(field.id);
      });
    });
    liveFrameWired = true;
  }

  function applyContentToFrameDocument(frame) {
    if (!frame || !frame.contentDocument) return;
    var doc = frame.contentDocument;
    applyAllToDoc(doc);
    pendingImagesToDoc(doc);
    bustPreviewImages(doc);
    wireVisualEditor(frame);
    if (state.activeFieldId) highlightVisualTarget(state.activeFieldId);
  }

  function refreshLiveSiteFrame() {
    ensureVisualEditorShell();
    var frame = $("live-site-frame");
    if (!frame) return;
    liveFrameWired = false;
    frame.onload = function () {
      applyContentToFrameDocument(frame);
    };
    frame.removeAttribute("srcdoc");
    frame.src = "../index.html?cms_preview=1&t=" + Date.now();
  }

  function bustPreviewImages(doc) {
    if (!doc) return;
    doc.querySelectorAll("img[src]").forEach(function (img) {
      var src = img.getAttribute("src") || "";
      if (!src || /^(blob:|data:)/i.test(src)) return;
      img.setAttribute("src", cacheBustAsset(src));
    });
  }

  function bustFieldImage(doc, field) {
    if (!doc || !field || !isImageField(field)) return;
    var el = doc.querySelector(field.selector);
    if (!el) return;
    var img = field.type === "image-slot" ? el.querySelector(".slot-photo") : el;
    if (!img || String(img.tagName).toUpperCase() !== "IMG") return;
    var src = img.getAttribute("src") || "";
    if (!src || /^(blob:|data:)/i.test(src)) return;
    img.setAttribute("src", cacheBustAsset(src));
  }

  function patchLivePreview(field) {
    var frame = $("live-site-frame");
    if (!field || !frame || !frame.contentDocument) return;
    applyField(frame.contentDocument, field, state.content[field.id]);
    applyCommentSlides(frame.contentDocument);
    applyVideoBlocks(frame.contentDocument);
    updateDepoimentosVisibility(frame.contentDocument);
    updateGaleriasVisibility(frame.contentDocument);
    applyProgramaMedia(frame.contentDocument);
    applyFeaturePhotos(frame.contentDocument);
    applyAutoCarousels(frame.contentDocument);
    bustFieldImage(frame.contentDocument, field);
  }

  function syncFromIndex() {
    if (!state.indexDoc) return;
    allFields().forEach(function (field) {
      if (state.content[field.id] !== undefined) return;
      var v = readField(state.indexDoc, field);
      if (v !== null && v !== "") state.content[field.id] = v;
    });
  }

  function setStatus(text, kind) {
    var pill = $("save-status");
    if (!pill) return;
    pill.textContent = text;
    pill.className = "status-pill " + (kind || "");
  }

  function localAdminUrl() {
    return "http://fazendoemcasa.test/admin/";
  }

  function isPublicAdminHost() {
    var h = (window.location.hostname || "").toLowerCase();
    return (
      h === "fazendoemcasa.com" ||
      h === "www.fazendoemcasa.com" ||
      h.indexOf("github.io") !== -1
    );
  }

  function isLocalTestHost() {
    var h = window.location.hostname || "";
    return /\.test$/i.test(h) || h === "localhost" || h === "127.0.0.1";
  }

  function phpHintMessage() {
    if (isPublicAdminHost()) {
      if (!state.authenticated) {
        return "Inicia sessão (login) para gravar fotos e publicar no servidor.";
      }
      return "Ligação ao servidor PHP perdida — recarrega a página. Se persistir, confirma que estás em https://fazendoemcasa.com/admin/";
    }
    if (isLocalTestHost()) {
      return (
        "PHP não responde neste URL. No Laragon: Menu → caminho www\\fazendoemcasa → «Criar virtual host» (fazendoemcasa.test), reinicia Apache e volta a " +
        localAdminUrl()
      );
    }
    return "Sem PHP: abre " + localAdminUrl() + " no Laragon.";
  }

  function updatePhpUi() {
    var hint = $("php-hint");
    var btnLar = $("btn-open-laragon-admin");
    var bs = $("btn-save");
    var ba = $("btn-apply");
    var bp = $("btn-publish");
    if (state.php) {
      if (hint) {
        hint.classList.add("hidden");
        hint.textContent = "";
      }
      if (btnLar) btnLar.classList.add("hidden");
      if (state.database) {
        setStatus("Ligado ao servidor · alterações gravam na base e em ficheiros", "ok");
        if (bs) bs.textContent = "Guardar conteúdo";
        if (ba) ba.textContent = "Atualizar index.html";
        if (bp) bp.textContent = "Publicar no site";
      } else {
        setStatus("PHP local — podes gravar fotos e JSON", "ok");
        if (bs) bs.textContent = "Guardar conteúdo";
        if (ba) ba.textContent = "Atualizar index.html";
        if (bp) bp.textContent = "Guardar e aplicar";
      }
      updateAdminChrome();
      return;
    }
    if (hint) {
      hint.classList.remove("hidden");
      hint.textContent = phpHintMessage();
    }
    if (btnLar) btnLar.classList.remove("hidden");
    if (!state.php) {
      var bs = $("btn-save");
      var ba = $("btn-apply");
      var bp = $("btn-publish");
      if (bs) bs.textContent = "Guardar no navegador";
      if (ba) ba.textContent = "Aplicar (pré-visualização)";
      if (bp) bp.textContent = "Descarregar para Git";
    }
    if (isPublicAdminHost()) {
      setStatus("Hospedagem estática: Salvar = memória do browser; descargas só em «Descarregar para Git»", "");
    }
  }

  function markDirty() {
    state.dirty = true;
    setStatus("Alterações por guardar", "warn");
  }

  function compressImage(file, maxWidth, quality) {
    return new Promise(function (resolve, reject) {
      var url = URL.createObjectURL(file);
      var img = new Image();
      img.onload = function () {
        var scale = Math.min(1, maxWidth / img.width);
        var w = Math.round(img.width * scale);
        var h = Math.round(img.height * scale);
        var canvas = document.createElement("canvas");
        canvas.width = w;
        canvas.height = h;
        var ctx = canvas.getContext("2d");
        ctx.drawImage(img, 0, 0, w, h);
        URL.revokeObjectURL(url);
        canvas.toBlob(
          function (blob) {
            if (!blob) {
              reject(new Error("Não foi possível comprimir."));
              return;
            }
            resolve(blob);
          },
          "image/webp",
          quality
        );
      };
      img.onerror = function () {
        URL.revokeObjectURL(url);
        reject(new Error("Imagem inválida."));
      };
      img.src = url;
    });
  }

  function blobToDataUrl(blob) {
    return new Promise(function (resolve) {
      var fr = new FileReader();
      fr.onload = function () {
        resolve(fr.result);
      };
      fr.readAsDataURL(blob);
    });
  }

  function renderMenu() {
    var wrap = $("menu-groups");
    if (!wrap || !state.map) return;
    wrap.innerHTML = "";
    state.map.groups.forEach(function (group) {
      var visibleFields = (group.fields || []).filter(fieldPassesFilter);
      if (!visibleFields.length) return;

      var g = document.createElement("div");
      g.className = "menu-group";
      if (state.openGroups[group.id]) g.classList.add("is-open");

      var head = document.createElement("button");
      head.type = "button";
      head.className = "menu-group-toggle";
      head.setAttribute("aria-expanded", state.openGroups[group.id] ? "true" : "false");
      var countLabel = visibleFields.length + (state.menuFilter === "images" ? " img" : " campos");
      head.innerHTML =
        '<span class="menu-chevron" aria-hidden="true">▶</span>' +
        '<span class="menu-group-label">' +
        group.title +
        '<span class="where">' +
        (group.where || "") +
        "</span></span>" +
        '<span class="menu-count">' +
        countLabel +
        "</span>";
      head.addEventListener("click", function () {
        toggleGroup(group.id);
      });
      g.appendChild(head);

      var list = document.createElement("div");
      list.className = "menu-fields";
      visibleFields.forEach(function (field) {
        var b = document.createElement("button");
        b.type = "button";
        b.dataset.fieldId = field.id;
        if (state.activeFieldId === field.id) b.classList.add("active");
        var tagClass = "type-tag";
        if (isImageField(field)) tagClass += " is-img";
        if (isVideoField(field)) tagClass += " is-vid";
        b.innerHTML =
          '<span class="' +
          tagClass +
          '">' +
          typeLabel(field.type) +
          "</span>" +
          field.label +
          '<span class="field-where">Onde: ' +
          (field.where || "—") +
          "</span>";
        b.addEventListener("click", function () {
          setGroupOpen(group.id, true);
          selectField(field.id);
        });
        list.appendChild(b);
      });
      g.appendChild(list);
      wrap.appendChild(g);
    });
  }

  function selectField(fieldId) {
    state.activeFieldId = fieldId;
    var field = fieldById(fieldId);
    if (field) {
      state.map.groups.some(function (g) {
        if ((g.fields || []).some(function (f) {
          return f.id === fieldId;
        })) {
          state.activeGroupId = g.id;
          setGroupOpen(g.id, true);
          return true;
        }
        return false;
      });
    }
    renderMenu();
    renderEditor();
    if (state.activeTab === "editar") scrollToFieldInFrame(field);
    if (state.activeTab === "guia") renderGuide();
  }

  function renderEditor() {
    ensureVisualEditorShell();
    var mount = $("visual-inspector-body");
    if (!mount) return;
    var field = state.activeFieldId ? fieldById(state.activeFieldId) : null;
    if (!field) {
      closeVisualInspector();
      return;
    }

    openVisualInspector(field);
    highlightVisualTarget(field.id);

    var val = state.content[field.id];
    var group = state.map.groups.find(function (g) {
      return g.id === state.activeGroupId;
    });

    var html = "";
    html += "<div class=\"editor-card\">";
    html += "<h2>" + escapeHtml(field.label) + "</h2>";
    html += "<div class=\"editor-meta\">";
    html += "Secção: <strong>" + escapeHtml(group ? group.title : "") + "</strong><br>";
    html += "Onde no site: " + escapeHtml(field.where || "") + "<br>";
    html += "Seletor: <code>" + escapeHtml(field.selector) + "</code>";
    html += "</div>";

    if (field.type === "text" || field.type === "html" || field.type === "attr") {
      var isArea = field.type === "html" || (val && String(val).length > 80);
      html += "<div class=\"field\">";
      html += "<label for=\"f-val\">Conteúdo</label>";
      if (isArea) {
        html += "<textarea id=\"f-val\">" + escapeHtml(String(val || "")) + "</textarea>";
      } else {
        html += "<input id=\"f-val\" type=\"" + (field.type === "attr" && field.attribute === "href" ? "url" : "text") + "\" value=\"" + escapeAttr(String(val || "")) + "\">";
      }
      if (field.hint) html += "<p class=\"field-hint\">" + escapeHtml(field.hint) + "</p>";
      html += "</div>";
    }

    if (field.type === "choice") {
      var current = String(val || "off");
      html += "<div class=\"field\">";
      html += "<label for=\"f-val\">O que mostrar logo abaixo</label>";
      html += "<select id=\"f-val\">";
      (field.options || []).forEach(function (opt) {
        html += "<option value=\"" + escapeAttr(opt.value) + "\"" + (current === opt.value ? " selected" : "") + ">" + escapeHtml(opt.label) + "</option>";
      });
      html += "</select>";
      if (field.hint) html += "<p class=\"field-hint\">" + escapeHtml(field.hint) + "</p>";
      html += "</div>";
    }

    if (field.type === "image") {
      val = val && typeof val === "object" ? val : { src: "", alt: "", lightbox: "" };
      html += imageUploadBannerHtml();
      html += imageEditorHtml(field, val);
    }

    if (field.type === "image-slot") {
      val = val && typeof val === "object" ? val : { src: "", label: "", note: "" };
      html += imageUploadBannerHtml();
      html += imageSlotEditorHtml(field, val);
    }

    if (field.type === "video-embed") {
      html += '<p class="upload-banner"><strong>Recomendado:</strong> no Facebook/YouTube, «Incorporar» → copia o <strong>código iframe</strong> e cola abaixo. Mais leve do que upload.</p>';
      html += "<div class=\"field\"><label for=\"f-val\">Código embed (iframe) ou URL</label>";
      html += "<textarea id=\"f-val\" rows=\"8\" placeholder=\"&lt;iframe src=&quot;...&quot; ...&gt;&lt;/iframe&gt;\">" + escapeHtml(String(val || "")) + "</textarea>";
      if (field.hint) html += "<p class=\"field-hint\">" + escapeHtml(field.hint) + "</p>";
      html += "</div>";
    }

    if (field.type === "video-file") {
      html += '<p class="upload-banner"><strong>Alternativa:</strong> MP4 curto (máx. 12 MB). Se tiveres embed, usa o outro campo.</p>';
      html += "<div class=\"field img-tools\"><label for=\"vid-file\">Carregar MP4</label>";
      html += "<input type=\"file\" id=\"vid-file\" accept=\"video/mp4\">";
      if (val) html += "<p class=\"field-hint\">Atual: <code>" + escapeHtml(String(val)) + "</code></p>";
      html += '<p class="field-hint" id="vid-size-info"></p></div>';
    }

    html += "</div>";
    revokePreviewObjectUrl();
    mount.innerHTML = html;
    bindEditor(field);
    if (isImageField(field)) {
      restorePendingPreview(field);
      refreshImageLiveStatus(field);
    }
  }

  function imageEditorHtml(field, val) {
    var html = mediaPreviewSection(val.src || "");
    html += "<div class=\"field img-tools\">";
    html += "<label><strong>1.</strong> Carregar imagem (JPG, PNG ou WebP)</label>";
    html += "<div class=\"img-controls\">";
    html += "<input type=\"file\" id=\"img-file\" accept=\"image/*\">";
    html += "<label>Largura máx. <input type=\"range\" id=\"img-max\" min=\"600\" max=\"2000\" step=\"50\" value=\"1400\"></label>";
    html += "<span id=\"img-max-lbl\">1400px</span>";
    html += "<label>Qualidade <input type=\"range\" id=\"img-q\" min=\"0.5\" max=\"0.95\" step=\"0.05\" value=\"0.82\"></label>";
    html += "</div>";
    html += "<div class=\"field\"><label for=\"img-alt\">Texto alternativo (acessibilidade)</label>";
    html += "<input id=\"img-alt\" type=\"text\" value=\"" + escapeAttr(val.alt || "") + "\"></div>";
    if (field.lightboxSelector) {
      html += "<div class=\"field\"><label for=\"img-lb\">Lightbox (imagem grande)</label>";
      html += "<input id=\"img-lb\" type=\"text\" value=\"" + escapeAttr(val.lightbox || val.src || "") + "\">";
      html += "<p class=\"field-hint\">Ao carregar, pode usar o mesmo ficheiro ou enviar outro em «Aplicar ao site».</p></div>";
    }
    html += "<p class=\"field-hint\" id=\"img-size-info\"></p>";
    html += imageFieldActionsHtml(!!(val.src || ""));
    html += "</div>";
    return html;
  }

  function imageSlotEditorHtml(field, val) {
    var html = mediaPreviewSection(val.src || "");
    html += "<div class=\"field img-tools\">";
    html += "<label><strong>1.</strong> Carregar fotografia (JPG, PNG ou WebP)</label>";
    html += "<div class=\"img-controls\">";
    html += "<input type=\"file\" id=\"img-file\" accept=\"image/*\">";
    html += "<label>Largura máx. <input type=\"range\" id=\"img-max\" min=\"600\" max=\"1600\" step=\"50\" value=\"1200\"></label>";
    html += "<span id=\"img-max-lbl\">1200px</span>";
    html += "<label>Qualidade <input type=\"range\" id=\"img-q\" min=\"0.5\" max=\"0.95\" step=\"0.05\" value=\"0.82\"></label>";
    html += "</div>";
    html += "<div class=\"field\"><label for=\"slot-mode\">O que mostrar neste espaço</label>";
    html += "<select id=\"slot-mode\">";
    html += "<option value=\"both\"" + (val.mode === "both" ? " selected" : "") + ">Imagem e texto</option>";
    html += "<option value=\"image\"" + (val.mode === "image" ? " selected" : "") + ">Só imagem</option>";
    html += "<option value=\"text\"" + (val.mode !== "both" && val.mode !== "image" ? " selected" : "") + ">Só texto</option>";
    html += "</select>";
    html += "<p class=\"field-hint\">Imagem e texto: a foto fica em cima, limpa, e o texto do cartão continua por baixo. Só imagem: a foto ocupa o cartão. Só texto: o espaço riscado, sem foto. A nota só aparece quando não há fotografia.</p></div>";
    html += "<div class=\"slot-extra\">";
    html += "<div class=\"field\"><label for=\"slot-label\">Rótulo (data-label)</label>";
    html += "<input id=\"slot-label\" type=\"text\" value=\"" + escapeAttr(val.label || "") + "\"></div>";
    html += "<div class=\"field\"><label for=\"slot-note\">Nota visível no placeholder</label>";
    html += "<input id=\"slot-note\" type=\"text\" value=\"" + escapeAttr(val.note || "") + "\"></div>";
    html += "</div>";
    html += "<p class=\"field-hint\" id=\"img-size-info\"></p>";
    html += imageFieldActionsHtml(!!(val.src || ""));
    html += "</div>";
    return html;
  }

  function bindEditor(field) {
    var simple = $("f-val");
    if (simple) {
      var onSimple = function () {
        state.content[field.id] = simple.value;
        markDirty();
        patchLivePreview(field);
      };
      if (simple.tagName === "SELECT") simple.addEventListener("change", onSimple);
      else simple.addEventListener("input", onSimple);
    }
    var vidFile = $("vid-file");
    if (vidFile) {
      vidFile.addEventListener("change", function () {
        var file = vidFile.files && vidFile.files[0];
        if (!file) return;
        var info = $("vid-size-info");
        if (file.size > 12 * 1024 * 1024) {
          if (info) info.textContent = "Ficheiro demasiado grande. Usa embed.";
          return;
        }
        var fr = new FileReader();
        fr.onload = function () {
          var dataUrl = fr.result;
          var upload = state.php && state.authenticated
            ? api("upload-video.php", { method: "POST", body: { fieldId: field.id, dataUrl: dataUrl } })
            : Promise.reject(new Error("Sem PHP"));
          upload
            .then(function (res) {
              state.content[field.id] = res.path;
              markDirty();
              renderEditor();
            })
            .catch(function () {
              if (info) info.textContent = "Grava só com Laragon/PHP ou usa embed.";
            });
        };
        fr.readAsDataURL(file);
      });
      return;
    }
    var imgFile = $("img-file");
    if (!imgFile) return;

    var maxR = $("img-max");
    var maxLbl = $("img-max-lbl");
    var qR = $("img-q");
    if (maxR && maxLbl) {
      maxR.addEventListener("input", function () {
        maxLbl.textContent = maxR.value + "px";
      });
    }

    imgFile.addEventListener("change", function () {
      var file = imgFile.files && imgFile.files[0];
      if (!file) return;
      showLocalFilePreview(file);
      var info = $("img-size-info");
      if (info) {
        info.textContent = Math.round(file.size / 1024) + " KB — clica «Salvar foto» para comprimir e gravar.";
      }
      refreshImageLiveStatus(field);
      updateRemovePhotoButton();
    });

    var btnSaveField = $("btn-field-save");
    if (btnSaveField) {
      btnSaveField.addEventListener("click", function () {
        saveImageField(field);
      });
    }
    var btnRemovePhoto = $("btn-field-remove-photo");
    if (btnRemovePhoto) {
      btnRemovePhoto.addEventListener("click", function () {
        removeImageField(field);
      });
    }

    var altEl = $("img-alt");
    if (altEl) {
      altEl.addEventListener("input", function () {
        var cur = state.content[field.id] || {};
        cur.alt = altEl.value;
        state.content[field.id] = cur;
        markDirty();
      });
    }
    var lbEl = $("img-lb");
    if (lbEl) {
      lbEl.addEventListener("input", function () {
        var cur = state.content[field.id] || {};
        cur.lightbox = lbEl.value;
        state.content[field.id] = cur;
        markDirty();
      });
    }
    ["slot-label", "slot-note", "slot-mode"].forEach(function (id) {
      var el = $(id);
      if (!el) return;
      var onSlotMeta = function () {
        var cur = state.content[field.id] || { src: "", label: "", note: "", mode: "text" };
        if (id === "slot-label") cur.label = el.value;
        if (id === "slot-note") cur.note = el.value;
        if (id === "slot-mode") cur.mode = el.value;
        state.content[field.id] = cur;
        markDirty();
        patchLivePreview(field);
      };
      el.addEventListener("input", onSlotMeta);
      el.addEventListener("change", onSlotMeta);
    });
  }

  function uploadOrStoreImage(fieldId, pack) {
    if (state.php && state.authenticated) {
      return api("upload-image.php", {
        method: "POST",
        body: { fieldId: fieldId, dataUrl: pack.dataUrl, ext: "webp" },
      }).then(function (res) {
        return res.path;
      });
    }
    var publicPath = staticImagePath(fieldId);
    if (!state.content._pendingImages) state.content._pendingImages = {};
    state.content._pendingImages[fieldId] = pack.dataUrl;
    return Promise.resolve(publicPath);
  }

  function renderGuide() {
    var mount = $("panel-guia");
    if (!mount || !state.map) return;
    var sections = "";
    state.map.groups.forEach(function (g) {
      var rows = "";
      (g.fields || []).forEach(function (f) {
        rows +=
          "<tr><td>" +
          escapeHtml(f.label) +
          "</td><td>" +
          typeLabel(f.type) +
          "</td><td>" +
          escapeHtml(f.where || "") +
          "</td><td><code>" +
          escapeHtml(f.id) +
          "</code></td></tr>";
      });
      sections +=
        "<details class=\"guide-section\"><summary>" +
        escapeHtml(g.title) +
        " <span class=\"guide-section-meta\">(" +
        (g.fields || []).length +
        " campos)</span></summary>" +
        "<p class=\"guide-section-where\">" +
        escapeHtml(g.where || "") +
        "</p>" +
        "<div class=\"guide-table-wrap\"><table class=\"guide\"><thead><tr><th>O quê</th><th>Tipo</th><th>Onde</th><th>ID</th></tr></thead><tbody>" +
        rows +
        "</tbody></table></div></details>";
    });
    mount.innerHTML =
      "<div class=\"guide-intro\">" +
      "<p>Para <strong>upload de imagens</strong>, usa a aba <strong>Editar</strong> → filtro <strong>Só imagens</strong> (não esta guia).</p>" +
      "<ol><li>Edita na aba Editar.</li><li>Guardar JSON → Aplicar ao index.html.</li><li>Commit e push para publicar.</li></ol>" +
      (state.php ? "" : "<p><strong>Sem PHP:</strong> exporta JSON e index.html pelo navegador.</p>") +
      "</div>" +
      sections;
  }

  function renderPreview() {
    var mount = $("panel-preview");
    if (!mount) return;
    var frame = $("preview-frame");
    if (!frame) {
      mount.innerHTML =
        '<iframe id="preview-frame" class="preview-frame" title="Pré-visualização"></iframe>';
      frame = $("preview-frame");
    }
    frame.onload = function () {
      var doc = frame.contentDocument;
      if (!doc) return;
      applyAllToDoc(doc);
      pendingImagesToDoc(doc);
    };
    frame.removeAttribute("srcdoc");
    frame.src = "../index.html?cms_preview=1&t=" + Date.now();
  }

  function pendingImagesToDoc(doc) {
    var pending = state.content._pendingImages;
    if (!pending) return;
    Object.keys(pending).forEach(function (fieldId) {
      var field = fieldById(fieldId);
      if (!field) return;
      var val = state.content[fieldId];
      if (field.type === "image" && val && val.src) val = Object.assign({}, val, { src: pending[fieldId] });
      if (field.type === "image-slot" && val && val.src) {
        val = Object.assign({}, val, { src: pending[fieldId] });
        applyImageSlot(doc.querySelector(field.selector), val);
        var img = doc.querySelector(field.selector + " .slot-photo");
        if (img) img.setAttribute("src", pending[fieldId]);
      } else if (field.type === "image") {
        var el = doc.querySelector(field.selector);
        if (el) el.setAttribute("src", pending[fieldId]);
      }
    });
  }

  function contentPayloadForDisk() {
    var payload = Object.assign({}, state.content);
    if (payload._pendingImages) delete payload._pendingImages;
    return payload;
  }

  function saveToBrowserStorage() {
    try {
      localStorage.setItem(STATIC_STORAGE_KEY, JSON.stringify(contentPayloadForDisk()));
      if (state.content._pendingImages) {
        localStorage.setItem(STATIC_PENDING_KEY, JSON.stringify(state.content._pendingImages));
      } else {
        localStorage.removeItem(STATIC_PENDING_KEY);
      }
      return true;
    } catch (e) {
      return false;
    }
  }

  function loadFromBrowserStorage() {
    try {
      var raw = localStorage.getItem(STATIC_STORAGE_KEY);
      if (!raw) return false;
      var parsed = JSON.parse(raw);
      if (parsed && typeof parsed === "object") {
        state.content = Object.assign({}, state.content, parsed);
      }
      var pend = localStorage.getItem(STATIC_PENDING_KEY);
      if (pend) {
        state.content._pendingImages = JSON.parse(pend);
      }
      return true;
    } catch (e) {
      return false;
    }
  }

  function dataUrlToBlob(dataUrl) {
    var parts = String(dataUrl).split(",");
    var mime = parts[0].match(/:(.*?);/)[1];
    var bin = atob(parts[1]);
    var len = bin.length;
    var arr = new Uint8Array(len);
    for (var i = 0; i < len; i++) arr[i] = bin.charCodeAt(i);
    return new Blob([arr], { type: mime });
  }

  function requireServerWrite() {
    if (!isPublicAdminHost() && !isLocalTestHost()) return true;
    if (state.php && state.authenticated) return true;
    var msg =
      isPublicAdminHost() && !state.authenticated
        ? "Inicia sessão para gravar no servidor."
        : "Sem ligação ao PHP — recarrega o painel ou usa " + localAdminUrl();
    setStatus(msg, "warn");
    if (!state.authenticated && $("login-screen")) showLogin();
    return false;
  }

  function saveContent(options) {
    options = options || {};
    var payload = contentPayloadForDisk();
    if (isPublicAdminHost() && (!state.php || !state.authenticated)) {
      if (!options.forceDownload) {
        if (!requireServerWrite()) return Promise.resolve();
      }
    }
    if (state.php && state.authenticated) {
      return api("save-content.php", { method: "POST", body: { content: payload } })
        .then(function () {
          state.dirty = false;
          setStatus("Guardado no servidor", "ok");
        })
        .catch(function (e) {
          setStatus(e.message, "warn");
        });
    }
    if (!options.forceDownload && saveToBrowserStorage()) {
      state.dirty = false;
      setStatus("Guardado neste navegador — sem descarga", "ok");
      return Promise.resolve();
    }
    downloadJson("content.json", payload);
    state.dirty = false;
    setStatus("content.json descarregado", "ok");
    return Promise.resolve();
  }

  function downloadExportForGit() {
    setStatus("A descarregar ficheiros para o Git…", "warn");
    var pending = state.content._pendingImages || {};
    var ids = Object.keys(pending);
    var chain = Promise.resolve();
    ids.forEach(function (fieldId, index) {
      chain = chain.then(function () {
        return new Promise(function (resolve) {
          setTimeout(function () {
            var blob = dataUrlToBlob(pending[fieldId]);
            downloadBlobFile(blob, staticImagePath(fieldId).split("/").pop());
            resolve();
          }, index * 900);
        });
      });
    });
    return chain
      .then(function () {
        return new Promise(function (resolve) {
          setTimeout(resolve, 400);
        });
      })
      .then(function () {
        return saveContent({ forceDownload: true });
      })
      .then(function () {
        return new Promise(function (resolve) {
          setTimeout(resolve, 500);
        });
      })
      .then(function () {
        return applyToSite({ forceDownload: true });
      })
      .then(function () {
        setStatus("Descargas concluídas — envia ao repositório ou pede ao assistente «publica».", "ok");
      });
  }

  function flushPendingImages() {
    var pending = state.content._pendingImages;
    if (!pending || !state.php || !state.authenticated) return Promise.resolve();
    var jobs = Object.keys(pending).map(function (fieldId) {
      return api("upload-image.php", {
        method: "POST",
        body: { fieldId: fieldId, dataUrl: pending[fieldId], ext: "webp" },
      });
    });
    return Promise.all(jobs).then(function () {
      delete state.content._pendingImages;
    });
  }

  function applyToSite(options) {
    options = options || {};
    var run = state.dirty && state.php && state.authenticated ? saveContent() : Promise.resolve();
    return run
      .then(flushPendingImages)
      .then(loadIndexDoc)
      .then(function (doc) {
        applyAllToDoc(doc);
        pendingImagesToDoc(doc);
        var serialized = serializeDoc(doc);
        if (state.php && state.authenticated) {
          return api("apply-index.php", { method: "POST", body: { html: serialized } })
            .then(function (res) {
              var msg =
                state.database
                  ? "Site publicado no servidor (index.html atualizado). Atualiza fazendoemcasa.com no browser (backup: " +
                    (res.backup || "—") +
                    ")."
                  : "index.html atualizado no servidor (backup: " + (res.backup || "—") + ").";
              setStatus(msg, "ok");
              var f = state.activeFieldId ? fieldById(state.activeFieldId) : null;
              if (f && isImageField(f)) refreshImageLiveStatus(f);
            })
            .catch(function (e) {
              setStatus(e.message, "warn");
            });
        }
        saveToBrowserStorage();
        if (!options.forceDownload) {
          state.dirty = false;
          setStatus("Aplicado na pré-visualização (sem descarga)", "ok");
          return;
        }
        downloadText("index.html", serialized);
        setStatus("index.html descarregado", "ok");
      });
  }

  function publishAll() {
    if (isPublicAdminHost() && (!state.php || !state.authenticated)) {
      if (!requireServerWrite()) return Promise.resolve();
    }
    if (!state.php) {
      return downloadExportForGit();
    }
    setStatus("A guardar e aplicar…", "");
    return saveContent()
      .then(function () {
        return applyToSite();
      })
      .catch(function (e) {
        setStatus(e.message || "Erro", "warn");
      });
  }

  function serializeDoc(doc) {
    return "<!DOCTYPE html>\n" + doc.documentElement.outerHTML;
  }

  function downloadJson(name, data) {
    downloadText(name, JSON.stringify(data, null, 2));
  }

  function downloadText(name, text) {
    var blob = new Blob([text], { type: "text/plain;charset=utf-8" });
    downloadBlobFile(blob, name);
  }

  function downloadBlobFile(blob, filename) {
    var a = document.createElement("a");
    var url = URL.createObjectURL(blob);
    a.href = url;
    a.download = filename;
    a.click();
    URL.revokeObjectURL(url);
  }

  function staticImagePath(fieldId) {
    var safe = String(fieldId).replace(/[^a-z0-9._-]/gi, "");
    return "img/cms/" + safe + ".webp";
  }

  function switchTab(tab) {
    state.activeTab = tab;
    document.querySelectorAll(".tabs-main button").forEach(function (b) {
      b.classList.toggle("active", b.dataset.tab === tab);
    });
    $("panel-editor").classList.toggle("hidden", tab !== "editar");
    $("panel-guia").classList.toggle("hidden", tab !== "guia");
    $("panel-preview").classList.toggle("hidden", tab !== "preview");
    var perfil = $("panel-perfil");
    if (perfil) perfil.classList.toggle("hidden", tab !== "perfil");
    var side = $("sidebar-editor");
    var topbar = document.querySelector(".topbar");
    var hideEditorChrome = tab === "perfil" || tab === "guia" || tab === "preview";
    if (side) side.classList.toggle("hidden", hideEditorChrome);
    if (topbar) topbar.classList.toggle("hidden", tab === "perfil");
    if (tab === "guia") renderGuide();
    if (tab === "preview") renderPreview();
    if (tab === "editar") {
      refreshLiveSiteFrame();
      renderEditor();
    }
    if (tab === "perfil") renderProfilePanel();
  }

  function updateAdminChrome() {
    var tabPerfil = $("tab-perfil");
    var logout = $("btn-logout");
    var label = $("admin-user-label");
    var env = $("env-badge");
    if (tabPerfil) tabPerfil.classList.toggle("hidden", !state.accounts);
    if (logout) logout.classList.toggle("hidden", !state.accounts);
    if (label) {
      if (state.accounts && state.profile && state.profile.nome) {
        label.textContent = state.profile.nome;
        label.classList.remove("hidden");
      } else if (state.accounts && state.profile && state.profile.login) {
        label.textContent = state.profile.login;
        label.classList.remove("hidden");
      } else {
        label.classList.add("hidden");
      }
    }
    if (env) {
      if (state.php && state.database) {
        env.textContent = "Produção · MySQL";
        env.classList.remove("hidden");
      } else if (state.php) {
        env.textContent = "PHP local";
        env.classList.remove("hidden");
      } else {
        env.classList.add("hidden");
      }
    }
  }

  function renderProfilePanel() {
    var p = state.profile;
    if (!p) return;
    var nome = $("prof-nome");
    var email = $("prof-email");
    var loginEl = $("prof-login");
    if (nome) nome.value = p.nome || "";
    if (email) email.value = p.email || "";
    if (loginEl) loginEl.textContent = p.login || "—";
  }

  function bindProfileFormsOnce() {
    if (state.profileFormsBound) return;
    state.profileFormsBound = true;
    var pf = $("profile-form");
    if (pf) {
      pf.addEventListener("submit", function (ev) {
        ev.preventDefault();
        var msg = $("prof-msg");
        if (msg) msg.textContent = "";
        api("profile.php", {
          method: "POST",
          body: {
            nome: $("prof-nome").value.trim(),
            email: $("prof-email").value.trim(),
          },
        })
          .then(function (res) {
            state.profile = res.profile;
            updateAdminChrome();
            if (msg) msg.textContent = "Perfil guardado.";
          })
          .catch(function (e) {
            if (msg) msg.textContent = e.message || "Erro ao guardar.";
          });
      });
    }
    var pwf = $("password-form");
    if (pwf) {
      pwf.addEventListener("submit", function (ev) {
        ev.preventDefault();
        var msg = $("pwd-msg");
        if (msg) msg.textContent = "";
        api("change-password.php", {
          method: "POST",
          body: {
            currentPassword: $("pwd-current").value,
            newPassword: $("pwd-new").value,
          },
        })
          .then(function () {
            $("pwd-current").value = "";
            $("pwd-new").value = "";
            if (msg) msg.textContent = "Senha atualizada.";
          })
          .catch(function (e) {
            if (msg) msg.textContent = e.message || "Erro.";
          });
      });
    }
  }

  function configureLoginForm(res) {
    var hint = $("login-hint");
    var acc = $("login-account-fields");
    var pin = $("login-pin-fields");
    var useAccounts = !!(res && res.accounts);
    var pinRequired = !!(res && res.pinRequired);
    if (acc) acc.classList.toggle("hidden", !useAccounts);
    if (pin) pin.classList.toggle("hidden", useAccounts || !pinRequired);
    if (hint) {
      if (useAccounts) {
        hint.textContent = "Entra com o teu login e senha de administrador.";
      } else if (pinRequired) {
        hint.textContent =
          "Configura textos e imagens. Usa o PIN do config.local.php (modo legado).";
      } else {
        hint.textContent = "Modo local sem autenticação.";
      }
    }
    var admLogin = $("adm-login");
    var admPass = $("adm-password");
    var pinIn = $("pin");
    if (admLogin) admLogin.required = useAccounts;
    if (admPass) admPass.required = useAccounts;
    if (pinIn) pinIn.required = !useAccounts && pinRequired;
  }

  function showLogin() {
    $("login-screen").classList.remove("hidden");
    $("app").classList.add("hidden");
  }

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function escapeAttr(s) {
    return escapeHtml(s).replace(/'/g, "&#39;");
  }

  function showApp() {
    $("login-screen").classList.add("hidden");
    $("app").classList.remove("hidden");
  }

  function tryLogin() {
    var body = {};
    if (state.accounts) {
      body.login = ($("adm-login") && $("adm-login").value.trim()) || "";
      body.password = ($("adm-password") && $("adm-password").value) || "";
    } else {
      body.pin = ($("pin") && $("pin").value) || "";
    }
    return api("login.php", { method: "POST", body: body })
      .then(function (res) {
        state.authenticated = true;
        state.php = true;
        if (res.profile) state.profile = res.profile;
        return api("session.php").then(function (sess) {
          state.database = !!sess.database;
          state.accounts = !!sess.accounts;
        });
      })
      .then(function () {
        showApp();
        updateAdminChrome();
        updatePhpUi();
        bootstrapApp();
      })
      .catch(function (e) {
        $("login-err").textContent = e.message;
      });
  }

  function loadContentPayload() {
    if (state.php && state.authenticated) {
      return api("content.php").then(function (res) {
        return res.content || {};
      });
    }
    return fetch("content.json").then(function (r) {
      return r.json();
    });
  }

  function bootstrapApp() {
    bindProfileFormsOnce();
    if (state.appBootstrapped) return;
    state.appBootstrapped = true;
    Promise.all([
      fetch("site-map.json").then(function (r) {
        return r.json();
      }),
      loadContentPayload(),
      loadIndexDoc(),
    ])
      .then(function (parts) {
        state.map = parts[0];
        state.content = parts[1] || {};
        if (!state.php) loadFromBrowserStorage();
        syncFromIndex();
        var programaSlot = state.content["programa.photo"];
        if (programaSlot && typeof programaSlot === "object" && (programaSlot.src || "").trim() && programaSlot.mode !== "image" && programaSlot.mode !== "both") {
          programaSlot.mode = "both";
        }
        if (!state.activeFieldId && state.map.groups[0] && state.map.groups[0].fields[0]) {
          selectField(state.map.groups[0].fields[0].id);
        } else {
          renderMenu();
          renderEditor();
        }
        renderGuide();
        if (state.activeTab === "editar") refreshLiveSiteFrame();
      })
      .catch(function (e) {
        setStatus("Erro ao carregar: " + e.message, "warn");
      });

    $("btn-save").addEventListener("click", function () {
      saveContent();
    });
    $("btn-apply").addEventListener("click", applyToSite);
    var btnPub = $("btn-publish");
    if (btnPub) btnPub.addEventListener("click", publishAll);
    $("btn-preview-refresh").addEventListener("click", renderPreview);
    $("btn-open-site").addEventListener("click", function () {
      window.open("../index.html", "_blank");
    });
    var btnLar = $("btn-open-laragon-admin");
    if (btnLar) {
      btnLar.addEventListener("click", function () {
        window.location.href = localAdminUrl();
      });
    }

    document.querySelectorAll(".tabs-main button").forEach(function (b) {
      b.addEventListener("click", function () {
        switchTab(b.dataset.tab);
      });
    });

    document.querySelectorAll("[data-menu-filter]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        state.menuFilter = btn.getAttribute("data-menu-filter") || "all";
        document.querySelectorAll("[data-menu-filter]").forEach(function (b) {
          b.classList.toggle("active", b === btn);
        });
        if (state.menuFilter === "images") {
          setGroupOpen("galeria-fotos", true);
          setGroupOpen("galeria-comentarios", true);
          setGroupOpen("hero", true);
        }
        if (state.menuFilter === "videos") {
          setGroupOpen("videos", true);
        }
        renderMenu();
      });
    });

    var btnLogout = $("btn-logout");
    if (btnLogout) {
      btnLogout.addEventListener("click", function () {
        api("logout.php", { method: "POST", body: {} })
          .then(function () {
            state.authenticated = false;
            state.profile = null;
            showLogin();
          })
          .catch(function () {
            showLogin();
          });
      });
    }
  }

  function init() {
    els.login = $("login-form");
    if (els.login) {
      els.login.addEventListener("submit", function (ev) {
        ev.preventDefault();
        tryLogin();
      });
    }

    function applySession(res) {
      state.php = !!res.php;
      state.database = !!res.database;
      state.accounts = !!res.accounts;
      state.authMode = res.authMode || "none";
      if (res.profile) state.profile = res.profile;
      configureLoginForm(res);
      updateAdminChrome();
      var needAuth = state.accounts || res.pinRequired === true;
      if (res.authenticated) {
        state.authenticated = true;
        showApp();
        updateAdminChrome();
        updatePhpUi();
        bootstrapApp();
        return;
      }
      state.authenticated = false;
      if (!needAuth) {
        state.authenticated = true;
        showApp();
        updatePhpUi();
        bootstrapApp();
        return;
      }
      showLogin();
    }

    function sessionFailed() {
      state.php = false;
      state.authenticated = false;
      if (isPublicAdminHost() || isLocalTestHost()) {
        configureLoginForm({ accounts: true, pinRequired: false });
        showLogin();
        var err = $("login-err");
        if (err) {
          err.textContent =
            "Não foi possível contactar o servidor (PHP). Recarrega a página ou confirma que estás com sessão iniciada.";
        }
        return;
      }
      state.authenticated = true;
      showApp();
      updatePhpUi();
      bootstrapApp();
    }

    api("session.php")
      .then(applySession)
      .catch(function () {
        return api("session.php").then(applySession).catch(sessionFailed);
      });
  }

  init();
})();
