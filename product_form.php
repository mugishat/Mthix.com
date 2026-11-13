<?php /** @var ?array $p,$cats,$econ,$rule */
$edit = $p !== null;
$v = fn(string $k, $def = '') => old($k, (string)($p[$k] ?? $def)); ?>
<div class="page-head"><div><h1><?= $edit ? 'Edit product' : 'Add new product' ?></h1>
  <div class="muted"><?= $edit ? e($p['name']) . ' · ' . status_badge($p['status']) : 'Create a product, then add colours and sizes in the Variant Builder.' ?></div></div>
  <?php if ($edit): ?><a class="btn btn-light" href="/seller/products/<?= (int)$p['id'] ?>/variants">Variant Builder →</a><?php endif; ?></div>
<form method="post" enctype="multipart/form-data" action="<?= $edit ? '/seller/products/' . (int)$p['id'] : '/seller/products' ?>" data-once>
<?= csrf_field() ?>
<div class="grid g-main">
  <div class="stack">
    <div class="card"><h3>Basic information</h3>
      <div class="field"><label for="name">Product name *</label><input id="name" type="text" name="name" value="<?= e($v('name')) ?>" maxlength="120" required></div>
      <div class="form-grid">
        <div class="field"><label for="cat">Category *</label><select id="cat" name="category_id" required><option value="">Select category</option>
          <?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (string)$v('category_id') === (string)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="brand">Brand</label><input id="brand" type="text" name="brand" value="<?= e($v('brand')) ?>" maxlength="60"></div>
      </div>
      <div class="field"><label for="desc">Description</label><textarea id="desc" name="description" maxlength="5000"><?= e($v('description')) ?></textarea></div>
      <div class="field"><label for="img">Photo (JPG, PNG or WebP, max 2 MB)</label>
        <?php if ($edit && $p['image']): ?><div class="mb"><?= product_thumb($p, 'sv-thumb') ?></div><?php endif; ?>
        <input id="img" type="file" name="image" accept="image/jpeg,image/png,image/webp"></div>
    </div>
    <div class="card"><h3>Pricing</h3>
      <div class="form-grid">
        <div class="field"><label for="bp">Selling price (RWF) *</label><input id="bp" type="number" name="base_price" min="100" step="1" value="<?= e($v('base_price')) ?>" required></div>
        <div class="field"><label for="cp">Compare-at price</label><input id="cp" type="number" name="compare_price" min="0" step="1" value="<?= e($v('compare_price')) ?>"><div class="hint">Shown crossed out as the old price.</div></div>
        <div class="field"><label for="co">Your cost price</label><input id="co" type="number" name="cost_price" min="0" step="1" value="<?= e($v('cost_price', '0')) ?>"><div class="hint">Private. Used to estimate your profit.</div></div>
        <?php if (!$edit): ?><div class="field"><label for="st">Opening stock</label><input id="st" type="number" name="stock" min="0" step="1" value="<?= e(old('stock', '10')) ?>"></div><?php endif; ?>
      </div>
      <label class="check"><input type="hidden" name="reseller_eligible" value="0"><input type="checkbox" name="reseller_eligible" value="1" <?= $v('reseller_eligible', '1') === '1' ? 'checked' : '' ?>> Let resellers promote this product (they earn a commission out of your sale)</label>
    </div>
  </div>
  <div class="stack">
    <div class="card"><h3>What you’ll receive</h3><?= \App\Core\View::render('seller/_econ', ['econ' => $econ, 'rule' => $rule], null) ?></div>
    <div class="card"><h3>Publish</h3>
      <?php if ($edit): ?><p>Status: <?= status_badge($p['status']) ?></p>
        <button class="btn btn-block" type="submit">Save changes</button>
      <?php else: ?>
        <label class="check mb"><input type="checkbox" name="publish" value="1" checked> Publish now (visible to buyers)</label>
        <button class="btn btn-block" type="submit">Save product</button>
      <?php endif; ?>
      <a class="btn btn-light btn-block mt" href="/seller/products">Cancel</a></div>
  </div>
</div></form>
