<div class="page-head"><h1>My deliveries</h1></div>
<?php if (!$jobs): ?><div class="card empty">You have no deliveries yet.</div>
<?php else: ?><div class="grid g2"><?php foreach ($jobs as $j): echo \App\Core\View::partial('job_card', ['j' => $j]); endforeach; ?></div><?php endif; ?>
