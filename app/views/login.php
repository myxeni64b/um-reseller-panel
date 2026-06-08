<h2>Sign in</h2>
<form method="post" class="stack-form">
  <input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>">
  <label>Username<input type="text" name="username" value="<?php echo panel_e(isset($old['username']) ? $old['username'] : ''); ?>" autocomplete="username"></label>
  <label>Password<input type="password" name="password" autocomplete="current-password"></label>
  <p class="muted-box">The panel automatically detects whether this username belongs to an admin or a reseller.</p>
  <?php if (!empty($errors)): ?><div class="form-errors"><?php foreach ($errors as $group): foreach ($group as $err): ?><div><?php echo panel_e($err); ?></div><?php endforeach; endforeach; ?></div><?php endif; ?>
  <button class="btn btn-primary" type="submit">Login</button>
</form>
