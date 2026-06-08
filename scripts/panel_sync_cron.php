<?php
$url = isset($argv[1]) ? trim((string) $argv[1]) : (getenv('PANEL_SYNC_URL') ? trim((string) getenv('PANEL_SYNC_URL')) : '');
$secret = isset($argv[2]) ? trim((string) $argv[2]) : (getenv('PANEL_SYNC_SECRET') ? trim((string) getenv('PANEL_SYNC_SECRET')) : '');
$stderr = defined('STDERR') ? STDERR : @fopen('php://stderr', 'w');
if ($url === '' || !preg_match('#^https?://#i', $url)) {
    $msg = "Panel sync URL argument is required.\n";
    if (is_resource($stderr)) { fwrite($stderr, $msg); } else { echo $msg; }
    exit(1);
}

$headers = array('Accept: application/json', 'User-Agent: XUI-PanelSyncCron/1.0');
if ($secret !== '') {
    $headers[] = 'X-Panel-Sync-Secret: ' . $secret;
    $headers[] = 'Authorization: Bearer ' . $secret;
}

if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $err !== '') {
        $msg = "Panel sync cron request failed: " . ($err !== '' ? $err : 'Unknown error') . "\n";
        if (is_resource($stderr)) { fwrite($stderr, $msg); } else { echo $msg; }
        exit(1);
    }
} else {
    $context = stream_context_create(array('http' => array('method' => 'GET', 'timeout' => 120, 'header' => implode("\r\n", $headers) . "\r\n")));
    $body = @file_get_contents($url, false, $context);
    $code = 200;
    if ($body === false) {
        $msg = "Panel sync cron request failed.\n";
        if (is_resource($stderr)) { fwrite($stderr, $msg); } else { echo $msg; }
        exit(1);
    }
}

$decoded = json_decode((string) $body, true);
if (!is_array($decoded)) {
    $msg = "Panel sync cron received invalid JSON. HTTP " . $code . "\n";
    if (is_resource($stderr)) { fwrite($stderr, $msg); } else { echo $msg; }
    exit(1);
}
if (empty($decoded['ok'])) {
    $msg = "Panel sync cron failed: " . (isset($decoded['message']) ? $decoded['message'] : 'Unknown response') . "\n";
    if (is_resource($stderr)) { fwrite($stderr, $msg); } else { echo $msg; }
    exit(1);
}
echo isset($decoded['message']) ? $decoded['message'] . PHP_EOL : "OK\n";
