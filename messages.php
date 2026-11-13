<h1>Messages</h1>
<div class="layout-side mt">
  <div class="side-list"><?php if (!$threads): ?><div class="empty small">No conversations yet. Open a product and tap “Chat with seller”.</div><?php endif; ?>
    <?php foreach ($threads as $t): ?><a class="<?= $with && (int)$with['id'] === (int)$t['id'] ? 'on' : '' ?>" href="/messages/<?= (int)$t['id'] ?>"><?= e($t['name']) ?><?= (int)$t['unread'] ? ' <span class="badge badge-ok">' . (int)$t['unread'] . '</span>' : '' ?></a><?php endforeach; ?></div>
  <div class="card"><?php if (!$with): ?><div class="empty">Select a conversation.</div><?php else: ?>
    <h3><?= e($with['name']) ?></h3>
    <div class="chat"><?php foreach ($chat as $m): $me = (int)$m['from_id'] === (int)\App\Core\Auth::id(); ?><div class="bubble <?= $me ? 'me' : '' ?>"><?= nl2br(e($m['body'])) ?><small><?= dt($m['created_at'], 'M j, H:i') ?></small></div><?php endforeach; ?></div>
    <form method="post" action="/messages/send" class="row mt" data-once><?= csrf_field() ?><input type="hidden" name="to" value="<?= (int)$with['id'] ?>"><input class="grow" type="text" name="body" maxlength="1000" placeholder="Type a message…" required aria-label="Message"><button class="btn" type="submit">Send</button></form>
  <?php endif; ?></div>
</div>
