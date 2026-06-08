<?php
function panel_e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function panel_now()
{
    return gmdate('c');
}

function panel_password_hash($password)
{
    return password_hash($password, PASSWORD_DEFAULT);
}

function panel_slug($value, $dash)
{
    $value = strtolower(trim((string) $value));
    $value = preg_replace('/[^a-z0-9]+/', $dash ? '-' : '_', $value);
    $value = trim($value, $dash ? '-' : '_');
    return $value;
}

function panel_random_bytes($bytes)
{
    $bytes = max(1, (int) $bytes);
    if (function_exists('random_bytes')) {
        try {
            $buf = random_bytes($bytes);
            if (is_string($buf) && strlen($buf) === $bytes) {
                return $buf;
            }
        } catch (Exception $e) {
        }
    }
    if (function_exists('openssl_random_pseudo_bytes')) {
        $strong = false;
        $buf = openssl_random_pseudo_bytes($bytes, $strong);
        if (is_string($buf) && strlen($buf) >= $bytes) {
            return substr($buf, 0, $bytes);
        }
    }
    $out = '';
    while (strlen($out) < $bytes) {
        $seed = microtime(true) . '|' . mt_rand() . '|' . uniqid('', true) . '|' . serialize(isset($_SERVER) ? $_SERVER : array());
        $out .= hash('sha256', $seed, true);
    }
    return substr($out, 0, $bytes);
}

function panel_random_hex($length)
{
    $bytes = (int) ceil($length / 2);
    return substr(bin2hex(panel_random_bytes($bytes)), 0, $length);
}

function panel_to_bytes_from_gb($gb)
{
    return (float) $gb * 1024 * 1024 * 1024;
}

function panel_to_gb_from_bytes($bytes)
{
    return round(((float) $bytes) / (1024 * 1024 * 1024), 2);
}

function panel_format_gb($gb)
{
    return number_format((float) $gb, 2, '.', '');
}

function panel_safe_json_decode($json)
{
    if (is_array($json)) {
        return $json;
    }
    if (!is_string($json) || trim($json) === '') {
        return array();
    }
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : array();
}

function panel_parse_bool($value, $default)
{
    if ($value === null || $value === '') {
        return (bool) $default;
    }
    if (is_bool($value)) {
        return $value;
    }
    $value = strtolower(trim((string) $value));
    if (in_array($value, array('1', 'true', 'yes', 'on'), true)) {
        return true;
    }
    if (in_array($value, array('0', 'false', 'no', 'off'), true)) {
        return false;
    }
    return (bool) $default;
}

function panel_parse_multi_json($value)
{
    $out = panel_safe_json_decode($value);
    if ($out) {
        return $out;
    }
    if (!is_string($value) || trim($value) === '') {
        return array();
    }
    $decoded = json_decode(html_entity_decode($value, ENT_QUOTES, 'UTF-8'), true);
    return is_array($decoded) ? $decoded : array();
}

function panel_json_field($row, $key)
{
    if (!is_array($row) || !isset($row[$key])) {
        return array();
    }
    return panel_parse_multi_json($row[$key]);
}

function panel_array_get($array, $path, $default)
{
    if (!is_array($array)) {
        return $default;
    }
    $segments = is_array($path) ? $path : explode('.', (string) $path);
    $cursor = $array;
    foreach ($segments as $segment) {
        if (is_array($cursor) && array_key_exists($segment, $cursor)) {
            $cursor = $cursor[$segment];
        } else {
            return $default;
        }
    }
    return $cursor;
}

function panel_request_origin()
{
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') || (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? trim((string) $_SERVER['HTTP_HOST']) : '';
    if ($host === '') {
        $host = isset($_SERVER['SERVER_NAME']) ? trim((string) $_SERVER['SERVER_NAME']) : 'localhost';
        $port = isset($_SERVER['SERVER_PORT']) ? (string) $_SERVER['SERVER_PORT'] : '';
        if ($port !== '' && $port !== '80' && $port !== '443' && strpos($host, ':') === false) {
            $host .= ':' . $port;
        }
    }
    return $scheme . '://' . $host;
}

function panel_normalize_base_path($path)
{
    $path = trim((string) $path);
    if ($path === '' || $path === '/') {
        return '';
    }
    $path = '/' . trim($path, '/');
    return $path === '/.' ? '' : $path;
}

function panel_base_url($appUrl)
{
    $appUrl = trim((string) $appUrl);
    if ($appUrl === '') {
        return '';
    }
    return rtrim($appUrl, '/');
}

function panel_url_join($base, $path)
{
    $base = rtrim((string) $base, '/');
    $path = '/' . ltrim((string) $path, '/');
    if ($base === '') {
        return $path;
    }
    return $base . $path;
}

function panel_guess_port($security, $fallback)
{
    if ($fallback !== null && $fallback !== '') {
        return (int) $fallback;
    }
    return strtolower((string) $security) === 'tls' || strtolower((string) $security) === 'reality' ? 443 : 80;
}

function panel_qs($params)
{
    $filtered = array();
    foreach ((array) $params as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $filtered[$key] = (string) $value;
    }
    return http_build_query($filtered, '', '&', PHP_QUERY_RFC3986);
}

function panel_is_valid_json_string($json)
{
    if (!is_string($json) || trim($json) === '') {
        return true;
    }
    json_decode($json, true);
    return json_last_error() === JSON_ERROR_NONE;
}
