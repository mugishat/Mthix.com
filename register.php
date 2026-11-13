<?php $has = fn(string $r) => in_array($r, (array)($_SESSION['old']['roles'] ?? []), true) || $preRole === $r; ?>
<div class="auth-wrap">
  <div class="card">
    <h1>Create your account</h1>
    <p class="muted">One account to buy, sell, share and earn.</p>
    <form method="post" action="/register" data-once>
      <?= csrf_field() ?>
      <div class="field"><label for="name">Full name</label><input id="name" type="text" name="name" value="<?= old('name') ?>" required autocomplete="name"></div>
      <div class="field"><label for="email">Email</label><input id="email" type="email" name="email" value="<?= old('email') ?>" required autocomplete="email"></div>
      <div class="field"><label for="phone">Phone number</label><input id="phone" type="tel" name="phone" value="<?= old('phone') ?>" placeholder="0788123456" required autocomplete="tel"></div>
      <div class="form-grid">
        <div class="field"><label for="password">Password</label><input id="password" type="password" name="password" required minlength="8" autocomplete="new-password"><div class="hint">8+ characters, letters and numbers.</div></div>
        <div class="field"><label for="password2">Confirm password</label><input id="password2" type="password" name="password2" required autocomplete="new-password"></div>
      </div>
      <fieldset style="border:1px solid var(--border);border-radius:10px;padding:12px 14px;margin:0 0 14px">
        <legend class="small muted">I also want to… (optional)</legend>
        <label class="check"><input type="checkbox" name="roles[]" value="seller" <?= $has('seller') ? 'checked' : '' ?>> Sell products in my own store</label>
        <label class="check"><input type="checkbox" name="roles[]" value="reseller" <?= $has('reseller') ? 'checked' : '' ?>> Resell products and earn 4% commission</label>
        <label class="check"><input type="checkbox" name="roles[]" value="affiliate" <?= $has('affiliate') ? 'checked' : '' ?>> Refer people and earn 1% commission</label>
        <label class="check"><input type="checkbox" name="roles[]" value="delivery" <?= $has('delivery') ? 'checked' : '' ?>> Deliver orders as a delivery partner</label>
      </fieldset>
      <div class="field"><label for="store_name">Store name <span class="muted">(if selling)</span></label><input id="store_name" type="text" name="store_name" value="<?= old('store_name') ?>"></div>
      <button class="btn btn-block btn-lg" type="submit">Create account</button>
    </form>
    <p class="center mt small">Already registered? <a href="/login">Sign in</a></p>
  </div>
</div>
