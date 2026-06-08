<form method="post" class="stack-form card">
  <label>Subject<input type="text" name="subject" value="<?php echo panel_e($record['subject']); ?>"></label>
  <label>Priority<select name="priority"><option value="low" <?php echo $record['priority'] === 'low' ? 'selected' : ''; ?>>Low</option><option value="normal" <?php echo $record['priority'] === 'normal' ? 'selected' : ''; ?>>Normal</option><option value="high" <?php echo $record['priority'] === 'high' ? 'selected' : ''; ?>>High</option></select></label>
  <label>Message<textarea name="body" rows="7"><?php echo panel_e($record['body']); ?></textarea></label>
  <?php if (!empty($errors)): ?><div class="form-errors"><?php foreach ($errors as $group): foreach ($group as $err): ?><div><?php echo panel_e($err); ?></div><?php endforeach; endforeach; ?></div><?php endif; ?>
  <input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>">
  <button class="btn btn-primary" type="submit">Open Ticket</button>
</form>
