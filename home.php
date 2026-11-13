<section class="hero">
  <div>
    <h1>One Platform.<br>Endless Opportunities.<br>Limitless Growth.</h1>
    <p>Buy, sell, share and earn with MTHIX. Pay with MTN MoMo or Airtel Money; your money is held safely in escrow until your order arrives.</p>
    <div class="row"><a class="btn btn-lg" href="#new">Explore now</a><a class="btn btn-dark btn-lg" href="/register">Join MTHIX</a></div>
  </div>
  <div class="hero-art">
    <div><span>🛍</span><b>Sell products</b><small class="muted">Open a store</small></div>
    <div><span>🔗</span><b>Share &amp; earn</b><small class="muted">Reseller 4% · Affiliate 1%</small></div>
    <div><span>🔒</span><b>Escrow-protected</b><small class="muted">Pay safely</small></div>
    <div><span>🚚</span><b>Tracked delivery</b><small class="muted">Code-confirmed</small></div>
  </div>
</section>
<div class="stats-strip">
  <?php foreach ($stats as $label => $n): ?><div><b><?= number_format($n) ?></b><span class="muted small"><?= e($label) ?></span></div><?php endforeach; ?>
</div>
<h2 class="mt2">Top categories</h2>
<div class="cat-row">
  <?php foreach ($cats as $c): ?><a class="cat-tile" href="/category/<?= e($c['slug']) ?>"><span><?= emoji_for($c['slug']) ?></span><?= e($c['name']) ?></a><?php endforeach; ?>
</div>
<?php if ($top): ?>
<h2 class="mt2">Best sellers</h2>
<div class="prod-grid"><?php foreach ($top as $p) { echo \App\Core\View::partial('pcard', ['p' => $p]); } ?></div>
<?php endif; ?>
<h2 class="mt2" id="new">New arrivals</h2>
<?php if (!$new): ?><div class="card empty">No products yet. <a href="/register?role=seller">Open a store</a> and add the first one.</div><?php endif; ?>
<div class="prod-grid"><?php foreach ($new as $p) { echo \App\Core\View::partial('pcard', ['p' => $p]); } ?></div>
