<div class="page-head"><h1>Users</h1></div>
<form class="filters" method="get">
  <div class="field"><label for="q">Search</label><input id="q" type="search" name="q" value="<?= e($q) ?>" placeholder="Name, email or phone"></div>
  <div class="field"><label for="role">Role</label><select id="role" name="role" data-autosubmit><option value="">All roles</option>
    <?php foreach (['buyer','seller','reseller','affiliate','delivery','admin','finance_admin','support_admin'] as $r): ?><option value="<?= $r ?>" <?= $role === $r ? 'selected' : '' ?>><?= e(status_label($r)) ?></option><?php endforeach; ?></select></div>
  <button class="btn btn-light" type="submit">Filter</button>
</form>
<div class="card"><div class="table-wrap"><table>
  <thead><tr><th>User</th><th>Roles</th><th>KYC</th><th>Status</th><th>Joined</th><th>Action</th></tr></thead><tbody>
  <?php foreach ($rows as $u): $staff = (bool)array_intersect(explode(',', (string)$u['roles']), ['admin','finance_admin','support_admin']); ?>
    <tr><td><b><?= e($u['name']) ?></b><div class="small muted"><?= e($u['email']) ?> · <?= e($u['phone']) ?></div></td>
      <td class="small"><?= e(str_replace(',', ', ', (string)$u['roles'])) ?></td>
      <td><?= status_badge($u['kyc_status']) ?></td><td><?= status_badge($u['status']) ?></td><td class="small nowrap"><?= e(dt($u['created_at'])) ?></td>
      <td><?php if (!$staff && \App\Core\Auth::hasRole('admin')): ?>
        <form method="post" action="/admin/users/<?= (int)$u['id'] ?>/status" class="row" style="gap:6px"><?= csrf_field() ?>
          <select name="status" aria-label="New status" style="width:auto"><?php foreach (['active','suspended','banned'] as $s): ?><option <?= $u['status'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select>
          <input type="text" name="reason" placeholder="Reason" aria-label="Reason" style="width:120px">
          <button class="btn btn-light btn-sm" type="submit" data-confirm="Change this user’s status? It is recorded in the audit log.">Apply</button></form>
        <?php else: ?><span class="muted small"><?= $staff ? 'Staff' : '—' ?></span><?php endif; ?></td></tr>
  <?php endforeach; ?><?php if (!$rows): ?><tr><td colspan="6" class="empty">No users match.</td></tr><?php endif; ?></tbody></table></div>
  <?= App\Core\View::partial('admin_pager', ['pg' => $pg]) ?></div>
