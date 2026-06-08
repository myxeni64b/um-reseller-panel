<div class="card">
  <div class="card-head"><h3>Credit transactions</h3></div>
  <form class="toolbar" method="get">
    <select name="reseller_id">
      <option value="">All resellers</option>
      <?php foreach ($resellers as $r): ?>
        <option value="<?php echo panel_e($r['id']); ?>" <?php echo $selected_reseller_id === $r['id'] ? 'selected' : ''; ?>><?php echo panel_e($r['display_name']); ?></option>
      <?php endforeach; ?>
    </select>
    <select name="type">
      <option value="">All types</option>
      <?php foreach ($types as $type): ?>
        <option value="<?php echo panel_e($type); ?>" <?php echo $selected_type === $type ? 'selected' : ''; ?>><?php echo panel_e($type); ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn" type="submit">Apply</button>
  </form>
  <table class="table">
    <tr><th>Time</th><th>Reseller</th><th>Amount GB</th><th>Type</th><th>Note</th></tr>
    <?php foreach ($items as $item): $resName=''; foreach ($resellers as $r) { if ($r['id'] === $item['reseller_id']) { $resName = $r['display_name']; break; } } $note = isset($item['note']) ? (string) $item['note'] : ''; $serviceType = ''; if (preg_match('/\b(UM|XUI)\b/i', $note, $m)) { $serviceType = strtoupper($m[1]); } $serviceBadge = $serviceType === 'UM' ? 'info' : ($serviceType === 'XUI' ? 'good' : ''); ?>
      <tr>
        <td><?php echo panel_e(isset($item['created_at']) ? $item['created_at'] : ''); ?></td>
        <td><?php echo panel_e($resName !== '' ? $resName : (isset($item['reseller_id']) ? $item['reseller_id'] : '')); ?></td>
        <td><span class="badge <?php echo (float) $item['amount_gb'] >= 0 ? 'good' : 'bad'; ?>"><?php echo panel_e(panel_format_gb($item['amount_gb'])); ?></span></td>
        <td>
          <?php echo panel_e(isset($item['type']) ? $item['type'] : ''); ?>
          <?php if ($serviceType !== ''): ?><br><span class="badge <?php echo panel_e($serviceBadge); ?>"><?php echo panel_e($serviceType); ?></span><?php endif; ?>
        </td>
        <td><?php echo panel_e($note); ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($items)): ?><tr><td colspan="5"><div class="muted-box">No transactions found.</div></td></tr><?php endif; ?>
  </table>
</div>
