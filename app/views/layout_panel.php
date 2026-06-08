<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive,nosnippet,noimageindex">
<title><?php echo panel_e($title); ?> - <?php echo panel_e($app_name); ?></title>
<link rel="stylesheet" href="<?php echo panel_e($app->asset('app.css')); ?>">
</head>
<body>
<div class="panel-shell">
  <aside class="sidebar">
    <div class="sidebar-brand">
      <div class="brand-mark small">XR</div>
      <div>
        <strong><?php echo panel_e($app_name); ?></strong>
        <small><?php echo panel_e($auth['role']); ?></small>
      </div>
    </div>
    <nav class="nav-list">
      <?php if ($auth['role'] === 'admin'): ?>
        <a class="nav-link <?php echo strpos($current_path, '/admin/dashboard') === 0 ? 'active' : ''; ?>" href="<?php echo panel_e($app->url('/admin/dashboard')); ?>">Dashboard</a>
        <a class="nav-link <?php echo strpos($current_path, '/admin/resellers') === 0 ? 'active' : ''; ?>" href="<?php echo panel_e($app->url('/admin/resellers')); ?>">Resellers</a>
        <a class="nav-link <?php echo strpos($current_path, '/admin/nodes') === 0 ? 'active' : ''; ?>" href="<?php echo panel_e($app->url('/admin/nodes')); ?>">Servers</a>
        <a class="nav-link <?php echo strpos($current_path, '/admin/templates') === 0 ? 'active' : ''; ?>" href="<?php echo panel_e($app->url('/admin/templates')); ?>">Inbound Templates</a>
        <a class="nav-link <?php echo strpos($current_path, '/admin/notices') === 0 ? 'active' : ''; ?>" href="<?php echo panel_e($app->url('/admin/notices')); ?>">Notices</a>
        <a class="nav-link <?php echo strpos($current_path, '/admin/activity') === 0 ? 'active' : ''; ?>" href="<?php echo panel_e($app->url('/admin/activity')); ?>">Activity Logs</a>
        <a class="nav-link <?php echo strpos($current_path, '/admin/transactions') === 0 ? 'active' : ''; ?>" href="<?php echo panel_e($app->url('/admin/transactions')); ?>">Transactions</a>
        <a class="nav-link <?php echo strpos($current_path, '/admin/logs') === 0 ? 'active' : ''; ?>" href="<?php echo panel_e($app->url('/admin/logs')); ?>">System Logs</a>
        <a class="nav-link <?php echo strpos($current_path, '/admin/customers') === 0 ? 'active' : ''; ?>" href="<?php echo panel_e($app->url('/admin/customers')); ?>">Customers</a>
        <a class="nav-link <?php echo strpos($current_path, '/admin/tickets') === 0 ? 'active' : ''; ?>" href="<?php echo panel_e($app->url('/admin/tickets')); ?>">Tickets</a>
        <a class="nav-link <?php echo strpos($current_path, '/admin/settings') === 0 ? 'active' : ''; ?>" href="<?php echo panel_e($app->url('/admin/settings')); ?>">Settings</a>
      <?php else: ?>
        <a class="nav-link <?php echo strpos($current_path, '/reseller/dashboard') === 0 ? 'active' : ''; ?>" href="<?php echo panel_e($app->url('/reseller/dashboard')); ?>">Dashboard</a>
        <a class="nav-link <?php echo strpos($current_path, '/reseller/customers') === 0 ? 'active' : ''; ?>" href="<?php echo panel_e($app->url('/reseller/customers')); ?>">Customers</a>
        <a class="nav-link <?php echo strpos($current_path, '/reseller/transactions') === 0 ? 'active' : ''; ?>" href="<?php echo panel_e($app->url('/reseller/transactions')); ?>">Transactions</a>
        <a class="nav-link <?php echo strpos($current_path, '/reseller/activity') === 0 ? 'active' : ''; ?>" href="<?php echo panel_e($app->url('/reseller/activity')); ?>">Activity Logs</a>
        <a class="nav-link <?php echo strpos($current_path, '/reseller/tickets') === 0 ? 'active' : ''; ?>" href="<?php echo panel_e($app->url('/reseller/tickets')); ?>">Tickets</a>
        <a class="nav-link <?php echo strpos($current_path, '/reseller/profile') === 0 ? 'active' : ''; ?>" href="<?php echo panel_e($app->url('/reseller/profile')); ?>">Profile</a>
      <?php endif; ?>
      <a class="nav-link" href="<?php echo panel_e($app->url('/logout?_token=' . rawurlencode($app->csrfToken()))); ?>">Logout</a>
    </nav>
  </aside>
  <main class="content">
    <header class="topbar">
      <div>
        <h1><?php echo panel_e($title); ?></h1>
        <p>Welcome, <?php echo panel_e($auth['display_name']); ?></p>
      </div>
    </header>
    <?php if ($flash): ?><div class="alert alert-<?php echo panel_e($flash['type']); ?>"><?php echo panel_e($flash['message']); ?></div><?php endif; ?>
    <?php if (!empty($active_notices)): foreach ($active_notices as $notice): ?>
      <div class="alert alert-info"><strong><?php echo panel_e($notice['title']); ?></strong><br><?php echo nl2br(panel_e($notice['body'])); ?></div>
    <?php endforeach; endif; ?>
    <?php include $view_file; ?>
  </main>
</div>
<script>window.__PANEL_SHIELD_ACTIVE__=<?php echo !empty($shield_forms_enabled) ? 'true' : 'false'; ?>;window.__PANEL_SHIELD_FORM__=<?php echo !empty($shield_forms_enabled) ? 'true' : 'false'; ?>;</script>
<?php if (!empty($shield_forms_enabled)): ?><script src="<?php echo panel_e($app->asset('key.js')); ?>"></script><?php endif; ?>
<script src="<?php echo panel_e($app->asset('app.js')); ?>"></script>
</body>
</html>
