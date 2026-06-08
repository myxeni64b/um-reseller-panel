<div class="card">
  <div class="card-head"><h3>Resellers</h3><a class="btn btn-primary" href="<?php echo panel_e($app->url('/admin/resellers/create')); ?>">Create reseller</a></div>
  <div class="muted-box" style="margin-bottom:12px;">
    Resellers are shown in a compact list view. Credit adjustment is kept under each reseller row to keep the page shorter and easier to scan.
  </div>

  <div class="table-wrap reseller-list-table-wrap">
    <table class="table reseller-list-table">
      <thead>
        <tr>
          <th>Reseller</th>
          <th>Credit</th>
          <th>Traffic rules</th>
          <th>Limits</th>
          <th>API</th>
          <th>Templates</th>
          <th style="width:260px;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($resellers as $item): ?>
          <?php
            $names = array();
            foreach ((array) $item['allowed_template_ids'] as $tid) {
              if (isset($template_map[$tid])) { $names[] = $template_map[$tid]['public_label']; }
            }
            $templatesText = implode(', ', $names);
            $templatesCount = count($names);
            $creditValue = isset($item['credit_gb']) ? (float) $item['credit_gb'] : 0.0;
            $transactionsUrl = $app->url('/admin/transactions') . '?reseller_id=' . rawurlencode($item['id']);
            $maxTraffic = isset($item['max_customer_traffic_gb']) ? (float) $item['max_customer_traffic_gb'] : 0.0;
            $maxTrafficText = $maxTraffic > 0 ? panel_format_gb($maxTraffic) . ' GB' : 'Unlimited';
            $expirationDays = isset($item['max_expiration_days']) ? (int) $item['max_expiration_days'] : (int) $item['fixed_duration_days'];
            $expirationText = $expirationDays > 0 ? $expirationDays . ' days' : 'Unlimited';
            $maxIpLimit = isset($item['max_ip_limit']) ? (int) $item['max_ip_limit'] : 0;
            $maxIpText = $maxIpLimit > 0 ? (string) $maxIpLimit : 'Unlimited';
            $trafficModeText = (!isset($item['allow_fractional_traffic_gb']) || !empty($item['allow_fractional_traffic_gb'])) ? 'Fractional allowed' : 'Whole GB only';
            $apiKeyPreview = !empty($item['api_key']) ? substr($item['api_key'], 0, 8) . '…' : 'auto';
          ?>
          <tr>
            <td>
              <strong><?php echo panel_e($item['display_name']); ?></strong><br>
              <span class="muted">@<?php echo panel_e($item['username']); ?></span>
              <span class="muted">• Prefix <code class="inline-code"><?php echo panel_e($item['prefix']); ?></code></span><br>
              <span class="badge <?php echo !empty($item['restrict']) ? 'bad' : 'muted'; ?>"><?php echo !empty($item['restrict']) ? 'Restricted' : 'Open'; ?></span>
              <span class="badge <?php echo $item['status'] === 'active' ? 'good' : 'bad'; ?>"><?php echo panel_e($item['status']); ?></span>
            </td>
            <td>
              <strong><?php echo panel_e(panel_format_gb($creditValue)); ?> GB</strong>
            </td>
            <td>
              Min <?php echo panel_e(panel_format_gb(isset($item['min_customer_traffic_gb']) ? $item['min_customer_traffic_gb'] : 0)); ?> GB<br>
              Max <?php echo panel_e($maxTrafficText); ?><br>
              <span class="muted"><?php echo panel_e($trafficModeText); ?></span>
            </td>
            <td>
              Expiration <?php echo panel_e($expirationText); ?><br>
              Max IP <?php echo panel_e($maxIpText); ?>
            </td>
            <td>
              Key <code class="inline-code"><?php echo panel_e($apiKeyPreview); ?></code><br>
              <span class="muted">Status <?php echo panel_e($item['status']); ?></span>
            </td>
            <td>
              <?php if ($templatesCount > 0): ?>
                <strong><?php echo panel_e($templatesCount); ?> assigned</strong><br>
                <span class="muted reseller-table-template-preview"><?php echo panel_e($templatesText); ?></span>
              <?php else: ?>
                <span class="muted">No template assigned</span>
              <?php endif; ?>
            </td>
            <td>
              <div class="actions reseller-row-actions">
                <a class="btn" href="<?php echo panel_e($app->url('/admin/resellers/' . $item['id'] . '/edit')); ?>">Edit</a>
                <form method="post" action="<?php echo panel_e($app->url('/admin/resellers/' . $item['id'] . '/toggle')); ?>"><input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>"><button class="btn" type="submit">Toggle</button></form>
                <a class="btn" href="<?php echo panel_e($transactionsUrl); ?>">Transactions</a>
                <form method="post" action="<?php echo panel_e($app->url('/admin/resellers/' . $item['id'] . '/delete')); ?>" onsubmit="return confirm('Delete reseller?');"><input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>"><button class="btn btn-danger" type="submit">Delete</button></form>
              </div>
              <details class="reseller-row-adjust-panel">
                <summary>GB adjustment</summary>
                <form method="post" action="<?php echo panel_e($app->url('/admin/resellers/' . $item['id'] . '/credit')); ?>" class="stack-form reseller-row-credit-form">
                  <input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>">
                  <label>Adjust GB<input type="number" step="0.01" name="amount_gb" placeholder="Example: +5 or -2"></label>
                  <label>Note<input type="text" name="note" placeholder="Why this change was made"></label>
                  <div class="actions">
                    <button class="btn btn-primary" type="submit">Save adjustment</button>
                    <a class="btn" href="<?php echo panel_e($transactionsUrl); ?>">Open transactions</a>
                  </div>
                </form>
              </details>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($resellers)): ?>
          <tr><td colspan="7"><div class="muted-box">No resellers found.</div></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
