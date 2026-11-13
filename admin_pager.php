<?php
// Expects $pg (from paginate()). Keeps the current filters in the links.
if ($pg['pages'] <= 1) { return; }
$qs = $_GET; unset($qs['page']);
$link = fn(int $n) => '?' . http_build_query($qs + ['page' => $n]);
?>
<nav class="pager" aria-label="Pages">
  <?php if ($pg['page'] > 1): ?><a href="<?= e($link($pg['page'] - 1)) ?>">‹ Prev</a><?php endif; ?>
  <?php for ($i = max(1, $pg['page'] - 2); $i <= min($pg['pages'], $pg['page'] + 2); $i++): ?>
    <?php if ($i === $pg['page']): ?><span class="on"><?= $i ?></span><?php else: ?><a href="<?= e($link($i)) ?>"><?= $i ?></a><?php endif; ?>
  <?php endfor; ?>
  <?php if ($pg['page'] < $pg['pages']): ?><a href="<?= e($link($pg['page'] + 1)) ?>">Next ›</a><?php endif; ?>
</nav>
