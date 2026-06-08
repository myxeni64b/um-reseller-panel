<form method="post" class="stack-form card" id="server-form">
  <?php
    $stype = isset($record['server_type']) ? strtolower((string) $record['server_type']) : 'xui';
    $umApiMode = isset($record['um_api_mode']) ? strtolower((string) $record['um_api_mode']) : 'rest';
    $defaults = isset($node_ui_defaults) && is_array($node_ui_defaults) ? $node_ui_defaults : array();
  ?>

  <div class="muted-box" style="margin-bottom:14px;">
    Choose the server type first. The form will only show the fields needed for that server, so XUI and MikroTik UM stay clean and separate.
  </div>

  <label>Server Type
    <select name="server_type" id="server-type-select">
      <option value="xui" <?php echo $stype === 'xui' ? 'selected' : ''; ?>>XUI</option>
      <option value="um" <?php echo $stype === 'um' ? 'selected' : ''; ?>>MikroTik UM (Radius)</option>
    </select>
  </label>

  <div class="grid two-col">
    <label>Title<input type="text" name="title" value="<?php echo panel_e($record['title']); ?>"></label>
    <label>Slug<input type="text" name="slug" value="<?php echo panel_e($record['slug']); ?>"></label>
  </div>

  <fieldset>
    <legend>Connection</legend>
    <label>Base URL<input type="text" name="base_url" value="<?php echo panel_e($record['base_url']); ?>" placeholder="<?php echo $stype === 'um' ? 'https://router.example.com' : 'https://panel.example.com'; ?>"></label>
    <div class="grid two-col server-type-block" data-server-type="xui">
      <label>Panel Path<input type="text" name="panel_path" value="<?php echo panel_e($record['panel_path']); ?>" placeholder="/panel"></label>
      <label>Subscription Base<input type="text" name="subscription_base" value="<?php echo panel_e(isset($record['subscription_base']) ? $record['subscription_base'] : ''); ?>" placeholder="https://sub.domain.tld/user/"></label>
    </div>
    <div class="grid two-col">
      <label>Panel Username<input type="text" name="panel_username" value="<?php echo panel_e($record['panel_username']); ?>"></label>
      <label>Panel Password<input type="password" name="panel_password" placeholder="<?php echo $mode === 'edit' ? 'Leave empty to keep current password' : ''; ?>"></label>
    </div>
    <div class="server-type-block" data-server-type="xui">
      <label>XUI API Token<input type="text" name="xui_api_token" value="" placeholder="<?php echo !empty($record['xui_api_token_hint']) ? 'Leave empty to keep current token' : 'Optional for older panels, required by some newer 3x-ui v3 panels'; ?>"></label>
      <div class="muted-box" style="margin-top:8px;">
        For newer 3x-ui panels that require Bearer token auth, paste the API token here. When this token is set, API calls use Authorization: Bearer &lt;token&gt; and do not depend only on cookie login.<?php if (!empty($record['xui_api_token_hint'])): ?> Current token: configured.<?php endif; ?>
      </div>
      <div class="grid two-col" style="margin-top:12px;">
        <label>Request Host Header <small class="label-hint">Optional</small><input type="text" name="xui_request_host" value="<?php echo panel_e(isset($record['xui_request_host']) ? $record['xui_request_host'] : ''); ?>" placeholder="cdn.example.com or cdn.example.com:443"></label>
        <label>TLS SNI <small class="label-hint">Optional</small><input type="text" name="xui_request_sni" value="<?php echo panel_e(isset($record['xui_request_sni']) ? $record['xui_request_sni'] : ''); ?>" placeholder="cdn.example.com"></label>
      </div>
      <div class="muted-box" style="margin-top:8px;">
        Use these only when the XUI panel is behind a CDN or fronting setup. Host overrides the HTTP Host header. TLS SNI overrides the server name used for HTTPS. Leave them empty to use the normal Base URL host.
      </div>
      <div class="grid two-col" style="margin-top:12px;">
        <label>XUI Proxy Type
          <select name="xui_proxy_type">
            <option value="http" <?php echo (isset($record['xui_proxy_type']) ? $record['xui_proxy_type'] : 'http') === 'http' ? 'selected' : ''; ?>>HTTP</option>
            <option value="https" <?php echo (isset($record['xui_proxy_type']) ? $record['xui_proxy_type'] : 'http') === 'https' ? 'selected' : ''; ?>>HTTPS</option>
            <option value="socks5" <?php echo (isset($record['xui_proxy_type']) ? $record['xui_proxy_type'] : 'http') === 'socks5' ? 'selected' : ''; ?>>SOCKS5</option>
          </select>
        </label>
        <label>XUI Proxy Host <small class="label-hint">Optional</small><input type="text" name="xui_proxy_host" value="<?php echo panel_e(isset($record['xui_proxy_host']) ? $record['xui_proxy_host'] : ''); ?>" placeholder="127.0.0.1 or proxy.example.com"></label>
      </div>
      <div class="grid two-col" style="margin-top:12px;">
        <label>XUI Proxy Port <small class="label-hint">Optional</small><input type="number" min="1" max="65535" name="xui_proxy_port" value="<?php echo panel_e(isset($record['xui_proxy_port']) ? $record['xui_proxy_port'] : ''); ?>" placeholder="8080"></label>
        <label>XUI Proxy Username <small class="label-hint">Optional</small><input type="text" name="xui_proxy_username" value="<?php echo panel_e(isset($record['xui_proxy_username']) ? $record['xui_proxy_username'] : ''); ?>" placeholder="Leave empty for no proxy auth"></label>
      </div>
      <label style="margin-top:12px;">XUI Proxy Password <small class="label-hint">Optional</small><input type="password" name="xui_proxy_password" value="" placeholder="<?php echo !empty($record['xui_proxy_password_hint']) ? 'Leave empty to keep current proxy password' : 'Leave empty for no proxy auth'; ?>"></label>
      <div class="muted-box" style="margin-top:8px;">
        Optional per-server proxy for all XUI API requests, including test, sync, quick sync visible, cron sync, import, and customer operations. Leave host and port empty to disable the proxy completely.
      </div>
    </div>
  </fieldset>

  <fieldset>
    <legend>Transport</legend>
    <div class="grid two-col">
      <label>Request Timeout (sec)<input type="number" min="5" name="request_timeout" value="<?php echo panel_e(isset($record['request_timeout']) ? $record['request_timeout'] : '20'); ?>"></label>
      <label>Connect Timeout (sec)<input type="number" min="3" name="connect_timeout" value="<?php echo panel_e(isset($record['connect_timeout']) ? $record['connect_timeout'] : '8'); ?>"></label>
    </div>
    <div class="grid two-col">
      <label>Retry Attempts<input type="number" min="1" max="5" name="retry_attempts" value="<?php echo panel_e(isset($record['retry_attempts']) ? $record['retry_attempts'] : '2'); ?>"></label>
      <label class="check"><input type="checkbox" name="allow_insecure_tls" value="1" <?php echo !empty($record['allow_insecure_tls']) ? 'checked' : ''; ?>> Allow insecure TLS / self-signed certificate</label>
    </div>
  </fieldset>

  <fieldset class="server-type-block" data-server-type="um">
    <legend>UM API Access</legend>
    <label>UM API Mode
      <select name="um_api_mode" id="um-api-mode-select">
        <option value="rest" <?php echo $umApiMode === 'rest' ? 'selected' : ''; ?>>REST API</option>
        <option value="internal" <?php echo $umApiMode === 'internal' ? 'selected' : ''; ?>>Internal API</option>
      </select>
    </label>
    <div class="muted-box um-api-mode-block" data-um-api-mode="rest" style="margin-bottom:12px;">
      REST mode uses the Base URL scheme directly, so you can choose HTTPS or plain HTTP according to the router and environment.
    </div>
    <div class="um-api-mode-block" data-um-api-mode="internal">
      <div class="grid two-col">
        <label>UM API Host Override<input type="text" name="um_api_host" value="<?php echo panel_e(isset($record['um_api_host']) ? $record['um_api_host'] : ''); ?>" placeholder="Blank = host from Base URL"></label>
        <label>UM API Port<input type="number" min="1" max="65535" name="um_api_port" value="<?php echo panel_e(isset($record['um_api_port']) ? $record['um_api_port'] : ''); ?>" placeholder="8728 or 8729"></label>
      </div>
      <label class="check"><input type="checkbox" name="um_api_ssl" value="1" <?php echo !empty($record['um_api_ssl']) ? 'checked' : ''; ?>> Use MikroTik API-SSL (usually 8729)</label>
    </div>
  </fieldset>

  <fieldset class="server-type-block" data-server-type="um">
    <legend>UM Delivery</legend>
    <label>Connection Info Mode
      <select name="um_connection_mode" id="um-connection-mode-select">
        <option value="text" <?php echo (isset($record['um_connection_mode']) ? $record['um_connection_mode'] : 'text') === 'text' ? 'selected' : ''; ?>>Text</option>
        <option value="file" <?php echo (isset($record['um_connection_mode']) ? $record['um_connection_mode'] : 'text') === 'file' ? 'selected' : ''; ?>>File / URL</option>
      </select>
    </label>
    <div class="um-connection-mode-block" data-um-connection-mode="text">
      <label>Connection Info Text<textarea rows="6" name="um_connection_text" placeholder="Use placeholders like {username}, {password}, {profile}, {server}"><?php echo panel_e(isset($record['um_connection_text']) ? $record['um_connection_text'] : ''); ?></textarea></label>
    </div>
    <div class="um-connection-mode-block" data-um-connection-mode="file">
      <div class="grid two-col">
        <label>Connection File URL<input type="text" name="um_connection_file_url" value="<?php echo panel_e(isset($record['um_connection_file_url']) ? $record['um_connection_file_url'] : ''); ?>" placeholder="https://example.com/configs/{username}.ovpn"></label>
        <label>Connection File Name<input type="text" name="um_connection_file_name" value="<?php echo panel_e(isset($record['um_connection_file_name']) ? $record['um_connection_file_name'] : ''); ?>" placeholder="Optional display name"></label>
      </div>
    </div>
  </fieldset>

  <fieldset>
    <legend>Public Utility Links</legend>
    <label>Utility Links <small class="label-hint">One per line: Name|URL or Name|URL|xui,um</small><textarea rows="6" name="utility_links_text" placeholder="Windows App|https://example.com/windows.exe|xui
Android App|https://example.com/android.apk|all"><?php echo panel_e(isset($record['utility_links_text']) ? $record['utility_links_text'] : ''); ?></textarea></label>
    <div class="muted-box">These links appear as buttons in the public customer page and /get access page. URLs stay clickable, but the raw link text is not shown.</div>
  </fieldset>

  <label>Status<select name="status"><option value="active" <?php echo $record['status'] === 'active' ? 'selected' : ''; ?>>Active</option><option value="disabled" <?php echo $record['status'] === 'disabled' ? 'selected' : ''; ?>>Disabled</option></select></label>
  <label>Notes<textarea name="notes"><?php echo panel_e($record['notes']); ?></textarea></label>

  <?php if (!empty($errors)): ?><div class="form-errors"><?php foreach ($errors as $group): foreach ($group as $err): ?><div><?php echo panel_e($err); ?></div><?php endforeach; endforeach; ?></div><?php endif; ?>
  <input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>">
  <button class="btn btn-primary" type="submit"><?php echo $mode === 'edit' ? 'Save Changes' : 'Save Server'; ?></button>
</form>

<script>
(function(){
  var defaults = <?php echo json_encode($defaults); ?> || {};
  var typeSelect = document.getElementById('server-type-select');
  var apiModeSelect = document.getElementById('um-api-mode-select');
  var connModeSelect = document.getElementById('um-connection-mode-select');
  var touched = {};

  function markTouched(name){ touched[name] = true; }
  Array.prototype.forEach.call(document.querySelectorAll('#server-form input, #server-form select, #server-form textarea'), function(el){
    var key = el.name || el.id;
    if(!key){ return; }
    el.addEventListener('input', function(){ markTouched(key); });
    el.addEventListener('change', function(){ markTouched(key); });
  });

  function showGroup(selector, attr, current){
    Array.prototype.forEach.call(document.querySelectorAll(selector), function(el){
      el.style.display = el.getAttribute(attr) === current ? '' : 'none';
    });
  }

  function setValue(name, value){
    var input = document.querySelector('[name="' + name + '"]');
    if(!input || touched[name]){ return; }
    if(input.type === 'checkbox'){
      input.checked = !!value;
      return;
    }
    if(input.value === '' || input.value === '/' || input.value === '/panel'){
      input.value = value;
    }
  }

  function applyTypeDefaults(type){
    if(!defaults[type]){ return; }
    var d = defaults[type];
    setValue('panel_path', d.panel_path || '');
    setValue('subscription_base', d.subscription_base || '');
    setValue('xui_request_host', d.xui_request_host || '');
    setValue('xui_request_sni', d.xui_request_sni || '');
    setValue('xui_proxy_type', d.xui_proxy_type || 'http');
    setValue('xui_proxy_host', d.xui_proxy_host || '');
    setValue('xui_proxy_port', d.xui_proxy_port || '');
    setValue('xui_proxy_username', d.xui_proxy_username || '');
    setValue('request_timeout', d.request_timeout || '20');
    setValue('connect_timeout', d.connect_timeout || '8');
    setValue('retry_attempts', d.retry_attempts || '2');
    setValue('um_api_mode', d.um_api_mode || 'rest');
    setValue('um_api_host', d.um_api_host || '');
    setValue('um_api_port', d.um_api_port || '');
    setValue('um_connection_mode', d.um_connection_mode || 'text');
    setValue('um_connection_text', d.um_connection_text || '');
    setValue('um_connection_file_url', d.um_connection_file_url || '');
    setValue('um_connection_file_name', d.um_connection_file_name || '');
    if(!touched['allow_insecure_tls']){
      var tls = document.querySelector('[name="allow_insecure_tls"]');
      if(tls && typeof d.allow_insecure_tls !== 'undefined'){ tls.checked = !!d.allow_insecure_tls; }
    }
    if(!touched['um_api_ssl']){
      var ssl = document.querySelector('[name="um_api_ssl"]');
      if(ssl && typeof d.um_api_ssl !== 'undefined'){ ssl.checked = !!d.um_api_ssl; }
    }
  }

  function refresh(){
    var type = typeSelect ? typeSelect.value : 'xui';
    showGroup('.server-type-block', 'data-server-type', type);
    applyTypeDefaults(type);
    var apiMode = apiModeSelect ? apiModeSelect.value : 'rest';
    showGroup('.um-api-mode-block', 'data-um-api-mode', apiMode);
    var connMode = connModeSelect ? connModeSelect.value : 'text';
    showGroup('.um-connection-mode-block', 'data-um-connection-mode', connMode);
  }

  if(typeSelect){ typeSelect.addEventListener('change', refresh); }
  if(apiModeSelect){ apiModeSelect.addEventListener('change', refresh); }
  if(connModeSelect){ connModeSelect.addEventListener('change', refresh); }
  refresh();
})();
</script>
