(function () {
  var KEY = "cookie_consent";
  var PIXEL_ID = "2111475296169479";
  var banner = document.getElementById("cookie-banner");
  var btnAccept = document.getElementById("cookie-accept");
  var btnRefuse = document.getElementById("cookie-refuse");
  var settingsLinks = document.querySelectorAll("a[data-cookie-settings]");
  var pixelLoaded = false;
  var trackingAllowed = false;

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

  function deleteMetaCookies() {
    var names = ["_fbp", "_fbc"];
    var hostname = location.hostname;
    var domains = ["", hostname];
    if (hostname.indexOf(".") !== -1) {
      domains.push("." + hostname);
      var parts = hostname.split(".");
      if (parts.length >= 2) {
        domains.push("." + parts.slice(-2).join("."));
      }
    }
    names.forEach(function (name) {
      domains.forEach(function (domain) {
        var base = name + "=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/; Max-Age=0";
        if (domain) {
          document.cookie = base + "; domain=" + domain;
        } else {
          document.cookie = base;
        }
      });
    });
  }

  function revokeTracking() {
    trackingAllowed = false;
    try {
      if (typeof fbq === "function") {
        fbq("consent", "revoke");
      }
    } catch (e) {}
    deleteMetaCookies();
  }

  function loadPixel() {
    trackingAllowed = true;
    if (pixelLoaded) {
      try {
        if (typeof fbq === "function") {
          fbq("consent", "grant");
          fbq("track", "PageView");
        }
      } catch (e) {}
      return;
    }
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

  window.hasTrackingConsent = function () {
    return trackingAllowed && getConsent() === "accepted";
  };

  var consent = getConsent();
  if (consent === "accepted") {
    hideBanner();
    loadPixel();
  } else if (consent === "refused") {
    hideBanner();
    trackingAllowed = false;
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
      revokeTracking();
    });
  }
  settingsLinks.forEach(function (el) {
    el.addEventListener("click", function (ev) {
      ev.preventDefault();
      showBanner();
    });
  });

  // InitiateCheckout on data-checkout clicks (only with consent)
  document.querySelectorAll("a[data-checkout]").forEach(function (link) {
    link.addEventListener("click", function (ev) {
      if (!window.hasTrackingConsent || !window.hasTrackingConsent()) {
        return; // navigate normally, no pixel
      }
      if (typeof fbq !== "function") {
        return;
      }
      ev.preventDefault();
      var href = link.href;
      var navigated = false;
      function go() {
        if (navigated) return;
        navigated = true;
        window.location.href = href;
      }
      try {
        fbq("track", "InitiateCheckout");
      } catch (e) {}
      // Prefer sendBeacon-style delay so /tr can leave before navigation
      setTimeout(go, 500);
    });
  });
})();
