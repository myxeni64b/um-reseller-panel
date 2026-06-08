<div class="card">
  <div class="card-head"><h3><?php echo panel_e($ticket['ticket_no']); ?> - <?php echo panel_e($ticket['subject']); ?></h3><span class="badge <?php echo $ticket['status'] === 'closed' ? 'bad' : 'good'; ?>"><?php echo panel_e($ticket['status']); ?></span></div>
  <div class="chat-thread">
    <?php foreach ($messages as $msg): ?>
      <div class="chat-bubble <?php echo $msg['sender_role'] === $scope ? 'mine' : 'other'; ?>">
        <div class="chat-meta"><?php echo panel_e($msg['sender_role']); ?> &middot; <?php echo panel_e($msg['created_at']); ?></div>
        <div><?php echo nl2br(panel_e($msg['body'])); ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<form method="post" action="<?php echo panel_e($app->url('/' . $scope . '/tickets/' . $ticket['id'] . '/reply')); ?>" class="stack-form card">
  <label>Reply<textarea name="body" rows="5"></textarea></label>
  <input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>">
  <button class="btn btn-primary" type="submit">Send Reply</button>
</form>
<form method="post" action="<?php echo panel_e($app->url('/' . $scope . '/tickets/' . $ticket['id'] . '/status')); ?>" class="inline-form card compact">
  <input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>">
  <select name="status"><option value="open">Open</option><option value="waiting-admin">Waiting Admin</option><option value="waiting-reseller">Waiting Reseller</option><option value="closed">Closed</option></select>
  <button class="btn" type="submit">Update Status</button>
</form>

<?php if ($scope === 'admin'): ?>
<form method="post" action="<?php echo panel_e($app->url('/admin/tickets/' . $ticket['id'] . '/delete')); ?>" class="inline-form card compact" onsubmit="return confirm('Delete this ticket and all replies?');">
  <input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>">
  <button class="btn btn-danger" type="submit">Delete Ticket</button>
</form>
<?php endif; ?>
