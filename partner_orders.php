<?php if (!$rows): ?><div class="empty">No orders yet.</div><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>Order</th><th>Date</th><th>Order status</th><th>Product total</th><th>Commission</th><th>Commission status</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><code><?= e($r['code']) ?></code></td><td><?= e(dt($r['created_at'])) ?></td><td><?= status_badge($r['status']) ?></td>
<td><?= money($r['subtotal']) ?></td><td class="pos"><?= $r['commission'] !== null ? money($r['commission']) : '—' ?></td>
<td><?= $r['cstatus'] ? status_badge($r['cstatus']) : '—' ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
