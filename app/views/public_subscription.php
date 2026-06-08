<?php
$type = $app->customerServerType($customer, $template, $node);
$entry = isset($entry) && is_array($entry) ? $entry : $app->buildCustomerPublicPayload($customer, $template, $node);
$serviceUser = isset($entry['service_username']) ? $entry['service_username'] : '';
$servicePass = isset($entry['service_password']) ? $entry['service_password'] : '';
$umConnection = isset($entry['um_connection']) && is_array($entry['um_connection']) ? $entry['um_connection'] : array();
$utilityLinks = isset($entry['utility_links']) && is_array($entry['utility_links']) ? $entry['utility_links'] : array();
$traffic = isset($entry['traffic']) && is_array($entry['traffic']) ? $entry['traffic'] : $app->customerPublicTrafficSummary($customer);
?>
<div class="card public-card">
  <h1><?php echo $type === 'um' ? 'Account Access' : 'Subscription'; ?> for <?php echo panel_e($customer['display_name']); ?></h1>
  <?php $runtimeStatus = $app->customerRuntimeStatusLabel($customer); $runtimeBadge = $app->customerRuntimeStatusBadgeClass($customer); $rawStatus = isset($customer['status']) ? strtolower(trim((string) $customer['status'])) : 'active'; ?>
  <p class="muted">Type: <span class="badge <?php echo panel_e($type === 'um' ? 'info' : 'good'); ?>"><?php echo panel_e(strtoupper($type)); ?></span> · Last sync: <?php echo panel_e($app->customerLastSyncAgo($customer)); ?></p>
  <p>Status: <span class="badge <?php echo panel_e($runtimeBadge); ?>"><?php echo panel_e($runtimeStatus); ?></span><?php if ($rawStatus !== strtolower($runtimeStatus)): ?> <span class="muted">(local: <?php echo panel_e($rawStatus); ?>)</span><?php endif; ?></p>
  <?php if ($runtimeStatus !== 'Active'): ?>
  <div class="alert alert-info"><?php echo panel_e(isset($public_access_message) ? $public_access_message : 'This customer is not currently active.'); ?></div>
  <?php endif; ?>
  <p>Traffic used: <strong><?php echo panel_e(panel_format_gb(isset($traffic['used_gb']) ? $traffic['used_gb'] : 0)); ?> GB</strong></p>
  <p>Traffic left: <strong><?php echo panel_e(panel_format_gb(isset($traffic['left_gb']) ? $traffic['left_gb'] : 0)); ?> GB</strong></p>
  <p>Expires at: <strong><?php echo panel_e($app->customerExpirationLabel($customer)); ?></strong></p>
  <p><?php echo $type === 'um' ? 'Profile' : 'Inbound'; ?>: <strong><?php echo panel_e($type === 'um' ? panel_array_get($template, 'um_profile_name', 'Unknown') : ($template ? $template['inbound_name'] : 'Unknown')); ?></strong></p>
  <p>Server: <strong><?php echo panel_e($node ? $node['title'] : 'Unknown'); ?></strong></p>

  <?php if (!empty($public_access_allowed)): ?>
    <?php if ($type === 'um'): ?>
      <p>Username: <strong><code><?php echo panel_e($serviceUser !== '' ? $serviceUser : '-'); ?></code></strong></p>
      <p>Password: <strong><code><?php echo panel_e($servicePass !== '' ? $servicePass : '-'); ?></code></strong></p>
      <?php if (!empty($umConnection['value'])): ?>
        <p><?php echo panel_e($umConnection['type'] === 'file' ? 'Connection file' : 'Connection info'); ?>:
          <?php if ($umConnection['type'] === 'file'): ?>
            <strong><a href="<?php echo panel_e($umConnection['value']); ?>" target="_blank"><?php echo panel_e(!empty($umConnection['file_name']) ? $umConnection['file_name'] : $umConnection['value']); ?></a></strong>
          <?php else: ?>
            <strong><code><?php echo panel_e($umConnection['value']); ?></code></strong>
          <?php endif; ?>
        </p>
      <?php endif; ?>
      <div class="public-actions actions">
        <button class="btn" type="button" data-copy="<?php echo panel_e($serviceUser); ?>">Copy User</button>
        <button class="btn" type="button" data-copy="<?php echo panel_e($servicePass); ?>">Copy Pass</button>
        <?php if (!empty($umConnection['value'])): ?><button class="btn" type="button" data-copy="<?php echo panel_e($umConnection['value']); ?>">Copy Info</button><?php endif; ?>
        <a class="btn btn-primary" href="<?php echo panel_e($app->url('/user/' . $customer['subscription_key'] . '/export')); ?>">Export access text</a>
      </div>
      <textarea rows="10" class="full-text" readonly><?php echo panel_e(implode("\n", $app->buildUmAccessLines($customer, $template, $node))); ?></textarea>
    <?php else: ?>
      <?php $primarySubscriptionUrl = !empty($entry['proxy_subscription_url']) ? $entry['proxy_subscription_url'] : $app->appLink('/user/' . $customer['subscription_key']); ?>
      <?php $fallbackSubscriptionUrl = !empty($entry['proxy_subscription_url']) ? $app->appLink('/user/' . $customer['subscription_key']) : ''; ?>
      <p>Primary subscription URL: <strong><?php echo panel_e($primarySubscriptionUrl); ?></strong></p>
      <?php if ($fallbackSubscriptionUrl !== ''): ?><p>Fallback subscription URL: <strong><?php echo panel_e($fallbackSubscriptionUrl); ?></strong></p><?php endif; ?>
      <div class="public-actions actions">
        <a class="btn btn-primary" href="<?php echo panel_e($app->url('/user/' . $customer['subscription_key'] . '/export')); ?>">Export all links</a>
        <button class="btn" type="button" data-copy="<?php echo panel_e(implode("\n", (array) $configs)); ?>">Copy all</button>
        <button class="btn" type="button" data-copy="<?php echo panel_e($primarySubscriptionUrl); ?>">Copy Primary URL</button>
        <?php if ($fallbackSubscriptionUrl !== ''): ?><button class="btn" type="button" data-copy="<?php echo panel_e($fallbackSubscriptionUrl); ?>">Copy Fallback URL</button><?php endif; ?>
      </div>
      <div class="config-grid" style="margin-bottom:16px">
        <div class="config-card">
          <div class="card-head config-head"><h4>Primary Subscription QR</h4><button class="btn" type="button" data-copy="<?php echo panel_e($primarySubscriptionUrl); ?>">Copy URL</button></div>
          <?php $primaryQr = $app->qrImageUrl($primarySubscriptionUrl); if ($primaryQr !== ''): ?>
          <div class="config-qr-wrap"><img class="config-qr" src="<?php echo panel_e($primaryQr); ?>" alt="Primary subscription QR"></div>
          <?php endif; ?>
          <textarea rows="3" class="full-text" readonly><?php echo panel_e($primarySubscriptionUrl); ?></textarea>
        </div>
        <?php if ($fallbackSubscriptionUrl !== ''): ?>
        <div class="config-card">
          <div class="card-head config-head"><h4>Fallback Subscription QR</h4><button class="btn" type="button" data-copy="<?php echo panel_e($fallbackSubscriptionUrl); ?>">Copy URL</button></div>
          <?php $fallbackQr = $app->qrImageUrl($fallbackSubscriptionUrl); if ($fallbackQr !== ''): ?>
          <div class="config-qr-wrap"><img class="config-qr" src="<?php echo panel_e($fallbackQr); ?>" alt="Fallback subscription QR"></div>
          <?php endif; ?>
          <textarea rows="3" class="full-text" readonly><?php echo panel_e($fallbackSubscriptionUrl); ?></textarea>
        </div>
        <?php endif; ?>
      </div>
      <textarea rows="10" class="full-text" readonly><?php echo panel_e(implode("\n", (array) $configs)); ?></textarea>
    <?php endif; ?>
  <?php else: ?>
    <div class="alert alert-info"><?php echo $type === 'um' ? 'Account access is hidden while this customer is not active.' : 'Public links, QR codes, and exported configs are hidden while this customer is not active.'; ?></div>
  <?php endif; ?>
  <?php if (!empty($utilityLinks)): ?>
    <div class="card" style="margin-top:16px;">
      <h3>Tools & Utilities</h3>
      <div class="actions">
        <?php foreach ($utilityLinks as $tool): ?>
          <a class="btn" href="<?php echo panel_e($tool['url']); ?>" target="_blank" rel="noopener"><?php echo panel_e($tool['name']); ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>
</div>
