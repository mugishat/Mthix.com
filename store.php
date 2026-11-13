<?php /** @var array $store,$me */ ?>
<div class="page-head"><div><h1>Store settings</h1><div class="muted">How buyers see your store.</div></div></div>
<div class="grid g2">
<form class="card" method="post" action="/seller/store" data-once><?= csrf_field() ?><h3>Store profile</h3>
  <div class="field"><label for="n">Store name</label><input id="n" type="text" name="name" value="<?= e($store['name']) ?>" minlength="3" maxlength="60" required></div>
  <div class="field"><label for="d">Description</label><textarea id="d" name="description" maxlength="500"><?= e($store['description'] ?? '') ?></textarea></div>
  <button class="btn" type="submit">Save changes</button></form>
<div class="card"><h3>Account status</h3><dl class="kv">
  <dt>Store status</dt><dd><?= status_badge($store['status']) ?></dd>
  <dt>Store address</dt><dd class="small mono"><?= e($store['slug']) ?></dd>
  <dt>Identity (KYC)</dt><dd><?= status_badge($me['kyc_status']) ?></dd>
  <dt>Referral code</dt><dd class="mono"><?= e($me['ref_code']) ?></dd>
  <dt>Member since</dt><dd><?= dt($store['created_at'], 'M j, Y') ?></dd></dl>
  <?php if ($me['kyc_status'] !== 'verified'): ?><p class="mt"><a class="btn btn-light" href="/kyc">Complete verification</a></p><?php endif; ?></div>
</div>
