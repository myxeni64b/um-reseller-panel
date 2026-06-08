<?php
$landing = isset($landing) && is_array($landing) ? $landing : array();
$landing_buttons = isset($landing_buttons) && is_array($landing_buttons) ? $landing_buttons : array();
$landing_links = isset($landing_links) && is_array($landing_links) ? $landing_links : array();
$landing_features = isset($landing_features) && is_array($landing_features) ? $landing_features : array();
$landing_preview = !empty($landing_preview);
$heroImage = isset($landing['landing_hero_image']) ? trim((string) $landing['landing_hero_image']) : '';
$sectionTitle = isset($landing['landing_section_title']) ? trim((string) $landing['landing_section_title']) : '';
$sectionText = isset($landing['landing_section_text']) ? trim((string) $landing['landing_section_text']) : '';
$footerNote = isset($landing['landing_footer_note']) ? trim((string) $landing['landing_footer_note']) : '';
$brandTitle = trim((string) (isset($landing['landing_title']) ? $landing['landing_title'] : $app_name));
$brandInitial = $brandTitle !== '' ? strtoupper(substr($brandTitle, 0, 1)) : 'P';
?>
<div class="card landing-card landing-card-v2">
  <?php if ($landing_preview): ?>
    <div class="alert alert-info landing-preview-banner">
      <strong>Preview mode</strong><br>
      You are viewing the public landing page preview from the admin account. Guests will see this page on the root route only when the landing page is enabled.
    </div>
  <?php endif; ?>

  <header class="landing-topbar">
    <div class="landing-brand-wrap">
      <div class="brand-mark landing-brand-mark"><?php echo panel_e($brandInitial); ?></div>
      <div class="landing-brand-copy">
        <strong><?php echo panel_e($brandTitle !== '' ? $brandTitle : $app_name); ?></strong>
        <span>Internet services portal</span>
      </div>
    </div>
    <div class="landing-topbar-actions">
      <?php foreach ($landing_links as $link): ?>
        <?php
          $linkClass = 'btn landing-nav-btn';
          if ($link['variant'] === 'primary') { $linkClass .= ' btn-primary'; }
          elseif ($link['variant'] === 'secondary') { $linkClass .= ' btn-success'; }
          $linkHref = preg_match('#^(https?:|mailto:|tel:)#i', (string) $link['url']) ? (string) $link['url'] : $app->url($link['url']);
        ?>
        <a class="<?php echo panel_e($linkClass); ?>" href="<?php echo panel_e($linkHref); ?>"<?php echo !empty($link['external']) ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>><?php echo panel_e($link['label']); ?></a>
      <?php endforeach; ?>
    </div>
  </header>

  <section class="landing-hero landing-hero-v2">
    <div class="landing-copy">
      <?php if (!empty($landing['landing_badge'])): ?>
        <span class="badge info landing-badge"><?php echo panel_e($landing['landing_badge']); ?></span>
      <?php endif; ?>
      <h1><?php echo panel_e($brandTitle !== '' ? $brandTitle : $app_name); ?></h1>
      <?php if (!empty($landing['landing_subtitle'])): ?>
        <p class="landing-subtitle"><?php echo nl2br(panel_e($landing['landing_subtitle'])); ?></p>
      <?php endif; ?>

      <div class="landing-actions">
        <?php foreach ($landing_buttons as $button): ?>
          <?php
            $btnClass = 'btn landing-main-btn';
            if ($button['variant'] === 'primary') { $btnClass .= ' btn-primary'; }
            elseif ($button['variant'] === 'secondary') { $btnClass .= ' btn-success'; }
            $buttonHref = preg_match('#^(https?:|mailto:|tel:)#i', (string) $button['url']) ? (string) $button['url'] : $app->url($button['url']);
          ?>
          <a class="<?php echo panel_e($btnClass); ?>" href="<?php echo panel_e($buttonHref); ?>"<?php echo !empty($button['external']) ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>><?php echo panel_e($button['label']); ?></a>
        <?php endforeach; ?>
      </div>

      <div class="landing-hero-points">
        <div class="landing-point-card">
          <span class="landing-point-kicker">Service types</span>
          <strong>XUI & MikroTik UM</strong>
          <small>One panel for modern customer delivery.</small>
        </div>
        <div class="landing-point-card">
          <span class="landing-point-kicker">Customer access</span>
          <strong>Fast self-service</strong>
          <small>Open account details, links, and setup tools from /get.</small>
        </div>
        <div class="landing-point-card">
          <span class="landing-point-kicker">Operations</span>
          <strong>Reseller & admin ready</strong>
          <small>Customers, credits, logs, sync, and support in one place.</small>
        </div>
      </div>
    </div>

    <div class="landing-visual landing-visual-v2">
      <div class="landing-device-shell">
        <div class="landing-device-topbar">
          <span></span><span></span><span></span>
        </div>
        <?php if ($heroImage !== ''): ?>
          <div class="landing-image-frame landing-image-frame-v2">
            <img class="landing-image" src="<?php echo panel_e(preg_match('#^(https?:|mailto:|tel:)#i', (string) $heroImage) ? $heroImage : $app->url($heroImage)); ?>" alt="Landing hero image">
          </div>
        <?php else: ?>
          <div class="landing-placeholder landing-placeholder-v2">
            <div class="landing-placeholder-chip">Secure provider portal</div>
            <strong><?php echo panel_e($app_name); ?></strong>
            <span>Designed for VPN, internet access, and VPS-style service delivery with public access routes and clean operator workflows.</span>
            <div class="landing-placeholder-grid">
              <div class="landing-mini-card">
                <b>/get</b>
                <small>Customer access</small>
              </div>
              <div class="landing-mini-card">
                <b>/login</b>
                <small>Reseller & admin</small>
              </div>
              <div class="landing-mini-card">
                <b>XUI</b>
                <small>Subscriptions & configs</small>
              </div>
              <div class="landing-mini-card">
                <b>UM</b>
                <small>Credentials & tools</small>
              </div>
            </div>
          </div>
        <?php endif; ?>
      </div>
      <div class="landing-side-notes">
        <div class="landing-float-card landing-float-card-primary">
          <span>Public access</span>
          <strong>Customer self-service is ready</strong>
          <small>Account lookup, subscriptions, setup links, and service details.</small>
        </div>
        <div class="landing-float-card landing-float-card-secondary">
          <span>Operator workflow</span>
          <strong>Clear delivery and sync</strong>
          <small>Resellers and admins can manage users, logs, and service actions cleanly.</small>
        </div>
      </div>
    </div>
  </section>

  <section class="landing-strip">
    <div class="landing-strip-item">
      <span>Customer route</span>
      <strong><?php echo panel_e($app->url('/get')); ?></strong>
    </div>
    <div class="landing-strip-item">
      <span>Panel route</span>
      <strong><?php echo panel_e($app->url('/login')); ?></strong>
    </div>
    <div class="landing-strip-item">
      <span>Delivery modes</span>
      <strong>XUI subscriptions + UM credentials</strong>
    </div>
  </section>

  <section class="landing-section landing-section-elevated">
    <?php if ($sectionTitle !== ''): ?><h2><?php echo panel_e($sectionTitle); ?></h2><?php endif; ?>
    <?php if ($sectionText !== ''): ?><p class="landing-section-text"><?php echo nl2br(panel_e($sectionText)); ?></p><?php endif; ?>
    <?php if (!empty($landing_features)): ?>
      <div class="landing-feature-grid landing-feature-grid-v2">
        <?php $featureIndex = 0; foreach ($landing_features as $feature): $featureIndex++; ?>
          <div class="landing-feature-card landing-feature-card-v2">
            <div class="landing-feature-icon"><?php echo panel_e((string) $featureIndex); ?></div>
            <h3><?php echo panel_e($feature['title']); ?></h3>
            <p><?php echo nl2br(panel_e($feature['body'])); ?></p>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <section class="landing-route-strip landing-route-strip-v2">
    <div class="landing-route-card landing-route-card-highlight">
      <span class="landing-route-kicker">Customer access</span>
      <strong>Open service details instantly</strong>
      <p>Use the customer access area to find subscription links, UM credentials, setup links, and account information without contacting support first.</p>
      <a class="btn btn-primary" href="<?php echo panel_e($app->url('/get')); ?>">Go to /get</a>
    </div>
    <div class="landing-route-card">
      <span class="landing-route-kicker">Panel login</span>
      <strong>Manage operations securely</strong>
      <p>Resellers and admins can sign in to handle customers, templates, nodes, sync, transactions, activity, and public delivery behavior.</p>
      <a class="btn" href="<?php echo panel_e($app->url('/login')); ?>">Open /login</a>
    </div>
  </section>

  <?php if (!empty($landing_links)): ?>
    <section class="landing-section">
      <h2>Quick links</h2>
      <p class="landing-section-text">Use these buttons to surface app downloads, setup pages, payment pages, or support destinations directly from the landing page.</p>
      <div class="landing-link-grid landing-link-grid-v2">
        <?php foreach ($landing_links as $link): ?>
          <?php
            $linkClass = 'btn landing-utility-btn';
            if ($link['variant'] === 'primary') { $linkClass .= ' btn-primary'; }
            elseif ($link['variant'] === 'secondary') { $linkClass .= ' btn-success'; }
            $linkHref = preg_match('#^(https?:|mailto:|tel:)#i', (string) $link['url']) ? (string) $link['url'] : $app->url($link['url']);
          ?>
          <a class="<?php echo panel_e($linkClass); ?>" href="<?php echo panel_e($linkHref); ?>"<?php echo !empty($link['external']) ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>><?php echo panel_e($link['label']); ?></a>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <?php if ($footerNote !== ''): ?>
    <div class="muted-box landing-footer-note"><?php echo nl2br(panel_e($footerNote)); ?></div>
  <?php endif; ?>
</div>
