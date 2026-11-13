<div class="crumbs"><a href="/">Home</a> / <?= e($heading) ?></div>
<div class="layout-side">
  <aside>
    <div class="side-list">
      <strong style="display:block;padding:8px 12px">Categories</strong>
      <?php foreach ($cats as $c): ?><a class="<?= ($cat['slug'] ?? '') === $c['slug'] ? 'on' : '' ?>" href="/category/<?= e($c['slug']) ?>"><?= e($c['name']) ?></a><?php endforeach; ?>
    </div>
  </aside>
  <div>
    <div class="page-head">
      <h1><?= e($heading) ?> <small class="muted" style="font-size:14px">(<?= (int)$pg['total'] ?>)</small></h1>
      <form method="get" class="filters">
        <?php if (get('q') !== ''): ?><input type="hidden" name="q" value="<?= e(get('q')) ?>"><?php endif; ?>
        <div class="field"><label for="min">Min price</label><input id="min" type="number" name="min" min="0" value="<?= e(get('min')) ?>"></div>
        <div class="field"><label for="max">Max price</label><input id="max" type="number" name="max" min="0" value="<?= e(get('max')) ?>"></div>
        <div class="field"><label for="sort">Sort</label>
          <select id="sort" name="sort" data-autosubmit>
            <?php foreach (['new' => 'Newest', 'low' => 'Price: low to high', 'high' => 'Price: high to low', 'rating' => 'Top rated'] as $k => $l): ?><option value="<?= $k ?>" <?= $sort === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
          </select></div>
        <button class="btn btn-light" type="submit">Apply</button>
      </form>
    </div>
    <?php if (!$items): ?><div class="card empty">Nothing found. Try a different search or category.</div><?php endif; ?>
    <div class="prod-grid"><?php foreach ($items as $p) { echo \App\Core\View::partial('pcard', ['p' => $p]); } ?></div>
    <?php if ($pg['pages'] > 1): ?><div class="pager">
      <?php for ($i = 1; $i <= $pg['pages']; $i++): $q = $_GET; $q['page'] = $i; ?>
        <?php if ($i === $pg['page']): ?><span class="on"><?= $i ?></span><?php else: ?><a href="?<?= e(http_build_query($q)) ?>"><?= $i ?></a><?php endif; ?>
      <?php endfor; ?></div><?php endif; ?>
  </div>
</div>
