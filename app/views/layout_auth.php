<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive,nosnippet,noimageindex">
<title><?php echo panel_e($app_name); ?></title>
<link rel="stylesheet" href="<?php echo panel_e($app->asset('app.css')); ?>">
</head>
<body class="auth-body">
<div class="auth-shell">
  <div class="brand-card">
    <div class="brand-mark">XR</div>
    <h1><?php echo panel_e($app_name); ?></h1>
    <p>Admin and reseller production panel for multi 3x-ui nodes.</p>
    <?php if (!empty($active_notices)): foreach ($active_notices as $notice): ?>
      <div class="alert alert-info" style="margin-top:16px"><strong><?php echo panel_e($notice['title']); ?></strong><br><?php echo nl2br(panel_e($notice['body'])); ?></div>
    <?php endforeach; endif; ?>
  </div>
  <div class="auth-card">
    <?php if ($flash): ?><div class="alert alert-<?php echo panel_e($flash['type']); ?>"><?php echo panel_e($flash['message']); ?></div><?php endif; ?>
    <?php include $view_file; ?>
  </div>
</div>
<script>window.__PANEL_SHIELD_ACTIVE__=<?php echo !empty($shield_forms_enabled) ? 'true' : 'false'; ?>;window.__PANEL_SHIELD_FORM__=<?php echo !empty($shield_forms_enabled) ? 'true' : 'false'; ?>;</script>
<?php if (!empty($shield_forms_enabled)): ?><script src="<?php echo panel_e($app->asset('key.js')); ?>"></script><?php endif; ?>
<script src="<?php echo panel_e($app->asset('app.js')); ?>"></script>
</body>
</html>
