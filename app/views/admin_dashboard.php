<div class="grid cards-6">
  <div class="stat-card"><span>Resellers</span><strong><?php echo count($recent_resellers) + max(0, $stats['resellers'] - count($recent_resellers)); ?></strong></div>
  <div class="stat-card"><span>Servers</span><strong><?php echo panel_e($stats['nodes']); ?></strong></div>
  <div class="stat-card"><span>Templates</span><strong><?php echo panel_e($stats['templates']); ?></strong></div>
  <div class="stat-card"><span>Customers</span><strong><?php echo panel_e($stats['customers']); ?></strong></div>
  <div class="stat-card"><span>Open Tickets</span><strong><?php echo panel_e($stats['tickets']); ?></strong></div>
  <div class="stat-card"><span>Total Credit GB</span><strong><?php echo panel_e(panel_format_gb($stats['credit_gb'])); ?></strong></div>
</div>
<div class="card">
  <div class="card-head"><h3>Recent resellers</h3><a class="btn btn-primary" href="<?php echo panel_e($app->url('/admin/resellers/create')); ?>">Create reseller</a></div>
  <table class="table">
    <tr><th>Name</th><th>Prefix</th><th>Credit GB</th><th>Status</th></tr>
    <?php foreach ($recent_resellers as $item): ?>
      <tr><td><?php echo panel_e($item['display_name']); ?></td><td><?php echo panel_e($item['prefix']); ?></td><td><?php echo panel_e(panel_format_gb($item['credit_gb'])); ?></td><td><span class="badge <?php echo $item['status'] === 'active' ? 'good' : 'bad'; ?>"><?php echo panel_e($item['status']); ?></span></td></tr>
    <?php endforeach; ?>
  </table>
</div>
