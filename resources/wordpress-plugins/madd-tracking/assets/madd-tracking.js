/* MADD Tracking — front-end. Talks only to this site's admin-ajax.php. */
(function () {
  "use strict";

  var config = window.MaddTracking || {};
  var STEPS = ["สร้างรายการ", "รอรับพัสดุ", "ระหว่างขนส่ง", "จัดส่งสำเร็จ"];
  var STEP_INDEX = { not_picked_up: 1, in_transit: 2, delivered: 3 };

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

  function steps(status) {
    if (status === "cancelled") return el("div", { class: "madd-tracking__cancelled", text: "Shipment นี้ถูกยกเลิกแล้ว" });
    var reached = STEP_INDEX[status] || 1;
    return el(
      "ol",
      { class: "madd-tracking__steps" },
      STEPS.map(function (label, i) {
        return el("li", { class: "madd-tracking__step" + (i <= reached ? " is-done" : "") + (i === reached ? " is-current" : ""), text: label });
      })
    );
  }

  function render(container, data) {
    container.innerHTML = "";
    var facts = [
      ["Carrier", data.carrier + (data.service_name ? " · " + data.service_name : "")],
      ["เส้นทาง", (data.origin_country || "?") + " → " + (data.destination_country || "?")],
      data.booked_at ? ["วันที่จอง", formatDate(data.booked_at)] : null,
      data.picked_up_at ? ["รับพัสดุเมื่อ", formatDate(data.picked_up_at, true)] : null,
      data.delivered_at
        ? ["ส่งถึงเมื่อ", formatDate(data.delivered_at, true)]
        : data.estimated_delivery && data.status !== "cancelled"
        ? ["คาดว่าจะถึง", formatDate(data.estimated_delivery)]
        : null,
    ].filter(Boolean);

    var events = (data.events || []).map(function (e) {
      return el("li", { class: "madd-tracking__event" }, [
        el("div", { class: "madd-tracking__event-time", text: [formatDate(e.date), e.time ? e.time.slice(0, 5) : ""].join(" ").trim() }),
        el("div", { class: "madd-tracking__event-body" }, [
          el("div", { text: e.description || "" }),
          e.location ? el("div", { class: "madd-tracking__event-loc", text: e.location }) : null,
        ]),
      ]);
    });

    container.appendChild(
      el("div", { class: "madd-tracking__card" }, [
        el("div", { class: "madd-tracking__head" }, [
          el("div", {}, [el("div", { class: "madd-tracking__caption", text: "เลข Tracking" }), el("div", { class: "madd-tracking__number", text: data.tracking_number })]),
          el("div", { class: "madd-tracking__status madd-tracking__status--" + data.status, text: data.status_text }),
        ]),
        steps(data.status),
        el(
          "dl",
          { class: "madd-tracking__facts" },
          facts.reduce(function (acc, f) {
            acc.push(el("dt", { text: f[0] }), el("dd", { text: f[1] }));
            return acc;
          }, [])
        ),
        events.length
          ? el("div", {}, [el("h4", { class: "madd-tracking__title", text: "ประวัติการขนส่ง" }), el("ol", { class: "madd-tracking__events" }, events)])
          : data.status !== "cancelled"
          ? el("p", { class: "madd-tracking__empty", text: "ยังไม่มีการสแกนจาก Carrier — ข้อมูลจะอัปเดตเมื่อ Courier รับพัสดุแล้ว" })
          : null,
        config.contactUrl ? el("a", { class: "madd-tracking__contact", href: config.contactUrl, target: "_blank", rel: "noopener", text: config.contactLabel || "ติดต่อเรา" }) : null,
      ])
    );
  }

  function init(root) {
    var form = root.querySelector(".madd-tracking__form");
    var input = form.querySelector('[name="tracking_number"]');
    var result = root.querySelector(".madd-tracking__result");
    var error = root.querySelector(".madd-tracking__error");
    var submit = form.querySelector(".madd-tracking__submit");

    function showError(message) {
      error.textContent = message;
      error.hidden = !message;
    }

    function lookup() {
      showError("");
      var number = input.value.replace(/[^A-Za-z0-9]/g, "").toUpperCase();
      if (number.length < 8) return showError("กรุณากรอกเลข Tracking ให้ถูกต้อง");

      var body = new FormData();
      body.append("action", config.action);
      body.append("nonce", config.nonce);
      body.append("tracking_number", number);
      var turnstile = form.querySelector('[name="cf-turnstile-response"]');
      if (turnstile) body.append("turnstile", turnstile.value);

      submit.disabled = true;
      submit.textContent = "กำลังค้นหา...";
      result.innerHTML = '<div class="madd-tracking__loading">กำลังดึงข้อมูลจาก Carrier...</div>';

      fetch(config.ajaxUrl, { method: "POST", body: body, credentials: "same-origin" })
        .then(function (res) {
          return res.json();
        })
        .then(function (json) {
          if (json && json.success) {
            render(result, json.data);
            try {
              var url = new URL(window.location.href);
              url.searchParams.set("tn", number);
              window.history.replaceState(null, "", url.toString());
            } catch (e) {
              /* old browser — skip updating the address bar */
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
          submit.textContent = "ติดตาม";
          if (window.turnstile) window.turnstile.reset();
        });
    }

    form.addEventListener("submit", function (e) {
      e.preventDefault();
      lookup();
    });
    // Direct link (?tn=...) — search straight away unless Turnstile has to be solved first.
    if (input.value && !form.querySelector(".cf-turnstile")) lookup();
  }

  document.addEventListener("DOMContentLoaded", function () {
    Array.prototype.forEach.call(document.querySelectorAll(".madd-tracking"), init);
  });
})();
