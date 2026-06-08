<div class="card public-card">
  <h1>Get Your Access</h1>
  <p class="muted-box">Enter your phone or email and your PIN to view all matching client access details, usage, and delivery information. Phone is the primary access method; email works as a secondary method when it is saved for the client.</p>
  <form method="post" class="stack-form public-get-form">
    <div class="grid two-col form-grid-tight">
      <label>Phone or Email
        <input type="text" maxlength="190" name="access" value="<?php echo panel_e(isset($access) ? $access : ''); ?>" placeholder="Phone number or email">
      </label>
      <label>PIN
        <input type="password" maxlength="6" name="pin" value="" placeholder="1 to 6 letters or numbers">
      </label>
    </div>
    <?php if (!empty($errors)): ?><div class="form-errors"><?php foreach ($errors as $group): foreach ($group as $err): ?><div><?php echo panel_e($err); ?></div><?php endforeach; endforeach; ?></div><?php endif; ?>
    <input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>">
    <div class="actions">
      <button class="btn btn-primary" type="submit">Show My Access</button>
      <a class="btn" href="<?php echo panel_e($app->url('/get')); ?>">Reset</a>
    </div>
  </form>
</div>

<?php foreach ($entries as $entry):
  $customer = $entry['customer'];
  $template = $entry['template'];
  $node = $entry['node'];
  $configs = isset($entry['configs']) ? (array) $entry['configs'] : array();
  $type = $app->customerServerType($customer, $template, $node);
  $serviceUser = isset($entry['service_username']) ? $entry['service_username'] : '';
  $servicePass = isset($entry['service_password']) ? $entry['service_password'] : '';
  $umConnection = isset($entry['um_connection']) && is_array($entry['um_connection']) ? $entry['um_connection'] : array();
  $utilityLinks = isset($entry['utility_links']) && is_array($entry['utility_links']) ? $entry['utility_links'] : array();
  $traffic = isset($entry['traffic']) && is_array($entry['traffic']) ? $entry['traffic'] : $app->customerPublicTrafficSummary($customer);
?>
<div class="card public-card access-card">
  <?php $runtimeStatus = $app->customerRuntimeStatusLabel($customer); $runtimeBadge = $app->customerRuntimeStatusBadgeClass($customer); $runtimeState = $app->customerRuntimeState($customer); $rawStatus = isset($customer['status']) ? strtolower(trim((string) $customer['status'])) : 'active'; ?>
  <div class="card-head"><h3><?php echo panel_e($customer['display_name']); ?> <span class="badge <?php echo panel_e($type === 'um' ? 'info' : 'good'); ?>"><?php echo panel_e(strtoupper($type)); ?></span></h3><span class="badge <?php echo panel_e($runtimeBadge); ?>"><?php echo panel_e($runtimeStatus); ?></span></div>
  <?php if ($runtimeState !== 'active'): ?>
    <div class="alert alert-info">
      This account is currently <?php echo panel_e($runtimeStatus); ?>.
      <?php echo panel_e(isset($entry['public_access_message']) ? $entry['public_access_message'] : ''); ?>
      <?php if ($rawStatus !== strtolower($runtimeStatus)): ?><span class="muted">(Local state: <?php echo panel_e($rawStatus); ?>)</span><?php endif; ?>
    </div>
  <?php endif; ?>
  <div class="grid two-col access-summary">
    <div>
      <p><strong>Server:</strong> <?php echo panel_e($node ? $node['title'] : 'Unknown'); ?></p>
      <p><strong><?php echo $type === 'um' ? 'Profile' : 'Inbound'; ?>:</strong> <?php echo panel_e($type === 'um' ? panel_array_get($template, 'um_profile_name', 'Unknown') : ($template ? $template['inbound_name'] : 'Unknown')); ?></p>
      <p><strong>Phone:</strong> <?php echo panel_e(!empty($customer['phone']) ? $customer['phone'] : '-'); ?></p>
      <p><strong>Email:</strong> <?php echo panel_e(!empty($customer['email']) ? $customer['email'] : '-'); ?></p>
      <p><strong>Used:</strong> <?php echo panel_e(panel_format_gb(isset($traffic['used_gb']) ? $traffic['used_gb'] : 0)); ?> GB</p>
      <p><strong>Left:</strong> <?php echo panel_e(panel_format_gb(isset($traffic['left_gb']) ? $traffic['left_gb'] : 0)); ?> GB</p>
      <p><strong>Expires:</strong> <?php echo panel_e($app->customerExpirationLabel($customer)); ?></p>
    </div>
    <div>
      <?php if (!empty($entry['public_access_allowed'])): ?>
        <?php if ($type === 'um'): ?>
          <p><strong>Username</strong></p>
          <div class="sub-line"><code><?php echo panel_e($serviceUser !== '' ? $serviceUser : '-'); ?></code></div>
          <p style="margin-top:12px"><strong>Password</strong></p>
          <div class="sub-line"><code><?php echo panel_e($servicePass !== '' ? $servicePass : '-'); ?></code></div>
          <?php if (!empty($umConnection['value'])): ?>
            <p style="margin-top:12px"><strong><?php echo panel_e($umConnection['type'] === 'file' ? 'Connection file' : 'Connection info'); ?></strong></p>
            <div class="sub-line"><code><?php echo panel_e($umConnection['value']); ?></code></div>
          <?php endif; ?>
          <div class="actions compact-actions">
            <button class="btn" type="button" data-copy="<?php echo panel_e($serviceUser); ?>">Copy User</button>
            <button class="btn" type="button" data-copy="<?php echo panel_e($servicePass); ?>">Copy Pass</button>
            <?php if (!empty($umConnection['value'])): ?><button class="btn" type="button" data-copy="<?php echo panel_e($umConnection['value']); ?>">Copy Info</button><?php endif; ?>
            <a class="btn" target="_blank" href="<?php echo panel_e($app->url('/user/' . $customer['subscription_key'] . '/export')); ?>">Export Text</a>
          </div>
        <?php else: ?>
          <p><strong>Primary subscription URL</strong></p>
          <div class="sub-line"><code><?php echo panel_e($entry['primary_subscription_url']); ?></code></div>
          <div class="actions compact-actions">
            <button class="btn" type="button" data-copy="<?php echo panel_e($entry['primary_subscription_url']); ?>">Copy Primary URL</button>
            <a class="btn" target="_blank" href="<?php echo panel_e($app->url('/user/' . $customer['subscription_key'] . '/export')); ?>">Export Text</a>
          </div>
          <?php if (!empty($entry['fallback_subscription_url'])): ?>
            <p style="margin-top:12px"><strong>Fallback URL</strong></p>
            <div class="sub-line"><code><?php echo panel_e($entry['fallback_subscription_url']); ?></code></div>
            <div class="actions compact-actions">
              <button class="btn" type="button" data-copy="<?php echo panel_e($entry['fallback_subscription_url']); ?>">Copy Fallback URL</button>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      <?php else: ?>
      <div class="alert alert-info"><?php echo $type === 'um' ? 'Public account access is hidden while this customer is not active.' : 'Public links and configs are hidden while this customer is not active.'; ?></div>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!empty($utilityLinks)): ?>
    <div class="actions compact-actions" style="margin-bottom:14px;">
      <?php foreach ($utilityLinks as $tool): ?>
        <a class="btn" href="<?php echo panel_e($tool['url']); ?>" target="_blank" rel="noopener"><?php echo panel_e($tool['name']); ?></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($type === 'um'): ?>
    <?php if (!empty($entry['public_access_allowed'])): ?>
      <textarea rows="8" class="full-text" readonly><?php echo panel_e(implode("\n", $app->buildUmAccessLines($customer, $template, $node))); ?></textarea>
    <?php endif; ?>
  <?php elseif (!empty($configs)): ?>
    <div class="config-grid">
      <?php foreach ($configs as $i => $config): ?>
        <div class="config-card">
          <div class="card-head config-head"><h4>Config <?php echo (int) ($i + 1); ?></h4><button class="btn" type="button" data-copy="<?php echo panel_e($config); ?>">Copy Config</button></div>
          <textarea rows="5" class="full-text" readonly><?php echo panel_e($config); ?></textarea>
          <?php $qrUrl = $app->qrImageUrl($config); ?>
          <?php if ($qrUrl !== ''): ?>
            <div class="config-qr-wrap">
              <img class="config-qr" src="<?php echo panel_e($qrUrl); ?>" alt="QR code for config <?php echo (int) ($i + 1); ?>" loading="lazy" onerror="this.style.display='none'; if(this.nextElementSibling){this.nextElementSibling.style.display='block';}">
              <div class="qr-fallback-note" style="display:none">QR preview unavailable. Use the copy button.</div>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="alert alert-info">No configs could be built for this client yet.</div>
  <?php endif; ?>
</div>
<?php endforeach; ?>
