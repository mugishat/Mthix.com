<?php /** @var array $econ @var array $rule  — per-unit payout table; seller.js recalculates it live from the price/cost fields */ ?>
<div class="table-wrap">
<table id="econ" data-pbps="<?= (int)$rule['platform_bps'] ?>" data-rbps="<?= (int)$rule['reseller_bps'] ?>" data-abps="<?= (int)$rule['affiliate_bps'] ?>"
       data-lowthr="<?= (int)$rule['low_profit_threshold'] ?>" data-lowbps="<?= (int)$rule['low_profit_platform_bps'] ?>">
  <thead><tr><th>If sold…</th><th class="right">MTHIX fee</th><th class="right">Reseller</th><th class="right">Affiliate</th><th class="right">You receive</th><th class="right">Est. profit</th></tr></thead>
  <tbody>
  <?php foreach ($econ as $row): ?>
    <tr data-r="<?= $row['r'] ? 1 : 0 ?>" data-a="<?= $row['a'] ? 1 : 0 ?>">
      <td><?= e($row['label']) ?></td>
      <td class="right" data-c="platform"><?= money($row['platform']) ?></td>
      <td class="right" data-c="reseller"><?= money($row['reseller']) ?></td>
      <td class="right" data-c="affiliate"><?= money($row['affiliate']) ?></td>
      <td class="right"><b data-c="seller"><?= money($row['seller']) ?></b></td>
      <td class="right <?= $row['profit'] < 0 ? 'neg' : 'pos' ?>" data-c="profit"><?= money($row['profit']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<p class="hint">Per unit, before delivery (paid separately by the buyer). Commission rules v<?= (int)$rule['version'] ?>: MTHIX <?= $rule['platform_bps'] / 100 ?>%, reseller <?= $rule['reseller_bps'] / 100 ?>%, affiliate <?= $rule['affiliate_bps'] / 100 ?>%.
  If your profit would drop under <?= money($rule['low_profit_threshold']) ?>, MTHIX’s fee falls to <?= $rule['low_profit_platform_bps'] / 100 ?>%.</p>
<script src="<?= asset('seller.js') ?>" defer></script>
