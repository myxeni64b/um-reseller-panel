<?php $serverType = isset($record['server_type']) ? strtolower((string) $record['server_type']) : 'xui'; ?>
<form method="post" class="stack-form card">
  <label>Customer Name<input type="text" name="display_name" value="<?php echo panel_e($record['display_name']); ?>"></label>
  <div class="grid two-col form-grid-tight">
    <label>Phone Number <small class="label-hint">Optional · digits only</small>
      <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="20" name="phone" value="<?php echo panel_e(isset($record['phone']) ? $record['phone'] : ''); ?>" placeholder="Optional for /get access">
    </label>
    <label>Email <small class="label-hint">Optional secondary access</small>
      <input type="email" maxlength="190" name="email" value="<?php echo panel_e(isset($record['email']) ? $record['email'] : ''); ?>" placeholder="Optional for /get access">
    </label>
  </div>
  <label>Access PIN <small class="label-hint"><?php echo $mode === 'edit' ? 'Optional · leave blank to keep current PIN' : 'Optional · set together with phone or email'; ?></small>
    <input type="text" maxlength="6" name="access_pin" value="" placeholder="1 to 6 letters or numbers">
  </label>
  <label><?php echo $serverType === 'um' ? 'Server / UM Profile' : 'Server / Inbound Template'; ?>
    <select name="template_id">
      <option value="">Select one</option>
      <?php foreach ($templates as $tpl): $node = isset($node_map[$tpl['node_id']]) ? $node_map[$tpl['node_id']] : null; $tplType = !empty($tpl['server_type']) ? strtolower((string) $tpl['server_type']) : 'xui'; if ($tplType !== $serverType) { continue; } ?>
        <option value="<?php echo panel_e($tpl['id']); ?>" <?php echo $record['template_id'] === $tpl['id'] ? 'selected' : ''; ?>><?php echo panel_e($tpl['public_label']); ?> - <?php echo panel_e($node ? $node['title'] : 'Unknown'); ?> / <?php echo panel_e($tplType === 'um' ? (isset($tpl['um_profile_name']) ? $tpl['um_profile_name'] : $tpl['title']) : $tpl['inbound_name']); ?><?php if ($tplType === 'um'): ?> (billing <?php echo panel_e(panel_format_gb(isset($tpl['billing_gb']) ? $tpl['billing_gb'] : 0)); ?> GB)<?php else: ?> (<?php echo panel_e($tpl['protocol']); ?><?php echo !empty($tpl['port']) ? ' :' . panel_e($tpl['port']) : ''; ?>)<?php endif; ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <?php if ($serverType === 'um'): ?>
    <label>Service Password <small class="label-hint"><?php echo $mode === 'edit' ? 'Optional · leave blank to keep current account password' : 'Optional · leave blank to auto-generate'; ?></small><input type="text" name="service_password" value="" placeholder="Account password"></label>
    <?php if ($mode === 'edit' && !empty($record['service_username'])): ?><div class="muted-box">Username is fixed for UM accounts: <strong><?php echo panel_e($record['service_username']); ?></strong>. Profile cannot be changed after creation. Only password can be changed.</div><?php endif; ?>
  <?php else: ?>
    <?php $minTraffic = isset($reseller['min_customer_traffic_gb']) ? (float) $reseller['min_customer_traffic_gb'] : 0; $maxTraffic = isset($reseller['max_customer_traffic_gb']) ? (float) $reseller['max_customer_traffic_gb'] : 0; $allowFractionalTraffic = !isset($reseller['allow_fractional_traffic_gb']) || !empty($reseller['allow_fractional_traffic_gb']); $trafficStep = $allowFractionalTraffic ? '0.01' : '1'; $trafficMin = (!empty($record['id']) && !empty($reseller['restrict'])) ? panel_format_gb($record['traffic_gb']) : ($minTraffic > 0 ? panel_format_gb($minTraffic) : ($allowFractionalTraffic ? '0.1' : '1')); ?>
    <label>Initial Traffic GB<input type="number" step="<?php echo panel_e($trafficStep); ?>" min="<?php echo panel_e($trafficMin); ?>" <?php echo $maxTraffic > 0 ? 'max="' . panel_e(panel_format_gb($maxTraffic)) . '"' : ''; ?> name="traffic_gb" value="<?php echo panel_e($record['traffic_gb']); ?>"></label>
    <label>IP Limit<input type="number" min="<?php echo !empty($max_ip_limit) ? '1' : '0'; ?>" <?php echo !empty($max_ip_limit) ? 'max="' . panel_e($max_ip_limit) . '"' : ''; ?> name="ip_limit" value="<?php echo panel_e(isset($record['ip_limit']) ? $record['ip_limit'] : (!empty($max_ip_limit) ? 1 : 0)); ?>"></label>
  <?php endif; ?>
  <label>Expiration Days<input type="number" min="<?php echo !empty($max_expiration_days) ? '1' : '0'; ?>" <?php echo !empty($max_expiration_days) ? 'max="' . panel_e($max_expiration_days) . '"' : ''; ?> name="duration_days" value="<?php echo panel_e(isset($record['duration_days']) ? $record['duration_days'] : (!empty($max_expiration_days) ? $max_expiration_days : 0)); ?>"></label>
  <label>Expiration Mode
    <select name="duration_mode">
      <option value="fixed" <?php echo (!isset($record['duration_mode']) || $record['duration_mode'] === 'fixed') ? 'selected' : ''; ?>>Fixed from now</option>
      <option value="first_use" <?php echo (isset($record['duration_mode']) && $record['duration_mode'] === 'first_use') ? 'selected' : ''; ?>>Start after first connection</option>
    </select>
  </label>
  <label>Status<select name="status"><option value="active" <?php echo $record['status'] === 'active' ? 'selected' : ''; ?>>Active</option><?php if (empty($reseller['restrict'])): ?><option value="disabled" <?php echo $record['status'] === 'disabled' ? 'selected' : ''; ?>>Disabled</option><?php endif; ?></select></label>
  <label>Notes<textarea name="notes"><?php echo panel_e($record['notes']); ?></textarea></label>
  <div class="muted-box">Reseller credit left: <strong><?php echo panel_e(panel_format_gb($reseller['credit_gb'])); ?> GB</strong><?php if ($serverType === 'um'): ?> · UM customers spend the profile billing GB defined by admin.<?php else: ?> · Traffic rule and limits still apply for XUI customers.<?php endif; ?></div>
  <?php if (!empty($errors)): ?><div class="form-errors"><?php foreach ($errors as $group): foreach ($group as $err): ?><div><?php echo panel_e($err); ?></div><?php endforeach; endforeach; ?></div><?php endif; ?>
  <input type="hidden" name="server_type" value="<?php echo panel_e($serverType); ?>">
  <input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>">
  <button class="btn btn-primary" type="submit"><?php echo $mode === 'edit' ? 'Save Changes' : ($serverType === 'um' ? 'Create UM Customer' : 'Create Customer'); ?></button>
</form>
