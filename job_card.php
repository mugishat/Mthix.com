<?php $st = $j['status']; ?>
<div class="card">
  <div class="card-head"><h3><?= e($j['code']) ?> <?= status_badge($st) ?></h3><b class="pos"><?= money($j['fee']) ?></b></div>
  <dl class="kv"><dt>Pick up from</dt><dd><?= e($j['store_name']) ?></dd><dt>Deliver to</dt><dd><?= e($j['ship_name']) ?> · <?= e($j['ship_phone']) ?></dd><dt>Address</dt><dd><?= e($j['ship_address']) ?></dd>
  <?php if ($j['failed_reason']): ?><dt>Failed because</dt><dd><?= e($j['failed_reason']) ?></dd><?php endif; ?></dl>
  <div class="row mt">
  <?php if ($st === 'open'): ?>
    <form method="post" action="/delivery/jobs/<?= (int)$j['id'] ?>/accept" data-once><?= csrf_field() ?><button class="btn" type="submit">Accept job</button></form>
  <?php elseif ($st === 'accepted'): ?>
    <form method="post" action="/delivery/jobs/<?= (int)$j['id'] ?>/pickup" data-once><?= csrf_field() ?><button class="btn" type="submit">Picked up</button></form>
  <?php elseif ($st === 'picked_up'): ?>
    <form method="post" action="/delivery/jobs/<?= (int)$j['id'] ?>/out" data-once><?= csrf_field() ?><button class="btn" type="submit">Out for delivery</button></form>
  <?php endif; ?>
  <?php if ($st === 'out_for_delivery'): ?>
    <form method="post" action="/delivery/jobs/<?= (int)$j['id'] ?>/delivered" class="row" data-once><?= csrf_field() ?>
      <input type="text" name="code" inputmode="numeric" maxlength="4" pattern="[0-9]{4}" placeholder="Buyer's 4-digit code" required style="width:190px"><button class="btn" type="submit">Confirm delivery</button></form>
  <?php endif; ?>
  <?php if (in_array($st, ['accepted', 'picked_up', 'out_for_delivery'], true)): ?>
    <form method="post" action="/delivery/jobs/<?= (int)$j['id'] ?>/failed" class="row"><?= csrf_field() ?>
      <input type="text" name="reason" placeholder="Reason if it failed" required style="width:200px"><button class="btn btn-danger" type="submit" data-confirm="Report this delivery as failed?">Failed</button></form>
  <?php endif; ?>
  </div>
</div>
