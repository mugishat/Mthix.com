<h1>My cart <small class="muted" style="font-size:15px">(<?= cart_count() ?> items)</small></h1>
<?php if (!$lines): ?><div class="card empty">Your cart is empty. <a href="/">Start shopping</a></div><?php return; endif; ?>
<div class="grid g-main mt">
  <div class="card table-wrap"><table>
    <thead><tr><th>Product</th><th>Price</th><th>Qty</th><th class="right">Total</th><th></th></tr></thead><tbody>
    <?php foreach ($lines as $l): ?><tr>
      <td><a href="/product/<?= e($l['slug']) ?>"><b><?= e($l['name']) ?></b></a><div class="small muted"><?= e($l['label']) ?> · <?= e($l['store_name']) ?></div><?php if ($l['short']): ?><div class="small neg">Only <?= (int)$l['stock'] ?> left</div><?php endif; ?></td>
      <td class="nowrap"><?= money($l['price']) ?></td>
      <td><form method="post" action="/cart/update"><?= csrf_field() ?><input type="hidden" name="variant_id" value="<?= (int)$l['variant_id'] ?>"><input style="width:70px" type="number" name="qty" min="0" max="20" value="<?= (int)$l['qty'] ?>" data-autosubmit aria-label="Quantity"></form></td>
      <td class="right nowrap"><b><?= money($l['line_total']) ?></b></td>
      <td><form method="post" action="/cart/remove" class="inline"><?= csrf_field() ?><input type="hidden" name="variant_id" value="<?= (int)$l['variant_id'] ?>"><button class="btn btn-danger btn-sm" type="submit" aria-label="Remove">✕</button></form></td>
    </tr><?php endforeach; ?></tbody></table></div>
  <aside class="card totals">
    <h3>Order summary</h3>
    <div><span>Subtotal</span><b><?= money($subtotal) ?></b></div>
    <div class="muted small"><span>Delivery is added at checkout</span></div>
    <a class="btn btn-lg btn-block mt" href="/checkout">Proceed to checkout</a>
    <form method="post" action="/cart/clear" class="mt"><?= csrf_field() ?><button class="btn btn-light btn-block btn-sm" type="submit" data-confirm="Remove everything from your cart?">Clear cart</button></form>
  </aside>
</div>
