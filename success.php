<?php $paid = array_filter($orders, fn($o) => $o['status'] !== 'pending_payment'); ?>
<div class="card center" style="max-width:640px;margin:20px auto;padding:34px">
  <div class="success-icon">✓</div>
  <h1>Order placed successfully!</h1>
  <p class="muted">Thank you for shopping with MTHIX. Your payment is held safely in escrow.</p>
  <?php foreach ($orders as $o): ?><p>Order ID: <b class="mono"><?= e($o['code']) ?></b> <?= status_badge($o['status']) ?></p><?php endforeach; ?>
  <div class="row" style="justify-content:center"><a class="btn" href="/account/orders/<?= e(reset($orders)['code']) ?>">Track order</a><a class="btn btn-light" href="/">Continue shopping</a></div>
</div>
