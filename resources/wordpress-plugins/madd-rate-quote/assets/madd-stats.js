/* MADD [madd_stats] — live usage counter (quotes checked, parcels tracked, visitors). */
(function () {
  "use strict";

  var config = window.MaddStats || {};
  var LABELS = {
    en: { quotes: "quotes checked", tracked: "parcels tracked", visitors: "visitors", views: "page views" },
    th: { quotes: "ครั้งที่เช็คราคา", tracked: "ครั้งที่ติดตามพัสดุ", visitors: "ผู้เข้าชม", views: "ครั้งที่เข้าชม" },
  };
  var ICONS = {
    quotes: '<path d="M4 4h16v16H4z"/><path d="M8 9h8M8 13h5M8 17h3"/>',
    tracked: '<path d="M21 8l-9-5-9 5 9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/>',
    visitors: '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M21.5 20a6.5 6.5 0 0 0-4.5-6.2"/>',
    views: '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
  };

  function countUp(node, to, locale) {
    var reduce = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    if (reduce || to < 10) {
      node.textContent = to.toLocaleString(locale);
      return;
    }
    var start = null;
    var duration = 1400;
    function frame(ts) {
      if (!start) start = ts;
      var p = Math.min(1, (ts - start) / duration);
      var eased = 1 - Math.pow(1 - p, 3);
      node.textContent = Math.round(to * eased).toLocaleString(locale);
      if (p < 1) window.requestAnimationFrame(frame);
    }
    window.requestAnimationFrame(frame);
  }

  function render(root, data) {
    var lang = root.getAttribute("data-lang") === "th" ? "th" : "en";
    var locale = lang === "th" ? "th-TH" : "en-US";
    var keys = (root.getAttribute("data-show") || "quotes,tracked,visitors").split(",").map(function (k) {
      return k.trim();
    }).filter(function (k) {
      return LABELS.en[k] && typeof data[k] === "number";
    });
    var min = parseInt(root.getAttribute("data-min") || "0", 10) || 0;
    keys = keys.filter(function (k) {
      return data[k] >= min;
    });
    if (!keys.length) return;

    var targets = [];
    keys.forEach(function (k) {
      var item = document.createElement("div");
      item.className = "madd-stats__item";
      item.innerHTML =
        '<span class="madd-stats__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' +
        ICONS[k] +
        '</svg></span><span class="madd-stats__text"><b class="madd-stats__value">0</b><span class="madd-stats__label"></span></span>';
      item.querySelector(".madd-stats__label").textContent = LABELS[lang][k];
      root.appendChild(item);
      targets.push([item.querySelector(".madd-stats__value"), data[k]]);
    });
    root.hidden = false;

    var run = function () {
      targets.forEach(function (t) {
        countUp(t[0], t[1], locale);
      });
    };
    if ("IntersectionObserver" in window) {
      var io = new IntersectionObserver(function (entries) {
        if (entries.some(function (e) { return e.isIntersecting; })) {
          io.disconnect();
          run();
        }
      });
      io.observe(root);
    } else {
      run();
    }
  }

  document.addEventListener("DOMContentLoaded", function () {
    var roots = document.querySelectorAll(".madd-stats");
    if (!roots.length || !config.apiUrl) return;
    fetch(config.apiUrl + "/public/v1/web/stats", { credentials: "omit" })
      .then(function (res) {
        return res.ok ? res.json() : null;
      })
      .then(function (data) {
        if (!data) return;
        Array.prototype.forEach.call(roots, function (root) {
          render(root, data);
        });
      })
      .catch(function () {
        /* counter is decorative — stay hidden */
      });
  });
})();
