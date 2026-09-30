/* MADD Tracking — front-end form. Talks only to this site's admin-ajax.php. */
(function () {
  "use strict";

  var config = window.MaddTrack || {};
  var STEPS = [
    { key: "booked", label: "สร้างรายการ" },
    { key: "not_picked_up", label: "รอรับพัสดุ" },
    { key: "in_transit", label: "ระหว่างขนส่ง" },
    { key: "delivered", label: "จัดส่งสำเร็จ" },
  ];

  function el(tag, attrs, children) {
    var node = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === "text") node.textContent = attrs[k];
      else node.setAttribute(k, attrs[k]);
    });
    (children || []).forEach(function (c) {
      if (c) node.appendChild(c);
    });
    return node;
  }

  function formatDate(value, withTime) {
    if (!value) return "";
    var d = new Date(value);
    if (isNaN(d.getTime())) return value;
    var opts = { day: "numeric", month: "short", year: "numeric" };
    if (withTime) {
      opts.hour = "2-digit";
      opts.minute = "2-digit";
    }
    return d.toLocaleString("th-TH", opts);
  }

  function progress(status) {
    if (status === "cancelled") return el("div", { class: "madd-track__cancelled", text: "Shipment นี้ถูกยกเลิกแล้ว" });
    var reached = { not_picked_up: 1, in_transit: 2, delivered: 3 }[status] || 1;
    return el(
      "ol",
      { class: "madd-track__steps" },
      STEPS.map(function (s, i) {
        return el("li", { class: "madd-track__step" + (i <= reached ? " is-done" : "") + (i === reached ? " is-current" : ""), text: s.label });
      })
    );
  }

  function render(container, data) {
    container.innerHTML = "";
    var facts = [
      ["Carrier", data.carrier + (data.service_name ? " · " + data.service_name : "")],
      ["เส้นทาง", (data.origin_country || "?") + " → " + (data.destination_country || "?")],
      ["วันที่จอง", formatDate(data.booked_at)],
      data.picked_up_at ? ["รับพัสดุเมื่อ", formatDate(data.picked_up_at, true)] : null,
      data.delivered_at ? ["ส่งถึงเมื่อ", formatDate(data.delivered_at, true)] : data.estimated_delivery ? ["คาดว่าจะถึง", formatDate(data.estimated_delivery)] : null,
    ].filter(Boolean);

    var events = (data.events || []).map(function (e) {
      return el("li", { class: "madd-track__event" }, [
        el("div", { class: "madd-track__event-time", text: [e.date, e.time ? e.time.slice(0, 5) : ""].join(" ").trim() }),
        el("div", { class: "madd-track__event-body" }, [el("div", { text: e.description || "" }), e.location ? el("div", { class: "madd-track__event-loc", text: e.location }) : null]),
      ]);
    });

    container.appendChild(
      el("div", { class: "madd-track__card" }, [
        el("div", { class: "madd-track__head" }, [
          el("div", { class: "madd-track__number", text: data.tracking_number }),
          el("div", { class: "madd-track__status madd-track__status--" + data.status, text: data.status_text }),
        ]),
        progress(data.status),
        el(
          "dl",
          { class: "madd-track__facts" },
          facts.reduce(function (acc, f) {
            acc.push(el("dt", { text: f[0] }), el("dd", { text: f[1] }));
            return acc;
          }, [])
        ),
        events.length
          ? el("div", {}, [el("h4", { class: "madd-track__title", text: "ประวัติการขนส่ง" }), el("ol", { class: "madd-track__events" }, events)])
          : data.status !== "cancelled"
          ? el("p", { class: "madd-rate__empty", text: "ยังไม่มีการสแกนจาก Carrier — ข้อมูลจะอัปเดตเมื่อ Courier รับพัสดุแล้ว" })
          : null,
      ])
    );
  }

  function init(root) {
    var form = root.querySelector(".madd-track__form");
    var result = root.querySelector(".madd-track__result");
    var error = root.querySelector(".madd-rate__error");
    var submit = form.querySelector(".madd-rate__submit");

    function showError(message) {
      error.textContent = message;
      error.hidden = !message;
    }

    function lookup() {
      showError("");
      var number = form.tracking_number.value.replace(/[^A-Za-z0-9]/g, "").toUpperCase();
      if (number.length < 8) return showError("กรุณากรอกเลข Tracking ให้ถูกต้อง");

      var body = new FormData();
      body.append("action", "madd_track");
      body.append("nonce", config.nonce);
      body.append("tracking_number", number);
      var turnstile = form.querySelector('[name="cf-turnstile-response"]');
      if (turnstile) body.append("turnstile", turnstile.value);

      submit.disabled = true;
      submit.textContent = "กำลังค้นหา...";
      result.innerHTML = '<div class="madd-rate__loading">กำลังดึงข้อมูลจาก Carrier...</div>';

      fetch(config.ajaxUrl, { method: "POST", body: body, credentials: "same-origin" })
        .then(function (res) {
          return res.json();
        })
        .then(function (json) {
          if (json && json.success) {
            render(result, json.data);
            if (window.history && window.history.replaceState) {
              var url = new URL(window.location.href);
              url.searchParams.set("tn", number);
              window.history.replaceState(null, "", url.toString());
            }
          } else {
            result.innerHTML = "";
            showError((json && json.data && json.data.message) || "ค้นหาไม่สำเร็จ กรุณาลองใหม่");
          }
        })
        .catch(function () {
          result.innerHTML = "";
          showError("เชื่อมต่อไม่สำเร็จ กรุณาลองใหม่");
        })
        .finally(function () {
          submit.disabled = false;
          submit.textContent = "ติดตามพัสดุ";
          if (window.turnstile) window.turnstile.reset();
        });
    }

    form.addEventListener("submit", function (e) {
      e.preventDefault();
      lookup();
    });
    // Direct link (?tn=...) — look it up straight away unless Turnstile must be solved first.
    if (form.tracking_number.value && !form.querySelector(".cf-turnstile")) lookup();
  }

  document.addEventListener("DOMContentLoaded", function () {
    Array.prototype.forEach.call(document.querySelectorAll(".madd-track"), init);
  });
})();
