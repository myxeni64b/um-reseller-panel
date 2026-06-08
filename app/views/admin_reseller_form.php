<form method="post" class="stack-form card">
  <label>Username<input type="text" name="username" value="<?php echo panel_e($record['username']); ?>"></label>
  <label>Display Name<input type="text" name="display_name" value="<?php echo panel_e($record['display_name']); ?>"></label>
  <label>Password<input type="password" name="password"></label>
  <label>Prefix<input type="text" name="prefix" value="<?php echo panel_e($record['prefix']); ?>"></label>
  <label>Credit GB<input type="number" step="0.01" min="0" name="credit_gb" value="<?php echo panel_e($record['credit_gb']); ?>"></label>
  <label>Default Duration Days<input type="number" min="1" name="fixed_duration_days" value="<?php echo panel_e($record['fixed_duration_days']); ?>"></label>
  <div class="grid two-col form-grid-tight">
    <label>Min Customer Traffic GB <small class="label-hint">0 = no minimum</small><input type="number" step="0.01" min="0" name="min_customer_traffic_gb" value="<?php echo panel_e(isset($record['min_customer_traffic_gb']) ? $record['min_customer_traffic_gb'] : 0); ?>"></label>
    <label>Max Customer Traffic GB <small class="label-hint">0 = no maximum</small><input type="number" step="0.01" min="0" name="max_customer_traffic_gb" value="<?php echo panel_e(isset($record['max_customer_traffic_gb']) ? $record['max_customer_traffic_gb'] : 0); ?>"></label>
  </div>
  <label>Max Expiration Days Allowed<input type="number" min="0" name="max_expiration_days" value="<?php echo panel_e(isset($record['max_expiration_days']) ? $record['max_expiration_days'] : $record['fixed_duration_days']); ?>"></label>
  <label>Max User IP Limit Allowed<input type="number" min="0" name="max_ip_limit" value="<?php echo panel_e(isset($record['max_ip_limit']) ? $record['max_ip_limit'] : 0); ?>"></label>
  <label>Telegram User ID<input type="text" name="telegram_user_id" value="<?php echo panel_e(isset($record['telegram_user_id']) ? $record['telegram_user_id'] : ''); ?>"></label>
  <label class="check"><input type="checkbox" name="allow_fractional_traffic_gb" value="1" <?php echo !isset($record['allow_fractional_traffic_gb']) || !empty($record['allow_fractional_traffic_gb']) ? 'checked' : ''; ?>> Allow fractional customer traffic values (example: 0.5 GB, 1.25 GB)</label>
  <label class="check"><input type="checkbox" name="allow_xui_traffic_edit" value="1" <?php echo !array_key_exists('allow_xui_traffic_edit', $record) || !empty($record['allow_xui_traffic_edit']) ? 'checked' : ''; ?>> Allow this reseller to change XUI customer traffic later (add or reduce GB on edit)</label>
  <label class="check"><input type="checkbox" name="restrict" value="1" <?php echo !empty($record['restrict']) ? 'checked' : ''; ?>> Restrict reseller (cannot delete, disable, or lower user traffic; only add more GB)</label>
  <label>Status<select name="status"><option value="active" <?php echo $record['status'] === 'active' ? 'selected' : ''; ?>>Active</option><option value="disabled" <?php echo $record['status'] === 'disabled' ? 'selected' : ''; ?>>Disabled</option></select></label>
  <label>API Key<input type="text" name="api_key" value="<?php echo panel_e(isset($record['api_key']) ? $record['api_key'] : ''); ?>"></label>
  <label class="check"><input type="checkbox" name="regenerate_api_key" value="1"> Regenerate reseller API key</label>
  <label>Notes<textarea name="notes"><?php echo panel_e($record['notes']); ?></textarea></label>
  <fieldset><legend>Allowed Templates</legend>
    <div class="checkbox-grid">
      <?php foreach ($templates as $tpl): $stype = !empty($tpl['server_type']) ? strtolower((string) $tpl['server_type']) : 'xui'; ?>
        <label class="check"><input type="checkbox" name="allowed_template_ids[]" value="<?php echo panel_e($tpl['id']); ?>" <?php echo in_array($tpl['id'], (array) $record['allowed_template_ids'], true) ? 'checked' : ''; ?>> [<?php echo strtoupper(panel_e($stype)); ?>] <?php echo panel_e($tpl['public_label']); ?> (<?php echo panel_e($stype === 'um' ? (isset($tpl['um_profile_name']) ? $tpl['um_profile_name'] : $tpl['title']) : $tpl['inbound_name']); ?>)</label>
      <?php endforeach; ?>
    </div>
  </fieldset>
  <?php if (!empty($errors)): ?><div class="form-errors"><?php foreach ($errors as $group): foreach ($group as $err): ?><div><?php echo panel_e($err); ?></div><?php endforeach; endforeach; ?></div><?php endif; ?>
  <input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>">
  <button class="btn btn-primary" type="submit"><?php echo $mode === 'edit' ? 'Save Changes' : 'Create Reseller'; ?></button>
</form>
