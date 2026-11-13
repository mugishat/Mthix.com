// MTHIX front-end helpers. No inline scripts are used (CSP: script-src 'self').
(function () {
  'use strict';

  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-confirm]');
    if (t && !window.confirm(t.getAttribute('data-confirm'))) {
      e.preventDefault();
      return;
    }
    var copy = e.target.closest('[data-copy]');
    if (copy) {
      var text = copy.getAttribute('data-copy');
      var done = function () {
        var old = copy.textContent;
        copy.textContent = 'Copied ✓';
        setTimeout(function () { copy.textContent = old; }, 1400);
      };
      if (navigator.clipboard) { navigator.clipboard.writeText(text).then(done); }
      else {
        var ta = document.createElement('textarea'); ta.value = text; document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); done(); } catch (err) { /* ignore */ }
        document.body.removeChild(ta);
      }
    }
    if (e.target.closest('.menu-btn')) { document.body.classList.toggle('nav-open'); }
    var step = e.target.closest('[data-step]');
    if (step) {
      var input = step.parentElement.querySelector('input');
      var v = parseInt(input.value || '1', 10) + parseInt(step.getAttribute('data-step'), 10);
      var min = parseInt(input.min || '1', 10), max = parseInt(input.max || '99', 10);
      input.value = Math.max(min, Math.min(max, v));
      input.dispatchEvent(new Event('change', { bubbles: true }));
    }
  });

  // Selects marked data-autosubmit submit their form when changed (filters, quantity).
  document.addEventListener('change', function (e) {
    if (e.target.matches('[data-nav]')) { window.location.href = e.target.getAttribute('data-nav'); return; }
    if (e.target.matches('[data-autosubmit]')) { e.target.form.submit(); }
  });

  // Prevent double submits: disable the submit button once the form is sent.
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (f.matches('[data-once]')) {
      var btns = f.querySelectorAll('button[type=submit],input[type=submit]');
      setTimeout(function () { btns.forEach(function (b) { b.disabled = true; }); }, 0);
    }
  });

  // Product page: show only the sizes that exist for the chosen colour, and update price/stock.
  var pv = document.getElementById('variant-data');
  if (pv) {
    var variants = JSON.parse(pv.textContent);
    var form = document.getElementById('buy-form');
    var colorIn = form.querySelectorAll('input[name=color]');
    var sizeIn = form.querySelectorAll('input[name=size]');
    var priceEl = document.getElementById('pdp-price');
    var stockEl = document.getElementById('pdp-stock');
    var vid = form.querySelector('input[name=variant_id]');
    var buttons = form.querySelectorAll('button[data-buy]');
    var qty = form.querySelector('input[name=qty]');
    var fmt = function (n) { return 'RWF ' + n.toLocaleString('en-US'); };
    var pick = function (name) { var c = form.querySelector('input[name=' + name + ']:checked'); return c ? c.value : ''; };
    var update = function () {
      var color = pick('color'), size = pick('size');
      var match = variants.filter(function (v) { return (v.color || '') === color && (v.size || '') === size; })[0];
      sizeIn.forEach(function (s) {
        var ok = variants.some(function (v) { return (v.color || '') === color && (v.size || '') === s.value && v.stock > 0; });
        s.disabled = !ok;
      });
      colorIn.forEach(function (c) {
        var ok = variants.some(function (v) { return (v.color || '') === c.value && v.stock > 0; });
        c.disabled = !ok;
      });
      if (match && match.stock > 0) {
        vid.value = match.id;
        priceEl.textContent = fmt(match.price);
        stockEl.textContent = match.stock <= 5 ? 'Only ' + match.stock + ' left' : 'In stock (' + match.stock + ' items)';
        stockEl.className = match.stock <= 5 ? 'neg' : 'pos';
        qty.max = match.stock;
        buttons.forEach(function (b) { b.disabled = false; });
      } else {
        vid.value = '';
        stockEl.textContent = 'Out of stock';
        stockEl.className = 'neg';
        buttons.forEach(function (b) { b.disabled = true; });
      }
    };
    form.addEventListener('change', update);
    // Pre-select the first in-stock variant.
    var first = variants.filter(function (v) { return v.stock > 0; })[0] || variants[0];
    if (first) {
      var c = form.querySelector('input[name=color][value="' + (first.color || '').replace(/"/g, '\\"') + '"]');
      var s = form.querySelector('input[name=size][value="' + (first.size || '').replace(/"/g, '\\"') + '"]');
      if (c) { c.checked = true; }
      if (s) { s.checked = true; }
    }
    update();
  }

  // Scroll chat threads to the newest message.
  var chat = document.querySelector('.chat');
  if (chat) { chat.scrollTop = chat.scrollHeight; }
})();
