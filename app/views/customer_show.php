<?php
$type = $app->customerServerType($customer, $template, $node);
$typeLabel = strtoupper($type);
$typeBadge = $type === 'um' ? 'info' : 'good';
$entry = isset($entry) && is_array($entry) ? $entry : $app->buildCustomerPublicPayload($customer, $template, $node);
$serviceUser = isset($entry['service_username']) ? $entry['service_username'] : '';
$servicePass = isset($entry['service_password']) ? $entry['service_password'] : '';
$umConnection = isset($entry['um_connection']) && is_array($entry['um_connection']) ? $entry['um_connection'] : array();
$utilityLinks = isset($entry['utility_links']) && is_array($entry['utility_links']) ? $entry['utility_links'] : array();
?>
<div class="grid two-col">
  <div class="card">
    <h3><?php echo panel_e($customer['display_name']); ?> <span class="badge <?php echo panel_e($typeBadge); ?>"><?php echo panel_e($typeLabel); ?></span></h3>
    <p><strong>System Name:</strong> <?php echo panel_e($customer['system_name']); ?></p>
    <p><strong>Phone:</strong> <?php echo panel_e(!empty($customer['phone']) ? $customer['phone'] : '-'); ?></p>
    <p><strong>Email:</strong> <?php echo panel_e(!empty($customer['email']) ? $customer['email'] : '-'); ?></p>
    <p><strong>PIN:</strong> <?php echo panel_e(!empty($customer['pin_code']) ? $customer['pin_code'] : '-'); ?></p>
    <p><strong>Server:</strong> <?php echo panel_e($node ? $node['title'] : 'Unknown'); ?></p>
    <p><strong><?php echo $type === 'um' ? 'Profile' : 'Inbound'; ?>:</strong> <?php echo panel_e($type === 'um' ? panel_array_get($template, 'um_profile_name', 'Unknown') : panel_array_get($template, 'inbound_name', 'Unknown')); ?></p>
    <p><strong>Traffic:</strong> <?php echo panel_e(panel_format_gb(panel_array_get($customer, 'traffic_gb', 0))); ?> GB</p>
    <p><strong>Used:</strong> <?php echo panel_e(panel_format_gb(panel_to_gb_from_bytes(panel_array_get($customer, 'traffic_bytes_used', 0)))); ?> GB</p>
    <p><strong>Left:</strong> <?php echo panel_e(panel_format_gb(panel_to_gb_from_bytes(panel_array_get($customer, 'traffic_bytes_left', 0)))); ?> GB</p>
    <p><strong>Expires:</strong> <?php echo panel_e($app->customerExpirationLabel($customer)); ?></p>
    <p><strong>Status:</strong> <span class="badge <?php echo panel_e($app->customerRuntimeStatusBadgeClass($customer)); ?>"><?php echo panel_e($app->customerRuntimeStatusLabel($customer)); ?></span></p>
    <p><strong>Last sync:</strong> <?php echo panel_e($app->customerLastSyncAgo($customer)); ?></p>
    <?php if (!empty($customer['created_at'])): ?><p><strong>Created:</strong> <?php echo panel_e($customer['created_at']); ?></p><?php endif; ?>
    <?php if (!empty($customer['updated_at'])): ?><p><strong>Updated:</strong> <?php echo panel_e($customer['updated_at']); ?></p><?php endif; ?>
  </div>

  <div class="card">
    <h3><?php echo $type === 'um' ? 'Access / Delivery' : 'Subscription / Delivery'; ?></h3>
    <?php if ($type === 'um'): ?>
      <p><strong>Username:</strong> <code><?php echo panel_e($serviceUser !== '' ? $serviceUser : '-'); ?></code></p>
      <p><strong>Password:</strong> <code><?php echo panel_e($servicePass !== '' ? $servicePass : '-'); ?></code></p>
      <?php if (!empty($umConnection['value'])): ?>
        <p><strong><?php echo panel_e($umConnection['type'] === 'file' ? 'Connection file' : 'Connection info'); ?>:</strong>
          <?php if ($umConnection['type'] === 'file'): ?>
            <a href="<?php echo panel_e($umConnection['value']); ?>" target="_blank"><?php echo panel_e(!empty($umConnection['file_name']) ? $umConnection['file_name'] : $umConnection['value']); ?></a>
          <?php else: ?>
            <code><?php echo panel_e($umConnection['value']); ?></code>
          <?php endif; ?>
        </p>
      <?php endif; ?>
      <?php if (!empty($entry['public_access_allowed'])): ?>
        <div class="actions compact-actions">
          <button class="btn" type="button" data-copy="<?php echo panel_e($serviceUser); ?>">Copy User</button>
          <button class="btn" type="button" data-copy="<?php echo panel_e($servicePass); ?>">Copy Pass</button>
          <?php if (!empty($umConnection['value'])): ?><button class="btn" type="button" data-copy="<?php echo panel_e($umConnection['value']); ?>">Copy Info</button><?php endif; ?>
          <a class="btn" href="<?php echo panel_e($app->url('/user/' . $customer['subscription_key'])); ?>" target="_blank">Open Public</a>
          <a class="btn" href="<?php echo panel_e($app->url('/user/' . $customer['subscription_key'] . '/export')); ?>" target="_blank">Export</a>
        </div>
      <?php else: ?>
        <div class="alert alert-info"><?php echo panel_e(isset($entry['public_access_message']) ? $entry['public_access_message'] : 'Public delivery is hidden while this customer is not active.'); ?></div>
      <?php endif; ?>
    <?php else: ?>
      <?php $primarySubscriptionUrl = !empty($entry['proxy_subscription_url']) ? $entry['proxy_subscription_url'] : $app->appLink('/user/' . $customer['subscription_key']); ?>
      <?php $fallbackSubscriptionUrl = !empty($entry['proxy_subscription_url']) ? $app->appLink('/user/' . $customer['subscription_key']) : ''; ?>
      <?php if (!empty($entry['public_access_allowed'])): ?>
        <p><strong>Primary subscription URL:</strong> <code><?php echo panel_e($primarySubscriptionUrl); ?></code></p>
        <?php if ($fallbackSubscriptionUrl !== ''): ?><p><strong>Fallback URL:</strong> <code><?php echo panel_e($fallbackSubscriptionUrl); ?></code></p><?php endif; ?>
        <div class="actions compact-actions">
          <button class="btn" type="button" data-copy="<?php echo panel_e($primarySubscriptionUrl); ?>">Copy Primary</button>
          <?php if ($fallbackSubscriptionUrl !== ''): ?><button class="btn" type="button" data-copy="<?php echo panel_e($fallbackSubscriptionUrl); ?>">Copy Fallback</button><?php endif; ?>
          <a class="btn" href="<?php echo panel_e($app->url('/user/' . $customer['subscription_key'])); ?>" target="_blank">Open Public</a>
          <a class="btn" href="<?php echo panel_e($app->url('/user/' . $customer['subscription_key'] . '/export')); ?>" target="_blank">Export</a>
        </div>
        <?php if (!empty($entry['configs'])): ?>
          <textarea rows="12" class="full-text" readonly><?php echo panel_e(implode("\n", (array) $entry['configs'])); ?></textarea>
        <?php endif; ?>
      <?php else: ?>
        <div class="alert alert-info"><?php echo panel_e(isset($entry['public_access_message']) ? $entry['public_access_message'] : 'Public delivery is hidden while this customer is not active.'); ?></div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<?php if (!empty($utilityLinks)): ?>
<div class="card">
  <h3>Tools & Utilities</h3>
  <div class="actions compact-actions">
    <?php foreach ($utilityLinks as $tool): ?>
      <a class="btn" href="<?php echo panel_e($tool['url']); ?>" target="_blank" rel="noopener"><?php echo panel_e($tool['name']); ?></a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>
