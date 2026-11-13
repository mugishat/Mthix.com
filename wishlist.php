<h1>Wishlist</h1>
<?php if (!$items): ?><div class="card empty">Nothing saved yet.</div><?php endif; ?>
<div class="prod-grid mt"><?php foreach ($items as $p) { echo \App\Core\View::partial('pcard', ['p' => $p]); } ?></div>
