<?php
$stateCounts = isset($customer_state_counts) && is_array($customer_state_counts) ? $customer_state_counts : array('all' => count($customers), 'live' => 0, 'ended' => 0);
$bucket = isset($bucket) ? $bucket : 'all';
$queryStringBase = 'sort=' . rawurlencode($sort) . '&q=' . rawurlencode($query);
?>
<div class="card">
  <div class="card-head">
    <h3>Customers</h3>
    <div class="actions">
      <?php if (!empty($sync_visible_ids)): ?>
      <form method="post" action="<?php echo panel_e($app->url('/' . $scope . '/customers/sync-visible')); ?>">
        <input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>">
        <?php foreach ($sync_visible_ids as $syncId): ?><input type="hidden" name="ids[]" value="<?php echo panel_e($syncId); ?>"><?php endforeach; ?>
        <button class="btn" type="submit">Quick Sync Visible</button>
      </form>
      <?php endif; ?>
      <?php if ($scope === 'admin' && $bucket === 'ended' && (int) panel_array_get($stateCounts, 'ended', 0) > 0): ?>
      <form method="post" action="<?php echo panel_e($app->url('/admin/customers/clear-ended')); ?>" onsubmit="return confirm('Clear all depleted, ended, and removed customers from the panel list? This removes them locally and cannot be undone.');">
        <input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>">
        <button class="btn btn-danger" type="submit">Clear Depleted / Ended / Removed</button>
      </form>
      <?php endif; ?>
      <?php if ($scope === 'reseller'): ?>
        <a class="btn btn-primary" href="<?php echo panel_e($app->url('/reseller/customers/create')); ?>">Create XUI client</a>
        <a class="btn btn-success" href="<?php echo panel_e($app->url('/reseller/customers/create-um')); ?>">Create UM client</a>
      <?php endif; ?>
    </div>
  </div>
  <div class="actions" style="margin-bottom:14px;">
    <a class="btn <?php echo $bucket === 'all' ? 'btn-primary' : ''; ?>" href="<?php echo panel_e($app->url('/' . $scope . '/customers') . '?' . $queryStringBase . '&bucket=all'); ?>">All <span class="badge muted" style="margin-left:8px;"><?php echo (int) panel_array_get($stateCounts, 'all', 0); ?></span></a>
    <a class="btn <?php echo $bucket === 'live' ? 'btn-primary' : ''; ?>" href="<?php echo panel_e($app->url('/' . $scope . '/customers') . '?' . $queryStringBase . '&bucket=live'); ?>">Active <span class="badge good" style="margin-left:8px;"><?php echo (int) panel_array_get($stateCounts, 'live', 0); ?></span></a>
    <a class="btn <?php echo $bucket === 'ended' ? 'btn-primary' : ''; ?>" href="<?php echo panel_e($app->url('/' . $scope . '/customers') . '?' . $queryStringBase . '&bucket=ended'); ?>">Depleted / Ended / Removed <span class="badge bad" style="margin-left:8px;"><?php echo (int) panel_array_get($stateCounts, 'ended', 0); ?></span></a>
  </div>
  <form class="toolbar" method="get">
    <input type="hidden" name="bucket" value="<?php echo panel_e($bucket); ?>">
    <input type="text" name="q" placeholder="Search customers" value="<?php echo panel_e($query); ?>">
    <select name="sort"><option value="new" <?php echo $sort === 'new' ? 'selected' : ''; ?>>Newest</option><option value="name" <?php echo $sort === 'name' ? 'selected' : ''; ?>>Name</option><option value="traffic" <?php echo $sort === 'traffic' ? 'selected' : ''; ?>>Traffic Left</option></select>
    <button class="btn" type="submit">Apply</button>
  </form>
  <div class="muted-box" style="margin:8px 0 12px;">
    These customer usage values may be stale until the next sync. Visible rows can auto-refresh in small batches to reduce server overhead.
    <?php if (!empty($pagination_enabled)): ?> Pagination is enabled: <?php echo (int) $per_page; ?> row(s) per page.<?php endif; ?>
  </div>
  <?php if (!empty($sync_visible_ids)): ?><p class="muted" style="margin:8px 0 12px;">The page can auto-refresh up to <?php echo (int) $auto_sync_batch_limit; ?> stale customers older than <?php echo (int) ceil($auto_sync_window_seconds / 60); ?> minute(s) to reduce node overhead.</p><?php endif; ?>
  <table class="table">
    <tr><th>Name</th><th>Server / Profile</th><th>Traffic</th><th>Used</th><th>Left</th><th>Expires</th><th>Status</th><th>Access</th><th>Actions</th></tr>
    <?php foreach ($customers as $item):
      $tpl = isset($template_map[$item['template_id']]) ? $template_map[$item['template_id']] : null;
      $node = $tpl && isset($node_map[$tpl['node_id']]) ? $node_map[$tpl['node_id']] : null;
      $type = $app->customerServerType($item, $tpl, $node);
      $typeLabel = strtoupper($type);
      $typeBadge = $type === 'um' ? 'info' : 'good';
      $entry = $app->buildCustomerPublicPayload($item, $tpl, $node, array('include_configs' => false));
      $runtimeStatusLabel = $app->customerRuntimeStatusLabel($item);
      $runtimeStatusClass = $app->customerRuntimeStatusBadgeClass($item);
      $serviceUser = isset($entry['service_username']) ? $entry['service_username'] : '';
      $servicePass = isset($entry['service_password']) ? $entry['service_password'] : '';
      $umConnection = isset($entry['um_connection']) && is_array($entry['um_connection']) ? $entry['um_connection'] : array();
    ?>
      <tr>
        <td>
          <a href="<?php echo panel_e($app->url('/' . $scope . '/customers/' . $item['id'])); ?>"><?php echo panel_e($item['display_name']); ?></a>
          <br><small><?php echo panel_e($item['system_name']); ?></small>
          <br><span class="badge <?php echo panel_e($typeBadge); ?>"><?php echo panel_e($typeLabel); ?></span>
          <?php if (!empty($item['phone'])): ?><br><small>Phone: <?php echo panel_e($item['phone']); ?></small><?php endif; ?>
          <?php if (!empty($item['email'])): ?><br><small>Email: <?php echo panel_e($item['email']); ?></small><?php endif; ?>
        </td>
        <td>
          <?php echo panel_e($node ? $node['title'] : 'Unknown'); ?>
          <br><small><?php echo panel_e($type === 'um' ? panel_array_get($tpl, 'um_profile_name', '') : panel_array_get($tpl, 'inbound_name', '')); ?></small>
        </td>
        <td><?php echo panel_e(panel_format_gb($item['traffic_gb'])); ?> GB</td>
        <td><?php echo panel_e(panel_format_gb(panel_to_gb_from_bytes($item['traffic_bytes_used']))); ?> GB</td>
        <td><?php echo panel_e(panel_format_gb(panel_to_gb_from_bytes($item['traffic_bytes_left']))); ?> GB</td>
        <td><?php echo panel_e($app->customerExpirationLabel($item)); ?></td>
        <td><span class="badge <?php echo panel_e($runtimeStatusClass); ?>"><?php echo panel_e($runtimeStatusLabel); ?></span><br><small>Last sync: <?php echo panel_e($app->customerLastSyncAgo($item)); ?></small></td>
        <td>
          <?php if ($type === 'um'): ?>
            <div><strong>User:</strong> <code><?php echo panel_e($serviceUser !== '' ? $serviceUser : '-'); ?></code></div>
            <div><strong>Pass:</strong> <code><?php echo panel_e($servicePass !== '' ? $servicePass : '-'); ?></code></div>
            <?php if (!empty($entry['public_access_allowed'])): ?>
              <div class="actions compact-actions">
                <button class="btn" type="button" data-copy="<?php echo panel_e($serviceUser); ?>">Copy User</button>
                <button class="btn" type="button" data-copy="<?php echo panel_e($servicePass); ?>">Copy Pass</button>
                <a class="btn" target="_blank" href="<?php echo panel_e($app->url('/user/' . $item['subscription_key'])); ?>">Open Public</a>
                <a class="btn" target="_blank" href="<?php echo panel_e($app->url('/user/' . $item['subscription_key'] . '/export')); ?>">Export</a>
              </div>
              <?php if (!empty($umConnection['value'])): ?>
                <small><?php echo panel_e($umConnection['type'] === 'file' ? 'File' : 'Info'); ?>: <code><?php echo panel_e($umConnection['value']); ?></code></small>
              <?php endif; ?>
            <?php else: ?>
              <small class="muted">Public delivery hidden while inactive.</small>
            <?php endif; ?>
          <?php else: ?>
            <code><?php echo panel_e($entry['primary_subscription_url']); ?></code>
            <?php if (!empty($entry['fallback_subscription_url'])): ?><br><small>Fallback: <code><?php echo panel_e($entry['fallback_subscription_url']); ?></code></small><?php endif; ?>
            <div class="actions compact-actions">
              <button class="btn" type="button" data-copy="<?php echo panel_e($entry['primary_subscription_url']); ?>">Copy</button>
              <?php if (!empty($entry['fallback_subscription_url'])): ?><button class="btn" type="button" data-copy="<?php echo panel_e($entry['fallback_subscription_url']); ?>">Copy Fallback</button><?php endif; ?>
              <a class="btn" target="_blank" href="<?php echo panel_e($app->url('/user/' . $item['subscription_key'])); ?>">Open Public</a>
              <a class="btn" target="_blank" href="<?php echo panel_e($app->url('/user/' . $item['subscription_key'] . '/export')); ?>">Export</a>
            </div>
          <?php endif; ?>
        </td>
        <td class="actions">
          <?php if ($scope === 'reseller'): ?><a class="btn" href="<?php echo panel_e($app->url('/reseller/customers/' . $item['id'] . '/edit')); ?>">Edit</a><?php endif; ?>
          <form method="post" action="<?php echo panel_e($app->url('/' . $scope . '/customers/' . $item['id'] . '/sync')); ?>"><input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>"><button class="btn" type="submit">Sync</button></form>
          <?php if (!($scope === 'reseller' && !empty($reseller) && !empty($reseller['restrict']))): ?>
          <form method="post" action="<?php echo panel_e($app->url('/' . $scope . '/customers/' . $item['id'] . '/toggle')); ?>"><input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>"><button class="btn" type="submit">Toggle</button></form>
          <form method="post" action="<?php echo panel_e($app->url('/' . $scope . '/customers/' . $item['id'] . '/delete')); ?>" onsubmit="return confirm('Delete customer?');"><input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>"><button class="btn btn-danger" type="submit">Delete</button></form>
          <?php else: ?>
          <span class="badge muted">Toggle restricted</span>
          <span class="badge muted">Delete restricted</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($customers)): ?>
      <tr><td colspan="9"><div class="muted-box">No customers found in this section.</div></td></tr>
    <?php endif; ?>
  </table>
  <?php if (!empty($pagination_enabled) && !empty($page_count) && $page_count > 1): ?>
  <div class="actions" style="margin-top:14px; justify-content:space-between; align-items:center;">
    <div class="muted">Page <?php echo (int) $current_page; ?> of <?php echo (int) $page_count; ?> · Total customers: <?php echo (int) $total_customers; ?></div>
    <div class="actions">
      <?php $baseQuery = array('q' => $query, 'sort' => $sort, 'bucket' => $bucket); ?>
      <?php if ($current_page > 1): ?><a class="btn" href="<?php echo panel_e($app->url('/' . $scope . '/customers?' . http_build_query(array_merge($baseQuery, array('page' => $current_page - 1))))); ?>">&larr; Prev</a><?php endif; ?>
      <?php if ($current_page < $page_count): ?><a class="btn" href="<?php echo panel_e($app->url('/' . $scope . '/customers?' . http_build_query(array_merge($baseQuery, array('page' => $current_page + 1))))); ?>">Next &rarr;</a><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php if (!empty($sync_visible_ids)): ?>
<form id="customer-auto-sync-form" method="post" action="<?php echo panel_e($app->url('/' . $scope . '/customers/sync-visible')); ?>" style="display:none">
  <input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>">
  <?php foreach ($sync_visible_ids as $syncId): ?><input type="hidden" name="ids[]" value="<?php echo panel_e($syncId); ?>"><?php endforeach; ?>
</form>
<?php endif; ?>
<?php if (!empty($auto_sync_should_run) && !empty($sync_visible_ids)): ?>
<script>
(function(){
  var ran=false;
  function submitSync(){
    if(ran){return;}
    ran=true;
    var form=document.getElementById('customer-auto-sync-form');
    if(form){ form.submit(); }
  }
  if(document.readyState==='loading'){
    document.addEventListener('DOMContentLoaded', function(){ setTimeout(submitSync, 700); });
  } else {
    setTimeout(submitSync, 700);
  }
})();
</script>
<?php endif; ?>
