(function ($) {
  function setDate() {
    var el = document.querySelector("[data-sr-date]");
    if (!el) return;
    try {
      var d = new Date();
      el.textContent = d.toLocaleDateString(undefined, {
        weekday: "long",
        day: "2-digit",
        month: "long",
      });
    } catch (e) {
      el.textContent = "";
    }
  }

  function activateTab(tab) {
    if (!tab) tab = "dashboard";

    // panels
    document.querySelectorAll("[data-sr-panel]").forEach(function (p) {
      p.classList.toggle("is-active", p.getAttribute("data-sr-panel") === tab);
    });

    // nav
    document.querySelectorAll("[data-sr-tab]").forEach(function (a) {
      a.classList.toggle("is-active", a.getAttribute("data-sr-tab") === tab);
    });
  }

  function initTabs() {
    var navLinks = document.querySelectorAll("[data-sr-tab]");
    if (!navLinks.length) return;

    navLinks.forEach(function (a) {
      a.addEventListener("click", function (e) {
        e.preventDefault();
        var tab = a.getAttribute("data-sr-tab");
        window.location.hash = tab;
        activateTab(tab);
      });
    });

    var initial = (window.location.hash || "").replace("#", "") || "dashboard";
    activateTab(initial);

    window.addEventListener("hashchange", function () {
      var tab = (window.location.hash || "").replace("#", "") || "dashboard";
      activateTab(tab);
    });
  }

  function initEditCards() {
    // enable edit mode for a card
    function setCardEditing(card, editing) {
      card.classList.toggle("is-editing", !!editing);
      card.querySelectorAll("input").forEach(function (inp) {
        // never enable read-only fields without name
        if (!inp.getAttribute("name")) return;
        inp.disabled = !editing;
      });

      // store original values on first edit
      if (editing && !card.__srSnapshot) {
        var snap = {};
        card.querySelectorAll("input[name]").forEach(function (inp) {
          snap[inp.name] = inp.value;
        });
        card.__srSnapshot = snap;
      }
    }

    document
      .querySelectorAll(".sr-editBtn[data-sr-edit]")
      .forEach(function (btn) {
        btn.addEventListener("click", function () {
          var key = btn.getAttribute("data-sr-edit");
          var card = document.querySelector(
            '.sr-cardBlock[data-sr-card="' + key + '"]'
          );
          if (!card) return;
          setCardEditing(card, true);
        });
      });

    document
      .querySelectorAll(".sr-cancelBtn[data-sr-cancel]")
      .forEach(function (btn) {
        btn.addEventListener("click", function () {
          var key = btn.getAttribute("data-sr-cancel");
          var card = document.querySelector(
            '.sr-cardBlock[data-sr-card="' + key + '"]'
          );
          if (!card) return;

          // restore snapshot
          if (card.__srSnapshot) {
            Object.keys(card.__srSnapshot).forEach(function (name) {
              var inp = card.querySelector('input[name="' + name + '"]');
              if (inp) inp.value = card.__srSnapshot[name];
            });
          }

          setCardEditing(card, false);
        });
      });

    // Save buttons are submit buttons (form submit) - we just ensure card is in editing state
    document
      .querySelectorAll(".sr-saveBtn[data-sr-save]")
      .forEach(function (btn) {
        btn.addEventListener("click", function () {
          var key = btn.getAttribute("data-sr-save");
          var card = document.querySelector(
            '.sr-cardBlock[data-sr-card="' + key + '"]'
          );
          if (!card) return;
          // keep enabled so values submit
          setCardEditing(card, true);
        });
      });
  }

  $(function () {
    setDate();
    initTabs();
    initEditCards();
  });
})(jQuery);
