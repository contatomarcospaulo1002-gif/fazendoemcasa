(function () {
  "use strict";

  function prefersReducedMotion() {
    return window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  }

  function visibleSlides(track) {
    return Array.prototype.filter.call(track.querySelectorAll(".carousel-slide"), function (slide) {
      return !slide.hasAttribute("hidden");
    });
  }

  function scrollSlideIntoView(viewport, slide, behavior) {
    if (!viewport || !slide) return;
    var vpRect = viewport.getBoundingClientRect();
    var slRect = slide.getBoundingClientRect();
    var delta = slRect.left - vpRect.left - (vpRect.width - slRect.width) / 2;
    viewport.scrollBy({ left: delta, behavior: behavior || "smooth" });
  }

  function nearestIndex(viewport, slides) {
    var center = viewport.scrollLeft + viewport.clientWidth / 2;
    var best = 0;
    var bestDist = Infinity;
    slides.forEach(function (slide, i) {
      var mid = slide.offsetLeft + slide.offsetWidth / 2;
      var d = Math.abs(mid - center);
      if (d < bestDist) {
        bestDist = d;
        best = i;
      }
    });
    return best;
  }

  function updateActive(root, viewport, slides) {
    if (!slides.length) return;
    var idx = nearestIndex(viewport, slides);
    slides.forEach(function (slide, i) {
      slide.classList.toggle("is-active", i === idx);
      slide.setAttribute("aria-hidden", i === idx ? "false" : "true");
    });
    var dots = root.querySelectorAll(".carousel-dot");
    dots.forEach(function (dot, i) {
      dot.classList.toggle("is-active", i === idx);
    });
    root._carouselIndex = idx;
  }

  function goTo(root, viewport, slides, index) {
    if (!slides.length) return;
    var i = (index + slides.length) % slides.length;
    scrollSlideIntoView(viewport, slides[i], "smooth");
    root._carouselIndex = i;
  }

  function initCarousel(root) {
    var viewport = root.querySelector(".carousel-viewport");
    var track = root.querySelector(".carousel-track");
    if (!viewport || !track) return;

    var slides = visibleSlides(track);

    var autoplayMs = parseInt(root.getAttribute("data-carousel-autoplay") || "5500", 10);
    var timer = null;
    var paused = false;

    function refreshSlides() {
      slides = visibleSlides(track);
      buildDots();
      updateActive(root, viewport, slides);
      if (slides.length) {
        scrollSlideIntoView(viewport, slides[root._carouselIndex || 0] || slides[0], "auto");
      }
      startAutoplay();
    }

    function buildDots() {
      var dotsWrap = root.querySelector(".carousel-dots");
      if (!dotsWrap) return;
      dotsWrap.innerHTML = "";
      slides.forEach(function (_, i) {
        var b = document.createElement("button");
        b.type = "button";
        b.className = "carousel-dot";
        b.setAttribute("aria-label", "Ir para diapositiva " + (i + 1));
        b.addEventListener("click", function () {
          goTo(root, viewport, slides, i);
        });
        dotsWrap.appendChild(b);
      });
    }

    function startAutoplay() {
      stopAutoplay();
      if (prefersReducedMotion() || autoplayMs < 2000 || slides.length < 2) return;
      timer = window.setInterval(function () {
        if (paused) return;
        var next = (root._carouselIndex + 1) % slides.length;
        goTo(root, viewport, slides, next);
      }, autoplayMs);
    }

    function stopAutoplay() {
      if (timer) window.clearInterval(timer);
      timer = null;
    }

    var prev = root.querySelector(".carousel-prev");
    var next = root.querySelector(".carousel-next");
    if (prev) {
      prev.addEventListener("click", function () {
        goTo(root, viewport, slides, (root._carouselIndex || 0) - 1);
      });
    }
    if (next) {
      next.addEventListener("click", function () {
        goTo(root, viewport, slides, (root._carouselIndex || 0) + 1);
      });
    }

    var scrollT;
    viewport.addEventListener("scroll", function () {
      window.clearTimeout(scrollT);
      scrollT = window.setTimeout(function () {
        updateActive(root, viewport, slides);
      }, 80);
    }, { passive: true });

    viewport.addEventListener("touchstart", function () {
      paused = true;
    }, { passive: true });
    viewport.addEventListener("touchend", function () {
      window.setTimeout(function () {
        paused = false;
      }, 4000);
    }, { passive: true });

    root.addEventListener("mouseenter", function () {
      paused = true;
    });
    root.addEventListener("mouseleave", function () {
      paused = false;
    });

    refreshSlides();
    root._carouselIndex = 0;
    if (!slides.length) {
      root._carouselRefresh = refreshSlides;
      return;
    }
    window.requestAnimationFrame(function () {
      scrollSlideIntoView(viewport, slides[0], "auto");
      updateActive(root, viewport, slides);
      startAutoplay();
    });

    root._carouselRefresh = refreshSlides;
  }

  function boot() {
    document.querySelectorAll("[data-carousel]").forEach(initCarousel);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }
})();
