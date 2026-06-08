<?php
$stats = isset($stats) && is_array($stats) ? $stats : array();
$summary = isset($stats['summary']) && is_array($stats['summary']) ? $stats['summary'] : array();
$daily = isset($stats['daily']) && is_array($stats['daily']) ? $stats['daily'] : array('labels' => array(), 'values' => array());
$monthly = isset($stats['monthly']) && is_array($stats['monthly']) ? $stats['monthly'] : array('labels' => array(), 'values' => array());
?>
<div class="grid cards-4">
  <div class="stat-card"><span>Credit</span><strong><?php echo panel_e(panel_format_gb($reseller['credit_gb'])); ?> GB</strong></div>
  <div class="stat-card"><span>Active Users</span><strong><?php echo (int) (isset($active_user_count) ? $active_user_count : 0); ?></strong></div>
  <div class="stat-card"><span>Total Sold</span><strong><?php echo panel_e(panel_format_gb(isset($summary['sold_total_gb']) ? $summary['sold_total_gb'] : 0)); ?> GB</strong></div>
  <div class="stat-card"><span>Refunded</span><strong><?php echo panel_e(panel_format_gb(isset($summary['refunded_total_gb']) ? $summary['refunded_total_gb'] : 0)); ?> GB</strong></div>
</div>
<div class="grid two-col">
  <div class="card">
    <div class="card-head"><h3>Account</h3><a class="btn" href="<?php echo panel_e($app->url('/reseller/profile')); ?>">Open profile</a></div>
    <div class="grid two-col">
      <div class="muted-box"><strong>API</strong><br><?php echo !empty($api_enabled) ? 'Enabled by admin' : 'Disabled by admin'; ?></div>
      <div class="muted-box"><strong>Restriction</strong><br><?php echo !empty($reseller['restrict']) ? 'Restricted account' : 'Open account'; ?></div>
      <div class="muted-box"><strong>Customers</strong><br><?php echo (int) (isset($customers_total) ? $customers_total : count($customers)); ?> total</div>
      <div class="muted-box"><strong>XUI Traffic Edit</strong><br><?php echo !array_key_exists('allow_xui_traffic_edit', $reseller) || !empty($reseller['allow_xui_traffic_edit']) ? 'Allowed' : 'Disabled by admin'; ?></div>
    </div>
    <div class="actions" style="margin-top:14px;">
      <a class="btn" href="<?php echo panel_e($app->url('/reseller/transactions')); ?>">Transactions</a>
      <a class="btn" href="<?php echo panel_e($app->url('/reseller/activity')); ?>">Activity Logs</a>
    </div>
  </div>
  <div class="card">
    <div class="card-head"><h3>Sales Snapshot</h3><span class="badge info">Local charts</span></div>
    <div class="grid two-col">
      <div class="muted-box"><strong>Net Sales</strong><br><?php echo panel_e(panel_format_gb(isset($summary['net_sales_gb']) ? $summary['net_sales_gb'] : 0)); ?> GB</div>
      <div class="muted-box"><strong>Top-Ups</strong><br><?php echo panel_e(panel_format_gb(isset($summary['topup_total_gb']) ? $summary['topup_total_gb'] : 0)); ?> GB</div>
      <div class="muted-box"><strong>Remaining Traffic</strong><br><?php echo panel_e(panel_format_gb(isset($summary['remaining_total_gb']) ? $summary['remaining_total_gb'] : 0)); ?> GB</div>
      <div class="muted-box"><strong>Outstanding Usage</strong><br>Sold but not used yet across current customers</div>
    </div>
    <div style="margin-top:14px;">
      <strong>Last 30 days sales</strong>
      <canvas id="sales-daily-chart" height="160"></canvas>
    </div>
    <div style="margin-top:14px;">
      <strong>Last 12 months sales</strong>
      <canvas id="sales-monthly-chart" height="160"></canvas>
    </div>
  </div>
</div>
<div class="card">
  <div class="card-head"><h3>Allowed server / inbound list</h3><div class="actions"><a class="btn btn-primary" href="<?php echo panel_e($app->url('/reseller/customers/create')); ?>">Create XUI client</a><a class="btn" style="background:#f5b700;color:#111827;border-color:#f5b700;" href="<?php echo panel_e($app->url('/reseller/customers/create-um')); ?>">Create UM client</a></div></div>
  <table class="table">
    <tr><th>Template</th><th>Server</th><th>Type</th><th>Inbound / Profile</th><th>Protocol / Billing</th></tr>
    <?php foreach ($templates as $tpl): $node = isset($node_map[$tpl['node_id']]) ? $node_map[$tpl['node_id']] : null; $tplType = !empty($tpl['server_type']) ? strtolower((string) $tpl['server_type']) : 'xui'; ?>
      <tr>
        <td><?php echo panel_e($tpl['public_label']); ?></td>
        <td><?php echo panel_e($node ? $node['title'] : 'Unknown'); ?></td>
        <td><span class="badge <?php echo $tplType === 'um' ? 'info' : 'good'; ?>"><?php echo panel_e(strtoupper($tplType)); ?></span></td>
        <td><?php echo panel_e($tplType === 'um' ? (isset($tpl['um_profile_name']) ? $tpl['um_profile_name'] : $tpl['title']) : $tpl['inbound_name']); ?><?php echo ($tplType !== 'um' && !empty($tpl['port'])) ? ' :' . panel_e($tpl['port']) : ''; ?></td>
        <td><?php echo $tplType === 'um' ? panel_e(panel_format_gb(isset($tpl['billing_gb']) ? $tpl['billing_gb'] : 0)) . ' GB billing' : panel_e($tpl['protocol']); ?><br><small><?php echo $tplType === 'um' ? panel_e(isset($tpl['title']) ? $tpl['title'] : '') : panel_e(isset($tpl['network']) ? $tpl['network'] : '-'); ?><?php if ($tplType !== 'um'): ?> / <?php echo panel_e(isset($tpl['security']) ? $tpl['security'] : '-'); ?><?php endif; ?></small></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
<div class="card">
  <div class="card-head"><h3>Recent customers</h3><a class="btn" href="<?php echo panel_e($app->url('/reseller/customers')); ?>">View all</a></div>
  <table class="table">
    <tr><th>Name</th><th>Type</th><th>Traffic</th><th>Status</th><th>Open</th></tr>
    <?php foreach ($customers as $item):
      $customerType = $app->customerServerType($item);
    ?>
      <tr>
        <td><a href="<?php echo panel_e($app->url('/reseller/customers/' . $item['id'])); ?>"><?php echo panel_e($item['display_name']); ?></a></td>
        <td><span class="badge <?php echo $customerType === 'um' ? 'info' : 'good'; ?>"><?php echo panel_e(strtoupper($customerType)); ?></span></td>
        <td><?php echo panel_e(panel_format_gb($item['traffic_gb'])); ?> GB</td>
        <td><span class="badge <?php echo $app->customerRuntimeState($item) === 'active' ? 'good' : 'muted'; ?>"><?php echo panel_e($app->customerRuntimeStatusLabel($item)); ?></span></td>
        <td><a class="btn" href="<?php echo panel_e($app->url('/reseller/customers/' . $item['id'])); ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
<script>
(function(){
  function drawBars(id, labels, values){
    var canvas = document.getElementById(id);
    if(!canvas || !canvas.getContext){ return; }
    var ctx = canvas.getContext('2d');
    var w = canvas.width = canvas.clientWidth || 600;
    var h = canvas.height = canvas.height || 160;
    ctx.clearRect(0,0,w,h);
    var pad = 28;
    var max = 0;
    for(var i=0;i<values.length;i++){ if(values[i] > max){ max = values[i]; } }
    max = max || 1;
    var step = Math.max(1, Math.ceil(labels.length / 8));
    var barW = Math.max(4, (w - pad * 2) / Math.max(1, values.length) - 2);
    ctx.strokeStyle = '#cbd5e1';
    ctx.beginPath(); ctx.moveTo(pad, 10); ctx.lineTo(pad, h-pad); ctx.lineTo(w-10, h-pad); ctx.stroke();
    for(var j=0;j<values.length;j++){
      var x = pad + j * ((w - pad * 2) / Math.max(1, values.length));
      var bh = ((h - pad - 16) * (values[j] / max));
      ctx.fillStyle = '#2563eb';
      ctx.fillRect(x, h-pad-bh, barW, bh);
      if(j % step === 0){
        ctx.fillStyle = '#64748b';
        ctx.font = '10px sans-serif';
        ctx.fillText(labels[j].slice(5), x, h-10);
      }
    }
  }
  drawBars('sales-daily-chart', <?php echo json_encode(isset($daily['labels']) ? array_values($daily['labels']) : array()); ?>, <?php echo json_encode(isset($daily['values']) ? array_values($daily['values']) : array()); ?>);
  drawBars('sales-monthly-chart', <?php echo json_encode(isset($monthly['labels']) ? array_values($monthly['labels']) : array()); ?>, <?php echo json_encode(isset($monthly['values']) ? array_values($monthly['values']) : array()); ?>);
})();
</script>
