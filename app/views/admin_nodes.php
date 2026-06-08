<div class="card">
  <div class="card-head"><h3>Servers</h3><a class="btn btn-primary" href="<?php echo panel_e($app->url('/admin/nodes/create')); ?>">Add server</a></div>
  <table class="table">
    <tr><th>Title</th><th>Type</th><th>Access</th><th>Base URL</th><th>Panel Path</th><th>Delivery / Subscription</th><th>Timeout / Retry</th><th>TLS</th><th>Status</th><th>Actions</th></tr>
    <?php foreach ($nodes as $item): $stype = !empty($item['server_type']) ? strtolower((string) $item['server_type']) : 'xui'; $umMode = !empty($item['um_api_mode']) ? strtolower((string) $item['um_api_mode']) : 'rest'; ?>
      <tr>
        <td><?php echo panel_e($item['title']); ?><br><small><?php echo panel_e($item['slug']); ?></small></td>
        <td><span class="badge <?php echo $stype === 'um' ? 'info' : 'good'; ?>"><?php echo strtoupper(panel_e($stype)); ?></span></td>
        <td>
          <?php if ($stype === 'um'): ?>
            <span class="badge"><?php echo $umMode === 'internal' ? 'Internal API' : 'REST API'; ?></span>
            <br><small>
              <?php if ($umMode === 'internal'): ?>
                <?php echo !empty($item['um_api_ssl']) ? 'API-SSL' : 'API'; ?>
                <?php $host = !empty($item['um_api_host']) ? $item['um_api_host'] : parse_url((string) $item['base_url'], PHP_URL_HOST); ?>
                <?php echo panel_e($host !== null ? $host : ''); ?>:<?php echo panel_e(!empty($item['um_api_port']) ? $item['um_api_port'] : (!empty($item['um_api_ssl']) ? 8729 : 8728)); ?>
              <?php else: ?>
                <?php $scheme = strtoupper((string) parse_url((string) $item['base_url'], PHP_URL_SCHEME)); echo panel_e($scheme !== '' ? $scheme : 'HTTPS'); ?>
              <?php endif; ?>
            </small>
          <?php else: ?>
            <span class="badge">XUI Panel API</span>
          <?php endif; ?>
        </td>
        <td><?php echo panel_e($item['base_url']); ?></td>
        <td><?php echo panel_e($item['panel_path']); ?></td>
        <td>
          <?php if ($stype === 'um'): ?>
            <?php echo panel_e(isset($item['um_connection_mode']) ? strtoupper($item['um_connection_mode']) : 'TEXT'); ?>
            <?php if (!empty($item['um_connection_file_url'])): ?><br><small><?php echo panel_e($item['um_connection_file_url']); ?></small><?php endif; ?>
          <?php else: ?>
            <?php echo panel_e(isset($item['subscription_base']) ? $item['subscription_base'] : '-'); ?>
          <?php endif; ?>
        </td>
        <td><?php echo panel_e(isset($item['connect_timeout']) ? $item['connect_timeout'] : 8); ?>s / <?php echo panel_e(isset($item['request_timeout']) ? $item['request_timeout'] : 20); ?>s<br><small><?php echo panel_e(isset($item['retry_attempts']) ? $item['retry_attempts'] : 2); ?> tries</small></td>
        <td><?php echo !empty($item['allow_insecure_tls']) ? '<span class="badge bad">Insecure</span>' : '<span class="badge good">Verified</span>'; ?></td>
        <td><span class="badge <?php echo $item['status'] === 'active' ? 'good' : 'bad'; ?>"><?php echo panel_e($item['status']); ?></span></td>
        <td class="actions">
          <a class="btn" href="<?php echo panel_e($app->url('/admin/nodes/' . $item['id'] . '/edit')); ?>">Edit</a>
          <form method="post" action="<?php echo panel_e($app->url('/admin/nodes/' . $item['id'] . '/test')); ?>"><input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>"><button class="btn" type="submit">Test</button></form>
          <?php if ($stype === 'xui'): ?><form method="post" action="<?php echo panel_e($app->url('/admin/nodes/' . $item['id'] . '/import-inbounds')); ?>"><input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>"><button class="btn" type="submit">Import Inbounds</button></form><?php else: ?><form method="post" action="<?php echo panel_e($app->url('/admin/nodes/' . $item['id'] . '/import-profiles')); ?>"><input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>"><button class="btn" type="submit">Import Profiles</button></form><?php endif; ?>
          <form method="post" action="<?php echo panel_e($app->url('/admin/nodes/' . $item['id'] . '/toggle')); ?>"><input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>"><button class="btn" type="submit">Toggle</button></form>
          <form method="post" action="<?php echo panel_e($app->url('/admin/nodes/' . $item['id'] . '/delete')); ?>" onsubmit="return confirm('Delete node?');"><input type="hidden" name="_token" value="<?php echo panel_e($csrf_token); ?>"><button class="btn btn-danger" type="submit">Delete</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
