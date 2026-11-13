<?php
use App\Core\Auth;
$u = Auth::check();
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · MTHIX</title><link rel="stylesheet" href="<?= asset('app.css') ?>"></head>
<body class="public"><div class="container err-page">
  <a class="logo" href="/"><b>M</b>THIX</a>
  <h1><?= (int)$code ?></h1>
  <h2><?= e($title) ?></h2>
  <?php if ($message): ?><p class="muted"><?= e($message) ?></p><?php endif; ?>
  <p class="mt"><a class="btn" href="/">Back to the marketplace</a>
  <?php if (!$u): ?> <a class="btn btn-light" href="/login">Sign in</a><?php endif; ?></p>
</div></body></html>
