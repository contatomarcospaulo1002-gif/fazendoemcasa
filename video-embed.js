(function () {
  "use strict";

  function activateLazyIframes() {
    document.querySelectorAll(".video-embed-slot iframe[data-src]").forEach(function (frame) {
      var src = frame.getAttribute("data-src");
      if (!src) return;
      frame.setAttribute("src", src);
      frame.removeAttribute("data-src");
    });
  }

  if ("IntersectionObserver" in window) {
    var io = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) return;
          entry.target.querySelectorAll("iframe[data-src]").forEach(function (frame) {
            var src = frame.getAttribute("data-src");
            if (!src) return;
            frame.setAttribute("src", src);
            frame.removeAttribute("data-src");
          });
          io.unobserve(entry.target);
        });
      },
      { rootMargin: "120px" }
    );
    document.querySelectorAll(".video-embed-ratio").forEach(function (el) {
      io.observe(el);
    });
  } else {
    activateLazyIframes();
  }
})();
