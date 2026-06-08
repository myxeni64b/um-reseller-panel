<div class="card">
  <div class="card-head"><h3>Templates</h3><a class="btn btn-primary" href="<?php echo panel_e($app->url('/admin/templates/create')); ?>">Add template</a></div>
  <table class="table">
    <tr><th>Title</th><th>Type</th><th>Server</th><th>Template / Profile</th><th>Usage</th><th>Status</th><th>Actions</th></tr>
    <?php foreach ($templates as $item): $node = isset($node_map[$item['node_id']]) ? $node_map[$item['node_id']] : null; $stype = !empty($item['server_type']) ? strtolower((string) $item['server_type']) : 'xui'; ?>
      <tr>
        <td><?php echo panel_e($item['public_label']); ?></td>
        <td><span class="badge <?php echo $stype === 'um' ? 'info' : 'good'; ?>"><?php echo strtoupper(panel_e($stype)); ?></span></td>
        <td><?php echo panel_e($node ? $node['title'] : 'Unknown'); ?></td>
        <td>
          <?php if ($stype === 'um'): ?>
            <?php echo panel_e(isset($item['um_profile_name']) ? $item['um_profile_name'] : $item['title']); ?> (#<?php echo panel_e(isset($item['um_profile_id']) ? $item['um_profile_id'] : ''); ?>)
          <?php else: ?>
            <?php echo panel_e($item['inbound_name']); ?> (#<?php echo panel_e($item['inbound_id']); ?>)
          <?php endif; ?>
        </td>
        <td>
          <?php if ($stype === 'um'): ?>Billing: <?php echo panel_e(panel_format_gb(isset($item['billing_gb']) ? $item['billing_gb'] : 0)); ?> GB<?php else: ?><?php echo panel_e($item['protocol']); ?><br><small><?php echo panel_e(isset($item['network']) ? $item['network'] : '-'); ?> / <?php echo panel_e(isset($item['security']) ? $item['security'] : '-'); ?></small><?php if (!empty($item['client_extra_query'])): ?><br><small><span class="badge info">Extra URI fields</span></small><?php endif; ?><?php endif; ?>
        </td>
        <td><span class="badge <?php echo $item['status'] === 'active' ? 'good' : 'bad'; ?>"><?php echo panel_e($item['status']); ?></span></td>
        <td class="actions">
          <a class="btn" href="<?php echo panel_e($app->url('/admin/templates/' . $item['id'] . '/edit')); ?>">Edit</a>
          <form method="post" action="<?php echo panel_e($app->url('/admin/templates/' . $item['id'] . '/toggle')); ?>"><input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>"><button class="btn" type="submit">Toggle</button></form>
          <form method="post" action="<?php echo panel_e($app->url('/admin/templates/' . $item['id'] . '/delete')); ?>" onsubmit="return confirm('Delete template?');"><input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>"><button class="btn btn-danger" type="submit">Delete</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
