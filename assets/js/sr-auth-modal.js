(function () {
  function qs(sel, root) {
    return (root || document).querySelector(sel);
  }
  function qsa(sel, root) {
    return Array.from((root || document).querySelectorAll(sel));
  }

  function openModal(tab) {
    var wrap = qs("#srh-auth");
    if (!wrap) return;
    wrap.classList.add("is-open");
    wrap.setAttribute("aria-hidden", "false");
    setTab(tab || "login");
    document.documentElement.style.overflow = "hidden";
  }

  function closeModal() {
    var wrap = qs("#srh-auth");
    if (!wrap) return;
    wrap.classList.remove("is-open");
    wrap.setAttribute("aria-hidden", "true");
    document.documentElement.style.overflow = "";
  }

  function setTab(tab) {
    var wrap = qs("#srh-auth");
    if (!wrap) return;

    qsa("[data-srh-tab]", wrap).forEach(function (btn) {
      var on = btn.getAttribute("data-srh-tab") === tab;
      btn.classList.toggle("is-active", on);
      btn.setAttribute("aria-selected", on ? "true" : "false");
    });
    qsa("[data-srh-pane]", wrap).forEach(function (p) {
      p.classList.toggle("is-active", p.getAttribute("data-srh-pane") === tab);
    });

    // Back button behavior:
    // - If on signup -> back goes to login
    // - If on login -> back closes modal
    var back = qs("[data-srh-back]", wrap);
    if (back)
      back.dataset.srhBackMode = tab === "signup" ? "to-login" : "close";
  }

  function initTabs() {
    var wrap = qs("#srh-auth");
    if (!wrap) return;

    qsa("[data-srh-tab]", wrap).forEach(function (btn) {
      btn.addEventListener("click", function () {
        setTab(btn.getAttribute("data-srh-tab"));
      });
    });

    // Close controls
    qsa("[data-srh-close]", wrap).forEach(function (el) {
      el.addEventListener("click", closeModal);
    });

    // Back button
    var back = qs("[data-srh-back]", wrap);
    if (back) {
      back.addEventListener("click", function () {
        if (back.dataset.srhBackMode === "to-login") setTab("login");
        else closeModal();
      });
    }

    // ESC closes
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && wrap.classList.contains("is-open"))
        closeModal();
    });

    // Toggle password
    qsa("[data-srh-togglepw]", wrap).forEach(function (btn) {
      btn.addEventListener("click", function () {
        var inp = btn.closest(".srh__pw")?.querySelector("input");
        if (!inp) return;
        inp.type = inp.type === "password" ? "text" : "password";
      });
    });

    // Auto-open when error/success exists in URL (from form redirect)
    var url = new URL(window.location.href);
    if (url.searchParams.get("sr_auth") === "1") {
      openModal(url.searchParams.get("sr_tab") || "login");
    }
  }

  // IMPORTANT: Override SmartMag header auth behavior
  function initHeaderIntercept() {
    document.addEventListener(
      "click",
      function (e) {
        var a = e.target.closest("a.auth-link");
        if (!a) return;

        // stop theme popup + stop logout
        e.preventDefault();
        e.stopPropagation();
        if (e.stopImmediatePropagation) e.stopImmediatePropagation();

        // logged-in -> go to profile (instead of logout)
        if (document.body.classList.contains("logged-in")) {
          if (window.SR_AUTH_MODAL && SR_AUTH_MODAL.profileUrl) {
            window.location.href = SR_AUTH_MODAL.profileUrl;
            return;
          }
          return;
        }

        // logged-out -> open modal
        openModal("login");
      },
      true
    );
  }

  document.addEventListener("DOMContentLoaded", function () {
    initTabs();
    initHeaderIntercept();
  });

  // Expose for debugging
  window.SRH_OPEN_AUTH = openModal;
  window.SRH_CLOSE_AUTH = closeModal;
})();
