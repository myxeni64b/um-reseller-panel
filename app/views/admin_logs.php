<div class="card">
  <div class="card-head"><h3>System logs</h3></div>
  <form class="toolbar" method="get">
    <select name="name">
      <?php foreach ($log_names as $name): ?>
        <option value="<?php echo panel_e($name); ?>" <?php echo $selected_log_name === $name ? 'selected' : ''; ?>><?php echo panel_e($name); ?></option>
      <?php endforeach; ?>
    </select>
    <select name="limit">
      <?php foreach (array(50,100,200,300,500) as $n): ?><option value="<?php echo $n; ?>" <?php echo (int) $limit === $n ? 'selected' : ''; ?>><?php echo $n; ?> rows</option><?php endforeach; ?>
    </select>
    <button class="btn" type="submit">Apply</button>
  </form>
  <form method="post" action="<?php echo panel_e($app->url('/admin/logs/clear')); ?>" class="inline-form" onsubmit="return confirm('Clear this log and all rotated files?');" style="margin:12px 0;">
    <input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>">
    <input type="hidden" name="name" value="<?php echo panel_e($selected_log_name); ?>">
    <button class="btn btn-danger" type="submit">Clear Selected Log</button>
  </form>
  <table class="table">
    <tr><th>Time</th><th>Message</th><th>Path</th><th>Context</th></tr>
    <?php foreach ($rows as $row): ?>
      <tr>
        <td><?php echo panel_e(isset($row['time']) ? $row['time'] : ''); ?></td>
        <td><?php echo panel_e(isset($row['message']) ? $row['message'] : ''); ?></td>
        <td><?php echo panel_e(isset($row['path']) ? $row['path'] : ''); ?></td>
        <td><code class="inline-code"><?php echo panel_e(json_encode(isset($row['context']) ? $row['context'] : array())); ?></code></td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($rows)): ?><tr><td colspan="4"><div class="muted-box">No rows found for this log yet.</div></td></tr><?php endif; ?>
  </table>
</div>
