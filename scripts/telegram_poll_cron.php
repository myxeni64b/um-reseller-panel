<?php
// Telegram polling helper for shared hosting cron.
// Usage:
//   php telegram_poll_cron.php "https://example.com/shop/telegram/poll/SECRET?timeout=55"
// or set environment variable POLL_URL.

function tgcron_stderr()
{
    static $fp = null;
    if ($fp !== null) {
        return $fp;
    }
    if (defined('STDERR')) {
        $fp = STDERR;
        return $fp;
    }
    $fp = @fopen('php://stderr', 'wb');
    if (!$fp) {
        $fp = @fopen('php://output', 'wb');
    }
    return $fp;
}

function tgcron_err($message)
{
    $fp = tgcron_stderr();
    if ($fp) {
        @fwrite($fp, $message);
    }
}

if (PHP_SAPI !== 'cli') {
    tgcron_err("This script must run from CLI.
");
    exit(1);
}

$url = isset($argv[1]) && $argv[1] !== '' ? $argv[1] : getenv('POLL_URL');
if (!$url) {
    tgcron_err("Missing poll URL. Pass it as argv[1] or set POLL_URL.
");
    exit(1);
}

$lockFile = getenv('LOCK_FILE') ? getenv('LOCK_FILE') : sys_get_temp_dir() . '/xui_reseller_tg_poll_php.lock';
$fp = @fopen($lockFile, 'c+');
if (!$fp) {
    tgcron_err("Cannot open lock file: {$lockFile}
");
    exit(1);
}
if (!@flock($fp, LOCK_EX | LOCK_NB)) {
    fclose($fp);
    exit(0);
}

$timeout = (int) (getenv('HTTP_TIMEOUT') ? getenv('HTTP_TIMEOUT') : 65);
$body = null;
$error = '';
$code = 0;

if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, max(15, $timeout));
    curl_setopt($ch, CURLOPT_USERAGENT, 'XUIResellerTelegramCron/1.1');
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
} else {
    $context = stream_context_create(array('http' => array('timeout' => max(15, $timeout), 'user_agent' => 'XUIResellerTelegramCron/1.1')));
    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        $error = 'HTTP request failed';
    }
}

@flock($fp, LOCK_UN);
@fclose($fp);

if ($body === false || $error !== '' || $code >= 400) {
    tgcron_err('Polling failed' . ($code ? ' [' . $code . ']' : '') . ($error !== '' ? ': ' . $error : '') . "
");
    exit(1);
}

echo $body;
exit(0);
