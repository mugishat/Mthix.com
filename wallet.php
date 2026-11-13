<div class="page-head"><div><h1>Wallet</h1><div class="muted">Your balance, earnings and withdrawals.</div></div></div>
<div class="grid g4">
  <div class="card stat"><div class="ico">◈</div><div><div class="lbl">Available</div><div class="val"><?= money($bal['available']) ?></div></div></div>
  <div class="card stat"><div class="ico a">⏳</div><div><div class="lbl">Pending (held until orders complete)</div><div class="val"><?= money($bal['pending']) ?></div></div></div>
  <div class="card stat"><div class="ico p">↗</div><div><div class="lbl">Withdrawn</div><div class="val"><?= money($bal['withdrawn']) ?></div></div></div>
  <div class="card stat"><div class="ico b">Σ</div><div><div class="lbl">Total earnings</div><div class="val"><?= money($bal['total_earnings']) ?></div></div></div>
</div>
<div class="grid g-main mt">
  <div class="card"><h3>Transactions</h3><?php if (!$entries): ?><div class="empty">No transactions yet.</div><?php else: ?><div class="table-wrap"><table>
    <thead><tr><th>Date</th><th>Type</th><th>Order</th><th>Bucket</th><th class="right">Amount</th></tr></thead><tbody>
    <?php foreach ($entries as $x): ?><tr><td class="nowrap"><?= dt($x['created_at']) ?></td><td><?= e(status_label($x['type'])) ?><div class="small muted"><?= e((string)$x['note']) ?></div></td><td class="mono"><?= e((string)$x['order_code']) ?></td><td><?= status_badge($x['bucket']) ?></td>
      <td class="right nowrap <?= $x['amount'] < 0 ? 'neg' : 'pos' ?>"><?= $x['amount'] < 0 ? '−' : '+' ?><?= money(abs($x['amount'])) ?></td></tr><?php endforeach; ?></tbody></table></div>
    <div class="pager"><?php if ($page > 1): ?><a href="?page=<?= $page - 1 ?>">← Newer</a><?php endif; ?><?php if (count($entries) === 25): ?><a href="?page=<?= $page + 1 ?>">Older →</a><?php endif; ?></div><?php endif; ?></div>
  <div class="stack">
    <form class="card" method="post" action="/wallet/withdraw" data-once><?= csrf_field() ?><input type="hidden" name="idem" value="<?= e($idem) ?>"><h3>Withdraw</h3>
      <?php if ($me['kyc_status'] !== 'verified'): ?><div class="flash warn">Verify your identity first. <a href="/kyc">Start KYC</a></div><?php endif; ?>
      <div class="field"><label for="a">Amount (min <?= money(cfg('min_withdrawal')) ?>)</label><input id="a" type="number" name="amount" min="<?= (int)cfg('min_withdrawal') ?>" max="<?= (int)$bal['available'] ?>" required></div>
      <div class="field"><label for="m">Pay out to</label><select id="m" name="method"><?php foreach (cfg('payment_methods') as $k => $l): if ($k === 'card') continue; ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?><option value="bank">Bank account</option></select></div>
      <div class="field"><label for="ac">Phone or account number</label><input id="ac" type="text" name="account" required maxlength="40"></div>
      <button class="btn btn-block" type="submit">Request withdrawal</button>
      <p class="hint">Large or unusual withdrawals are reviewed by our team first.</p></form>
    <div class="card"><h3>Recent withdrawals</h3><?php if (!$withdrawals): ?><div class="empty">None yet.</div><?php endif; ?>
      <?php foreach ($withdrawals as $w): ?><div class="row spread"><span><?= money($w['amount']) ?><div class="small muted"><?= dt($w['created_at'], 'M j') ?></div></span><?= status_badge($w['status']) ?></div><?php endforeach; ?></div>
  </div>
</div>
