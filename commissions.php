<?php $pc = fn($bps) => rtrim(rtrim(number_format($bps / 100, 2), '0'), '.') . '%'; $isAdmin = \App\Core\Auth::hasRole('admin'); ?>
<div class="page-head"><div><h1>Commission rules</h1><div class="muted">Versioned. A new version applies to orders paid after its effective date; old orders keep the rule they were settled with.</div></div></div>
<div class="grid g4 mb">
  <?php foreach ([['MTHIX', $active['platform_bps']], ['Reseller', $active['reseller_bps']], ['Affiliate', $active['affiliate_bps']], ['Low-profit MTHIX rate', $active['low_profit_platform_bps']]] as [$l, $b]): ?>
    <div class="card"><div class="lbl muted"><?= $l ?></div><div style="font-size:26px;font-weight:800"><?= $pc($b) ?></div><div class="small muted">Active rule v<?= (int)$active['version'] ?></div></div><?php endforeach; ?>
</div>
<div class="grid g-main mb">
  <div class="card"><h2>History</h2><div class="table-wrap"><table><thead><tr><th>Version</th><th>MTHIX</th><th>Reseller</th><th>Affiliate</th><th>Low-profit</th><th>Effective from</th><th>By</th></tr></thead><tbody>
    <?php foreach ($rules as $r): ?><tr><td>v<?= (int)$r['version'] ?> <?= (int)$r['version'] === (int)$active['version'] ? '<span class="badge badge-ok">Active</span>' : '' ?></td>
      <td><?= $pc($r['platform_bps']) ?></td><td><?= $pc($r['reseller_bps']) ?></td><td><?= $pc($r['affiliate_bps']) ?></td>
      <td class="small"><?= $pc($r['low_profit_platform_bps']) ?> if profit &lt; <?= money($r['low_profit_threshold']) ?></td><td class="small nowrap"><?= e(dt($r['effective_from'])) ?></td><td class="small"><?= e($r['author'] ?? 'system') ?></td></tr><?php endforeach; ?></tbody></table></div></div>
  <div class="card"><h2>Earned so far</h2><?php foreach ($earned as $e): ?><div class="row spread" style="padding:6px 0"><span><?= e(status_label($e['party'])) ?> <span class="muted small">(<?= (int)$e['n'] ?> orders)</span></span><b><?= money($e['amount']) ?></b></div><?php endforeach; ?>
    <?php if (!$earned): ?><div class="empty">No commissions yet.</div><?php endif; ?></div>
</div>
<?php if ($isAdmin): ?>
<div class="card"><h2>Create a new version</h2>
  <form method="post" action="/admin/commissions" data-once><?= csrf_field() ?>
    <div class="form-grid">
      <div class="field"><label for="platform">MTHIX %</label><input id="platform" type="number" step="0.01" min="0" max="25" name="platform" value="<?= $active['platform_bps'] / 100 ?>" required></div>
      <div class="field"><label for="reseller">Reseller %</label><input id="reseller" type="number" step="0.01" min="0" max="25" name="reseller" value="<?= $active['reseller_bps'] / 100 ?>" required></div>
      <div class="field"><label for="affiliate">Affiliate %</label><input id="affiliate" type="number" step="0.01" min="0" max="25" name="affiliate" value="<?= $active['affiliate_bps'] / 100 ?>" required></div>
      <div class="field"><label for="threshold">Low-profit threshold (RWF)</label><input id="threshold" type="number" min="0" name="threshold" value="<?= (int)$active['low_profit_threshold'] ?>" required></div>
      <div class="field"><label for="low">Low-profit MTHIX %</label><input id="low" type="number" step="0.01" min="0" max="25" name="low_platform" value="<?= $active['low_profit_platform_bps'] / 100 ?>" required></div>
      <div class="field"><label for="eff">Effective from (Kigali time)</label><input id="eff" type="text" name="effective_from" placeholder="Now, or 2026-12-01 00:00"><div class="hint">Leave empty to start immediately.</div></div></div>
    <button class="btn" type="submit" data-confirm="Create a new commission rule version? It is recorded in the audit log.">Save new version</button></form></div>
<?php endif; ?>
