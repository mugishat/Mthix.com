<h1>Profile &amp; security</h1>
<div class="grid g2 mt">
<form class="card" method="post" action="/account/settings"><?= csrf_field() ?><input type="hidden" name="form" value="profile"><h3>Profile</h3>
  <div class="field"><label for="n">Full name</label><input id="n" type="text" name="name" value="<?= e($me['name']) ?>" required></div>
  <div class="field"><label for="e">Email</label><input id="e" type="email" value="<?= e($me['email']) ?>" disabled></div>
  <div class="field"><label for="p">Phone</label><input id="p" type="tel" name="phone" value="<?= e((string)$me['phone']) ?>" required></div>
  <button class="btn" type="submit">Save changes</button></form>
<form class="card" method="post" action="/account/settings"><?= csrf_field() ?><input type="hidden" name="form" value="password"><h3>Change password</h3>
  <div class="field"><label for="c">Current password</label><input id="c" type="password" name="current" required autocomplete="current-password"></div>
  <div class="field"><label for="n1">New password</label><input id="n1" type="password" name="password" required autocomplete="new-password"></div>
  <div class="field"><label for="n2">Repeat new password</label><input id="n2" type="password" name="password2" required autocomplete="new-password"></div>
  <button class="btn" type="submit">Update password</button></form>
</div>
