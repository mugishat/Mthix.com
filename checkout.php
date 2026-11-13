<div class="steps"><div class="s on"><i>1</i>Shipping</div><div class="s on"><i>2</i>Delivery</div><div class="s"><i>3</i>Payment</div><div class="s"><i>4</i>Confirm</div></div>
<form method="post" action="/checkout" data-once class="grid g-main">
  <?= csrf_field() ?>
  <div class="stack">
    <div class="card"><h3>Shipping address</h3>
      <div class="form-grid">
        <div class="field"><label for="name">Full name</label><input id="name" type="text" name="name" required value="<?= e(old('name', $me['name'])) ?>"></div>
        <div class="field"><label for="phone">Phone</label><input id="phone" type="tel" name="phone" required value="<?= e(old('phone', (string)$me['phone'])) ?>"></div>
      </div>
      <div class="field"><label for="address">Address (district, sector, street, landmark)</label><textarea id="address" name="address" required><?= e(old('address')) ?></textarea></div>
    </div>
    <div class="card"><h3>Delivery method</h3>
      <?php foreach (cfg('delivery_methods') as $k => $m): ?>
        <label class="pay-opt"><input type="radio" name="delivery" value="<?= e($k) ?>" <?= $method === $k ? 'checked' : '' ?> data-nav="/checkout?delivery=<?= e($k) ?>"><span><?= e($m['label']) ?> <small class="muted">(<?= e($m['eta']) ?>)</small></span><b style="margin-left:auto"><?= money($m['fee']) ?></b></label>
      <?php endforeach; ?>
      <p class="hint">Delivery is charged per seller shipment. Totals update when you pick a method and press “Update totals”.</p>
      <a class="btn btn-light btn-sm" href="/checkout?delivery=<?= e($method) ?>" id="upd">Update totals</a>
    </div>
  </div>
  <aside class="card totals">
    <h3>Order summary</h3>
    <?php foreach ($quote['groups'] as $g): ?>
      <div class="small muted"><span><?= e($g['store_name']) ?></span></div>
      <?php foreach ($g['lines'] as $l): ?><div class="small"><span><?= (int)$l['qty'] ?> × <?= e($l['name']) ?></span><span><?= money($l['line_total']) ?></span></div><?php endforeach; ?>
    <?php endforeach; ?>
    <div class="mt"><span>Items</span><b><?= money($quote['subtotal']) ?></b></div>
    <div><span>Delivery</span><b><?= money($quote['delivery']) ?></b></div>
    <div class="small muted"><span>VAT (<?= (int)cfg('vat_percent') ?>%, included)</span><span><?= money($quote['vat_included']) ?></span></div>
    <div class="grand"><span>Total</span><b><?= money($quote['total']) ?></b></div>
    <button class="btn btn-lg btn-block mt" type="submit">Continue to payment</button>
  </aside>
</form>
