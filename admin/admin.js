(function () {
  "use strict";

  var state = {
    map: null,
    content: {},
    activeTab: "editar",
    activeGroupId: null,
    activeFieldId: null,
    php: false,
    authenticated: false,
    dirty: false,
    indexDoc: null,
  };

  var els = {};

  function $(id) {
    return document.getElementById(id);
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
    };
    return map[type] || type;
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
      return {
        src: el.getAttribute("data-cms-src") || "",
        label: el.getAttribute("data-label") || "",
        note: note ? (note.textContent || "").trim() : "",
      };
    }
    return null;
  }

  function applyField(doc, field, value) {
    var el = doc.querySelector(field.selector);
    if (!el) return false;
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
    return false;
  }

  function applyImageSlot(el, val) {
    val = val || {};
    var src = val.src || "";
    if (!src) {
      el.classList.remove("has-photo");
      el.removeAttribute("data-cms-src");
      var old = el.querySelector(".slot-photo");
      if (old) old.remove();
      return;
    }
    el.setAttribute("data-cms-src", src);
    el.classList.add("has-photo");
    var img = el.querySelector(".slot-photo");
    if (!img) {
      img = el.ownerDocument.createElement("img");
      img.className = "slot-photo";
      img.alt = val.label || "";
      el.insertBefore(img, el.firstChild);
    }
    img.setAttribute("src", src);
    img.setAttribute("alt", val.label || "");
    if (val.label) el.setAttribute("data-label", val.label);
    var note = el.querySelector(".slot-note");
    if (note && val.note !== undefined) note.textContent = val.note;
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
    updateDepoimentosVisibility(doc);
    updateGaleriasVisibility(doc);
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
      var g = document.createElement("div");
      g.className = "menu-group";

      var head = document.createElement("button");
      head.type = "button";
      head.innerHTML =
        group.title +
        '<span class="where">' +
        (group.where || "") +
        "</span>";
      head.addEventListener("click", function () {
        state.activeGroupId = group.id;
        if (group.fields && group.fields[0]) selectField(group.fields[0].id);
      });
      g.appendChild(head);

      var list = document.createElement("div");
      list.className = "menu-fields";
      (group.fields || []).forEach(function (field) {
        var b = document.createElement("button");
        b.type = "button";
        b.dataset.fieldId = field.id;
        if (state.activeFieldId === field.id) b.classList.add("active");
        b.innerHTML =
          '<span class="type-tag">' +
          typeLabel(field.type) +
          "</span>" +
          field.label +
          '<span class="field-where">Onde: ' +
          (field.where || "—") +
          "</span>";
        b.addEventListener("click", function () {
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
          return true;
        }
        return false;
      });
    }
    renderMenu();
    renderEditor();
    if (state.activeTab === "guia") renderGuide();
  }

  function renderEditor() {
    var mount = $("panel-editor");
    if (!mount) return;
    var field = state.activeFieldId ? fieldById(state.activeFieldId) : null;
    if (!field) {
      mount.innerHTML = "<p class=\"guide-intro\">Escolhe um campo no menu à esquerda. Cada item indica <strong>o quê</strong> e <strong>onde</strong> no site será alterado.</p>";
      return;
    }

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

    if (field.type === "image") {
      val = val && typeof val === "object" ? val : { src: "", alt: "", lightbox: "" };
      html += imageEditorHtml(field, val);
    }

    if (field.type === "image-slot") {
      val = val && typeof val === "object" ? val : { src: "", label: "", note: "" };
      html += imageSlotEditorHtml(field, val);
    }

    html += "</div>";
    mount.innerHTML = html;
    bindEditor(field);
  }

  function imageEditorHtml(field, val) {
    var html = "<div class=\"field img-tools\">";
    html += "<label>Imagem atual</label>";
    if (val.src) html += "<img class=\"img-preview\" id=\"img-prev\" src=\"../" + escapeAttr(val.src) + "\" alt=\"\">";
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
    html += "</div>";
    return html;
  }

  function imageSlotEditorHtml(field, val) {
    var html = "<div class=\"field img-tools\">";
    html += "<label>Fotografia no retângulo tracejado</label>";
    if (val.src) html += "<img class=\"img-preview\" id=\"img-prev\" src=\"../" + escapeAttr(val.src) + "\" alt=\"\">";
    html += "<div class=\"img-controls\">";
    html += "<input type=\"file\" id=\"img-file\" accept=\"image/*\">";
    html += "<label>Largura máx. <input type=\"range\" id=\"img-max\" min=\"600\" max=\"1600\" step=\"50\" value=\"1200\"></label>";
    html += "<span id=\"img-max-lbl\">1200px</span>";
    html += "<label>Qualidade <input type=\"range\" id=\"img-q\" min=\"0.5\" max=\"0.95\" step=\"0.05\" value=\"0.82\"></label>";
    html += "</div>";
    html += "<div class=\"slot-extra\">";
    html += "<div class=\"field\"><label for=\"slot-label\">Rótulo (data-label)</label>";
    html += "<input id=\"slot-label\" type=\"text\" value=\"" + escapeAttr(val.label || "") + "\"></div>";
    html += "<div class=\"field\"><label for=\"slot-note\">Nota visível no placeholder</label>";
    html += "<input id=\"slot-note\" type=\"text\" value=\"" + escapeAttr(val.note || "") + "\"></div>";
    html += "</div>";
    html += "<p class=\"field-hint\" id=\"img-size-info\"></p>";
    html += "</div>";
    return html;
  }

  function bindEditor(field) {
    var simple = $("f-val");
    if (simple) {
      simple.addEventListener("input", function () {
        state.content[field.id] = simple.value;
        markDirty();
      });
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
      var maxW = maxR ? parseInt(maxR.value, 10) : 1400;
      var q = qR ? parseFloat(qR.value) : 0.82;
      var info = $("img-size-info");
      if (info) info.textContent = "A comprimir…";
      compressImage(file, maxW, q)
        .then(function (blob) {
          return blobToDataUrl(blob).then(function (dataUrl) {
            return { blob: blob, dataUrl: dataUrl };
          });
        })
        .then(function (pack) {
          if (info) {
            info.textContent =
              "Original: " +
              Math.round(file.size / 1024) +
              " KB → WebP: " +
              Math.round(pack.blob.size / 1024) +
              " KB";
          }
          return uploadOrStoreImage(field.id, pack).then(function (path) {
            if (field.type === "image") {
              var cur = state.content[field.id];
              if (!cur || typeof cur !== "object") cur = { src: "", alt: "", lightbox: "" };
              cur.src = path;
              var altEl = $("img-alt");
              if (altEl && altEl.value) cur.alt = altEl.value;
              var lbEl = $("img-lb");
              if (lbEl && lbEl.value) cur.lightbox = lbEl.value;
              else if (!cur.lightbox) cur.lightbox = path;
              state.content[field.id] = cur;
            } else {
              var slot = state.content[field.id];
              if (!slot || typeof slot !== "object") slot = { src: "", label: "", note: "" };
              slot.src = path;
              var sl = $("slot-label");
              var sn = $("slot-note");
              if (sl) slot.label = sl.value;
              if (sn) slot.note = sn.value;
              state.content[field.id] = slot;
            }
            markDirty();
            renderEditor();
          });
        })
        .catch(function (err) {
          if (info) info.textContent = err.message || "Erro";
        });
    });

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
    ["slot-label", "slot-note"].forEach(function (id) {
      var el = $(id);
      if (!el) return;
      el.addEventListener("input", function () {
        var cur = state.content[field.id] || { src: "", label: "", note: "" };
        if (id === "slot-label") cur.label = el.value;
        if (id === "slot-note") cur.note = el.value;
        state.content[field.id] = cur;
        markDirty();
      });
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
    state.content._pendingImages = state.content._pendingImages || {};
    state.content._pendingImages[fieldId] = pack.dataUrl;
    return Promise.resolve("img/cms/" + fieldId + ".webp");
  }

  function renderGuide() {
    var mount = $("panel-guia");
    if (!mount || !state.map) return;
    var rows = "";
    state.map.groups.forEach(function (g) {
      (g.fields || []).forEach(function (f) {
        rows +=
          "<tr><td>" +
          escapeHtml(g.title) +
          "</td><td>" +
          escapeHtml(f.label) +
          "</td><td>" +
          typeLabel(f.type) +
          "</td><td>" +
          escapeHtml(f.where || "") +
          "</td><td><code>" +
          escapeHtml(f.selector) +
          "</code></td><td><code>" +
          escapeHtml(f.id) +
          "</code></td></tr>";
      });
    });
    mount.innerHTML =
      "<div class=\"guide-intro\">" +
      "<p>Esta guia é o <strong>mapeamento completo</strong> da página inicial. Cada linha corresponde a um campo editável no menu «Editar».</p>" +
      "<ol><li>Edita textos e imagens na aba Editar.</li><li>Guarda o JSON (Laragon grava em <code>admin/content.json</code>).</li><li>Clica «Aplicar ao index.html» para o site público usar os valores.</li><li>Faz commit e push para publicar no GitHub Pages.</li></ol>" +
      (state.php ? "" : "<p><strong>Sem PHP:</strong> podes exportar JSON e descarregar o <code>index.html</code> gerado pelo navegador.</p>") +
      "</div>" +
      "<div class=\"guide-table-wrap\"><table class=\"guide\"><thead><tr><th>Secção</th><th>O quê</th><th>Tipo</th><th>Onde aparece</th><th>Seletor CSS</th><th>ID interno</th></tr></thead><tbody>" +
      rows +
      "</tbody></table></div>";
  }

  function renderPreview() {
    var mount = $("panel-preview");
    if (!mount) return;
    loadIndexDoc().then(function (doc) {
      applyAllToDoc(doc);
      pendingImagesToDoc(doc);
      var html = "<!DOCTYPE html>\n" + doc.documentElement.outerHTML;
      var frame = $("preview-frame");
      if (!frame) {
        mount.innerHTML = "<iframe id=\"preview-frame\" class=\"preview-frame\" title=\"Pré-visualização\"></iframe>";
        frame = $("preview-frame");
      }
      frame.srcdoc = html;
    });
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

  function saveContent() {
    var payload = Object.assign({}, state.content);
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
    downloadJson("content.json", payload);
    state.dirty = false;
    setStatus("JSON descarregado", "ok");
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

  function applyToSite() {
    return flushPendingImages()
      .then(loadIndexDoc)
      .then(function (doc) {
      applyAllToDoc(doc);
      pendingImagesToDoc(doc);
      var serialized = serializeDoc(doc);
      if (state.php && state.authenticated) {
        return api("apply-index.php", { method: "POST", body: { html: serialized } })
          .then(function (res) {
            setStatus("index.html atualizado (backup: " + (res.backup || "—") + ")", "ok");
          })
          .catch(function (e) {
            setStatus(e.message, "warn");
          });
      }
      downloadText("index.html", serialized);
      setStatus("index.html descarregado — substitui na raiz do projeto", "ok");
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
    var a = document.createElement("a");
    a.href = URL.createObjectURL(blob);
    a.download = name;
    a.click();
    URL.revokeObjectURL(a.href);
  }

  function switchTab(tab) {
    state.activeTab = tab;
    document.querySelectorAll(".tabs-main button").forEach(function (b) {
      b.classList.toggle("active", b.dataset.tab === tab);
    });
    $("panel-editor").classList.toggle("hidden", tab !== "editar");
    $("panel-guia").classList.toggle("hidden", tab !== "guia");
    $("panel-preview").classList.toggle("hidden", tab !== "preview");
    if (tab === "guia") renderGuide();
    if (tab === "preview") renderPreview();
    if (tab === "editar") renderEditor();
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

  function tryLogin(pin) {
    return api("login.php", { method: "POST", body: { pin: pin } })
      .then(function () {
        state.authenticated = true;
        showApp();
        bootstrapApp();
      })
      .catch(function (e) {
        $("login-err").textContent = e.message;
      });
  }

  function bootstrapApp() {
    Promise.all([
      fetch("site-map.json").then(function (r) {
        return r.json();
      }),
      fetch("content.json").then(function (r) {
        return r.json();
      }),
      loadIndexDoc(),
    ])
      .then(function (parts) {
        state.map = parts[0];
        state.content = parts[1] || {};
        syncFromIndex();
        if (!state.activeFieldId && state.map.groups[0] && state.map.groups[0].fields[0]) {
          selectField(state.map.groups[0].fields[0].id);
        } else {
          renderMenu();
          renderEditor();
        }
        renderGuide();
      })
      .catch(function (e) {
        setStatus("Erro ao carregar: " + e.message, "warn");
      });

    $("btn-save").addEventListener("click", saveContent);
    $("btn-apply").addEventListener("click", applyToSite);
    $("btn-preview-refresh").addEventListener("click", renderPreview);
    $("btn-open-site").addEventListener("click", function () {
      window.open("../index.html", "_blank");
    });

    document.querySelectorAll(".tabs-main button").forEach(function (b) {
      b.addEventListener("click", function () {
        switchTab(b.dataset.tab);
      });
    });
  }

  function init() {
    els.login = $("login-form");
    if (els.login) {
      els.login.addEventListener("submit", function (ev) {
        ev.preventDefault();
        tryLogin($("pin").value);
      });
    }

    api("session.php")
      .then(function (res) {
        state.php = !!res.php;
        var pinRequired = res.pinRequired !== false;
        if (res.authenticated || !pinRequired) {
          state.authenticated = true;
          showApp();
          if (!state.php) $("php-hint").classList.remove("hidden");
          bootstrapApp();
          return;
        }
      })
      .catch(function () {
        state.authenticated = true;
        showApp();
        $("php-hint").classList.remove("hidden");
        bootstrapApp();
      });
  }

  init();
})();
