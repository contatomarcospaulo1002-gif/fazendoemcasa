(function () {
  var KEY = "cookie_consent";
  var PIXEL_ID = "2111475296169479";
  var banner = document.getElementById("cookie-banner");
  var btnAccept = document.getElementById("cookie-accept");
  var btnRefuse = document.getElementById("cookie-refuse");
  var settingsLinks = document.querySelectorAll("a[data-cookie-settings]");
  var pixelLoaded = false;

  function getConsent() {
    try { return localStorage.getItem(KEY); } catch (e) { return null; }
  }
  function setConsent(v) {
    try { localStorage.setItem(KEY, v); } catch (e) {}
  }
  function showBanner() {
    if (banner) {
      banner.hidden = false;
      banner.setAttribute("aria-hidden", "false");
    }
  }
  function hideBanner() {
    if (banner) {
      banner.hidden = true;
      banner.setAttribute("aria-hidden", "true");
    }
  }
  function loadPixel() {
    if (pixelLoaded) return;
    pixelLoaded = true;
    !(function (f, b, e, v, n, t, s) {
      if (f.fbq) return;
      n = f.fbq = function () {
        n.callMethod ? n.callMethod.apply(n, arguments) : n.queue.push(arguments);
      };
      if (!f._fbq) f._fbq = n;
      n.push = n;
      n.loaded = !0;
      n.version = "2.0";
      n.queue = [];
      t = b.createElement(e);
      t.async = !0;
      t.src = v;
      s = b.getElementsByTagName(e)[0];
      s.parentNode.insertBefore(t, s);
    })(window, document, "script", "https://connect.facebook.net/en_US/fbevents.js");
    fbq("init", PIXEL_ID);
    fbq("track", "PageView");
  }

  var consent = getConsent();
  if (consent === "accepted") {
    hideBanner();
    loadPixel();
  } else if (consent === "refused") {
    hideBanner();
  } else {
    showBanner();
  }

  if (btnAccept) {
    btnAccept.addEventListener("click", function () {
      setConsent("accepted");
      hideBanner();
      loadPixel();
    });
  }
  if (btnRefuse) {
    btnRefuse.addEventListener("click", function () {
      setConsent("refused");
      hideBanner();
    });
  }
  settingsLinks.forEach(function (el) {
    el.addEventListener("click", function (ev) {
      ev.preventDefault();
      showBanner();
    });
  });
})();
