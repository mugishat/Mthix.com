<div class="page-head"><h1>Available jobs</h1></div>
<?php if (!$jobs): ?><div class="card empty">No packed orders are waiting for a courier right now.</div>
<?php else: ?><div class="grid g2"><?php foreach ($jobs as $j): echo \App\Core\View::partial('job_card', ['j' => $j]); endforeach; ?></div><?php endif; ?>
