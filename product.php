<?php $price = $variants ? min(array_column($variants, 'price')) : (int)$p['base_price'];
$vdata = array_map(fn($v) => ['id' => (int)$v['id'], 'color' => $v['color'], 'size' => $v['size'], 'price' => (int)$v['price'], 'stock' => (int)$v['stock']], $variants);
$own = $me && (int)$me['id'] === (int)$p['store_user']; ?>
<div class="crumbs"><a href="/">Home</a> / <a href="/category/<?= e($p['cat_slug']) ?>"><?= e($p['cat_name']) ?></a> / <?= e($p['name']) ?></div>
<?php if ($p['status'] !== 'active'): ?><div class="flash warn">This product is <?= e($p['status']) ?> and not visible to shoppers.</div><?php endif; ?>
<div class="pdp">
  <div class="big"><?php if ($p['image']): ?><img src="/uploads/<?= e($p['image']) ?>" alt="<?= e($p['name']) ?>"><?php else: ?><?= emoji_for($p['cat_slug']) ?><?php endif; ?></div>
  <div>
    <h1><?= e($p['name']) ?></h1>
    <div class="small"><?= stars($rating) ?> <span class="muted"><?= count($reviews) ?> review<?= count($reviews) === 1 ? '' : 's' ?></span><?php if ($p['brand']): ?> · Brand: <b><?= e($p['brand']) ?></b><?php endif; ?></div>
    <p class="mt"><span class="price" id="pdp-price" style="font-size:26px"><?= money($price) ?></span>
      <?php if ($p['compare_price']): ?><span class="was"><?= money($p['compare_price']) ?></span><?php endif; ?></p>
    <p id="pdp-stock" class="pos"></p>
    <?php if ($variants && !$own): ?>
    <form id="buy-form" method="post" action="/cart/add" data-once>
      <?= csrf_field() ?><input type="hidden" name="variant_id" value="">
      <?php if ($colors): ?><div class="field"><label>Colour</label><div class="opt-group"><?php foreach ($colors as $c): ?><label class="opt"><input type="radio" name="color" value="<?= e($c) ?>"><?= e($c) ?></label><?php endforeach; ?></div></div><?php endif; ?>
      <?php if ($sizes): ?><div class="field"><label>Size</label><div class="opt-group"><?php foreach ($sizes as $s): ?><label class="opt"><input type="radio" name="size" value="<?= e($s) ?>"><?= e($s) ?></label><?php endforeach; ?></div></div><?php endif; ?>
      <div class="field"><label for="qty">Quantity</label><span class="qty"><button type="button" data-step="-1" aria-label="Less">−</button><input id="qty" type="number" name="qty" value="1" min="1" max="20"><button type="button" data-step="1" aria-label="More">+</button></span></div>
      <div class="row"><button class="btn btn-light btn-lg" type="submit" data-buy>Add to cart</button><button class="btn btn-lg" type="submit" name="buy_now" value="1" data-buy>Buy now</button></div>
    </form>
    <script type="application/json" id="variant-data"><?= json_encode($vdata, JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    <?php elseif ($own): ?><div class="flash info">This is your own product. <a href="/seller/products/<?= (int)$p['id'] ?>/edit">Edit it</a></div>
    <?php else: ?><div class="flash warn">This product has no variants on sale yet.</div><?php endif; ?>
    <?php if ($me): ?><form method="post" action="/wishlist/<?= (int)$p['id'] ?>" class="mt"><?= csrf_field() ?><button class="btn btn-light btn-sm" type="submit"><?= $inWishlist ? '♥ Saved' : '♡ Save for later' ?></button></form><?php endif; ?>
    <h3 class="mt2">Description</h3>
    <p><?= nl2br(e((string)$p['description'])) ?></p>
  </div>
  <aside class="card">
    <small class="muted">Sold by</small>
    <h3><?= e($p['store_name']) ?> <?= $p['kyc_status'] === 'verified' ? '<span class="badge badge-ok">✔ Verified</span>' : '' ?></h3>
    <?php if ($me && !$own): ?><a class="btn btn-light btn-block" href="/messages/<?= (int)$p['store_user'] ?>">Chat with seller</a><?php endif; ?>
    <ul class="small muted" style="padding-left:18px">
      <li>Paid into escrow, released when you confirm delivery</li><li>Delivery from <?= money(min(array_column(cfg('delivery_methods'), 'fee'))) ?></li><li>Delivery code protects your parcel</li>
    </ul>
  </aside>
</div>
<h2 class="mt2">Reviews</h2>
<?php if (!$reviews): ?><div class="card empty">No reviews yet. Only buyers with a completed order can review.</div><?php endif; ?>
<?php foreach ($reviews as $r): ?><div class="card mb"><?= stars((float)$r['rating']) ?> <b><?= e($r['name']) ?></b> <span class="muted small"><?= dt($r['created_at'], 'M j, Y') ?></span><p><?= e((string)$r['comment']) ?></p></div><?php endforeach; ?>
<?php if ($related): ?><h2 class="mt2">You may also like</h2><div class="prod-grid"><?php foreach ($related as $x) { echo \App\Core\View::partial('pcard', ['p' => $x]); } ?></div><?php endif; ?>
