<div class="page-head"><h1>Audit log</h1><span class="muted">Append-only: entries cannot be edited or deleted.</span></div>
<form class="filters" method="get">
  <div class="field"><label for="a">Action starts with</label><input id="a" type="text" name="action" value="<?= e($action) ?>" placeholder="e.g. withdrawal, kyc, order"></div>
  <div class="field"><label for="u">Actor ID</label><input id="u" type="text" name="actor" value="<?= e($actor) ?>" style="width:90px"></div>
  <button class="btn btn-light" type="submit">Filter</button>
</form>
<div class="card"><div class="table-wrap"><table>
  <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Entity</th><th>Detail</th><th>IP</th></tr></thead><tbody>
  <?php foreach ($rows as $a): ?><tr><td class="small nowrap"><?= e(dt($a['created_at'], 'M j, Y H:i:s')) ?></td><td><?= e($a['name'] ?? ($a['actor_id'] ? '#' . $a['actor_id'] : 'system')) ?></td>
    <td><span class="mono"><?= e($a['action']) ?></span></td><td class="small"><?= e(trim(($a['entity'] ?? '') . ' ' . ($a['entity_id'] ?? ''))) ?></td>
    <td class="small" style="max-width:340px;word-break:break-word"><?= e((string)$a['detail']) ?></td><td class="small"><?= e((string)$a['ip']) ?></td></tr>
  <?php endforeach; ?><?php if (!$rows): ?><tr><td colspan="6" class="empty">No entries.</td></tr><?php endif; ?></tbody></table></div>
  <?= App\Core\View::partial('admin_pager', ['pg' => $pg]) ?></div>
