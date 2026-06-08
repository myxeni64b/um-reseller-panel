<?php
define('PANEL_ROOT', dirname(__DIR__));
require PANEL_ROOT . '/app/bootstrap.php';
if (!empty($config['debug'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT);
    ini_set('display_errors', '0');
}
$app->run();
