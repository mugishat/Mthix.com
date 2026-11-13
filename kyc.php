<div class="page-head"><h1>KYC verification</h1></div>
<div class="tabs"><a href="?tab=pending" class="<?= $tab === 'pending' ? 'on' : '' ?>">Pending</a><a href="?tab=done" class="<?= $tab === 'done' ? 'on' : '' ?>">Reviewed</a></div>
<?php if (!$rows): ?><div class="card empty">Nothing here.</div><?php endif; ?>
<?php foreach ($rows as $k): ?>
<div class="card mb">
  <div class="row spread"><div><h3 style="margin:0"><?= e($k['full_name']) ?> <?= status_badge($k['status']) ?></h3>
    <div class="small muted"><?= e($k['email']) ?> · <?= e($k['phone']) ?> · roles: <?= e($k['roles']) ?> · submitted <?= e(dt($k['created_at'])) ?></div></div></div>
  <dl class="kv mt"><dt>Document</dt><dd><?= e(status_label($k['doc_type'])) ?> · <span class="mono"><?= e($k['doc_number']) ?></span></dd>
    <dt>Business TIN</dt><dd><?= e($k['business_tin'] ?: '—') ?></dd><dt>Payout account</dt><dd><?= e($k['payout_account'] ?: '—') ?></dd>
    <?php if ($k['note']): ?><dt>Reviewer note</dt><dd><?= e($k['note']) ?></dd><?php endif; ?></dl>
  <?php if ($k['status'] === 'pending'): ?>
  <form method="post" action="/admin/kyc/<?= (int)$k['id'] ?>" class="row mt" data-once><?= csrf_field() ?>
    <input type="text" name="note" placeholder="Note (required when rejecting)" aria-label="Note" style="flex:1;min-width:220px">
    <button class="btn" name="decision" value="verified" type="submit">Approve</button>
    <button class="btn btn-danger" name="decision" value="rejected" type="submit">Reject</button></form>
  <?php endif; ?>
</div>
<?php endforeach; ?>
