<?php
if (!defined('PANEL_ROOT')) {
    define('PANEL_ROOT', dirname(__DIR__));
}
require PANEL_ROOT . '/app/bootstrap.php';
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'CLI only';
    exit;
}
$summary = $app->runMaintenanceCron(false);
echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
