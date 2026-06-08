<?php
class XuiAdapter
{
    protected $node;
    protected $storageRoot;
    protected $cookieFile;
    protected $timeout;
    protected $connectTimeout;
    protected $retryAttempts;
    protected $baseUrl;
    protected $panelPath;
    protected $apiBase;
    protected $lastError = '';
    protected $verifyPeer = true;
    protected $verifyHost = 2;
    protected $lastResponseHeaders = array();
    protected $lastStatusCode = 0;
    protected $apiToken = '';
    protected $requestHostOverride = '';
    protected $requestSniOverride = '';
    protected $proxyType = 'http';
    protected $proxyHost = '';
    protected $proxyPort = 0;
    protected $proxyUsername = '';
    protected $proxyPassword = '';
    protected $lastCurlErrno = 0;
    protected $transportProfiles = array();
    protected $compatFile;
    protected $compat = array(
        'api_base' => '',
        'server_api_base' => '',
        'client_api_base' => '',
        'actions' => array(),
        'preferred_auth' => '',
        'disabled_actions' => array(),
    );

    public function __construct($node, $storageRoot)
    {
        $this->node = is_array($node) ? $node : array();
        $this->storageRoot = rtrim((string) $storageRoot, '/');
        $this->timeout = isset($this->node['request_timeout']) ? max(5, (int) $this->node['request_timeout']) : 20;
        $this->connectTimeout = isset($this->node['connect_timeout']) ? max(3, (int) $this->node['connect_timeout']) : 8;
        $this->retryAttempts = isset($this->node['retry_attempts']) ? max(1, (int) $this->node['retry_attempts']) : 2;
        $this->apiToken = trim(isset($this->node['xui_api_token_plain']) ? (string) $this->node['xui_api_token_plain'] : (isset($this->node['xui_api_token']) ? (string) $this->node['xui_api_token'] : ''));
        $this->requestHostOverride = trim(isset($this->node['xui_request_host']) ? (string) $this->node['xui_request_host'] : '');
        $this->requestSniOverride = trim(isset($this->node['xui_request_sni']) ? (string) $this->node['xui_request_sni'] : '');
        $this->proxyType = strtolower(trim(isset($this->node['xui_proxy_type']) ? (string) $this->node['xui_proxy_type'] : 'http'));
        if (!in_array($this->proxyType, array('http', 'https', 'socks5'), true)) {
            $this->proxyType = 'http';
        }
        $this->proxyHost = trim(isset($this->node['xui_proxy_host']) ? (string) $this->node['xui_proxy_host'] : '');
        $this->proxyPort = isset($this->node['xui_proxy_port']) ? (int) $this->node['xui_proxy_port'] : 0;
        $this->proxyUsername = trim(isset($this->node['xui_proxy_username']) ? (string) $this->node['xui_proxy_username'] : '');
        $this->proxyPassword = isset($this->node['xui_proxy_password_plain']) ? (string) $this->node['xui_proxy_password_plain'] : (isset($this->node['xui_proxy_password']) ? (string) $this->node['xui_proxy_password'] : '');

        $baseUrl = trim(isset($this->node['base_url']) ? (string) $this->node['base_url'] : '');
        $panelPath = trim(isset($this->node['panel_path']) ? (string) $this->node['panel_path'] : '');
        $normalized = $this->normalizePaths($baseUrl, $panelPath);
        $this->baseUrl = $normalized['base_url'];
        $this->panelPath = $normalized['panel_path'];
        $this->apiBase = $this->buildApiBase('/panel/api/inbounds');
        $this->transportProfiles = $this->buildTransportProfiles();

        $cookieDir = $this->storageRoot . '/cache/cookies';
        if (!is_dir($cookieDir)) {
            @mkdir($cookieDir, 0775, true);
        }
        $nodeId = isset($this->node['id']) ? $this->node['id'] : md5($this->baseUrl . '|' . $this->panelPath);
        $this->cookieFile = $cookieDir . '/' . $nodeId . '.cookie';
        $allowInsecure = isset($this->node['allow_insecure_tls']) ? panel_parse_bool($this->node['allow_insecure_tls'], false) : false;
        $this->verifyPeer = !$allowInsecure;
        $this->verifyHost = $allowInsecure ? 0 : 2;

        $compatDir = $this->storageRoot . '/cache/xui';
        if (!is_dir($compatDir)) {
            @mkdir($compatDir, 0775, true);
        }
        $this->compatFile = $compatDir . '/' . $nodeId . '.compat.json';
        $this->loadCompat();
    }

    public function error()
    {
        return $this->lastError;
    }

    public function ping()
    {
        $login = $this->login(true);
        if (!$login['ok']) {
            return $login;
        }

        $status = $this->probeServerStatus();
        if (!$status['ok']) {
            $status = $this->listInbounds();
        }
        if (!$status['ok']) {
            return $status;
        }

        return array(
            'ok' => true,
            'message' => 'Node connection successful.',
            'data' => array(
                'inbounds' => is_array(isset($status['data']) ? $status['data'] : null) ? $status['data'] : array(),
                'status_code' => $this->lastStatusCode,
                'api_base' => $this->compatApiBase(),
                'auth_mode' => $this->authModeLabel(),
            ),
        );
    }

    public function listInbounds()
    {
        $variants = array();
        foreach ($this->apiBaseCandidates() as $apiBase) {
            $variants[] = $this->makeVariant('GET', $apiBase . '/list', null, false, 'list_get', $apiBase, '/list', 'api_base');
            $variants[] = $this->makeVariant('GET', $apiBase . '/list/', null, false, 'list_get_slash', $apiBase, '/list/', 'api_base');
        }
        return $this->requestCompat('list_inbounds', $variants, 'normalizeInboundListResult');
    }

    public function getInbound($inboundId)
    {
        $variants = array();
        $id = rawurlencode((string) $inboundId);
        foreach ($this->apiBaseCandidates() as $apiBase) {
            $variants[] = $this->makeVariant('GET', $apiBase . '/get/' . $id, null, false, 'get_get', $apiBase, '/get/' . $id, 'api_base');
            $variants[] = $this->makeVariant('GET', $apiBase . '/get/' . $id . '/', null, false, 'get_get_slash', $apiBase, '/get/' . $id . '/', 'api_base');
        }
        return $this->requestCompat('get_inbound', $variants, 'normalizeSingleInboundResult');
    }

    public function addClient($inboundId, $settings)
    {
        $variants = array();
        foreach ($this->clientApiBaseCandidates() as $apiBase) {
            $clientBody = array(
                'client' => $this->buildClientApiPayload($settings),
                'inboundIds' => array((int) $inboundId),
            );
            $variants[] = $this->makeVariant('POST', $apiBase . '/add', $clientBody, false, 'clients_add_json', $apiBase, '/add', 'client_api_base');
            $variants[] = $this->makeVariant('POST', $apiBase . '/add/', $clientBody, false, 'clients_add_json_slash', $apiBase, '/add/', 'client_api_base');
        }
        foreach ($this->apiBaseCandidates() as $apiBase) {
            foreach ($this->clientPayloadVariants($inboundId, $settings, null) as $payload) {
                $variants[] = $this->makeVariant('POST', $apiBase . '/addClient', $payload['body'], $payload['allowForm'], $payload['mode'], $apiBase, '/addClient', 'api_base');
                $variants[] = $this->makeVariant('POST', $apiBase . '/addClient/', $payload['body'], $payload['allowForm'], $payload['mode'] . '_slash', $apiBase, '/addClient/', 'api_base');
            }
        }
        return $this->requestCompat('add_client', $variants, 'normalizeGenericResult');
    }

    public function updateClient($clientId, $inboundId, $settings, $currentEmail)
    {
        $variants = array();
        $currentEmail = trim((string) $currentEmail);
        $newEmail = trim((string) panel_array_get($settings, 'email', ''));
        if ($currentEmail === '') {
            $currentEmail = $newEmail;
        }
        if ($newEmail !== '') {
            foreach ($this->clientApiBaseCandidates() as $apiBase) {
                $pathEmail = rawurlencode($currentEmail !== '' ? $currentEmail : $newEmail);
                $clientBody = $this->buildClientApiPayload($settings);
                $variants[] = $this->makeVariant('POST', $apiBase . '/update/' . $pathEmail, $clientBody, false, 'clients_update_json', $apiBase, '/update/' . $pathEmail, 'client_api_base');
                $variants[] = $this->makeVariant('POST', $apiBase . '/update/' . $pathEmail . '/', $clientBody, false, 'clients_update_json_slash', $apiBase, '/update/' . $pathEmail . '/', 'client_api_base');
            }
        }

        $encodedClientId = rawurlencode((string) $clientId);
        foreach ($this->apiBaseCandidates() as $apiBase) {
            foreach ($this->clientPayloadVariants($inboundId, $settings, $clientId) as $payload) {
                $variants[] = $this->makeVariant('POST', $apiBase . '/updateClient/' . $encodedClientId, $payload['body'], $payload['allowForm'], 'path_' . $payload['mode'], $apiBase, '/updateClient/' . $encodedClientId, 'api_base');
                $variants[] = $this->makeVariant('POST', $apiBase . '/updateClient/' . $encodedClientId . '/', $payload['body'], $payload['allowForm'], 'path_' . $payload['mode'] . '_slash', $apiBase, '/updateClient/' . $encodedClientId . '/', 'api_base');
                $bodyWithClient = $payload['body'];
                if (is_array($bodyWithClient)) {
                    if (!isset($bodyWithClient['clientId'])) {
                        $bodyWithClient['clientId'] = (string) $clientId;
                    }
                    $variants[] = $this->makeVariant('POST', $apiBase . '/updateClient', $bodyWithClient, $payload['allowForm'], 'body_' . $payload['mode'], $apiBase, '/updateClient', 'api_base');
                }
            }
        }
        return $this->requestCompat('update_client', $variants, 'normalizeGenericResult');
    }

    public function deleteClient($inboundId, $clientId, $email)
    {
        $variants = array();
        $inboundEncoded = rawurlencode((string) $inboundId);
        $clientEncoded = rawurlencode((string) $clientId);
        $emailEncoded = rawurlencode((string) $email);
        if ((string) $email !== '') {
            foreach ($this->clientApiBaseCandidates() as $apiBase) {
                $variants[] = $this->makeVariant('POST', $apiBase . '/del/' . $emailEncoded, null, false, 'clients_del_path', $apiBase, '/del/' . $emailEncoded, 'client_api_base');
                $variants[] = $this->makeVariant('POST', $apiBase . '/del/' . $emailEncoded . '/', null, false, 'clients_del_path_slash', $apiBase, '/del/' . $emailEncoded . '/', 'client_api_base');
            }
        }
        foreach ($this->apiBaseCandidates() as $apiBase) {
            if ((string) $clientId !== '') {
                $variants[] = $this->makeVariant('POST', $apiBase . '/' . $inboundEncoded . '/delClient/' . $clientEncoded, null, true, 'path_client', $apiBase, '/' . $inboundEncoded . '/delClient/' . $clientEncoded, 'api_base');
                $variants[] = $this->makeVariant('POST', $apiBase . '/delClient/' . $clientEncoded, array('id' => (int) $inboundId), true, 'legacy_client', $apiBase, '/delClient/' . $clientEncoded, 'api_base');
                $variants[] = $this->makeVariant('POST', $apiBase . '/delClient', array('id' => (int) $inboundId, 'clientId' => (string) $clientId), true, 'body_client', $apiBase, '/delClient', 'api_base');
            }
            if ((string) $email !== '') {
                $variants[] = $this->makeVariant('POST', $apiBase . '/' . $inboundEncoded . '/delClientByEmail/' . $emailEncoded, null, true, 'path_email', $apiBase, '/' . $inboundEncoded . '/delClientByEmail/' . $emailEncoded, 'api_base');
                $variants[] = $this->makeVariant('POST', $apiBase . '/delClientByEmail/' . $emailEncoded, array('id' => (int) $inboundId), true, 'legacy_email', $apiBase, '/delClientByEmail/' . $emailEncoded, 'api_base');
                $variants[] = $this->makeVariant('POST', $apiBase . '/delClient', array('id' => (int) $inboundId, 'email' => (string) $email), true, 'body_email', $apiBase, '/delClient', 'api_base');
            }
        }
        return $this->requestCompat('delete_client', $variants, 'normalizeGenericResult');
    }

    public function getClientTraffic($email)
    {
        $variants = array();
        $encoded = rawurlencode((string) $email);
        foreach ($this->clientApiBaseCandidates() as $apiBase) {
            $variants[] = $this->makeVariant('GET', $apiBase . '/traffic/' . $encoded, null, false, 'clients_traffic_get', $apiBase, '/traffic/' . $encoded, 'client_api_base');
            $variants[] = $this->makeVariant('GET', $apiBase . '/get/' . $encoded, null, false, 'clients_get_email', $apiBase, '/get/' . $encoded, 'client_api_base');
        }
        foreach ($this->apiBaseCandidates() as $apiBase) {
            $variants[] = $this->makeVariant('GET', $apiBase . '/getClientTraffics/' . $encoded, null, false, 'traffic_get', $apiBase, '/getClientTraffics/' . $encoded, 'api_base');
            $variants[] = $this->makeVariant('POST', $apiBase . '/getClientTraffics/' . $encoded, array(), true, 'traffic_post', $apiBase, '/getClientTraffics/' . $encoded, 'api_base');
        }
        return $this->requestCompat('get_client_traffic', $variants, 'normalizeTrafficResult');
    }

    public function updateClientTraffic($email, $totalBytes, $expiryMillis)
    {
        $email = trim((string) $email);
        if ($email !== '') {
            $client = $this->getClientByEmail($email);
            if (!empty($client['ok']) && !empty($client['data']) && is_array($client['data'])) {
                $body = $this->buildClientApiPayload($client['data']);
                $body['totalGB'] = (float) $totalBytes;
                $body['expiryTime'] = (int) $expiryMillis;
                $variants = array();
                $encoded = rawurlencode($email);
                foreach ($this->clientApiBaseCandidates() as $apiBase) {
                    $variants[] = $this->makeVariant('POST', $apiBase . '/update/' . $encoded, $body, false, 'clients_update_traffic', $apiBase, '/update/' . $encoded, 'client_api_base');
                }
                $result = $this->requestCompat('update_client_traffic_clients', $variants, 'normalizeGenericResult');
                if (!empty($result['ok'])) {
                    return $result;
                }
            }
        }

        $variants = array();
        $encoded = rawurlencode((string) $email);
        foreach ($this->apiBaseCandidates() as $apiBase) {
            $variants[] = $this->makeVariant('POST', $apiBase . '/updateClientTraffic/' . $encoded, array(
                'total' => (float) $totalBytes,
                'expiryTime' => (int) $expiryMillis,
            ), true, 'legacy_form', $apiBase, '/updateClientTraffic/' . $encoded, 'api_base');
            $variants[] = $this->makeVariant('POST', $apiBase . '/updateClientTraffic/' . $encoded, array(
                'total' => (float) $totalBytes,
                'expiryTime' => (int) $expiryMillis,
                'email' => (string) $email,
            ), false, 'json_email', $apiBase, '/updateClientTraffic/' . $encoded, 'api_base');
        }
        return $this->requestCompat('update_client_traffic', $variants, 'normalizeGenericResult');
    }

    public function getOnlines()
    {
        $variants = array();
        foreach ($this->clientApiBaseCandidates() as $apiBase) {
            $variants[] = $this->makeVariant('POST', $apiBase . '/onlines', array(), false, 'clients_post_json', $apiBase, '/onlines', 'client_api_base');
            $variants[] = $this->makeVariant('POST', $apiBase . '/onlines', array(), true, 'clients_post_form', $apiBase, '/onlines', 'client_api_base');
        }
        foreach ($this->apiBaseCandidates() as $apiBase) {
            $variants[] = $this->makeVariant('POST', $apiBase . '/onlines', array(), true, 'post_form', $apiBase, '/onlines', 'api_base');
            $variants[] = $this->makeVariant('POST', $apiBase . '/onlines', array(), false, 'post_json', $apiBase, '/onlines', 'api_base');
            $variants[] = $this->makeVariant('GET', $apiBase . '/onlines', null, false, 'get', $apiBase, '/onlines', 'api_base');
        }
        return $this->requestCompat('get_onlines', $variants, 'normalizeOnlinesResult');
    }

    public function lastOnline($emails)
    {
        $list = array_values((array) $emails);
        $variants = array();
        foreach ($this->clientApiBaseCandidates() as $apiBase) {
            $variants[] = $this->makeVariant('POST', $apiBase . '/lastOnline', array('emails' => $list), false, 'clients_json_emails', $apiBase, '/lastOnline', 'client_api_base');
            $variants[] = $this->makeVariant('POST', $apiBase . '/lastOnline', array('email' => $list), true, 'clients_form_email', $apiBase, '/lastOnline', 'client_api_base');
            $variants[] = $this->makeVariant('POST', $apiBase . '/lastOnline', $list, false, 'clients_json_array', $apiBase, '/lastOnline', 'client_api_base');
        }
        foreach ($this->apiBaseCandidates() as $apiBase) {
            $variants[] = $this->makeVariant('POST', $apiBase . '/lastOnline', array('email' => $list), true, 'form_email', $apiBase, '/lastOnline', 'api_base');
            $variants[] = $this->makeVariant('POST', $apiBase . '/lastOnline', array('emails' => $list), false, 'json_emails', $apiBase, '/lastOnline', 'api_base');
            $variants[] = $this->makeVariant('POST', $apiBase . '/lastOnline', $list, false, 'json_array', $apiBase, '/lastOnline', 'api_base');
        }
        return $this->requestCompat('last_online', $variants, 'normalizeLastOnlineResult');
    }

    public function getClientLinks($email, $subId)
    {
        $email = trim((string) $email);
        $subId = trim((string) $subId);
        if ($email === '' && $subId === '') {
            return array('ok' => false, 'message' => 'Client email or subscription ID is required.');
        }
        $variants = array();
        foreach ($this->clientApiBaseCandidates() as $apiBase) {
            if ($email !== '') {
                $encodedEmail = rawurlencode($email);
                $variants[] = $this->makeVariant('GET', $apiBase . '/links/' . $encodedEmail, null, false, 'clients_links_email', $apiBase, '/links/' . $encodedEmail, 'client_api_base');
                $variants[] = $this->makeVariant('GET', $apiBase . '/links/' . $encodedEmail . '/', null, false, 'clients_links_email_slash', $apiBase, '/links/' . $encodedEmail . '/', 'client_api_base');
            }
            if ($subId !== '') {
                $encodedSub = rawurlencode($subId);
                $variants[] = $this->makeVariant('GET', $apiBase . '/subLinks/' . $encodedSub, null, false, 'clients_sublinks_subid', $apiBase, '/subLinks/' . $encodedSub, 'client_api_base');
                $variants[] = $this->makeVariant('GET', $apiBase . '/subLinks/' . $encodedSub . '/', null, false, 'clients_sublinks_subid_slash', $apiBase, '/subLinks/' . $encodedSub . '/', 'client_api_base');
            }
        }
        return $this->requestCompat('get_client_links', $variants, 'normalizeLinksResult');
    }

    public function ensureClientState($inboundId, $clientId, $settings, $email)
    {
        $result = $this->addClient($inboundId, $settings);
        if (!$result['ok']) {
            $check = $this->getClientTraffic($email);
            if ($check['ok']) {
                return array('ok' => true, 'message' => 'Client already exists remotely; traffic lookup succeeded.', 'data' => isset($check['data']) ? $check['data'] : array(), 'raw' => isset($check['raw']) ? $check['raw'] : array());
            }
            return $result;
        }
        return $result;
    }

    protected function hasApiToken()
    {
        return $this->apiToken !== '';
    }

    protected function authModeLabel()
    {
        $preferred = $this->preferredAuthMode();
        if ($preferred !== '') {
            return $preferred;
        }
        return $this->hasApiToken() ? 'bearer_token' : 'session_cookie';
    }

    protected function hasNodeCredentials()
    {
        $username = isset($this->node['panel_username']) ? trim((string) $this->node['panel_username']) : '';
        $password = isset($this->node['panel_password_plain']) ? (string) $this->node['panel_password_plain'] : '';
        return $username !== '' && $password !== '';
    }

    protected function preferredAuthMode()
    {
        $mode = trim((string) panel_array_get($this->compat, 'preferred_auth', ''));
        if (!in_array($mode, array('bearer_token', 'session_cookie'), true)) {
            return '';
        }
        if ($mode === 'bearer_token' && !$this->hasApiToken()) {
            return '';
        }
        if ($mode === 'session_cookie' && !$this->hasNodeCredentials()) {
            return '';
        }
        return $mode;
    }

    protected function rememberPreferredAuthMode($mode)
    {
        $mode = trim((string) $mode);
        if (!in_array($mode, array('bearer_token', 'session_cookie', ''), true)) {
            return;
        }
        $this->compat['preferred_auth'] = $mode;
        $this->saveCompat();
    }

    protected function shouldUseBearerForRequest($url, $forceCookieAuth)
    {
        if ($forceCookieAuth || !$this->hasApiToken() || !$this->urlUsesApiAuth($url)) {
            return false;
        }
        $preferred = $this->preferredAuthMode();
        if ($preferred === 'session_cookie') {
            return false;
        }
        return true;
    }

    protected function urlUsesApiAuth($url)
    {
        $url = (string) $url;
        return strpos($url, '/panel/api/') !== false || preg_match('~/panel/api$~', $url);
    }

    protected function login($force)
    {
        if ($this->hasApiToken() && $this->preferredAuthMode() !== 'session_cookie') {
            return array('ok' => true, 'message' => 'Bearer token auth configured for this node.', 'auth_mode' => 'bearer_token');
        }
        return $this->loginSession($force);
    }

    protected function loginSession($force)
    {
        $username = isset($this->node['panel_username']) ? trim((string) $this->node['panel_username']) : '';
        $password = isset($this->node['panel_password_plain']) ? (string) $this->node['panel_password_plain'] : '';
        if ($username === '' || $password === '') {
            return array('ok' => false, 'message' => $this->hasApiToken() ? 'Node credentials are missing for session-auth fallback.' : 'Node credentials are missing and no XUI API token is configured.');
        }

        $metaFile = $this->cookieFile . '.meta';
        if (!$force && is_file($this->cookieFile) && is_file($metaFile)) {
            $meta = panel_safe_json_decode(@file_get_contents($metaFile));
            if (!empty($meta['logged_at']) && (time() - (int) $meta['logged_at'] < 900)) {
                $this->rememberPreferredAuthMode('session_cookie');
                return array('ok' => true, 'message' => 'Existing node session reused.', 'auth_mode' => 'session_cookie');
            }
        }

        $urls = $this->loginUrls();
        $payloads = array(
            array('body' => array('username' => $username, 'password' => $password), 'allowForm' => true, 'mode' => 'form'),
            array('body' => array('username' => $username, 'password' => $password), 'allowForm' => false, 'mode' => 'json'),
        );
        $last = array('ok' => false, 'message' => 'Node login failed.');
        foreach ($urls as $url) {
            foreach ($payloads as $payload) {
                $last = $this->raw('POST', $url, $payload['body'], true, $payload['allowForm'], true, true);
                if ($last['ok']) {
                    @file_put_contents($metaFile, json_encode(array('logged_at' => time(), 'url' => $url, 'mode' => $payload['mode']), JSON_UNESCAPED_SLASHES));
                    $this->rememberPreferredAuthMode('session_cookie');
                    return $last;
                }
            }
        }
        if (!empty($urls)) {
            $last['message'] .= ' Tried: ' . implode(', ', $urls);
        }
        return $last;
    }

    protected function request($method, $path, $body, $decodeJson, $allowForm)
    {
        $login = $this->login(false);
        if (!$login['ok']) {
            $login = $this->login(true);
            if (!$login['ok']) {
                return $login;
            }
        }

        $result = $this->raw($method, $this->apiBase . $path, $body, $decodeJson, $allowForm, true);
        if (!empty($result['ok'])) {
            if ($this->shouldUseBearerForRequest($this->apiBase . $path, false)) {
                $this->rememberPreferredAuthMode('bearer_token');
            }
            return $result;
        }
        if ($this->lastStatusCode === 401 || $this->lastStatusCode === 403) {
            $retry = $this->retryWithSessionAuth($method, $this->apiBase . $path, $body, $decodeJson, $allowForm, true);
            if (!empty($retry['attempted'])) {
                return $retry['result'];
            }
            $login = $this->login(true);
            if ($login['ok']) {
                $result = $this->raw($method, $this->apiBase . $path, $body, $decodeJson, $allowForm, true);
            }
        }
        return $result;
    }

    protected function requestCompat($action, $variants, $normalizer)
    {
        if ($this->isActionTemporarilyDisabled($action)) {
            return array('ok' => false, 'message' => 'Compatibility route temporarily disabled for this node/action.');
        }

        $variants = $this->orderVariantsForAction($action, $variants);
        if (!$variants) {
            return array('ok' => false, 'message' => 'No compatibility variants are available for this request.');
        }

        $login = $this->login(false);
        if (!$login['ok']) {
            $login = $this->login(true);
            if (!$login['ok']) {
                return $login;
            }
        }

        $last = array('ok' => false, 'message' => 'Request failed on all compatibility routes.');
        $allMissingRoutes = true;
        $attemptedVariants = 0;
        foreach ($variants as $variant) {
            $attemptedVariants++;
            $result = $this->raw($variant['method'], $variant['url'], $variant['body'], true, $variant['allowForm'], true);
            if (empty($result['ok']) && ($this->lastStatusCode === 401 || $this->lastStatusCode === 403)) {
                $retry = $this->retryWithSessionAuth($variant['method'], $variant['url'], $variant['body'], true, $variant['allowForm'], true);
                if (!empty($retry['attempted'])) {
                    $result = $retry['result'];
                } else {
                    $login = $this->login(true);
                    if ($login['ok']) {
                        $result = $this->raw($variant['method'], $variant['url'], $variant['body'], true, $variant['allowForm'], true);
                    }
                }
            }
            if (!empty($result['ok']) && $this->shouldUseBearerForRequest($variant['url'], false)) {
                $this->rememberPreferredAuthMode('bearer_token');
            }
            $normalized = is_string($normalizer) && method_exists($this, $normalizer)
                ? call_user_func(array($this, $normalizer), $result, $variant)
                : $result;
            $last = $normalized;
            if (!empty($normalized['ok'])) {
                $this->clearDisabledAction($action);
                $this->rememberVariantSuccess($action, $variant);
                return $normalized;
            }
            if (!$this->isMissingRouteFailure($result, $normalized)) {
                $allMissingRoutes = false;
            }
        }

        if ($attemptedVariants > 0 && $allMissingRoutes && $this->shouldTemporarilyDisableAction($action)) {
            $this->disableActionTemporarily($action, 21600, 'All compatibility routes returned missing/not-found responses.');
        }
        return $last;
    }

    protected function probeServerStatus()
    {
        $variants = array();
        foreach ($this->serverApiBaseCandidates() as $apiBase) {
            $variants[] = $this->makeVariant('GET', $apiBase . '/status', null, false, 'status_get', $apiBase, '/status', 'server_api_base');
        }
        return $this->requestCompat('server_status', $variants, 'normalizeServerStatusResult');
    }

    protected function clientPayloadVariants($inboundId, $settings, $clientId)
    {
        $clientEnvelope = array('clients' => array($settings));
        $variants = array();

        $legacy = array(
            'id' => (int) $inboundId,
            'settings' => json_encode($clientEnvelope, JSON_UNESCAPED_SLASHES),
        );
        if ($clientId !== null) {
            $legacy['clientId'] = (string) $clientId;
        }
        $variants[] = array('mode' => 'legacy_form', 'allowForm' => true, 'body' => $legacy);

        $legacyStringId = $legacy;
        $legacyStringId['id'] = (string) $inboundId;
        $variants[] = array('mode' => 'legacy_form_string_id', 'allowForm' => true, 'body' => $legacyStringId);

        $jsonNested = array(
            'id' => (int) $inboundId,
            'settings' => $clientEnvelope,
        );
        if ($clientId !== null) {
            $jsonNested['clientId'] = (string) $clientId;
        }
        $variants[] = array('mode' => 'json_nested', 'allowForm' => false, 'body' => $jsonNested);

        $jsonClient = array(
            'id' => (int) $inboundId,
            'client' => $settings,
        );
        if ($clientId !== null) {
            $jsonClient['clientId'] = (string) $clientId;
        }
        $variants[] = array('mode' => 'json_client', 'allowForm' => false, 'body' => $jsonClient);

        $jsonInbound = array(
            'inboundId' => (int) $inboundId,
            'settings' => $clientEnvelope,
        );
        if ($clientId !== null) {
            $jsonInbound['clientId'] = (string) $clientId;
        }
        $variants[] = array('mode' => 'json_inbound_id', 'allowForm' => false, 'body' => $jsonInbound);

        return $variants;
    }

    protected function normalizeServerStatusResult($result, $variant)
    {
        if (empty($result['ok'])) {
            return $result;
        }
        $data = is_array(isset($result['data']) ? $result['data'] : null) ? $result['data'] : array();
        $normalized = $data;
        if (!isset($normalized['inbounds']) || !is_array($normalized['inbounds'])) {
            $rows = $this->extractInboundRows($data);
            if ($rows !== null) {
                $normalized['inbounds'] = $rows;
            }
        }
        $result['data'] = $normalized;
        return $result;
    }

    protected function normalizeInboundListResult($result, $variant)
    {
        if (empty($result['ok'])) {
            return $result;
        }
        $rows = $this->extractInboundRows(isset($result['data']) ? $result['data'] : array());
        if ($rows === null) {
            return array('ok' => false, 'message' => 'Inbound list route returned an unsupported response shape.', 'raw' => isset($result['raw']) ? $result['raw'] : array(), 'data' => array());
        }
        $result['data'] = $rows;
        return $result;
    }

    protected function normalizeSingleInboundResult($result, $variant)
    {
        if (empty($result['ok'])) {
            return $result;
        }
        $row = $this->extractInboundRow(isset($result['data']) ? $result['data'] : array());
        if ($row === null) {
            return array('ok' => false, 'message' => 'Inbound route returned an unsupported response shape.', 'raw' => isset($result['raw']) ? $result['raw'] : array(), 'data' => array());
        }
        $result['data'] = $row;
        return $result;
    }

    protected function normalizeTrafficResult($result, $variant)
    {
        if (empty($result['ok'])) {
            return $result;
        }
        $row = $this->extractTrafficRow(isset($result['data']) ? $result['data'] : array());
        if ($row === null) {
            return array('ok' => false, 'message' => 'Traffic route returned an unsupported response shape.', 'raw' => isset($result['raw']) ? $result['raw'] : array(), 'data' => array());
        }
        if (isset($row['traffic']) && is_array($row['traffic'])) {
            foreach (array('up', 'down', 'total', 'expiryTime', 'enable') as $key) {
                if (!isset($row[$key]) && isset($row['traffic'][$key])) {
                    $row[$key] = $row['traffic'][$key];
                }
            }
        }
        if (!isset($row['up']) && isset($row['upload'])) {
            $row['up'] = $row['upload'];
        }
        if (!isset($row['down']) && isset($row['download'])) {
            $row['down'] = $row['download'];
        }
        if (!isset($row['up']) && isset($row['uploaded'])) {
            $row['up'] = $row['uploaded'];
        }
        if (!isset($row['down']) && isset($row['downloaded'])) {
            $row['down'] = $row['downloaded'];
        }
        if (!isset($row['up']) && isset($row['tx'])) {
            $row['up'] = $row['tx'];
        }
        if (!isset($row['down']) && isset($row['rx'])) {
            $row['down'] = $row['rx'];
        }
        if (!isset($row['up']) && isset($row['tx-bytes'])) {
            $row['up'] = $row['tx-bytes'];
        }
        if (!isset($row['down']) && isset($row['rx-bytes'])) {
            $row['down'] = $row['rx-bytes'];
        }
        if (!isset($row['up']) && isset($row['total-upload'])) {
            $row['up'] = $row['total-upload'];
        }
        if (!isset($row['down']) && isset($row['total-download'])) {
            $row['down'] = $row['total-download'];
        }
        if (!isset($row['lastOnline']) && isset($row['last_online'])) {
            $row['lastOnline'] = $row['last_online'];
        }
        $result['data'] = $row;
        return $result;
    }

    protected function normalizeOnlinesResult($result, $variant)
    {
        if (empty($result['ok'])) {
            return $result;
        }
        $list = $this->extractStringList(isset($result['data']) ? $result['data'] : array());
        if ($list === null) {
            return array('ok' => false, 'message' => 'Onlines route returned an unsupported response shape.', 'raw' => isset($result['raw']) ? $result['raw'] : array(), 'data' => array());
        }
        $result['data'] = $list;
        return $result;
    }

    protected function normalizeLastOnlineResult($result, $variant)
    {
        if (empty($result['ok'])) {
            return $result;
        }
        $map = $this->extractLastOnlineMap(isset($result['data']) ? $result['data'] : array());
        if ($map === null) {
            return array('ok' => false, 'message' => 'Last-online route returned an unsupported response shape.', 'raw' => isset($result['raw']) ? $result['raw'] : array(), 'data' => array());
        }
        $result['data'] = $map;
        return $result;
    }

    protected function normalizeLinksResult($result, $variant)
    {
        if (empty($result['ok'])) {
            return $result;
        }
        $list = $this->extractStringList(isset($result['data']) ? $result['data'] : array());
        if ($list === null) {
            return array('ok' => false, 'message' => 'Client links route returned an unsupported response shape.', 'raw' => isset($result['raw']) ? $result['raw'] : array(), 'data' => array());
        }
        $out = array();
        foreach ((array) $list as $item) {
            $item = trim((string) $item);
            if ($item === '' || strpos($item, '://') === false) {
                continue;
            }
            $out[] = $item;
        }
        $result['data'] = array_values(array_unique($out));
        return $result;
    }

    protected function normalizeGenericResult($result, $variant)
    {
        if (empty($result['ok'])) {
            return $result;
        }
        return $result;
    }

    protected function normalizeClientGetResult($result, $variant)
    {
        if (empty($result['ok'])) {
            return $result;
        }
        $row = $this->extractClientRow(isset($result['data']) ? $result['data'] : array());
        if ($row === null) {
            return array('ok' => false, 'message' => 'Client route returned an unsupported response shape.', 'raw' => isset($result['raw']) ? $result['raw'] : array(), 'data' => array());
        }
        $result['data'] = $row;
        return $result;
    }

    protected function extractClientRow($data)
    {
        if (is_array($data) && isset($data['email'])) {
            return $data;
        }
        if (is_array($data) && $this->isList($data) && isset($data[0]) && is_array($data[0]) && isset($data[0]['email'])) {
            return $data[0];
        }
        $queue = array($data);
        $seen = 0;
        while (!empty($queue) && $seen < 20) {
            $seen++;
            $current = array_shift($queue);
            if (is_array($current) && isset($current['email'])) {
                return $current;
            }
            if (is_array($current) && $this->isList($current) && isset($current[0]) && is_array($current[0]) && isset($current[0]['email'])) {
                return $current[0];
            }
            if (is_array($current)) {
                foreach (array('data', 'obj', 'result', 'rows', 'items', 'list', 'client') as $key) {
                    if (isset($current[$key])) {
                        $queue[] = $current[$key];
                    }
                }
            }
        }
        return null;
    }

    protected function buildClientApiPayload($settings)
    {
        $payload = array();
        foreach (array('email', 'enable', 'limitIp', 'expiryTime', 'totalGB', 'subId', 'tgId', 'reset', 'id', 'password', 'method', 'flow', 'comment', 'groupName', 'group_name', 'auth', 'security', 'reverse') as $key) {
            if (array_key_exists($key, (array) $settings)) {
                $payload[$key] = $settings[$key];
            }
        }
        if (isset($payload['group_name']) && !isset($payload['groupName'])) {
            $payload['groupName'] = $payload['group_name'];
        }
        unset($payload['group_name']);
        if (isset($payload['limitIp'])) {
            $payload['limitIp'] = (int) $payload['limitIp'];
        }
        if (isset($payload['expiryTime'])) {
            $payload['expiryTime'] = (int) $payload['expiryTime'];
        }
        if (isset($payload['totalGB'])) {
            $payload['totalGB'] = (float) $payload['totalGB'];
        }
        if (isset($payload['tgId']) && $payload['tgId'] === '') {
            $payload['tgId'] = 0;
        }
        if (!isset($payload['tgId'])) {
            $payload['tgId'] = 0;
        }
        if (!isset($payload['reset'])) {
            $payload['reset'] = 0;
        }
        return $payload;
    }

    protected function getClientByEmail($email)
    {
        $email = trim((string) $email);
        if ($email === '') {
            return array('ok' => false, 'message' => 'Client email is required.');
        }
        $variants = array();
        $encoded = rawurlencode($email);
        foreach ($this->clientApiBaseCandidates() as $apiBase) {
            $variants[] = $this->makeVariant('GET', $apiBase . '/get/' . $encoded, null, false, 'clients_get', $apiBase, '/get/' . $encoded, 'client_api_base');
            $variants[] = $this->makeVariant('GET', $apiBase . '/get/' . $encoded . '/', null, false, 'clients_get_slash', $apiBase, '/get/' . $encoded . '/', 'client_api_base');
        }
        return $this->requestCompat('get_client_by_email', $variants, 'normalizeClientGetResult');
    }

    protected function extractInboundRows($data)
    {
        if (is_array($data) && $this->isInboundRowList($data)) {
            return $this->normalizeInboundRows($data);
        }
        $queue = array($data);
        $seen = 0;
        while (!empty($queue) && $seen < 20) {
            $seen++;
            $current = array_shift($queue);
            if (is_array($current) && $this->isInboundRowList($current)) {
                return $this->normalizeInboundRows($current);
            }
            if (is_array($current)) {
                foreach (array('list', 'items', 'rows', 'data', 'obj', 'result', 'inbounds') as $key) {
                    if (isset($current[$key])) {
                        $queue[] = $current[$key];
                    }
                }
            }
        }
        return null;
    }

    protected function extractInboundRow($data)
    {
        if (is_array($data) && isset($data['id'])) {
            return $this->normalizeInboundRow($data);
        }
        $rows = $this->extractInboundRows($data);
        if (is_array($rows) && isset($rows[0])) {
            return $rows[0];
        }
        return null;
    }

    protected function normalizeInboundRows($rows)
    {
        $out = array();
        foreach ((array) $rows as $row) {
            if (is_array($row)) {
                $out[] = $this->normalizeInboundRow($row);
            }
        }
        return $out;
    }

    protected function normalizeInboundRow($row)
    {
        if (isset($row['stream_settings']) && !isset($row['streamSettings'])) {
            $row['streamSettings'] = $row['stream_settings'];
        }
        if (isset($row['sniffing_settings']) && !isset($row['sniffing'])) {
            $row['sniffing'] = $row['sniffing_settings'];
        }
        if (isset($row['name']) && !isset($row['remark'])) {
            $row['remark'] = $row['name'];
        }
        if (isset($row['inboundId']) && !isset($row['id'])) {
            $row['id'] = $row['inboundId'];
        }
        if (isset($row['settings']) && is_array($row['settings'])) {
            $row['settings'] = json_encode($row['settings'], JSON_UNESCAPED_SLASHES);
        }
        if (isset($row['streamSettings']) && is_array($row['streamSettings'])) {
            $row['streamSettings'] = json_encode($row['streamSettings'], JSON_UNESCAPED_SLASHES);
        }
        if (isset($row['sniffing']) && is_array($row['sniffing'])) {
            $row['sniffing'] = json_encode($row['sniffing'], JSON_UNESCAPED_SLASHES);
        }
        return $row;
    }

    protected function extractTrafficRow($data)
    {
        if (is_array($data) && (isset($data['up']) || isset($data['down']) || isset($data['email']) || isset($data['download']) || isset($data['upload']) || isset($data['lastOnline']))) {
            return $data;
        }
        if (is_array($data) && $this->isList($data) && isset($data[0]) && is_array($data[0])) {
            return $data[0];
        }
        $queue = array($data);
        $seen = 0;
        while (!empty($queue) && $seen < 20) {
            $seen++;
            $current = array_shift($queue);
            if (is_array($current) && (isset($current['up']) || isset($current['down']) || isset($current['email']) || isset($current['download']) || isset($current['upload']) || isset($current['lastOnline']))) {
                return $current;
            }
            if (is_array($current) && $this->isList($current) && isset($current[0]) && is_array($current[0])) {
                return $current[0];
            }
            if (is_array($current)) {
                foreach (array('data', 'obj', 'result', 'rows', 'items', 'list') as $key) {
                    if (isset($current[$key])) {
                        $queue[] = $current[$key];
                    }
                }
            }
        }
        return null;
    }

    protected function extractStringList($data)
    {
        if (is_array($data) && $this->isList($data) && (!isset($data[0]) || !is_array($data[0]))) {
            return array_values($data);
        }
        if (is_array($data)) {
            foreach (array('list', 'items', 'data', 'obj', 'result', 'emails', 'onlines') as $key) {
                if (isset($data[$key]) && is_array($data[$key]) && $this->isList($data[$key]) && (!isset($data[$key][0]) || !is_array($data[$key][0]))) {
                    return array_values($data[$key]);
                }
            }
        }
        return null;
    }

    protected function extractLastOnlineMap($data)
    {
        if (is_array($data) && !$this->isList($data)) {
            $hasScalarValues = false;
            foreach ($data as $key => $value) {
                if (!is_array($value)) {
                    $hasScalarValues = true;
                    break;
                }
            }
            if ($hasScalarValues) {
                return $data;
            }
        }
        if (is_array($data) && $this->isList($data)) {
            $map = array();
            foreach ($data as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $email = isset($row['email']) ? (string) $row['email'] : (isset($row['name']) ? (string) $row['name'] : '');
                if ($email === '') {
                    continue;
                }
                $map[$email] = isset($row['lastOnline']) ? $row['lastOnline'] : (isset($row['last_online']) ? $row['last_online'] : '');
            }
            if (!empty($map)) {
                return $map;
            }
        }
        if (is_array($data)) {
            foreach (array('data', 'obj', 'result', 'rows', 'items', 'list') as $key) {
                if (isset($data[$key])) {
                    $map = $this->extractLastOnlineMap($data[$key]);
                    if ($map !== null) {
                        return $map;
                    }
                }
            }
        }
        return null;
    }

    protected function isInboundRowList($rows)
    {
        if (!$this->isList($rows) || empty($rows)) {
            return false;
        }
        $first = reset($rows);
        return is_array($first) && (isset($first['id']) || isset($first['remark']) || isset($first['protocol']) || isset($first['port']) || isset($first['settings']) || isset($first['inboundId']));
    }

    protected function isList($value)
    {
        if (!is_array($value)) {
            return false;
        }
        return array_keys($value) === range(0, count($value) - 1);
    }

    protected function apiBaseCandidates()
    {
        $bases = $this->compatApiCandidates((string) panel_array_get($this->compat, 'api_base', ''), array('/panel/api/inbounds', '/api/inbounds'));
        $bases[] = rtrim($this->apiBase, '/');
        return array_values(array_unique(array_filter($bases)));
    }

    protected function clientApiBaseCandidates()
    {
        return $this->compatApiCandidates((string) panel_array_get($this->compat, 'client_api_base', ''), array('/panel/api/clients', '/api/clients'));
    }

    protected function serverApiBaseCandidates()
    {
        return $this->compatApiCandidates((string) panel_array_get($this->compat, 'server_api_base', ''), array('/panel/api/server', '/api/server'));
    }

    protected function compatApiCandidates($cachedBase, $suffixes)
    {
        $bases = array();
        $cachedBase = trim((string) $cachedBase);
        if ($cachedBase !== '') {
            $bases[] = rtrim($cachedBase, '/');
        }

        $origin = $this->baseOrigin();
        foreach ($this->candidateRootPrefixes() as $prefix) {
            $root = rtrim($origin . $prefix, '/');
            if ($root === '') {
                continue;
            }
            foreach ((array) $suffixes as $suffix) {
                $bases[] = $root . '/' . ltrim((string) $suffix, '/');
            }
        }

        $roots = array();
        $roots[] = $this->baseUrl . $this->panelPath;
        if (!$this->hasExplicitApiRootHint()) {
            $roots[] = $this->baseUrl;
        }
        foreach ($roots as $root) {
            $root = rtrim((string) $root, '/');
            if ($root === '') {
                continue;
            }
            foreach ((array) $suffixes as $suffix) {
                $bases[] = $root . '/' . ltrim((string) $suffix, '/');
            }
        }

        return array_values(array_unique(array_filter($bases)));
    }

    protected function candidateRootPrefixes()
    {
        $prefixes = array();
        $urlPath = '';
        $parts = @parse_url($this->baseUrl);
        if (is_array($parts) && isset($parts['path'])) {
            $urlPath = $this->normalizePrefixPath($parts['path']);
        }
        $panelPath = $this->normalizePrefixPath($this->panelPath);

        $add = function ($value) use (&$prefixes) {
            $value = $this->normalizePrefixPath($value);
            if (!in_array($value, $prefixes, true)) {
                $prefixes[] = $value;
            }
        };

        if ($urlPath !== '') {
            $add($urlPath);
            if (preg_match('~/panel$~i', $urlPath)) {
                $add(preg_replace('~/panel$~i', '', $urlPath));
            }
        }
        if ($panelPath !== '') {
            $add($panelPath);
            if (preg_match('~/panel$~i', $panelPath)) {
                $add(preg_replace('~/panel$~i', '', $panelPath));
            }
        }
        if ($urlPath !== '' && $panelPath !== '' && strcasecmp($urlPath, $panelPath) !== 0) {
            $combined = $this->normalizePrefixPath($urlPath . '/' . ltrim($panelPath, '/'));
            $add($combined);
        }
        if (!$this->hasExplicitApiRootHint()) {
            $add('');
        }

        return array_values(array_unique(array_filter($prefixes, function ($value) {
            return $value !== null;
        })));
    }

    protected function normalizePrefixPath($value)
    {
        $value = trim((string) $value);
        if ($value === '' || $value === '/') {
            return '';
        }
        if ($value[0] !== '/') {
            $value = '/' . $value;
        }
        $value = preg_replace('~/{2,}~', '/', $value);
        $value = rtrim($value, '/');
        return $value === '/' ? '' : $value;
    }

    protected function hasExplicitApiRootHint()
    {
        $panelPath = $this->normalizePrefixPath($this->panelPath);
        if ($panelPath !== '') {
            return true;
        }
        $parts = @parse_url($this->baseUrl);
        if (is_array($parts) && isset($parts['path'])) {
            return $this->normalizePrefixPath($parts['path']) !== '';
        }
        return false;
    }

    protected function baseOrigin()
    {
        $parts = @parse_url($this->baseUrl);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return rtrim((string) $this->baseUrl, '/');
        }
        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }
        return $origin;
    }

    protected function compatApiBase()
    {
        return trim((string) panel_array_get($this->compat, 'api_base', $this->apiBase));
    }

    protected function makeVariant($method, $url, $body, $allowForm, $mode, $apiBase, $path, $baseKey)
    {
        return array(
            'method' => strtoupper((string) $method),
            'url' => (string) $url,
            'body' => $body,
            'allowForm' => (bool) $allowForm,
            'mode' => (string) $mode,
            'api_base' => rtrim((string) $apiBase, '/'),
            'path' => (string) $path,
            'base_key' => (string) $baseKey,
        );
    }

    protected function variantSignature($variant)
    {
        return md5(json_encode(array(
            'method' => isset($variant['method']) ? $variant['method'] : '',
            'api_base' => isset($variant['api_base']) ? $variant['api_base'] : '',
            'path' => isset($variant['path']) ? $variant['path'] : '',
            'mode' => isset($variant['mode']) ? $variant['mode'] : '',
            'allowForm' => !empty($variant['allowForm']) ? 1 : 0,
        ), JSON_UNESCAPED_SLASHES));
    }


    protected function shouldTemporarilyDisableAction($action)
    {
        return in_array((string) $action, array('get_client_links'), true);
    }

    protected function isActionTemporarilyDisabled($action)
    {
        $until = (int) panel_array_get($this->compat, 'disabled_actions.' . $action . '.until', 0);
        if ($until <= 0) {
            return false;
        }
        return $until > time();
    }

    protected function disableActionTemporarily($action, $seconds, $reason)
    {
        if (!isset($this->compat['disabled_actions']) || !is_array($this->compat['disabled_actions'])) {
            $this->compat['disabled_actions'] = array();
        }
        $this->compat['disabled_actions'][(string) $action] = array(
            'until' => time() + max(60, (int) $seconds),
            'reason' => (string) $reason,
            'updated_at' => panel_now(),
        );
        $this->saveCompat();
    }

    protected function clearDisabledAction($action)
    {
        if (isset($this->compat['disabled_actions']) && is_array($this->compat['disabled_actions']) && isset($this->compat['disabled_actions'][(string) $action])) {
            unset($this->compat['disabled_actions'][(string) $action]);
            $this->saveCompat();
        }
    }

    protected function isMissingRouteFailure($result, $normalized)
    {
        $status = (int) $this->lastStatusCode;
        if ($status === 404 || $status === 405) {
            return true;
        }
        $message = '';
        if (is_array($normalized) && isset($normalized['message'])) {
            $message = strtolower(trim((string) $normalized['message']));
        } elseif (is_array($result) && isset($result['message'])) {
            $message = strtolower(trim((string) $result['message']));
        }
        if ($message === '') {
            return false;
        }
        return strpos($message, 'not found') !== false
            || strpos($message, 'unsupported response shape') !== false
            || strpos($message, 'received non-json response') !== false
            || strpos($message, '404') !== false;
    }

    protected function orderVariantsForAction($action, $variants)
    {
        $cachedSig = trim((string) panel_array_get($this->compat, 'actions.' . $action . '.signature', ''));
        if ($cachedSig === '') {
            return $variants;
        }
        $preferred = array();
        $others = array();
        foreach ((array) $variants as $variant) {
            if ($this->variantSignature($variant) === $cachedSig) {
                $preferred[] = $variant;
            } else {
                $others[] = $variant;
            }
        }
        return array_merge($preferred, $others);
    }

    protected function rememberVariantSuccess($action, $variant)
    {
        if (!isset($this->compat['actions']) || !is_array($this->compat['actions'])) {
            $this->compat['actions'] = array();
        }
        $this->compat['actions'][$action] = array(
            'signature' => $this->variantSignature($variant),
            'api_base' => isset($variant['api_base']) ? $variant['api_base'] : '',
            'path' => isset($variant['path']) ? $variant['path'] : '',
            'mode' => isset($variant['mode']) ? $variant['mode'] : '',
            'allowForm' => !empty($variant['allowForm']),
            'updated_at' => panel_now(),
        );
        if (!empty($variant['api_base'])) {
            $baseKey = isset($variant['base_key']) ? (string) $variant['base_key'] : '';
            if ($baseKey === 'server_api_base') {
                $this->compat['server_api_base'] = (string) $variant['api_base'];
            } elseif ($baseKey === 'client_api_base') {
                $this->compat['client_api_base'] = (string) $variant['api_base'];
            } else {
                $this->compat['api_base'] = (string) $variant['api_base'];
            }
        }
        $this->saveCompat();
    }

    protected function loadCompat()
    {
        if (!is_file($this->compatFile)) {
            return;
        }
        $data = panel_safe_json_decode(@file_get_contents($this->compatFile));
        if (is_array($data)) {
            $this->compat = array_merge($this->compat, $data);
        }
    }

    protected function saveCompat()
    {
        @file_put_contents($this->compatFile, json_encode($this->compat, JSON_UNESCAPED_SLASHES));
    }

    protected function retryWithSessionAuth($method, $url, $body, $decodeJson, $allowForm, $attachCookies)
    {
        if (!$this->hasApiToken() || !$this->urlUsesApiAuth($url) || !$this->hasNodeCredentials()) {
            return array('attempted' => false, 'result' => array());
        }
        $login = $this->loginSession(true);
        if (!$login['ok']) {
            return array('attempted' => true, 'result' => $login);
        }
        $result = $this->raw($method, $url, $body, $decodeJson, $allowForm, $attachCookies, true);
        if (!empty($result['ok'])) {
            $this->rememberPreferredAuthMode('session_cookie');
        }
        return array('attempted' => true, 'result' => $result);
    }

    protected function raw($method, $url, $body, $decodeJson, $allowForm, $attachCookies, $forceCookieAuth = false)
    {
        if (!function_exists('curl_init')) {
            return array('ok' => false, 'message' => 'cURL is required on the server.');
        }

        $method = strtoupper((string) $method);
        $attempt = 0;
        $last = array('ok' => false, 'message' => 'Unknown request failure.');

        while ($attempt < $this->retryAttempts) {
            $attempt++;
            $profiles = $this->transportProfiles;
            foreach ($profiles as $profile) {
                $result = $this->execCurlProfile($method, $url, $body, $decodeJson, $allowForm, $attachCookies, $profile, $forceCookieAuth);
                $last = $result;
                if ($result['ok']) {
                    return $result;
                }

                $errno = $this->lastCurlErrno;
                $httpCode = $this->lastStatusCode;
                $transportRetryErrnos = array(6, 7, 28, 35, 52, 56);
                $retryNextProfile = ($errno > 0 && in_array($errno, $transportRetryErrnos, true));

                if (!$retryNextProfile) {
                    return $result;
                }
                if ($errno === 0 && $httpCode > 0) {
                    return $result;
                }
            }

            if ($attempt >= $this->retryAttempts) {
                break;
            }
            usleep(250000 * $attempt);
        }

        return $last;
    }

    protected function execCurlProfile($method, $url, $body, $decodeJson, $allowForm, $attachCookies, $profile, $forceCookieAuth = false)
    {
        $headers = array(
            'Accept: application/json, text/plain, */*',
            'X-Requested-With: XMLHttpRequest',
            'Expect:',
        );
        $effectiveUrl = $this->frontedUrl($url);
        $connectTo = $this->connectToRule($url, $effectiveUrl);
        $useFrontingHeaders = ($this->requestHostOverride !== '' || $this->requestSniOverride !== '');
        $origin = $useFrontingHeaders ? $this->requestOrigin() : $this->baseUrl;
        $refererBase = $useFrontingHeaders ? $this->requestRefererBase() : $this->baseUrl;
        if ($this->panelPath !== '') {
            $headers[] = 'Referer: ' . rtrim($refererBase, '/') . $this->panelPath . '/';
            $headers[] = 'Origin: ' . $origin;
        }
        $hostHeader = $this->requestHostHeader();
        if (($this->requestHostOverride !== '' || $this->requestSniOverride !== '') && $hostHeader !== '') {
            $headers[] = 'Host: ' . $hostHeader;
        }
        if ($this->shouldUseBearerForRequest($url, $forceCookieAuth)) {
            $headers[] = 'Authorization: Bearer ' . $this->apiToken;
        }

        $raw = '';
        $code = 0;
        $err = '';
        $errno = 0;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $effectiveUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->verifyPeer);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->verifyHost);
        if ($connectTo !== null && defined('CURLOPT_CONNECT_TO')) {
            @curl_setopt($ch, CURLOPT_CONNECT_TO, array($connectTo));
        }
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, array($this, 'captureHeader'));
        curl_setopt($ch, CURLOPT_USERAGENT, 'XUI-Reseller-Panel/1.3');
        curl_setopt($ch, CURLOPT_NOSIGNAL, true);
        if (defined('CURLOPT_ENCODING')) {
            @curl_setopt($ch, CURLOPT_ENCODING, '');
        }
        if (defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }
        if (defined('CURL_HTTP_VERSION_1_1')) {
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        }
        if (defined('CURLOPT_SSL_ENABLE_ALPN')) {
            @curl_setopt($ch, CURLOPT_SSL_ENABLE_ALPN, false);
        }
        if (defined('CURLOPT_SSL_ENABLE_NPN')) {
            @curl_setopt($ch, CURLOPT_SSL_ENABLE_NPN, false);
        }
        if (!empty($profile['ipresolve']) && defined('CURLOPT_IPRESOLVE')) {
            @curl_setopt($ch, CURLOPT_IPRESOLVE, $profile['ipresolve']);
        }
        $this->applyProxyCurlOptions($ch);

        if ($attachCookies) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookieFile);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookieFile);
        }

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }

        if ($method !== 'GET' && $body !== null) {
            if ($allowForm) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query((array) $body));
                $headers[] = 'Content-Type: application/x-www-form-urlencoded; charset=UTF-8';
            } else {
                $payload = is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_SLASHES);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                $headers[] = 'Content-Type: application/json; charset=UTF-8';
            }
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $this->lastResponseHeaders = array();
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $errno = (int) curl_errno($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $this->lastStatusCode = $code;
        $this->lastCurlErrno = $errno;

        if ($raw === false || $err !== '') {
            $transportLabel = isset($profile['label']) ? $profile['label'] : 'default';
            $this->lastError = $err !== '' ? $err : 'Unknown cURL error';
            $result = array(
                'ok' => false,
                'message' => 'Node request failed [' . $transportLabel . ']: ' . $this->lastError,
                'curl_errno' => $errno,
                'status_code' => $code,
            );
            $this->logRequestResult('error', $method, $url, $body, $result['message'], $code, $errno);
            return $result;
        }

        $result = $decodeJson ? $this->decodeResponse($raw, $code) : array(
            'ok' => $code >= 200 && $code < 300,
            'message' => $code >= 200 && $code < 300 ? 'OK' : 'HTTP ' . $code,
            'data' => array('raw' => $raw),
            'raw' => $raw,
        );
        $result['curl_errno'] = $errno;
        $result['status_code'] = $code;
        $this->logRequestResult(!empty($result['ok']) ? 'access' : 'error', $method, $url, $body, isset($result['message']) ? $result['message'] : '', $code, $errno);
        return $result;
    }

    protected function normalizePaths($baseUrl, $panelPath)
    {
        $baseUrl = rtrim((string) $baseUrl, '/');
        $panelPath = trim((string) $panelPath);
        if ($panelPath === '/' || strtolower($panelPath) === '/login') {
            $panelPath = '';
        }
        if ($panelPath !== '') {
            if ($panelPath[0] !== '/') {
                $panelPath = '/' . $panelPath;
            }
            $panelPath = rtrim($panelPath, '/');
        }

        $parts = @parse_url($baseUrl);
        if (is_array($parts) && isset($parts['scheme']) && isset($parts['host'])) {
            $path = isset($parts['path']) ? rtrim((string) $parts['path'], '/') : '';
            if ($path === '/') {
                $path = '';
            }
            if ($panelPath !== '' && $path !== '' && strtolower($path) === strtolower($panelPath)) {
                $panelPath = '';
            } elseif ($panelPath === '' && $path !== '' && strtolower(substr($path, -6)) === '/panel') {
                $panelPath = '';
            }
            $baseUrl = $parts['scheme'] . '://' . $parts['host'];
            if (isset($parts['port'])) {
                $baseUrl .= ':' . $parts['port'];
            }
            $baseUrl .= $path;
        }

        return array('base_url' => rtrim($baseUrl, '/'), 'panel_path' => $panelPath);
    }

    protected function loginUrls()
    {
        $urls = array();
        if ($this->panelPath !== '') {
            $urls[] = $this->baseUrl . $this->panelPath . '/login';
        }
        if (!$this->hasExplicitApiRootHint() || $this->panelPath === '') {
            $urls[] = $this->baseUrl . '/login';
        }
        if ($this->panelPath === '' && preg_match('~/panel$~i', $this->baseUrl)) {
            $urls[] = rtrim($this->baseUrl, '/') . '/login';
            $urls[] = preg_replace('~/panel$~i', '', $this->baseUrl) . '/login';
        }
        $urls = array_values(array_unique(array_filter($urls)));
        return $urls;
    }

    protected function buildApiBase($suffix)
    {
        $suffix = '/' . ltrim((string) $suffix, '/');
        return $this->baseUrl . $this->panelPath . $suffix;
    }

    protected function buildTransportProfiles()
    {
        $profiles = array(
            array('label' => 'default', 'ipresolve' => 0),
        );
        if (defined('CURL_IPRESOLVE_V4')) {
            $profiles[] = array('label' => 'ipv4', 'ipresolve' => CURL_IPRESOLVE_V4);
        }
        if (defined('CURL_IPRESOLVE_V6')) {
            $profiles[] = array('label' => 'ipv6', 'ipresolve' => CURL_IPRESOLVE_V6);
        }
        return $profiles;
    }

    protected function hasProxy()
    {
        return $this->proxyHost !== '' && $this->proxyPort > 0;
    }

    protected function applyProxyCurlOptions(&$ch)
    {
        if (!$this->hasProxy()) {
            return;
        }
        curl_setopt($ch, CURLOPT_PROXY, $this->proxyHost);
        curl_setopt($ch, CURLOPT_PROXYPORT, (int) $this->proxyPort);
        if ($this->proxyType === 'socks5') {
            if (defined('CURLPROXY_SOCKS5_HOSTNAME')) {
                curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
            } elseif (defined('CURLPROXY_SOCKS5')) {
                curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
            }
        } elseif ($this->proxyType === 'https') {
            if (defined('CURLPROXY_HTTPS')) {
                curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTPS);
            } elseif (defined('CURLPROXY_HTTP')) {
                curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
            }
        } else {
            if (defined('CURLPROXY_HTTP')) {
                curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
            }
        }
        if ($this->proxyUsername !== '') {
            curl_setopt($ch, CURLOPT_PROXYUSERPWD, $this->proxyUsername . ':' . $this->proxyPassword);
        }
    }

    protected function requestHostHeader()
    {
        if ($this->requestHostOverride !== '') {
            return $this->requestHostOverride;
        }
        if ($this->requestSniOverride !== '') {
            return $this->requestSniOverride;
        }
        $parts = @parse_url($this->baseUrl);
        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }
        $host = (string) $parts['host'];
        if (!empty($parts['port'])) {
            $host .= ':' . (int) $parts['port'];
        }
        return $host;
    }

    protected function requestOrigin()
    {
        $parts = @parse_url($this->baseUrl);
        if (!is_array($parts) || empty($parts['scheme'])) {
            return $this->baseUrl;
        }
        return (string) $parts['scheme'] . '://' . $this->requestHostHeader();
    }

    protected function requestRefererBase()
    {
        $parts = @parse_url($this->baseUrl);
        if (!is_array($parts) || empty($parts['scheme'])) {
            return $this->baseUrl;
        }
        $path = isset($parts['path']) ? rtrim((string) $parts['path'], '/') : '';
        return (string) $parts['scheme'] . '://' . $this->requestHostHeader() . $path;
    }

    protected function frontedUrl($url)
    {
        if ($this->requestSniOverride === '') {
            return $url;
        }
        $parts = @parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return $url;
        }
        $scheme = !empty($parts['scheme']) ? (string) $parts['scheme'] : 'https';
        $authority = $this->requestSniOverride;
        if (!empty($parts['port'])) {
            $authority .= ':' . (int) $parts['port'];
        }
        $rebuilt = $scheme . '://' . $authority;
        if (isset($parts['path'])) {
            $rebuilt .= (string) $parts['path'];
        }
        if (!empty($parts['query'])) {
            $rebuilt .= '?' . (string) $parts['query'];
        }
        if (!empty($parts['fragment'])) {
            $rebuilt .= '#' . (string) $parts['fragment'];
        }
        return $rebuilt;
    }

    protected function connectToRule($originalUrl, $effectiveUrl)
    {
        if ($this->requestSniOverride === '' || !defined('CURLOPT_CONNECT_TO')) {
            return null;
        }
        $original = @parse_url($originalUrl);
        $effective = @parse_url($effectiveUrl);
        if (!is_array($original) || !is_array($effective) || empty($original['host']) || empty($effective['host'])) {
            return null;
        }
        $originalPort = !empty($original['port']) ? (int) $original['port'] : (($original['scheme'] ?? 'https') === 'http' ? 80 : 443);
        $effectivePort = !empty($effective['port']) ? (int) $effective['port'] : (($effective['scheme'] ?? 'https') === 'http' ? 80 : 443);
        if ((string) $original['host'] === (string) $effective['host'] && $originalPort === $effectivePort) {
            return null;
        }
        return (string) $effective['host'] . ':' . $effectivePort . ':' . (string) $original['host'] . ':' . $originalPort;
    }

    protected function decodeResponse($raw, $code)
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $contentType = isset($this->lastResponseHeaders['content-type']) ? (is_array($this->lastResponseHeaders['content-type']) ? implode(', ', $this->lastResponseHeaders['content-type']) : $this->lastResponseHeaders['content-type']) : '';
            $location = isset($this->lastResponseHeaders['location']) ? (is_array($this->lastResponseHeaders['location']) ? implode(', ', $this->lastResponseHeaders['location']) : $this->lastResponseHeaders['location']) : '';
            $snippet = trim(preg_replace('/\s+/', ' ', strip_tags(substr((string) $raw, 0, 260))));
            $message = 'Received non-JSON response.';
            if ($contentType !== '') {
                $message .= ' Content-Type: ' . $contentType . '.';
            }
            if ($location !== '') {
                $message .= ' Redirect: ' . $location . '.';
            }
            if ($snippet !== '') {
                $message .= ' Snippet: ' . $snippet;
            }
            return array(
                'ok' => false,
                'message' => $message,
                'data' => array('raw' => $raw),
                'raw' => $raw,
            );
        }

        $ok = $code >= 200 && $code < 300;
        if (isset($decoded['success'])) {
            $ok = (bool) $decoded['success'];
        } elseif (isset($decoded['status'])) {
            $status = strtolower((string) $decoded['status']);
            if (in_array($status, array('success', 'ok'), true)) {
                $ok = true;
            } elseif (in_array($status, array('error', 'fail', 'failed'), true)) {
                $ok = false;
            }
        } elseif (isset($decoded['code']) && is_numeric($decoded['code'])) {
            $codeValue = (int) $decoded['code'];
            if ($codeValue === 0) {
                $ok = true;
            } elseif ($codeValue >= 400) {
                $ok = false;
            }
        }

        $message = '';
        foreach (array('msg', 'message', 'detail', 'error') as $key) {
            if (isset($decoded[$key]) && $decoded[$key] !== '') {
                $message = (string) $decoded[$key];
                break;
            }
        }
        if ($message === '') {
            $message = $ok ? 'Request successful.' : 'Request failed.';
        }

        $data = null;
        foreach (array('obj', 'data', 'result', 'items', 'list') as $key) {
            if (array_key_exists($key, $decoded)) {
                $data = $decoded[$key];
                break;
            }
        }
        if ($data === null) {
            $data = $decoded;
        }

        return array(
            'ok' => $ok,
            'message' => $message,
            'data' => $data,
            'raw' => $decoded,
        );
    }

    protected function appendLog($name, $row)
    {
        $dir = $this->storageRoot . '/logs';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $file = $dir . '/' . $name . '.log';
        if (is_file($file) && @filesize($file) > 512 * 1024) {
            for ($i = 4; $i >= 1; $i--) {
                $src = $file . '.' . $i;
                $dst = $file . '.' . ($i + 1);
                if (is_file($src)) { @rename($src, $dst); }
            }
            @rename($file, $file . '.1');
        }
        @file_put_contents($file, json_encode($row, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
    }

    protected function logRequestResult($level, $method, $url, $body, $message, $code, $errno)
    {
        $name = $level === 'error' ? 'xui_error' : 'xui_access';
        $payload = is_array($body) ? array_keys($body) : (is_string($body) && $body !== '' ? 'raw' : '');
        $this->appendLog($name, array(
            'time' => panel_now(),
            'channel' => 'xui',
            'level' => $level,
            'node_id' => isset($this->node['id']) ? $this->node['id'] : '',
            'node_title' => isset($this->node['title']) ? $this->node['title'] : '',
            'method' => $method,
            'url' => $url,
            'status_code' => (int) $code,
            'curl_errno' => (int) $errno,
            'message' => (string) $message,
            'payload_keys' => $payload,
        ));
    }

    protected function captureHeader($ch, $headerLine)
    {
        $len = strlen($headerLine);
        $header = trim($headerLine);
        if ($header === '' || strpos($header, ':') === false) {
            return $len;
        }
        list($name, $value) = explode(':', $header, 2);
        $name = strtolower(trim($name));
        $value = trim($value);
        if (!isset($this->lastResponseHeaders[$name])) {
            $this->lastResponseHeaders[$name] = $value;
        } else {
            if (!is_array($this->lastResponseHeaders[$name])) {
                $this->lastResponseHeaders[$name] = array($this->lastResponseHeaders[$name]);
            }
            $this->lastResponseHeaders[$name][] = $value;
        }
        return $len;
    }
}
