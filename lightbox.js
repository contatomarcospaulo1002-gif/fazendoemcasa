(function () {
  var overlay = document.getElementById("image-lightbox");
  if (!overlay) return;
  var img = overlay.querySelector(".lightbox-img");
  var closeBtn = overlay.querySelector(".lightbox-close");
  var openers = document.querySelectorAll("[data-lightbox-src]");

  function openLightbox(src, alt) {
    img.src = src;
    img.alt = alt || "";
    overlay.hidden = false;
    overlay.setAttribute("aria-hidden", "false");
    document.body.style.overflow = "hidden";
  }
  function closeLightbox() {
    overlay.hidden = true;
    overlay.setAttribute("aria-hidden", "true");
    img.removeAttribute("src");
    document.body.style.overflow = "";
  }

  openers.forEach(function (el) {
    el.addEventListener("click", function (ev) {
      ev.preventDefault();
      openLightbox(el.getAttribute("data-lightbox-src"), el.getAttribute("data-lightbox-alt") || el.getAttribute("alt") || "");
    });
    el.addEventListener("keydown", function (ev) {
      if (ev.key === "Enter" || ev.key === " ") {
        ev.preventDefault();
        openLightbox(el.getAttribute("data-lightbox-src"), el.getAttribute("data-lightbox-alt") || "");
      }
    });
  });
  if (closeBtn) closeBtn.addEventListener("click", function (ev) { ev.stopPropagation(); closeLightbox(); });
  overlay.addEventListener("click", function (ev) {
    if (ev.target === overlay || ev.target.classList.contains("lightbox-stage")) {
      closeLightbox();
    }
  });
  document.addEventListener("keydown", function (ev) {
    if (ev.key === "Escape" && !overlay.hidden) closeLightbox();
  });
})();
