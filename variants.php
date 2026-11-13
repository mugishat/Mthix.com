<?php /** @var array $p,$vars,$sum,$colors,$sizes,$econ; @var string $prefix */ ?>
<div class="crumbs"><a href="/seller/products">Products</a> › <a href="/seller/products/<?= (int)$p['id'] ?>/edit"><?= e($p['name']) ?></a> › Variant Builder</div>
<div class="page-head"><div><h1>Variant Builder</h1><div class="muted">Create colours, sizes, SKUs, prices and stock for <b><?= e($p['name']) ?></b> <?= status_badge($p['status']) ?></div></div>
  <a class="btn btn-light" href="/seller/products/<?= (int)$p['id'] ?>/edit">← Back to product</a></div>

<div class="grid g-main">
<div class="stack">
  <div class="card"><h3>1 · Define options</h3>
    <form method="post" action="/seller/products/<?= (int)$p['id'] ?>/variants/generate" data-once>
      <?= csrf_field() ?>
      <div class="form-grid">
        <div class="field"><label for="colors">Colours <span class="muted">(comma separated)</span></label><input id="colors" type="text" name="colors" placeholder="Black, White, Red" value="<?= e(implode(', ', $colors)) ?>"></div>
        <div class="field"><label for="sizes">Sizes <span class="muted">(comma separated)</span></label><input id="sizes" type="text" name="sizes" placeholder="40, 41, 42" value="<?= e(implode(', ', $sizes)) ?>"></div>
        <div class="field"><label for="pf">SKU prefix</label><input id="pf" type="text" name="prefix" maxlength="8" value="<?= e($prefix) ?>"></div>
        <div class="field"><label for="gs">Stock for new variants</label><input id="gs" type="number" name="stock" min="0" value="0"></div>
      </div>
      <button class="btn" type="submit">Generate variants, SKUs &amp; barcodes</button>
      <span class="hint">Only missing combinations are created; existing variants are never changed or duplicated. New ones start at the product’s selling price (<?= money($p['base_price']) ?>).</span>
    </form></div>

  <form class="card" method="post" action="/seller/products/<?= (int)$p['id'] ?>/variants/save" data-once>
    <?= csrf_field() ?>
    <div class="card-head"><h3>2 · Variants (<?= count($vars) ?>)</h3><button class="btn" type="submit">Save variants</button></div>
    <?php if (!$vars): ?><div class="empty">No variants yet. Generate them above.</div><?php else: ?>
    <div class="table-wrap"><table class="sv-vt">
      <thead><tr><th>Colour</th><th>Size</th><th>SKU</th><th>Barcode</th><th>Price</th><th>Compare</th><th>Cost</th><th>Stock</th><th>Low alert</th><th>Active</th></tr></thead><tbody>
      <?php foreach ($vars as $v): $i = (int)$v['id']; $st = (int)$v['stock']; ?>
      <tr>
        <td><?= e($v['color'] ?? '—') ?></td><td><?= e($v['size'] ?? '—') ?></td>
        <td><input class="sv-sku" type="text" name="v[<?= $i ?>][sku]" value="<?= e($v['sku']) ?>" aria-label="SKU"></td>
        <td class="sv-bar"><?= e($v['barcode']) ?></td>
        <td><input type="number" min="0" name="v[<?= $i ?>][price]" value="<?= (int)$v['price'] ?>" aria-label="Price"></td>
        <td><input type="number" min="0" name="v[<?= $i ?>][compare_price]" value="<?= (int)$v['compare_price'] ?: '' ?>" aria-label="Compare-at price"></td>
        <td><input type="number" min="0" name="v[<?= $i ?>][cost_price]" value="<?= (int)$v['cost_price'] ?>" aria-label="Cost"></td>
        <td><input class="sv-n" type="number" min="0" name="v[<?= $i ?>][stock]" value="<?= $st ?>" aria-label="Stock"> <?= $st === 0 ? '<span class="badge badge-bad">Out</span>' : ($st <= $v['low_stock_alert'] ? '<span class="badge badge-warn">Low</span>' : '') ?></td>
        <td><input class="sv-n" type="number" min="0" name="v[<?= $i ?>][low_stock_alert]" value="<?= (int)$v['low_stock_alert'] ?>" aria-label="Low stock alert"></td>
        <td><input type="hidden" name="v[<?= $i ?>][active]" value="0"><input class="sv-sw" type="checkbox" name="v[<?= $i ?>][active]" value="1" <?= $v['active'] ? 'checked' : '' ?> aria-label="Active"></td>
      </tr><?php endforeach; ?></tbody></table></div>
    <p class="hint">Stock changes are logged in your inventory history. Deactivated variants disappear from the shop but keep their order history.</p>
    <?php endif; ?>
  </form>
</div>

<div class="stack">
  <div class="card"><h3>Variant summary</h3><dl class="kv">
    <dt>Total variants</dt><dd><?= $sum['total'] ?></dd><dt>Total stock</dt><dd><?= number_format($sum['stock']) ?> units</dd>
    <dt>In stock</dt><dd class="pos"><?= $sum['in'] ?></dd><dt>Low stock</dt><dd style="color:var(--amber)"><?= $sum['low'] ?></dd><dt>Out of stock</dt><dd class="neg"><?= $sum['out'] ?></dd></dl></div>
  <div class="card"><h3>Price summary</h3><dl class="kv">
    <dt>Lowest</dt><dd><?= money($sum['min']) ?></dd><dt>Highest</dt><dd><?= money($sum['max']) ?></dd><dt>Average</dt><dd><?= money($sum['avg']) ?></dd>
    <dt>Inventory value (at cost)</dt><dd><?= money($sum['value']) ?></dd></dl></div>
  <div class="card"><h3>Your payout on the lowest price</h3>
    <div class="table-wrap"><table><thead><tr><th>If sold…</th><th class="right">You receive</th><th class="right">Profit</th></tr></thead><tbody>
    <?php foreach ($econ as $r): ?><tr><td><?= e($r['label']) ?></td><td class="right"><b><?= money($r['seller']) ?></b></td><td class="right <?= $r['profit'] < 0 ? 'neg' : 'pos' ?>"><?= money($r['profit']) ?></td></tr><?php endforeach; ?></tbody></table></div></div>
</div></div>
