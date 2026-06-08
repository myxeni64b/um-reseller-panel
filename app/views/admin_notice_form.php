<form method="post" class="stack-form card">
  <label>Title<input type="text" name="title" value="<?php echo panel_e($record['title']); ?>"></label>
  <label>Body<textarea name="body" rows="6"><?php echo panel_e($record['body']); ?></textarea></label>
  <label>Target<select name="target"><option value="reseller" <?php echo $record['target'] === 'reseller' ? 'selected' : ''; ?>>Reseller panel</option><option value="public" <?php echo $record['target'] === 'public' ? 'selected' : ''; ?>>Client subscription/public pages</option><option value="all" <?php echo $record['target'] === 'all' ? 'selected' : ''; ?>>Both reseller and public</option></select></label>
  <label>Start At (optional)<input type="text" name="start_at" placeholder="2026-03-22 12:00:00" value="<?php echo panel_e($record['start_at']); ?>"></label>
  <label>End At (optional)<input type="text" name="end_at" placeholder="2026-03-29 12:00:00" value="<?php echo panel_e($record['end_at']); ?>"></label>
  <label>Status<select name="status"><option value="active" <?php echo $record['status'] === 'active' ? 'selected' : ''; ?>>Active</option><option value="disabled" <?php echo $record['status'] === 'disabled' ? 'selected' : ''; ?>>Disabled</option></select></label>
  <?php if (!empty($errors)): ?><div class="form-errors"><?php foreach ($errors as $group): foreach ($group as $err): ?><div><?php echo panel_e($err); ?></div><?php endforeach; endforeach; ?></div><?php endif; ?>
  <input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>">
  <button class="btn btn-primary" type="submit"><?php echo $mode === 'edit' ? 'Save notice' : 'Create notice'; ?></button>
</form>
