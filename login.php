<div class="auth-wrap">
  <div class="card">
    <h1>Sign in</h1>
    <p class="muted">Welcome back to MTHIX.</p>
    <form method="post" action="/login" data-once>
      <?= csrf_field() ?>
      <input type="hidden" name="next" value="<?= e($next) ?>">
      <div class="field"><label for="email">Email</label><input id="email" type="email" name="email" value="<?= old('email') ?>" required autocomplete="email" autofocus></div>
      <div class="field"><label for="password">Password</label><input id="password" type="password" name="password" required autocomplete="current-password"></div>
      <button class="btn btn-block btn-lg" type="submit">Sign in</button>
    </form>
    <p class="center mt small">New to MTHIX? <a href="/register">Create an account</a></p>
    <?php if (cfg('env') === 'dev'): ?>
      <details class="mt small"><summary class="muted">Demo accounts (dev only)</summary>
        <p class="muted">Password for all: <code>Mthix@2026</code><br>
        buyer@mthix.rw · seller@mthix.rw · reseller@mthix.rw · affiliate@mthix.rw · delivery@mthix.rw · admin@mthix.rw · finance@mthix.rw · support@mthix.rw</p>
      </details>
    <?php endif; ?>
  </div>
</div>
