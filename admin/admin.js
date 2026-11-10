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
    menuFilter: "all",
    openGroups: {},
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
      return {
        src: el.getAttribute("data-cms-src") || "",
        label: el.getAttribute("data-label") || "",
        note: note ? (note.textContent || "").trim() : "",
      };
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
    applyVideoBlocks(doc);
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
    if (state.activeTab === "guia") renderGuide();
  }

  function renderEditor() {
    var mount = $("panel-editor");
    if (!mount) return;
    var field = state.activeFieldId ? fieldById(state.activeFieldId) : null;
    if (!field) {
      mount.innerHTML =
        "<p class=\"guide-intro\">Escolhe uma <strong>secção</strong> no menu (▶ abre e fecha).</p>" +
        "<p class=\"guide-intro\">Upload: <strong>Só imagens</strong> → <strong>Galeria de fotos</strong> ou <strong>Hero</strong> → campo com etiqueta verde <strong>IMAGEM</strong>.</p>";
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
      html += '<p class="upload-banner"><strong>Upload aqui:</strong> botão «Escolher ficheiro» abaixo. Depois <strong>Guardar JSON</strong> e <strong>Aplicar ao index.html</strong>.</p>';
      html += imageEditorHtml(field, val);
    }

    if (field.type === "image-slot") {
      val = val && typeof val === "object" ? val : { src: "", label: "", note: "" };
      html += '<p class="upload-banner"><strong>Upload da fotografia:</strong> escolhe o ficheiro abaixo (comprime para WebP).</p>';
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
    mount.innerHTML = html;
    bindEditor(field);
  }

  function imageEditorHtml(field, val) {
    var html = "<div class=\"field img-tools\">";
    html += "<label><strong>1.</strong> Carregar imagem (JPG, PNG ou WebP)</label>";
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
    html += "<label><strong>1.</strong> Carregar fotografia (JPG, PNG ou WebP)</label>";
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
            if (state.php && state.authenticated) {
              return saveContent().then(function () {
                setStatus("Foto em img/cms + JSON guardado. Clica «Aplicar» ou «Guardar e aplicar». Depois git push para o site público.", "ok");
                renderEditor();
              });
            }
            setStatus("Upload precisa do Laragon (fazendoemcasa.test/admin), não do site no GitHub.", "warn");
            renderEditor();
          });
        })
        .catch(function (err) {
          if (info) info.textContent = err.message || "Erro";
          setStatus(err.message || "Erro no upload", "warn");
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
    return Promise.reject(
      new Error("Sem PHP no servidor. Abre http://fazendoemcasa.test/admin no Laragon.")
    );
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
    return Promise.resolve();
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
              setStatus(
                "Site local atualizado. Faz commit + push para aparecer em fazendoemcasa.com (backup: " +
                  (res.backup || "—") +
                  ")",
                "ok"
              );
            })
            .catch(function (e) {
              setStatus(e.message, "warn");
            });
        }
        downloadText("index.html", serialized);
        setStatus("index.html descarregado — substitui na raiz do projeto", "ok");
      });
  }

  function publishAll() {
    setStatus("A guardar e aplicar…", "");
    return saveContent()
      .then(applyToSite)
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
        if (!state.php) {
          $("php-hint").classList.remove("hidden");
          $("php-hint").textContent =
            "Estás sem PHP: upload não grava. Usa http://fazendoemcasa.test/admin no Laragon.";
        }
        bootstrapApp();
        return;
      }
      })
      .catch(function () {
        state.authenticated = true;
        showApp();
        $("php-hint").classList.remove("hidden");
        $("php-hint").textContent =
          "Estás sem PHP: upload não grava. Usa http://fazendoemcasa.test/admin no Laragon.";
        bootstrapApp();
      });
  }

  init();
})();
