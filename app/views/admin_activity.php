<div class="card">
  <div class="card-head"><h3>Reseller activity logs</h3></div>
  <form class="toolbar" method="get">
    <select name="reseller_id">
      <option value="">All resellers</option>
      <?php foreach ($resellers as $r): ?>
        <option value="<?php echo panel_e($r['id']); ?>" <?php echo $selected_reseller_id === $r['id'] ? 'selected' : ''; ?>><?php echo panel_e($r['display_name']); ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn" type="submit">Apply</button>
  </form>
  <table class="table">
    <tr><th>Time</th><th>Reseller</th><th>Action</th><th>Type</th><th>Customer</th><th>Details</th><th>IP</th></tr>
    <?php foreach ($items as $item): $res = null; foreach ($resellers as $r) { if ($r['id'] === $item['reseller_id']) { $res = $r; break; } } $context = isset($item['context']) && is_array($item['context']) ? $item['context'] : array(); $stype = !empty($context['server_type']) ? strtolower((string) $context['server_type']) : ''; ?>
      <tr>
        <td><?php echo panel_e(isset($item['created_at']) ? $item['created_at'] : ''); ?></td>
        <td><?php echo panel_e($res ? $res['display_name'] : $item['reseller_id']); ?></td>
        <td><?php echo panel_e($item['action']); ?></td>
        <td>
          <?php if ($stype !== ''): ?>
            <span class="badge <?php echo $stype === 'um' ? 'info' : 'good'; ?>"><?php echo panel_e(strtoupper($stype)); ?></span>
            <?php if (!empty($context['profile_name'])): ?><br><small><?php echo panel_e($context['profile_name']); ?></small><?php endif; ?>
            <?php if (!empty($context['node_title'])): ?><br><small><?php echo panel_e($context['node_title']); ?></small><?php endif; ?>
          <?php else: ?>
            <span class="muted">-</span>
          <?php endif; ?>
        </td>
        <td><?php echo panel_e($item['customer_name']); ?><br><small><?php echo panel_e($item['system_name']); ?></small></td>
        <td><code class="inline-code"><?php echo panel_e(json_encode($context)); ?></code></td>
        <td><?php echo panel_e(isset($item['ip']) ? $item['ip'] : ''); ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($items)): ?><tr><td colspan="7"><div class="muted-box">No reseller activity logged yet.</div></td></tr><?php endif; ?>
  </table>
</div>
