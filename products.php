<?php /** @var array $items,$pg,$counts; @var string $status,$q */
$tabs = ['' => 'All', 'active' => 'Active', 'draft' => 'Drafts', 'archived' => 'Archived', 'suspended' => 'Suspended']; ?>
<div class="page-head"><div><h1>Products</h1><div class="muted">Manage everything you sell on MTHIX.</div></div>
  <a class="btn" href="/seller/products/new">+ Add product</a></div>
<div class="tabs"><?php foreach ($tabs as $k => $l): ?>
  <a class="<?= $status === $k ? 'on' : '' ?>" href="?<?= e(http_build_query(array_filter(['status' => $k, 'q' => $q]))) ?>"><?= e($l) ?><?= $k === '' ? ' (' . array_sum($counts) . ')' : ' (' . (int)($counts[$k] ?? 0) . ')' ?></a>
<?php endforeach; ?></div>
<form class="filters" method="get"><input type="hidden" name="status" value="<?= e($status) ?>">
  <div class="field"><input type="search" name="q" value="<?= e($q) ?>" placeholder="Search name or brand…" aria-label="Search products"></div>
  <button class="btn btn-light" type="submit">Search</button></form>
<div class="card">
<?php if (!$items): ?><div class="empty">No products here yet. <a href="/seller/products/new">Add your first product</a>.</div><?php else: ?>
<div class="table-wrap"><table>
  <thead><tr><th>Product</th><th>Category</th><th class="right">Price</th><th class="right">Stock</th><th class="right">Sold</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($items as $p): ?><tr>
    <td><div class="sv-row"><?= product_thumb($p, 'sv-thumb') ?><div><a href="/seller/products/<?= (int)$p['id'] ?>/edit"><b><?= e($p['name']) ?></b></a><div class="small muted"><?= (int)$p['variants'] ?> variant<?= $p['variants'] == 1 ? '' : 's' ?></div></div></div></td>
    <td><?= e($p['cat_name']) ?></td>
    <td class="right nowrap"><?php if ($p['min_price'] === null): ?>—<?php elseif ($p['min_price'] == $p['max_price']): ?><?= money($p['min_price']) ?><?php else: ?><?= money($p['min_price']) ?> – <?= abbr((int)$p['max_price']) ?><?php endif; ?></td>
    <td class="right"><?= (int)$p['stock'] === 0 ? '<span class="neg">0</span>' : (int)$p['stock'] ?></td>
    <td class="right"><?= (int)$p['sold'] ?></td>
    <td><?= status_badge($p['status']) ?></td>
    <td class="right nowrap">
      <a class="btn btn-light btn-sm" href="/seller/products/<?= (int)$p['id'] ?>/variants">Variants</a>
      <a class="btn btn-light btn-sm" href="/seller/products/<?= (int)$p['id'] ?>/edit">Edit</a>
      <?php if ($p['status'] !== 'suspended'): $to = $p['status'] === 'active' ? 'draft' : 'active'; ?>
      <form class="inline" method="post" action="/seller/products/<?= (int)$p['id'] ?>/status"><?= csrf_field() ?><input type="hidden" name="status" value="<?= $to ?>">
        <button class="btn btn-sm <?= $to === 'draft' ? 'btn-light' : '' ?>" type="submit"><?= $to === 'draft' ? 'Unpublish' : 'Publish' ?></button></form>
      <?php endif; ?>
    </td></tr><?php endforeach; ?>
  </tbody></table></div>
<?= \App\Core\View::render('seller/_pager', ['pg' => $pg], null) ?>
<?php endif; ?></div>
