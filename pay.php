<div class="steps"><div class="s on"><i>✓</i>Shipping</div><div class="s on"><i>✓</i>Delivery</div><div class="s on"><i>3</i>Payment</div><div class="s"><i>4</i>Confirm</div></div>
<div class="grid g-main">
  <div class="card">
    <?php if ($pending): ?>
      <h3>Waiting for your payment</h3>
      <p>We sent a payment request of <b><?= money($total) ?></b>. Approve it on your phone; this page updates when the gateway confirms.</p>
      <?php if (cfg('env') === 'dev'): ?>
      <div class="flash warn"><b>Developer mode:</b> no real gateway is connected. Use these buttons to act as the gateway.
        <div class="row mt"><form method="post" action="/pay/<?= e($group) ?>/simulate" class="inline"><?= csrf_field() ?><input type="hidden" name="result" value="success"><button class="btn btn-sm" type="submit">Simulate successful payment</button></form>
        <form method="post" action="/pay/<?= e($group) ?>/simulate" class="inline"><?= csrf_field() ?><input type="hidden" name="result" value="failed"><button class="btn btn-danger btn-sm" type="submit">Simulate failed payment</button></form></div></div>
      <?php endif; ?>
      <hr><h3>Change method</h3>
    <?php else: ?><h3>Choose payment method</h3><?php endif; ?>
    <form method="post" action="/pay/<?= e($group) ?>" data-once>
      <?= csrf_field() ?>
      <?php foreach (cfg('payment_methods') as $k => $label): ?><label class="pay-opt"><input type="radio" name="method" value="<?= e($k) ?>" required><?= e($label) ?></label><?php endforeach; ?>
      <button class="btn btn-lg" type="submit">Pay <?= money($total) ?></button>
    </form>
  </div>
  <aside class="card totals"><h3>Payment summary</h3>
    <?php foreach ($orders as $o): ?><div><span class="mono"><?= e($o['code']) ?></span><b><?= money($o['total']) ?></b></div><?php endforeach; ?>
    <div class="grand"><span>Total</span><b><?= money($total) ?></b></div>
    <p class="small muted mt">🔒 Your money is held in escrow. The seller is paid only after you confirm delivery.</p>
  </aside>
</div>
