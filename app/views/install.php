<h2>Install panel</h2>
<form method="post" class="stack-form">
  <input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>">
  <label>Application Name<input type="text" name="app_name" value="<?php echo panel_e(isset($old['app_name']) ? $old['app_name'] : 'XUI Reseller Panel'); ?>"></label>
  <label>Application URL<input type="text" name="app_url" value="<?php echo panel_e(isset($old['app_url']) ? $old['app_url'] : ''); ?>"></label>
  <label>Timezone<input type="text" name="timezone" value="<?php echo panel_e(isset($old['timezone']) ? $old['timezone'] : 'Europe/Sofia'); ?>"></label>
  <label>Default Duration Days<input type="number" min="1" name="default_duration_days" value="<?php echo panel_e(isset($old['default_duration_days']) ? $old['default_duration_days'] : '30'); ?>"></label>
  <label>Admin Username<input type="text" name="admin_username" value="<?php echo panel_e(isset($old['admin_username']) ? $old['admin_username'] : 'admin'); ?>"></label>
  <label>Admin Password<input type="password" name="admin_password"></label>
  <?php if (!empty($errors)): ?><div class="form-errors"><?php foreach ($errors as $group): foreach ($group as $err): ?><div><?php echo panel_e($err); ?></div><?php endforeach; endforeach; ?></div><?php endif; ?>
  <button class="btn btn-primary" type="submit">Install</button>
</form>
