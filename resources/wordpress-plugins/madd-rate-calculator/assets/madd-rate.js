/* MADD Rate Calculator — front-end form. Talks only to this site's admin-ajax.php. */
(function () {
  "use strict";

  var config = window.MaddRate || {};

  function el(tag, attrs, children) {
    var node = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === "text") node.textContent = attrs[k];
      else node.setAttribute(k, attrs[k]);
    });
    (children || []).forEach(function (c) {
      node.appendChild(c);
    });
    return node;
  }

  function money(value, currency) {
    return Number(value).toLocaleString("th-TH", { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + " " + (currency === "THB" ? "บาท" : currency);
  }

  function numberInput(name, label, placeholder, step) {
    return el("label", { class: "madd-rate__field madd-rate__field--xs" }, [
      el("span", { text: label }),
      el("input", { name: name, type: "number", min: step === "1" ? "1" : "0.1", step: step || "0.1", placeholder: placeholder || "", inputmode: "decimal" }),
    ]);
  }

  function packageRow(isFirst) {
    var row = el("div", { class: "madd-rate__package" }, [
      numberInput("weight", "น้ำหนัก (กก.) *", "เช่น 2.5"),
      numberInput("length", "ยาว (ซม.)", ""),
      numberInput("width", "กว้าง (ซม.)", ""),
      numberInput("height", "สูง (ซม.)", ""),
      numberInput("quantity", "จำนวน", "1", "1"),
    ]);
    if (!isFirst) {
      var remove = el("button", { type: "button", class: "madd-rate__remove", "aria-label": "ลบกล่อง", text: "×" });
      remove.addEventListener("click", function () {
        row.remove();
      });
      row.appendChild(remove);
    }
    return row;
  }

  function renderResults(container, data) {
    container.innerHTML = "";
    var options = data.options || [];
    if (!options.length) {
      container.appendChild(el("p", { class: "madd-rate__empty", text: "ไม่พบบริการสำหรับปลายทางนี้ กรุณาติดต่อเจ้าหน้าที่" }));
      return;
    }
    var list = el("div", { class: "madd-rate__list" });
    options.forEach(function (o, i) {
      var meta = [];
      if (o.transit_days) meta.push("ประมาณ " + o.transit_days + " วันทำการ");
      if (o.estimated_delivery) meta.push("ถึงประมาณ " + new Date(o.estimated_delivery).toLocaleDateString("th-TH"));
      list.appendChild(
        el("div", { class: "madd-rate__card" + (i === 0 ? " madd-rate__card--best" : "") }, [
          el("div", { class: "madd-rate__card-head" }, [
            el("span", { class: "madd-rate__carrier madd-rate__carrier--" + String(o.carrier).toLowerCase(), text: o.carrier }),
            i === 0 ? el("span", { class: "madd-rate__badge", text: "ราคาดีที่สุด" }) : el("span"),
          ]),
          el("div", { class: "madd-rate__service", text: o.service_name }),
          el("div", { class: "madd-rate__price", text: money(o.price, o.currency) }),
          el("div", { class: "madd-rate__meta", text: meta.join(" · ") }),
        ])
      );
    });
    container.appendChild(list);
    if (data.disclaimer) container.appendChild(el("p", { class: "madd-rate__disclaimer", text: "* " + data.disclaimer }));
    if (config.contactUrl) {
      container.appendChild(el("a", { class: "madd-rate__contact", href: config.contactUrl, target: "_blank", rel: "noopener", text: config.contactLabel || "ติดต่อเรา" }));
    }
  }

  function init(root) {
    var form = root.querySelector(".madd-rate__form");
    var packages = root.querySelector(".madd-rate__packages");
    var results = root.querySelector(".madd-rate__results");
    var error = root.querySelector(".madd-rate__error");
    var submit = root.querySelector(".madd-rate__submit");

    packages.appendChild(packageRow(true));
    root.querySelector(".madd-rate__add").addEventListener("click", function () {
      if (packages.children.length < 20) packages.appendChild(packageRow(false));
    });

    function showError(message) {
      error.textContent = message;
      error.hidden = !message;
    }

    form.addEventListener("submit", function (e) {
      e.preventDefault();
      showError("");
      var country = form.country.value;
      var rows = Array.prototype.map.call(packages.querySelectorAll(".madd-rate__package"), function (row) {
        var get = function (n) {
          return row.querySelector('[name="' + n + '"]').value;
        };
        return { weight: get("weight"), length: get("length"), width: get("width"), height: get("height"), quantity: get("quantity") || 1 };
      });
      if (!country) return showError("กรุณาเลือกประเทศปลายทาง");
      if (rows.some(function (r) { return !(Number(r.weight) > 0); })) return showError("กรุณาระบุน้ำหนักของทุกกล่อง");

      var body = new FormData();
      body.append("action", "madd_rates");
      body.append("nonce", config.nonce);
      body.append("country", country);
      body.append("city", form.city.value);
      body.append("postcode", form.postcode.value);
      body.append("shipment_type", form.querySelector('[name="shipment_type"]:checked').value);
      body.append("packages", JSON.stringify(rows));
      var turnstile = form.querySelector('[name="cf-turnstile-response"]');
      if (turnstile) body.append("turnstile", turnstile.value);

      submit.disabled = true;
      submit.textContent = "กำลังเช็คราคา...";
      results.innerHTML = '<div class="madd-rate__loading">กำลังดึงราคาจาก Carrier อาจใช้เวลาสักครู่...</div>';

      fetch(config.ajaxUrl, { method: "POST", body: body, credentials: "same-origin" })
        .then(function (res) {
          return res.json();
        })
        .then(function (json) {
          if (json && json.success) {
            renderResults(results, json.data);
          } else {
            results.innerHTML = "";
            showError((json && json.data && json.data.message) || "เช็คราคาไม่สำเร็จ กรุณาลองใหม่");
          }
        })
        .catch(function () {
          results.innerHTML = "";
          showError("เชื่อมต่อไม่สำเร็จ กรุณาลองใหม่");
        })
        .finally(function () {
          submit.disabled = false;
          submit.textContent = "เช็คราคา";
          if (window.turnstile) window.turnstile.reset();
        });
    });
  }

  document.addEventListener("DOMContentLoaded", function () {
    Array.prototype.forEach.call(document.querySelectorAll(".madd-rate"), init);
  });
})();
