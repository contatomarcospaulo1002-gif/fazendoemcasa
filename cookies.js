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

  function isTrackCommand(cmd) {
    return cmd === "track" || cmd === "trackCustom" || cmd === "trackSingle" || cmd === "trackSingleCustom";
  }

  function guardFbq() {
    if (typeof window.fbq !== "function") return;
    if (window.fbq.__consentGuarded) return;
    var orig = window.fbq;
    function guarded() {
      var cmd = arguments[0];
      if (!trackingAllowed && isTrackCommand(cmd)) {
        return;
      }
      if (!trackingAllowed && cmd === "consent" && arguments[1] === "grant") {
        return;
      }
      return orig.apply(this, arguments);
    }
    var k;
    for (k in orig) {
      if (Object.prototype.hasOwnProperty.call(orig, k)) {
        guarded[k] = orig[k];
      }
    }
    guarded.callMethod = function () {
      return guarded.apply(null, arguments);
    };
    guarded.queue = orig.queue || [];
    guarded.push = guarded;
    guarded.loaded = orig.loaded;
    guarded.version = orig.version;
    guarded.__consentGuarded = true;
    window.fbq = guarded;
    if (window._fbq) window._fbq = guarded;
  }

  function revokeTracking() {
    trackingAllowed = false;
    try {
      if (typeof fbq === "function") {
        // ensure guard is on so later auto track is dropped
        guardFbq();
        fbq("consent", "revoke");
      }
    } catch (e) {}
    deleteMetaCookies();
  }

  function loadPixel() {
    trackingAllowed = true;
    if (pixelLoaded) {
      try {
        guardFbq();
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
      t.onload = function () {
        // Guard AFTER Meta has replaced/enhanced the stub
        guardFbq();
      };
      s = b.getElementsByTagName(e)[0];
      s.parentNode.insertBefore(t, s);
    })(window, document, "script", "https://connect.facebook.net/en_US/fbevents.js");
    fbq("set", "autoConfig", false, PIXEL_ID);
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

  function onRefuseCapture(ev) {
    trackingAllowed = false;
    setConsent("refused");
    revokeTracking();
    hideBanner();
    if (ev && ev.stopPropagation) ev.stopPropagation();
  }
  if (btnRefuse) {
    btnRefuse.addEventListener("click", onRefuseCapture, true);
  }
  if (banner) {
    banner.addEventListener(
      "click",
      function (ev) {
        var t = ev.target;
        if (t && (t.id === "cookie-refuse" || (t.closest && t.closest("#cookie-refuse")))) {
          onRefuseCapture(ev);
        }
      },
      true
    );
  }
  settingsLinks.forEach(function (el) {
    el.addEventListener(
      "click",
      function (ev) {
        ev.preventDefault();
        showBanner();
      },
      true
    );
  });

  document.querySelectorAll("a[data-checkout]").forEach(function (link) {
    link.addEventListener("click", function (ev) {
      if (!window.hasTrackingConsent || !window.hasTrackingConsent()) {
        return;
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
      setTimeout(go, 500);
    });
  });
})();
