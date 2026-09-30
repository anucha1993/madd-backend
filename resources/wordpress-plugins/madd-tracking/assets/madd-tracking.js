/* MADD Tracking — front-end. Talks only to this site's admin-ajax.php. */
(function () {
  "use strict";

  var config = window.MaddTracking || {};

  var I18N = {
    th: {
      locale: "th-TH",
      steps: ["สร้างรายการ", "รอรับพัสดุ", "ระหว่างขนส่ง", "จัดส่งสำเร็จ"],
      status: { not_picked_up: "รอ Courier เข้ารับพัสดุ", in_transit: "อยู่ระหว่างขนส่ง", delivered: "จัดส่งสำเร็จ", cancelled: "ยกเลิกการจัดส่ง" },
      caption: "เลข Tracking",
      carrier: "Carrier",
      route: "เส้นทาง",
      booked: "วันที่จอง",
      pickedUp: "รับพัสดุเมื่อ",
      delivered: "ส่งถึงเมื่อ",
      eta: "คาดว่าจะถึง",
      history: "ประวัติการขนส่ง",
      noScans: "ยังไม่มีการสแกนจาก Carrier — ข้อมูลจะอัปเดตเมื่อ Courier รับพัสดุแล้ว",
      cancelled: "Shipment นี้ถูกยกเลิกแล้ว",
      invalid: "กรุณากรอกเลข Tracking ให้ถูกต้อง",
      notFound: "ไม่พบเลข Tracking นี้ในระบบ กรุณาตรวจสอบเลขอีกครั้ง",
      rateLimited: "ค้นหาบ่อยเกินไป กรุณารอสักครู่แล้วลองใหม่",
      searching: "กำลังค้นหา...",
      loading: "กำลังดึงข้อมูลจาก Carrier...",
      button: "ติดตาม",
      failed: "ค้นหาไม่สำเร็จ กรุณาลองใหม่",
      network: "เชื่อมต่อไม่สำเร็จ กรุณาลองใหม่",
      contact: "ติดต่อเรา",
    },
    en: {
      locale: "en-GB",
      steps: ["Label created", "Awaiting pickup", "In transit", "Delivered"],
      status: { not_picked_up: "Awaiting pickup", in_transit: "In transit", delivered: "Delivered", cancelled: "Cancelled" },
      caption: "Tracking number",
      carrier: "Carrier",
      route: "Route",
      booked: "Booked on",
      pickedUp: "Picked up",
      delivered: "Delivered",
      eta: "Estimated delivery",
      history: "Shipment history",
      noScans: "No carrier scans yet — updates will appear once the courier collects the parcel.",
      cancelled: "This shipment has been cancelled.",
      invalid: "Please enter a valid tracking number.",
      notFound: "We couldn't find this tracking number. Please check it and try again.",
      rateLimited: "Too many searches. Please wait a moment and try again.",
      searching: "Searching...",
      loading: "Fetching the latest status from the carrier...",
      button: "Track",
      failed: "We couldn't find that shipment. Please try again.",
      network: "Connection failed. Please try again.",
      contact: "Contact us",
    },
  };
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

  function init(root) {
    var lang = root.getAttribute("data-lang") === "en" ? "en" : "th";
    var t = I18N[lang];
    var form = root.querySelector(".madd-tracking__form");
    var input = form.querySelector('[name="tracking_number"]');
    var result = root.querySelector(".madd-tracking__result");
    var error = root.querySelector(".madd-tracking__error");
    var submit = form.querySelector(".madd-tracking__submit");

    function formatDate(value, withTime) {
      if (!value) return "";
      var d = new Date(value);
      if (isNaN(d.getTime())) return value;
      var opts = { day: "numeric", month: "short", year: "numeric" };
      if (withTime) {
        opts.hour = "2-digit";
        opts.minute = "2-digit";
      }
      return d.toLocaleString(t.locale, opts);
    }

    function steps(status) {
      if (status === "cancelled") return el("div", { class: "madd-tracking__cancelled", text: t.cancelled });
      var reached = STEP_INDEX[status] || 1;
      return el(
        "ol",
        { class: "madd-tracking__steps" },
        t.steps.map(function (label, i) {
          return el("li", { class: "madd-tracking__step" + (i <= reached ? " is-done" : "") + (i === reached ? " is-current" : ""), text: label });
        })
      );
    }

    function render(data) {
      result.innerHTML = "";
      var facts = [
        [t.carrier, data.carrier + (data.service_name ? " · " + data.service_name : "")],
        [t.route, (data.origin_country || "?") + " → " + (data.destination_country || "?")],
        data.booked_at ? [t.booked, formatDate(data.booked_at)] : null,
        data.picked_up_at ? [t.pickedUp, formatDate(data.picked_up_at, true)] : null,
        data.delivered_at
          ? [t.delivered, formatDate(data.delivered_at, true)]
          : data.estimated_delivery && data.status !== "cancelled"
          ? [t.eta, formatDate(data.estimated_delivery)]
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

      result.appendChild(
        el("div", { class: "madd-tracking__card" }, [
          el("div", { class: "madd-tracking__head" }, [
            el("div", {}, [el("div", { class: "madd-tracking__caption", text: t.caption }), el("div", { class: "madd-tracking__number", text: data.tracking_number })]),
            el("div", { class: "madd-tracking__status madd-tracking__status--" + data.status, text: t.status[data.status] || data.status_text }),
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
            ? el("div", {}, [el("h4", { class: "madd-tracking__title", text: t.history }), el("ol", { class: "madd-tracking__events" }, events)])
            : data.status !== "cancelled"
            ? el("p", { class: "madd-tracking__empty", text: t.noScans })
            : null,
          config.contactUrl ? el("a", { class: "madd-tracking__contact", href: config.contactUrl, target: "_blank", rel: "noopener", text: config.contactLabel || t.contact }) : null,
        ])
      );
    }

    function showError(message) {
      error.textContent = message;
      error.hidden = !message;
    }

    function lookup() {
      showError("");
      var number = input.value.replace(/[^A-Za-z0-9]/g, "").toUpperCase();
      if (number.length < 8) return showError(t.invalid);

      var body = new FormData();
      body.append("action", config.action);
      body.append("nonce", config.nonce);
      body.append("lang", lang);
      body.append("tracking_number", number);
      var turnstile = form.querySelector('[name="cf-turnstile-response"]');
      if (turnstile) body.append("turnstile", turnstile.value);

      submit.disabled = true;
      submit.textContent = t.searching;
      result.innerHTML = "";
      result.appendChild(el("div", { class: "madd-tracking__loading", text: t.loading }));

      // 1) Straight from the visitor's browser to MADD (the site must be listed under "browser
      //    origins" on the API key) — avoids routing every look-up through the web server's IP.
      // 2) Anything else (origin not registered, network/CORS error, old MADD) falls back to
      //    this site's admin-ajax, which calls MADD server-to-server with the API key.
      var direct = config.apiUrl
        ? fetch(config.apiUrl + "/public/v1/web/tracking/" + encodeURIComponent(number), { method: "GET", mode: "cors", credentials: "omit" }).then(function (res) {
            if (res.ok) return res.json().then(function (data) { return { success: true, data: data }; });
            if (res.status === 404) return { success: false, data: { message: t.notFound } };
            if (res.status === 429) return { success: false, data: { message: t.rateLimited } };
            if (res.status === 422) return { success: false, data: { message: t.invalid } };
            throw new Error("fallback");
          })
        : Promise.reject(new Error("fallback"));

      direct
        .catch(function () {
          return fetch(config.ajaxUrl, { method: "POST", body: body, credentials: "same-origin" }).then(function (res) {
            return res.json();
          });
        })
        .then(function (json) {
          if (json && json.success) {
            render(json.data);
            try {
              var url = new URL(window.location.href);
              url.searchParams.set("tn", number);
              window.history.replaceState(null, "", url.toString());
            } catch (e) {
              /* old browser — skip updating the address bar */
            }
          } else {
            result.innerHTML = "";
            showError((json && json.data && json.data.message) || t.failed);
          }
        })
        .catch(function () {
          result.innerHTML = "";
          showError(t.network);
        })
        .finally(function () {
          submit.disabled = false;
          submit.textContent = t.button;
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
