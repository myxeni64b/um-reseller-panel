<?php
require PANEL_ROOT . '/app/lib/functions.php';
require PANEL_ROOT . '/app/lib/JsonStore.php';
require PANEL_ROOT . '/app/lib/XuiAdapter.php';
require PANEL_ROOT . '/app/lib/MikrotikUmAdapter.php';
require PANEL_ROOT . '/app/PanelApp.php';
$config = require PANEL_ROOT . '/config.php';
date_default_timezone_set(isset($config['timezone']) ? $config['timezone'] : 'Europe/Sofia');
$app = new PanelApp($config);
