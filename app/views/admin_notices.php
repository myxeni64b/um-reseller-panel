<div class="card">
  <div class="card-head"><h3>Notices</h3><a class="btn btn-primary" href="<?php echo panel_e($app->url('/admin/notices/create')); ?>">Create notice</a></div>
  <table class="table">
    <tr><th>Title</th><th>Target</th><th>Period</th><th>Status</th><th>Actions</th></tr>
    <?php foreach ($notices as $item): ?>
      <tr>
        <td><strong><?php echo panel_e($item['title']); ?></strong><br><small><?php echo nl2br(panel_e($item['body'])); ?></small></td>
        <td><?php echo panel_e($item['target']); ?></td>
        <td><?php echo !empty($item['start_at']) ? panel_e($item['start_at']) : 'Immediate'; ?> → <?php echo !empty($item['end_at']) ? panel_e($item['end_at']) : 'Permanent'; ?></td>
        <td><span class="badge <?php echo $item['status'] === 'active' ? 'good' : 'bad'; ?>"><?php echo panel_e($item['status']); ?></span></td>
        <td class="actions">
          <a class="btn" href="<?php echo panel_e($app->url('/admin/notices/' . $item['id'] . '/edit')); ?>">Edit</a>
          <form method="post" action="<?php echo panel_e($app->url('/admin/notices/' . $item['id'] . '/toggle')); ?>"><input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>"><button class="btn" type="submit">Toggle</button></form>
          <form method="post" action="<?php echo panel_e($app->url('/admin/notices/' . $item['id'] . '/delete')); ?>" onsubmit="return confirm('Delete this notice?');"><input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>"><button class="btn btn-danger" type="submit">Delete</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($notices)): ?><tr><td colspan="5"><div class="muted-box">No notices created yet.</div></td></tr><?php endif; ?>
  </table>
</div>
