<?php
$projectRoot = defined('PANEL_ROOT') ? PANEL_ROOT : dirname(dirname(__DIR__));
$pollUrlLong = $app->telegramPollUrl() . '?timeout=55';
$shellScriptPath = $projectRoot . '/scripts/telegram_poll_runner.sh';
$phpCronPath = $projectRoot . '/scripts/telegram_poll_cron.php';
$serviceExamplePath = $projectRoot . '/scripts/telegram_bot.service.example';
$cronExample = '* * * * * /usr/bin/php ' . $phpCronPath . ' ' . escapeshellarg($pollUrlLong) . ' >/dev/null 2>&1';
$syncRunUrl = $app->panelSyncRunUrl();
$syncExportUrl = $app->panelSyncExportUrl();
$syncCronPath = isset($sync_script_path) ? $sync_script_path : ($projectRoot . '/scripts/panel_sync_cron.php');
$syncCronExample = '* * * * * PANEL_SYNC_SECRET=' . escapeshellarg(isset($settings['panel_sync_shared_secret']) ? $settings['panel_sync_shared_secret'] : '') . ' /usr/bin/php ' . $syncCronPath . ' ' . escapeshellarg($syncRunUrl) . ' >/dev/null 2>&1';
$maintenanceCronPath = isset($maintenance_script_path) ? $maintenance_script_path : ($projectRoot . '/scripts/cron.php');
$maintenanceCronExample = '* * * * * /usr/bin/php ' . $maintenanceCronPath . ' >/dev/null 2>&1';
?>
<div class="grid admin-settings-grid">
  <div class="card settings-main-card">
    <div class="card-head"><h3>Application Settings</h3><span class="badge muted">Compact layout</span></div>
    <form method="post" class="stack-form settings-form-grid">
      <details class="settings-section" open>
        <summary>Core application</summary>
        <div class="grid two-col form-grid-tight settings-fields">
          <label>Application Name<input type="text" name="app_name" value="<?php echo panel_e($settings['app_name']); ?>"></label>
          <label>Application URL<input type="text" name="app_url" value="<?php echo panel_e($settings['app_url']); ?>"></label>
          <label>Timezone<input type="text" name="timezone" value="<?php echo panel_e($settings['timezone']); ?>"></label>
          <label>Default Duration Days<input type="number" min="1" name="default_duration_days" value="<?php echo panel_e($settings['default_duration_days']); ?>"></label>
        </div>
      </details>


      <details class="settings-section" open>
        <summary>Public landing page</summary>
        <div class="grid two-col form-grid-tight settings-fields">
          <label>Landing Badge<input type="text" name="landing_badge" value="<?php echo panel_e($settings['landing_badge']); ?>"></label>
          <label>Landing Title<input type="text" name="landing_title" value="<?php echo panel_e($settings['landing_title']); ?>"></label>
          <label style="grid-column:1/-1">Landing Subtitle<textarea name="landing_subtitle" rows="3"><?php echo panel_e($settings['landing_subtitle']); ?></textarea></label>
          <label>Primary Button Label<input type="text" name="landing_primary_label" value="<?php echo panel_e($settings['landing_primary_label']); ?>"></label>
          <label>Primary Button URL<input type="text" name="landing_primary_url" value="<?php echo panel_e($settings['landing_primary_url']); ?>"></label>
          <label>Secondary Button Label<input type="text" name="landing_secondary_label" value="<?php echo panel_e($settings['landing_secondary_label']); ?>"></label>
          <label>Secondary Button URL<input type="text" name="landing_secondary_url" value="<?php echo panel_e($settings['landing_secondary_url']); ?>"></label>
          <label style="grid-column:1/-1">Hero Image URL<input type="text" name="landing_hero_image" value="<?php echo panel_e($settings['landing_hero_image']); ?>"><span class="label-hint">Use an absolute http/https URL or a root-relative path. Leave blank to show the default branded panel card.</span></label>
          <label>Section Title<input type="text" name="landing_section_title" value="<?php echo panel_e($settings['landing_section_title']); ?>"></label>
          <label style="grid-column:1/-1">Section Text<textarea name="landing_section_text" rows="3"><?php echo panel_e($settings['landing_section_text']); ?></textarea></label>
          <label>Feature 1 Title<input type="text" name="landing_feature_1_title" value="<?php echo panel_e($settings['landing_feature_1_title']); ?>"></label>
          <label>Feature 2 Title<input type="text" name="landing_feature_2_title" value="<?php echo panel_e($settings['landing_feature_2_title']); ?>"></label>
          <label>Feature 1 Body<textarea name="landing_feature_1_body" rows="3"><?php echo panel_e($settings['landing_feature_1_body']); ?></textarea></label>
          <label>Feature 2 Body<textarea name="landing_feature_2_body" rows="3"><?php echo panel_e($settings['landing_feature_2_body']); ?></textarea></label>
          <label>Feature 3 Title<input type="text" name="landing_feature_3_title" value="<?php echo panel_e($settings['landing_feature_3_title']); ?>"></label>
          <label>Feature 3 Body<textarea name="landing_feature_3_body" rows="3"><?php echo panel_e($settings['landing_feature_3_body']); ?></textarea></label>
          <label style="grid-column:1/-1">Extra Buttons / Links<textarea name="landing_links_text" rows="5" placeholder="Label|/path|ghost
Download App|https://example.com/app.apk|primary"><?php echo panel_e($settings['landing_links_text']); ?></textarea><span class="label-hint">One per line. Use <code>Label|URL</code> or <code>Label|URL|variant</code>. Variants: primary, secondary, ghost.</span></label>
          <label style="grid-column:1/-1">Footer Note<textarea name="landing_footer_note" rows="3"><?php echo panel_e($settings['landing_footer_note']); ?></textarea></label>
        </div>
        <div class="checkbox-grid settings-checks">
          <label class="check"><input type="checkbox" name="landing_enabled" value="1" <?php echo !empty($settings['landing_enabled']) ? 'checked' : ''; ?>> Show the landing page on the root route for guests</label>
        </div>
        <div class="actions compact-actions" style="margin-top:12px">
          <a class="btn" href="<?php echo panel_e($app->url('/landing?preview=1')); ?>" target="_blank" rel="noopener noreferrer">Preview Landing Page</a>
          <a class="btn" href="<?php echo panel_e($app->url('/')); ?>" target="_blank" rel="noopener noreferrer">Open Root Route</a>
        </div>
      </details>

      <details class="settings-section" open>
        <summary>Security, limits, and shield</summary>
        <div class="grid two-col form-grid-tight settings-fields">
          <label>Login Max Attempts<input type="number" min="1" name="login_max_attempts" value="<?php echo panel_e($settings['login_max_attempts']); ?>"></label>
          <label>Login Window Seconds<input type="number" min="1" name="login_window_seconds" value="<?php echo panel_e($settings['login_window_seconds']); ?>"></label>
          <label>Login Lockout Seconds<input type="number" min="1" name="login_lockout_seconds" value="<?php echo panel_e($settings['login_lockout_seconds']); ?>"></label>
          <label>Subscription Max Requests<input type="number" min="1" name="subscription_max_requests" value="<?php echo panel_e($settings['subscription_max_requests']); ?>"></label>
          <label>Subscription Window Seconds<input type="number" min="1" name="subscription_window_seconds" value="<?php echo panel_e($settings['subscription_window_seconds']); ?>"></label>
          <label>Optional Page Shield Mode
            <select name="page_shield_mode">
              <option value="off" <?php echo $settings['page_shield_mode'] === 'off' ? 'selected' : ''; ?>>Off</option>
              <option value="http_only" <?php echo $settings['page_shield_mode'] === 'http_only' ? 'selected' : ''; ?>>HTTP only</option>
              <option value="always" <?php echo $settings['page_shield_mode'] === 'always' ? 'selected' : ''; ?>>Always</option>
            </select>
          </label>
        </div>
        <div class="checkbox-grid settings-checks">
          <label class="check"><input type="checkbox" name="page_shield_forms" value="1" <?php echo !empty($settings['page_shield_forms']) ? 'checked' : ''; ?>> Encrypt POST forms when shield is active</label>
          <label class="check"><input type="checkbox" name="js_hardening" value="1" <?php echo !empty($settings['js_hardening']) ? 'checked' : ''; ?>> Use internal JS hardening</label>
          <label class="check"><input type="checkbox" name="api_enabled" value="1" <?php echo !empty($settings['api_enabled']) ? 'checked' : ''; ?>> Enable reseller API</label>
          <label class="check"><input type="checkbox" name="api_encryption" value="1" <?php echo !empty($settings['api_encryption']) ? 'checked' : ''; ?>> Require API encryption</label>
          <label class="check"><input type="checkbox" name="mask_removed_public_usage" value="1" <?php echo !empty($settings['mask_removed_public_usage']) ? 'checked' : ''; ?>> Mask removed customers on public pages by showing full traffic as used and 0 left</label>
          <label class="check"><input type="checkbox" name="regenerate_page_shield_key" value="1"> Regenerate shield key and rewrite <code>key.js</code></label>
        </div>
      </details>

      <details class="settings-section">
        <summary>Telegram bot</summary>
        <div class="grid two-col form-grid-tight settings-fields">
          <label>Telegram Bot Token<input type="text" name="telegram_bot_token" value="<?php echo panel_e($settings['telegram_bot_token']); ?>"></label>
          <label>Telegram Mode<select name="telegram_mode"><option value="webhook" <?php echo $settings['telegram_mode'] === 'webhook' ? 'selected' : ''; ?>>Webhook</option><option value="polling" <?php echo $settings['telegram_mode'] === 'polling' ? 'selected' : ''; ?>>Polling</option></select></label>
          <label>Telegram Webhook Secret<input type="text" name="telegram_webhook_secret" value="<?php echo panel_e($settings['telegram_webhook_secret']); ?>"></label>
          <label>Telegram Poll Limit<input type="number" min="1" max="100" name="telegram_poll_limit" value="<?php echo panel_e($settings['telegram_poll_limit']); ?>"></label>
          <label>Telegram Proxy Type<select name="telegram_proxy_type"><option value="http" <?php echo $settings['telegram_proxy_type'] === 'http' ? 'selected' : ''; ?>>HTTP/HTTPS</option><option value="https" <?php echo $settings['telegram_proxy_type'] === 'https' ? 'selected' : ''; ?>>HTTPS</option><option value="socks5" <?php echo $settings['telegram_proxy_type'] === 'socks5' ? 'selected' : ''; ?>>SOCKS5</option></select></label>
          <label>Telegram Proxy Host<input type="text" name="telegram_proxy_host" value="<?php echo panel_e($settings['telegram_proxy_host']); ?>"></label>
          <label>Telegram Proxy Port<input type="number" min="0" max="65535" name="telegram_proxy_port" value="<?php echo panel_e($settings['telegram_proxy_port']); ?>"></label>
          <label>Telegram Proxy Username<input type="text" name="telegram_proxy_username" value="<?php echo panel_e($settings['telegram_proxy_username']); ?>"></label>
          <label>Telegram Proxy Password<input type="text" name="telegram_proxy_password" value="<?php echo panel_e($settings['telegram_proxy_password']); ?>"></label>
        </div>
        <div class="checkbox-grid settings-checks">
          <label class="check"><input type="checkbox" name="telegram_enabled" value="1" <?php echo !empty($settings['telegram_enabled']) ? 'checked' : ''; ?>> Enable Telegram bot</label>
          <label class="check"><input type="checkbox" name="telegram_allow_reseller" value="1" <?php echo !empty($settings['telegram_allow_reseller']) ? 'checked' : ''; ?>> Allow reseller bot access</label>
          <label class="check"><input type="checkbox" name="telegram_allow_client" value="1" <?php echo !empty($settings['telegram_allow_client']) ? 'checked' : ''; ?>> Allow client bot access</label>
          <label class="check"><input type="checkbox" name="telegram_allow_admin" value="1" <?php echo !empty($settings['telegram_allow_admin']) ? 'checked' : ''; ?>> Allow admin bot access (reserved)</label>
          <label class="check"><input type="checkbox" name="telegram_proxy_enabled" value="1" <?php echo !empty($settings['telegram_proxy_enabled']) ? 'checked' : ''; ?>> Use proxy for Telegram API</label>
        </div>
        <div class="muted-box" style="margin-top:12px">
          Webhook is the most responsive option. Polling also works: use the included PHP cron helper on shared hosting or the shell runner/service on a VPS. The long-poll endpoint is <code class="inline-code"><?php echo panel_e($pollUrlLong); ?></code>.
        </div>
      </details>


      <details class="settings-section">
        <summary>Customer sync, pagination, cleanup, and auto backup</summary>
        <div class="grid two-col form-grid-tight settings-fields">
          <label>Customer Sync Period (minutes)<input type="number" min="1" max="1440" name="customer_sync_period_minutes" value="<?php echo panel_e($settings['customer_sync_period_minutes']); ?>"></label>
          <label>Customer Sync Retries<input type="number" min="1" max="5" name="customer_sync_retry_attempts" value="<?php echo panel_e($settings['customer_sync_retry_attempts']); ?>"></label>
          <label>Cron Sync Window (customers / run)<input type="number" min="1" max="500" name="customer_sync_batch_size" value="<?php echo panel_e($settings['customer_sync_batch_size']); ?>"></label>
          <label>Customers Per Page<input type="number" min="5" max="250" name="customer_pagination_per_page" value="<?php echo panel_e($settings['customer_pagination_per_page']); ?>"></label>
          <label>Visible Auto Sync Batch<input type="number" min="1" max="100" name="customer_auto_sync_batch_limit" value="<?php echo panel_e($settings['customer_auto_sync_batch_limit']); ?>"></label>
          <label>Cleanup Period (hours)<input type="number" min="1" max="720" name="maintenance_cleanup_period_hours" value="<?php echo panel_e($settings['maintenance_cleanup_period_hours']); ?>"></label>
          <label>Cleanup Max File Age (days)<input type="number" min="1" max="3650" name="maintenance_cleanup_max_age_days" value="<?php echo panel_e($settings['maintenance_cleanup_max_age_days']); ?>"></label>
          <label>Auto Backup Period (hours)<input type="number" min="1" max="720" name="auto_backup_period_hours" value="<?php echo panel_e($settings['auto_backup_period_hours']); ?>"></label>
          <label>Backup Rotation Count<input type="number" min="1" max="1000" name="auto_backup_rotation_count" value="<?php echo panel_e($settings['auto_backup_rotation_count']); ?>"></label>
        </div>
        <div class="checkbox-grid settings-checks">
          <label class="check"><input type="checkbox" name="customer_sync_cron_enabled" value="1" <?php echo !empty($settings['customer_sync_cron_enabled']) ? 'checked' : ''; ?>> Enable cron customer state sync from 3x-ui</label>
          <label class="check"><input type="checkbox" name="customer_pagination_enabled" value="1" <?php echo !empty($settings['customer_pagination_enabled']) ? 'checked' : ''; ?>> Enable customer list pagination</label>
          <label class="check"><input type="checkbox" name="customer_auto_sync_admin_enabled" value="1" <?php echo !empty($settings['customer_auto_sync_admin_enabled']) ? 'checked' : ''; ?>> Enable visible auto sync on admin customer list</label>
          <label class="check"><input type="checkbox" name="customer_auto_sync_reseller_enabled" value="1" <?php echo !empty($settings['customer_auto_sync_reseller_enabled']) ? 'checked' : ''; ?>> Enable visible auto sync on reseller customer list</label>
          <label class="check"><input type="checkbox" name="maintenance_cleanup_enabled" value="1" <?php echo !empty($settings['maintenance_cleanup_enabled']) ? 'checked' : ''; ?>> Enable periodic stale cache cleanup</label>
          <label class="check"><input type="checkbox" name="auto_backup_enabled" value="1" <?php echo !empty($settings['auto_backup_enabled']) ? 'checked' : ''; ?>> Enable automatic backups</label>
        </div>
        <div class="muted-box" style="margin-top:12px">
          Shared-hosting friendly cron file: <code class="inline-code"><?php echo panel_e($maintenanceCronPath); ?></code><br>
          Example cron: <code class="inline-code"><?php echo panel_e($maintenanceCronExample); ?></code><br>
          Customer sync runs in oldest-first windows using the batch size above, visible page auto-sync can be toggled separately for admin and reseller pages, backups rotate automatically to the count you set, and cleanup only touches cache / QR / temp-style files.
        </div>
      </details>

      <details class="settings-section">
        <summary>Panel sync service</summary>
        <div class="grid two-col form-grid-tight settings-fields">
          <label>Sync Mode
            <select name="panel_sync_mode">
              <option value="off" <?php echo $settings['panel_sync_mode'] === 'off' ? 'selected' : ''; ?>>Off</option>
              <option value="master" <?php echo $settings['panel_sync_mode'] === 'master' ? 'selected' : ''; ?>>Master</option>
              <option value="slave" <?php echo $settings['panel_sync_mode'] === 'slave' ? 'selected' : ''; ?>>Slave</option>
            </select>
          </label>
          <label>Master Panel URL<input type="text" name="panel_sync_master_url" value="<?php echo panel_e($settings['panel_sync_master_url']); ?>" placeholder="https://master.example.com/panel"></label>
          <label>Shared Sync Secret<input type="text" name="panel_sync_shared_secret" value="<?php echo panel_e($settings['panel_sync_shared_secret']); ?>"></label>
          <label>Sync Interval (seconds)<input type="number" min="60" max="86400" name="panel_sync_interval_seconds" value="<?php echo panel_e($settings['panel_sync_interval_seconds']); ?>"></label>
          <label>Sync Proxy Type
            <select name="panel_sync_proxy_type">
              <option value="http" <?php echo $settings['panel_sync_proxy_type'] === 'http' ? 'selected' : ''; ?>>HTTP/HTTPS</option>
              <option value="https" <?php echo $settings['panel_sync_proxy_type'] === 'https' ? 'selected' : ''; ?>>HTTPS</option>
              <option value="socks5" <?php echo $settings['panel_sync_proxy_type'] === 'socks5' ? 'selected' : ''; ?>>SOCKS5</option>
            </select>
          </label>
          <label>Sync Proxy Host<input type="text" name="panel_sync_proxy_host" value="<?php echo panel_e($settings['panel_sync_proxy_host']); ?>"></label>
          <label>Sync Proxy Port<input type="number" min="0" max="65535" name="panel_sync_proxy_port" value="<?php echo panel_e($settings['panel_sync_proxy_port']); ?>"></label>
          <label>Sync Proxy Username<input type="text" name="panel_sync_proxy_username" value="<?php echo panel_e($settings['panel_sync_proxy_username']); ?>"></label>
          <label>Sync Proxy Password<input type="text" name="panel_sync_proxy_password" value="<?php echo panel_e($settings['panel_sync_proxy_password']); ?>"></label>
        </div>
        <div class="checkbox-grid settings-checks">
          <label class="check"><input type="checkbox" name="panel_sync_enabled" value="1" <?php echo !empty($settings['panel_sync_enabled']) ? 'checked' : ''; ?>> Enable panel sync service</label>
          <label class="check"><input type="checkbox" name="panel_sync_prune_missing" value="1" <?php echo !empty($settings['panel_sync_prune_missing']) ? 'checked' : ''; ?>> Prune synced records removed from master</label>
          <label class="check"><input type="checkbox" name="panel_sync_proxy_enabled" value="1" <?php echo !empty($settings['panel_sync_proxy_enabled']) ? 'checked' : ''; ?>> Use proxy when pulling from master</label>
        </div>
        <div class="muted-box" style="margin-top:12px">
          Sync mirrors only reseller, server, customer, and related template/link data. It does not mirror admins, app settings, backups, or install state. Run the PHP cron helper every minute; the interval setting above controls when a real pull happens. The recommended cron flow now sends the shared secret in an HTTP header instead of embedding it in the URL.
        </div>
      </details>

      <div class="settings-savebar">
        <?php if (!empty($errors)): ?><div class="form-errors"><?php foreach ($errors as $group): foreach ($group as $err): ?><div><?php echo panel_e($err); ?></div><?php endforeach; endforeach; ?></div><?php endif; ?>
        <input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>">
        <button class="btn btn-primary" type="submit">Save Settings</button>
      </div>
    </form>
  </div>

  <div class="grid settings-side-grid">
    <div class="card compact">
      <div class="card-head"><h3>Install & Shield</h3></div>
      <div class="muted-box">
        <strong>Install lock</strong><br>
        Status: <strong><?php echo !empty($settings['install_locked']) ? 'Locked' : 'Unlocked'; ?></strong><br>
        Lock file: <code class="inline-code"><?php echo panel_e($install_lock_path); ?></code>
      </div>
      <div class="muted-box" style="margin-top:12px">
        Internal JS hardener: <strong><?php echo !empty($settings['js_hardening']) ? 'Enabled' : 'Disabled'; ?></strong><br>
        Client key asset: <code class="inline-code"><?php echo panel_e($shield_asset_url); ?></code>
      </div>
      <div class="muted-box" style="margin-top:12px">
        CSRF tokens stay active on all POST forms. Request data is sanitized on arrival. The optional page shield remains an extra browser-side wrapper and does not replace trusted HTTPS.
      </div>
    </div>

    <div class="card compact">
      <div class="card-head"><h3>Telegram Bot</h3></div>
      <div class="muted-box">
        Webhook URL: <code class="inline-code"><?php echo panel_e($app->telegramWebhookUrl()); ?></code><br>
        Poll URL: <code class="inline-code"><?php echo panel_e($app->telegramPollUrl()); ?></code><br>
        Long-poll URL: <code class="inline-code"><?php echo panel_e($pollUrlLong); ?></code><br>
        Mode: <strong><?php echo panel_e($settings['telegram_mode']); ?></strong><br>
        Enabled: <strong><?php echo !empty($settings['telegram_enabled']) ? 'Yes' : 'No'; ?></strong>
      </div>
      <div class="actions compact-actions" style="margin-top:12px">
        <form method="post" action="<?php echo panel_e($app->url('/admin/telegram/webhook/set')); ?>">
          <input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>">
          <button class="btn" type="submit">Set Webhook</button>
        </form>
        <form method="post" action="<?php echo panel_e($app->url('/admin/telegram/webhook/delete')); ?>">
          <input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>">
          <button class="btn btn-danger" type="submit">Delete Webhook</button>
        </form>
        <form method="post" action="<?php echo panel_e($app->url('/admin/telegram/poll')); ?>">
          <input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>">
          <button class="btn btn-primary" type="submit">Run Poll Once</button>
        </form>
      </div>
      <div class="muted-box" style="margin-top:12px">
        <strong>Included helper files</strong><br>
        Shell runner: <code class="inline-code"><?php echo panel_e($shellScriptPath); ?></code><br>
        PHP cron file: <code class="inline-code"><?php echo panel_e($phpCronPath); ?></code><br>
        Service example: <code class="inline-code"><?php echo panel_e($serviceExamplePath); ?></code>
      </div>
      <div class="muted-box" style="margin-top:12px">
        <strong>Recommended usage</strong><br>
        Best: Webhook mode on HTTPS.<br>
        Polling on shared hosting: run the PHP cron file every minute.<br>
        Polling on VPS: use the shell runner inside a service or supervisor.
      </div>
      <div class="muted-box" style="margin-top:12px">
        <strong>Sample cron line</strong><br>
        <code class="inline-code"><?php echo panel_e($cronExample); ?></code>
      </div>
      <div class="muted-box" style="margin-top:12px">
        <strong>Bot command quick help</strong><br>
        Reseller: <code class="inline-code">/start</code>, <code class="inline-code">/help</code>, <code class="inline-code">/balance</code>, <code class="inline-code">/customers</code>, <code class="inline-code">/customer &lt;id&gt;</code>, <code class="inline-code">/create</code>, <code class="inline-code">/sync &lt;id&gt;</code>, <code class="inline-code">/sub &lt;id&gt;</code>, <code class="inline-code">/addtraffic &lt;id&gt; &lt;gb&gt;</code>, <code class="inline-code">/settraffic &lt;id&gt; &lt;total_gb&gt;</code>, <code class="inline-code">/setip &lt;id&gt; &lt;limit&gt;</code>, <code class="inline-code">/setdays &lt;id&gt; &lt;days&gt; [fixed|first_use]</code>, <code class="inline-code">/toggle &lt;id&gt;</code>, <code class="inline-code">/delete &lt;id&gt;</code>, <code class="inline-code">/notices</code>.<br>
        Client: <code class="inline-code">/start</code>, <code class="inline-code">/client &lt;subscription_key_or_uuid&gt;</code>, <code class="inline-code">/bind &lt;subscription_key_or_uuid&gt;</code>, <code class="inline-code">/status</code>, <code class="inline-code">/sub</code>, <code class="inline-code">/unbind</code>.
      </div>
    </div>


    <div class="card compact">
      <div class="card-head"><h3>Panel Sync</h3>
        <form method="post" action="<?php echo panel_e($app->url('/admin/sync/run')); ?>">
          <input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>">
          <button class="btn btn-primary" type="submit">Run Sync Now</button>
        </form>
      </div>
      <div class="muted-box">
        Mode: <strong><?php echo panel_e($settings['panel_sync_mode']); ?></strong><br>
        Enabled: <strong><?php echo !empty($settings['panel_sync_enabled']) ? 'Yes' : 'No'; ?></strong><br>
        Last run: <strong><?php echo !empty($sync_state['last_run_at']) ? panel_e($sync_state['last_run_at']) : 'Never'; ?></strong><br>
        Last status: <strong><?php echo panel_e(isset($sync_state['last_status']) ? $sync_state['last_status'] : 'never'); ?></strong><br>
        Next due: <strong><?php echo !empty($sync_state['next_due_at']) ? panel_e($sync_state['next_due_at']) : 'n/a'; ?></strong>
      </div>
      <div class="muted-box" style="margin-top:12px">
        <strong>Last message</strong><br>
        <?php echo panel_e(isset($sync_state['last_message']) ? $sync_state['last_message'] : 'No sync has run yet.'); ?>
      </div>
      <div class="muted-box" style="margin-top:12px">
        <strong>Master export URL</strong><br>
        <code class="inline-code"><?php echo panel_e($syncExportUrl); ?></code><br><br>
        <strong>Slave run URL</strong><br>
        <code class="inline-code"><?php echo panel_e($syncRunUrl); ?></code>
      </div>
      <div class="muted-box" style="margin-top:12px">
        <strong>PHP cron helper</strong><br>
        Script: <code class="inline-code"><?php echo panel_e($syncCronPath); ?></code><br>
        Cron: <code class="inline-code"><?php echo panel_e($syncCronExample); ?></code>
      </div>
      <?php if (!empty($sync_state['last_counts'])): ?>
      <div class="muted-box" style="margin-top:12px">
        <strong>Last synced counts</strong><br>
        <?php foreach ($sync_state['last_counts'] as $k => $v): ?>
          <span class="badge muted"><?php echo panel_e($k); ?>: <?php echo panel_e($v); ?></span>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <div class="card compact">
      <div class="card-head"><h3>Backups</h3>
        <form method="post" action="<?php echo panel_e($app->url('/admin/backups/create')); ?>">
          <input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>">
          <button class="btn btn-primary" type="submit">Create Backup</button>
        </form>
      </div>
      <div class="muted-box">Creates a full archive of the current panel project and storage, excluding old backup archives themselves.</div>
      <table class="table settings-backup-table">
        <tr><th>Name</th><th>Size</th><th>Created</th><th>Actions</th></tr>
        <?php foreach ($backups as $item): ?>
          <tr>
            <td><code class="inline-code"><?php echo panel_e($item['name']); ?></code></td>
            <td><?php echo panel_e(number_format(((float) $item['size']) / 1024, 1)); ?> KB</td>
            <td><?php echo panel_e(gmdate('Y-m-d H:i:s', (int) $item['time'])); ?> UTC</td>
            <td class="actions">
              <a class="btn" href="<?php echo panel_e($app->url('/admin/backups/download?file=' . rawurlencode($item['name']))); ?>">Download</a>
              <form method="post" action="<?php echo panel_e($app->url('/admin/backups/delete')); ?>" onsubmit="return confirm('Delete this backup?');">
                <input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>">
                <input type="hidden" name="file" value="<?php echo panel_e($item['name']); ?>">
                <button class="btn btn-danger" type="submit">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($backups)): ?>
          <tr><td colspan="4"><div class="muted-box">No backups created yet.</div></td></tr>
        <?php endif; ?>
      </table>
    </div>
  </div>
</div>
