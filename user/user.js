(function () {
  "use strict";

  function $(id) {
    return document.getElementById(id);
  }

  function api(path, options) {
    options = options || {};
    var method = options.method || "GET";
    var headers = { Accept: "application/json" };
    var init = { method: method, headers: headers, credentials: "same-origin" };
    if (options.body !== undefined) {
      headers["Content-Type"] = "application/json";
      init.body = JSON.stringify(options.body);
    }
    return fetch("api/" + path, init).then(function (r) {
      return r.json().then(function (data) {
        if (!r.ok) {
          throw new Error(data.error || "Erro");
        }
        return data;
      });
    });
  }

  function formatEuro(n) {
    try {
      return new Intl.NumberFormat("pt-PT", {
        style: "currency",
        currency: "EUR",
      }).format(n);
    } catch (e) {
      return String(n) + " €";
    }
  }

  function formatDate(iso) {
    if (!iso) return "—";
    var parts = String(iso).split("-");
    if (parts.length !== 3) return iso;
    return parts[2] + "/" + parts[1] + "/" + parts[0];
  }

  function showLogin() {
    $("login-screen").classList.remove("hidden");
    $("app").classList.add("hidden");
  }

  function showApp() {
    $("login-screen").classList.add("hidden");
    $("app").classList.remove("hidden");
  }

  function renderProfile(p) {
    if (!p) return;
    $("profile-card").classList.remove("hidden");
    $("p-nome").textContent = p.nome || "—";
    $("p-login").textContent = p.login ? "Login: " + p.login : "";
    $("p-email").textContent = p.email || "—";
    $("p-valor").textContent = formatEuro(p.valor_pago || 0);
    $("p-desde").textContent = formatDate(p.plano_valido_desde);
    $("p-ate").textContent = formatDate(p.plano_valido_ate);

    var pill = $("p-plano-status");
    pill.className = "status-pill";
    if (p.plano_ativo) {
      pill.textContent = "Plano ativo";
      pill.classList.add("ok");
    } else if (!p.ativo) {
      pill.textContent = "Conta desativada — contacta o suporte";
      pill.classList.add("bad");
    } else {
      pill.textContent = "Plano expirado em " + formatDate(p.plano_valido_ate);
      pill.classList.add("warn");
    }
  }

  function boot() {
    api("session.php")
      .then(function (data) {
        if (!data.database) {
          $("no-db").classList.remove("hidden");
          showApp();
          return;
        }
        if (data.authenticated && data.profile) {
          showApp();
          renderProfile(data.profile);
        } else {
          showLogin();
        }
      })
      .catch(function (err) {
        $("load-err").textContent = err.message || "Não foi possível ligar ao servidor.";
        $("load-err").classList.remove("hidden");
        showLogin();
      });
  }

  var loginForm = $("login-form");
  if (loginForm) {
    loginForm.addEventListener("submit", function (e) {
      e.preventDefault();
      var errEl = $("login-err");
      if (errEl) errEl.textContent = "";
      var login = $("login").value.trim();
      var password = $("password").value;
      api("login.php", { method: "POST", body: { login: login, password: password } })
        .then(function (data) {
          showApp();
          renderProfile(data.profile);
        })
        .catch(function (err) {
          if (errEl) errEl.textContent = err.message || "Não foi possível entrar.";
        });
    });
  }

  var btnLogout = $("btn-logout");
  if (btnLogout) {
    btnLogout.addEventListener("click", function () {
      api("logout.php", { method: "POST", body: {} })
        .then(function () {
          showLogin();
          $("profile-card").classList.add("hidden");
        })
        .catch(function () {
          showLogin();
        });
    });
  }

  boot();
})();
