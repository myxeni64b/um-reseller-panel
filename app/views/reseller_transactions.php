<div class="card">
  <div class="card-head"><h3>Your credit transactions</h3><a class="btn" href="<?php echo panel_e($app->url('/reseller/dashboard')); ?>">Back to dashboard</a></div>
  <form class="toolbar" method="get">
    <select name="type">
      <option value="">All types</option>
      <?php foreach ($types as $type): ?>
        <option value="<?php echo panel_e($type); ?>" <?php echo $selected_type === $type ? 'selected' : ''; ?>><?php echo panel_e($type); ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn" type="submit">Apply</button>
  </form>
  <table class="table">
    <tr><th>Time</th><th>Amount GB</th><th>Type</th><th>Note</th></tr>
    <?php foreach ($items as $item): $note = isset($item['note']) ? (string) $item['note'] : ''; $serviceType = ''; if (preg_match('/\b(UM|XUI)\b/i', $note, $m)) { $serviceType = strtoupper($m[1]); } $serviceBadge = $serviceType === 'UM' ? 'info' : ($serviceType === 'XUI' ? 'good' : ''); ?>
      <tr>
        <td><?php echo panel_e(isset($item['created_at']) ? $item['created_at'] : ''); ?></td>
        <td><span class="badge <?php echo (float) $item['amount_gb'] >= 0 ? 'good' : 'bad'; ?>"><?php echo panel_e(panel_format_gb($item['amount_gb'])); ?></span></td>
        <td><?php echo panel_e(isset($item['type']) ? $item['type'] : ''); ?><?php if ($serviceType !== ''): ?> <span class="badge <?php echo panel_e($serviceBadge); ?>"><?php echo panel_e($serviceType); ?></span><?php endif; ?></td>
        <td><?php echo panel_e($note); ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($items)): ?><tr><td colspan="4"><div class="muted-box">No transactions found.</div></td></tr><?php endif; ?>
  </table>
</div>
