(function () {
  "use strict";

  function pad2(n) {
    n = Number(n) || 0;
    return n < 10 ? "0" + n : "" + n;
  }

  function formatMMSS(seconds) {
    seconds = Math.max(0, Math.floor(seconds || 0));
    var m = Math.floor(seconds / 60);
    var s = seconds % 60;
    return pad2(m) + ":" + pad2(s);
  }

  // Minimal closest() fallback
  function closestEl(el, selector) {
    if (!el) return null;
    if (el.closest) return el.closest(selector);

    // fallback
    var node = el;
    while (node && node.nodeType === 1) {
      if (matchesSel(node, selector)) return node;
      node = node.parentElement;
    }
    return null;
  }

  function matchesSel(el, selector) {
    if (!el || el.nodeType !== 1) return false;
    var p = Element.prototype;
    var fn =
      p.matches ||
      p.matchesSelector ||
      p.msMatchesSelector ||
      p.webkitMatchesSelector;
    if (fn) return fn.call(el, selector);

    // ultra fallback
    var nodes = (el.document || el.ownerDocument).querySelectorAll(selector);
    for (var i = 0; i < nodes.length; i++) {
      if (nodes[i] === el) return true;
    }
    return false;
  }

  // TIMER (supports multiple)
  function initTimer() {
    var wraps = document.querySelectorAll(".spg-ty-top-timer");
    if (!wraps || !wraps.length) return;

    for (var i = 0; i < wraps.length; i++) {
      (function (wrap) {
        var out = wrap.querySelector(".spg-ty-timer-text");
        var expires = parseInt(wrap.getAttribute("data-expires") || "0", 10);
        if (!out || !expires) return;

        var t = null;

        function tick() {
          var now = Math.floor(Date.now() / 1000);
          var left = expires - now;

          out.textContent = formatMMSS(left);

          if (left <= 0) {
            out.textContent = "00:00";
            wrap.classList.add("is-expired");
            if (t) clearInterval(t);
            t = null;
          }
        }

        tick();
        t = setInterval(tick, 1000);
      })(wraps[i]);
    }
  }

  // COPY
  function initCopy() {
    function legacyCopy(text) {
      var ta = document.createElement("textarea");
      ta.value = text;

      ta.setAttribute("readonly", "");
      ta.style.position = "fixed";
      ta.style.top = "0";
      ta.style.left = "-9999px";
      ta.style.opacity = "0";

      document.body.appendChild(ta);

      // iOS-ish friendliness
      ta.focus();
      ta.select();
      ta.setSelectionRange(0, ta.value.length);

      try {
        document.execCommand("copy");
      } catch (e) {}

      document.body.removeChild(ta);
    }

    function flash(okEl) {
      if (!okEl) return;
      okEl.classList.add("is-visible");
      setTimeout(function () {
        okEl.classList.remove("is-visible");
      }, 900);
    }

    document.addEventListener("click", function (e) {
      var btn = closestEl(e.target, ".spg-copy-address-btn");
      if (!btn) return;

      e.preventDefault();

      var text = (btn.getAttribute("data-copy") || "").trim();
      if (!text) return;

      var ok = btn.parentElement
        ? btn.parentElement.querySelector(".spg-copy-ok")
        : null;

      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(
          function () {
            flash(ok);
          },
          function () {
            legacyCopy(text);
            flash(ok);
          }
        );
      } else {
        legacyCopy(text);
        flash(ok);
      }
    });
  }

  document.addEventListener("DOMContentLoaded", function () {
    // Only run on our thank you template
    if (!document.querySelector(".spg-ty")) return;

    initTimer();
    initCopy();
  });
})();