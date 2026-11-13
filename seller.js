// Seller workspace: live "what will I receive" table on the product form. (CSP-safe: external file, no inline script.)
(function () {
  'use strict';
  var pb = document.getElementById('print-btn');
  if (pb) { pb.addEventListener('click', function () { window.print(); }); }
  var t = document.getElementById('econ');
  var price = document.querySelector('[name=base_price]');
  var cost = document.querySelector('[name=cost_price]');
  if (!t || !price || !cost) { return; }
  var num = function (el) { return parseInt((el.value || '0').replace(/[^0-9]/g, ''), 10) || 0; };
  var fmt = function (n) { return (n < 0 ? '-' : '') + 'RWF ' + Math.abs(n).toLocaleString('en-US'); };
  var share = function (a, bps) { return Math.floor((a * bps + 5000) / 10000); };
  var d = t.dataset;
  var calc = function () {
    var p = num(price), c = num(cost);
    t.querySelectorAll('tbody tr').forEach(function (tr) {
      var r = tr.dataset.r === '1' ? +d.rbps : 0, a = tr.dataset.a === '1' ? +d.abps : 0;
      var build = function (pb) { var rs = share(p, r), as = share(p, a), ps = share(p, pb); return { ps: ps, rs: rs, as: as, seller: p - rs - as - ps }; };
      var x = build(+d.pbps);
      if (c > 0 && x.seller - c < +d.lowthr) { x = build(+d.lowbps); }
      var set = function (k, v) { var el = tr.querySelector('[data-c=' + k + ']'); if (el) { el.textContent = fmt(v); } };
      set('platform', x.ps); set('reseller', x.rs); set('affiliate', x.as); set('seller', x.seller); set('profit', x.seller - c);
      tr.querySelector('[data-c=profit]').className = 'right ' + (x.seller - c < 0 ? 'neg' : 'pos');
    });
  };
  ['input', 'change'].forEach(function (ev) { price.addEventListener(ev, calc); cost.addEventListener(ev, calc); });
  calc();
})();
