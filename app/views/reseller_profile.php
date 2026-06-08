<div class="grid two-col">
  <div class="card">
    <div class="card-head"><h3>Account profile</h3></div>
    <div class="stack-form">
      <label>Username<input type="text" value="<?php echo panel_e($reseller['username']); ?>" readonly></label>
      <label>Display Name<input type="text" value="<?php echo panel_e($reseller['display_name']); ?>" readonly></label>
      <label>Credit GB<input type="text" value="<?php echo panel_e(panel_format_gb($reseller['credit_gb'])); ?>" readonly></label>
      <label>API Key<input type="text" value="<?php echo panel_e($api_key); ?>" readonly></label>
      <label>Telegram User ID<input type="text" value="<?php echo panel_e(isset($reseller['telegram_user_id']) ? $reseller['telegram_user_id'] : ''); ?>" readonly></label>
      <div class="muted-box">
        API status: <strong><?php echo $api_enabled ? 'Enabled' : 'Disabled'; ?></strong><br>
        API encryption: <strong><?php echo $api_encryption ? 'Required' : 'Optional / Off'; ?></strong><br>
        Use header <code class="inline-code">X-Reseller-Api-Key: <?php echo panel_e($api_key); ?></code> or <code class="inline-code">Authorization: Bearer <?php echo panel_e($api_key); ?></code>.
      </div>
      <?php if ($api_encryption): ?>
        <div class="alert alert-info">
          <strong>Encrypted reseller API mode is enabled on this panel.</strong><br>
          For POST requests, do not send plain customer JSON directly. First JSON-encode your payload, derive the crypto key with <code class="inline-code">sha256("panel-api|" + API_KEY)</code>, encrypt with <code class="inline-code">AES-256-CBC</code>, then send:<br>
          <code class="inline-code">{"iv":"base64_iv","payload":"base64_cipher"}</code><br>
          Encrypted responses come back as <code class="inline-code">{"ok":true,"encrypted":1,"iv":"...","payload":"..."}</code>. The PHP example below already shows the exact flow.
        </div>
      <?php endif; ?>
    </div>
  </div>
  <div class="card">
    <div class="card-head"><h3>Telegram bot</h3></div>
    <form method="post" class="stack-form">
      <label>Telegram User ID<input type="text" name="telegram_user_id" value="<?php echo panel_e(isset($reseller['telegram_user_id']) ? $reseller['telegram_user_id'] : ''); ?>"></label>
      <div class="muted-box">
        Bot enabled: <strong><?php echo !empty($telegram_settings['enabled']) ? 'Yes' : 'No'; ?></strong><br>
        Webhook URL: <code class="inline-code"><?php echo panel_e($app->telegramWebhookUrl()); ?></code><br>
        Poll URL: <code class="inline-code"><?php echo panel_e($app->telegramPollUrl()); ?></code><br>
        Link token: <code class="inline-code"><?php echo panel_e($telegram_link_token); ?></code><br>
        To link your Telegram account, send:<br>
        <code class="inline-code">/link <?php echo panel_e($telegram_link_token); ?></code><br>
        Or set your Telegram user ID here manually if you already know it.
      </div>
      <?php if (!empty($errors)): ?><div class="form-errors"><?php foreach ($errors as $group): foreach ($group as $err): ?><div><?php echo panel_e($err); ?></div><?php endforeach; endforeach; ?></div><?php endif; ?>
      <input type="hidden" name="profile_section" value="telegram">
      <input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>">
      <label class="check"><input type="checkbox" name="regenerate_telegram_link" value="1"> Regenerate the Telegram link token</label>
      <button class="btn btn-primary" type="submit">Save Telegram settings</button>
    </form>
  </div>
  <div class="card">
    <div class="card-head"><h3>Change password</h3></div>
    <form method="post" class="stack-form">
      <label>Current Password<input type="password" name="current_password"></label>
      <label>New Password<input type="password" name="new_password"></label>
      <label>Confirm New Password<input type="password" name="confirm_password"></label>
      <?php if (!empty($errors)): ?><div class="form-errors"><?php foreach ($errors as $group): foreach ($group as $err): ?><div><?php echo panel_e($err); ?></div><?php endforeach; endforeach; ?></div><?php endif; ?>
      <input type="hidden" name="profile_section" value="password">
      <input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>">
      <button class="btn btn-primary" type="submit">Update password</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-head"><h3>Telegram bot quick help</h3></div>
  <div class="stack-form">
    <div class="muted-box">
      Use these Telegram commands after your account is linked:<br>
      <code class="inline-code">/balance</code>, <code class="inline-code">/customers</code>, <code class="inline-code">/customer &lt;id&gt;</code>, <code class="inline-code">/create</code>, <code class="inline-code">/addtraffic &lt;id&gt; &lt;gb&gt;</code>, <code class="inline-code">/settraffic &lt;id&gt; &lt;total_gb&gt;</code>, <code class="inline-code">/setip &lt;id&gt; &lt;limit&gt;</code>, <code class="inline-code">/setdays &lt;id&gt; &lt;days&gt; [fixed|first_use]</code>, <code class="inline-code">/sync &lt;id&gt;</code>, <code class="inline-code">/sub &lt;id&gt;</code>, and <code class="inline-code">/notices</code>.
    </div>
  </div>
</div>

<div class="card">
  <div class="card-head"><h3>Reseller API quick help</h3></div>
  <div class="stack-form">
    <div class="muted-box">
      <strong>API base URL</strong><br>
      <code class="inline-code"><?php echo panel_e($app->appLink('/api/reseller')); ?></code><br><br>
      <strong>Authentication</strong><br>
      Send one of these headers on every request:<br>
      <code class="inline-code">X-Reseller-Api-Key: <?php echo panel_e($api_key); ?></code><br>
      <code class="inline-code">Authorization: Bearer <?php echo panel_e($api_key); ?></code>
    </div>

    <?php if ($api_encryption): ?>
      <div class="muted-box">
        <strong>When encryption is required:</strong><br>
        1) Build your plain request JSON, for example <code class="inline-code">{"display_name":"User",...}</code><br>
        2) Derive the binary key with <code class="inline-code">sha256("panel-api|" + API_KEY)</code><br>
        3) Encrypt the plain JSON using <code class="inline-code">AES-256-CBC</code> with a fresh 16-byte IV<br>
        4) Base64 both values and send only this envelope as the POST body:<br>
        <code class="inline-code">{"iv":"base64_iv","payload":"base64_cipher"}</code><br>
        5) Decrypt API responses the same way when you receive <code class="inline-code">encrypted=1</code>.
      </div>
    <?php endif; ?>

    <details open>
      <summary><strong>Available endpoints</strong></summary>
      <div class="muted-box">
        <strong>GET</strong> <code class="inline-code">/api/reseller/profile</code> &mdash; reseller info, limits, API mode, and fractional-traffic permission<br>
        <strong>GET</strong> <code class="inline-code">/api/reseller/templates</code> &mdash; allowed server / inbound templates with forward-compatible fields<br>
        <strong>GET</strong> <code class="inline-code">/api/reseller/customers</code> &mdash; all of your customers<br>
        <strong>GET</strong> <code class="inline-code">/api/reseller/customers/{id}</code> &mdash; one customer details<br>
        <strong>POST</strong> <code class="inline-code">/api/reseller/customers/create</code> &mdash; create customer<br>
        <strong>POST</strong> <code class="inline-code">/api/reseller/customers/{id}/edit</code> &mdash; edit customer<br>
        <strong>POST</strong> <code class="inline-code">/api/reseller/customers/{id}/toggle</code> &mdash; enable / disable customer<?php if (!empty($reseller['restrict'])): ?> (restricted resellers cannot use this)<?php endif; ?><br>
        <strong>POST</strong> <code class="inline-code">/api/reseller/customers/{id}/delete</code> &mdash; delete customer<br>
        <strong>POST</strong> <code class="inline-code">/api/reseller/customers/{id}/sync</code> &mdash; refresh usage from 3x-ui<br>
        <strong>POST</strong> <code class="inline-code">/api/reseller/password</code> &mdash; change your reseller password
      </div>
    </details>

    <details>
      <summary><strong>Create / edit customer payload</strong></summary>
      <div class="muted-box">
        Send JSON with these fields:<br><br>
        <code class="inline-code">display_name</code> = customer display name<br>
        <code class="inline-code">phone</code> = optional customer phone with digits only<br>
        <code class="inline-code">email</code> = optional secondary email for <code class="inline-code">/get</code> lookup<br>
        <code class="inline-code">access_pin</code> = 1 to 6 letters or numbers used for <code class="inline-code">/get</code> lookup<br>
        <code class="inline-code">template_id</code> = one of your allowed template IDs<br>
        <code class="inline-code">traffic_gb</code> = total traffic in GB<br>
        <code class="inline-code">ip_limit</code> = user IP limit<br>
        <code class="inline-code">duration_days</code> = expiration days (0 only if your reseller account allows unlimited)<br>
        <code class="inline-code">duration_mode</code> or <code class="inline-code">expiration_mode</code> = <code class="inline-code">fixed</code> or <code class="inline-code">first_use</code><br>
        <code class="inline-code">status</code> = <code class="inline-code">active</code> or <code class="inline-code">disabled</code><?php if (!empty($reseller['restrict'])): ?> (restricted resellers must keep this as <code class="inline-code">active</code>)<?php endif; ?><br>
        <code class="inline-code">notes</code> = optional notes<br><br>
        Example plain JSON body:<br>
        <code class="inline-code">{"display_name":"Test User","phone":"989121234567","email":"user@example.com","access_pin":"A12345","template_id":"tpl_123","traffic_gb":5,"ip_limit":1,"duration_days":30,"duration_mode":"first_use","status":"active","notes":"API test"}</code><br><br>
        Responses also include compatibility and API-mode fields such as <code class="inline-code">api_version</code>, <code class="inline-code">server_type</code>, <code class="inline-code">template_id</code>, <code class="inline-code">node_id</code>, <code class="inline-code">encryption_required</code>, and <code class="inline-code">request_format</code>.
      </div>
    </details>

    <details>
      <summary><strong>Response shape</strong></summary>
      <div class="muted-box">
        <?php if ($api_encryption): ?>
          <strong>Encryption is required on this panel.</strong><br>
          POST body envelope:<br>
          <code class="inline-code">{"iv":"base64_iv","payload":"base64_cipher"}</code><br><br>
          Encrypted response envelope:<br>
          <code class="inline-code">{"ok":true,"encrypted":1,"iv":"...","payload":"..."}</code><br>
          The decrypted inner JSON is the real API response payload.
        <?php else: ?>
          <strong>Encryption is optional / off on this panel.</strong><br>
          Requests and responses use plain JSON like:<br>
          <code class="inline-code">{"ok":true,...}</code>
        <?php endif; ?>
      </div>
    </details>

    <details>
      <summary><strong>Download sample files</strong></summary>
      <div class="actions compact-actions">
        <a class="btn" href="<?php echo panel_e($app->asset('examples/reseller_api_example.php.txt')); ?>" download>PHP example</a>
      </div>
      <div class="muted-box">
        The bundled example is PHP-only and works in plain mode, and in encrypted mode if the server has OpenSSL enabled.
      </div>
    </details>
  </div>
</div>
