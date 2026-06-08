<?php $stype = isset($record['server_type']) ? strtolower((string) $record['server_type']) : 'xui'; ?>
<form method="post" class="stack-form card" id="template-form">
  <div class="muted-box" style="margin-bottom:14px;">
    Pick the server first. The template form will automatically switch between XUI inbound settings and MikroTik UM profile settings.
  </div>

  <div class="grid two-col">
    <label>Title<input type="text" name="title" value="<?php echo panel_e($record['title']); ?>"></label>
    <label>Public Label<input type="text" name="public_label" value="<?php echo panel_e($record['public_label']); ?>"></label>
  </div>

  <label>Server
    <select name="node_id" id="template-node-select">
      <option value="">Select server</option>
      <?php foreach ($nodes as $node): $ntype = !empty($node['server_type']) ? strtolower((string) $node['server_type']) : 'xui'; ?>
        <option value="<?php echo panel_e($node['id']); ?>" data-server-type="<?php echo panel_e($ntype); ?>" <?php echo $record['node_id'] === $node['id'] ? 'selected' : ''; ?>><?php echo panel_e($node['title']); ?> (<?php echo strtoupper(panel_e($ntype)); ?>)</option>
      <?php endforeach; ?>
    </select>
  </label>
  <input type="hidden" name="server_type" value="<?php echo panel_e($stype); ?>" id="template-server-type">

  <div class="grid two-col">
    <label>Sort Order<input type="number" name="sort_order" value="<?php echo panel_e($record['sort_order']); ?>"></label>
    <label>Status<select name="status"><option value="active" <?php echo $record['status'] === 'active' ? 'selected' : ''; ?>>Active</option><option value="disabled" <?php echo $record['status'] === 'disabled' ? 'selected' : ''; ?>>Disabled</option></select></label>
  </div>

  <fieldset class="template-type-block" data-template-type="xui">
    <legend>XUI Inbound Template</legend>
    <div class="grid two-col">
      <label>Inbound ID<input type="text" name="inbound_id" value="<?php echo panel_e($record['inbound_id']); ?>"></label>
      <label>Inbound Name<input type="text" name="inbound_name" value="<?php echo panel_e($record['inbound_name']); ?>"></label>
    </div>
    <div class="grid two-col">
      <label>Protocol<input type="text" name="protocol" value="<?php echo panel_e($record['protocol']); ?>"></label>
      <label>Port<input type="text" name="port" value="<?php echo panel_e(isset($record['port']) ? $record['port'] : ''); ?>"></label>
    </div>
    <div class="grid two-col">
      <label>Listen<input type="text" name="listen" value="<?php echo panel_e(isset($record['listen']) ? $record['listen'] : ''); ?>"></label>
      <label>Network<input type="text" name="network" value="<?php echo panel_e(isset($record['network']) ? $record['network'] : ''); ?>"></label>
    </div>
    <label>Security<input type="text" name="security" value="<?php echo panel_e(isset($record['security']) ? $record['security'] : ''); ?>"></label>
    <label>Manual client URI query parameters
      <input type="text" name="client_extra_query" value="<?php echo panel_e(isset($record['client_extra_query']) ? $record['client_extra_query'] : ''); ?>" placeholder="fp=chrome&sni=your-domain.com&alpn=http%2F1.1">
      <small class="muted">Optional. Appended to every generated XUI config from this template, useful for ExternalProxy/manual client fields. Leading ? or & is accepted. Extra keys override generated keys.</small>
    </label>
    <details>
      <summary>Advanced imported JSON</summary>
      <label>Settings JSON<textarea rows="6" name="settings_json"><?php echo panel_e(isset($record['settings_json']) ? $record['settings_json'] : ''); ?></textarea></label>
      <label>Stream Settings JSON<textarea rows="8" name="stream_settings_json"><?php echo panel_e(isset($record['stream_settings_json']) ? $record['stream_settings_json'] : ''); ?></textarea></label>
      <label>Sniffing JSON<textarea rows="4" name="sniffing_json"><?php echo panel_e(isset($record['sniffing_json']) ? $record['sniffing_json'] : ''); ?></textarea></label>
    </details>
  </fieldset>

  <fieldset class="template-type-block" data-template-type="um">
    <legend>UM Profile Template</legend>
    <div class="grid two-col">
      <label>UM Profile ID<input type="text" name="um_profile_id" value="<?php echo panel_e(isset($record['um_profile_id']) ? $record['um_profile_id'] : ''); ?>"></label>
      <label>UM Profile Name<input type="text" name="um_profile_name" value="<?php echo panel_e(isset($record['um_profile_name']) ? $record['um_profile_name'] : ''); ?>"></label>
    </div>
    <label>Billing GB<input type="number" step="0.01" min="0" name="billing_gb" value="<?php echo panel_e(isset($record['billing_gb']) ? $record['billing_gb'] : '1'); ?>"></label>
  </fieldset>

  <label>Notes<textarea name="notes"><?php echo panel_e($record['notes']); ?></textarea></label>
  <?php if (!empty($errors)): ?><div class="form-errors"><?php foreach ($errors as $group): foreach ($group as $err): ?><div><?php echo panel_e($err); ?></div><?php endforeach; endforeach; ?></div><?php endif; ?>
  <input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>">
  <button class="btn btn-primary" type="submit"><?php echo $mode === 'edit' ? 'Save Changes' : 'Create Template'; ?></button>
</form>

<script>
(function(){
  var nodeSelect = document.getElementById('template-node-select');
  var typeInput = document.getElementById('template-server-type');
  function refresh(){
    var opt = nodeSelect && nodeSelect.options[nodeSelect.selectedIndex] ? nodeSelect.options[nodeSelect.selectedIndex] : null;
    var type = opt && opt.getAttribute('data-server-type') ? opt.getAttribute('data-server-type') : (typeInput ? typeInput.value : 'xui');
    if(typeInput){ typeInput.value = type || 'xui'; }
    Array.prototype.forEach.call(document.querySelectorAll('.template-type-block'), function(el){
      el.style.display = el.getAttribute('data-template-type') === type ? '' : 'none';
    });
  }
  if(nodeSelect){ nodeSelect.addEventListener('change', refresh); }
  refresh();
})();
</script>
