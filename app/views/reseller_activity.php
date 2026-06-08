<div class="card">
  <div class="card-head"><h3>Your activity logs</h3><a class="btn" href="<?php echo panel_e($app->url('/reseller/dashboard')); ?>">Back to dashboard</a></div>
  <table class="table">
    <tr><th>Time</th><th>Action</th><th>Type</th><th>Customer</th><th>Details</th><th>IP</th></tr>
    <?php foreach ($items as $item): $context = isset($item['context']) && is_array($item['context']) ? $item['context'] : array(); $stype = !empty($context['server_type']) ? strtolower((string) $context['server_type']) : ''; ?>
      <tr>
        <td><?php echo panel_e(isset($item['created_at']) ? $item['created_at'] : ''); ?></td>
        <td><?php echo panel_e($item['action']); ?></td>
        <td><?php if ($stype !== ''): ?><span class="badge <?php echo $stype === 'um' ? 'info' : 'good'; ?>"><?php echo panel_e(strtoupper($stype)); ?></span><?php else: ?><span class="muted">-</span><?php endif; ?></td>
        <td><?php echo panel_e($item['customer_name']); ?><br><small><?php echo panel_e($item['system_name']); ?></small></td>
        <td><code class="inline-code"><?php echo panel_e(json_encode($context)); ?></code></td>
        <td><?php echo panel_e(isset($item['ip']) ? $item['ip'] : ''); ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($items)): ?><tr><td colspan="6"><div class="muted-box">No reseller activity logged yet.</div></td></tr><?php endif; ?>
  </table>
</div>
