<div class="page-head"><h1>Disputes</h1></div>
<div class="tabs"><a href="?tab=open" class="<?= $tab === 'open' ? 'on' : '' ?>">Open</a><a href="?tab=resolved" class="<?= $tab === 'resolved' ? 'on' : '' ?>">Resolved</a></div>
<?php if (!$rows): ?><div class="card empty">No <?= e($tab) ?> disputes.</div><?php endif; ?>
<?php foreach ($rows as $d): ?>
<div class="card mb">
  <div class="row spread"><div><h3 style="margin:0">Dispute #<?= (int)$d['id'] ?> · <a href="/admin/orders/<?= e($d['code']) ?>"><?= e($d['code']) ?></a> <?= status_badge($d['status']) ?></h3>
    <div class="small muted">Opened by <?= e($d['opener']) ?> · store <?= e($d['store_name']) ?> · order <?= status_badge($d['order_status']) ?> <?= money($d['total']) ?> · <?= e(dt($d['created_at'])) ?></div></div></div>
  <p class="mt"><?= nl2br(e($d['reason'])) ?></p>
  <?php if ($d['status'] === 'open'): ?>
  <form method="post" action="/admin/disputes/<?= (int)$d['id'] ?>" class="mt" data-once><?= csrf_field() ?>
    <div class="form-grid">
      <div class="field"><label>Outcome</label><select name="outcome">
        <option value="none">Resolve only (no money moves)</option>
        <?php if (array_intersect(['refunded','cancelled'], $d['next'])): ?><option value="refund">Refund the buyer (<?= in_array('refunded', $d['next'], true) ? 'refund' : 'cancel' ?> order)</option><?php endif; ?>
        <?php if (in_array('completed', $d['next'], true)): ?><option value="release">Release to the seller (complete order)</option><?php endif; ?></select></div>
      <div class="field"><label>Resolution (shown to both sides)</label><input type="text" name="resolution" required></div></div>
    <button class="btn" type="submit" data-confirm="Resolve this dispute? Refund and release move money and cannot be undone.">Resolve dispute</button></form>
  <?php else: ?><p class="small"><b>Resolution:</b> <?= e($d['resolution']) ?> <span class="muted">· <?= e(dt($d['resolved_at'])) ?></span></p><?php endif; ?>
</div>
<?php endforeach; ?>
