/* MADD Rate Quote — front-end. Asks MADD from the browser, falls back to this site's admin-ajax. */
(function () {
  "use strict";

  var config = window.MaddRateQuote || {};
  var POPULAR = ["US", "CN", "JP", "SG", "AU", "GB", "KR", "HK", "DE", "FR", "TW", "MY"];
  var MAX_PACKAGES = 20;

  // Count the page view for MADD's usage stats (fire-and-forget, once per page).
  function countView(page) {
    if (window.__maddViewSent || !config.apiUrl || !navigator.sendBeacon) return;
    window.__maddViewSent = true;
    try {
      navigator.sendBeacon(config.apiUrl + "/public/v1/web/hit", new URLSearchParams({ page: page }));
    } catch (e) {
      /* stats only — never break the page */
    }
  }

  var I18N = {
    en: {
      locale: "en-GB",
      region: "en",
      destination: "Destination",
      country: "Country",
      choose: "Select a country",
      popular: "Popular destinations",
      all: "All countries",
      city: "City (optional)",
      postcode: "Postal code (optional)",
      postcodeHint: "Some countries (e.g. US, CA, AU) need a postal code for an accurate price.",
      whatShipping: "What are you shipping?",
      parcel: "Parcel",
      parcelHint: "Boxes & goods",
      document: "Document",
      documentHint: "Papers & envelopes",
      packages: "Packages",
      weight: "Weight",
      length: "Length",
      width: "Width",
      height: "Height",
      qty: "Qty",
      kg: "kg",
      cm: "cm",
      addPackage: "Add another package",
      remove: "Remove",
      chargeable: "Estimated chargeable weight",
      chargeableHint: "Carriers charge the higher of actual weight and volumetric weight (L × W × H ÷ 5000).",
      submit: "Get my quote",
      loading: "Comparing live rates from UPS and DHL…",
      results: "Your quotes",
      from: "From Thailand to",
      best: "Best price",
      fastest: "Fastest",
      days: function (n) { return n + (n === 1 ? " business day" : " business days"); },
      eta: "Estimated delivery",
      noOptions: "No services are available for this destination online. Please contact us for a quote.",
      errCountry: "Please select a destination country.",
      errWeight: "Please enter the weight of every package.",
      errRate: "Too many requests. Please wait a moment and try again.",
      errInvalid: "Please check the details and try again.",
      errFailed: "We couldn't get a quote right now. Please try again shortly.",
      disclaimer: "Estimated price from a standard Bangkok pick-up. The final price depends on the actual pick-up address, weight and dimensions.",
      book: "Book this shipment",
      vat: "",
      aiTitle: "Describe your shipment",
      aiBadge: "AI",
      aiPlaceholder: "e.g. 3 pairs of shoes and 2 T-shirts to Los Angeles",
      aiButton: "Fill in for me",
      aiThinking: "Reading your request…",
      aiTry: "Try:",
      aiExamples: ["2 boxes of clothes, 5 kg each, to Tokyo", "iPhone + charger to Sydney", "Contract documents to London"],
      aiNotUnderstood: "Sorry, we couldn't understand that. Please describe what you're sending and where, or fill in the form below.",
      aiNeedCountry: "Please also choose the destination country below.",
      aiFailed: "The assistant is busy right now — please fill in the form below.",
      aiEstimated: "AI estimate",
      aiCheck: "Please check the estimate below before booking.",
    },
    th: {
      locale: "th-TH",
      region: "th",
      destination: "ปลายทาง",
      country: "ประเทศ",
      choose: "เลือกประเทศปลายทาง",
      popular: "ประเทศยอดนิยม",
      all: "ทุกประเทศ",
      city: "เมือง (ไม่บังคับ)",
      postcode: "รหัสไปรษณีย์ (ไม่บังคับ)",
      postcodeHint: "บางประเทศ เช่น สหรัฐฯ แคนาดา ออสเตรเลีย ต้องใช้รหัสไปรษณีย์เพื่อให้ได้ราคาที่แม่นยำ",
      whatShipping: "ส่งอะไร",
      parcel: "พัสดุ",
      parcelHint: "กล่อง / สินค้า",
      document: "เอกสาร",
      documentHint: "เอกสาร / ซองจดหมาย",
      packages: "กล่องพัสดุ",
      weight: "น้ำหนัก",
      length: "ยาว",
      width: "กว้าง",
      height: "สูง",
      qty: "จำนวน",
      kg: "กก.",
      cm: "ซม.",
      addPackage: "เพิ่มกล่อง",
      remove: "ลบ",
      chargeable: "น้ำหนักที่ใช้คิดราคา (โดยประมาณ)",
      chargeableHint: "Carrier คิดราคาจากน้ำหนักจริงหรือน้ำหนักตามปริมาตร (ก × ย × ส ÷ 5000) แล้วแต่ค่าไหนมากกว่า",
      submit: "เช็คราคา",
      loading: "กำลังเปรียบเทียบราคาจาก UPS และ DHL…",
      results: "ราคาค่าส่ง",
      from: "จากประเทศไทยไป",
      best: "ราคาดีที่สุด",
      fastest: "เร็วที่สุด",
      days: function (n) { return n + " วันทำการ"; },
      eta: "คาดว่าจะถึง",
      noOptions: "ยังไม่มีบริการออนไลน์สำหรับปลายทางนี้ กรุณาติดต่อเจ้าหน้าที่เพื่อขอราคา",
      errCountry: "กรุณาเลือกประเทศปลายทาง",
      errWeight: "กรุณาระบุน้ำหนักของทุกกล่อง",
      errRate: "เช็คราคาบ่อยเกินไป กรุณารอสักครู่แล้วลองใหม่",
      errInvalid: "กรุณาตรวจสอบข้อมูลอีกครั้ง",
      errFailed: "ไม่สามารถเช็คราคาได้ในขณะนี้ กรุณาลองใหม่อีกครั้ง",
      disclaimer: "ราคาประมาณการจากการรับพัสดุในกรุงเทพฯ ราคาจริงขึ้นอยู่กับที่อยู่รับของ น้ำหนักและขนาดจริง",
      book: "จองส่งพัสดุ",
      vat: "",
      aiTitle: "เล่าสั้นๆ ว่าจะส่งอะไร ไปที่ไหน",
      aiBadge: "AI",
      aiPlaceholder: "เช่น ส่งรองเท้า 3 คู่ กับเสื้อ 2 ตัว ไปลอสแองเจลิส",
      aiButton: "กรอกให้อัตโนมัติ",
      aiThinking: "กำลังอ่านข้อความ…",
      aiTry: "ลองพิมพ์:",
      aiExamples: ["เสื้อผ้า 2 กล่อง กล่องละ 5 กิโล ไปโตเกียว", "iPhone + ที่ชาร์จ ไปซิดนีย์", "เอกสารสัญญา ไปลอนดอน"],
      aiNotUnderstood: "ขออภัย ยังไม่เข้าใจข้อความ ลองบอกว่าส่งอะไร ไปประเทศไหน หรือกรอกฟอร์มด้านล่างได้เลย",
      aiNeedCountry: "กรุณาเลือกประเทศปลายทางด้านล่างด้วย",
      aiFailed: "ผู้ช่วยไม่ว่างในขณะนี้ กรุณากรอกฟอร์มด้านล่าง",
      aiEstimated: "AI ประมาณ",
      aiCheck: "กรุณาตรวจสอบค่าที่ประมาณไว้ด้านล่างก่อนจอง",
    },
  };

  function el(tag, attrs, children) {
    var node = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === "text") node.textContent = attrs[k];
      else if (k === "html") node.innerHTML = attrs[k];
      else if (attrs[k] !== false && attrs[k] != null) node.setAttribute(k, attrs[k] === true ? "" : attrs[k]);
    });
    (children || []).forEach(function (c) {
      if (c) node.appendChild(typeof c === "string" ? document.createTextNode(c) : c);
    });
    return node;
  }

  var ICON = {
    box: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8l-9-5-9 5 9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/></svg>',
    doc: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/></svg>',
    plus: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>',
    trash: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/></svg>',
    clock: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
    cal: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg>',
    arrow: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>',
    sparkle: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.8 4.7L18.5 9.5l-4.7 1.8L12 16l-1.8-4.7L5.5 9.5l4.7-1.8z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"/></svg>',
  };

  function icon(name, cls) {
    return el("span", { class: "madd-quote__icon" + (cls ? " " + cls : ""), "aria-hidden": "true", html: ICON[name] });
  }

  // Direct browser → MADD first; admin-ajax (server → MADD with the API key) when that isn't set up.
  function request(path, formData, ajaxAction) {
    var direct = config.apiUrl
      ? fetch(config.apiUrl + path, formData ? { method: "POST", body: new URLSearchParams(formData), credentials: "omit" } : { credentials: "omit" }).then(function (res) {
          if (res.status === 403) throw new Error("fallback");
          return res.json().then(function (json) {
            return { ok: res.ok, status: res.status, data: json };
          });
        })
      : Promise.reject(new Error("fallback"));

    return direct.catch(function () {
      var body = new FormData();
      body.append("action", ajaxAction);
      body.append("nonce", config.nonce);
      (formData || []).forEach(function (pair) {
        body.append(pair[0], pair[1]);
      });
      return fetch(config.ajaxUrl, { method: "POST", body: body, credentials: "same-origin" }).then(function (res) {
        return res.json().then(function (json) {
          return json && json.success ? { ok: true, status: 200, data: json.data } : { ok: false, status: res.status, data: json && json.data };
        });
      });
    });
  }

  function init(root) {
    var lang = root.getAttribute("data-lang") === "th" ? "th" : "en";
    var t = I18N[lang];
    var form = root.querySelector(".madd-quote__form");
    var result = root.querySelector(".madd-quote__result");
    var regionNames = null;
    try {
      regionNames = new Intl.DisplayNames([t.region], { type: "region" });
    } catch (e) {
      /* fall back to MADD's English names */
    }
    function countryName(code, fallback) {
      try {
        return (regionNames && regionNames.of(code)) || fallback || code;
      } catch (e) {
        return fallback || code;
      }
    }
    var money = function (v, cur) {
      return Number(v).toLocaleString(t.locale, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + " " + (cur === "THB" ? (lang === "th" ? "บาท" : "THB") : cur);
    };

    // ---------- form ----------
    var select = el("select", { name: "country", required: true, class: "madd-quote__select" }, [el("option", { value: "", text: t.choose })]);
    var city = el("input", { name: "city", type: "text", maxlength: "100", autocomplete: "address-level2" });
    var postcode = el("input", { name: "postcode", type: "text", maxlength: "20", autocomplete: "postal-code" });
    var typeParcel = el("input", { type: "radio", name: "shipment_type", value: "parcel", checked: true });
    var typeDoc = el("input", { type: "radio", name: "shipment_type", value: "document" });
    var packages = el("div", { class: "madd-quote__packages" });
    var chargeable = el("div", { class: "madd-quote__chargeable", hidden: true });
    var error = el("p", { class: "madd-quote__error", role: "alert", hidden: true });
    var submit = el("button", { type: "submit", class: "madd-quote__submit" }, [el("span", { text: t.submit }), icon("arrow")]);
    var addBtn = el("button", { type: "button", class: "madd-quote__add" }, [icon("plus"), el("span", { text: t.addPackage })]);

    function field(label, input, unit, cls) {
      return el("label", { class: "madd-quote__field" + (cls ? " " + cls : "") }, [
        el("span", { class: "madd-quote__label", text: label }),
        el("span", { class: "madd-quote__control" + (unit ? " has-unit" : "") }, [input, unit ? el("span", { class: "madd-quote__unit", text: unit }) : null]),
      ]);
    }
    function num(name, placeholder, step, min) {
      return el("input", { name: name, type: "number", inputmode: "decimal", step: step || "0.1", min: min || "0.1", placeholder: placeholder || "" });
    }

    function packageRow(values) {
      var row = el("div", { class: "madd-quote__package" + (values && values.estimated ? " is-ai" : "") });
      var remove = el("button", { type: "button", class: "madd-quote__remove", title: t.remove, "aria-label": t.remove }, [icon("trash")]);
      remove.addEventListener("click", function () {
        row.remove();
        refresh();
      });
      row.appendChild(el("div", { class: "madd-quote__package-no" }));
      row.appendChild(field(t.weight, num("weight", "0.0"), t.kg, "is-weight"));
      row.appendChild(field(t.length, num("length", "—", "1", "1"), t.cm, "is-dim"));
      row.appendChild(field(t.width, num("width", "—", "1", "1"), t.cm, "is-dim"));
      row.appendChild(field(t.height, num("height", "—", "1", "1"), t.cm, "is-dim"));
      row.appendChild(field(t.qty, num("quantity", "1", "1", "1"), "", "is-qty"));
      row.appendChild(remove);
      if (values && values.estimated) row.appendChild(el("span", { class: "madd-quote__ai-tag", title: values.item || "" }, [icon("sparkle"), el("span", { text: t.aiEstimated })]));
      if (values) {
        ["weight", "length", "width", "height", "quantity"].forEach(function (k) {
          if (values[k] != null) row.querySelector('[name="' + k + '"]').value = values[k];
        });
      }
      row.addEventListener("input", function () {
        row.classList.remove("is-ai");
        var tag = row.querySelector(".madd-quote__ai-tag");
        if (tag) tag.remove();
        refresh();
      });
      return row;
    }

    function rows() {
      return Array.prototype.map.call(packages.querySelectorAll(".madd-quote__package"), function (row) {
        var v = function (n) {
          return row.querySelector('[name="' + n + '"]').value;
        };
        return { weight: v("weight"), length: v("length"), width: v("width"), height: v("height"), quantity: v("quantity") || "1" };
      });
    }

    function refresh() {
      var list = packages.querySelectorAll(".madd-quote__package");
      Array.prototype.forEach.call(list, function (row, i) {
        row.querySelector(".madd-quote__package-no").textContent = "#" + (i + 1);
        row.querySelector(".madd-quote__remove").hidden = list.length === 1;
      });
      addBtn.hidden = list.length >= MAX_PACKAGES;
      var isDoc = typeDoc.checked;
      root.classList.toggle("is-document", isDoc);
      var total = 0;
      var any = false;
      rows().forEach(function (r) {
        var w = Number(r.weight) || 0;
        var vol = !isDoc && r.length && r.width && r.height ? (Number(r.length) * Number(r.width) * Number(r.height)) / 5000 : 0;
        if (w || vol) any = true;
        total += Math.max(w, vol) * Math.max(1, Number(r.quantity) || 1);
      });
      chargeable.hidden = !any;
      chargeable.innerHTML = "";
      chargeable.appendChild(el("span", { text: t.chargeable }));
      chargeable.appendChild(el("b", { text: (Math.ceil(total * 2) / 2).toLocaleString(t.locale) + " " + t.kg }));
      chargeable.title = t.chargeableHint;
    }

    function typeCard(input, iconName, label, hint) {
      return el("label", { class: "madd-quote__type" }, [input, el("span", { class: "madd-quote__type-body" }, [icon(iconName), el("span", {}, [el("b", { text: label }), el("small", { text: hint })])])]);
    }

    // ---------- AI assistant: sentence → form ----------
    var aiEnabled = root.getAttribute("data-ai") !== "off" && !!config.apiUrl;
    var aiInput = el("textarea", { class: "madd-quote__ai-input", rows: "2", maxlength: "500", placeholder: t.aiPlaceholder });
    var aiButton = el("button", { type: "button", class: "madd-quote__ai-button" }, [icon("sparkle"), el("span", { text: t.aiButton })]);
    var aiNote = el("p", { class: "madd-quote__ai-note", hidden: true });
    var aiBox = el("div", { class: "madd-quote__ai" }, [
      el("div", { class: "madd-quote__ai-head" }, [el("span", { class: "madd-quote__ai-badge" }, [icon("sparkle"), el("span", { text: t.aiBadge })]), el("b", { text: t.aiTitle })]),
      el("div", { class: "madd-quote__ai-row" }, [aiInput, aiButton]),
      el(
        "div",
        { class: "madd-quote__ai-examples" },
        [el("span", { text: t.aiTry })].concat(
          t.aiExamples.map(function (ex) {
            var chip = el("button", { type: "button", class: "madd-quote__ai-chip", text: ex });
            chip.addEventListener("click", function () {
              aiInput.value = ex;
              askAi();
            });
            return chip;
          })
        )
      ),
      aiNote,
    ]);

    function aiMessage(text, kind) {
      aiNote.textContent = text || "";
      aiNote.className = "madd-quote__ai-note" + (kind ? " is-" + kind : "");
      aiNote.hidden = !text;
    }

    function applyAi(data) {
      if (!data || !data.understood || !data.packages || !data.packages.length) {
        aiMessage(t.aiNotUnderstood, "warn");
        return;
      }
      (data.shipment_type === "document" ? typeDoc : typeParcel).checked = true;
      city.value = (data.destination && data.destination.city) || "";
      postcode.value = (data.destination && data.destination.postcode) || "";
      packages.innerHTML = "";
      data.packages.forEach(function (p) {
        packages.appendChild(packageRow(p));
      });
      refresh();
      var estimated = data.packages.some(function (p) {
        return p.estimated;
      });
      countriesReady.then(function () {
        var code = data.destination && data.destination.country;
        var hasOption = code && select.querySelector('option[value="' + code + '"]');
        if (hasOption) select.value = code;
        aiMessage([data.note, estimated ? t.aiCheck : "", hasOption ? "" : t.aiNeedCountry].filter(Boolean).join(" "), hasOption ? "ok" : "warn");
        if (hasOption) {
          if (form.requestSubmit) form.requestSubmit();
          else submit.click();
        } else {
          select.focus();
        }
      });
    }

    function askAi() {
      var text = aiInput.value.trim();
      if (text.length < 3) return aiInput.focus();
      aiButton.disabled = true;
      aiBox.classList.add("is-busy");
      aiMessage(t.aiThinking, "busy");
      fetch(config.apiUrl + "/public/v1/web/ai-parse", { method: "POST", body: new URLSearchParams({ text: text, lang: lang }), credentials: "omit" })
        .then(function (res) {
          return res.json().then(function (json) {
            return { ok: res.ok, status: res.status, data: json };
          });
        })
        .then(function (res) {
          if (res.ok) return applyAi(res.data);
          if (res.status === 503) {
            aiBox.hidden = true; // AI switched off in MADD
            return;
          }
          aiMessage(res.status === 429 ? t.errRate : t.aiFailed, "warn");
        })
        .catch(function () {
          aiMessage(t.aiFailed, "warn");
        })
        .finally(function () {
          aiButton.disabled = false;
          aiBox.classList.remove("is-busy");
        });
    }

    aiButton.addEventListener("click", askAi);
    aiInput.addEventListener("keydown", function (e) {
      if (e.key === "Enter" && !e.shiftKey) {
        e.preventDefault();
        askAi();
      }
    });
    // Its own card above the form card (not a section inside it).
    if (aiEnabled) root.insertBefore(aiBox, form);

    form.appendChild(
      el("div", { class: "madd-quote__section" }, [
        el("div", { class: "madd-quote__section-title", text: t.destination }),
        el("div", { class: "madd-quote__grid" }, [
          field(t.country, select, "", "is-country"),
          field(t.city, city),
          field(t.postcode, postcode),
        ]),
        el("p", { class: "madd-quote__hint", text: t.postcodeHint }),
      ])
    );
    form.appendChild(
      el("div", { class: "madd-quote__section" }, [
        el("div", { class: "madd-quote__section-title", text: t.whatShipping }),
        el("div", { class: "madd-quote__types" }, [typeCard(typeParcel, "box", t.parcel, t.parcelHint), typeCard(typeDoc, "doc", t.document, t.documentHint)]),
      ])
    );
    form.appendChild(el("div", { class: "madd-quote__section" }, [el("div", { class: "madd-quote__section-title", text: t.packages }), packages, el("div", { class: "madd-quote__package-bar" }, [addBtn, chargeable])]));
    form.appendChild(error);
    form.appendChild(submit);

    packages.appendChild(packageRow());
    refresh();
    addBtn.addEventListener("click", function () {
      packages.appendChild(packageRow());
      refresh();
    });
    typeParcel.addEventListener("change", refresh);
    typeDoc.addEventListener("change", refresh);

    // ---------- countries ----------
    var countriesReady = request("/public/v1/web/countries", null, "madd_quote_countries").then(function (res) {
      var list = (res.ok && res.data && res.data.countries) || [];
      var named = list.map(function (c) {
        return { code: c.iso2, name: countryName(c.iso2, c.name) };
      });
      named.sort(function (a, b) {
        return a.name.localeCompare(b.name, t.locale);
      });
      var popular = POPULAR.map(function (code) {
        return named.filter(function (c) {
          return c.code === code;
        })[0];
      }).filter(Boolean);
      var opt = function (c) {
        return el("option", { value: c.code, text: c.name });
      };
      if (popular.length) select.appendChild(el("optgroup", { label: t.popular }, popular.map(opt)));
      select.appendChild(el("optgroup", { label: t.all }, named.map(opt)));
    });

    // ---------- submit ----------
    function showError(message) {
      error.textContent = message || "";
      error.hidden = !message;
    }

    form.addEventListener("submit", function (e) {
      e.preventDefault();
      showError("");
      var list = rows();
      if (!select.value) return showError(t.errCountry);
      if (list.some(function (r) { return !(Number(r.weight) > 0); })) return showError(t.errWeight);

      var isDoc = typeDoc.checked;
      var data = [
        ["destination[country]", select.value],
        ["shipment_type", isDoc ? "document" : "parcel"],
      ];
      if (city.value.trim()) data.push(["destination[city]", city.value.trim()]);
      if (postcode.value.trim()) data.push(["destination[postcode]", postcode.value.trim()]);
      list.forEach(function (r, i) {
        ["weight", "length", "width", "height", "quantity"].forEach(function (k) {
          if (r[k] && !(isDoc && k !== "weight" && k !== "quantity")) data.push(["packages[" + i + "][" + k + "]", r[k]]);
        });
      });

      submit.disabled = true;
      root.classList.add("is-loading");
      result.innerHTML = "";
      result.appendChild(
        el("div", { class: "madd-quote__loading" }, [el("div", { class: "madd-quote__spinner" }), el("span", { text: t.loading }), el("div", { class: "madd-quote__skeleton" }), el("div", { class: "madd-quote__skeleton" })])
      );

      request("/public/v1/web/rates", data, "madd_quote_rates")
        .then(function (res) {
          if (res.ok) return render(res.data, select.value);
          result.innerHTML = "";
          var admin = res.data && res.data.admin ? " [Admin] " + res.data.admin : "";
          showError((res.status === 429 ? t.errRate : res.status === 422 ? t.errInvalid : t.errFailed) + admin);
        })
        .catch(function () {
          result.innerHTML = "";
          showError(t.errFailed);
        })
        .finally(function () {
          submit.disabled = false;
          root.classList.remove("is-loading");
        });
    });

    // ---------- results ----------
    function render(data, code) {
      result.innerHTML = "";
      var options = (data && data.options) || [];
      var head = el("div", { class: "madd-quote__result-head" }, [
        el("div", {}, [el("div", { class: "madd-quote__eyebrow", text: t.results }), el("div", { class: "madd-quote__route", text: t.from + " " + countryName(code) })]),
      ]);
      result.appendChild(head);

      if (!options.length) {
        result.appendChild(el("div", { class: "madd-quote__empty", text: t.noOptions }));
        return contact();
      }

      var cheapest = options.reduce(function (m, o) {
        return o.price < m ? o.price : m;
      }, Infinity);
      var fastestDays = options.reduce(function (m, o) {
        return o.transit_days && o.transit_days < m ? o.transit_days : m;
      }, Infinity);

      var list = el("div", { class: "madd-quote__options" });
      options.forEach(function (o, i) {
        var badges = [];
        if (o.price === cheapest) badges.push(el("span", { class: "madd-quote__badge is-best", text: t.best }));
        if (o.transit_days && o.transit_days === fastestDays && options.length > 1) badges.push(el("span", { class: "madd-quote__badge is-fast", text: t.fastest }));
        var meta = [];
        if (o.transit_days) meta.push(el("span", {}, [icon("clock"), document.createTextNode(t.days(o.transit_days))]));
        if (o.estimated_delivery) {
          var d = new Date(o.estimated_delivery);
          if (!isNaN(d.getTime())) meta.push(el("span", {}, [icon("cal"), document.createTextNode(t.eta + " " + d.toLocaleDateString(t.locale, { weekday: "short", day: "numeric", month: "short" }))]));
        }
        list.appendChild(
          el("article", { class: "madd-quote__option" + (o.price === cheapest ? " is-best" : ""), style: "animation-delay:" + i * 60 + "ms" }, [
            el("div", { class: "madd-quote__carrier madd-quote__carrier--" + String(o.carrier).toLowerCase() }, [el("b", { text: o.carrier })]),
            el("div", { class: "madd-quote__service" }, [
              el("div", { class: "madd-quote__badges" }, badges),
              el("div", { class: "madd-quote__service-name", text: o.service_name }),
              el("div", { class: "madd-quote__meta" }, meta),
            ]),
            el("div", { class: "madd-quote__price" }, [el("div", { class: "madd-quote__amount", text: money(o.price, o.currency) })]),
          ])
        );
      });
      result.appendChild(list);
      result.appendChild(el("p", { class: "madd-quote__disclaimer", text: "* " + t.disclaimer }));
      contact();
      if (result.scrollIntoView && window.innerWidth < 900) result.scrollIntoView({ behavior: "smooth", block: "start" });
    }

    function contact() {
      if (!config.contactUrl) return;
      result.appendChild(
        el("a", { class: "madd-quote__book", href: config.contactUrl, target: "_blank", rel: "noopener" }, [el("span", { text: config.contactLabel || t.book }), icon("arrow")])
      );
    }
  }

  document.addEventListener("DOMContentLoaded", function () {
    var roots = document.querySelectorAll(".madd-quote");
    if (roots.length) countView("rates");
    Array.prototype.forEach.call(roots, init);
  });
})();
