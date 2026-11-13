<?php /** @var array $p */
$price = (int)($p['min_price'] ?? $p['base_price']);
$off = !empty($p['compare_price']) && $p['compare_price'] > $price ? (int)round(100 * ($p['compare_price'] - $price) / $p['compare_price']) : 0;
?>
<a class="pcard" href="/product/<?= e($p['slug']) ?>">
  <?php if ($off): ?><span class="off">-<?= $off ?>%</span><?php endif; ?>
  <?= product_thumb($p) ?>
  <div class="body">
    <div class="name"><?= e($p['name']) ?></div>
    <div><span class="price"><?= money($price) ?></span><?php if ($off): ?><span class="was"><?= money($p['compare_price']) ?></span><?php endif; ?></div>
    <?php if (isset($p['rating']) && (int)($p['reviews'] ?? 0) > 0): ?><div class="small"><?= stars((float)$p['rating']) ?> <span class="muted">(<?= (int)$p['reviews'] ?>)</span></div><?php endif; ?>
    <?php if (isset($p['stock']) && (int)$p['stock'] === 0): ?><div class="small neg">Out of stock</div><?php endif; ?>
  </div>
</a>
