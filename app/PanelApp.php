<?php
class PanelApp
{
    protected $config = array();
    protected $storage;
    protected $viewPath;
    protected $store;
    protected $requestMethod;
    protected $requestPath;
    protected $basePath;
    protected $apiContext = false;
    protected $apiCaptured = null;

    public function __construct($config)
    {
        $this->config = $config;
        $this->storage = PANEL_ROOT . '/storage';
        $this->viewPath = PANEL_ROOT . '/app/views';
        $this->ensureDirectories();
        $this->store = new JsonStore($this->storage);
        $this->applyRuntimeTimezone();
        $this->store->ensureCollections(array(
            'admins', 'resellers', 'nodes', 'templates', 'customers', 'customer_links',
            'tickets', 'ticket_messages', 'credit_ledger', 'activity', 'notices',
            'telegram_bindings', 'telegram_states'
        ));
        $this->startSession();
        $this->requestMethod = strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET');
        $this->ensureInstallLockIfInstalled();
        $this->maybeDecodeShieldPost();
        $this->sanitizeIncomingRequests();
        $this->basePath = $this->detectBasePath();
        $requestUriPath = parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
        if (!$requestUriPath) {
            $requestUriPath = '/';
        }
        if ($this->basePath !== '' && strpos($requestUriPath, $this->basePath) === 0) {
            $requestUriPath = substr($requestUriPath, strlen($this->basePath));
            if ($requestUriPath === false || $requestUriPath === '') {
                $requestUriPath = '/';
            }
        }
        $this->requestPath = $requestUriPath;
        if (rtrim($this->requestPath, '/') === '') {
            $this->requestPath = '/';
        } else {
            $this->requestPath = rtrim($this->requestPath, '/');
        }
    }

    protected function applyRuntimeTimezone()
    {
        $cfg = $this->store ? $this->store->readConfig('app') : array();
        if (!empty($cfg['timezone'])) {
            @date_default_timezone_set((string) $cfg['timezone']);
        }
    }

    protected function detectBasePath()
    {
        $scriptName = isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '';
        $base = panel_normalize_base_path(dirname($scriptName));
        if ($base !== '' && substr($base, -7) === '/public') {
            $base = substr($base, 0, -7);
            $base = panel_normalize_base_path($base);
        }
        return $base;
    }

    public function basePath()
    {
        return $this->basePath;
    }

    public function projectRoot()
    {
        return PANEL_ROOT;
    }

    public function url($path)
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', (string) $path)) {
            return (string) $path;
        }
        return panel_url_join($this->basePath, $path);
    }

    public function asset($path)
    {
        $clean = ltrim((string) $path, '/');
        if ($clean !== '' && preg_match('/\.js$/i', $clean) && !empty($this->securitySettings()['js_hardening'])) {
            return $this->url('/__asset/' . rawurlencode($clean));
        }
        return $this->url('/assets/' . $clean);
    }

    protected function ensureDirectories()
    {
        $paths = array(
            $this->storage,
            $this->storage . '/config',
            $this->storage . '/data',
            $this->storage . '/logs',
            $this->storage . '/cache',
            $this->storage . '/cache/cookies',
            $this->storage . '/cache/rate_limits',
            $this->storage . '/cache/qrcodes',
            $this->storage . '/locks',
            $this->storage . '/backups',
        );
        foreach ($paths as $path) {
            if (!is_dir($path)) {
                @mkdir($path, 0775, true);
            }
        }
    }

    protected function startSession()
    {
        if (session_status() === PHP_SESSION_NONE) {
            $secure = $this->requestIsSecure();
            session_name(isset($this->config['session_name']) ? $this->config['session_name'] : 'xui_reseller');
            ini_set('session.gc_maxlifetime', '7200');
            ini_set('session.use_only_cookies', '1');
            ini_set('session.use_strict_mode', '1');
            ini_set('session.cookie_httponly', '1');
            if ($secure) {
                ini_set('session.cookie_secure', '1');
            }
            if (!headers_sent()) {
                if (defined('PHP_VERSION_ID') && PHP_VERSION_ID >= 70300) {
                    session_set_cookie_params(array(
                        'lifetime' => 7200,
                        'path' => '/',
                        'secure' => $secure,
                        'httponly' => true,
                        'samesite' => 'Lax',
                    ));
                } else {
                    session_set_cookie_params(7200, '/; samesite=Lax', '', $secure, true);
                }
            }
            session_start();
        }
        if (!isset($_SESSION['_meta'])) {
            $_SESSION['_meta'] = array('created_at' => time(), 'regenerated_at' => time());
        }
        if (time() - (int) $_SESSION['_meta']['regenerated_at'] >= 300) {
            session_regenerate_id(true);
            $_SESSION['_meta']['regenerated_at'] = time();
        }
    }

    public function run()
    {
        $path = $this->requestPath;
        $method = $this->requestMethod;

        if (preg_match('#^/user/([a-zA-Z0-9_-]+)$#', $path, $m) && $method === 'GET') {
            return $this->publicSubscription($m[1]);
        }
        if (preg_match('#^/user/([a-zA-Z0-9_-]+)/export$#', $path, $m) && $method === 'GET') {
            return $this->publicSubscriptionExport($m[1]);
        }
        if ($path === '/get' && $method === 'GET') {
            $this->requireInstalled();
            return $this->publicGetAccess();
        }
        if ($path === '/get' && $method === 'POST') {
            $this->requireInstalled();
            $this->validateCsrf();
            return $this->publicGetAccess();
        }
        if (($path === '/__qr' || preg_match('#^/__qr/([a-f0-9]{40})$#', $path, $qrMatch)) && $method === 'GET') {
            $this->requireInstalled();
            $qrToken = isset($qrMatch[1]) ? $qrMatch[1] : trim((string) $this->input('k', ''));
            return $this->serveLocalQr($qrToken);
        }
        if (preg_match('#^/__asset/([^/]+)$#', $path, $m) && $method === 'GET') {
            return $this->serveInternalAsset(rawurldecode($m[1]));
        }
        if (preg_match('#^/telegram/webhook/([a-zA-Z0-9_-]+)$#', $path, $m)) {
            return $this->telegramWebhook($m[1]);
        }
        if (preg_match('#^/telegram/poll/([a-zA-Z0-9_-]+)$#', $path, $m)) {
            return $this->telegramPollEndpoint($m[1]);
        }

        if ($path === '/sync/export' && $method === 'GET') {
            $this->requireInstalled();
            return $this->panelSyncExportEndpoint('');
        }
        if ($path === '/sync/run' && ($method === 'GET' || $method === 'POST')) {
            $this->requireInstalled();
            return $this->panelSyncRunEndpoint('');
        }
        if (preg_match('#^/sync/export/([a-zA-Z0-9_-]+)$#', $path, $m) && $method === 'GET') {
            $this->requireInstalled();
            return $this->panelSyncExportEndpoint($m[1]);
        }
        if (preg_match('#^/sync/run/([a-zA-Z0-9_-]+)$#', $path, $m) && ($method === 'GET' || $method === 'POST')) {
            $this->requireInstalled();
            return $this->panelSyncRunEndpoint($m[1]);
        }

        if ($path === '/') {
            if (!$this->isInstalled()) {
                $this->redirect('/install');
            }
            if ($this->authCheck()) {
                $this->redirect($this->authRole() === 'admin' ? '/admin/dashboard' : '/reseller/dashboard');
            }
            if ($this->landingEnabled()) {
                return $this->publicLandingPage(false);
            }
            $this->redirect('/login');
        }
        if ($path === '/landing' && $method === 'GET') {
            $this->requireInstalled();
            return $this->publicLandingPage(true);
        }

        if ($path === '/install' && $method === 'GET') {
            $this->installerOnly();
            return $this->renderAuth('install.php', array('errors' => array(), 'old' => array()));
        }
        if ($path === '/install' && $method === 'POST') {
            $this->installerOnly();
            $this->validateCsrf();
            return $this->handleInstall();
        }


$this->requireInstalled();

if (strpos($path, '/api/reseller') === 0) {
    return $this->handleResellerApi($path, $method);
}

if ($path === '/login' && $method === 'GET') {

            $this->guestOnly(true);
            return $this->renderAuth('login.php', array('errors' => array(), 'old' => array('username' => '')));
        }
        if ($path === '/login' && $method === 'POST') {
            $this->guestOnly(true);
            $this->validateCsrf();
            return $this->handleLogin();
        }
        if ($path === '/logout' && ($method === 'GET' || $method === 'POST')) {
            return $this->logout();
        }

        if ($path === '/admin/dashboard' && $method === 'GET') { $this->adminOnly(); return $this->adminDashboard(); }
        if ($path === '/admin/resellers' && $method === 'GET') { $this->adminOnly(); return $this->adminResellers(); }
        if ($path === '/admin/resellers/create' && $method === 'GET') { $this->adminOnly(); return $this->adminResellerForm('create'); }
        if ($path === '/admin/resellers/create' && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->saveReseller(); }
        if (preg_match('#^/admin/resellers/([^/]+)/edit$#', $path, $m) && $method === 'GET') { $this->adminOnly(); return $this->adminResellerForm('edit', $m[1]); }
        if (preg_match('#^/admin/resellers/([^/]+)/edit$#', $path, $m) && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->saveReseller($m[1]); }
        if (preg_match('#^/admin/resellers/([^/]+)/toggle$#', $path, $m) && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->toggleEntity('resellers', $m[1], '/admin/resellers', 'Reseller status updated.'); }
        if (preg_match('#^/admin/resellers/([^/]+)/delete$#', $path, $m) && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->deleteReseller($m[1]); }
        if (preg_match('#^/admin/resellers/([^/]+)/credit$#', $path, $m) && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->adjustResellerCredit($m[1]); }

        if ($path === '/admin/nodes' && $method === 'GET') { $this->adminOnly(); return $this->adminNodes(); }
        if ($path === '/admin/nodes/create' && $method === 'GET') { $this->adminOnly(); return $this->adminNodeForm('create'); }
        if ($path === '/admin/nodes/create' && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->saveNode(); }
        if (preg_match('#^/admin/nodes/([^/]+)/edit$#', $path, $m) && $method === 'GET') { $this->adminOnly(); return $this->adminNodeForm('edit', $m[1]); }
        if (preg_match('#^/admin/nodes/([^/]+)/edit$#', $path, $m) && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->saveNode($m[1]); }
        if (preg_match('#^/admin/nodes/([^/]+)/toggle$#', $path, $m) && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->toggleEntity('nodes', $m[1], '/admin/nodes', 'Server status updated.'); }
        if (preg_match('#^/admin/nodes/([^/]+)/delete$#', $path, $m) && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->deleteNode($m[1]); }
        if (preg_match('#^/admin/nodes/([^/]+)/test$#', $path, $m) && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->testNode($m[1]); }

        if ($path === '/admin/templates' && $method === 'GET') { $this->adminOnly(); return $this->adminTemplates(); }
        if ($path === '/admin/templates/create' && $method === 'GET') { $this->adminOnly(); return $this->adminTemplateForm('create'); }
        if ($path === '/admin/templates/create' && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->saveTemplate(); }
        if (preg_match('#^/admin/templates/([^/]+)/edit$#', $path, $m) && $method === 'GET') { $this->adminOnly(); return $this->adminTemplateForm('edit', $m[1]); }
        if (preg_match('#^/admin/templates/([^/]+)/edit$#', $path, $m) && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->saveTemplate($m[1]); }
        if (preg_match('#^/admin/templates/([^/]+)/toggle$#', $path, $m) && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->toggleEntity('templates', $m[1], '/admin/templates', 'Template status updated.'); }
        if (preg_match('#^/admin/templates/([^/]+)/delete$#', $path, $m) && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->deleteTemplate($m[1]); }
        if (preg_match('#^/admin/nodes/([^/]+)/import-inbounds$#', $path, $m) && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->importNodeInbounds($m[1]); }
        if (preg_match('#^/admin/nodes/([^/]+)/import-profiles$#', $path, $m) && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->importNodeProfiles($m[1]); }

        if ($path === '/admin/customers' && $method === 'GET') { $this->adminOnly(); return $this->customersPage('admin'); }
        if ($path === '/admin/customers/sync-visible' && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->syncCustomersList('admin'); }
        if ($path === '/admin/customers/clear-ended' && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->clearEndedCustomers(); }
        if (preg_match('#^/admin/customers/([^/]+)$#', $path, $m) && $method === 'GET') { $this->adminOnly(); return $this->customerDetailsPage('admin', $m[1]); }
        if (preg_match('#^/admin/customers/([^/]+)/toggle$#', $path, $m) && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->toggleCustomer($m[1], false); }
        if (preg_match('#^/admin/customers/([^/]+)/delete$#', $path, $m) && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->deleteCustomer($m[1], false); }
        if (preg_match('#^/admin/customers/([^/]+)/sync$#', $path, $m) && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->syncCustomer($m[1], false); }

        if ($path === '/admin/tickets' && $method === 'GET') { $this->adminOnly(); return $this->ticketsPage('admin'); }
        if ($path === '/admin/tickets/create' && $method === 'GET') { $this->adminOnly(); return $this->ticketForm('admin'); }
        if ($path === '/admin/tickets/create' && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->saveTicket('admin'); }
        if (preg_match('#^/admin/tickets/([^/]+)$#', $path, $m) && $method === 'GET') { $this->adminOnly(); return $this->ticketView('admin', $m[1]); }
        if (preg_match('#^/admin/tickets/([^/]+)/reply$#', $path, $m) && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->replyTicket('admin', $m[1]); }
        if (preg_match('#^/admin/tickets/([^/]+)/status$#', $path, $m) && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->ticketStatus('admin', $m[1]); }
        if (preg_match('#^/admin/tickets/([^/]+)/delete$#', $path, $m) && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->deleteTicket('admin', $m[1]); }


if ($path === '/admin/notices' && $method === 'GET') { $this->adminOnly(); return $this->adminNotices(); }
if ($path === '/admin/notices/create' && $method === 'GET') { $this->adminOnly(); return $this->adminNoticeForm('create'); }
if ($path === '/admin/notices/create' && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->saveNotice(); }
if (preg_match('#^/admin/notices/([^/]+)/edit$#', $path, $m) && $method === 'GET') { $this->adminOnly(); return $this->adminNoticeForm('edit', $m[1]); }
if (preg_match('#^/admin/notices/([^/]+)/edit$#', $path, $m) && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->saveNotice($m[1]); }
if (preg_match('#^/admin/notices/([^/]+)/toggle$#', $path, $m) && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->toggleEntity('notices', $m[1], '/admin/notices', 'Notice status updated.'); }
if (preg_match('#^/admin/notices/([^/]+)/delete$#', $path, $m) && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->deleteNotice($m[1]); }
if ($path === '/admin/activity' && $method === 'GET') { $this->adminOnly(); return $this->adminActivity(); }
if ($path === '/admin/transactions' && $method === 'GET') { $this->adminOnly(); return $this->adminTransactions(); }
if ($path === '/admin/logs' && $method === 'GET') { $this->adminOnly(); return $this->adminSystemLogs(); }
if ($path === '/admin/settings' && $method === 'GET') { $this->adminOnly(); return $this->adminSettings(); }

        if ($path === '/admin/settings' && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->saveAdminSettings(); }
        if ($path === '/admin/logs/clear' && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->clearAdminLog(); }
        if ($path === '/admin/telegram/poll' && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->adminTelegramPoll(); }
        if ($path === '/admin/telegram/webhook/set' && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->adminTelegramSetWebhook(); }
        if ($path === '/admin/telegram/webhook/delete' && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->adminTelegramDeleteWebhook(); }
        if ($path === '/admin/sync/run' && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->adminRunPanelSync(); }
        if ($path === '/admin/backups/create' && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->createAdminBackup(); }
        if ($path === '/admin/backups/download' && $method === 'GET') { $this->adminOnly(); return $this->downloadAdminBackup(); }
        if ($path === '/admin/backups/delete' && $method === 'POST') { $this->adminOnly(); $this->validateCsrf(); return $this->deleteAdminBackup(); }

if ($path === '/reseller/dashboard' && $method === 'GET') { $this->resellerOnly(); return $this->resellerDashboard(); }
if ($path === '/reseller/transactions' && $method === 'GET') { $this->resellerOnly(); return $this->resellerTransactions(); }
if ($path === '/reseller/activity' && $method === 'GET') { $this->resellerOnly(); return $this->resellerActivity(); }
if ($path === '/reseller/profile' && $method === 'GET') { $this->resellerOnly(); return $this->resellerProfile(); }
if ($path === '/reseller/profile' && $method === 'POST') { $this->resellerOnly(); $this->validateCsrf(); return $this->saveResellerPassword(); }
        if ($path === '/reseller/customers' && $method === 'GET') { $this->resellerOnly(); return $this->customersPage('reseller'); }
        if ($path === '/reseller/customers/sync-visible' && $method === 'POST') { $this->resellerOnly(); $this->validateCsrf(); return $this->syncCustomersList('reseller'); }
        if ($path === '/reseller/customers/create' && $method === 'GET') { $this->resellerOnly(); return $this->customerForm(null, 'xui'); }
        if ($path === '/reseller/customers/create' && $method === 'POST') { $this->resellerOnly(); $this->validateCsrf(); return $this->saveCustomer(); }
        if ($path === '/reseller/customers/create-um' && $method === 'GET') { $this->resellerOnly(); return $this->customerForm(null, 'um'); }
        if ($path === '/reseller/customers/create-um' && $method === 'POST') { $this->resellerOnly(); $this->validateCsrf(); return $this->saveCustomer(); }
        if (preg_match('#^/reseller/customers/([^/]+)$#', $path, $m) && $method === 'GET') { $this->resellerOnly(); return $this->customerDetailsPage('reseller', $m[1]); }
        if (preg_match('#^/reseller/customers/([^/]+)/edit$#', $path, $m) && $method === 'GET') { $this->resellerOnly(); return $this->customerForm($m[1]); }
        if (preg_match('#^/reseller/customers/([^/]+)/edit$#', $path, $m) && $method === 'POST') { $this->resellerOnly(); $this->validateCsrf(); return $this->saveCustomer($m[1]); }
        if (preg_match('#^/reseller/customers/([^/]+)/toggle$#', $path, $m) && $method === 'POST') { $this->resellerOnly(); $this->validateCsrf(); return $this->toggleCustomer($m[1], true); }
        if (preg_match('#^/reseller/customers/([^/]+)/delete$#', $path, $m) && $method === 'POST') { $this->resellerOnly(); $this->validateCsrf(); return $this->deleteCustomer($m[1], true); }
        if (preg_match('#^/reseller/customers/([^/]+)/sync$#', $path, $m) && $method === 'POST') { $this->resellerOnly(); $this->validateCsrf(); return $this->syncCustomer($m[1], true); }

        if ($path === '/reseller/tickets' && $method === 'GET') { $this->resellerOnly(); return $this->ticketsPage('reseller'); }
        if ($path === '/reseller/tickets/create' && $method === 'GET') { $this->resellerOnly(); return $this->ticketForm('reseller'); }
        if ($path === '/reseller/tickets/create' && $method === 'POST') { $this->resellerOnly(); $this->validateCsrf(); return $this->saveTicket('reseller'); }
        if (preg_match('#^/reseller/tickets/([^/]+)$#', $path, $m) && $method === 'GET') { $this->resellerOnly(); return $this->ticketView('reseller', $m[1]); }
        if (preg_match('#^/reseller/tickets/([^/]+)/reply$#', $path, $m) && $method === 'POST') { $this->resellerOnly(); $this->validateCsrf(); return $this->replyTicket('reseller', $m[1]); }
        if (preg_match('#^/reseller/tickets/([^/]+)/status$#', $path, $m) && $method === 'POST') { $this->resellerOnly(); $this->validateCsrf(); return $this->ticketStatus('reseller', $m[1]); }

        $this->abort(404, 'Page not found.');
    }

    public function config($key, $default)
    {
        return array_key_exists($key, $this->config) ? $this->config[$key] : $default;
    }

    public function input($key, $default)
    {
        if (isset($_POST[$key])) { return $_POST[$key]; }
        if (isset($_GET[$key])) { return $_GET[$key]; }
        return $default;
    }

    protected function sanitizeIncomingRequests()
    {
        $_GET = $this->sanitizeRequestBag(isset($_GET) ? $_GET : array());
        $_POST = $this->sanitizeRequestBag(isset($_POST) ? $_POST : array());
        $_REQUEST = array_merge($_GET, $_POST);
    }

    protected function sanitizeRequestBag($bag, $depth = 0)
    {
        if (!is_array($bag) || $depth > 8) { return array(); }
        $clean = array();
        foreach ($bag as $key => $value) {
            $safeKey = $this->sanitizeRequestKey($key);
            if ($safeKey === '') { continue; }
            if (is_array($value)) {
                $clean[$safeKey] = $this->sanitizeRequestBag($value, $depth + 1);
            } else {
                $clean[$safeKey] = $this->sanitizeRequestScalar($value);
            }
        }
        return $clean;
    }

    protected function sanitizeRequestKey($key)
    {
        $key = (string) $key;
        $key = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $key);
        if (strlen($key) > 80) { $key = substr($key, 0, 80); }
        return $key;
    }

    protected function sanitizeRequestScalar($value)
    {
        if ($value === null) { return ''; }
        if (is_bool($value)) { return $value ? '1' : '0'; }
        if (is_int($value) || is_float($value)) { return (string) $value; }
        if (!is_string($value)) { return ''; }
        if (function_exists('mb_check_encoding') && !mb_check_encoding($value, 'UTF-8')) {
            if (function_exists('mb_convert_encoding')) {
                $value = (string) @mb_convert_encoding($value, 'UTF-8', 'UTF-8');
            }
        }
        $value = str_replace("", '', $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
        if (strlen($value) > 200000) { $value = substr($value, 0, 200000); }
        return $value;
    }

    protected function sanitizeIdentifier($value, $maxLen)
    {
        $value = strtolower(trim((string) $value));
        $value = preg_replace('/[^a-z0-9_\-]/', '', $value);
        if (strlen($value) > $maxLen) { $value = substr($value, 0, $maxLen); }
        return $value;
    }

    public function authCheck() { return !empty($_SESSION['auth']); }
    public function authUser() { return isset($_SESSION['auth']) ? $_SESSION['auth'] : null; }
    public function authRole() { $u = $this->authUser(); return is_array($u) && isset($u['role']) ? $u['role'] : null; }

    protected function currentActorLabel()
    {
        $auth = $this->authUser();
        if (!is_array($auth)) { return 'system'; }
        if (!empty($auth['display_name'])) { return (string) $auth['display_name']; }
        if (!empty($auth['username'])) { return (string) $auth['username']; }
        if (!empty($auth['id'])) { return (string) $auth['id']; }
        return 'system';
    }

    protected function requireAuth()
    {
        if (!$this->authCheck()) {
            $this->flash('error', 'Please sign in first.');
            $this->redirect('/login');
        }
    }
    protected function adminOnly() { $this->requireAuth(); if ($this->authRole() !== 'admin') { $this->abort(403, 'Forbidden'); } }
    protected function resellerOnly() { $this->requireAuth(); if ($this->authRole() !== 'reseller') { $this->abort(403, 'Forbidden'); } }

    public function flash($type, $message)
    {
        if ($type !== null && $message !== null) {
            $_SESSION['_flash'] = array('type' => $type, 'message' => $message);
            return null;
        }
        if (!isset($_SESSION['_flash'])) { return null; }
        $f = $_SESSION['_flash']; unset($_SESSION['_flash']); return $f;
    }

    public function csrfToken()
    {
        if (empty($_SESSION['_csrf'])) { $_SESSION['_csrf'] = panel_random_hex(64); }
        return $_SESSION['_csrf'];
    }

    protected function validateCsrf()
    {
        $token = (string) $this->input('_token', '');
        $known = isset($_SESSION['_csrf']) ? (string) $_SESSION['_csrf'] : '';
        if (!hash_equals($known, $token)) {
            $this->appendSecurityLog('firewall', 'error', 'CSRF token validation failed.', array('path' => $this->requestPath, 'ip' => $this->clientIp()));
            if ($this->isAjax()) { $this->json(array('ok' => false, 'message' => 'Invalid security token.'), 419); }
            $this->abort(419, 'Invalid security token.');
        }
        if (!$this->validateSameOriginPost()) {
            $this->appendSecurityLog('firewall', 'error', 'Origin check failed.', array('path' => $this->requestPath, 'ip' => $this->clientIp(), 'origin' => isset($_SERVER['HTTP_ORIGIN']) ? (string) $_SERVER['HTTP_ORIGIN'] : '', 'referer' => isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : ''));
            if ($this->isAjax()) { $this->json(array('ok' => false, 'message' => 'Origin check failed.'), 403); }
            $this->abort(403, 'Origin check failed.');
        }
    }

    public function isAjax()
    {
        return strtolower(isset($_SERVER['HTTP_X_REQUESTED_WITH']) ? $_SERVER['HTTP_X_REQUESTED_WITH'] : '') === 'xmlhttprequest';
    }

    public function isInstalled()
    {
        return is_file($this->storage . '/config/app.json') && count($this->store->all('admins')) > 0;
    }

    protected function installLockPath()
    {
        return $this->storage . '/config/install.lock.json';
    }

    protected function isInstallLocked()
    {
        return is_file($this->installLockPath());
    }

    protected function writeInstallLock()
    {
        $payload = array(
            'locked' => true,
            'locked_at' => panel_now(),
            'ip' => $this->clientIp(),
        );
        @file_put_contents($this->installLockPath() . '.tmp', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @rename($this->installLockPath() . '.tmp', $this->installLockPath());
    }

    protected function ensureInstallLockIfInstalled()
    {
        if ($this->isInstalled() && !$this->isInstallLocked()) {
            $this->writeInstallLock();
        }
    }

    protected function installerOnly()
    {
        if ($this->authCheck()) {
            $this->redirect($this->authRole() === 'admin' ? '/admin/dashboard' : '/reseller/dashboard');
        }
        if ($this->isInstalled() || $this->isInstallLocked()) {
            $this->flash('error', 'Installer is locked because the panel is already installed.');
            $this->redirect('/login');
        }
    }

    protected function requireInstalled() { if (!$this->isInstalled()) { $this->redirect('/install'); } }
    protected function guestOnly($installedRequired)
    {
        if ($installedRequired && !$this->isInstalled()) { $this->redirect('/install'); }
        if ($this->authCheck()) {
            $this->redirect($this->authRole() === 'admin' ? '/admin/dashboard' : '/reseller/dashboard');
        }
    }

    protected function handleInstall()
    {
        $data = array(
            'app_name' => trim((string) $this->input('app_name', 'XUI Reseller Panel')),
            'app_url' => trim((string) $this->input('app_url', '')),
            'timezone' => trim((string) $this->input('timezone', 'Europe/Sofia')),
            'default_duration_days' => trim((string) $this->input('default_duration_days', '30')),
            'admin_username' => trim((string) $this->input('admin_username', 'admin')),
            'admin_password' => (string) $this->input('admin_password', ''),
        );
        $errors = array();
        if (strlen($data['app_name']) < 3) { $errors['app_name'][] = 'Application name must be at least 3 characters.'; }
        if ($data['app_url'] !== '' && filter_var($data['app_url'], FILTER_VALIDATE_URL) === false) { $errors['app_url'][] = 'Application URL is not valid.'; }
        if (!ctype_digit($data['default_duration_days']) || (int) $data['default_duration_days'] < 1) { $errors['default_duration_days'][] = 'Default duration must be a positive integer.'; }
        if (!preg_match('/^[a-zA-Z0-9_-]{3,32}$/', $data['admin_username'])) { $errors['admin_username'][] = 'Admin username is invalid.'; }
        if (strlen($data['admin_password']) < 8) { $errors['admin_password'][] = 'Admin password must be at least 8 characters.'; }
        if ($errors) { return $this->renderAuth('install.php', array('errors' => $errors, 'old' => $data)); }

        $cfg = array(
            'app_name' => $data['app_name'],
            'app_url' => $data['app_url'],
            'timezone' => $data['timezone'],
            'app_key' => panel_random_hex(64),
            'default_duration_days' => (int) $data['default_duration_days'],
            'login_max_attempts' => 8,
            'login_window_seconds' => 900,
            'login_lockout_seconds' => 900,
            'subscription_max_requests' => 60,
            'subscription_window_seconds' => 60,
            'page_shield_mode' => 'off',
            'page_shield_key' => base64_encode(function_exists('random_bytes') ? random_bytes(32) : openssl_random_pseudo_bytes(32)),
            'page_shield_forms' => 1,
            'js_hardening' => 1,
            'api_enabled' => 0,
            'api_encryption' => 0,
            'panel_sync_enabled' => 0,
            'panel_sync_mode' => 'off',
            'panel_sync_master_url' => '',
            'panel_sync_shared_secret' => panel_random_hex(24),
            'panel_sync_interval_seconds' => 300,
            'panel_sync_prune_missing' => 0,
            'panel_sync_proxy_enabled' => 0,
            'panel_sync_proxy_type' => 'http',
            'panel_sync_proxy_host' => '',
            'panel_sync_proxy_port' => 0,
            'panel_sync_proxy_username' => '',
            'panel_sync_proxy_password' => '',
            'customer_sync_cron_enabled' => 0,
            'customer_sync_period_minutes' => 30,
            'customer_sync_retry_attempts' => 2,
            'customer_sync_batch_size' => 25,
            'customer_pagination_enabled' => 1,
            'customer_pagination_per_page' => 25,
            'customer_auto_sync_admin_enabled' => 1,
            'customer_auto_sync_reseller_enabled' => 1,
            'customer_auto_sync_batch_limit' => 8,
            'maintenance_cleanup_enabled' => 1,
            'maintenance_cleanup_period_hours' => 24,
            'maintenance_cleanup_max_age_days' => 30,
            'auto_backup_enabled' => 0,
            'auto_backup_period_hours' => 24,
            'auto_backup_rotation_count' => 10,
            'created_at' => panel_now(),
        );
        $this->store->writeConfig('app', $cfg);
        $this->store->insert('admins', array(
            'username' => $data['admin_username'],
            'display_name' => 'Administrator',
            'password_hash' => panel_password_hash($data['admin_password']),
            'status' => 'active',
        ), 'adm');
        $this->writeInstallLock();
        $this->log('install.completed', array('admin_username' => $data['admin_username']));
        $this->flash('success', 'Installation completed. Please sign in.');
        $this->redirect('/login');
    }

    protected function handleLogin()
    {
        $username = $this->sanitizeIdentifier($this->input('username', ''), 64);
        $password = $this->sanitizeRequestScalar($this->input('password', ''));

        if ($username === '' || strlen($password) < 1 || strlen($password) > 4096) {
            $this->noteLoginFailure('auto', $username === '' ? 'empty' : $username);
            $this->appendSecurityLog('login', 'error', 'Login rejected because username/password were empty or malformed.', array('username' => $username, 'ip' => $this->clientIp()));
            return $this->renderAuth('login.php', array('errors' => array('login' => array('Invalid credentials or disabled account.')), 'old' => array('username' => $username)));
        }

        $limit = $this->assertLoginRateAllowed('auto', $username);
        if (!$limit['ok']) {
            $this->appendSecurityLog('login', 'error', 'Login rate limit hit.', array('username' => $username, 'ip' => $this->clientIp(), 'message' => $limit['message']));
            return $this->renderAuth('login.php', array('errors' => array('login' => array($limit['message'])), 'old' => array('username' => $username)));
        }

        $record = $this->store->findBy('admins', 'username', $username);
        $role = 'admin';
        if (!$record) {
            $record = $this->store->findBy('resellers', 'username', $username);
            $role = 'reseller';
        }
        if (!$record || (isset($record['status']) && $record['status'] !== 'active') || !password_verify($password, isset($record['password_hash']) ? $record['password_hash'] : '')) {
            $this->noteLoginFailure('auto', $username);
            $this->appendSecurityLog('login', 'error', 'Login failed.', array('username' => $username, 'resolved_role' => $record ? $role : '', 'ip' => $this->clientIp()));
            return $this->renderAuth('login.php', array('errors' => array('login' => array('Invalid credentials or disabled account.')), 'old' => array('username' => $username)));
        }

        $this->clearLoginFailure('auto', $username);
        session_regenerate_id(true);
        $_SESSION['auth'] = array('id' => $record['id'], 'role' => $role, 'username' => $record['username'], 'display_name' => isset($record['display_name']) ? $record['display_name'] : $record['username']);
        $this->appendSecurityLog('login', 'access', 'Login succeeded.', array('username' => $username, 'role' => $role, 'ip' => $this->clientIp()));
        $this->flash('success', 'Welcome back.');
        $this->redirect($role === 'admin' ? '/admin/dashboard' : '/reseller/dashboard');
    }

    public function logout()
    {
        if (!empty($_SESSION['auth'])) {
            $known = isset($_SESSION['_csrf']) ? (string) $_SESSION['_csrf'] : '';
            $provided = trim((string) $this->input('_token', ''));
            if ($provided === '' || $known === '' || !hash_equals($known, $provided)) {
                $this->appendSecurityLog('logout', 'error', 'Logout token validation failed.', array('ip' => $this->clientIp(), 'method' => $this->requestMethod));
                $this->abort(419, 'Invalid security token.');
            }
        }
        unset($_SESSION['auth']);
        session_regenerate_id(true);
        $this->flash('info', 'You have been signed out.');
        $this->redirect('/login');
    }

    protected function adminDashboard()
    {
        $resellers = $this->store->all('resellers');
        $nodes = $this->store->all('nodes');
        $templates = $this->store->all('templates');
        $customers = $this->store->all('customers');
        $openTickets = $this->store->filterBy('tickets', function ($r) { return isset($r['status']) && $r['status'] !== 'closed'; });
        $creditTotal = 0; foreach ($resellers as $item) { $creditTotal += (float) (isset($item['credit_gb']) ? $item['credit_gb'] : 0); }
        usort($resellers, array($this, 'sortNewest'));
        $this->renderPanel('admin_dashboard.php', array(
            'title' => 'Dashboard',
            'stats' => array('resellers' => count($resellers), 'nodes' => count($nodes), 'templates' => count($templates), 'customers' => count($customers), 'tickets' => count($openTickets), 'credit_gb' => $creditTotal),
            'recent_resellers' => array_slice($resellers, 0, 6),
        ));
    }

    protected function adminResellers()
    {
        $resellers = $this->store->all('resellers'); usort($resellers, array($this, 'sortNewest'));
        $templates = $this->sortedTemplates();
        $map = array(); foreach ($templates as $t) { $map[$t['id']] = $t; }
        $this->renderPanel('admin_resellers.php', array('title' => 'Resellers', 'resellers' => $resellers, 'template_map' => $map));
    }

    protected function adminResellerForm($mode, $id = null)
    {
        $record = array('username' => '', 'display_name' => '', 'prefix' => '', 'credit_gb' => '0', 'fixed_duration_days' => $this->defaultDurationDays(), 'max_expiration_days' => $this->defaultDurationDays(), 'max_ip_limit' => 0, 'min_customer_traffic_gb' => '0', 'max_customer_traffic_gb' => '0', 'allow_fractional_traffic_gb' => 1, 'allow_xui_traffic_edit' => 1, 'status' => 'active', 'restrict' => 0, 'notes' => '', 'api_key' => '', 'regenerate_api_key' => 0, 'allowed_template_ids' => array());
        $errors = array();
        if ($mode === 'edit') {
            $found = $this->store->find('resellers', $id);
            if (!$found) { $this->flash('error', 'Reseller not found.'); $this->redirect('/admin/resellers'); }
            if (!isset($found['max_expiration_days'])) { $found['max_expiration_days'] = isset($found['fixed_duration_days']) ? (int) $found['fixed_duration_days'] : $this->defaultDurationDays(); }
            if (!isset($found['max_ip_limit'])) { $found['max_ip_limit'] = 0; }
            if (!isset($found['min_customer_traffic_gb'])) { $found['min_customer_traffic_gb'] = '0'; }
            if (!isset($found['max_customer_traffic_gb'])) { $found['max_customer_traffic_gb'] = '0'; }
            if (!isset($found['allow_fractional_traffic_gb'])) { $found['allow_fractional_traffic_gb'] = 1; }
            if (!isset($found['allow_xui_traffic_edit'])) { $found['allow_xui_traffic_edit'] = 1; }
            $record = array_merge($record, $found);
        }
        $this->renderPanel('admin_reseller_form.php', array('title' => $mode === 'edit' ? 'Edit reseller' : 'Create reseller', 'mode' => $mode, 'record' => $record, 'errors' => $errors, 'templates' => $this->sortedTemplates()));
    }

    protected function saveReseller($id = null)
    {
        $mode = $id ? 'edit' : 'create';
        $existing = $id ? $this->store->find('resellers', $id) : null;
        if ($id && !$existing) { $this->flash('error', 'Reseller not found.'); $this->redirect('/admin/resellers'); }
        $allowed = isset($_POST['allowed_template_ids']) ? (array) $_POST['allowed_template_ids'] : array();
        $data = array('username' => trim((string) $this->input('username', '')), 'display_name' => trim((string) $this->input('display_name', '')), 'password' => (string) $this->input('password', ''), 'prefix' => trim((string) $this->input('prefix', '')), 'credit_gb' => trim((string) $this->input('credit_gb', '0')), 'fixed_duration_days' => trim((string) $this->input('fixed_duration_days', (string) $this->defaultDurationDays())), 'max_expiration_days' => trim((string) $this->input('max_expiration_days', (string) $this->input('fixed_duration_days', (string) $this->defaultDurationDays()))), 'max_ip_limit' => trim((string) $this->input('max_ip_limit', '0')), 'min_customer_traffic_gb' => trim((string) $this->input('min_customer_traffic_gb', $existing && isset($existing['min_customer_traffic_gb']) ? (string) $existing['min_customer_traffic_gb'] : '0')), 'max_customer_traffic_gb' => trim((string) $this->input('max_customer_traffic_gb', $existing && isset($existing['max_customer_traffic_gb']) ? (string) $existing['max_customer_traffic_gb'] : '0')), 'allow_fractional_traffic_gb' => isset($_POST['allow_fractional_traffic_gb']) ? 1 : 0, 'allow_xui_traffic_edit' => isset($_POST['allow_xui_traffic_edit']) ? 1 : 0,  'telegram_user_id' => trim((string) $this->input('telegram_user_id', $existing && isset($existing['telegram_user_id']) ? $existing['telegram_user_id'] : '')), 'status' => trim((string) $this->input('status', 'active')), 'restrict' => isset($_POST['restrict']) ? 1 : 0, 'notes' => trim((string) $this->input('notes', '')), 'api_key' => trim((string) $this->input('api_key', $existing && isset($existing['api_key']) ? $existing['api_key'] : '')), 'regenerate_api_key' => isset($_POST['regenerate_api_key']) ? 1 : 0, 'allowed_template_ids' => array_values($allowed));
        $errors = array();
        if (!preg_match('/^[a-zA-Z0-9_-]{3,32}$/', $data['username'])) { $errors['username'][] = 'Username may contain only letters, numbers, dash, and underscore.'; }
        if (strlen($data['display_name']) < 2) { $errors['display_name'][] = 'Display name must be at least 2 characters.'; }
        if ($mode === 'create' && strlen($data['password']) < 8) { $errors['password'][] = 'Password must be at least 8 characters.'; }
        if ($mode === 'edit' && $data['password'] !== '' && strlen($data['password']) < 8) { $errors['password'][] = 'Password must be at least 8 characters.'; }
        if (!preg_match('/^[a-zA-Z0-9_-]{2,20}$/', $data['prefix'])) { $errors['prefix'][] = 'Prefix is invalid.'; }
        if (!is_numeric($data['credit_gb']) || (float) $data['credit_gb'] < 0) { $errors['credit_gb'][] = 'Credit must be a non-negative number.'; }
        if (!ctype_digit($data['fixed_duration_days']) || (int) $data['fixed_duration_days'] < 1) { $errors['fixed_duration_days'][] = 'Duration must be a positive integer.'; }
        if (!ctype_digit($data['max_expiration_days']) || (int) $data['max_expiration_days'] < 0) { $errors['max_expiration_days'][] = 'Max expiration days must be zero or a positive integer.'; }
        if (!ctype_digit($data['max_ip_limit']) || (int) $data['max_ip_limit'] < 0) { $errors['max_ip_limit'][] = 'Max IP limit must be zero or a positive integer.'; }
        if (!is_numeric($data['min_customer_traffic_gb']) || (float) $data['min_customer_traffic_gb'] < 0) { $errors['min_customer_traffic_gb'][] = 'Minimum customer traffic must be zero or a positive number.'; }
        if (!is_numeric($data['max_customer_traffic_gb']) || (float) $data['max_customer_traffic_gb'] < 0) { $errors['max_customer_traffic_gb'][] = 'Maximum customer traffic must be zero or a positive number.'; }
        if (is_numeric($data['min_customer_traffic_gb']) && is_numeric($data['max_customer_traffic_gb']) && (float) $data['max_customer_traffic_gb'] > 0 && (float) $data['min_customer_traffic_gb'] > (float) $data['max_customer_traffic_gb']) { $errors['max_customer_traffic_gb'][] = 'Maximum customer traffic must be greater than or equal to the minimum.'; }
        if (!$data['allow_fractional_traffic_gb']) {
            if (is_numeric($data['min_customer_traffic_gb']) && !$this->gbValueIsWhole($data['min_customer_traffic_gb'])) { $errors['min_customer_traffic_gb'][] = 'Minimum customer traffic must be a whole GB value when fractional traffic is disabled.'; }
            if (is_numeric($data['max_customer_traffic_gb']) && !$this->gbValueIsWhole($data['max_customer_traffic_gb'])) { $errors['max_customer_traffic_gb'][] = 'Maximum customer traffic must be a whole GB value when fractional traffic is disabled.'; }
        }
        if ($data['telegram_user_id'] !== '' && !ctype_digit($data['telegram_user_id'])) { $errors['telegram_user_id'][] = 'Telegram user ID must contain digits only.'; }
        if (!in_array($data['status'], array('active', 'disabled'), true)) { $errors['status'][] = 'Status is invalid.'; }
        $dup = $this->store->findBy('resellers', 'username', $data['username']);
        if ($dup && (!$id || $dup['id'] !== $id)) { $errors['username'][] = 'Username already exists.'; }
        if ($errors) { return $this->renderPanel('admin_reseller_form.php', array('title' => $mode === 'edit' ? 'Edit reseller' : 'Create reseller', 'mode' => $mode, 'record' => $data, 'errors' => $errors, 'templates' => $this->sortedTemplates())); }
        $payload = array('username' => $data['username'], 'display_name' => $data['display_name'], 'prefix' => $data['prefix'], 'credit_gb' => (float) $data['credit_gb'], 'fixed_duration_days' => (int) $data['fixed_duration_days'], 'max_expiration_days' => (int) $data['max_expiration_days'], 'max_ip_limit' => (int) $data['max_ip_limit'], 'min_customer_traffic_gb' => round((float) $data['min_customer_traffic_gb'], 2), 'max_customer_traffic_gb' => round((float) $data['max_customer_traffic_gb'], 2), 'allow_fractional_traffic_gb' => $data['allow_fractional_traffic_gb'] ? 1 : 0, 'allow_xui_traffic_edit' => $data['allow_xui_traffic_edit'] ? 1 : 0, 'telegram_user_id' => $data['telegram_user_id'], 'status' => $data['status'], 'restrict' => $data['restrict'] ? 1 : 0, 'notes' => $data['notes'], 'allowed_template_ids' => $data['allowed_template_ids']);
        if (!empty($data['api_key'])) { $payload['api_key'] = $data['api_key']; }
        if ($mode === 'create' || $data['regenerate_api_key']) { $payload['api_key'] = panel_random_hex(48); }
        if ($data['password'] !== '') { $payload['password_hash'] = panel_password_hash($data['password']); }
        if ($id) {
            $oldCredit = isset($existing['credit_gb']) ? round((float) $existing['credit_gb'], 2) : 0.0;
            $newCredit = round((float) $payload['credit_gb'], 2);
            $payload['credit_gb'] = $newCredit;
            $this->store->update('resellers', $id, $payload);
            $deltaCredit = round($newCredit - $oldCredit, 2);
            if (abs($deltaCredit) >= 0.00001) {
                $this->store->insert('credit_ledger', array(
                    'reseller_id' => $id,
                    'amount_gb' => $deltaCredit,
                    'type' => $deltaCredit >= 0 ? 'admin_edit_add' : 'admin_edit_deduct',
                    'note' => 'Reseller credit edited in admin form by ' . $this->currentActorLabel() . ' (' . panel_format_gb($oldCredit) . ' -> ' . panel_format_gb($newCredit) . ' GB)',
                ), 'led');
            }
            $this->log('reseller.updated', array('reseller_id' => $id, 'credit_delta_gb' => $deltaCredit));
        }
        else {
            $payload['credit_gb'] = round((float) $payload['credit_gb'], 2);
            $created = $this->store->insert('resellers', $payload, 'rsl');
            $this->store->insert('credit_ledger', array('reseller_id' => $created['id'], 'amount_gb' => (float) $payload['credit_gb'], 'type' => 'initial', 'note' => 'Initial credit'), 'led');
            $this->log('reseller.created', array('username' => $payload['username']));
        }
        $this->flash('success', $id ? 'Reseller updated successfully.' : 'Reseller created successfully.');
        $this->redirect('/admin/resellers');
    }

    protected function adjustResellerCredit($id)
    {
        $reseller = $this->store->find('resellers', $id);
        if (!$reseller) { $this->flash('error', 'Reseller not found.'); $this->redirect('/admin/resellers'); }
        $amount = trim((string) $this->input('amount_gb', '0'));
        $note = trim((string) $this->input('note', ''));
        if (!is_numeric($amount)) { $this->flash('error', 'Credit amount is invalid.'); $this->redirect('/admin/resellers'); }
        $delta = round((float) $amount, 2);
        if (abs($delta) < 0.00001) { $this->flash('error', 'Credit amount must not be zero.'); $this->redirect('/admin/resellers'); }
        $old = round((float) $reseller['credit_gb'], 2);
        $new = round($old + $delta, 2);
        if ($new < 0) { $this->flash('error', 'Credit cannot go below zero.'); $this->redirect('/admin/resellers'); }
        $actorLabel = $this->currentActorLabel();
        if ($note === '') {
            $note = 'Manual adjustment by ' . $actorLabel;
        } else {
            $note .= ' (by ' . $actorLabel . ')';
        }
        $this->store->update('resellers', $id, array('credit_gb' => $new));
        $this->store->insert('credit_ledger', array('reseller_id' => $id, 'amount_gb' => $delta, 'type' => $delta >= 0 ? 'admin_add' : 'admin_deduct', 'note' => $note), 'led');
        $this->log('reseller.credit_adjusted', array('reseller_id' => $id, 'delta_gb' => $delta, 'old_credit_gb' => $old, 'new_credit_gb' => $new, 'actor' => $actorLabel));
        $this->flash('success', 'Reseller credit updated.');
        $this->redirect('/admin/resellers');
    }

    protected function toggleEntity($collection, $id, $redirect, $successMessage)
    {
        $record = $this->store->find($collection, $id);
        if (!$record) { $this->flash('error', 'Record not found.'); $this->redirect($redirect); }
        $current = isset($record['status']) ? (string) $record['status'] : 'active';
        $next = $current === 'active' ? 'disabled' : 'active';
        $this->store->update($collection, $id, array('status' => $next));
        $this->flash('success', $successMessage);
        $this->redirect($redirect);
    }

    protected function deleteReseller($id)
    {
        $customers = $this->store->filterBy('customers', function ($item) use ($id) { return isset($item['reseller_id']) && $item['reseller_id'] === $id; });
        if ($customers) { $this->flash('error', 'Delete reseller customers first.'); $this->redirect('/admin/resellers'); }
        $this->store->delete('resellers', $id);
        $this->flash('success', 'Reseller deleted.');
        $this->redirect('/admin/resellers');
    }

    protected function adminNodes()
    {
        $nodes = $this->sortedNodes();
        $this->renderPanel('admin_nodes.php', array('title' => 'Servers', 'nodes' => $nodes));
    }

    protected function adminNodeForm($mode, $id = null)
    {
        $record = array('title' => '', 'slug' => '', 'server_type' => 'xui', 'base_url' => '', 'panel_path' => '/panel', 'subscription_base' => '', 'panel_username' => '', 'panel_password' => '', 'xui_api_token' => '', 'xui_request_host' => '', 'xui_request_sni' => '', 'xui_proxy_type' => 'http', 'xui_proxy_host' => '', 'xui_proxy_port' => '', 'xui_proxy_username' => '', 'xui_proxy_password' => '', 'xui_proxy_password_hint' => '', 'request_timeout' => '20', 'connect_timeout' => '8', 'retry_attempts' => '2', 'allow_insecure_tls' => false, 'status' => 'active', 'notes' => '', 'utility_links_text' => '', 'utility_links_json' => '[]', 'um_connection_mode' => 'text', 'um_connection_text' => '', 'um_connection_file_url' => '', 'um_connection_file_name' => '', 'um_api_mode' => 'rest', 'um_api_host' => '', 'um_api_port' => '', 'um_api_ssl' => false);
        if ($mode === 'edit') {
            $found = $this->store->find('nodes', $id);
            if (!$found) { $this->flash('error', 'Server not found.'); $this->redirect('/admin/nodes'); }
            $record = array_replace($record, $found);
        }
        $record['server_type'] = $this->nodeServerType($record);
        if (empty($record['utility_links_text']) && !empty($record['utility_links_json'])) {
            $record['utility_links_text'] = $this->utilityLinksTextFromJson($record['utility_links_json']);
        }
        $this->renderPanel('admin_node_form.php', array('title' => $mode === 'edit' ? 'Edit server' : 'Add server', 'mode' => $mode, 'record' => $record, 'errors' => array(), 'node_ui_defaults' => $this->nodeUiDefaults()));
    }

    protected function saveNode($id = null)
    {
        $mode = $id ? 'edit' : 'create';
        $existing = $id ? $this->store->find('nodes', $id) : null;
        if ($id && !$existing) { $this->flash('error', 'Server not found.'); $this->redirect('/admin/nodes'); }
        $data = array(
            'title' => trim((string) $this->input('title', '')),
            'slug' => trim((string) $this->input('slug', '')),
            'server_type' => $this->normalizeServerType($this->input('server_type', $existing ? panel_array_get($existing, 'server_type', 'xui') : 'xui')),
            'base_url' => rtrim(trim((string) $this->input('base_url', '')), '/'),
            'panel_path' => trim((string) $this->input('panel_path', '/panel')),
            'subscription_base' => trim((string) $this->input('subscription_base', '')),
            'panel_username' => trim((string) $this->input('panel_username', '')),
            'panel_password' => (string) $this->input('panel_password', ''),
            'xui_api_token' => trim((string) $this->input('xui_api_token', '')),
            'xui_request_host' => $this->normalizeAuthorityLikeField($this->input('xui_request_host', $existing ? panel_array_get($existing, 'xui_request_host', '') : ''), true),
            'xui_request_sni' => $this->normalizeAuthorityLikeField($this->input('xui_request_sni', $existing ? panel_array_get($existing, 'xui_request_sni', '') : ''), false),
            'xui_proxy_type' => trim((string) $this->input('xui_proxy_type', $existing ? panel_array_get($existing, 'xui_proxy_type', 'http') : 'http')),
            'xui_proxy_host' => trim((string) $this->input('xui_proxy_host', $existing ? panel_array_get($existing, 'xui_proxy_host', '') : '')),
            'xui_proxy_port' => trim((string) $this->input('xui_proxy_port', $existing ? panel_array_get($existing, 'xui_proxy_port', '') : '')),
            'xui_proxy_username' => trim((string) $this->input('xui_proxy_username', $existing ? panel_array_get($existing, 'xui_proxy_username', '') : '')),
            'xui_proxy_password' => (string) $this->input('xui_proxy_password', ''),
            'request_timeout' => trim((string) $this->input('request_timeout', '20')),
            'connect_timeout' => trim((string) $this->input('connect_timeout', '8')),
            'retry_attempts' => trim((string) $this->input('retry_attempts', '2')),
            'allow_insecure_tls' => panel_parse_bool($this->input('allow_insecure_tls', ''), false),
            'status' => trim((string) $this->input('status', 'active')),
            'notes' => trim((string) $this->input('notes', '')),
            'utility_links_text' => trim((string) $this->input('utility_links_text', $existing ? $this->utilityLinksTextFromJson(panel_array_get($existing, 'utility_links_json', '[]')) : '')),
            'um_connection_mode' => trim((string) $this->input('um_connection_mode', 'text')),
            'um_connection_text' => trim((string) $this->input('um_connection_text', '')),
            'um_connection_file_url' => trim((string) $this->input('um_connection_file_url', '')),
            'um_connection_file_name' => trim((string) $this->input('um_connection_file_name', '')),
            'um_api_mode' => trim((string) $this->input('um_api_mode', $existing ? panel_array_get($existing, 'um_api_mode', 'rest') : 'rest')),
            'um_api_host' => trim((string) $this->input('um_api_host', $existing ? panel_array_get($existing, 'um_api_host', '') : '')),
            'um_api_port' => trim((string) $this->input('um_api_port', $existing ? panel_array_get($existing, 'um_api_port', '') : '')),
            'um_api_ssl' => panel_parse_bool($this->input('um_api_ssl', ''), false),
        );
        $errors = array();
        if (strlen($data['title']) < 2) { $errors['title'][] = 'Title must be at least 2 characters.'; }
        if (!preg_match('/^[a-zA-Z0-9_-]{2,30}$/', $data['slug'])) { $errors['slug'][] = 'Slug is invalid.'; }
        if ($data['base_url'] === '' || filter_var($data['base_url'], FILTER_VALIDATE_URL) === false) { $errors['base_url'][] = 'Base URL is invalid.'; }
        if ($data['server_type'] === 'xui' && $data['subscription_base'] !== '' && filter_var($data['subscription_base'], FILTER_VALIDATE_URL) === false) { $errors['subscription_base'][] = 'Subscription base URL is invalid.'; }
        if ($data['server_type'] === 'xui' && $data['xui_api_token'] === '' && strlen($data['panel_username']) < 2) { $errors['panel_username'][] = 'Panel username is required when API token is empty.'; }
        if ($data['server_type'] === 'xui' && $mode === 'create' && $data['xui_api_token'] === '' && strlen($data['panel_password']) < 1) { $errors['panel_password'][] = 'Panel password is required when API token is empty.'; }
        if ($data['server_type'] === 'xui' && !in_array($data['xui_proxy_type'], array('http', 'https', 'socks5'), true)) { $errors['xui_proxy_type'][] = 'XUI proxy type is invalid.'; }
        if ($data['server_type'] === 'xui' && $data['xui_proxy_host'] !== '' && preg_match('/[\s\/\?#]/', $data['xui_proxy_host'])) { $errors['xui_proxy_host'][] = 'XUI proxy host is invalid.'; }
        if ($data['server_type'] === 'xui' && $data['xui_proxy_port'] !== '' && (!ctype_digit($data['xui_proxy_port']) || (int) $data['xui_proxy_port'] < 1 || (int) $data['xui_proxy_port'] > 65535)) { $errors['xui_proxy_port'][] = 'XUI proxy port must be between 1 and 65535.'; }
        if ($data['server_type'] === 'xui' && (($data['xui_proxy_host'] === '') xor ($data['xui_proxy_port'] === ''))) { $errors['xui_proxy_host'][] = 'XUI proxy host and port must both be filled or both be empty.'; }
        if ($data['server_type'] === 'um' && strlen($data['panel_username']) < 2) { $errors['panel_username'][] = 'Panel username is required.'; }
        if ($data['server_type'] === 'um' && $mode === 'create' && strlen($data['panel_password']) < 1) { $errors['panel_password'][] = 'Panel password is required.'; }
        if (!ctype_digit($data['request_timeout']) || (int) $data['request_timeout'] < 5) { $errors['request_timeout'][] = 'Request timeout must be at least 5 seconds.'; }
        if (!ctype_digit($data['connect_timeout']) || (int) $data['connect_timeout'] < 3) { $errors['connect_timeout'][] = 'Connect timeout must be at least 3 seconds.'; }
        if (!ctype_digit($data['retry_attempts']) || (int) $data['retry_attempts'] < 1 || (int) $data['retry_attempts'] > 5) { $errors['retry_attempts'][] = 'Retry attempts must be between 1 and 5.'; }
        if (!in_array($data['status'], array('active', 'disabled'), true)) { $errors['status'][] = 'Status is invalid.'; }
        if (!in_array($data['um_connection_mode'], array('text', 'file'), true)) { $errors['um_connection_mode'][] = 'Connection mode is invalid.'; }
        if (!in_array($data['um_api_mode'], array('rest', 'internal'), true)) { $errors['um_api_mode'][] = 'UM API mode is invalid.'; }
        $utilityLinks = $this->parseNodeUtilityLinks($data['utility_links_text'], $errors);
        if ($data['server_type'] === 'um') {
            if ($data['um_connection_mode'] === 'text' && $data['um_connection_text'] === '') { $errors['um_connection_text'][] = 'Connection text is required for UM text mode.'; }
            if ($data['um_connection_mode'] === 'file' && ($data['um_connection_file_url'] === '' || filter_var($data['um_connection_file_url'], FILTER_VALIDATE_URL) === false)) { $errors['um_connection_file_url'][] = 'Connection file URL is invalid.'; }
            if ($data['um_api_host'] !== '' && !preg_match('/^[a-zA-Z0-9._:-]+$/', $data['um_api_host'])) { $errors['um_api_host'][] = 'UM API host is invalid.'; }
            if ($data['um_api_port'] !== '' && (!ctype_digit($data['um_api_port']) || (int) $data['um_api_port'] < 1 || (int) $data['um_api_port'] > 65535)) { $errors['um_api_port'][] = 'UM API port must be between 1 and 65535.'; }
        }
        $dup = $this->store->findBy('nodes', 'slug', $data['slug']); if ($dup && (!$id || $dup['id'] !== $id)) { $errors['slug'][] = 'Slug already exists.'; }
        if ($errors) { return $this->renderPanel('admin_node_form.php', array('title' => $mode === 'edit' ? 'Edit server' : 'Add server', 'mode' => $mode, 'record' => $data, 'errors' => $errors, 'node_ui_defaults' => $this->nodeUiDefaults())); }
        $payload = array(
            'title' => $data['title'],
            'slug' => $data['slug'],
            'server_type' => $data['server_type'],
            'base_url' => $data['base_url'],
            'panel_path' => $data['panel_path'],
            'subscription_base' => $data['server_type'] === 'xui' ? (rtrim($data['subscription_base'], '/') . ($data['subscription_base'] !== '' ? '/' : '')) : '',
            'panel_username' => $data['panel_username'],
            'request_timeout' => (int) $data['request_timeout'],
            'xui_api_token_hint' => $data['server_type'] === 'xui' && $data['xui_api_token'] !== '' ? 'configured' : ($existing && !empty($existing['xui_api_token']) ? 'configured' : ''),
            'xui_request_host' => $data['server_type'] === 'xui' ? $data['xui_request_host'] : '',
            'xui_request_sni' => $data['server_type'] === 'xui' ? $data['xui_request_sni'] : '',
            'xui_proxy_type' => $data['server_type'] === 'xui' ? $data['xui_proxy_type'] : 'http',
            'xui_proxy_host' => $data['server_type'] === 'xui' ? $data['xui_proxy_host'] : '',
            'xui_proxy_port' => $data['server_type'] === 'xui' && $data['xui_proxy_port'] !== '' ? (int) $data['xui_proxy_port'] : 0,
            'xui_proxy_username' => $data['server_type'] === 'xui' ? $data['xui_proxy_username'] : '',
            'xui_proxy_password_hint' => $data['server_type'] === 'xui' && (($data['xui_proxy_host'] !== '' && $data['xui_proxy_password'] !== '') || ($existing && !empty($existing['xui_proxy_password']) && $data['xui_proxy_host'] !== '')) ? 'configured' : '',
            'connect_timeout' => (int) $data['connect_timeout'],
            'retry_attempts' => (int) $data['retry_attempts'],
            'allow_insecure_tls' => $data['allow_insecure_tls'],
            'status' => $data['status'],
            'notes' => $data['notes'],
            'utility_links_text' => $data['utility_links_text'],
            'utility_links_json' => json_encode($utilityLinks, JSON_UNESCAPED_SLASHES),
            'um_connection_mode' => $data['server_type'] === 'um' ? $data['um_connection_mode'] : 'text',
            'um_connection_text' => $data['server_type'] === 'um' ? $data['um_connection_text'] : '',
            'um_connection_file_url' => $data['server_type'] === 'um' ? $data['um_connection_file_url'] : '',
            'um_connection_file_name' => $data['server_type'] === 'um' ? $data['um_connection_file_name'] : '',
            'um_api_mode' => $data['server_type'] === 'um' ? $data['um_api_mode'] : 'rest',
            'um_api_host' => $data['server_type'] === 'um' ? $data['um_api_host'] : '',
            'um_api_port' => $data['server_type'] === 'um' ? $data['um_api_port'] : '',
            'um_api_ssl' => $data['server_type'] === 'um' ? $data['um_api_ssl'] : false,
        );
        if ($data['panel_password'] !== '') { $payload['panel_password'] = $this->encrypt($data['panel_password']); }
        if ($data['server_type'] === 'xui') {
            if ($data['xui_api_token'] !== '') {
                $payload['xui_api_token'] = $this->encrypt($data['xui_api_token']);
            } elseif ($existing && isset($existing['xui_api_token'])) {
                $payload['xui_api_token'] = $existing['xui_api_token'];
            }
            if ($data['xui_proxy_host'] !== '' && $data['xui_proxy_password'] !== '') {
                $payload['xui_proxy_password'] = $this->encrypt($data['xui_proxy_password']);
            } elseif ($data['xui_proxy_host'] !== '' && $existing && isset($existing['xui_proxy_password'])) {
                $payload['xui_proxy_password'] = $existing['xui_proxy_password'];
            } else {
                $payload['xui_proxy_password'] = '';
                $payload['xui_proxy_password_hint'] = '';
            }
        } else {
            $payload['xui_api_token'] = '';
            $payload['xui_api_token_hint'] = '';
            $payload['xui_proxy_password'] = '';
            $payload['xui_proxy_password_hint'] = '';
            $payload['xui_proxy_host'] = '';
            $payload['xui_proxy_port'] = 0;
            $payload['xui_proxy_username'] = '';
            $payload['xui_proxy_type'] = 'http';
        }
        if ($id) { $this->store->update('nodes', $id, $payload); } else { $this->store->insert('nodes', $payload, 'nod'); }
        $this->flash('success', $id ? 'Server updated successfully.' : 'Server saved successfully.');
        $this->redirect('/admin/nodes');
    }

    protected function deleteNode($id)
    {
        $templates = $this->store->filterBy('templates', function ($item) use ($id) { return isset($item['node_id']) && $item['node_id'] === $id; });
        if ($templates) { $this->flash('error', 'Delete related templates first.'); $this->redirect('/admin/nodes'); }
        $this->store->delete('nodes', $id);
        $this->flash('success', 'Server deleted.');
        $this->redirect('/admin/nodes');
    }

    protected function testNode($id)
    {
        $node = $this->store->find('nodes', $id);
        if (!$node) { $this->flash('error', 'Server not found.'); $this->redirect('/admin/nodes'); }
        $adapter = $this->nodeAdapter($node);
        $result = $adapter->ping();
        $message = $result['message'];
        if ($this->isUmNode($node)) {
            $mode = strtolower(trim((string) panel_array_get($node, 'um_api_mode', 'rest')));
            if ($mode === 'internal') {
                $message .= ' [Mode: ' . (!empty($node['um_api_ssl']) ? 'Internal API-SSL' : 'Internal API') . ']';
            } else {
                $parts = @parse_url((string) panel_array_get($node, 'base_url', ''));
                $scheme = (is_array($parts) && !empty($parts['scheme'])) ? strtoupper((string) $parts['scheme']) : 'HTTPS';
                $message .= ' [Mode: REST ' . $scheme . ']';
            }
        }
        if ($result['ok'] && isset($result['data']['inbounds']) && is_array($result['data']['inbounds'])) { $message .= ' Inbounds detected: ' . count($result['data']['inbounds']); }
        if ($this->isUmNode($node)) {
            $this->logUmEvent($result['ok'] ? 'access' : 'error', 'UM server test finished.', array('node_id' => $node['id'], 'slug' => panel_array_get($node, 'slug', ''), 'message' => $message));
        }
        $this->flash($result['ok'] ? 'success' : 'error', $message);
        $this->redirect('/admin/nodes');
    }

    protected function importNodeInbounds($id)
    {
        $node = $this->store->find('nodes', $id);
        if (!$node) { $this->flash('error', 'Server not found.'); $this->redirect('/admin/nodes'); }
        if ($this->isUmNode($node)) { $this->flash('error', 'Inbound import is available only for XUI servers. Create UM profiles manually.'); $this->redirect('/admin/nodes'); }
        $adapter = $this->nodeAdapter($node);
        $result = $adapter->listInbounds();
        if (!$result['ok'] || !is_array($result['data'])) {
            $this->flash('error', 'Could not load inbounds from node. ' . $result['message']);
            $this->redirect('/admin/nodes');
        }
        $imported = 0;
        $updated = 0;
        foreach ($result['data'] as $row) {
            if (!is_array($row) || !isset($row['id'])) { continue; }
            $title = isset($row['remark']) && trim((string) $row['remark']) !== '' ? trim((string) $row['remark']) : ('Inbound ' . $row['id']);
            $settings = panel_json_field($row, 'settings');
            $streamSettings = panel_json_field($row, 'streamSettings');
            $sniffing = panel_json_field($row, 'sniffing');
            $payload = array(
                'title' => $title,
                'public_label' => $title,
                'node_id' => $id,
                'inbound_id' => (string) $row['id'],
                'inbound_name' => $title,
                'protocol' => isset($row['protocol']) ? strtolower((string) $row['protocol']) : 'vless',
                'sort_order' => 10 + $imported + $updated,
                'status' => 'active',
                'listen' => isset($row['listen']) ? (string) $row['listen'] : '',
                'port' => isset($row['port']) ? (string) $row['port'] : '',
                'settings_json' => isset($row['settings']) ? (string) $row['settings'] : json_encode($settings),
                'stream_settings_json' => isset($row['streamSettings']) ? (string) $row['streamSettings'] : json_encode($streamSettings),
                'sniffing_json' => isset($row['sniffing']) ? (string) $row['sniffing'] : json_encode($sniffing),
                'network' => (string) panel_array_get($streamSettings, 'network', ''),
                'security' => (string) panel_array_get($streamSettings, 'security', ''),
                'server_type' => 'xui',
                'client_extra_query' => '',
                'notes' => 'Imported from node on ' . panel_now(),
                'raw_inbound_json' => json_encode($row, JSON_UNESCAPED_SLASHES),
            );
            $exists = null;
            $templates = $this->store->all('templates');
            foreach ($templates as $tpl) {
                if (isset($tpl['node_id'], $tpl['inbound_id']) && $tpl['node_id'] === $id && (string) $tpl['inbound_id'] === (string) $row['id']) { $exists = $tpl; break; }
            }
            if ($exists) {
                if (isset($exists['client_extra_query']) && trim((string) $exists['client_extra_query']) !== '') {
                    $payload['client_extra_query'] = $this->normalizeClientExtraQuery((string) $exists['client_extra_query']);
                }
                $this->store->update('templates', $exists['id'], $payload);
                $updated++;
            } else {
                $this->store->insert('templates', $payload, 'tpl');
                $imported++;
            }
        }
        $this->flash('success', 'Imported ' . $imported . ' inbound templates and updated ' . $updated . '.');
        $this->redirect('/admin/templates');
    }


    protected function importNodeProfiles($id)
    {
        $node = $this->store->find('nodes', $id);
        if (!$node) { $this->flash('error', 'Server not found.'); $this->redirect('/admin/nodes'); }
        if (!$this->isUmNode($node)) { $this->flash('error', 'Profile import is available only for UM servers.'); $this->redirect('/admin/nodes'); }
        $adapter = $this->nodeAdapter($node);
        if (!method_exists($adapter, 'listProfiles')) {
            $this->flash('error', 'UM adapter does not support profile import on this build.');
            $this->redirect('/admin/nodes');
        }
        $result = $adapter->listProfiles();
        if (!$result['ok'] || !is_array($result['data'])) {
            $this->logUmEvent('error', 'UM profile import failed.', array('node_id' => $node['id'], 'message' => panel_array_get($result, 'message', 'Could not load profiles.')));
            $this->flash('error', 'Could not load UM profiles from server. ' . $result['message']);
            $this->redirect('/admin/nodes');
        }
        $rows = $result['data'];
        if (!$rows) {
            $this->flash('error', 'No UM profiles were found on the selected server.');
            $this->redirect('/admin/nodes');
        }
        $templates = $this->store->all('templates');
        $imported = 0;
        $updated = 0;
        $position = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) { continue; }
            $profileId = trim((string) panel_array_get($row, '.id', panel_array_get($row, 'id', '')));
            $profileName = trim((string) panel_array_get($row, 'name', ''));
            if ($profileName === '') { continue; }
            $publicLabel = trim((string) panel_array_get($row, 'name-for-users', ''));
            if ($publicLabel === '') { $publicLabel = $profileName; }
            $existing = null;
            foreach ($templates as $tpl) {
                if ($this->templateServerType($tpl) !== 'um') { continue; }
                if (!isset($tpl['node_id']) || $tpl['node_id'] !== $id) { continue; }
                $tplProfileId = trim((string) panel_array_get($tpl, 'um_profile_id', ''));
                $tplProfileName = trim((string) panel_array_get($tpl, 'um_profile_name', ''));
                if (($profileId !== '' && $tplProfileId !== '' && $tplProfileId === $profileId) || ($tplProfileName !== '' && strcasecmp($tplProfileName, $profileName) === 0)) {
                    $existing = $tpl;
                    break;
                }
            }
            $billingGb = $existing ? (float) panel_array_get($existing, 'billing_gb', 1) : 1.0;
            if ($billingGb <= 0) {
                $billingGb = 1.0;
            }
            $sortOrder = $existing ? (int) panel_array_get($existing, 'sort_order', 10) : (10 + $position);
            $status = $existing ? trim((string) panel_array_get($existing, 'status', 'active')) : 'active';
            if (!in_array($status, array('active', 'disabled'), true)) {
                $status = 'active';
            }
            $noteParts = array();
            $noteParts[] = 'Imported from UM server on ' . panel_now();
            $startsWhen = trim((string) panel_array_get($row, 'starts-when', ''));
            $validity = trim((string) panel_array_get($row, 'validity', ''));
            $price = trim((string) panel_array_get($row, 'price', ''));
            if ($startsWhen !== '') { $noteParts[] = 'Starts when: ' . $startsWhen; }
            if ($validity !== '') { $noteParts[] = 'Validity: ' . $validity; }
            if ($price !== '') { $noteParts[] = 'Price: ' . $price; }
            $payload = array(
                'title' => $publicLabel,
                'public_label' => $publicLabel,
                'node_id' => $id,
                'server_type' => 'um',
                'inbound_id' => '',
                'inbound_name' => $profileName,
                'protocol' => 'um',
                'sort_order' => $sortOrder,
                'status' => $status,
                'listen' => '',
                'port' => '',
                'network' => '',
                'security' => '',
                'settings_json' => '{}',
                'stream_settings_json' => '{}',
                'sniffing_json' => '{}',
                'client_extra_query' => '',
                'notes' => implode("\n", $noteParts),
                'billing_gb' => round($billingGb, 2),
                'um_profile_id' => $profileId !== '' ? $profileId : $profileName,
                'um_profile_name' => $profileName,
            );
            if ($existing) {
                $this->store->update('templates', $existing['id'], $payload);
                $updated++;
            } else {
                $inserted = $this->store->insert('templates', $payload, 'tpl');
                if (is_array($inserted)) {
                    $templates[] = $inserted;
                } else {
                    $templates[] = $payload;
                }
                $imported++;
            }
            $position++;
        }
        $this->logUmEvent('access', 'UM profile import finished.', array('node_id' => $node['id'], 'imported' => $imported, 'updated' => $updated));
        $this->flash('success', 'Imported ' . $imported . ' UM profiles and updated ' . $updated . '. Imported profiles keep their current billing GB when re-imported; new ones default to 1 GB until you adjust them.');
        $this->redirect('/admin/templates');
    }

    protected function adminTemplates()
    {
        $templates = $this->sortedTemplates();
        $nodeMap = array(); foreach ($this->store->all('nodes') as $node) { $nodeMap[$node['id']] = $node; }
        $this->renderPanel('admin_templates.php', array('title' => 'Inbound templates', 'templates' => $templates, 'node_map' => $nodeMap));
    }

    protected function nodeUiDefaults()
    {
        return array(
            'xui' => array(
                'panel_path' => '/panel',
                'subscription_base' => '',
                'request_timeout' => '20',
                'connect_timeout' => '8',
                'retry_attempts' => '2',
                'allow_insecure_tls' => false,
                'xui_api_token' => '',
                'xui_request_host' => '',
                'xui_request_sni' => '',
                'xui_proxy_type' => 'http',
                'xui_proxy_host' => '',
                'xui_proxy_port' => '',
                'xui_proxy_username' => '',
                'xui_proxy_password' => '',
            ),
            'um' => array(
                'panel_path' => '/',
                'subscription_base' => '',
                'request_timeout' => '20',
                'connect_timeout' => '8',
                'retry_attempts' => '2',
                'allow_insecure_tls' => false,
                'um_api_mode' => 'rest',
                'um_api_host' => '',
                'um_api_port' => '',
                'um_api_ssl' => false,
                'um_connection_mode' => 'text',
                'um_connection_text' => "Username: {username}
Password: {password}
Profile: {profile}
Server: {server}",
                'um_connection_file_url' => '',
                'um_connection_file_name' => '',
            ),
        );
    }

    protected function normalizeAuthorityLikeField($value, $allowPort)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        if (preg_match('~^https?://~i', $value)) {
            $parts = @parse_url($value);
            if (is_array($parts) && !empty($parts['host'])) {
                $value = (string) $parts['host'];
                if ($allowPort && !empty($parts['port'])) {
                    $value .= ':' . (int) $parts['port'];
                }
            }
        }
        $value = preg_replace('~[/?#].*$~', '', $value);
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        if (!$allowPort) {
            if ($value !== '' && $value[0] === '[') {
                $end = strpos($value, ']');
                if ($end !== false) {
                    $value = substr($value, 1, $end - 1);
                }
            } elseif (substr_count($value, ':') === 1) {
                $parts = explode(':', $value, 2);
                if ($parts[0] !== '' && ctype_digit($parts[1])) {
                    $value = $parts[0];
                }
            }
        }
        if (preg_match('/\s/', $value)) {
            return '';
        }
        return $value;
    }

    protected function customerTypeContext($customer, $template = null, $node = null)
    {
        if (!is_array($customer)) {
            $customer = array();
        }
        $type = $this->customerServerType($customer, $template, $node);
        return array(
            'server_type' => $type,
            'template_id' => is_array($template) ? (string) panel_array_get($template, 'id', '') : '',
            'template_label' => is_array($template) ? (string) panel_array_get($template, 'public_label', panel_array_get($template, 'title', '')) : '',
            'node_id' => is_array($node) ? (string) panel_array_get($node, 'id', '') : '',
            'node_title' => is_array($node) ? (string) panel_array_get($node, 'title', '') : '',
            'profile_name' => $type === 'um' && is_array($template) ? (string) panel_array_get($template, 'um_profile_name', panel_array_get($template, 'public_label', '')) : '',
            'service_username' => $type === 'um' ? $this->customerServiceUsername($customer) : '',
        );
    }

    protected function adminTemplateForm($mode, $id = null)
    {
        $record = array('title' => '', 'public_label' => '', 'server_type' => 'xui', 'node_id' => '', 'inbound_id' => '', 'inbound_name' => '', 'protocol' => 'vless', 'sort_order' => '10', 'status' => 'active', 'listen' => '', 'port' => '', 'network' => '', 'security' => '', 'settings_json' => '', 'stream_settings_json' => '', 'sniffing_json' => '', 'client_extra_query' => '', 'notes' => '', 'billing_gb' => '1', 'um_profile_id' => '', 'um_profile_name' => '');
        if ($mode === 'edit') {
            $found = $this->store->find('templates', $id);
            if (!$found) { $this->flash('error', 'Template not found.'); $this->redirect('/admin/templates'); }
            $record = array_replace($record, $found);
        }
        $record['server_type'] = $this->templateServerType($record);
        $this->renderPanel('admin_template_form.php', array('title' => $mode === 'edit' ? 'Edit template' : 'Add template', 'mode' => $mode, 'record' => $record, 'errors' => array(), 'nodes' => $this->sortedNodes()));
    }

    protected function saveTemplate($id = null)
    {
        $mode = $id ? 'edit' : 'create';
        $existing = $id ? $this->store->find('templates', $id) : null;
        if ($id && !$existing) { $this->flash('error', 'Template not found.'); $this->redirect('/admin/templates'); }
        $data = array(
            'title' => trim((string) $this->input('title', '')),
            'public_label' => trim((string) $this->input('public_label', '')),
            'node_id' => trim((string) $this->input('node_id', '')),
            'server_type' => $this->normalizeServerType($this->input('server_type', $existing ? panel_array_get($existing, 'server_type', 'xui') : 'xui')),
            'inbound_id' => trim((string) $this->input('inbound_id', '')),
            'inbound_name' => trim((string) $this->input('inbound_name', '')),
            'protocol' => trim((string) $this->input('protocol', 'vless')),
            'sort_order' => trim((string) $this->input('sort_order', '10')),
            'status' => trim((string) $this->input('status', 'active')),
            'listen' => trim((string) $this->input('listen', isset($existing['listen']) ? $existing['listen'] : '')),
            'port' => trim((string) $this->input('port', isset($existing['port']) ? $existing['port'] : '')),
            'network' => trim((string) $this->input('network', isset($existing['network']) ? $existing['network'] : '')),
            'security' => trim((string) $this->input('security', isset($existing['security']) ? $existing['security'] : '')),
            'settings_json' => trim((string) $this->input('settings_json', isset($existing['settings_json']) ? $existing['settings_json'] : '')),
            'stream_settings_json' => trim((string) $this->input('stream_settings_json', isset($existing['stream_settings_json']) ? $existing['stream_settings_json'] : '')),
            'sniffing_json' => trim((string) $this->input('sniffing_json', isset($existing['sniffing_json']) ? $existing['sniffing_json'] : '')),
            'client_extra_query' => $this->normalizeClientExtraQuery((string) $this->input('client_extra_query', isset($existing['client_extra_query']) ? $existing['client_extra_query'] : '')),
            'notes' => trim((string) $this->input('notes', '')),
            'billing_gb' => trim((string) $this->input('billing_gb', isset($existing['billing_gb']) ? $existing['billing_gb'] : '1')),
            'um_profile_id' => trim((string) $this->input('um_profile_id', isset($existing['um_profile_id']) ? $existing['um_profile_id'] : '')),
            'um_profile_name' => trim((string) $this->input('um_profile_name', isset($existing['um_profile_name']) ? $existing['um_profile_name'] : '')),
        );
        $errors = array();
        $node = $this->store->find('nodes', $data['node_id']);
        if (strlen($data['title']) < 2) { $errors['title'][] = 'Title must be at least 2 characters.'; }
        if (strlen($data['public_label']) < 2) { $errors['public_label'][] = 'Public label must be at least 2 characters.'; }
        if (!$node) { $errors['node_id'][] = 'Select a valid server.'; }
        if (!preg_match('/^-?[0-9]+$/', $data['sort_order'])) { $errors['sort_order'][] = 'Sort order must be an integer.'; }
        if (!in_array($data['status'], array('active', 'disabled'), true)) { $errors['status'][] = 'Status is invalid.'; }
        $data['server_type'] = $node ? $this->nodeServerType($node) : $data['server_type'];
        if ($data['server_type'] === 'um') {
            if (!is_numeric($data['billing_gb']) || (float) $data['billing_gb'] <= 0) { $errors['billing_gb'][] = 'Billing GB must be greater than zero.'; }
            if ($data['um_profile_id'] === '') { $errors['um_profile_id'][] = 'UM profile ID is required.'; }
            if (strlen($data['um_profile_name']) < 1) { $errors['um_profile_name'][] = 'UM profile name is required.'; }
        } else {
            if ($data['inbound_id'] === '') { $errors['inbound_id'][] = 'Inbound ID is required.'; }
            if (strlen($data['inbound_name']) < 2) { $errors['inbound_name'][] = 'Inbound name must be at least 2 characters.'; }
            if ($data['protocol'] === '') { $errors['protocol'][] = 'Protocol is required.'; }
            if ($data['port'] !== '' && !ctype_digit($data['port'])) { $errors['port'][] = 'Port must be numeric.'; }
            if (!panel_is_valid_json_string($data['settings_json'])) { $errors['settings_json'][] = 'Settings JSON is invalid.'; }
            if (!panel_is_valid_json_string($data['stream_settings_json'])) { $errors['stream_settings_json'][] = 'Stream settings JSON is invalid.'; }
            if (!panel_is_valid_json_string($data['sniffing_json'])) { $errors['sniffing_json'][] = 'Sniffing JSON is invalid.'; }
            if (strlen($data['client_extra_query']) > 2000) { $errors['client_extra_query'][] = 'Manual client query parameters are too long.'; }
        }
        if ($errors) { return $this->renderPanel('admin_template_form.php', array('title' => $mode === 'edit' ? 'Edit template' : 'Add template', 'mode' => $mode, 'record' => $data, 'errors' => $errors, 'nodes' => $this->sortedNodes())); }
        $payload = array(
            'title' => $data['title'], 'public_label' => $data['public_label'], 'node_id' => $data['node_id'], 'server_type' => $data['server_type'],
            'inbound_id' => $data['server_type'] === 'xui' ? $data['inbound_id'] : '', 'inbound_name' => $data['server_type'] === 'xui' ? $data['inbound_name'] : $data['um_profile_name'],
            'protocol' => $data['server_type'] === 'xui' ? strtolower($data['protocol']) : 'um', 'sort_order' => (int) $data['sort_order'], 'status' => $data['status'], 'listen' => $data['server_type'] === 'xui' ? $data['listen'] : '', 'port' => $data['server_type'] === 'xui' ? $data['port'] : '',
            'network' => $data['server_type'] === 'xui' ? strtolower($data['network']) : '', 'security' => $data['server_type'] === 'xui' ? strtolower($data['security']) : '', 'settings_json' => $data['server_type'] === 'xui' ? $data['settings_json'] : '{}', 'stream_settings_json' => $data['server_type'] === 'xui' ? $data['stream_settings_json'] : '{}',
            'sniffing_json' => $data['server_type'] === 'xui' ? $data['sniffing_json'] : '{}', 'client_extra_query' => $data['server_type'] === 'xui' ? $data['client_extra_query'] : '', 'notes' => $data['notes'], 'billing_gb' => $data['server_type'] === 'um' ? round((float) $data['billing_gb'], 2) : 0,
            'um_profile_id' => $data['server_type'] === 'um' ? $data['um_profile_id'] : '', 'um_profile_name' => $data['server_type'] === 'um' ? $data['um_profile_name'] : '',
        );
        if ($id) { $this->store->update('templates', $id, $payload); } else { $this->store->insert('templates', $payload, 'tpl'); }
        $this->flash('success', $id ? 'Template updated.' : 'Template created.');
        $this->redirect('/admin/templates');
    }

    protected function deleteTemplate($id)
    {
        $customers = $this->store->filterBy('customers', function ($item) use ($id) { return isset($item['template_id']) && $item['template_id'] === $id; });
        if ($customers) { $this->flash('error', 'Delete related customers first.'); $this->redirect('/admin/templates'); }
        $this->store->delete('templates', $id);
        $this->flash('success', 'Template deleted.');
        $this->redirect('/admin/templates');
    }

    protected function resellerDashboard()
    {
        $reseller = $this->currentReseller();
        $templates = $this->resellerTemplates($reseller);
        $allCustomers = $this->store->filterBy('customers', function ($item) use ($reseller) { return isset($item['reseller_id']) && $item['reseller_id'] === $reseller['id']; });
        usort($allCustomers, array($this, 'sortNewest'));
        $activeUserCount = 0;
        foreach ($allCustomers as $item) {
            if ($this->customerRuntimeState($item) === 'active') {
                $activeUserCount++;
            }
        }
        $ledgerItems = $this->store->filterBy('credit_ledger', function ($item) use ($reseller) { return isset($item['reseller_id']) && $item['reseller_id'] === $reseller['id']; });
        usort($ledgerItems, array($this, 'sortNewest'));
        $stats = $this->resellerSalesStats($reseller, $ledgerItems, $allCustomers);
        $sec = $this->securitySettings();
        $this->renderPanel('reseller_dashboard.php', array('title' => 'Dashboard', 'reseller' => $reseller, 'templates' => $templates, 'node_map' => $this->nodeMap(), 'template_map' => $this->templateMap(), 'customers' => array_slice($allCustomers, 0, 6), 'customers_total' => count($allCustomers), 'active_user_count' => $activeUserCount, 'stats' => $stats, 'api_enabled' => !empty($sec['api_enabled']), 'api_encryption' => !empty($sec['api_encryption'])));
    }

    protected function customersPage($scope)
    {
        $customers = $this->store->all('customers');
        $authReseller = null;
        if ($scope === 'reseller') {
            $authReseller = $this->currentReseller();
            $customers = $this->store->filterBy('customers', function ($item) use ($authReseller) { return isset($item['reseller_id']) && $item['reseller_id'] === $authReseller['id']; });
        }
        $allCustomers = $customers;
        $customerStateCounts = $this->customerStateCounts($allCustomers);
        $q = strtolower(trim((string) $this->input('q', '')));
        $sort = trim((string) $this->input('sort', 'new'));
        $bucket = strtolower(trim((string) $this->input('bucket', 'all')));
        if (!in_array($bucket, array('all', 'live', 'ended'), true)) {
            $bucket = 'all';
        }
        if ($q !== '') {
            $customers = array_values(array_filter($customers, function ($item) use ($q) {
                $blob = strtolower((isset($item['display_name']) ? $item['display_name'] : '') . ' ' . (isset($item['system_name']) ? $item['system_name'] : '') . ' ' . (isset($item['service_username']) ? $item['service_username'] : '') . ' ' . (isset($item['subscription_key']) ? $item['subscription_key'] : '') . ' ' . (isset($item['phone']) ? $item['phone'] : '') . ' ' . (isset($item['email']) ? $item['email'] : ''));
                return strpos($blob, $q) !== false;
            }));
        }
        if ($bucket !== 'all') {
            $customers = array_values(array_filter($customers, function ($item) use ($bucket) {
                return $this->customerMatchesBucket($item, $bucket);
            }));
        }
        if ($sort === 'name') { usort($customers, array($this, 'sortDisplayName')); }
        elseif ($sort === 'traffic') { usort($customers, array($this, 'sortByTrafficLeft')); }
        else { usort($customers, array($this, 'sortNewest')); }

        $sec = $this->securitySettings();
        $paginationEnabled = !empty($sec['customer_pagination_enabled']);
        $perPage = max(5, min(250, (int) $sec['customer_pagination_per_page']));
        $page = trim((string) $this->input('page', '1'));
        if (!ctype_digit($page) || (int) $page < 1) { $page = '1'; }
        $page = (int) $page;
        $totalCustomers = count($customers);
        $pageCount = $paginationEnabled ? max(1, (int) ceil($totalCustomers / $perPage)) : 1;
        if ($page > $pageCount) { $page = $pageCount; }
        if ($paginationEnabled) {
            $offset = ($page - 1) * $perPage;
            $customers = array_slice($customers, $offset, $perPage);
        }

        $syncVisibleIds = $this->pickCustomerSyncCandidates($customers);
        $autoSyncShouldRun = !empty($syncVisibleIds) && $this->customerAutoSyncAllowed($scope);

        $this->renderPanel('customers_index.php', array(
            'title' => 'Customers', 'scope' => $scope, 'customers' => $customers, 'node_map' => $this->nodeMap(), 'template_map' => $this->templateMap(), 'reseller_map' => $this->resellerMap(), 'query' => $q, 'sort' => $sort, 'bucket' => $bucket, 'customer_state_counts' => $customerStateCounts, 'reseller' => $scope === 'reseller' ? $authReseller : null,
            'sync_visible_ids' => $syncVisibleIds, 'auto_sync_should_run' => $autoSyncShouldRun, 'auto_sync_batch_limit' => $this->customerAutoSyncBatchLimit(), 'auto_sync_window_seconds' => $this->customerAutoSyncMinAgeSeconds(),
            'pagination_enabled' => $paginationEnabled, 'current_page' => $page, 'page_count' => $pageCount, 'per_page' => $perPage, 'total_customers' => $totalCustomers,
        ));
    }

    protected function customerAutoSyncBatchLimit()
    {
        $cfg = $this->securitySettings();
        $limit = isset($cfg['customer_auto_sync_batch_limit']) ? (int) $cfg['customer_auto_sync_batch_limit'] : 8;
        return max(1, min(100, $limit));
    }

    protected function customerAutoSyncMinAgeSeconds()
    {
        return 180;
    }

    protected function customerAutoSyncCooldownSeconds()
    {
        return 120;
    }

    protected function customerAutoSyncAllowed($scope)
    {
        $cfg = $this->securitySettings();
        if ($scope === 'admin' && empty($cfg['customer_auto_sync_admin_enabled'])) { return false; }
        if ($scope !== 'admin' && empty($cfg['customer_auto_sync_reseller_enabled'])) { return false; }
        if (!isset($_SESSION['_customer_auto_sync']) || !is_array($_SESSION['_customer_auto_sync'])) {
            $_SESSION['_customer_auto_sync'] = array();
        }
        $last = isset($_SESSION['_customer_auto_sync'][$scope]) ? (int) $_SESSION['_customer_auto_sync'][$scope] : 0;
        return ($last <= 0 || (time() - $last) >= $this->customerAutoSyncCooldownSeconds());
    }

    protected function markCustomerAutoSyncRun($scope)
    {
        if (!isset($_SESSION['_customer_auto_sync']) || !is_array($_SESSION['_customer_auto_sync'])) {
            $_SESSION['_customer_auto_sync'] = array();
        }
        $_SESSION['_customer_auto_sync'][$scope] = time();
    }

    protected function pickCustomerSyncCandidates($customers)
    {
        $out = array();
        $limit = $this->customerAutoSyncBatchLimit();
        $minAge = $this->customerAutoSyncMinAgeSeconds();
        $items = array_values((array) $customers);
        usort($items, array($this, 'sortOldestSyncFirst'));
        foreach ($items as $item) {
            if (count($out) >= $limit) { break; }
            if (!is_array($item) || empty($item['id'])) { continue; }
            if (strtolower(trim((string) panel_array_get($item, 'status', 'active'))) === 'removed') { continue; }
            if (!$this->customerNodeIsSyncEnabled($item)) { continue; }
            $lastSync = !empty($item['last_synced_at']) ? strtotime($item['last_synced_at']) : 0;
            if ($lastSync > 0 && (time() - $lastSync) < $minAge) { continue; }
            $out[] = $item['id'];
        }
        return $out;
    }

    protected function customerNodeIsSyncEnabled($customer)
    {
        if (!is_array($customer) || empty($customer['template_id'])) {
            return false;
        }
        $template = $this->store->find('templates', $customer['template_id']);
        if (!$template || empty($template['node_id'])) {
            return false;
        }
        $node = $this->store->find('nodes', $template['node_id']);
        if (!$node) {
            return false;
        }
        return strtolower(trim((string) panel_array_get($node, 'status', 'active'))) === 'active';
    }

    protected function syncCustomersList($scope)
    {
        $ids = $this->input('ids', array());
        if (!is_array($ids)) {
            $ids = $ids === '' ? array() : explode(',', (string) $ids);
        }
        $cleanIds = array();
        foreach ($ids as $id) {
            $id = trim((string) $id);
            if ($id === '') { continue; }
            $cleanIds[$id] = $id;
            if (count($cleanIds) >= $this->customerAutoSyncBatchLimit()) { break; }
        }
        $cleanIds = array_values($cleanIds);
        $this->markCustomerAutoSyncRun($scope);

        if (empty($cleanIds)) {
            $this->flash('error', 'No customers selected for sync.');
            $this->redirect('/' . $scope . '/customers');
        }

        $ok = 0;
        $failed = 0;
        $removed = 0;
        $suspectedMissing = 0;
        foreach ($cleanIds as $id) {
            try {
                $customer = $this->loadCustomerForScope($id, $scope === 'reseller');
            } catch (Exception $e) {
                $failed++;
                continue;
            }
            $sync = $this->refreshCustomerUsageFromNode($customer, true);
            if (!empty($sync['ok'])) {
                $ok++;
                if (!empty($sync['removed_marked'])) {
                    $removed++;
                }
            } else {
                if (!empty($sync['suspected_missing'])) {
                    $suspectedMissing++;
                } else {
                    $failed++;
                }
                if (!empty($customer['id'])) {
                    $updates = array('last_error' => $sync['message']);
                    if (!empty($sync['suspected_missing'])) {
                        $updates['last_synced_at'] = panel_now();
                    }
                    $this->store->update('customers', $customer['id'], $updates);
                }
            }
        }

        if ($ok > 0 && $failed === 0 && $suspectedMissing === 0) {
            if ($removed > 0 && $removed === $ok) {
                $this->flash('success', $removed . ' customer(s) were no longer present on the remote server and were marked Removed locally.');
            } elseif ($removed > 0) {
                $this->flash('success', $ok . ' customer(s) handled, including ' . $removed . ' marked Removed.');
            } else {
                $this->flash('success', $ok . ' customer(s) synced.');
            }
        } elseif ($ok > 0 || $suspectedMissing > 0) {
            $parts = array();
            if ($ok > 0) {
                if ($removed > 0) {
                    $parts[] = $ok . ' handled, including ' . $removed . ' marked Removed';
                } else {
                    $parts[] = $ok . ' synced';
                }
            }
            if ($suspectedMissing > 0) {
                $parts[] = $suspectedMissing . ' returned missing-client responses but could not be confirmed yet';
            }
            if ($failed > 0) {
                $parts[] = $failed . ' failed';
            }
            $this->flash($failed > 0 ? 'success' : 'success', 'Customer sync completed: ' . implode(', ', $parts) . '.');
        } else {
            $this->flash('error', 'Customer sync failed for all selected entries.');
        }
        $this->redirect('/' . $scope . '/customers');
    }

    protected function customerDetailsPage($scope, $id)
    {
        $customer = $this->loadCustomerForScope($id, $scope === 'reseller');
        $template = $this->store->find('templates', $customer['template_id']);
        $node = $template ? $this->store->find('nodes', $template['node_id']) : null;
        $reseller = $this->store->find('resellers', $customer['reseller_id']);
        $link = $this->findCustomerLink($customer['id']);
        $entry = $this->buildCustomerPublicPayload($customer, $template, $node);
        $subscriptionUrl = $entry['subscription_url'];
        $exportUrl = $this->appLink('/user/' . $customer['subscription_key'] . '/export');
        $proxySubscriptionUrl = $entry['proxy_subscription_url'];
        $this->renderPanel('customer_show.php', array('title' => 'Customer details', 'scope' => $scope, 'customer' => $customer, 'template' => $template, 'node' => $node, 'reseller' => $reseller, 'link' => $link, 'subscription_url' => $subscriptionUrl, 'export_url' => $exportUrl, 'proxy_subscription_url' => $proxySubscriptionUrl, 'entry' => $entry));
    }

    protected function customerForm($id = null, $forceServerType = '')
    {
        $reseller = $this->currentReseller();
        $mode = $id ? 'edit' : 'create';
        $maxIpLimit = $this->resellerMaxIpLimit($reseller);
        $maxExpirationDays = $this->resellerMaxExpirationDays($reseller);
        $record = array('display_name' => '', 'template_id' => '', 'traffic_gb' => '1', 'ip_limit' => $maxIpLimit > 0 ? '1' : '0', 'duration_days' => (string) $this->resellerDefaultCustomerDurationDays($reseller), 'duration_mode' => 'fixed', 'status' => 'active', 'phone' => '', 'email' => '', 'access_pin' => '', 'notes' => '');
        if ($mode === 'edit') {
            $record = array_merge($record, $this->loadCustomerForScope($id, true));
            $record['access_pin'] = '';
            if ($maxIpLimit > 0 && (!isset($record['ip_limit']) || (int) $record['ip_limit'] < 1)) { $record['ip_limit'] = 1; }
            if ($maxExpirationDays > 0 && (!isset($record['duration_days']) || (int) $record['duration_days'] < 1)) { $record['duration_days'] = $maxExpirationDays; }
        }
        $effectiveCreateType = $forceServerType !== '' ? $this->normalizeServerType($forceServerType) : ($mode === 'edit' ? $this->customerServerType($record) : 'xui');
        $templates = ($mode === 'edit' || $effectiveCreateType === '') ? ($forceServerType !== '' ? $this->resellerTemplatesByType($reseller, $forceServerType) : $this->resellerTemplates($reseller)) : $this->resellerTemplatesByType($reseller, $effectiveCreateType);
        $record['server_type'] = $effectiveCreateType;
        $this->renderCustomerForm($mode, $record, array(), $reseller, $templates);
    }

    protected function renderCustomerForm($mode, $record, $errors, $reseller, $templates = null)
    {
        if ($templates === null) { $templates = $this->resellerTemplates($reseller); }
        if (!isset($record['server_type']) || $record['server_type'] === '') { $record['server_type'] = ($mode === 'edit') ? $this->customerServerType($record) : ($this->normalizeServerType($this->input('server_type', 'xui'))); }
        $record['server_type'] = $this->normalizeServerType($record['server_type']);
        $filteredTemplates = array();
        foreach ((array) $templates as $tpl) {
            if ($this->templateServerType($tpl) === $record['server_type']) {
                $filteredTemplates[] = $tpl;
            }
        }
        $this->renderPanel('customer_form.php', array('title' => $mode === 'edit' ? 'Edit customer' : ($record['server_type'] === 'um' ? 'Create UM customer' : 'Create customer'), 'mode' => $mode, 'record' => $record, 'errors' => $errors, 'templates' => $filteredTemplates, 'reseller' => $reseller, 'node_map' => $this->nodeMap(), 'max_ip_limit' => $this->resellerMaxIpLimit($reseller),
        'min_customer_traffic_gb' => isset($reseller['min_customer_traffic_gb']) ? (float) $reseller['min_customer_traffic_gb'] : 0.0,
        'max_customer_traffic_gb' => isset($reseller['max_customer_traffic_gb']) ? (float) $reseller['max_customer_traffic_gb'] : 0.0, 'max_expiration_days' => $this->resellerMaxExpirationDays($reseller)));
    }

    protected function saveCustomer($id = null)
    {
        $reseller = $this->currentReseller();
        $mode = $id ? 'edit' : 'create';
        $existing = $id ? $this->loadCustomerForScope($id, true) : null;
        $data = array('display_name' => trim((string) $this->input('display_name', '')), 'template_id' => trim((string) $this->input('template_id', '')), 'traffic_gb' => trim((string) $this->input('traffic_gb', '1')), 'ip_limit' => trim((string) $this->input('ip_limit', '0')), 'duration_days' => trim((string) $this->input('duration_days', (string) $this->resellerDefaultCustomerDurationDays($reseller))), 'duration_mode' => trim((string) $this->input('duration_mode', trim((string) $this->input('expiration_mode', 'fixed')))), 'status' => trim((string) $this->input('status', 'active')), 'phone' => $this->normalizeCustomerPhone($this->input('phone', '')), 'email' => $this->normalizeCustomerEmail($this->input('email', '')), 'access_pin' => trim((string) $this->input('access_pin', '')), 'notes' => trim((string) $this->input('notes', '')));
        if ($existing) {
            $postedTrafficRaw = isset($_POST['traffic_gb']) ? trim((string) $_POST['traffic_gb']) : '';
            if ($postedTrafficRaw === '') {
                $data['traffic_gb'] = (string) panel_array_get($existing, 'traffic_gb', '1');
            }
            if (!$this->resellerCanEditXuiTraffic($reseller)) {
                $data['traffic_gb'] = (string) panel_array_get($existing, 'traffic_gb', $data['traffic_gb']);
            }
        }
        $requestedTemplate = $this->store->find('templates', $data['template_id']);
        $effectiveType = $existing ? $this->customerServerType($existing) : ($requestedTemplate ? $this->templateServerType($requestedTemplate) : $this->normalizeServerType($this->input('server_type', 'xui')));
        $data['server_type'] = $effectiveType;
        if ($effectiveType === 'um') { return $this->saveUmCustomer($id, $reseller, $existing, $data); }
        $errors = array();
        $oldTraffic = $existing ? round((float) $existing['traffic_gb'], 2) : 0;
        if (strlen($data['display_name']) < 2) { $errors['display_name'][] = 'Name must be at least 2 characters.'; }
        if (!is_numeric($data['traffic_gb']) || (float) $data['traffic_gb'] <= 0) { $errors['traffic_gb'][] = 'Traffic must be greater than zero.'; }
        elseif (!$this->resellerAllowsFractionalTraffic($reseller)) {
            $postedTraffic = round((float) $data['traffic_gb'], 2);
            if (!$this->gbValueIsWhole($data['traffic_gb']) && !($existing && abs($postedTraffic - $oldTraffic) < 0.00001)) {
                $errors['traffic_gb'][] = 'This reseller can only use whole GB values.';
            }
        }
        if (!ctype_digit($data['ip_limit']) || (int) $data['ip_limit'] < 0) { $errors['ip_limit'][] = 'IP limit must be zero or a positive integer.'; }
        if (!ctype_digit($data['duration_days']) || (int) $data['duration_days'] < 0) { $errors['duration_days'][] = 'Expiration days must be zero or a positive integer.'; }
        if (!in_array($data['duration_mode'], array('fixed', 'first_use'), true)) { $errors['duration_mode'][] = 'Expiration mode is invalid.'; }
        $hasPhone = $data['phone'] !== '';
        $hasEmail = $data['email'] !== '';
        $hasAccessIdentity = $hasPhone || $hasEmail;
        $hasPinInput = $data['access_pin'] !== '';
        $existingHasPin = $existing && !empty($existing['access_pin_hash']);
        if ($hasPhone && !$this->isValidCustomerPhone($data['phone'])) { $errors['phone'][] = 'Phone must contain only digits and be between 6 and 20 numbers.'; }
        if ($hasEmail && !$this->isValidCustomerEmail($data['email'])) { $errors['email'][] = 'Email address is invalid.'; }
        if ($hasPinInput && !$this->isValidCustomerPin($data['access_pin'])) { $errors['access_pin'][] = 'PIN must be 1 to 6 letters or numbers.'; }
        if ($mode === 'create') {
            if ($hasAccessIdentity xor $hasPinInput) {
                $errors['auth'][] = 'Phone or email plus PIN must both be filled to enable /get access, or all left blank to disable it.';
            }
        } else {
            if ($hasAccessIdentity && !$hasPinInput && !$existingHasPin) {
                $errors['auth'][] = 'Set a PIN too, or clear phone/email to disable /get access.';
            }
            if (!$hasAccessIdentity && $hasPinInput) {
                $errors['auth'][] = 'Phone or email is required when setting a PIN.';
            }
        }
        $maxIpLimit = $this->resellerMaxIpLimit($reseller);
        if ($maxIpLimit > 0 && ctype_digit($data['ip_limit'])) {
            if ((int) $data['ip_limit'] < 1 || (int) $data['ip_limit'] > $maxIpLimit) { $errors['ip_limit'][] = 'IP limit must be between 1 and ' . $maxIpLimit . ' for this reseller.'; }
        }
        $maxExpirationDays = $this->resellerMaxExpirationDays($reseller);
        if ($maxExpirationDays > 0 && ctype_digit($data['duration_days'])) {
            if ((int) $data['duration_days'] < 1 || (int) $data['duration_days'] > $maxExpirationDays) { $errors['duration_days'][] = 'Expiration days must be between 1 and ' . $maxExpirationDays . ' for this reseller.'; }
        }
        $template = $this->store->find('templates', $data['template_id']);
        if (!$template || !$this->resellerCanUseTemplate($reseller, $data['template_id']) || (isset($template['status']) && $template['status'] !== 'active')) { $errors['template_id'][] = 'Select a permitted inbound template.'; }
        if (!in_array($data['status'], array('active', 'disabled'), true)) { $errors['status'][] = 'Status is invalid.'; }
        if ($errors) { return $this->renderCustomerForm($mode, $data, $errors, $reseller); }

        $traffic = round((float) $data['traffic_gb'], 2);
        if ($traffic <= 0) {
            $errors['traffic_gb'][] = 'Traffic must remain at least 0.01 GB after rounding.';
            return $this->renderCustomerForm($mode, $data, $errors, $reseller);
        }
        $minAllowedGb = isset($reseller['min_customer_traffic_gb']) ? round((float) $reseller['min_customer_traffic_gb'], 2) : 0;
        $maxAllowedGb = isset($reseller['max_customer_traffic_gb']) ? round((float) $reseller['max_customer_traffic_gb'], 2) : 0;
        if ($minAllowedGb > 0 && $traffic < $minAllowedGb) {
            $errors['traffic_gb'][] = 'Traffic must be at least ' . panel_format_gb($minAllowedGb) . ' GB for this reseller.';
        }
        if ($maxAllowedGb > 0 && $traffic > $maxAllowedGb) {
            $errors['traffic_gb'][] = 'Traffic must not be more than ' . panel_format_gb($maxAllowedGb) . ' GB for this reseller.';
        }
        $creditDelta = round($traffic - $oldTraffic, 2);
        $usedGb = $existing ? panel_to_gb_from_bytes(isset($existing['traffic_bytes_used']) ? $existing['traffic_bytes_used'] : 0) : 0;
        $liveUsage = null;
        $isRestrictedReseller = panel_parse_bool(isset($reseller['restrict']) ? $reseller['restrict'] : 0, false);
        if ($isRestrictedReseller && $data['status'] !== 'active') {
            $errors['status'][] = 'This reseller is restricted and cannot disable customers.';
        }
        if ($existing && $traffic < $oldTraffic) {
            if ($isRestrictedReseller) {
                $errors['traffic_gb'][] = 'This reseller is restricted and cannot lower customer traffic.';
            } else {
                $liveUsage = $this->refreshCustomerUsageFromNode($existing, true);
                if (!$liveUsage['ok']) {
                    $errors['traffic_gb'][] = 'Live usage sync is required before lowering traffic: ' . $liveUsage['message'];
                } else {
                    $usedGb = panel_to_gb_from_bytes($liveUsage['used_bytes']);
                    if ($traffic < $usedGb) {
                        $errors['traffic_gb'][] = 'Traffic cannot be lower than the customer\'s already used traffic (' . panel_format_gb($usedGb) . ' GB).';
                    }
                }
            }
        }
        if ($existing && !$this->resellerCanEditXuiTraffic($reseller) && abs($traffic - $oldTraffic) >= 0.00001) {
            $errors['traffic_gb'][] = 'Admin has disabled XUI traffic editing for this reseller.';
        }
        if ($creditDelta > 0 && (float) $reseller['credit_gb'] < $creditDelta) {
            $errors['traffic_gb'][] = 'Not enough reseller credit left.';
        }
        if ($errors) {
            return $this->renderCustomerForm($mode, $data, $errors, $reseller);
        }

        $node = $this->store->find('nodes', $template['node_id']);
        if ($node && isset($node['status']) && $node['status'] !== 'active') {
            $errors['template_id'][] = 'The selected server is disabled.';
            return $this->renderCustomerForm($mode, $data, $errors, $reseller);
        }

        $durationDays = (int) $data['duration_days'];
        $durationMode = $data['duration_mode'] === 'first_use' ? 'first_use' : 'fixed';
        $expireAt = ($durationMode === 'fixed' && $durationDays > 0) ? (time() + ($durationDays * 86400)) : 0;
        $ipLimit = (int) $data['ip_limit'];
        $displaySlug = panel_slug($data['display_name'], true);
        if ($existing) {
            if (abs($traffic - $oldTraffic) < 0.00001) {
                $systemName = (string) $existing['system_name'];
            } else {
                $systemName = $this->replaceTrafficMarkerInClientName((string) $existing['system_name'], $traffic);
            }
        } else {
            $nameParts = array();
            $prefixPart = panel_slug(isset($reseller['prefix']) ? $reseller['prefix'] : '', true);
            if ($prefixPart !== '') { $nameParts[] = $prefixPart; }
            $nameParts[] = preg_replace('/[^0-9.]/', '', panel_format_gb($traffic)) . 'gb';
            $nameParts[] = $displaySlug;
            $nameParts[] = panel_random_hex(6);
            $systemName = strtolower(implode('-', $nameParts));
        }
        $subKey = $existing ? $existing['subscription_key'] : panel_random_hex(16);
        $credential = $existing && isset($existing['uuid']) ? $existing['uuid'] : $this->generateClientCredential(isset($template['protocol']) ? $template['protocol'] : 'vless');
        $remoteEmail = $existing && isset($existing['remote_email']) ? (abs($traffic - $oldTraffic) < 0.00001 ? (string) $existing['remote_email'] : $this->replaceTrafficMarkerInClientName((string) $existing['remote_email'], $traffic)) : $systemName;
        if ($existing && $remoteEmail === '') {
            $remoteEmail = $systemName;
        }
        $remoteSubId = $existing && isset($existing['remote_sub_id']) ? $existing['remote_sub_id'] : $subKey;
        $link = $existing ? $this->findCustomerLink($existing['id']) : null;
        $remoteClientId = $existing && isset($existing['remote_client_id']) ? $existing['remote_client_id'] : $this->remoteClientIdentifier(isset($template['protocol']) ? $template['protocol'] : 'vless', $credential, $remoteEmail);
        $remoteClientId = $this->remoteClientIdentifier(isset($template['protocol']) ? $template['protocol'] : 'vless', $credential, $remoteEmail);

        $payload = array(
            'reseller_id' => $reseller['id'],
            'display_name' => $data['display_name'],
            'system_name' => $systemName,
            'template_id' => $template['id'],
            'node_id' => $node ? $node['id'] : '',
            'traffic_gb' => $traffic,
            'traffic_bytes_total' => panel_to_bytes_from_gb($traffic),
            'traffic_bytes_used' => $existing ? panel_to_bytes_from_gb($usedGb) : 0,
            'traffic_bytes_left' => max(0, panel_to_bytes_from_gb($traffic) - ($existing ? panel_to_bytes_from_gb($usedGb) : 0)),
            'duration_days' => $durationDays,
            'duration_mode' => $durationMode,
            'ip_limit' => $ipLimit,
            'expires_at' => $expireAt > 0 ? gmdate('c', $expireAt) : '',
            'status' => $data['status'],
            'phone' => $data['phone'],
            'email' => $data['email'],
            'access_pin_hash' => ((!$hasAccessIdentity) ? '' : ($data['access_pin'] !== '' ? panel_password_hash($data['access_pin']) : ($existing && isset($existing['access_pin_hash']) ? $existing['access_pin_hash'] : ''))),
            'notes' => $data['notes'],
            'subscription_key' => $subKey,
            'uuid' => $credential,
            'remote_email' => $remoteEmail,
            'remote_client_id' => $remoteClientId,
            'remote_sub_id' => $remoteSubId,
            'last_error' => '',
        );

        $xuiMessage = '';
        if ($node) {
            $adapter = $this->nodeAdapter($node);
            $settings = $this->buildXuiClientSettings($customer = array_merge($existing ? $existing : array(), $payload), $template, $expireAt);
            if ($mode === 'edit') {
                $oldTemplate = $this->store->find('templates', $existing['template_id']);
                $oldNode = $oldTemplate ? $this->store->find('nodes', $oldTemplate['node_id']) : null;
                if ($oldTemplate && $oldNode && ($oldTemplate['id'] !== $template['id'] || $oldNode['id'] !== $node['id'])) {
                    $oldAdapter = $this->nodeAdapter($oldNode);
                    $oldAdapter->deleteClient($oldTemplate['inbound_id'], isset($existing['remote_client_id']) ? $existing['remote_client_id'] : '', isset($existing['remote_email']) ? $existing['remote_email'] : $existing['system_name']);
                    $remote = $adapter->ensureClientState($template['inbound_id'], $remoteClientId, $settings, $remoteEmail);
                } else {
                    $remote = $adapter->updateClient($remoteClientId, $template['inbound_id'], $settings, isset($existing['remote_email']) ? $existing['remote_email'] : $existing['system_name']);
                    if (!$remote['ok']) {
                        $check = $adapter->getClientTraffic($remoteEmail);
                        if ($check['ok']) {
                            $remote = $adapter->updateClientTraffic($remoteEmail, $payload['traffic_bytes_total'], $this->resolveXuiExpiryMillis($customer, $expireAt));
                        }
                    }
                }
            } else {
                $remote = $adapter->ensureClientState($template['inbound_id'], $remoteClientId, $settings, $remoteEmail);
            }
            if (!$remote['ok']) {
                $this->logXuiEvent('error', 'Remote customer sync failed during save.', array('customer_id' => $id ? $id : '', 'reseller_id' => $reseller['id'], 'template_id' => $template['id'], 'node_id' => $node ? $node['id'] : '', 'mode' => $mode, 'message' => $remote['message']));
                $this->flash('error', 'Customer was not saved because node sync failed: ' . $remote['message']);
                $this->redirect('/reseller/customers');
            }
            $this->logXuiEvent('access', 'Remote customer sync completed during save.', array('customer_id' => $id ? $id : '', 'reseller_id' => $reseller['id'], 'template_id' => $template['id'], 'node_id' => $node ? $node['id'] : '', 'mode' => $mode, 'message' => $remote['message']));
            $xuiMessage = $remote['message'];
        }

        if ($mode === 'edit') {
            $this->store->update('customers', $id, $payload);
            if ($creditDelta != 0) {
                $this->changeResellerCredit($reseller['id'], -$creditDelta, 'customer_edit', 'XUI traffic adjustment for customer ' . $payload['display_name']);
            }
            $customer = $this->store->find('customers', $id);
        } else {
            $customer = $this->store->insert('customers', $payload, 'cus');
            $this->changeResellerCredit($reseller['id'], -$traffic, 'customer_create', 'XUI traffic allocation for customer ' . $payload['display_name']);
        }
        $this->saveCustomerLink($customer, $template, $node, $remoteClientId, $remoteEmail, $remoteSubId);
        if ($mode === 'edit') { $this->logResellerActivity($reseller['id'], 'customer.edit', $customer, array_merge(array('traffic_gb' => $traffic, 'ip_limit' => $ipLimit, 'duration_days' => $durationDays, 'duration_mode' => $durationMode), $this->customerTypeContext($customer, $template, $node))); } else { $this->logResellerActivity($reseller['id'], 'customer.create', $customer, array_merge(array('traffic_gb' => $traffic, 'ip_limit' => $ipLimit, 'duration_days' => $durationDays, 'duration_mode' => $durationMode), $this->customerTypeContext($customer, $template, $node))); }
        $this->flash('success', ($mode === 'edit' ? 'Customer updated.' : 'Customer created successfully.') . ($xuiMessage !== '' ? ' ' . $xuiMessage : ''));
        $this->redirect('/reseller/customers');
    }

    protected function toggleCustomer($id, $scoped)
    {
        $customer = $this->loadCustomerForScope($id, $scoped);
        if ($scoped) {
            $reseller = $this->store->find('resellers', $customer['reseller_id']);
            if ($reseller && panel_parse_bool(isset($reseller['restrict']) ? $reseller['restrict'] : 0, false)) {
                $this->flash('error', 'This reseller is restricted and cannot disable or enable customers.');
                $this->redirect('/reseller/customers');
            }
        }
        $status = $customer['status'] === 'active' ? 'disabled' : 'active';
        $template = $this->store->find('templates', $customer['template_id']);
        $node = $template ? $this->store->find('nodes', $template['node_id']) : null;
        if ($template && $node && isset($node['status']) && $node['status'] === 'active') {
            $adapter = $this->nodeAdapter($node);
            if ($this->customerServerType($customer, $template, $node) === 'um') {
                $remote = $adapter->setUserDisabled($customer, $status !== 'active');
                if (!$remote['ok']) {
                    $confirmedMissing = (!empty($remote['confirmed_absent']) || (!empty($remote['user_exists']) ? false : $this->remoteClientLooksMissing($remote)));
                    if ($confirmedMissing) {
                        $marked = $this->markCustomerRemovedLocally($customer, $template, $node, true, panel_array_get($remote, 'message', 'Remote UM user not found.'));
                        $this->flash('success', panel_array_get($marked, 'message', 'Customer was marked Removed because the UM user no longer exists.'));
                        $this->redirect($scoped ? '/reseller/customers' : '/admin/customers');
                    }
                    $this->logUmEvent('error', 'Remote UM customer status update failed.', array('customer_id' => $customer['id'], 'template_id' => $template['id'], 'node_id' => $node['id'], 'status' => $status, 'username' => $this->customerServiceUsername($customer), 'message' => $remote['message']));
                    $this->flash('error', 'Remote status update failed: ' . $remote['message']);
                    $this->redirect($scoped ? '/reseller/customers' : '/admin/customers');
                }
                if (!empty($remote['user']) && is_array($remote['user']) && !empty($customer['id'])) {
                    $this->store->update('customers', $customer['id'], array('um_remote_user_id' => trim((string) panel_array_get($remote['user'], 'id', panel_array_get($customer, 'um_remote_user_id', ''))), 'um_remote_user_menu' => trim((string) panel_array_get($remote['user'], 'menu', panel_array_get($customer, 'um_remote_user_menu', '')))));
                }
                $this->logUmEvent('access', 'Remote UM customer status updated.', array('customer_id' => $customer['id'], 'template_id' => $template['id'], 'node_id' => $node['id'], 'status' => $status, 'username' => $this->customerServiceUsername($customer)));
            } else {
                $settings = $this->buildXuiClientSettings(array_merge($customer, array('status' => $status)), $template, strtotime($customer['expires_at']));
                $remote = $adapter->updateClient(isset($customer['remote_client_id']) ? $customer['remote_client_id'] : $this->remoteClientIdentifier($template['protocol'], $customer['uuid'], isset($customer['remote_email']) ? $customer['remote_email'] : $customer['system_name']), $template['inbound_id'], $settings, isset($customer['remote_email']) ? $customer['remote_email'] : $customer['system_name']);
                if (!$remote['ok']) {
                    $this->logXuiEvent('error', 'Remote customer status update failed.', array('customer_id' => $customer['id'], 'template_id' => $template['id'], 'node_id' => $node['id'], 'status' => $status, 'message' => $remote['message']));
                    $this->flash('error', 'Remote status update failed: ' . $remote['message']);
                    $this->redirect($scoped ? '/reseller/customers' : '/admin/customers');
                }
                $this->logXuiEvent('access', 'Remote customer status updated.', array('customer_id' => $customer['id'], 'template_id' => $template['id'], 'node_id' => $node['id'], 'status' => $status));
            }
        }
        $this->store->update('customers', $customer['id'], array('status' => $status));
        if ($scoped) { $this->logResellerActivity($customer['reseller_id'], $status === 'active' ? 'customer.enable' : 'customer.disable', $customer, array_merge(array('status' => $status), $this->customerTypeContext($customer, $template, $node))); }
        $this->flash('success', 'Customer status updated.');
        $this->redirect($scoped ? '/reseller/customers' : '/admin/customers');
    }

    protected function deleteCustomer($id, $scoped)
    {
        $customer = $this->loadCustomerForScope($id, $scoped);
        $reseller = $this->store->find('resellers', $customer['reseller_id']);
        if ($scoped && $reseller && panel_parse_bool(isset($reseller['restrict']) ? $reseller['restrict'] : 0, false)) {
            $this->flash('error', 'This reseller is restricted and cannot delete customers.');
            $this->redirect('/reseller/customers');
        }

        $statusBeforeDelete = strtolower(trim((string) panel_array_get($customer, 'status', 'active')));
        $wasRemovedBeforeDelete = ($statusBeforeDelete === 'removed');
        $refundEligible = false;
        $refundBlockedReason = '';
        $refund = 0.0;

        $usage = array('ok' => true, 'customer' => $customer, 'template' => $this->store->find('templates', $customer['template_id']), 'node' => null, 'used_bytes' => isset($customer['traffic_bytes_used']) ? (float) $customer['traffic_bytes_used'] : 0);
        if (!$wasRemovedBeforeDelete) {
            $usage = $this->refreshCustomerUsageFromNode($customer, true);
            if (!$usage['ok']) {
                $this->flash('error', 'Customer delete blocked because live usage could not be verified: ' . $usage['message']);
                $this->redirect($scoped ? '/reseller/customers' : '/admin/customers');
            }
            $customer = $usage['customer'];
            $statusAfterSync = strtolower(trim((string) panel_array_get($customer, 'status', 'active')));
            if ($statusAfterSync === 'removed') {
                $refundBlockedReason = 'Refund skipped because the remote account was already absent and the customer was marked Removed before deletion.';
            } else {
                $refundEligible = true;
            }
        } else {
            $refundBlockedReason = 'Refund skipped because the customer was already marked Removed before deletion.';
        }
        $template = panel_array_get($usage, 'template', null);
        $node = panel_array_get($usage, 'node', null);
        if (!$node && $template) { $node = $this->store->find('nodes', $template['node_id']); }

        if ($template && $node && isset($node['status']) && $node['status'] === 'active') {
            $adapter = $this->nodeAdapter($node);
            if ($this->customerServerType($customer, $template, $node) === 'um') {
                $remote = $adapter->deleteUser($customer);
                if (!$remote['ok']) {
                    $probe = $adapter->getUserUsage($customer);
                    if (!empty($probe['user_exists'])) {
                        $this->logUmEvent('error', 'Remote UM customer delete failed and user still exists.', array('customer_id' => $customer['id'], 'template_id' => $template['id'], 'node_id' => $node['id'], 'username' => $this->customerServiceUsername($customer), 'message' => $remote['message']));
                        $this->flash('error', 'Remote delete failed and the user still exists on UM: ' . $remote['message']);
                        $this->redirect($scoped ? '/reseller/customers' : '/admin/customers');
                    }
                }
                $this->logUmEvent('access', 'Remote UM customer delete completed.', array('customer_id' => $customer['id'], 'template_id' => $template['id'], 'node_id' => $node['id'], 'username' => $this->customerServiceUsername($customer), 'message' => panel_array_get($remote, 'message', '')));
            } else {
                $remote = $adapter->deleteClient($template['inbound_id'], isset($customer['remote_client_id']) ? $customer['remote_client_id'] : '', isset($customer['remote_email']) ? $customer['remote_email'] : $customer['system_name']);
                if (!$remote['ok']) {
                    $traffic = $adapter->getClientTraffic(isset($customer['remote_email']) ? $customer['remote_email'] : $customer['system_name']);
                    if ($traffic['ok']) {
                        $this->logXuiEvent('error', 'Remote customer delete failed and the client still exists on the node.', array('customer_id' => $customer['id'], 'template_id' => $template['id'], 'node_id' => $node['id'], 'message' => $remote['message']));
                        $this->flash('error', 'Remote delete failed and the client still exists on the node: ' . $remote['message']);
                        $this->redirect($scoped ? '/reseller/customers' : '/admin/customers');
                    }
                }
                $this->logXuiEvent('access', 'Remote customer delete completed.', array('customer_id' => $customer['id'], 'template_id' => $template['id'], 'node_id' => $node['id']));
            }
        }

        if ($reseller && $refundEligible) {
            $refund = max(0, round((float) $customer['traffic_gb'] - panel_to_gb_from_bytes(isset($customer['traffic_bytes_used']) ? $customer['traffic_bytes_used'] : 0), 2));
            if ($refund > 0) {
                $refundNote = 'Refund for deleted XUI customer ' . $customer['display_name'];
                if ($this->customerServerType($customer, $template, $node) === 'um') {
                    $refundNote = 'Refund for deleted UM customer ' . $customer['display_name'];
                    $profileName = trim((string) panel_array_get($template, 'um_profile_name', panel_array_get($template, 'public_label', '')));
                    if ($profileName !== '') { $refundNote .= ' (' . $profileName . ')'; }
                }
                $this->changeResellerCredit($reseller['id'], $refund, 'customer_delete_refund', $refundNote);
            }
        }
        $link = $this->findCustomerLink($customer['id']);
        if ($link) { $this->store->delete('customer_links', $link['id']); }
        $this->store->delete('customers', $customer['id']);
        if ($scoped) {
            $this->logResellerActivity($customer['reseller_id'], 'customer.delete', $customer, array_merge(array(
                'refund_gb' => $refund,
                'refund_eligible' => $refundEligible ? 1 : 0,
                'refund_blocked_reason' => $refundEligible ? '' : $refundBlockedReason,
            ), $this->customerTypeContext($customer, $template, $node)));
        }
        $this->flash('success', 'Customer deleted.');
        $this->redirect($scoped ? '/reseller/customers' : '/admin/customers');
    }


    protected function clearEndedCustomers()
    {
        $customers = $this->store->filterBy('customers', function ($item) {
            return $this->customerMatchesBucket($item, 'ended');
        });
        if (empty($customers)) {
            $this->flash('info', 'There are no depleted, ended, or removed customers to clear.');
            $this->redirect('/admin/customers?bucket=ended');
        }
        $removedCount = 0;
        $linkRemovedCount = 0;
        $stateBreakdown = array('depleted' => 0, 'ended' => 0, 'removed' => 0);
        foreach ($customers as $customer) {
            if (!is_array($customer) || empty($customer['id'])) {
                continue;
            }
            $state = $this->customerRuntimeState($customer);
            if (isset($stateBreakdown[$state])) {
                $stateBreakdown[$state]++;
            }
            $link = $this->findCustomerLink($customer['id']);
            if ($link) {
                $this->store->delete('customer_links', $link['id']);
                $linkRemovedCount++;
            }
            $this->store->delete('customers', $customer['id']);
            $removedCount++;
        }
        $this->log('customers.bulk_clear_ended', array(
            'actor' => $this->currentActorLabel(),
            'removed_count' => $removedCount,
            'customer_link_removed_count' => $linkRemovedCount,
            'state_breakdown' => $stateBreakdown,
        ));
        $message = $removedCount . ' depleted, ended, or removed customer(s) were cleared from the panel.';
        if ($linkRemovedCount > 0) {
            $message .= ' ' . $linkRemovedCount . ' public access link record(s) were also removed.';
        }
        $this->flash('success', $message);
        $this->redirect('/admin/customers?bucket=ended');
    }

    protected function syncCustomer($id, $scoped)
    {
        $customer = $this->loadCustomerForScope($id, $scoped);
        $sync = $this->refreshCustomerUsageFromNode($customer, true);
        if (!$sync['ok']) {
            if (isset($customer['id'])) {
                $updates = array('last_error' => $sync['message']);
                if (!empty($sync['suspected_missing'])) {
                    $updates['last_synced_at'] = panel_now();
                }
                $this->store->update('customers', $customer['id'], $updates);
            }
            if (!empty($sync['suspected_missing'])) {
                $this->flash('success', 'Sync completed with caution: ' . $sync['message']);
            } else {
                $this->flash('error', 'Sync failed: ' . $sync['message']);
            }
            $this->redirect($scoped ? '/reseller/customers' : '/admin/customers');
        }
        if ($scoped) {
            $fresh = isset($sync['customer']) && is_array($sync['customer']) ? $sync['customer'] : $customer;
            $freshTemplate = isset($sync['template']) && is_array($sync['template']) ? $sync['template'] : $this->store->find('templates', panel_array_get($fresh, 'template_id', ''));
            $freshNode = isset($sync['node']) && is_array($sync['node']) ? $sync['node'] : ($freshTemplate ? $this->store->find('nodes', panel_array_get($freshTemplate, 'node_id', '')) : null);
            $this->logResellerActivity($customer['reseller_id'], 'customer.sync', $fresh, array_merge(array(
                'traffic_used_gb' => round(panel_to_gb_from_bytes(panel_array_get($fresh, 'traffic_bytes_used', 0)), 2),
                'traffic_left_gb' => round(panel_to_gb_from_bytes(panel_array_get($fresh, 'traffic_bytes_left', 0)), 2),
            ), $this->customerTypeContext($fresh, $freshTemplate, $freshNode)));
        }
        $this->flash('success', !empty($sync['message']) ? $sync['message'] : 'Customer usage synced.');
        $this->redirect($scoped ? '/reseller/customers/' . $customer['id'] : '/admin/customers/' . $customer['id']);
    }

    protected function remoteClientLooksMissing($traffic)
    {
        if (!is_array($traffic)) {
            return false;
        }

        $statusCode = isset($traffic['status_code']) ? (int) $traffic['status_code'] : 0;
        if ($statusCode === 404 || $statusCode === 410) {
            return true;
        }

        $message = strtolower(trim((string) panel_array_get($traffic, 'message', '')));
        if ($message !== '') {
            $needles = array(
                'not found',
                'no such client',
                'client not found',
                'email not found',
                'user not found',
                'does not exist',
                'no data found',
                'failed to find',
                'record not found',
                'client is not found',
                'unable to find',
            );
            foreach ($needles as $needle) {
                if (strpos($message, $needle) !== false) {
                    return true;
                }
            }
        }

        $raw = isset($traffic['raw']) ? $traffic['raw'] : null;
        if (is_array($raw)) {
            $rawMessage = strtolower(trim((string) panel_array_get($raw, 'msg', panel_array_get($raw, 'message', ''))));
            if ($rawMessage !== '' && $rawMessage !== $message) {
                $needles = array('not found', 'no such client', 'client not found', 'email not found', 'user not found', 'does not exist', 'failed to find', 'record not found');
                foreach ($needles as $needle) {
                    if (strpos($rawMessage, $needle) !== false) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    protected function remoteClientLooksExplicitlyMissing($traffic)
    {
        if (!is_array($traffic)) {
            return false;
        }
        $statusCode = isset($traffic['status_code']) ? (int) $traffic['status_code'] : 0;
        if ($statusCode === 404 || $statusCode === 410) {
            return true;
        }

        $messages = array();
        foreach (array(panel_array_get($traffic, 'message', '')) as $message) {
            $message = strtolower(trim((string) $message));
            if ($message !== '') {
                $messages[] = $message;
            }
        }
        $raw = isset($traffic['raw']) ? $traffic['raw'] : null;
        if (is_array($raw)) {
            foreach (array(panel_array_get($raw, 'msg', ''), panel_array_get($raw, 'message', ''), panel_array_get($raw, 'errors', '')) as $message) {
                if (is_array($message)) {
                    $message = implode(' ', array_map('strval', $message));
                }
                $message = strtolower(trim((string) $message));
                if ($message !== '') {
                    $messages[] = $message;
                }
            }
        }

        $needles = array(
            'client not found',
            'client is not found',
            'email not found',
            'user not found',
            'does not exist',
            'record not found',
            'no such client',
            'failed to find',
            'unable to find',
            'not found in db',
            'not found in database',
        );
        foreach ($messages as $message) {
            foreach ($needles as $needle) {
                if (strpos($message, $needle) !== false) {
                    return true;
                }
            }
        }
        return false;
    }

    protected function markCustomerRemovedLocally($customer, $template, $node, $updateStore, $reason)
    {
        $serverType = $this->customerServerType($customer, $template, $node);
        $reason = trim((string) $reason);
        if ($reason === '') {
            $reason = $serverType === 'um' ? 'User no longer exists on the remote UM server.' : 'Client no longer exists on the remote 3x-ui node.';
        }
        $message = $serverType === 'um' ? 'Remote UM user was not found and has been marked Removed locally.' : 'Remote client was not found on 3x-ui and has been marked Removed locally.';
        $used = isset($customer['traffic_bytes_used']) ? (float) $customer['traffic_bytes_used'] : 0;
        $total = isset($customer['traffic_bytes_total']) ? (float) $customer['traffic_bytes_total'] : panel_to_bytes_from_gb(isset($customer['traffic_gb']) ? $customer['traffic_gb'] : 0);
        $left = max(0, $total - $used);
        $updates = array(
            'status' => 'removed',
            'traffic_bytes_left' => $left,
            'last_synced_at' => panel_now(),
            'last_error' => $reason,
        );
        if ($updateStore && !empty($customer['id'])) {
            $this->store->update('customers', $customer['id'], $updates);
            $customer = $this->store->find('customers', $customer['id']);
        } else {
            $customer = array_merge($customer, $updates);
        }
        if ($serverType === 'um') {
            $this->logUmEvent('access', 'Remote UM customer missing on node; marked removed locally.', array(
                'customer_id' => isset($customer['id']) ? $customer['id'] : '',
                'template_id' => $template ? $template['id'] : '',
                'node_id' => $node ? $node['id'] : '',
                'username' => $this->customerServiceUsername($customer),
                'message' => $reason,
            ));
        } else {
            $this->logXuiEvent('access', 'Remote customer missing on node; marked removed locally.', array(
                'customer_id' => isset($customer['id']) ? $customer['id'] : '',
                'template_id' => $template ? $template['id'] : '',
                'node_id' => $node ? $node['id'] : '',
                'message' => $reason,
            ));
        }
        return array(
            'ok' => true,
            'message' => $message,
            'customer' => $customer,
            'template' => $template,
            'node' => $node,
            'used_bytes' => $used,
            'removed_marked' => true,
        );
    }

    protected function shouldProbeRemoteCustomerPresence($traffic)
    {
        if (!is_array($traffic)) {
            return false;
        }
        $statusCode = isset($traffic['status_code']) ? (int) $traffic['status_code'] : 0;
        if ($statusCode === 404 || $statusCode === 410) {
            return true;
        }
        if ($statusCode >= 500 && $statusCode <= 599) {
            return false;
        }
        $message = strtolower(trim((string) panel_array_get($traffic, 'message', '')));
        if ($message === '') {
            return false;
        }
        $transientNeedles = array(
            'timed out',
            'timeout',
            'could not resolve host',
            'connection refused',
            'failed to connect',
            'empty reply from server',
            'ssl',
            'tls',
            'node request failed',
            'non-json response',
            'received non-json response',
            'forbidden',
            'unauthorized',
            'session expired',
            'login failed',
            'bad gateway',
            'gateway timeout',
            'service unavailable',
            'temporarily unavailable',
            'network is unreachable',
        );
        foreach ($transientNeedles as $needle) {
            if (strpos($message, $needle) !== false) {
                return false;
            }
        }
        return true;
    }

    protected function extractInboundClientsFromPayload($payload)
    {
        if (is_string($payload)) {
            $payload = panel_parse_multi_json($payload);
        }
        if (!is_array($payload)) {
            return array();
        }

        $clients = array();
        if (isset($payload['settings'])) {
            $settings = panel_parse_multi_json($payload['settings']);
            if (isset($settings['clients']) && is_array($settings['clients'])) {
                $clients = array_merge($clients, array_values($settings['clients']));
            }
        }
        if (isset($payload['clientStats']) && is_array($payload['clientStats'])) {
            $clients = array_merge($clients, array_values($payload['clientStats']));
        }
        if (isset($payload['clients']) && is_array($payload['clients'])) {
            $clients = array_merge($clients, array_values($payload['clients']));
        }
        foreach (array('obj', 'data', 'result', 'inbound') as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                $clients = array_merge($clients, $this->extractInboundClientsFromPayload($payload[$key]));
            }
        }
        return $clients;
    }

    protected function extractInboundClientsForPresenceProbe($inbound)
    {
        $clients = $this->extractInboundClientsFromPayload($inbound);
        if (!empty($clients)) {
            return array('ok' => true, 'clients' => array_values($clients));
        }
        return array('ok' => false, 'clients' => array());
    }

    protected function inboundRecordMatchesTemplate($record, $template)
    {
        if (!is_array($record) || !is_array($template)) {
            return false;
        }
        $recordId = (string) panel_array_get($record, 'id', panel_array_get($record, 'inboundId', ''));
        $templateId = (string) panel_array_get($template, 'inbound_id', '');
        if ($recordId !== '' && $templateId !== '' && $recordId === $templateId) {
            return true;
        }
        $recordRemark = strtolower(trim((string) panel_array_get($record, 'remark', panel_array_get($record, 'name', ''))));
        $templateRemark = strtolower(trim((string) panel_array_get($template, 'inbound_name', '')));
        return $recordRemark !== '' && $templateRemark !== '' && $recordRemark === $templateRemark;
    }

    protected function confirmRemoteCustomerMissingOnNode($adapter, $template, $customer)
    {
        if (!$adapter || !$template) {
            return array('probe_ok' => false, 'confirmed_missing' => false, 'node_reachable' => false, 'message' => 'Presence probe unavailable.');
        }

        $messages = array();
        $nodeReachable = false;
        if (!empty($template['inbound_id'])) {
            $inbound = $adapter->getInbound($template['inbound_id']);
            if (!empty($inbound['ok'])) {
                $nodeReachable = true;
                $parsed = $this->extractInboundClientsForPresenceProbe($inbound);
                if (!empty($parsed['ok'])) {
                    foreach ((array) $parsed['clients'] as $client) {
                        if ($this->inboundClientMatchesCustomer($customer, $client)) {
                            return array('probe_ok' => true, 'confirmed_missing' => false, 'node_reachable' => true, 'message' => 'Remote customer still exists on the assigned inbound.');
                        }
                    }
                    return array('probe_ok' => true, 'confirmed_missing' => true, 'node_reachable' => true, 'message' => 'Remote customer not present in the assigned inbound client list.');
                }
                $messages[] = 'Assigned inbound response could not be parsed.';
            } else {
                $messages[] = isset($inbound['message']) ? (string) $inbound['message'] : 'Assigned inbound lookup failed.';
            }
        }

        $list = $adapter->listInbounds();
        if (!empty($list['ok'])) {
            $nodeReachable = true;
            $records = array();
            $data = panel_array_get($list, 'data', array());
            if (isset($data[0]) && is_array($data[0])) {
                $records = $data;
            } elseif (is_array($data)) {
                $records = array($data);
            }
            $probeOk = !empty($records);
            foreach ($records as $record) {
                if (!$this->inboundRecordMatchesTemplate($record, $template)) {
                    continue;
                }
                $parsed = $this->extractInboundClientsForPresenceProbe($record);
                if (empty($parsed['ok'])) {
                    continue;
                }
                $probeOk = true;
                foreach ((array) $parsed['clients'] as $client) {
                    if ($this->inboundClientMatchesCustomer($customer, $client)) {
                        return array('probe_ok' => true, 'confirmed_missing' => false, 'node_reachable' => true, 'message' => 'Remote customer still exists in the node inbound list.');
                    }
                }
                return array('probe_ok' => true, 'confirmed_missing' => true, 'node_reachable' => true, 'message' => 'Remote customer not present in the node inbound list.');
            }
            if ($probeOk) {
                return array('probe_ok' => true, 'confirmed_missing' => true, 'node_reachable' => true, 'message' => 'Assigned inbound was not present in the node list, so the remote customer was treated as absent.');
            }
            $messages[] = 'Node inbound list was empty.';
        } else {
            $messages[] = isset($list['message']) ? (string) $list['message'] : 'Node inbound list probe failed.';
        }

        $message = trim(implode(' ', array_filter($messages)));
        if ($message === '') {
            $message = 'Presence probe unavailable.';
        }
        return array('probe_ok' => false, 'confirmed_missing' => false, 'node_reachable' => $nodeReachable, 'message' => $message);
    }

    protected function inboundClientMatchesCustomer($customer, $client)
    {
        if (!is_array($client)) {
            return false;
        }
        $emails = array();
        foreach (array(panel_array_get($customer, 'remote_email', ''), panel_array_get($customer, 'system_name', '')) as $value) {
            $value = strtolower(trim((string) $value));
            if ($value !== '') {
                $emails[$value] = true;
            }
        }
        $ids = array();
        foreach (array(panel_array_get($customer, 'remote_client_id', ''), panel_array_get($customer, 'uuid', ''), panel_array_get($customer, 'remote_sub_id', '')) as $value) {
            $value = strtolower(trim((string) $value));
            if ($value !== '') {
                $ids[$value] = true;
            }
        }

        foreach (array('email', 'remark', 'name') as $field) {
            $value = strtolower(trim((string) panel_array_get($client, $field, '')));
            if ($value !== '' && isset($emails[$value])) {
                return true;
            }
        }
        foreach (array('id', 'password', 'subId', 'sub_id', 'uuid') as $field) {
            $value = strtolower(trim((string) panel_array_get($client, $field, '')));
            if ($value !== '' && isset($ids[$value])) {
                return true;
            }
        }
        return false;
    }

    protected function refreshCustomerUsageFromNode($customer, $updateStore)
    {
        $template = $this->store->find('templates', isset($customer['template_id']) ? $customer['template_id'] : '');
        $node = $template ? $this->store->find('nodes', $template['node_id']) : null;
        if (!$template || !$node) {
            return array('ok' => false, 'message' => 'Template or node not found.', 'customer' => $customer, 'template' => $template, 'node' => $node, 'used_bytes' => isset($customer['traffic_bytes_used']) ? (float) $customer['traffic_bytes_used'] : 0);
        }
        if (isset($node['status']) && $node['status'] !== 'active') {
            return array('ok' => false, 'message' => 'The node is disabled.', 'customer' => $customer, 'template' => $template, 'node' => $node, 'used_bytes' => isset($customer['traffic_bytes_used']) ? (float) $customer['traffic_bytes_used'] : 0);
        }

        $adapter = $this->nodeAdapter($node);
        if ($this->customerServerType($customer, $template, $node) === 'um') {
            $usage = $adapter->getUserUsage($customer);
            if (empty($usage['ok'])) {
                if (empty($usage['user_exists']) || $this->remoteClientLooksMissing($usage)) {
                    $reason = panel_array_get($usage, 'message', 'Remote UM user not found.');
                    return $this->markCustomerRemovedLocally($customer, $template, $node, $updateStore, $reason);
                }
                $this->logUmEvent('error', 'Remote UM customer usage sync failed.', array('customer_id' => panel_array_get($customer, 'id', ''), 'template_id' => panel_array_get($template, 'id', ''), 'node_id' => panel_array_get($node, 'id', ''), 'username' => $this->customerServiceUsername($customer), 'message' => panel_array_get($usage, 'message', 'UM usage lookup failed.')));
                return array('ok' => false, 'message' => panel_array_get($usage, 'message', 'UM usage lookup failed.'), 'customer' => $customer, 'template' => $template, 'node' => $node, 'used_bytes' => isset($customer['traffic_bytes_used']) ? (float) $customer['traffic_bytes_used'] : 0);
            }
            $used = (float) panel_array_get($usage, 'used_bytes', 0);
            $total = isset($customer['traffic_bytes_total']) ? (float) $customer['traffic_bytes_total'] : panel_to_bytes_from_gb((float) panel_array_get($customer, 'traffic_gb', panel_array_get($template, 'billing_gb', 0)));
            $left = max(0, $total - $used);
            $updates = array('traffic_bytes_used' => $used, 'traffic_bytes_left' => $left, 'last_synced_at' => panel_now(), 'last_error' => '');
            $remoteExpires = trim((string) panel_array_get($usage, 'expires_at', ''));
            if ($remoteExpires !== '') { $updates['expires_at'] = $remoteExpires; }
            $remoteUser = panel_array_get($usage, 'user', array());
            if (is_array($remoteUser) && !empty($remoteUser)) {
                $updates['um_remote_user_id'] = trim((string) panel_array_get($remoteUser, 'id', panel_array_get($customer, 'um_remote_user_id', '')));
                $updates['um_remote_user_menu'] = trim((string) panel_array_get($remoteUser, 'menu', panel_array_get($customer, 'um_remote_user_menu', '')));
                if ($updates['um_remote_user_id'] !== '') {
                    $updates['remote_client_id'] = $updates['um_remote_user_id'];
                }
            }
            $remoteStatus = trim((string) panel_array_get($usage, 'remote_status', ''));
            if (in_array($remoteStatus, array('active', 'disabled'), true)) {
                $updates['status'] = $remoteStatus;
            }
            $remoteLastOnlineAt = trim((string) panel_array_get($usage, 'last_online_at', ''));
            if ($remoteLastOnlineAt !== '') {
                $updates['last_online_at'] = $remoteLastOnlineAt;
            }
            if ($updateStore && !empty($customer['id'])) {
                $this->store->update('customers', $customer['id'], $updates);
                $customer = $this->store->find('customers', $customer['id']);
            } else {
                $customer = array_merge($customer, $updates);
            }
            $this->logUmEvent('access', 'Remote UM customer usage synced.', array('customer_id' => panel_array_get($customer, 'id', ''), 'template_id' => panel_array_get($template, 'id', ''), 'node_id' => panel_array_get($node, 'id', ''), 'username' => $this->customerServiceUsername($customer), 'used_bytes' => $used));
            return array('ok' => true, 'message' => panel_array_get($usage, 'message', 'UM customer usage synced.'), 'customer' => $customer, 'template' => $template, 'node' => $node, 'used_bytes' => $used, 'removed_marked' => !empty($updates['status']) && $updates['status'] === 'removed');
        }
        $traffic = $adapter->getClientTraffic(isset($customer['remote_email']) ? $customer['remote_email'] : $customer['system_name']);
        if (empty($traffic['ok'])) {
            $missingProbe = array('probe_ok' => false, 'confirmed_missing' => false, 'node_reachable' => false, 'message' => '');
            $suspectedMissing = false;
            $explicitMissing = $this->remoteClientLooksExplicitlyMissing($traffic);
            if ($this->remoteClientLooksMissing($traffic) || $this->shouldProbeRemoteCustomerPresence($traffic)) {
                $suspectedMissing = $this->remoteClientLooksMissing($traffic);
                $missingProbe = $this->confirmRemoteCustomerMissingOnNode($adapter, $template, $customer);
                if (!empty($missingProbe['confirmed_missing']) || ($explicitMissing && !empty($missingProbe['node_reachable']) && empty($missingProbe['probe_ok']))) {
                    $reason = isset($traffic['message']) ? trim((string) $traffic['message']) : '';
                    $probeMessage = isset($missingProbe['message']) ? trim((string) $missingProbe['message']) : '';
                    if ($probeMessage !== '') {
                        $reason = $reason !== '' ? ($reason . ' Confirmed by node reachability check: ' . $probeMessage) : $probeMessage;
                    }
                    return $this->markCustomerRemovedLocally($customer, $template, $node, $updateStore, $reason !== '' ? $reason : 'Remote client not found.');
                }
            }
            $message = isset($traffic['message']) ? (string) $traffic['message'] : 'Remote traffic lookup failed.';
            if (!empty($missingProbe['probe_ok']) && empty($missingProbe['confirmed_missing'])) {
                $message .= ' Remote client still exists on the inbound, so it was kept active.';
            } elseif ($suspectedMissing && empty($missingProbe['probe_ok'])) {
                if ($explicitMissing && !empty($missingProbe['node_reachable'])) {
                    $message .= ' Remote node was reachable, but the client could not be extracted from inbound lists; it will be treated as Removed on the next confirmed missing pass.';
                } else {
                    $message .= ' Missing-client response was seen, but the node could not confirm absence yet, so the customer was kept unchanged.';
                }
            }
            $this->logXuiEvent('error', 'Remote customer usage sync failed.', array(
                'customer_id' => isset($customer['id']) ? $customer['id'] : '',
                'template_id' => $template['id'],
                'node_id' => $node['id'],
                'message' => $message,
                'presence_probe' => $missingProbe,
                'suspected_missing' => $suspectedMissing,
            ));
            return array('ok' => false, 'message' => $message, 'customer' => $customer, 'template' => $template, 'node' => $node, 'used_bytes' => isset($customer['traffic_bytes_used']) ? (float) $customer['traffic_bytes_used'] : 0, 'presence_probe' => $missingProbe, 'suspected_missing' => $suspectedMissing);
        }

        $usage = $this->extractTrafficUsage($traffic);
        $used = $usage['used_bytes'];
        $total = isset($customer['traffic_bytes_total']) ? (float) $customer['traffic_bytes_total'] : panel_to_bytes_from_gb(isset($customer['traffic_gb']) ? $customer['traffic_gb'] : 0);
        $left = max(0, $total - $used);
        $updates = array('traffic_bytes_used' => $used, 'traffic_bytes_left' => $left, 'last_synced_at' => panel_now(), 'last_online_at' => $usage['last_online_at'], 'last_error' => '');
        if ($updateStore && isset($customer['id'])) {
            $this->store->update('customers', $customer['id'], $updates);
            $customer = $this->store->find('customers', $customer['id']);
        } else {
            $customer = array_merge($customer, $updates);
        }

        $this->logXuiEvent('access', 'Remote customer usage synced.', array('customer_id' => isset($customer['id']) ? $customer['id'] : '', 'template_id' => $template['id'], 'node_id' => $node['id'], 'used_bytes' => $used));
        return array('ok' => true, 'message' => 'Customer usage synced.', 'customer' => $customer, 'template' => $template, 'node' => $node, 'used_bytes' => $used, 'traffic' => $traffic);
    }

    protected function extractTrafficUsage($traffic)
    {
        $row = is_array(isset($traffic['data']) ? $traffic['data'] : null) ? $traffic['data'] : array();
        if (!isset($row['up']) && !isset($row['down']) && isset($traffic['raw']['obj'][0]) && is_array($traffic['raw']['obj'][0])) {
            $row = $traffic['raw']['obj'][0];
        }
        $used = (float) (isset($row['up']) ? $row['up'] : 0) + (float) (isset($row['down']) ? $row['down'] : 0);
        return array('row' => $row, 'used_bytes' => $used, 'last_online_at' => isset($row['lastOnline']) ? $row['lastOnline'] : '');
    }


    protected function normalizeCustomerPhone($value)
    {
        return preg_replace('/\D+/', '', (string) $value);
    }

    protected function normalizeCustomerEmail($value)
    {
        return strtolower(trim((string) $value));
    }

    protected function isValidCustomerEmail($value)
    {
        $value = $this->normalizeCustomerEmail($value);
        return $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    protected function normalizeGetIdentifier($value)
    {
        $value = trim((string) $value);
        if (strpos($value, '@') !== false) {
            return $this->normalizeCustomerEmail($value);
        }
        return $this->normalizeCustomerPhone($value);
    }

    protected function isValidCustomerPhone($value)
    {
        $value = $this->normalizeCustomerPhone($value);
        $len = strlen($value);
        return $len >= 6 && $len <= 20;
    }

    protected function isValidCustomerPin($value)
    {
        $value = trim((string) $value);
        return $value !== '' && (bool) preg_match('/^[A-Za-z0-9]{1,6}$/', $value);
    }


    protected function landingEnabled()
    {
        $settings = $this->landingSettings();
        return !empty($settings['landing_enabled']);
    }

    protected function landingSettings()
    {
        $cfg = $this->runtimeConfig();
        $appName = isset($cfg['app_name']) && trim((string) $cfg['app_name']) !== '' ? (string) $cfg['app_name'] : $this->appName();
        return array(
            'landing_enabled' => isset($cfg['landing_enabled']) ? (int) !!$cfg['landing_enabled'] : 1,
            'landing_badge' => isset($cfg['landing_badge']) ? (string) $cfg['landing_badge'] : 'Internet access • XUI & MikroTik UM • Self-service portal',
            'landing_title' => isset($cfg['landing_title']) ? (string) $cfg['landing_title'] : ($appName !== '' ? $appName : 'Internet Services Panel'),
            'landing_subtitle' => isset($cfg['landing_subtitle']) ? (string) $cfg['landing_subtitle'] : 'A modern provider-style portal for internet services, VPN, and VPS delivery. Customers can access their service page instantly while resellers and admins manage operations securely from one place.',
            'landing_primary_label' => isset($cfg['landing_primary_label']) ? (string) $cfg['landing_primary_label'] : 'Open Customer Access',
            'landing_primary_url' => isset($cfg['landing_primary_url']) ? (string) $cfg['landing_primary_url'] : '/get',
            'landing_secondary_label' => isset($cfg['landing_secondary_label']) ? (string) $cfg['landing_secondary_label'] : 'Login to Panel',
            'landing_secondary_url' => isset($cfg['landing_secondary_url']) ? (string) $cfg['landing_secondary_url'] : '/login',
            'landing_hero_image' => isset($cfg['landing_hero_image']) ? (string) $cfg['landing_hero_image'] : '',
            'landing_section_title' => isset($cfg['landing_section_title']) ? (string) $cfg['landing_section_title'] : 'Built for modern internet service delivery',
            'landing_section_text' => isset($cfg['landing_section_text']) ? (string) $cfg['landing_section_text'] : 'Present your service like a real provider website while keeping customer access, billing visibility, and operator workflows inside one secure panel.',
            'landing_feature_1_title' => isset($cfg['landing_feature_1_title']) ? (string) $cfg['landing_feature_1_title'] : 'Fast customer access',
            'landing_feature_1_body' => isset($cfg['landing_feature_1_body']) ? (string) $cfg['landing_feature_1_body'] : 'Give customers a direct way to open their service page, get setup details, and reach the right tools without waiting on manual support.',
            'landing_feature_2_title' => isset($cfg['landing_feature_2_title']) ? (string) $cfg['landing_feature_2_title'] : 'Clean reseller operations',
            'landing_feature_2_body' => isset($cfg['landing_feature_2_body']) ? (string) $cfg['landing_feature_2_body'] : 'Keep daily operations organized with customer management, credit tracking, activity logs, and one consistent workflow across XUI and MikroTik UM services.',
            'landing_feature_3_title' => isset($cfg['landing_feature_3_title']) ? (string) $cfg['landing_feature_3_title'] : 'Modern service presentation',
            'landing_feature_3_body' => isset($cfg['landing_feature_3_body']) ? (string) $cfg['landing_feature_3_body'] : 'Show a polished provider-style landing page while still supporting subscriptions, credentials, usage sync, and public delivery routes for both service types.',
            'landing_links_text' => isset($cfg['landing_links_text']) ? (string) $cfg['landing_links_text'] : "Customer Access|/get|ghost
Panel Login|/login|secondary
Support|/login|ghost",
            'landing_footer_note' => isset($cfg['landing_footer_note']) ? (string) $cfg['landing_footer_note'] : 'You can customize the landing text, buttons, links, and hero image at any time from Admin → Settings.',
        );
    }

    protected function normalizeLandingUrl($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        if ($value[0] === '/') {
            return $value;
        }
        if (preg_match('#^(https?:|mailto:|tel:)#i', $value)) {
            return $value;
        }
        return '';
    }

    protected function parseLandingLinks($raw)
    {
        $links = array();
        $lines = preg_split('/
|
|
/', (string) $raw);
        if (!is_array($lines)) {
            return $links;
        }
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            $label = isset($parts[0]) ? $parts[0] : '';
            $url = isset($parts[1]) ? $this->normalizeLandingUrl($parts[1]) : '';
            $variant = isset($parts[2]) ? strtolower($parts[2]) : 'ghost';
            if ($label === '' || $url === '') {
                continue;
            }
            if (!in_array($variant, array('primary', 'secondary', 'ghost'), true)) {
                $variant = 'ghost';
            }
            $links[] = array(
                'label' => $label,
                'url' => $url,
                'variant' => $variant,
                'external' => (bool) preg_match('#^(https?:|mailto:|tel:)#i', $url),
            );
            if (count($links) >= 12) {
                break;
            }
        }
        return $links;
    }

    protected function publicLandingPage($allowPreview)
    {
        $settings = $this->landingSettings();
        $preview = $allowPreview && $this->authCheck() && $this->authRole() === 'admin' && (string) $this->input('preview', '') === '1';
        if (empty($settings['landing_enabled']) && !$preview) {
            if ($this->authCheck()) {
                $this->redirect($this->authRole() === 'admin' ? '/admin/dashboard' : '/reseller/dashboard');
            }
            $this->redirect('/login');
        }
        $primaryUrl = $this->normalizeLandingUrl($settings['landing_primary_url']);
        $secondaryUrl = $this->normalizeLandingUrl($settings['landing_secondary_url']);
        if ($primaryUrl === '') { $primaryUrl = '/get'; }
        if ($secondaryUrl === '') { $secondaryUrl = '/login'; }
        $buttons = array(
            array(
                'label' => trim((string) $settings['landing_primary_label']) !== '' ? trim((string) $settings['landing_primary_label']) : 'Open Customer Access',
                'url' => $primaryUrl,
                'variant' => 'primary',
                'external' => (bool) preg_match('#^(https?:|mailto:|tel:)#i', $primaryUrl),
            ),
            array(
                'label' => trim((string) $settings['landing_secondary_label']) !== '' ? trim((string) $settings['landing_secondary_label']) : 'Login to Panel',
                'url' => $secondaryUrl,
                'variant' => 'secondary',
                'external' => (bool) preg_match('#^(https?:|mailto:|tel:)#i', $secondaryUrl),
            ),
        );
        $extraLinks = $this->parseLandingLinks(isset($settings['landing_links_text']) ? $settings['landing_links_text'] : '');
        $features = array();
        for ($i = 1; $i <= 3; $i++) {
            $titleKey = 'landing_feature_' . $i . '_title';
            $bodyKey = 'landing_feature_' . $i . '_body';
            $title = trim((string) (isset($settings[$titleKey]) ? $settings[$titleKey] : ''));
            $body = trim((string) (isset($settings[$bodyKey]) ? $settings[$bodyKey] : ''));
            if ($title === '' && $body === '') {
                continue;
            }
            $features[] = array('title' => $title, 'body' => $body);
        }
        $this->renderPublic('public_landing.php', array(
            'title' => trim((string) $settings['landing_title']) !== '' ? trim((string) $settings['landing_title']) : $this->appName(),
            'landing' => $settings,
            'landing_buttons' => $buttons,
            'landing_links' => $extraLinks,
            'landing_features' => $features,
            'landing_preview' => $preview,
            'public_shell_class' => 'landing-shell',
        ));
    }

    protected function publicGetRateSettings()
    {
        $s = $this->securitySettings();
        return array(
            'max_attempts' => max(3, min(20, (int) $s['login_max_attempts'])),
            'window_seconds' => max(60, (int) $s['login_window_seconds']),
            'lockout_seconds' => max(60, (int) $s['login_lockout_seconds']),
        );
    }

    protected function assertPublicGetRateAllowed($identifier)
    {
        $s = $this->publicGetRateSettings();
        $ip = strtolower($this->clientIp());
        $byIp = $this->assertRateLimitAllowed('client_get_ip', $ip, $s['max_attempts'], $s['window_seconds'], $s['lockout_seconds'], 'Too many client access attempts from this IP.');
        if (!$byIp['ok']) { return $byIp; }
        $identity = strtolower($ip . '|' . $this->normalizeGetIdentifier($identifier));
        return $this->assertRateLimitAllowed('client_get_identity', $identity, $s['max_attempts'], $s['window_seconds'], $s['lockout_seconds'], 'Too many client access attempts for this phone.');
    }

    protected function notePublicGetFailure($identifier)
    {
        $s = $this->publicGetRateSettings();
        $ip = strtolower($this->clientIp());
        $identity = strtolower($ip . '|' . $this->normalizeGetIdentifier($identifier));
        $this->hitRateLimit('client_get_ip', $ip, $s['window_seconds'], $s['lockout_seconds']);
        $this->hitRateLimit('client_get_identity', $identity, $s['window_seconds'], $s['lockout_seconds']);
    }

    protected function clearPublicGetFailure($identifier)
    {
        $ip = strtolower($this->clientIp());
        $identity = strtolower($ip . '|' . $this->normalizeGetIdentifier($identifier));
        $this->clearRateLimit('client_get_ip', $ip);
        $this->clearRateLimit('client_get_identity', $identity);
    }

    protected function findCustomersByAccessAndPin($identifier, $pin)
    {
        $identifier = trim((string) $identifier);
        $pin = trim((string) $pin);
        if ($identifier === '' || $pin === '') { return array(); }
        $mode = (strpos($identifier, '@') !== false) ? 'email' : 'phone';
        $value = $mode === 'email' ? $this->normalizeCustomerEmail($identifier) : $this->normalizeCustomerPhone($identifier);
        if ($value === '') { return array(); }
        $items = $this->store->filterBy('customers', function ($row) use ($mode, $value) {
            $field = $mode === 'email' ? 'email' : 'phone';
            return isset($row[$field]) && (string) $row[$field] === (string) $value;
        });
        $matches = array();
        foreach ($items as $item) {
            $hash = isset($item['access_pin_hash']) ? (string) $item['access_pin_hash'] : '';
            if ($hash === '' || !function_exists('password_verify')) {
                continue;
            }
            if (password_verify($pin, $hash)) {
                $matches[] = $item;
            }
        }
        usort($matches, function ($a, $b) {
            $at = isset($a['created_at']) ? strtotime($a['created_at']) : 0;
            $bt = isset($b['created_at']) ? strtotime($b['created_at']) : 0;
            if ($at === $bt) { return strcmp(isset($a['display_name']) ? $a['display_name'] : '', isset($b['display_name']) ? $b['display_name'] : ''); }
            if ($bt === $at) { return 0; }
            return ($bt > $at) ? 1 : -1;
        });
        return $matches;
    }

    protected function publicGetAccess()
    {
        $identifier = trim((string) $this->input('access', $this->input('phone', '')));
        $pin = trim((string) $this->input('pin', ''));
        $errors = array();
        $entries = array();
        if ($this->requestMethod === 'POST') {
            $limit = $this->assertPublicGetRateAllowed($identifier);
            if (!$limit['ok']) {
                $errors['auth'][] = $limit['message'];
                $this->appendSecurityLog('get', 'error', 'Public /get rate limit hit.', array('identifier' => $this->normalizeGetIdentifier($identifier), 'ip' => $this->clientIp(), 'message' => $limit['message']));
            } else {
                if (strpos($identifier, '@') !== false) {
                    if (!$this->isValidCustomerEmail($identifier)) { $errors['access'][] = 'Enter a valid email address.'; }
                } else {
                    if (!$this->isValidCustomerPhone($identifier)) { $errors['access'][] = 'Phone must contain only digits and be between 6 and 20 numbers.'; }
                }
                if (!$this->isValidCustomerPin($pin)) {
                    $errors['pin'][] = 'PIN must be 1 to 6 letters or numbers.';
                }
                if (!$errors) {
                    $matches = $this->findCustomersByAccessAndPin($identifier, $pin);
                    if (empty($matches)) {
                        $this->notePublicGetFailure($identifier);
                        $this->appendSecurityLog('get', 'error', 'Public /get access failed.', array('identifier' => $this->normalizeGetIdentifier($identifier), 'ip' => $this->clientIp()));
                        $errors['auth'][] = 'Phone or PIN is invalid.';
                    } else {
                        $this->clearPublicGetFailure($identifier);
                        $this->appendSecurityLog('get', 'access', 'Public /get access succeeded.', array('identifier' => $this->normalizeGetIdentifier($identifier), 'ip' => $this->clientIp(), 'matches' => count($matches)));
                        foreach ($matches as $customer) {
                            $template = $this->store->find('templates', isset($customer['template_id']) ? $customer['template_id'] : '');
                            $node = $template ? $this->store->find('nodes', isset($template['node_id']) ? $template['node_id'] : '') : null;
                            $entries[] = $this->buildCustomerPublicPayload($customer, $template, $node);
                        }
                    }
                }
            }
        }
        $this->renderPublic('public_get.php', array(
            'title' => 'Get Configs',
            'csrf_token' => $this->csrfToken(),
            'access' => $identifier,
            'entries' => $entries,
            'errors' => $errors,
        ));
    }

    public function qrImageUrl($value)
    {
        $value = trim((string) $value);
        if ($value === '') { return ''; }
        $hash = sha1($value);
        $dir = $this->storage . '/cache/qrcodes';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $dataFile = $dir . '/' . $hash . '.txt';
        if (!is_file($dataFile) || (string) @file_get_contents($dataFile) !== $value) {
            @file_put_contents($dataFile, $value, LOCK_EX);
        }
        return $this->url('/__qr/' . $hash);
    }

    protected function serveLocalQr($token = '')
    {
        $token = trim((string) $token);
        $data = '';
        $dir = $this->storage . '/cache/qrcodes';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        if ($token !== '' && preg_match('/^[a-f0-9]{40}$/', $token)) {
            $dataFile = $dir . '/' . $token . '.txt';
            if (is_file($dataFile)) {
                $data = (string) @file_get_contents($dataFile);
            }
        }
        if ($data === '') {
            $data = trim((string) $this->input('d', ''));
            $token = $data !== '' ? sha1($data) : '';
        }
        if ($data === '' || strlen($data) > 4096) {
            return $this->serveQrFallbackSvg('QR unavailable');
        }
        $cacheFile = $dir . '/' . $token . '.svg';
        if (is_file($cacheFile) && filesize($cacheFile) > 80) {
            return $this->sendQrSvg((string) @file_get_contents($cacheFile));
        }
        $svg = $this->generateLocalQrSvg($data);
        if ($svg === '') {
            return $this->serveQrFallbackSvg('Copy the config');
        }
        @file_put_contents($cacheFile, $svg, LOCK_EX);
        return $this->sendQrSvg($svg);
    }

    protected function sendQrSvg($svg)
    {
        if (headers_sent()) { return $svg; }
        header('Content-Type: image/svg+xml; charset=UTF-8');
        header('Cache-Control: public, max-age=31536000, immutable');
        header('X-Content-Type-Options: nosniff');
        echo $svg;
        return null;
    }

    protected function serveQrFallbackSvg($label)
    {
        $label = trim((string) $label);
        if ($label === '') { $label = 'QR unavailable'; }
        $safe = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        $svg = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<svg xmlns="http://www.w3.org/2000/svg" width="180" height="180" viewBox="0 0 180 180">'
            . '<rect width="180" height="180" fill="#ffffff"/>'
            . '<rect x="1" y="1" width="178" height="178" rx="12" fill="#ffffff" stroke="#111827" stroke-width="2"/>'
            . '<text x="90" y="88" text-anchor="middle" font-family="Arial, sans-serif" font-size="13" fill="#111827">' . $safe . '</text>'
            . '<text x="90" y="108" text-anchor="middle" font-family="Arial, sans-serif" font-size="10" fill="#6b7280">Use copy button below</text>'
            . '</svg>';
        return $this->sendQrSvg($svg);
    }

    protected function generateLocalQrSvg($data)
    {
        $data = (string) $data;
        if ($data === '' || strlen($data) > 2950) { return ''; }
        $lib = PANEL_ROOT . '/app/lib/PurePhpQr.php';
        if (!is_file($lib)) { return ''; }
        require_once $lib;
        if (!class_exists('PurePhpQr', false)) { return ''; }
        try {
            $svg = PurePhpQr::svg($data, array('scale' => 6, 'border' => 2, 'ecc' => 0));
            if (is_string($svg) && stripos($svg, '<svg') !== false) {
                return $svg;
            }
        } catch (Exception $e) {
            $this->log('qr.log', '[' . date('c') . '] php-qr failed: ' . trim((string) $e->getMessage()));
        }
        return '';
    }

    protected function customerAllowsPublicConfigs($customer)
    {
        return $this->customerRuntimeState($customer) === 'active';
    }

    protected function customerPublicAccessMessage($customer)
    {
        $state = $this->customerRuntimeState($customer);
        if ($state === 'removed') {
            return 'This customer was removed from the remote server and is kept here only for record and visibility.';
        }
        if ($state === 'ended') {
            return 'This customer validity period has ended.';
        }
        if ($state === 'depleted') {
            return 'This customer traffic quota is fully used.';
        }
        if ($state === 'disabled') {
            return 'This customer is not currently active.';
        }
        return '';
    }

    protected function publicSubscription($subKey)
    {
        $subscriptionLimit = $this->assertSubscriptionRateAllowed($subKey);
        if (!$subscriptionLimit['ok']) { $this->abort(429, $subscriptionLimit['message']); }
        $customer = $this->store->findBy('customers', 'subscription_key', $subKey);
        if (!$customer) { $this->abort(404, 'Subscription not found.'); }
        $template = $this->store->find('templates', $customer['template_id']);
        $node = $template ? $this->store->find('nodes', $template['node_id']) : null;
        $entry = $this->buildCustomerPublicPayload($customer, $template, $node);
        $this->renderPublic('public_subscription.php', array('title' => 'Subscription', 'customer' => $customer, 'template' => $template, 'node' => $node, 'configs' => $entry['configs'], 'proxy_subscription_url' => $entry['proxy_subscription_url'], 'public_access_allowed' => $entry['public_access_allowed'], 'public_access_message' => $entry['public_access_message'], 'entry' => $entry));
    }
    protected function publicSubscriptionExport($subKey)
    {
        $subscriptionLimit = $this->assertSubscriptionRateAllowed($subKey . ':export');
        if (!$subscriptionLimit['ok']) { $this->abort(429, $subscriptionLimit['message']); }
        $customer = $this->store->findBy('customers', 'subscription_key', $subKey);
        if (!$customer) { $this->abort(404, 'Subscription not found.'); }
        if (!$this->customerAllowsPublicConfigs($customer)) {
            $this->abort(403, 'This customer is not active and cannot export subscription links.');
        }
        $template = $this->store->find('templates', $customer['template_id']);
        $node = $template ? $this->store->find('nodes', $template['node_id']) : null;
        $this->sendCommonHeaders('text/plain; charset=utf-8');
        if ($this->customerServerType($customer, $template, $node) === 'um') {
            echo implode("
", $this->buildUmAccessLines($customer, $template, $node));
        } else {
            $configs = $this->buildSubscriptionConfigs($customer, $template, $node);
            echo implode("
", $configs);
        }
        exit;
    }

    protected function ticketsPage($scope)
    {
        $tickets = $this->store->all('tickets');
        if ($scope === 'reseller') {
            $reseller = $this->currentReseller();
            $tickets = array_values(array_filter($tickets, function ($item) use ($reseller) { return isset($item['creator_role'], $item['creator_id']) && $item['creator_role'] === 'reseller' && $item['creator_id'] === $reseller['id']; }));
        }
        usort($tickets, array($this, 'sortNewest'));
        $this->renderPanel('tickets_index.php', array('title' => 'Tickets', 'scope' => $scope, 'tickets' => $tickets, 'reseller_map' => $this->resellerMap()));
    }

    protected function ticketForm($scope)
    {
        $this->renderPanel('ticket_form.php', array('title' => 'New ticket', 'scope' => $scope, 'errors' => array(), 'record' => array('subject' => '', 'priority' => 'normal', 'body' => '')));
    }

    protected function saveTicket($scope)
    {
        $subject = trim((string) $this->input('subject', ''));
        $priority = trim((string) $this->input('priority', 'normal'));
        $body = trim((string) $this->input('body', ''));
        $errors = array();
        if (strlen($subject) < 3) { $errors['subject'][] = 'Subject must be at least 3 characters.'; }
        if (strlen($body) < 3) { $errors['body'][] = 'Message must be at least 3 characters.'; }
        if (!in_array($priority, array('low', 'normal', 'high'), true)) { $priority = 'normal'; }
        if ($errors) { return $this->renderPanel('ticket_form.php', array('title' => 'New ticket', 'scope' => $scope, 'errors' => $errors, 'record' => array('subject' => $subject, 'priority' => $priority, 'body' => $body))); }
        $creatorId = $scope === 'admin' ? $this->authUser()['id'] : $this->currentReseller()['id'];
        $ticket = $this->store->insert('tickets', array('ticket_no' => 'TCK-' . gmdate('Ymd') . '-' . strtoupper(panel_random_hex(5)), 'creator_role' => $scope, 'creator_id' => $creatorId, 'subject' => $subject, 'priority' => $priority, 'status' => 'open', 'last_reply_at' => panel_now()), 'tkt');
        $this->store->insert('ticket_messages', array('ticket_id' => $ticket['id'], 'sender_role' => $scope, 'sender_id' => $creatorId, 'body' => $body, 'seen' => 0), 'msg');
        $this->flash('success', 'Ticket created.');
        $this->redirect('/' . $scope . '/tickets/' . $ticket['id']);
    }

    protected function ticketView($scope, $id)
    {
        $ticket = $this->loadTicketForScope($id, $scope);
        $messages = $this->store->filterBy('ticket_messages', function ($item) use ($id) { return isset($item['ticket_id']) && $item['ticket_id'] === $id; });
        usort($messages, array($this, 'sortOldest'));
        $this->renderPanel('ticket_show.php', array('title' => 'Ticket', 'scope' => $scope, 'ticket' => $ticket, 'messages' => $messages, 'reseller_map' => $this->resellerMap()));
    }

    protected function replyTicket($scope, $id)
    {
        $ticket = $this->loadTicketForScope($id, $scope);
        $body = trim((string) $this->input('body', ''));
        if (strlen($body) < 2) { $this->flash('error', 'Reply is too short.'); $this->redirect('/' . $scope . '/tickets/' . $id); }
        $senderId = $scope === 'admin' ? $this->authUser()['id'] : $this->currentReseller()['id'];
        $this->store->insert('ticket_messages', array('ticket_id' => $id, 'sender_role' => $scope, 'sender_id' => $senderId, 'body' => $body, 'seen' => 0), 'msg');
        $nextStatus = $scope === 'admin' ? 'waiting-reseller' : 'waiting-admin';
        $this->store->update('tickets', $id, array('status' => $nextStatus, 'last_reply_at' => panel_now()));
        $this->flash('success', 'Reply posted.');
        $this->redirect('/' . $scope . '/tickets/' . $id);
    }

    protected function ticketStatus($scope, $id)
    {
        $ticket = $this->loadTicketForScope($id, $scope);
        $status = trim((string) $this->input('status', 'open'));
        if (!in_array($status, array('open', 'waiting-admin', 'waiting-reseller', 'closed'), true)) { $status = 'open'; }
        $this->store->update('tickets', $id, array('status' => $status, 'last_reply_at' => panel_now()));
        $this->flash('success', 'Ticket status updated.');
        $this->redirect('/' . $scope . '/tickets/' . $id);
    }


    protected function deleteTicket($scope, $id)
    {
        $ticket = $this->loadTicketForScope($id, $scope);
        if ($scope !== 'admin') {
            $this->abort(403, 'Forbidden');
        }
        $messages = $this->store->filterBy('ticket_messages', function ($item) use ($id) { return isset($item['ticket_id']) && $item['ticket_id'] === $id; });
        foreach ($messages as $message) {
            if (!empty($message['id'])) { $this->store->delete('ticket_messages', $message['id']); }
        }
        $this->store->delete('tickets', $ticket['id']);
        $this->flash('success', 'Ticket deleted.');
        $this->redirect('/admin/tickets');
    }

    protected function loadTicketForScope($id, $scope)
    {
        $ticket = $this->store->find('tickets', $id);
        if (!$ticket) { $this->flash('error', 'Ticket not found.'); $this->redirect('/' . $scope . '/tickets'); }
        if ($scope === 'reseller') {
            $reseller = $this->currentReseller();
            if ($ticket['creator_role'] !== 'reseller' || $ticket['creator_id'] !== $reseller['id']) { $this->abort(403, 'Forbidden'); }
        }
        return $ticket;
    }

    protected function currentReseller()
    {
        $auth = $this->authUser();
        $reseller = $this->store->find('resellers', $auth['id']);
        if (!$reseller) { $this->logout(); }
        if (empty($reseller['api_key'])) { $reseller['api_key'] = $this->resellerApiKey($reseller); }
        return $reseller;
    }

    protected function loadCustomerForScope($id, $resellerScoped)
    {
        $customer = $this->store->find('customers', $id);
        if (!$customer) { $this->flash('error', 'Customer not found.'); $this->redirect($resellerScoped ? '/reseller/customers' : '/admin/customers'); }
        if ($resellerScoped) {
            $reseller = $this->currentReseller();
            if ($customer['reseller_id'] !== $reseller['id']) { $this->abort(403, 'Forbidden'); }
        }
        return $customer;
    }

    protected function resellerTemplates($reseller)
    {
        $templates = array();
        $allowed = isset($reseller['allowed_template_ids']) ? (array) $reseller['allowed_template_ids'] : array();
        foreach ($allowed as $id) {
            $tpl = $this->store->find('templates', $id);
            if ($tpl && isset($tpl['status']) && $tpl['status'] === 'active') { $templates[] = $tpl; }
        }
        usort($templates, array($this, 'sortTemplate'));
        return $templates;
    }

    protected function resellerCanUseTemplate($reseller, $templateId)
    {
        $allowed = isset($reseller['allowed_template_ids']) ? (array) $reseller['allowed_template_ids'] : array();
        return in_array($templateId, $allowed, true);
    }

    protected function nodeMap() { $map = array(); foreach ($this->store->all('nodes') as $n) { $map[$n['id']] = $n; } return $map; }
    protected function templateMap() { $map = array(); foreach ($this->store->all('templates') as $n) { $map[$n['id']] = $n; } return $map; }
    protected function resellerMap() { $map = array(); foreach ($this->store->all('resellers') as $n) { $map[$n['id']] = $n; } return $map; }

    protected function saveCustomerLink($customer, $template, $node, $remoteClientId, $remoteEmail, $remoteSubId)
    {
        $link = $this->findCustomerLink($customer['id']);
        $payload = array('customer_id' => $customer['id'], 'template_id' => $template['id'], 'node_id' => $node ? $node['id'] : '', 'inbound_id' => $template['inbound_id'], 'remote_client_id' => $remoteClientId, 'remote_email' => $remoteEmail, 'remote_sub_id' => $remoteSubId, 'protocol' => isset($template['protocol']) ? $template['protocol'] : 'vless', 'server_type' => $this->templateServerType($template, $node));
        if ($link) { $this->store->update('customer_links', $link['id'], $payload); }
        else { $this->store->insert('customer_links', $payload, 'lnk'); }
    }

    protected function findCustomerLink($customerId)
    {
        $links = $this->store->filterBy('customer_links', function ($item) use ($customerId) { return isset($item['customer_id']) && $item['customer_id'] === $customerId; });
        return $links ? $links[0] : null;
    }

    protected function buildSubscriptionConfigs($customer, $template, $node)
    {
        if (!$template || !$node) {
            return array();
        }

        $protocol = strtolower(isset($template['protocol']) ? $template['protocol'] : 'vless');
        $stream = panel_parse_multi_json(isset($template['stream_settings_json']) ? $template['stream_settings_json'] : '');
        $settings = panel_parse_multi_json(isset($template['settings_json']) ? $template['settings_json'] : '');
        $port = isset($template['port']) && $template['port'] !== '' ? (int) $template['port'] : panel_guess_port(panel_array_get($stream, 'security', ''), 443);
        $address = $this->subscriptionSourceAddress($template, $node, $stream);
        $params = $this->buildSubscriptionParams($customer, $template, $stream, $settings, $address);
        $links = array();

        $externalLinks = array();
        $externalProxies = panel_array_get($stream, 'externalProxy', array());
        if (is_array($externalProxies) && !empty($externalProxies)) {
            $remoteLinks = $this->buildRemoteXuiSubscriptionConfigs($customer, $template, $node);
            if (!empty($remoteLinks)) {
                return $remoteLinks;
            }
        }
        if (is_array($externalProxies)) {
            foreach ($externalProxies as $externalProxy) {
                if (!is_array($externalProxy)) {
                    continue;
                }
                $dest = trim((string) panel_array_get($externalProxy, 'dest', ''));
                $proxyPort = (int) panel_array_get($externalProxy, 'port', 0);
                if ($dest === '' || $proxyPort <= 0) {
                    continue;
                }

                $proxyParams = $params;
                $newSecurity = strtolower((string) panel_array_get($externalProxy, 'forceTls', 'same'));
                if ($newSecurity !== '' && $newSecurity !== 'same') {
                    $proxyParams['security'] = $newSecurity;
                }
                if (isset($proxyParams['security']) && $proxyParams['security'] !== 'tls' && $proxyParams['security'] !== 'reality') {
                    unset($proxyParams['alpn'], $proxyParams['sni'], $proxyParams['fp'], $proxyParams['pbk'], $proxyParams['sid'], $proxyParams['spx'], $proxyParams['pqv']);
                }

                $proxyLink = $this->buildSubscriptionEndpointLink(
                    $protocol,
                    $customer,
                    $template,
                    $settings,
                    $dest,
                    $proxyPort,
                    $proxyParams,
                    trim((string) panel_array_get($externalProxy, 'remark', ''))
                );
                if ($proxyLink !== '') {
                    $externalLinks[] = $proxyLink;
                }
            }
        }

        $externalLinks = array_values(array_unique(array_filter($externalLinks)));
        if (!empty($externalLinks)) {
            return $externalLinks;
        }

        $baseLink = $this->buildSubscriptionEndpointLink($protocol, $customer, $template, $settings, $address, $port, $params, '');
        if ($baseLink !== '') {
            $links[] = $baseLink;
        }

        $links = array_values(array_unique(array_filter($links)));
        return $links;
    }

    protected function buildRemoteXuiSubscriptionConfigs($customer, $template, $node)
    {
        if (!$template || !$node || $this->customerServerType($customer, $template, $node) !== 'xui') {
            return array();
        }
        if (strtolower(trim((string) panel_array_get($node, 'status', 'active'))) !== 'active') {
            return array();
        }
        $email = trim((string) panel_array_get($customer, 'remote_email', panel_array_get($customer, 'system_name', '')));
        $subId = trim((string) panel_array_get($customer, 'remote_sub_id', panel_array_get($customer, 'subscription_key', '')));
        if ($email === '' && $subId === '') {
            return array();
        }
        $adapter = $this->nodeAdapter($node);
        if (!is_object($adapter) || !method_exists($adapter, 'getClientLinks')) {
            return array();
        }
        $result = $adapter->getClientLinks($email, $subId);
        if (empty($result['ok']) || empty($result['data']) || !is_array($result['data'])) {
            return array();
        }
        return $this->applyTemplateExtraQueryToLinks($result['data'], $template);
    }

    protected function parseRawQueryPairs($raw)
    {
        $raw = $this->normalizeClientExtraQuery($raw);
        if ($raw === '') {
            return array();
        }
        $out = array();
        foreach (explode('&', $raw) as $part) {
            $part = trim((string) $part);
            if ($part === '') {
                continue;
            }
            $eq = strpos($part, '=');
            if ($eq === false) {
                $key = rawurldecode($part);
                $value = '';
            } else {
                $key = rawurldecode(substr($part, 0, $eq));
                $value = rawurldecode(substr($part, $eq + 1));
            }
            $key = trim((string) $key);
            if ($key === '') {
                continue;
            }
            $out[$key] = (string) $value;
        }
        return $out;
    }

    protected function applyTemplateExtraQueryToLinks($links, $template)
    {
        $out = array();
        foreach ((array) $links as $link) {
            $patched = $this->applyTemplateExtraQueryToLink($link, $template);
            if ($patched !== '') {
                $out[] = $patched;
            }
        }
        return array_values(array_unique(array_filter($out)));
    }

    protected function applyTemplateExtraQueryToLink($link, $template)
    {
        $link = trim((string) $link);
        if ($link === '') {
            return '';
        }
        $extra = $this->parseClientExtraQueryParams($template);
        if (empty($extra)) {
            return $link;
        }

        if (stripos($link, 'vmess://') === 0) {
            $encoded = substr($link, 8);
            $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
            if ($decoded === false) {
                $decoded = base64_decode($encoded, true);
            }
            $payload = is_string($decoded) ? json_decode($decoded, true) : null;
            if (is_array($payload)) {
                foreach ($extra as $key => $value) {
                    $payload[$key] = (string) $value;
                }
                return 'vmess://' . base64_encode(json_encode($payload, JSON_UNESCAPED_SLASHES));
            }
            return $link;
        }

        $hash = '';
        $hashPos = strpos($link, '#');
        if ($hashPos !== false) {
            $hash = substr($link, $hashPos);
            $link = substr($link, 0, $hashPos);
        }
        $query = '';
        $queryPos = strpos($link, '?');
        if ($queryPos !== false) {
            $query = substr($link, $queryPos + 1);
            $link = substr($link, 0, $queryPos);
        }
        $params = $this->parseRawQueryPairs($query);
        foreach ($extra as $key => $value) {
            $params[$key] = (string) $value;
        }
        $qs = panel_qs($params);
        return $link . ($qs !== '' ? '?' . $qs : '') . $hash;
    }

    protected function subscriptionSourceAddress($template, $node, $stream)
    {
        $listen = isset($template['listen']) ? trim((string) $template['listen']) : '';
        if ($listen !== '' && !in_array($listen, array('0.0.0.0', '::', '::0'), true)) {
            return $listen;
        }
        return $this->nodeHostForExport($node, $stream);
    }

    protected function buildSubscriptionParams($customer, $template, $stream, $settings, $address)
    {
        $protocol = strtolower(isset($template['protocol']) ? $template['protocol'] : 'vless');
        $network = strtolower((string) panel_array_get($stream, 'network', isset($template['network']) ? $template['network'] : 'tcp'));
        $security = strtolower((string) panel_array_get($stream, 'security', isset($template['security']) ? $template['security'] : 'none'));
        $params = array();

        if ($protocol === 'vmess') {
            $params['network'] = $network;
        } else {
            $params['type'] = $network;
        }

        if ($protocol === 'vless') {
            $params['encryption'] = isset($settings['decryption']) && $settings['decryption'] !== '' ? (string) $settings['decryption'] : 'none';
        }

        switch ($network) {
            case 'tcp':
                $tcpSettings = panel_array_get($stream, 'tcpSettings', array());
                $headerType = (string) panel_array_get($tcpSettings, 'header.type', '');
                if ($headerType !== '' && $headerType !== 'none') {
                    if ($protocol === 'vmess') {
                        $params['vmess_type'] = $headerType;
                    } else {
                        $params['headerType'] = $headerType;
                    }
                    if ($headerType === 'http') {
                        $requestPath = panel_array_get($tcpSettings, 'header.request.path.0', '');
                        $requestHeaders = panel_array_get($tcpSettings, 'header.request.headers', array());
                        if ($requestPath !== '') {
                            $params['path'] = $requestPath;
                        }
                        $requestHost = '';
                        if (is_array($requestHeaders) && isset($requestHeaders['Host'])) {
                            $hostValue = $requestHeaders['Host'];
                            if (is_array($hostValue)) {
                                $requestHost = trim((string) reset($hostValue));
                            } else {
                                $requestHost = trim((string) $hostValue);
                            }
                        }
                        if ($requestHost !== '') {
                            $params['host'] = $requestHost;
                        }
                    }
                }
                if ($protocol === 'vless') {
                    $flow = (string) panel_array_get($settings, 'clients.0.flow', '');
                    if ($flow !== '') {
                        $params['flow'] = $flow;
                    }
                }
                break;

            case 'kcp':
                $kcpSettings = panel_array_get($stream, 'kcpSettings', array());
                $params['headerType'] = (string) panel_array_get($kcpSettings, 'header.type', '');
                $params['seed'] = (string) panel_array_get($kcpSettings, 'seed', '');
                break;

            case 'ws':
                $wsSettings = panel_array_get($stream, 'wsSettings', array());
                $params['path'] = (string) panel_array_get($wsSettings, 'path', '/');
                $host = (string) panel_array_get($wsSettings, 'host', '');
                if ($host === '') {
                    $host = (string) panel_array_get($wsSettings, 'headers.Host', '');
                }
                if ($host !== '') {
                    $params['host'] = $host;
                }
                break;

            case 'grpc':
                $grpcSettings = panel_array_get($stream, 'grpcSettings', array());
                $params['serviceName'] = (string) panel_array_get($grpcSettings, 'serviceName', '');
                $authority = (string) panel_array_get($grpcSettings, 'authority', '');
                if ($authority !== '') {
                    $params['authority'] = $authority;
                }
                if (panel_parse_bool(panel_array_get($grpcSettings, 'multiMode', false), false)) {
                    $params['mode'] = 'multi';
                } elseif ($protocol === 'vless' || $protocol === 'trojan') {
                    $params['mode'] = 'gun';
                }
                break;

            case 'httpupgrade':
                $httpupgrade = panel_array_get($stream, 'httpupgradeSettings', array());
                $params['path'] = (string) panel_array_get($httpupgrade, 'path', '/');
                $host = (string) panel_array_get($httpupgrade, 'host', '');
                if ($host === '') {
                    $host = (string) panel_array_get($httpupgrade, 'headers.Host', '');
                }
                if ($host !== '') {
                    $params['host'] = $host;
                }
                break;

            case 'xhttp':
                $xhttp = panel_array_get($stream, 'xhttpSettings', array());
                $params['path'] = (string) panel_array_get($xhttp, 'path', '/');
                $host = (string) panel_array_get($xhttp, 'host', '');
                if ($host === '') {
                    $host = (string) panel_array_get($xhttp, 'headers.Host', '');
                }
                if ($host !== '') {
                    $params['host'] = $host;
                }
                $mode = (string) panel_array_get($xhttp, 'mode', '');
                if ($mode !== '') {
                    $params['mode'] = $mode;
                }
                break;
        }

        $params['security'] = ($security === 'tls' || $security === 'reality') ? $security : 'none';

        if ($security === 'tls') {
            $tlsSettings = panel_array_get($stream, 'tlsSettings', array());
            $alpn = panel_array_get($tlsSettings, 'alpn', array());
            if (is_array($alpn) && !empty($alpn)) {
                $params['alpn'] = implode(',', $alpn);
            }
            $sni = (string) panel_array_get($tlsSettings, 'serverName', '');
            if ($sni !== '') {
                $params['sni'] = $sni;
            }
            $fp = (string) panel_array_get($tlsSettings, 'settings.fingerprint', '');
            if ($fp !== '') {
                $params['fp'] = $fp;
            }
        } elseif ($security === 'reality') {
            $realitySettings = panel_array_get($stream, 'realitySettings', array());
            $serverNames = panel_array_get($realitySettings, 'serverNames', array());
            if (is_array($serverNames) && !empty($serverNames)) {
                $params['sni'] = (string) reset($serverNames);
            }
            $pbk = (string) panel_array_get($realitySettings, 'settings.publicKey', '');
            if ($pbk === '') {
                $pbk = (string) panel_array_get($realitySettings, 'publicKey', '');
            }
            if ($pbk !== '') {
                $params['pbk'] = $pbk;
            }
            $shortIds = panel_array_get($realitySettings, 'shortIds', array());
            if (is_array($shortIds) && !empty($shortIds)) {
                $params['sid'] = (string) reset($shortIds);
            }
            $fp = (string) panel_array_get($realitySettings, 'settings.fingerprint', '');
            if ($fp === '') {
                $fp = (string) panel_array_get($realitySettings, 'fingerprint', '');
            }
            if ($fp !== '') {
                $params['fp'] = $fp;
            }
            $pqv = (string) panel_array_get($realitySettings, 'settings.mldsa65Verify', '');
            if ($pqv === '') {
                $pqv = (string) panel_array_get($realitySettings, 'mldsa65Verify', '');
            }
            if ($pqv !== '') {
                $params['pqv'] = $pqv;
            }
            $spiderX = (string) panel_array_get($realitySettings, 'settings.spiderX', '');
            if ($spiderX === '') {
                $spiderX = (string) panel_array_get($realitySettings, 'spiderX', '');
            }
            if ($spiderX !== '') {
                $params['spx'] = $spiderX;
            }
        }

        if (($protocol === 'vmess' || $protocol === 'trojan' || $protocol === 'shadowsocks') && isset($params['host']) && $params['host'] === '') {
            unset($params['host']);
        }

        if ((!isset($params['sni']) || $params['sni'] === '') && ($security === 'tls' || $security === 'reality')) {
            $params['sni'] = $address;
        }

        return $params;
    }

    protected function normalizeClientExtraQuery($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        if (strpos($value, '://') !== false) {
            $query = parse_url($value, PHP_URL_QUERY);
            $value = is_string($query) ? $query : '';
        }
        $hashPos = strpos($value, '#');
        if ($hashPos !== false) {
            $value = substr($value, 0, $hashPos);
        }
        $value = str_replace(array("\r", "\n"), '&', $value);
        $value = trim($value);
        $value = ltrim($value, '?&');
        while (strpos($value, '&&') !== false) {
            $value = str_replace('&&', '&', $value);
        }
        return substr($value, 0, 2000);
    }

    protected function parseClientExtraQueryParams($template)
    {
        $raw = $this->normalizeClientExtraQuery(is_array($template) && isset($template['client_extra_query']) ? $template['client_extra_query'] : '');
        if ($raw === '') {
            return array();
        }
        $out = array();
        foreach (explode('&', $raw) as $part) {
            $part = trim((string) $part);
            if ($part === '') {
                continue;
            }
            $eq = strpos($part, '=');
            if ($eq === false) {
                $key = rawurldecode($part);
                $value = '';
            } else {
                $key = rawurldecode(substr($part, 0, $eq));
                $value = rawurldecode(substr($part, $eq + 1));
            }
            $key = trim((string) $key);
            if ($key === '' || strlen($key) > 64 || !preg_match('/^[A-Za-z0-9_.:-]+$/', $key)) {
                continue;
            }
            if (strpos($key, '[') !== false || strpos($key, ']') !== false) {
                continue;
            }
            $out[$key] = (string) $value;
        }
        return $out;
    }

    protected function mergeClientExtraQueryParams($params, $template)
    {
        $params = is_array($params) ? $params : array();
        $extra = $this->parseClientExtraQueryParams($template);
        if (empty($extra)) {
            return $params;
        }
        foreach ($extra as $key => $value) {
            $params[$key] = $value;
        }
        return $params;
    }

    protected function buildSubscriptionEndpointLink($protocol, $customer, $template, $settings, $address, $port, $params, $remarkSuffix)
    {
        $protocol = strtolower((string) $protocol);
        $remark = $this->buildSubscriptionRemark($customer, $remarkSuffix);
        $params = $this->mergeClientExtraQueryParams($params, $template);

        if ($protocol === 'vmess') {
            $network = isset($params['network']) ? $params['network'] : 'tcp';
            $vmessType = isset($params['vmess_type']) && $params['vmess_type'] !== '' ? $params['vmess_type'] : 'none';
            $vmess = array(
                'v' => '2',
                'ps' => $remark,
                'add' => $address,
                'port' => (string) $port,
                'id' => isset($customer['uuid']) ? $customer['uuid'] : '',
                'aid' => '0',
                'net' => $network,
                'type' => $vmessType,
                'host' => (string) panel_array_get($params, 'host', $address),
                'path' => '',
                'tls' => isset($params['security']) && $params['security'] !== 'none' ? $params['security'] : '',
                'sni' => (string) panel_array_get($params, 'sni', ''),
            );
            if ($network === 'grpc') {
                $vmess['path'] = (string) panel_array_get($params, 'serviceName', '');
            } elseif (isset($params['path'])) {
                $vmess['path'] = (string) $params['path'];
            }
            if (isset($params['alpn'])) {
                $vmess['alpn'] = (string) $params['alpn'];
            }
            if (isset($params['fp'])) {
                $vmess['fp'] = (string) $params['fp'];
            }
            return 'vmess://' . base64_encode(json_encode($vmess, JSON_UNESCAPED_SLASHES));
        }

        if ($protocol === 'vless') {
            return 'vless://' . $customer['uuid'] . '@' . $address . ':' . (int) $port . '?' . panel_qs($params) . '#' . rawurlencode($remark);
        }

        if ($protocol === 'trojan') {
            return 'trojan://' . $customer['uuid'] . '@' . $address . ':' . (int) $port . '?' . panel_qs($params) . '#' . rawurlencode($remark);
        }

        if ($protocol === 'shadowsocks') {
            $method = (string) panel_array_get($settings, 'method', panel_array_get($settings, 'clients.0.method', 'aes-256-gcm'));
            $inboundPassword = (string) panel_array_get($settings, 'password', '');
            $encPart = $method . ':' . $customer['uuid'];
            if ($method !== '' && $method[0] === '2' && $inboundPassword !== '') {
                $encPart = $method . ':' . $inboundPassword . ':' . $customer['uuid'];
            }
            $secret = base64_encode($encPart);
            return 'ss://' . $secret . '@' . $address . ':' . (int) $port . '?' . panel_qs($params) . '#' . rawurlencode($remark);
        }

        return '';
    }

    protected function buildSubscriptionRemark($customer, $remarkSuffix)
    {
        $base = isset($customer['system_name']) ? (string) $customer['system_name'] : (isset($customer['display_name']) ? (string) $customer['display_name'] : 'subscription');
        $remarkSuffix = trim((string) $remarkSuffix);
        if ($remarkSuffix !== '') {
            return $base . '-' . $remarkSuffix;
        }
        return $base;
    }


    protected function replaceTrafficMarkerInClientName($name, $trafficGb)
    {
        $name = trim((string) $name);
        $replacement = preg_replace('/[^0-9.]/', '', panel_format_gb($trafficGb)) . 'gb';
        if ($replacement === 'gb') { $replacement = '0gb'; }
        if ($name === '') {
            return $replacement;
        }
        $segments = explode('-', $name);
        foreach ($segments as $index => $segment) {
            if (preg_match('/^\d+(?:\.\d+)?gb$/i', (string) $segment)) {
                $segments[$index] = $replacement;
                return implode('-', $segments);
            }
        }
        if (preg_match('/(^|-)\d+(?:\.\d+)?gb(?=-|$)/i', $name)) {
            return preg_replace_callback('/(^|-)\d+(?:\.\d+)?gb(?=-|$)/i', function ($m) use ($replacement) {
                return (isset($m[1]) ? $m[1] : '') . $replacement;
            }, $name, 1);
        }
        return $name;
    }

    protected function buildNodeSubscriptionUrl($node, $remoteSubId)
    {
        if (!$node || !is_array($node)) {
            return '';
        }
        $base = trim((string) (isset($node['subscription_base']) ? $node['subscription_base'] : ''));
        $remoteSubId = trim((string) $remoteSubId);
        if ($base === '' || $remoteSubId === '') {
            return '';
        }
        return rtrim($base, '/') . '/' . rawurlencode($remoteSubId);
    }

    protected function buildXuiClientSettings($customer, $template, $expireAt)
    {
        $protocol = strtolower(isset($template['protocol']) ? $template['protocol'] : 'vless');
        $settings = array(
            'email' => isset($customer['remote_email']) ? $customer['remote_email'] : $customer['system_name'],
            'enable' => isset($customer['status']) ? $customer['status'] === 'active' : true,
            'limitIp' => isset($customer['ip_limit']) ? (int) $customer['ip_limit'] : 0,
            'expiryTime' => $this->resolveXuiExpiryMillis($customer, $expireAt),
            'totalGB' => isset($customer['traffic_bytes_total']) ? (float) $customer['traffic_bytes_total'] : panel_to_bytes_from_gb(isset($customer['traffic_gb']) ? $customer['traffic_gb'] : 0),
            'subId' => isset($customer['remote_sub_id']) ? $customer['remote_sub_id'] : (isset($customer['subscription_key']) ? $customer['subscription_key'] : ''),
            'tgId' => '',
            'reset' => 0,
        );

        if ($protocol === 'trojan') {
            $settings['password'] = $customer['uuid'];
            return $settings;
        }
        if ($protocol === 'shadowsocks') {
            $templateSettings = panel_parse_multi_json(isset($template['settings_json']) ? $template['settings_json'] : '');
            $settings['method'] = (string) panel_array_get($templateSettings, 'method', panel_array_get($templateSettings, 'clients.0.method', 'aes-256-gcm'));
            $settings['password'] = $customer['uuid'];
            return $settings;
        }
        $settings['id'] = $customer['uuid'];
        if ($protocol === 'vless') {
            $templateSettings = panel_parse_multi_json(isset($template['settings_json']) ? $template['settings_json'] : '');
            $flow = (string) panel_array_get($templateSettings, 'clients.0.flow', '');
            if ($flow !== '') { $settings['flow'] = $flow; }
        }
        return $settings;
    }

    protected function generateClientCredential($protocol)
    {
        $protocol = strtolower((string) $protocol);
        if ($protocol === 'shadowsocks') {
            return panel_random_hex(16);
        }
        return $this->makeUuid();
    }

    protected function remoteClientIdentifier($protocol, $credential, $email)
    {
        $protocol = strtolower((string) $protocol);
        if ($protocol === 'trojan') {
            return (string) $credential;
        }
        if ($protocol === 'shadowsocks') {
            return (string) $email;
        }
        return (string) $credential;
    }

    protected function nodeHostForExport($node, $stream)
    {
        $host = parse_url(isset($node['base_url']) ? $node['base_url'] : '', PHP_URL_HOST);
        if (!$host) { $host = 'example.com'; }
        $realityServer = (string) panel_array_get($stream, 'realitySettings.dest', '');
        if ($realityServer !== '' && strpos($realityServer, ':') !== false) {
            $parts = explode(':', $realityServer, 2);
            if ($parts[0] !== '') { $host = $parts[0]; }
        }
        return $host;
    }

    public function customerExpirationMode($customer)
    {
        $mode = isset($customer['duration_mode']) ? strtolower(trim((string) $customer['duration_mode'])) : '';
        if ($mode !== 'first_use') {
            $mode = 'fixed';
        }
        return $mode;
    }

    public function customerExpirationLabel($customer)
    {
        $mode = $this->customerExpirationMode($customer);
        $days = isset($customer['duration_days']) ? (int) $customer['duration_days'] : 0;
        if ($mode === 'first_use') {
            if ($days > 0) {
                return 'First use + ' . $days . ' day(s)';
            }
            return 'Unlimited';
        }
        if (!empty($customer['expires_at'])) {
            return (string) $customer['expires_at'];
        }
        if ($days > 0) {
            return $days . ' day(s) from now';
        }
        return 'Unlimited';
    }


    public function customerRuntimeState($customer)
    {
        $status = isset($customer['status']) ? strtolower(trim((string) $customer['status'])) : 'active';
        if ($status === 'removed') {
            return 'removed';
        }
        if ($status !== 'active') {
            return 'disabled';
        }
        if ($this->customerIsExpired($customer)) {
            return 'ended';
        }
        $left = isset($customer['traffic_bytes_left']) ? (float) $customer['traffic_bytes_left'] : null;
        $total = isset($customer['traffic_bytes_total']) ? (float) $customer['traffic_bytes_total'] : null;
        if ($left !== null && $total !== null && $total > 0 && $left <= 0) {
            return 'depleted';
        }
        return 'active';
    }

    protected function customerIsExpired($customer)
    {
        $expiresAt = isset($customer['expires_at']) ? trim((string) $customer['expires_at']) : '';
        if ($expiresAt === '') {
            return false;
        }
        $ts = strtotime($expiresAt);
        if ($ts === false) {
            return false;
        }
        return $ts <= time();
    }

    public function customerRuntimeStatusLabel($customer)
    {
        $state = $this->customerRuntimeState($customer);
        if ($state === 'depleted') {
            return 'Depleted';
        }
        if ($state === 'ended') {
            return 'Ended';
        }
        if ($state === 'removed') {
            return 'Removed';
        }
        if ($state === 'disabled') {
            return 'Disabled';
        }
        return 'Active';
    }

    public function customerRuntimeStatusBadgeClass($customer)
    {
        $state = $this->customerRuntimeState($customer);
        if ($state === 'active') {
            return 'good';
        }
        if ($state === 'disabled' || $state === 'removed') {
            return 'muted';
        }
        return 'bad';
    }

    protected function customerMatchesBucket($customer, $bucket)
    {
        $state = $this->customerRuntimeState($customer);
        if ($bucket === 'live') {
            return $state === 'active';
        }
        if ($bucket === 'ended') {
            return in_array($state, array('depleted', 'ended', 'removed'), true);
        }
        return true;
    }

    protected function customerStateCounts($customers)
    {
        $counts = array('all' => 0, 'live' => 0, 'ended' => 0);
        foreach ((array) $customers as $customer) {
            $counts['all']++;
            if ($this->customerMatchesBucket($customer, 'live')) {
                $counts['live']++;
            }
            if ($this->customerMatchesBucket($customer, 'ended')) {
                $counts['ended']++;
            }
        }
        return $counts;
    }

    protected function resolveXuiExpiryMillis($customer, $expireAt)
    {
        $mode = $this->customerExpirationMode($customer);
        $days = isset($customer['duration_days']) ? (int) $customer['duration_days'] : 0;
        if ($mode === 'first_use') {
            if ($days > 0) {
                return -1 * ($days * 86400 * 1000);
            }
            return 0;
        }
        return ((int) $expireAt) > 0 ? (((int) $expireAt) * 1000) : 0;
    }

    public function appLink($path)
    {
        $cfg = $this->runtimeConfig();
        $base = panel_base_url(isset($cfg['app_url']) ? $cfg['app_url'] : '');
        if ($base === '') {
            $base = rtrim(panel_request_origin(), '/') . $this->basePath;
        }
        return panel_url_join($base, $path);
    }

    protected function changeResellerCredit($resellerId, $amountGb, $type, $note)
    {
        $reseller = $this->store->find('resellers', $resellerId);
        if (!$reseller) { return; }
        $new = round((float) $reseller['credit_gb'] + (float) $amountGb, 2);
        if ($new < 0) { $new = 0; }
        $this->store->update('resellers', $resellerId, array('credit_gb' => $new));
        $this->store->insert('credit_ledger', array('reseller_id' => $resellerId, 'amount_gb' => (float) $amountGb, 'type' => $type, 'note' => $note), 'led');
    }

    protected function renderAuth($view, $data)
    {
        $base = array('app' => $this, 'app_name' => $this->appName(), 'flash' => $this->flash(null, null), 'csrf_token' => $this->csrfToken(), 'shield_forms_enabled' => $this->shouldUsePageShieldForms(), 'active_notices' => $this->activeNotices('auth'));
        $data = array_replace($base, $data);
        $html = $this->captureView('layout_auth.php', $view, $data);
        $this->sendCommonHeaders('text/html; charset=utf-8');
        echo $this->maybeShieldHtml($html, isset($data['title']) ? $data['title'] : $this->appName());
        exit;
    }

    protected function renderPanel($view, $data)
    {
        $base = array('app' => $this, 'app_name' => $this->appName(), 'flash' => $this->flash(null, null), 'csrf_token' => $this->csrfToken(), 'auth' => $this->authUser(), 'current_path' => $this->requestPath, 'credit_unit' => $this->config('credit_unit', 'GB'), 'shield_forms_enabled' => $this->shouldUsePageShieldForms(), 'active_notices' => $this->activeNotices($this->authRole() === 'admin' ? 'admin' : 'reseller'));
        $data = array_replace($base, $data);
        $html = $this->captureView('layout_panel.php', $view, $data);
        $this->sendCommonHeaders('text/html; charset=utf-8');
        echo $this->maybeShieldHtml($html, isset($data['title']) ? $data['title'] : $this->appName());
        exit;
    }

    protected function renderPublic($view, $data)
    {
        $base = array('app' => $this, 'app_name' => $this->appName(), 'shield_forms_enabled' => $this->shouldUsePageShieldForms(), 'active_notices' => $this->activeNotices('public'));
        $data = array_replace($base, $data);
        $html = $this->captureView('layout_public.php', $view, $data);
        $this->sendCommonHeaders('text/html; charset=utf-8');
        echo $this->maybeShieldHtml($html, isset($data['title']) ? $data['title'] : $this->appName());
        exit;
    }

    public function json($data, $status)
    {
        if ($this->apiContext) { $this->apiCaptured = array('type' => 'json', 'status' => $status, 'data' => $data); throw new Exception('__API_STOP__'); }
        http_response_code($status);
        $this->sendCommonHeaders('application/json; charset=utf-8');
        echo json_encode($data);
        exit;
    }
    public function redirect($url)
    {
        $url = (string) $url;
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $url = $this->url($url);
        }
        if ($this->apiContext) { $this->apiCaptured = array('type' => 'redirect', 'url' => $url); throw new Exception('__API_STOP__'); }
        $this->sendCommonHeaders('text/html; charset=utf-8');
        header('Location: ' . $url);
        exit;
    }
    protected function abort($status, $message)
    {
        if ($this->apiContext) { $this->apiCaptured = array('type' => 'abort', 'status' => $status, 'message' => $message); throw new Exception('__API_STOP__'); }
        http_response_code($status);
        $this->sendCommonHeaders('text/plain; charset=utf-8');
        echo $message;
        exit;
    }


    protected function captureView($layout, $view, $data)
    {
        extract($data);
        $view_file = $this->viewPath . '/' . $view;
        ob_start();
        include $this->viewPath . '/' . $layout;
        return (string) ob_get_clean();
    }

    protected function sendCommonHeaders($contentType)
    {
        if (headers_sent()) { return; }
        header('Content-Type: ' . $contentType);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; connect-src 'self'; font-src 'self' data:; object-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
    }

    protected function requestIsSecure()
    {
        return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') || (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    }

    protected function validateSameOriginPost()
    {
        $origin = isset($_SERVER['HTTP_ORIGIN']) ? trim((string) $_SERVER['HTTP_ORIGIN']) : '';
        $referer = isset($_SERVER['HTTP_REFERER']) ? trim((string) $_SERVER['HTTP_REFERER']) : '';
        $fetchSite = isset($_SERVER['HTTP_SEC_FETCH_SITE']) ? strtolower(trim((string) $_SERVER['HTTP_SEC_FETCH_SITE'])) : '';
        $fetchMode = isset($_SERVER['HTTP_SEC_FETCH_MODE']) ? strtolower(trim((string) $_SERVER['HTTP_SEC_FETCH_MODE'])) : '';
        $fetchDest = isset($_SERVER['HTTP_SEC_FETCH_DEST']) ? strtolower(trim((string) $_SERVER['HTTP_SEC_FETCH_DEST'])) : '';

        if (strtolower($origin) === 'null') { $origin = ''; }
        if (strtolower($referer) === 'null') { $referer = ''; }

        $candidates = array();
        $candidates[] = panel_request_origin();
        $appUrl = (string) $this->config('app_url', '');
        if ($appUrl !== '') {
            $parts = @parse_url($appUrl);
            if (is_array($parts) && !empty($parts['scheme']) && !empty($parts['host'])) {
                $originUrl = $parts['scheme'] . '://' . $parts['host'];
                if (!empty($parts['port'])) {
                    $originUrl .= ':' . $parts['port'];
                }
                $candidates[] = $originUrl;
            }
        }

        $normalize = function ($value) {
            $value = trim((string) $value);
            if ($value === '' || strtolower($value) === 'null') { return ''; }
            $parts = @parse_url($value);
            if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
                return rtrim(strtolower($value), '/');
            }
            $scheme = strtolower((string) $parts['scheme']);
            $host = strtolower((string) $parts['host']);
            $port = isset($parts['port']) ? (int) $parts['port'] : 0;
            $defaultPort = ($scheme === 'https') ? 443 : 80;
            $out = $scheme . '://' . $host;
            if ($port > 0 && $port !== $defaultPort) {
                $out .= ':' . $port;
            }
            return $out;
        };

        $expected = array();
        foreach ($candidates as $candidate) {
            $n = $normalize($candidate);
            if ($n !== '' && !in_array($n, $expected, true)) {
                $expected[] = $n;
            }
        }

        if ($origin !== '') {
            return in_array($normalize($origin), $expected, true);
        }
        if ($referer !== '') {
            $refOrigin = '';
            $parts = @parse_url($referer);
            if (is_array($parts) && !empty($parts['scheme']) && !empty($parts['host'])) {
                $refOrigin = $parts['scheme'] . '://' . $parts['host'];
                if (!empty($parts['port'])) {
                    $refOrigin .= ':' . $parts['port'];
                }
            } else {
                $refOrigin = $referer;
            }
            return in_array($normalize($refOrigin), $expected, true);
        }

        // Some browsers/extensions and the optional page shield can turn navigational form posts
        // into opaque-origin requests, which surface as Origin: null and omit Referer. In that case,
        // fall back to Fetch Metadata and the existing CSRF token check instead of hard failing.
        if ($fetchSite === '' || $fetchSite === 'same-origin' || $fetchSite === 'same-site' || $fetchSite === 'none') {
            if ($fetchMode === '' || $fetchMode === 'navigate') {
                if ($fetchDest === '' || $fetchDest === 'document' || $fetchDest === 'iframe') {
                    return true;
                }
            }
        }

        return false;
    }

    protected function securitySettings()
    {
        $cfg = $this->runtimeConfig();
        return array(
            'app_name' => isset($cfg['app_name']) ? (string) $cfg['app_name'] : $this->config('app_name', 'XUI Reseller Panel'),
            'app_url' => isset($cfg['app_url']) ? (string) $cfg['app_url'] : '',
            'timezone' => isset($cfg['timezone']) ? (string) $cfg['timezone'] : 'UTC',
            'default_duration_days' => isset($cfg['default_duration_days']) ? max(1, (int) $cfg['default_duration_days']) : (int) $this->config('default_duration_days', 30),
            'login_max_attempts' => isset($cfg['login_max_attempts']) ? max(3, (int) $cfg['login_max_attempts']) : 8,
            'login_window_seconds' => isset($cfg['login_window_seconds']) ? max(60, (int) $cfg['login_window_seconds']) : 900,
            'login_lockout_seconds' => isset($cfg['login_lockout_seconds']) ? max(60, (int) $cfg['login_lockout_seconds']) : 900,
            'subscription_max_requests' => isset($cfg['subscription_max_requests']) ? max(10, (int) $cfg['subscription_max_requests']) : 60,
            'subscription_window_seconds' => isset($cfg['subscription_window_seconds']) ? max(10, (int) $cfg['subscription_window_seconds']) : 60,
            'page_shield_mode' => isset($cfg['page_shield_mode']) ? (string) $cfg['page_shield_mode'] : 'off',
            'page_shield_key' => isset($cfg['page_shield_key']) ? (string) $cfg['page_shield_key'] : '',
            'page_shield_forms' => isset($cfg['page_shield_forms']) ? (int) !!$cfg['page_shield_forms'] : 1,
            'js_hardening' => isset($cfg['js_hardening']) ? (int) !!$cfg['js_hardening'] : 1,
            'api_enabled' => isset($cfg['api_enabled']) ? (int) !!$cfg['api_enabled'] : 0,
            'api_encryption' => isset($cfg['api_encryption']) ? (int) !!$cfg['api_encryption'] : 0,
            'telegram_enabled' => isset($cfg['telegram_enabled']) ? (int) !!$cfg['telegram_enabled'] : 0,
            'telegram_bot_token' => isset($cfg['telegram_bot_token']) ? (string) $cfg['telegram_bot_token'] : '',
            'telegram_mode' => isset($cfg['telegram_mode']) ? (string) $cfg['telegram_mode'] : 'webhook',
            'telegram_webhook_secret' => isset($cfg['telegram_webhook_secret']) ? (string) $cfg['telegram_webhook_secret'] : '',
            'telegram_proxy_enabled' => isset($cfg['telegram_proxy_enabled']) ? (int) !!$cfg['telegram_proxy_enabled'] : 0,
            'telegram_proxy_type' => isset($cfg['telegram_proxy_type']) ? (string) $cfg['telegram_proxy_type'] : 'http',
            'telegram_proxy_host' => isset($cfg['telegram_proxy_host']) ? (string) $cfg['telegram_proxy_host'] : '',
            'telegram_proxy_port' => isset($cfg['telegram_proxy_port']) ? (int) $cfg['telegram_proxy_port'] : 0,
            'telegram_proxy_username' => isset($cfg['telegram_proxy_username']) ? (string) $cfg['telegram_proxy_username'] : '',
            'telegram_proxy_password' => isset($cfg['telegram_proxy_password']) ? (string) $cfg['telegram_proxy_password'] : '',
            'telegram_allow_reseller' => isset($cfg['telegram_allow_reseller']) ? (int) !!$cfg['telegram_allow_reseller'] : 1,
            'telegram_allow_client' => isset($cfg['telegram_allow_client']) ? (int) !!$cfg['telegram_allow_client'] : 1,
            'telegram_allow_admin' => isset($cfg['telegram_allow_admin']) ? (int) !!$cfg['telegram_allow_admin'] : 0,
            'telegram_poll_limit' => isset($cfg['telegram_poll_limit']) ? max(1, (int) $cfg['telegram_poll_limit']) : 20,
            'panel_sync_enabled' => isset($cfg['panel_sync_enabled']) ? (int) !!$cfg['panel_sync_enabled'] : 0,
            'panel_sync_mode' => isset($cfg['panel_sync_mode']) ? (string) $cfg['panel_sync_mode'] : 'off',
            'panel_sync_master_url' => isset($cfg['panel_sync_master_url']) ? (string) $cfg['panel_sync_master_url'] : '',
            'panel_sync_shared_secret' => isset($cfg['panel_sync_shared_secret']) ? (string) $cfg['panel_sync_shared_secret'] : '',
            'panel_sync_interval_seconds' => isset($cfg['panel_sync_interval_seconds']) ? max(60, (int) $cfg['panel_sync_interval_seconds']) : 300,
            'panel_sync_prune_missing' => isset($cfg['panel_sync_prune_missing']) ? (int) !!$cfg['panel_sync_prune_missing'] : 0,
            'panel_sync_proxy_enabled' => isset($cfg['panel_sync_proxy_enabled']) ? (int) !!$cfg['panel_sync_proxy_enabled'] : 0,
            'panel_sync_proxy_type' => isset($cfg['panel_sync_proxy_type']) ? (string) $cfg['panel_sync_proxy_type'] : 'http',
            'panel_sync_proxy_host' => isset($cfg['panel_sync_proxy_host']) ? (string) $cfg['panel_sync_proxy_host'] : '',
            'panel_sync_proxy_port' => isset($cfg['panel_sync_proxy_port']) ? (int) $cfg['panel_sync_proxy_port'] : 0,
            'panel_sync_proxy_username' => isset($cfg['panel_sync_proxy_username']) ? (string) $cfg['panel_sync_proxy_username'] : '',
            'panel_sync_proxy_password' => isset($cfg['panel_sync_proxy_password']) ? (string) $cfg['panel_sync_proxy_password'] : '',
            'customer_sync_cron_enabled' => isset($cfg['customer_sync_cron_enabled']) ? (int) !!$cfg['customer_sync_cron_enabled'] : 0,
            'customer_sync_period_minutes' => isset($cfg['customer_sync_period_minutes']) ? max(1, (int) $cfg['customer_sync_period_minutes']) : 30,
            'customer_sync_retry_attempts' => isset($cfg['customer_sync_retry_attempts']) ? max(1, (int) $cfg['customer_sync_retry_attempts']) : 2,
            'customer_sync_batch_size' => isset($cfg['customer_sync_batch_size']) ? max(1, (int) $cfg['customer_sync_batch_size']) : 25,
            'customer_pagination_enabled' => isset($cfg['customer_pagination_enabled']) ? (int) !!$cfg['customer_pagination_enabled'] : 1,
            'customer_pagination_per_page' => isset($cfg['customer_pagination_per_page']) ? max(5, (int) $cfg['customer_pagination_per_page']) : 25,
            'customer_auto_sync_admin_enabled' => isset($cfg['customer_auto_sync_admin_enabled']) ? (int) !!$cfg['customer_auto_sync_admin_enabled'] : 1,
            'customer_auto_sync_reseller_enabled' => isset($cfg['customer_auto_sync_reseller_enabled']) ? (int) !!$cfg['customer_auto_sync_reseller_enabled'] : 1,
            'customer_auto_sync_batch_limit' => isset($cfg['customer_auto_sync_batch_limit']) ? max(1, (int) $cfg['customer_auto_sync_batch_limit']) : 8,
            'maintenance_cleanup_enabled' => isset($cfg['maintenance_cleanup_enabled']) ? (int) !!$cfg['maintenance_cleanup_enabled'] : 1,
            'maintenance_cleanup_period_hours' => isset($cfg['maintenance_cleanup_period_hours']) ? max(1, (int) $cfg['maintenance_cleanup_period_hours']) : 24,
            'maintenance_cleanup_max_age_days' => isset($cfg['maintenance_cleanup_max_age_days']) ? max(1, (int) $cfg['maintenance_cleanup_max_age_days']) : 30,
            'auto_backup_enabled' => isset($cfg['auto_backup_enabled']) ? (int) !!$cfg['auto_backup_enabled'] : 0,
            'auto_backup_period_hours' => isset($cfg['auto_backup_period_hours']) ? max(1, (int) $cfg['auto_backup_period_hours']) : 24,
            'auto_backup_rotation_count' => isset($cfg['auto_backup_rotation_count']) ? max(1, (int) $cfg['auto_backup_rotation_count']) : 10,
            'mask_removed_public_usage' => isset($cfg['mask_removed_public_usage']) ? (int) !!$cfg['mask_removed_public_usage'] : 0,
            'landing_enabled' => isset($cfg['landing_enabled']) ? (int) !!$cfg['landing_enabled'] : 1,
            'landing_badge' => isset($cfg['landing_badge']) ? (string) $cfg['landing_badge'] : 'Internet access • XUI & MikroTik UM • Self-service portal',
            'landing_title' => isset($cfg['landing_title']) ? (string) $cfg['landing_title'] : ((isset($cfg['app_name']) && trim((string) $cfg['app_name']) !== '') ? (string) $cfg['app_name'] : $this->appName()),
            'landing_subtitle' => isset($cfg['landing_subtitle']) ? (string) $cfg['landing_subtitle'] : 'A modern provider-style portal for internet services, VPN, and VPS delivery. Customers can access their service page instantly while resellers and admins manage operations securely from one place.',
            'landing_primary_label' => isset($cfg['landing_primary_label']) ? (string) $cfg['landing_primary_label'] : 'Open Customer Access',
            'landing_primary_url' => isset($cfg['landing_primary_url']) ? (string) $cfg['landing_primary_url'] : '/get',
            'landing_secondary_label' => isset($cfg['landing_secondary_label']) ? (string) $cfg['landing_secondary_label'] : 'Login to Panel',
            'landing_secondary_url' => isset($cfg['landing_secondary_url']) ? (string) $cfg['landing_secondary_url'] : '/login',
            'landing_hero_image' => isset($cfg['landing_hero_image']) ? (string) $cfg['landing_hero_image'] : '',
            'landing_section_title' => isset($cfg['landing_section_title']) ? (string) $cfg['landing_section_title'] : 'Built for modern internet service delivery',
            'landing_section_text' => isset($cfg['landing_section_text']) ? (string) $cfg['landing_section_text'] : 'Present your service like a real provider website while keeping customer access, billing visibility, and operator workflows inside one secure panel.',
            'landing_feature_1_title' => isset($cfg['landing_feature_1_title']) ? (string) $cfg['landing_feature_1_title'] : 'Fast customer access',
            'landing_feature_1_body' => isset($cfg['landing_feature_1_body']) ? (string) $cfg['landing_feature_1_body'] : 'Give customers a direct way to open their service page, get setup details, and reach the right tools without waiting on manual support.',
            'landing_feature_2_title' => isset($cfg['landing_feature_2_title']) ? (string) $cfg['landing_feature_2_title'] : 'Clean reseller operations',
            'landing_feature_2_body' => isset($cfg['landing_feature_2_body']) ? (string) $cfg['landing_feature_2_body'] : 'Keep daily operations organized with customer management, credit tracking, activity logs, and one consistent workflow across XUI and MikroTik UM services.',
            'landing_feature_3_title' => isset($cfg['landing_feature_3_title']) ? (string) $cfg['landing_feature_3_title'] : 'Modern service presentation',
            'landing_feature_3_body' => isset($cfg['landing_feature_3_body']) ? (string) $cfg['landing_feature_3_body'] : 'Show a polished provider-style landing page while still supporting subscriptions, credentials, usage sync, and public delivery routes for both service types.',
            'landing_links_text' => isset($cfg['landing_links_text']) ? (string) $cfg['landing_links_text'] : "Customer Access|/get|ghost
Panel Login|/login|secondary
Support|/login|ghost",
            'landing_footer_note' => isset($cfg['landing_footer_note']) ? (string) $cfg['landing_footer_note'] : 'You can customize the landing text, buttons, links, and hero image at any time from Admin → Settings.',
            'install_locked' => $this->isInstallLocked() ? 1 : 0,
        );
    }


    protected function panelSyncSettings()
    {
        $cfg = $this->runtimeConfig();
        $sharedSecret = isset($cfg['panel_sync_shared_secret']) ? trim((string) $cfg['panel_sync_shared_secret']) : '';
        if ($sharedSecret === '') {
            $sharedSecret = panel_random_hex(24);
        }
        return array(
            'enabled' => !empty($cfg['panel_sync_enabled']) ? 1 : 0,
            'mode' => isset($cfg['panel_sync_mode']) && in_array($cfg['panel_sync_mode'], array('off', 'master', 'slave'), true) ? (string) $cfg['panel_sync_mode'] : 'off',
            'master_url' => isset($cfg['panel_sync_master_url']) ? rtrim((string) $cfg['panel_sync_master_url'], '/') : '',
            'shared_secret' => $sharedSecret,
            'interval_seconds' => isset($cfg['panel_sync_interval_seconds']) ? max(60, (int) $cfg['panel_sync_interval_seconds']) : 300,
            'prune_missing' => !empty($cfg['panel_sync_prune_missing']) ? 1 : 0,
            'proxy_enabled' => !empty($cfg['panel_sync_proxy_enabled']) ? 1 : 0,
            'proxy_type' => isset($cfg['panel_sync_proxy_type']) ? trim((string) $cfg['panel_sync_proxy_type']) : 'http',
            'proxy_host' => isset($cfg['panel_sync_proxy_host']) ? trim((string) $cfg['panel_sync_proxy_host']) : '',
            'proxy_port' => isset($cfg['panel_sync_proxy_port']) ? (int) $cfg['panel_sync_proxy_port'] : 0,
            'proxy_username' => isset($cfg['panel_sync_proxy_username']) ? (string) $cfg['panel_sync_proxy_username'] : '',
            'proxy_password' => isset($cfg['panel_sync_proxy_password']) ? (string) $cfg['panel_sync_proxy_password'] : '',
        );
    }

    protected function panelSyncState()
    {
        $state = $this->store->readConfig('panel_sync_state');
        return is_array($state) ? $state : array();
    }

    protected function panelSyncStateSummary()
    {
        $state = $this->panelSyncState();
        return array(
            'last_run_at' => isset($state['last_run_at']) ? (string) $state['last_run_at'] : '',
            'last_status' => isset($state['last_status']) ? (string) $state['last_status'] : 'never',
            'last_message' => isset($state['last_message']) ? (string) $state['last_message'] : 'No sync has run yet.',
            'last_counts' => isset($state['last_counts']) && is_array($state['last_counts']) ? $state['last_counts'] : array(),
            'next_due_at' => isset($state['next_due_at']) ? (string) $state['next_due_at'] : '',
        );
    }

    protected function writePanelSyncState($state)
    {
        if (!is_array($state)) { $state = array(); }
        $this->store->writeConfig('panel_sync_state', $state);
    }

    public function panelSyncExportUrl()
    {
        return $this->appLink('/sync/export');
    }

    public function panelSyncRunUrl()
    {
        return $this->appLink('/sync/run');
    }

    protected function panelSyncRequestSecret($provided)
    {
        $secret = trim((string) $provided);
        if ($secret !== '') {
            return $secret;
        }
        $header = isset($_SERVER['HTTP_X_PANEL_SYNC_SECRET']) ? trim((string) $_SERVER['HTTP_X_PANEL_SYNC_SECRET']) : '';
        if ($header !== '') {
            return $header;
        }
        $auth = isset($_SERVER['HTTP_AUTHORIZATION']) ? trim((string) $_SERVER['HTTP_AUTHORIZATION']) : '';
        if ($auth !== '' && stripos($auth, 'Bearer ') === 0) {
            return trim(substr($auth, 7));
        }
        return '';
    }

    protected function panelSyncSecretMatches($provided)
    {
        $settings = $this->panelSyncSettings();
        $secret = $this->panelSyncRequestSecret($provided);
        return $secret !== '' && hash_equals((string) $settings['shared_secret'], (string) $secret);
    }

    protected function rateLimitFile($scope, $key)
    {
        return $this->storage . '/cache/rate_limits/' . sha1($scope . '|' . $key) . '.json';
    }

    protected function readRateLimit($scope, $key)
    {
        $file = $this->rateLimitFile($scope, $key);
        if (!is_file($file)) {
            return array('count' => 0, 'first_at' => 0, 'block_until' => 0, 'updated_at' => 0);
        }
        $decoded = json_decode((string) @file_get_contents($file), true);
        return is_array($decoded) ? $decoded : array('count' => 0, 'first_at' => 0, 'block_until' => 0, 'updated_at' => 0);
    }

    protected function writeRateLimit($scope, $key, $state)
    {
        $file = $this->rateLimitFile($scope, $key);
        $state['updated_at'] = time();
        @file_put_contents($file . '.tmp', json_encode($state));
        @rename($file . '.tmp', $file);
    }

    protected function clearRateLimit($scope, $key)
    {
        $file = $this->rateLimitFile($scope, $key);
        if (is_file($file)) { @unlink($file); }
    }

    protected function clientIp()
    {
        $candidates = array('HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR');
        foreach ($candidates as $name) {
            if (empty($_SERVER[$name])) { continue; }
            $value = trim((string) $_SERVER[$name]);
            if ($name === 'HTTP_X_FORWARDED_FOR' && strpos($value, ',') !== false) {
                $parts = explode(',', $value);
                $value = trim($parts[0]);
            }
            if ($value !== '') { return $value; }
        }
        return 'unknown';
    }

    protected function assertRateLimitAllowed($scope, $key, $maxAttempts, $windowSeconds, $blockSeconds, $message)
    {
        $state = $this->readRateLimit($scope, $key);
        $now = time();
        if (isset($state['block_until']) && (int) $state['block_until'] > $now) {
            return array('ok' => false, 'message' => $message . ' Try again in ' . ((int) $state['block_until'] - $now) . ' seconds.');
        }
        if (!isset($state['first_at']) || ((int) $state['first_at']) <= 0 || ($now - (int) $state['first_at']) > $windowSeconds) {
            $state = array('count' => 0, 'first_at' => $now, 'block_until' => 0, 'updated_at' => $now);
            $this->writeRateLimit($scope, $key, $state);
        }
        if (isset($state['count']) && (int) $state['count'] >= $maxAttempts) {
            $state['block_until'] = $now + $blockSeconds;
            $this->writeRateLimit($scope, $key, $state);
            return array('ok' => false, 'message' => $message . ' Try again later.');
        }
        return array('ok' => true, 'message' => 'ok');
    }

    protected function hitRateLimit($scope, $key, $windowSeconds, $blockSeconds)
    {
        $state = $this->readRateLimit($scope, $key);
        $now = time();
        if (!isset($state['first_at']) || ((int) $state['first_at']) <= 0 || ($now - (int) $state['first_at']) > $windowSeconds) {
            $state = array('count' => 0, 'first_at' => $now, 'block_until' => 0, 'updated_at' => $now);
        }
        $state['count'] = isset($state['count']) ? ((int) $state['count'] + 1) : 1;
        if ((int) $state['count'] >= 1 && $blockSeconds > 0 && (int) $state['count'] >= 999999) {
            $state['block_until'] = $now + $blockSeconds;
        }
        $this->writeRateLimit($scope, $key, $state);
    }

    protected function assertLoginRateAllowed($role, $username)
    {
        $s = $this->securitySettings();
        $ip = $this->clientIp();
        $byIp = $this->assertRateLimitAllowed('login_ip', strtolower($ip), $s['login_max_attempts'], $s['login_window_seconds'], $s['login_lockout_seconds'], 'Too many login attempts from this IP.');
        if (!$byIp['ok']) { return $byIp; }
        $identity = strtolower($role . '|' . $username . '|' . $ip);
        return $this->assertRateLimitAllowed('login_identity', $identity, $s['login_max_attempts'], $s['login_window_seconds'], $s['login_lockout_seconds'], 'Too many login attempts for this account.');
    }

    protected function noteLoginFailure($role, $username)
    {
        $s = $this->securitySettings();
        $ip = $this->clientIp();
        $this->hitRateLimit('login_ip', strtolower($ip), $s['login_window_seconds'], $s['login_lockout_seconds']);
        $this->hitRateLimit('login_identity', strtolower($role . '|' . $username . '|' . $ip), $s['login_window_seconds'], $s['login_lockout_seconds']);
    }

    protected function clearLoginFailure($role, $username)
    {
        $ip = $this->clientIp();
        $this->clearRateLimit('login_ip', strtolower($ip));
        $this->clearRateLimit('login_identity', strtolower($role . '|' . $username . '|' . $ip));
    }

    protected function assertSubscriptionRateAllowed($subKey)
    {
        $s = $this->securitySettings();
        $ip = $this->clientIp();
        $global = $this->assertRateLimitAllowed('subscription_ip', strtolower($ip), $s['subscription_max_requests'], $s['subscription_window_seconds'], $s['subscription_window_seconds'], 'Too many subscription requests.');
        if (!$global['ok']) { return $global; }
        return $this->assertRateLimitAllowed('subscription_key', strtolower($ip . '|' . $subKey), $s['subscription_max_requests'], $s['subscription_window_seconds'], $s['subscription_window_seconds'], 'Too many requests for this subscription.');
    }

    protected function shouldUsePageShield()
    {
        if ($this->isAjax()) { return false; }
        $settings = $this->securitySettings();
        if ($settings['page_shield_mode'] === 'always') { return true; }
        if ($settings['page_shield_mode'] === 'http_only' && !$this->requestIsSecure()) { return true; }
        return false;
    }

    protected function shouldUsePageShieldForms()
    {
        $settings = $this->securitySettings();
        return $this->shouldUsePageShield() && !empty($settings['page_shield_forms']);
    }

    protected function maybeDecodeShieldPost()
    {
        if ($this->requestMethod !== 'POST') { return; }
        if (empty($_POST['__shield']) || empty($_POST['__shield_iv']) || empty($_POST['__shield_payload'])) { return; }
        $settings = $this->securitySettings();
        $keyB64 = trim((string) $settings['page_shield_key']);
        if ($keyB64 === '' || !function_exists('openssl_decrypt')) { return; }
        $key = base64_decode($keyB64, true);
        $iv = base64_decode((string) $_POST['__shield_iv'], true);
        $payload = base64_decode((string) $_POST['__shield_payload'], true);
        if ($key === false || $iv === false || $payload === false || strlen($key) < 32 || strlen($iv) !== 16) { return; }
        $plain = openssl_decrypt($payload, 'AES-256-CBC', substr($key, 0, 32), OPENSSL_RAW_DATA, $iv);
        if (!is_string($plain) || $plain === '') { return; }
        $decoded = json_decode($plain, true);
        if (!is_array($decoded) || !isset($decoded['fields']) || !is_array($decoded['fields'])) { return; }
        $fields = $decoded['fields'];
        foreach ($fields as $k => $v) {
            if (is_array($v)) {
                $clean = array();
                foreach ($v as $item) { $clean[] = is_scalar($item) ? (string) $item : ''; }
                $fields[$k] = $clean;
            } else {
                $fields[$k] = is_scalar($v) ? (string) $v : '';
            }
        }
        $_POST = $fields;
        $_REQUEST = array_merge($_GET, $_POST);
        $_SERVER['HTTP_X_PANEL_SHIELD'] = '1';
    }

    protected function ensureClientShieldAsset()
    {
        $settings = $this->securitySettings();
        $key = trim((string) $settings['page_shield_key']);
        if ($key === '') { return false; }
        $path = PANEL_ROOT . '/public/assets/key.js';
        $content = "window.__PANEL_KEY__ = '" . str_replace("'", "\'", $key) . "';
";
        if (!is_file($path) || (string) @file_get_contents($path) !== $content) {
            @file_put_contents($path, $content);
        }
        return true;
    }

    protected function maybeShieldHtml($html, $title)
    {
        if (!$this->shouldUsePageShield()) { return $html; }
        $settings = $this->securitySettings();
        $keyB64 = trim((string) $settings['page_shield_key']);
        if ($keyB64 === '') { return $html; }
        $key = base64_decode($keyB64, true);
        if ($key === false || strlen($key) < 32 || !function_exists('openssl_encrypt')) { return $html; }
        $this->ensureClientShieldAsset();
        $iv = function_exists('random_bytes') ? random_bytes(16) : openssl_random_pseudo_bytes(16);
        $cipher = openssl_encrypt($html, 'AES-256-CBC', substr($key, 0, 32), OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) { return $html; }
        $payload = base64_encode($cipher);
        $ivB64 = base64_encode($iv);
        $loadingTitle = panel_e($title !== '' ? $title : $this->appName());
        $keySrc = panel_e($this->asset('key.js'));
        $bootstrap = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive,nosnippet,noimageindex">
<title>{$loadingTitle}</title>
<style>
html,body{margin:0;padding:0;min-height:100%;background:#081125;color:#e8edf7;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.shield-toast{position:fixed;left:16px;right:16px;bottom:16px;max-width:360px;padding:12px 14px;border-radius:14px;background:rgba(7,15,31,.92);border:1px solid rgba(71,120,255,.24);box-shadow:0 16px 40px rgba(0,0,0,.35);backdrop-filter:blur(8px);z-index:99999}
.shield-row{display:flex;align-items:center;gap:10px}
.shield-spinner{width:14px;height:14px;border-radius:50%;border:2px solid rgba(255,255,255,.22);border-top-color:#5e8fff;animation:shield-spin .85s linear infinite;flex:0 0 auto}
.shield-title{font-size:14px;font-weight:700;line-height:1.2;margin:0}
.shield-note{font-size:12px;line-height:1.35;color:#b8c5e3;margin:2px 0 0}
.shield-error{background:rgba(120,18,34,.95);border-color:rgba(255,97,122,.35)}
.shield-error .shield-note{color:#ffd5dc}
@keyframes shield-spin{to{transform:rotate(360deg)}}
@media (min-width:640px){.shield-toast{left:auto;right:20px}}
</style>
<script src="{$keySrc}"></script>
</head>
<body>
<div id="shieldToast" class="shield-toast" role="status" aria-live="polite">
  <div class="shield-row">
    <div class="shield-spinner" aria-hidden="true"></div>
    <div>
      <p class="shield-title">Secured page loading</p>
      <p class="shield-note">Decrypting this page in your browser…</p>
    </div>
  </div>
  <noscript><div class="shield-note" style="margin-top:8px">JavaScript is required for the optional page shield mode.</div></noscript>
</div>
<script>
(function(){
  function b64ToBytes(b){var s=atob(b),a=new Uint8Array(s.length),i;for(i=0;i<s.length;i++){a[i]=s.charCodeAt(i);}return a;}
  function decodeText(buf){if(window.TextDecoder){return new TextDecoder().decode(buf);}var a=new Uint8Array(buf),s='',i;for(i=0;i<a.length;i++){s+=String.fromCharCode(a[i]);}try{return decodeURIComponent(escape(s));}catch(e){return s;}}
  function fail(msg){var el=document.getElementById('shieldToast');if(!el){return;}el.className='shield-toast shield-error';el.innerHTML='<div class="shield-row"><div><p class="shield-title">Secure page mode failed</p><p class="shield-note">'+msg+'</p></div></div>';}
  if(!window.__PANEL_KEY__||!window.crypto||!window.crypto.subtle){fail('Your browser could not initialize the shield loader.');return;}
  var payload = '{$payload}';
  var iv = '{$ivB64}';
  window.crypto.subtle.importKey('raw', b64ToBytes(window.__PANEL_KEY__), {name:'AES-CBC'}, false, ['decrypt'])
    .then(function(key){ return window.crypto.subtle.decrypt({name:'AES-CBC', iv:b64ToBytes(iv)}, key, b64ToBytes(payload)); })
    .then(function(buf){ document.open(); document.write(decodeText(buf)); document.close(); })
    .catch(function(){ fail('Could not decrypt this response in the browser.'); });
})();
</script>
</body>
</html>
HTML;
        return $bootstrap;
    }


    protected function serveInternalAsset($name)
    {
        $name = basename((string) $name);
        if ($name === '' || !preg_match('/^[a-zA-Z0-9_.\-]+$/', $name)) {
            $this->abort(404, 'Asset not found.');
        }
        $path = PANEL_ROOT . '/public/assets/' . $name;
        if ($name === 'key.js') {
            $this->ensureClientShieldAsset();
        }
        if (!is_file($path)) {
            $this->abort(404, 'Asset not found.');
        }
        $content = (string) @file_get_contents($path);
        if ($content === '') {
            $this->abort(404, 'Asset not found.');
        }
        $settings = $this->securitySettings();
        if (preg_match('/\.js$/i', $name) && !empty($settings['js_hardening'])) {
            $content = $this->obfuscateJavascript($content, $name);
        }
        $this->sendCommonHeaders('application/javascript; charset=UTF-8');
        echo $content;
        exit;
    }

    
    protected function obfuscateJavascript($code, $name)
    {
        $plain = (string) $code;
        $seed = function_exists('random_bytes') ? random_bytes(8) : openssl_random_pseudo_bytes(8);
        $keyByte = ord($seed[0]) ^ 91;
        if ($keyByte <= 0) { $keyByte = 91; }
        $xor = '';
        for ($i = 0, $len = strlen($plain); $i < $len; $i++) {
            $xor .= chr(ord($plain[$i]) ^ $keyByte);
        }
        $payload = base64_encode(strrev($xor));
        $chunks = str_split($payload, 24);
        shuffle($chunks);
        $glue = implode('|', $chunks);
        $parts = explode('|', $glue);
        sort($parts);
        $joined = implode('', $parts);
        $arr = str_split($payload, 24);
        $serialized = json_encode($arr);
        $label = panel_e($name);
        $varA = 'p' . substr(md5($name . panel_random_hex(4)), 0, 6);
        $varB = 'k' . substr(md5(panel_random_hex(4) . $name), 0, 6);
        return "/* hardened asset: {$label} */
(function(){var {$varA}={$serialized},{$varB}={$keyByte};function r(s){return s.split('').reverse().join('');}function d(x){try{return decodeURIComponent(escape(atob(x)));}catch(e){return atob(x);}}var b={$varA}.join(''),raw=r(d(b)),out='',i;for(i=0;i<raw.length;i++){out+=String.fromCharCode(raw.charCodeAt(i)^{$varB});}(0,Function)(out)();}());";
    }

    protected function adminSettings()

    {
        $settings = $this->securitySettings();
        $this->renderPanel('admin_settings.php', array(
            'title' => 'Settings',
            'settings' => $settings,
            'backups' => $this->listBackups(),
            'errors' => array(),
            'shield_asset_url' => $this->asset('key.js'),
            'install_lock_path' => $this->installLockPath(),
            'sync_state' => $this->panelSyncStateSummary(),
            'sync_script_path' => PANEL_ROOT . '/scripts/panel_sync_cron.php',
            'maintenance_script_path' => PANEL_ROOT . '/scripts/cron.php',
        ));
    }

    protected function saveAdminSettings()
    {
        $current = $this->runtimeConfig();
        $data = array(
            'app_name' => trim((string) $this->input('app_name', isset($current['app_name']) ? $current['app_name'] : $this->appName())),
            'app_url' => trim((string) $this->input('app_url', isset($current['app_url']) ? $current['app_url'] : '')),
            'timezone' => trim((string) $this->input('timezone', isset($current['timezone']) ? $current['timezone'] : 'UTC')),
            'default_duration_days' => trim((string) $this->input('default_duration_days', isset($current['default_duration_days']) ? $current['default_duration_days'] : '30')),
            'login_max_attempts' => trim((string) $this->input('login_max_attempts', '8')),
            'login_window_seconds' => trim((string) $this->input('login_window_seconds', '900')),
            'login_lockout_seconds' => trim((string) $this->input('login_lockout_seconds', '900')),
            'subscription_max_requests' => trim((string) $this->input('subscription_max_requests', '60')),
            'subscription_window_seconds' => trim((string) $this->input('subscription_window_seconds', '60')),
            'page_shield_mode' => trim((string) $this->input('page_shield_mode', 'off')),
            'page_shield_forms' => isset($_POST['page_shield_forms']) ? '1' : '0',
            'js_hardening' => isset($_POST['js_hardening']) ? '1' : '0',
            'api_enabled' => isset($_POST['api_enabled']) ? '1' : '0',
            'api_encryption' => isset($_POST['api_encryption']) ? '1' : '0',
            'telegram_enabled' => isset($_POST['telegram_enabled']) ? '1' : '0',
            'telegram_bot_token' => trim((string) $this->input('telegram_bot_token', isset($current['telegram_bot_token']) ? $current['telegram_bot_token'] : '')),
            'telegram_mode' => trim((string) $this->input('telegram_mode', isset($current['telegram_mode']) ? $current['telegram_mode'] : 'webhook')),
            'telegram_webhook_secret' => trim((string) $this->input('telegram_webhook_secret', isset($current['telegram_webhook_secret']) ? $current['telegram_webhook_secret'] : '')),
            'telegram_proxy_enabled' => isset($_POST['telegram_proxy_enabled']) ? '1' : '0',
            'telegram_proxy_type' => trim((string) $this->input('telegram_proxy_type', isset($current['telegram_proxy_type']) ? $current['telegram_proxy_type'] : 'http')),
            'telegram_proxy_host' => trim((string) $this->input('telegram_proxy_host', isset($current['telegram_proxy_host']) ? $current['telegram_proxy_host'] : '')),
            'telegram_proxy_port' => trim((string) $this->input('telegram_proxy_port', isset($current['telegram_proxy_port']) ? $current['telegram_proxy_port'] : '0')),
            'telegram_proxy_username' => trim((string) $this->input('telegram_proxy_username', isset($current['telegram_proxy_username']) ? $current['telegram_proxy_username'] : '')),
            'telegram_proxy_password' => trim((string) $this->input('telegram_proxy_password', isset($current['telegram_proxy_password']) ? $current['telegram_proxy_password'] : '')),
            'telegram_allow_reseller' => isset($_POST['telegram_allow_reseller']) ? '1' : '0',
            'telegram_allow_client' => isset($_POST['telegram_allow_client']) ? '1' : '0',
            'telegram_allow_admin' => isset($_POST['telegram_allow_admin']) ? '1' : '0',
            'telegram_poll_limit' => trim((string) $this->input('telegram_poll_limit', isset($current['telegram_poll_limit']) ? $current['telegram_poll_limit'] : '20')),
            'panel_sync_enabled' => isset($_POST['panel_sync_enabled']) ? '1' : '0',
            'panel_sync_mode' => trim((string) $this->input('panel_sync_mode', isset($current['panel_sync_mode']) ? $current['panel_sync_mode'] : 'off')),
            'panel_sync_master_url' => trim((string) $this->input('panel_sync_master_url', isset($current['panel_sync_master_url']) ? $current['panel_sync_master_url'] : '')),
            'panel_sync_shared_secret' => trim((string) $this->input('panel_sync_shared_secret', isset($current['panel_sync_shared_secret']) ? $current['panel_sync_shared_secret'] : '')),
            'panel_sync_interval_seconds' => trim((string) $this->input('panel_sync_interval_seconds', isset($current['panel_sync_interval_seconds']) ? $current['panel_sync_interval_seconds'] : '300')),
            'panel_sync_prune_missing' => isset($_POST['panel_sync_prune_missing']) ? '1' : '0',
            'panel_sync_proxy_enabled' => isset($_POST['panel_sync_proxy_enabled']) ? '1' : '0',
            'panel_sync_proxy_type' => trim((string) $this->input('panel_sync_proxy_type', isset($current['panel_sync_proxy_type']) ? $current['panel_sync_proxy_type'] : 'http')),
            'panel_sync_proxy_host' => trim((string) $this->input('panel_sync_proxy_host', isset($current['panel_sync_proxy_host']) ? $current['panel_sync_proxy_host'] : '')),
            'panel_sync_proxy_port' => trim((string) $this->input('panel_sync_proxy_port', isset($current['panel_sync_proxy_port']) ? $current['panel_sync_proxy_port'] : '0')),
            'panel_sync_proxy_username' => trim((string) $this->input('panel_sync_proxy_username', isset($current['panel_sync_proxy_username']) ? $current['panel_sync_proxy_username'] : '')),
            'panel_sync_proxy_password' => trim((string) $this->input('panel_sync_proxy_password', isset($current['panel_sync_proxy_password']) ? $current['panel_sync_proxy_password'] : '')),
            'customer_sync_cron_enabled' => isset($_POST['customer_sync_cron_enabled']) ? '1' : '0',
            'customer_sync_period_minutes' => trim((string) $this->input('customer_sync_period_minutes', isset($current['customer_sync_period_minutes']) ? $current['customer_sync_period_minutes'] : '30')),
            'customer_sync_retry_attempts' => trim((string) $this->input('customer_sync_retry_attempts', isset($current['customer_sync_retry_attempts']) ? $current['customer_sync_retry_attempts'] : '2')),
            'customer_sync_batch_size' => trim((string) $this->input('customer_sync_batch_size', isset($current['customer_sync_batch_size']) ? $current['customer_sync_batch_size'] : '25')),
            'customer_pagination_enabled' => isset($_POST['customer_pagination_enabled']) ? '1' : '0',
            'customer_pagination_per_page' => trim((string) $this->input('customer_pagination_per_page', isset($current['customer_pagination_per_page']) ? $current['customer_pagination_per_page'] : '25')),
            'customer_auto_sync_admin_enabled' => isset($_POST['customer_auto_sync_admin_enabled']) ? '1' : '0',
            'customer_auto_sync_reseller_enabled' => isset($_POST['customer_auto_sync_reseller_enabled']) ? '1' : '0',
            'customer_auto_sync_batch_limit' => trim((string) $this->input('customer_auto_sync_batch_limit', isset($current['customer_auto_sync_batch_limit']) ? $current['customer_auto_sync_batch_limit'] : '8')),
            'maintenance_cleanup_enabled' => isset($_POST['maintenance_cleanup_enabled']) ? '1' : '0',
            'maintenance_cleanup_period_hours' => trim((string) $this->input('maintenance_cleanup_period_hours', isset($current['maintenance_cleanup_period_hours']) ? $current['maintenance_cleanup_period_hours'] : '24')),
            'maintenance_cleanup_max_age_days' => trim((string) $this->input('maintenance_cleanup_max_age_days', isset($current['maintenance_cleanup_max_age_days']) ? $current['maintenance_cleanup_max_age_days'] : '30')),
            'auto_backup_enabled' => isset($_POST['auto_backup_enabled']) ? '1' : '0',
            'auto_backup_period_hours' => trim((string) $this->input('auto_backup_period_hours', isset($current['auto_backup_period_hours']) ? $current['auto_backup_period_hours'] : '24')),
            'auto_backup_rotation_count' => trim((string) $this->input('auto_backup_rotation_count', isset($current['auto_backup_rotation_count']) ? $current['auto_backup_rotation_count'] : '10')),
            'mask_removed_public_usage' => isset($_POST['mask_removed_public_usage']) ? '1' : '0',
            'landing_enabled' => isset($_POST['landing_enabled']) ? '1' : '0',
            'landing_badge' => trim((string) $this->input('landing_badge', isset($current['landing_badge']) ? $current['landing_badge'] : 'Internet access • XUI & MikroTik UM • Self-service portal')),
            'landing_title' => trim((string) $this->input('landing_title', isset($current['landing_title']) ? $current['landing_title'] : (isset($current['app_name']) ? $current['app_name'] : $this->appName()))),
            'landing_subtitle' => trim((string) $this->input('landing_subtitle', isset($current['landing_subtitle']) ? $current['landing_subtitle'] : '')),
            'landing_primary_label' => trim((string) $this->input('landing_primary_label', isset($current['landing_primary_label']) ? $current['landing_primary_label'] : 'Open Customer Access')),
            'landing_primary_url' => trim((string) $this->input('landing_primary_url', isset($current['landing_primary_url']) ? $current['landing_primary_url'] : '/get')),
            'landing_secondary_label' => trim((string) $this->input('landing_secondary_label', isset($current['landing_secondary_label']) ? $current['landing_secondary_label'] : 'Login to Panel')),
            'landing_secondary_url' => trim((string) $this->input('landing_secondary_url', isset($current['landing_secondary_url']) ? $current['landing_secondary_url'] : '/login')),
            'landing_hero_image' => trim((string) $this->input('landing_hero_image', isset($current['landing_hero_image']) ? $current['landing_hero_image'] : '')),
            'landing_section_title' => trim((string) $this->input('landing_section_title', isset($current['landing_section_title']) ? $current['landing_section_title'] : 'Built for modern internet service delivery')),
            'landing_section_text' => trim((string) $this->input('landing_section_text', isset($current['landing_section_text']) ? $current['landing_section_text'] : '')),
            'landing_feature_1_title' => trim((string) $this->input('landing_feature_1_title', isset($current['landing_feature_1_title']) ? $current['landing_feature_1_title'] : 'Fast customer access')),
            'landing_feature_1_body' => trim((string) $this->input('landing_feature_1_body', isset($current['landing_feature_1_body']) ? $current['landing_feature_1_body'] : '')),
            'landing_feature_2_title' => trim((string) $this->input('landing_feature_2_title', isset($current['landing_feature_2_title']) ? $current['landing_feature_2_title'] : 'Clean reseller operations')),
            'landing_feature_2_body' => trim((string) $this->input('landing_feature_2_body', isset($current['landing_feature_2_body']) ? $current['landing_feature_2_body'] : '')),
            'landing_feature_3_title' => trim((string) $this->input('landing_feature_3_title', isset($current['landing_feature_3_title']) ? $current['landing_feature_3_title'] : 'Modern service presentation')),
            'landing_feature_3_body' => trim((string) $this->input('landing_feature_3_body', isset($current['landing_feature_3_body']) ? $current['landing_feature_3_body'] : '')),
            'landing_links_text' => trim((string) $this->input('landing_links_text', isset($current['landing_links_text']) ? $current['landing_links_text'] : '')),
            'landing_footer_note' => trim((string) $this->input('landing_footer_note', isset($current['landing_footer_note']) ? $current['landing_footer_note'] : '')),
            'regenerate_page_shield_key' => isset($_POST['regenerate_page_shield_key']) ? '1' : '0',
        );
        $errors = array();
        if (strlen($data['app_name']) < 3) { $errors['app_name'][] = 'Application name must be at least 3 characters.'; }
        if ($data['app_url'] !== '' && filter_var($data['app_url'], FILTER_VALIDATE_URL) === false) { $errors['app_url'][] = 'Application URL is not valid.'; }
        if ($data['timezone'] === '' || strlen($data['timezone']) > 100) { $errors['timezone'][] = 'Timezone is required.'; }
        if (!ctype_digit($data['default_duration_days']) || (int) $data['default_duration_days'] < 1) { $errors['default_duration_days'][] = 'Default duration must be a positive integer.'; }
        foreach (array('login_max_attempts','login_window_seconds','login_lockout_seconds','subscription_max_requests','subscription_window_seconds') as $field) {
            if (!ctype_digit($data[$field]) || (int) $data[$field] < 1) { $errors[$field][] = 'This field must be a positive integer.'; }
        }
        if (!in_array($data['page_shield_mode'], array('off', 'http_only', 'always'), true)) { $errors['page_shield_mode'][] = 'Page shield mode is invalid.'; }
        if (!in_array($data['telegram_mode'], array('webhook', 'polling'), true)) { $errors['telegram_mode'][] = 'Telegram mode is invalid.'; }
        if ($data['telegram_enabled'] === '1' && strlen($data['telegram_bot_token']) < 20) { $errors['telegram_bot_token'][] = 'Telegram bot token looks invalid.'; }
        if ($data['telegram_webhook_secret'] !== '' && !preg_match('/^[a-zA-Z0-9_-]{8,80}$/', $data['telegram_webhook_secret'])) { $errors['telegram_webhook_secret'][] = 'Telegram webhook secret is invalid.'; }
        if (!in_array($data['telegram_proxy_type'], array('http', 'https', 'socks5'), true)) { $errors['telegram_proxy_type'][] = 'Telegram proxy type is invalid.'; }
        if ($data['telegram_proxy_port'] !== '' && (!ctype_digit($data['telegram_proxy_port']) || (int) $data['telegram_proxy_port'] < 0 || (int) $data['telegram_proxy_port'] > 65535)) { $errors['telegram_proxy_port'][] = 'Telegram proxy port is invalid.'; }
        if (!ctype_digit($data['telegram_poll_limit']) || (int) $data['telegram_poll_limit'] < 1 || (int) $data['telegram_poll_limit'] > 100) { $errors['telegram_poll_limit'][] = 'Telegram poll limit must be between 1 and 100.'; }
        if (!in_array($data['panel_sync_mode'], array('off', 'master', 'slave'), true)) { $errors['panel_sync_mode'][] = 'Panel sync mode is invalid.'; }
        if ($data['panel_sync_enabled'] === '1' && $data['panel_sync_mode'] === 'slave' && ($data['panel_sync_master_url'] === '' || filter_var($data['panel_sync_master_url'], FILTER_VALIDATE_URL) === false)) { $errors['panel_sync_master_url'][] = 'Master panel URL is required in slave mode.'; }
        if ($data['panel_sync_master_url'] !== '' && filter_var($data['panel_sync_master_url'], FILTER_VALIDATE_URL) === false) { $errors['panel_sync_master_url'][] = 'Master panel URL is invalid.'; }
        if ($data['panel_sync_shared_secret'] !== '' && !preg_match('/^[a-zA-Z0-9_-]{8,120}$/', $data['panel_sync_shared_secret'])) { $errors['panel_sync_shared_secret'][] = 'Sync secret is invalid.'; }
        if (!ctype_digit($data['panel_sync_interval_seconds']) || (int) $data['panel_sync_interval_seconds'] < 60 || (int) $data['panel_sync_interval_seconds'] > 86400) { $errors['panel_sync_interval_seconds'][] = 'Sync interval must be between 60 and 86400 seconds.'; }
        if (!in_array($data['panel_sync_proxy_type'], array('http', 'https', 'socks5'), true)) { $errors['panel_sync_proxy_type'][] = 'Sync proxy type is invalid.'; }
        if ($data['panel_sync_proxy_port'] !== '' && (!ctype_digit($data['panel_sync_proxy_port']) || (int) $data['panel_sync_proxy_port'] < 0 || (int) $data['panel_sync_proxy_port'] > 65535)) { $errors['panel_sync_proxy_port'][] = 'Sync proxy port is invalid.'; }
        if (!ctype_digit($data['customer_sync_period_minutes']) || (int) $data['customer_sync_period_minutes'] < 1 || (int) $data['customer_sync_period_minutes'] > 1440) { $errors['customer_sync_period_minutes'][] = 'Customer sync period must be between 1 and 1440 minutes.'; }
        if (!ctype_digit($data['customer_sync_retry_attempts']) || (int) $data['customer_sync_retry_attempts'] < 1 || (int) $data['customer_sync_retry_attempts'] > 5) { $errors['customer_sync_retry_attempts'][] = 'Customer sync retries must be between 1 and 5.'; }
        if (!ctype_digit($data['customer_sync_batch_size']) || (int) $data['customer_sync_batch_size'] < 1 || (int) $data['customer_sync_batch_size'] > 500) { $errors['customer_sync_batch_size'][] = 'Customer sync window must be between 1 and 500 customers per run.'; }
        if (!ctype_digit($data['customer_pagination_per_page']) || (int) $data['customer_pagination_per_page'] < 5 || (int) $data['customer_pagination_per_page'] > 250) { $errors['customer_pagination_per_page'][] = 'Customers per page must be between 5 and 250.'; }
        if (!ctype_digit($data['customer_auto_sync_batch_limit']) || (int) $data['customer_auto_sync_batch_limit'] < 1 || (int) $data['customer_auto_sync_batch_limit'] > 100) { $errors['customer_auto_sync_batch_limit'][] = 'Visible auto sync batch must be between 1 and 100 customers.'; }
        if (!ctype_digit($data['maintenance_cleanup_period_hours']) || (int) $data['maintenance_cleanup_period_hours'] < 1 || (int) $data['maintenance_cleanup_period_hours'] > 720) { $errors['maintenance_cleanup_period_hours'][] = 'Cleanup period must be between 1 and 720 hours.'; }
        if (!ctype_digit($data['maintenance_cleanup_max_age_days']) || (int) $data['maintenance_cleanup_max_age_days'] < 1 || (int) $data['maintenance_cleanup_max_age_days'] > 3650) { $errors['maintenance_cleanup_max_age_days'][] = 'Cleanup max age must be between 1 and 3650 days.'; }
        if (!ctype_digit($data['auto_backup_period_hours']) || (int) $data['auto_backup_period_hours'] < 1 || (int) $data['auto_backup_period_hours'] > 720) { $errors['auto_backup_period_hours'][] = 'Auto backup period must be between 1 and 720 hours.'; }
        if (!ctype_digit($data['auto_backup_rotation_count']) || (int) $data['auto_backup_rotation_count'] < 1 || (int) $data['auto_backup_rotation_count'] > 1000) { $errors['auto_backup_rotation_count'][] = 'Backup rotation count must be between 1 and 1000.'; }
        foreach (array('landing_title','landing_primary_label','landing_secondary_label','landing_section_title','landing_feature_1_title','landing_feature_2_title','landing_feature_3_title') as $field) {
            if (strlen($data[$field]) > 160) { $errors[$field][] = 'This field is too long.'; }
        }
        foreach (array('landing_badge','landing_footer_note') as $field) {
            if (strlen($data[$field]) > 220) { $errors[$field][] = 'This field is too long.'; }
        }
        foreach (array('landing_subtitle','landing_section_text','landing_feature_1_body','landing_feature_2_body','landing_feature_3_body') as $field) {
            if (strlen($data[$field]) > 1000) { $errors[$field][] = 'This field is too long.'; }
        }
        if ($data['landing_hero_image'] !== '' && $this->normalizeLandingUrl($data['landing_hero_image']) === '') { $errors['landing_hero_image'][] = 'Hero image URL must be an absolute http/https URL, mailto/tel, or a root-relative path.'; }
        foreach (array('landing_primary_url','landing_secondary_url') as $field) {
            if ($this->normalizeLandingUrl($data[$field]) === '') { $errors[$field][] = 'This button URL must be an absolute http/https URL, mailto/tel, or a root-relative path.'; }
        }
        if (strlen($data['landing_links_text']) > 4000) { $errors['landing_links_text'][] = 'Extra landing links are too long.'; }
        if ($data['landing_links_text'] !== '') {
            $parsedLandingLinks = $this->parseLandingLinks($data['landing_links_text']);
            if (empty($parsedLandingLinks)) {
                $errors['landing_links_text'][] = 'At least one valid extra link line is required when this field is filled. Use Label|URL or Label|URL|variant.';
            }
        }
        if ($errors) {
            return $this->renderPanel('admin_settings.php', array('title' => 'Settings', 'settings' => array_merge($this->securitySettings(), $data), 'backups' => $this->listBackups(), 'errors' => $errors, 'shield_asset_url' => $this->asset('key.js'), 'install_lock_path' => $this->installLockPath(), 'sync_state' => $this->panelSyncStateSummary(), 'sync_script_path' => PANEL_ROOT . '/scripts/panel_sync_cron.php', 'maintenance_script_path' => PANEL_ROOT . '/scripts/cron.php'));
        }
        $current['app_name'] = $data['app_name'];
        $current['app_url'] = $data['app_url'];
        $current['timezone'] = $data['timezone'];
        $current['default_duration_days'] = (int) $data['default_duration_days'];
        $current['login_max_attempts'] = (int) $data['login_max_attempts'];
        $current['login_window_seconds'] = (int) $data['login_window_seconds'];
        $current['login_lockout_seconds'] = (int) $data['login_lockout_seconds'];
        $current['subscription_max_requests'] = (int) $data['subscription_max_requests'];
        $current['subscription_window_seconds'] = (int) $data['subscription_window_seconds'];
        $current['page_shield_mode'] = $data['page_shield_mode'];
        $current['page_shield_forms'] = $data['page_shield_forms'] === '1' ? 1 : 0;
        $current['js_hardening'] = $data['js_hardening'] === '1' ? 1 : 0;
        $current['api_enabled'] = $data['api_enabled'] === '1' ? 1 : 0;
        $current['api_encryption'] = $data['api_encryption'] === '1' ? 1 : 0;
        $current['telegram_enabled'] = $data['telegram_enabled'] === '1' ? 1 : 0;
        $current['telegram_bot_token'] = $data['telegram_bot_token'];
        $current['telegram_mode'] = $data['telegram_mode'];
        $current['telegram_webhook_secret'] = $data['telegram_webhook_secret'] !== '' ? $data['telegram_webhook_secret'] : (!empty($current['telegram_webhook_secret']) ? $current['telegram_webhook_secret'] : panel_random_hex(24));
        $current['telegram_proxy_enabled'] = $data['telegram_proxy_enabled'] === '1' ? 1 : 0;
        $current['telegram_proxy_type'] = $data['telegram_proxy_type'];
        $current['telegram_proxy_host'] = $data['telegram_proxy_host'];
        $current['telegram_proxy_port'] = (int) $data['telegram_proxy_port'];
        $current['telegram_proxy_username'] = $data['telegram_proxy_username'];
        $current['telegram_proxy_password'] = $data['telegram_proxy_password'];
        $current['telegram_allow_reseller'] = $data['telegram_allow_reseller'] === '1' ? 1 : 0;
        $current['telegram_allow_client'] = $data['telegram_allow_client'] === '1' ? 1 : 0;
        $current['telegram_allow_admin'] = $data['telegram_allow_admin'] === '1' ? 1 : 0;
        $current['telegram_poll_limit'] = (int) $data['telegram_poll_limit'];
        $current['panel_sync_enabled'] = $data['panel_sync_enabled'] === '1' ? 1 : 0;
        $current['panel_sync_mode'] = $data['panel_sync_enabled'] === '1' ? $data['panel_sync_mode'] : 'off';
        $current['panel_sync_master_url'] = rtrim($data['panel_sync_master_url'], '/');
        $current['panel_sync_shared_secret'] = $data['panel_sync_shared_secret'] !== '' ? $data['panel_sync_shared_secret'] : (!empty($current['panel_sync_shared_secret']) ? $current['panel_sync_shared_secret'] : panel_random_hex(24));
        $current['panel_sync_interval_seconds'] = (int) $data['panel_sync_interval_seconds'];
        $current['panel_sync_prune_missing'] = $data['panel_sync_prune_missing'] === '1' ? 1 : 0;
        $current['panel_sync_proxy_enabled'] = $data['panel_sync_proxy_enabled'] === '1' ? 1 : 0;
        $current['panel_sync_proxy_type'] = $data['panel_sync_proxy_type'];
        $current['panel_sync_proxy_host'] = $data['panel_sync_proxy_host'];
        $current['panel_sync_proxy_port'] = (int) $data['panel_sync_proxy_port'];
        $current['panel_sync_proxy_username'] = $data['panel_sync_proxy_username'];
        $current['panel_sync_proxy_password'] = $data['panel_sync_proxy_password'];
        $current['customer_sync_cron_enabled'] = $data['customer_sync_cron_enabled'] === '1' ? 1 : 0;
        $current['customer_sync_period_minutes'] = (int) $data['customer_sync_period_minutes'];
        $current['customer_sync_retry_attempts'] = (int) $data['customer_sync_retry_attempts'];
        $current['customer_sync_batch_size'] = (int) $data['customer_sync_batch_size'];
        $current['customer_pagination_enabled'] = $data['customer_pagination_enabled'] === '1' ? 1 : 0;
        $current['customer_pagination_per_page'] = (int) $data['customer_pagination_per_page'];
        $current['customer_auto_sync_admin_enabled'] = $data['customer_auto_sync_admin_enabled'] === '1' ? 1 : 0;
        $current['customer_auto_sync_reseller_enabled'] = $data['customer_auto_sync_reseller_enabled'] === '1' ? 1 : 0;
        $current['customer_auto_sync_batch_limit'] = (int) $data['customer_auto_sync_batch_limit'];
        $current['maintenance_cleanup_enabled'] = $data['maintenance_cleanup_enabled'] === '1' ? 1 : 0;
        $current['maintenance_cleanup_period_hours'] = (int) $data['maintenance_cleanup_period_hours'];
        $current['maintenance_cleanup_max_age_days'] = (int) $data['maintenance_cleanup_max_age_days'];
        $current['auto_backup_enabled'] = $data['auto_backup_enabled'] === '1' ? 1 : 0;
        $current['auto_backup_period_hours'] = (int) $data['auto_backup_period_hours'];
        $current['auto_backup_rotation_count'] = (int) $data['auto_backup_rotation_count'];
        $current['mask_removed_public_usage'] = $data['mask_removed_public_usage'] === '1' ? 1 : 0;
        $current['landing_enabled'] = $data['landing_enabled'] === '1' ? 1 : 0;
        $current['landing_badge'] = $data['landing_badge'];
        $current['landing_title'] = $data['landing_title'];
        $current['landing_subtitle'] = $data['landing_subtitle'];
        $current['landing_primary_label'] = $data['landing_primary_label'];
        $current['landing_primary_url'] = $data['landing_primary_url'];
        $current['landing_secondary_label'] = $data['landing_secondary_label'];
        $current['landing_secondary_url'] = $data['landing_secondary_url'];
        $current['landing_hero_image'] = $data['landing_hero_image'];
        $current['landing_section_title'] = $data['landing_section_title'];
        $current['landing_section_text'] = $data['landing_section_text'];
        $current['landing_feature_1_title'] = $data['landing_feature_1_title'];
        $current['landing_feature_1_body'] = $data['landing_feature_1_body'];
        $current['landing_feature_2_title'] = $data['landing_feature_2_title'];
        $current['landing_feature_2_body'] = $data['landing_feature_2_body'];
        $current['landing_feature_3_title'] = $data['landing_feature_3_title'];
        $current['landing_feature_3_body'] = $data['landing_feature_3_body'];
        $current['landing_links_text'] = $data['landing_links_text'];
        $current['landing_footer_note'] = $data['landing_footer_note'];
        if ($data['regenerate_page_shield_key'] === '1' || empty($current['page_shield_key'])) {
            $current['page_shield_key'] = base64_encode(function_exists('random_bytes') ? random_bytes(32) : openssl_random_pseudo_bytes(32));
        }
        $this->store->writeConfig('app', $current);
        $this->writeInstallLock();
        $this->ensureClientShieldAsset();
        $this->flash('success', 'Settings updated.');
        $this->redirect('/admin/settings');
    }

    protected function listBackups()
    {
        $dir = $this->storage . '/backups';
        $items = array();
        if (!is_dir($dir)) { return $items; }
        $files = glob($dir . '/*');
        if (!is_array($files)) { return $items; }
        foreach ($files as $file) {
            if (!is_file($file)) { continue; }
            $items[] = array('name' => basename($file), 'size' => filesize($file), 'time' => filemtime($file));
        }
        usort($items, function ($a, $b) { return (int) $b['time'] - (int) $a['time']; });
        return $items;
    }

    protected function createAdminBackup()
    {
        $created = $this->createBackupArchive();
        if (!$created['ok']) {
            $this->flash('error', $created['message']);
            $this->redirect('/admin/settings');
        }
        $this->flash('success', 'Backup created: ' . $created['name']);
        $this->redirect('/admin/settings');
    }

    protected function downloadAdminBackup()
    {
        $name = basename((string) $this->input('file', ''));
        if ($name === '' || strpos($name, '..') !== false) { $this->abort(404, 'Backup not found.'); }
        $path = $this->storage . '/backups/' . $name;
        if (!is_file($path)) { $this->abort(404, 'Backup not found.'); }
        $mime = preg_match('/\.zip$/i', $name) ? 'application/zip' : 'application/octet-stream';
        $this->sendCommonHeaders($mime);
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }


protected function deleteAdminBackup()
{
    $name = basename((string) $this->input('file', ''));
    if ($name === '' || strpos($name, '..') !== false) { $this->flash('error', 'Backup not found.'); $this->redirect('/admin/settings'); }
    $path = $this->storage . '/backups/' . $name;
    if (!is_file($path)) { $this->flash('error', 'Backup not found.'); $this->redirect('/admin/settings'); }
    @unlink($path);
    $this->flash('success', 'Backup deleted.');
    $this->redirect('/admin/settings');
}

protected function createBackupArchive()
{

        $dir = $this->storage . '/backups';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $stamp = gmdate('Ymd-His');
        $root = PANEL_ROOT;
        $name = 'panel-backup-' . $stamp . '.zip';
        $path = $dir . '/' . $name;
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                return array('ok' => false, 'message' => 'Could not create zip backup archive.');
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
            foreach ($iterator as $file) {
                $filePath = (string) $file;
                $local = str_replace('\\', '/', substr($filePath, strlen($root) + 1));
                if ($local === false || $local === '') { continue; }
                if (strpos($local, 'storage/backups/') === 0) { continue; }
                if ($file->isDir()) {
                    $zip->addEmptyDir($local);
                } else {
                    $zip->addFile($filePath, $local);
                }
            }
            $zip->addFromString('storage/backups/manifest.json', json_encode(array('created_at' => panel_now(), 'app' => $this->appName(), 'base_path' => $this->basePath), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $zip->close();
            return $this->finalizeBackupArchiveResult(array('ok' => true, 'name' => $name, 'path' => $path));
        }
        $fallbackName = 'panel-backup-' . $stamp . '.json';
        $fallbackPath = $dir . '/' . $fallbackName;
        $payload = array(
            'created_at' => panel_now(),
            'config' => $this->runtimeConfig(),
            'collections' => array(),
            'logs' => array(),
        );
        foreach (array('admins','resellers','nodes','templates','customers','customer_links','tickets','ticket_messages','credit_ledger','activity') as $collection) {
            $payload['collections'][$collection] = $this->store->all($collection);
        }
        foreach (glob($this->storage . '/logs/*.log') ?: array() as $logFile) {
            $payload['logs'][basename($logFile)] = (string) @file_get_contents($logFile);
        }
        @file_put_contents($fallbackPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $this->finalizeBackupArchiveResult(array('ok' => true, 'name' => $fallbackName, 'path' => $fallbackPath));
    }

    protected function finalizeBackupArchiveResult($result)
    {
        if (empty($result['ok'])) { return $result; }
        $rotation = $this->backupRotationCount();
        $removed = $this->enforceBackupRotation($rotation);
        $result['rotation_count'] = $rotation;
        $result['rotation_removed'] = $removed;
        return $result;
    }

    protected function backupRotationCount()
    {
        $cfg = $this->securitySettings();
        $keep = isset($cfg['auto_backup_rotation_count']) ? (int) $cfg['auto_backup_rotation_count'] : 10;
        return max(1, min(1000, $keep));
    }

    protected function enforceBackupRotation($keep)
    {
        $keep = max(1, (int) $keep);
        $items = $this->listBackups();
        $removed = 0;
        if (count($items) <= $keep) { return $removed; }
        $stale = array_slice($items, $keep);
        foreach ($stale as $item) {
            $name = isset($item['name']) ? basename((string) $item['name']) : '';
            if ($name === '') { continue; }
            $path = $this->storage . '/backups/' . $name;
            if (!is_file($path)) { continue; }
            if (@unlink($path)) { $removed++; }
        }
        return $removed;
    }


    public function runMaintenanceCron($force = false)
    {
        $summary = array('customer_sync' => array('ran' => false, 'ok' => 0, 'failed' => 0, 'skipped' => 0, 'message' => ''), 'cleanup' => array('ran' => false, 'deleted' => 0, 'message' => ''), 'backup' => array('ran' => false, 'ok' => false, 'message' => ''));
        $lockFile = $this->storage . '/locks/maintenance_cron.lock';
        $lock = @fopen($lockFile, 'c+');
        if (!$lock) {
            $summary['customer_sync']['message'] = 'Could not open maintenance cron lock file.';
            $summary['cleanup']['message'] = 'Could not open maintenance cron lock file.';
            $summary['backup']['message'] = 'Could not open maintenance cron lock file.';
            return $summary;
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            $message = 'Maintenance cron is already running.';
            $summary['customer_sync']['message'] = $message;
            $summary['cleanup']['message'] = $message;
            $summary['backup']['message'] = $message;
            return $summary;
        }
        $summary['customer_sync'] = $this->runCustomerStateCronSync($force);
        $summary['cleanup'] = $this->runCleanupCron($force);
        $summary['backup'] = $this->runAutoBackupCron($force);
        flock($lock, LOCK_UN);
        fclose($lock);
        return $summary;
    }

    protected function cronState()
    {
        $state = $this->store->readConfig('maintenance_state');
        return is_array($state) ? $state : array();
    }

    protected function saveCronState($state)
    {
        $this->store->writeConfig('maintenance_state', (array) $state);
    }

    protected function runCustomerStateCronSync($force)
    {
        $sec = $this->securitySettings();
        $out = array('ran' => false, 'ok' => 0, 'failed' => 0, 'skipped' => 0, 'removed' => 0, 'suspected_missing' => 0, 'message' => 'Disabled.');
        if (empty($sec['customer_sync_cron_enabled']) && !$force) { return $out; }
        $state = $this->cronState();
        $last = isset($state['customer_sync_last_run']) ? (int) $state['customer_sync_last_run'] : 0;
        $period = max(60, (int) $sec['customer_sync_period_minutes'] * 60);
        if (!$force && $last > 0 && (time() - $last) < $period) {
            $out['message'] = 'Not due yet.';
            return $out;
        }
        $retries = max(1, (int) $sec['customer_sync_retry_attempts']);
        $batchSize = isset($sec['customer_sync_batch_size']) ? max(1, (int) $sec['customer_sync_batch_size']) : 25;
        $customers = $this->store->all('customers');
        usort($customers, array($this, 'sortOldestSyncFirst'));
        $processed = 0;
        $eligible = 0;
        foreach ($customers as $customer) {
            if (!is_array($customer) || empty($customer['id'])) { continue; }
            if (strtolower(trim((string) panel_array_get($customer, 'status', 'active'))) === 'removed') { $out['skipped']++; continue; }
            if (!$this->customerNodeIsSyncEnabled($customer)) { $out['skipped']++; continue; }
            $eligible++;
            if ($processed >= $batchSize) { continue; }
            $processed++;
            $sync = $this->refreshCustomerUsageWithRetry($customer, true, $retries);
            if (!empty($sync['ok'])) {
                $out['ok']++;
                if (!empty($sync['removed_marked'])) {
                    $out['removed']++;
                }
            } else {
                if (!empty($sync['suspected_missing'])) {
                    $out['suspected_missing']++;
                    if (!empty($customer['id'])) {
                        $this->store->update('customers', $customer['id'], array('last_error' => $sync['message'], 'last_synced_at' => panel_now()));
                    }
                } else {
                    $out['failed']++;
                }
            }
        }
        $remaining = max(0, $eligible - $processed);
        $out['ran'] = true;
        $out['message'] = 'Customer state sync finished. Window ' . $processed . ' of ' . $eligible . ' eligible customer(s) processed, ' . $remaining . ' remaining.';
        if ($out['removed'] > 0) {
            $out['message'] .= ' ' . $out['removed'] . ' missing remote account(s) were marked Removed locally.';
        }
        if ($out['suspected_missing'] > 0) {
            $out['message'] .= ' ' . $out['suspected_missing'] . ' customer(s) returned missing-client responses but could not be confirmed yet and were kept unchanged.';
        }
        $state['customer_sync_last_run'] = time();
        $this->saveCronState($state);
        $this->appendSecurityLog('cron', $out['failed'] > 0 ? 'error' : 'access', 'Customer state cron sync finished.', array('ok' => $out['ok'], 'failed' => $out['failed'], 'skipped' => $out['skipped'], 'removed' => $out['removed'], 'suspected_missing' => $out['suspected_missing'], 'window' => $processed, 'eligible' => $eligible, 'remaining' => $remaining));
        return $out;
    }

    protected function sortOldestSyncFirst($a, $b)
    {
        $ta = !empty($a['last_synced_at']) ? strtotime($a['last_synced_at']) : 0;
        $tb = !empty($b['last_synced_at']) ? strtotime($b['last_synced_at']) : 0;
        if ($ta === $tb) { return strcmp(isset($a['created_at']) ? $a['created_at'] : '', isset($b['created_at']) ? $b['created_at'] : ''); }
        return $ta < $tb ? -1 : 1;
    }

    protected function refreshCustomerUsageWithRetry($customer, $updateStore, $retries)
    {
        $last = array('ok' => false, 'message' => 'Sync failed.');
        for ($i = 0; $i < max(1, (int) $retries); $i++) {
            $last = $this->refreshCustomerUsageFromNode($customer, $updateStore);
            if (!empty($last['ok'])) { return $last; }
            usleep(150000 * ($i + 1));
        }
        return $last;
    }

    protected function runCleanupCron($force)
    {
        $sec = $this->securitySettings();
        $out = array('ran' => false, 'deleted' => 0, 'message' => 'Disabled.');
        if (empty($sec['maintenance_cleanup_enabled']) && !$force) { return $out; }
        $state = $this->cronState();
        $last = isset($state['cleanup_last_run']) ? (int) $state['cleanup_last_run'] : 0;
        $period = max(3600, (int) $sec['maintenance_cleanup_period_hours'] * 3600);
        if (!$force && $last > 0 && (time() - $last) < $period) { $out['message'] = 'Not due yet.'; return $out; }
        $maxAge = max(1, (int) $sec['maintenance_cleanup_max_age_days']) * 86400;
        $paths = $this->safeCleanupDirectories();
        foreach ($paths as $dir) {
            if (!is_dir($dir)) { continue; }
            foreach (glob($dir . '/*') ?: array() as $file) {
                if (!$this->isSafeCleanupFile($dir, $file)) { continue; }
                $age = time() - (int) @filemtime($file);
                if ($age >= $maxAge && @unlink($file)) { $out['deleted']++; }
            }
        }
        $out['ran'] = true;
        $out['message'] = 'Cleanup finished. Only cache, QR, cookie, and temp-style files were touched.';
        $state['cleanup_last_run'] = time();
        $this->saveCronState($state);
        $this->appendSecurityLog('cron', 'access', 'Cleanup cron finished.', array('deleted' => $out['deleted'], 'paths' => $paths));
        return $out;
    }

    protected function safeCleanupDirectories()
    {
        $paths = array(
            $this->storage . '/cache/qrcodes',
            $this->storage . '/cache/rate_limits',
            $this->storage . '/cache/cookies',
            $this->storage . '/cache/qr',
            $this->storage . '/cache/tmp',
            $this->storage . '/cache/temp',
            $this->storage . '/cache/temps',
            $this->storage . '/tmp',
            $this->storage . '/temp',
        );
        $clean = array();
        foreach ($paths as $dir) {
            $real = @realpath($dir);
            if ($real === false || !is_dir($real)) { continue; }
            if (strpos(str_replace('\\', '/', $real), str_replace('\\', '/', $this->storage)) !== 0) { continue; }
            if (!in_array($real, $clean, true)) { $clean[] = $real; }
        }
        return $clean;
    }

    protected function isSafeCleanupFile($dir, $file)
    {
        if (!is_string($file) || !is_file($file)) { return false; }
        $dirReal = @realpath($dir);
        $fileReal = @realpath($file);
        if ($dirReal === false || $fileReal === false) { return false; }
        $dirReal = str_replace('\\', '/', $dirReal);
        $fileReal = str_replace('\\', '/', $fileReal);
        if (strpos($fileReal, $dirReal . '/') !== 0 && $fileReal !== $dirReal) { return false; }
        $name = basename($fileReal);
        if ($name === '' || $name === '.gitkeep' || $name === '.htaccess') { return false; }
        if ($name[0] === '.') { return false; }
        return true;
    }

    protected function runAutoBackupCron($force)
    {
        $sec = $this->securitySettings();
        $out = array('ran' => false, 'ok' => false, 'message' => 'Disabled.');
        if (empty($sec['auto_backup_enabled']) && !$force) { return $out; }
        $state = $this->cronState();
        $last = isset($state['backup_last_run']) ? (int) $state['backup_last_run'] : 0;
        $period = max(3600, (int) $sec['auto_backup_period_hours'] * 3600);
        if (!$force && $last > 0 && (time() - $last) < $period) { $out['message'] = 'Not due yet.'; return $out; }
        $result = $this->createBackupArchive();
        $out['ran'] = true;
        $out['ok'] = !empty($result['ok']);
        $out['message'] = !empty($result['ok']) ? 'Backup created: ' . $result['name'] : (isset($result['message']) ? $result['message'] : 'Backup failed.');
        $state['backup_last_run'] = time();
        $this->saveCronState($state);
        $this->appendSecurityLog('cron', !empty($result['ok']) ? 'access' : 'error', 'Auto backup cron finished.', array('message' => $out['message']));
        return $out;
    }

    public function customerLastSyncAgo($customer)
    {
        $ts = !empty($customer['last_synced_at']) ? strtotime($customer['last_synced_at']) : 0;
        if (!$ts) { return 'Never synced'; }
        $diff = time() - $ts;
        if ($diff < 60) { return $diff . ' sec ago'; }
        if ($diff < 3600) { return floor($diff / 60) . ' min ago'; }
        if ($diff < 86400) { return floor($diff / 3600) . ' hour(s) ago'; }
        return floor($diff / 86400) . ' day(s) ago';
    }

    protected function appName() { $cfg = $this->runtimeConfig(); return !empty($cfg['app_name']) ? $cfg['app_name'] : $this->config('app_name', 'XUI Reseller Panel'); }
    protected function runtimeConfig() { return $this->store->readConfig('app'); }
    protected function defaultDurationDays() { $cfg = $this->runtimeConfig(); return isset($cfg['default_duration_days']) ? (int) $cfg['default_duration_days'] : (int) $this->config('default_duration_days', 30); }
    protected function resellerMaxExpirationDays($reseller)
    {
        if (isset($reseller['max_expiration_days'])) { return max(0, (int) $reseller['max_expiration_days']); }
        if (isset($reseller['fixed_duration_days'])) { return max(0, (int) $reseller['fixed_duration_days']); }
        return $this->defaultDurationDays();
    }
    protected function resellerMaxIpLimit($reseller)
    {
        return isset($reseller['max_ip_limit']) ? max(0, (int) $reseller['max_ip_limit']) : 0;
    }
    protected function resellerDefaultCustomerDurationDays($reseller)
    {
        $max = $this->resellerMaxExpirationDays($reseller);
        return $max > 0 ? $max : $this->defaultDurationDays();
    }
    protected function resellerAllowsFractionalTraffic($reseller)
    {
        if (!is_array($reseller) || !array_key_exists('allow_fractional_traffic_gb', $reseller)) { return true; }
        return panel_parse_bool($reseller['allow_fractional_traffic_gb'], true);
    }

    protected function gbValueIsWhole($value)
    {
        if (!is_numeric($value)) { return false; }
        return abs(((float) $value) - round((float) $value)) < 0.00001;
    }


    protected function normalizeServerType($value)
    {
        $value = strtolower(trim((string) $value));
        return $value === 'um' ? 'um' : 'xui';
    }

    public function nodeServerType($node)
    {
        return $this->normalizeServerType(is_array($node) && isset($node['server_type']) ? $node['server_type'] : 'xui');
    }

    public function templateServerType($template, $node = null)
    {
        if (is_array($template) && !empty($template['server_type'])) {
            return $this->normalizeServerType($template['server_type']);
        }
        if ($node === null && is_array($template) && !empty($template['node_id'])) {
            $node = $this->store->find('nodes', $template['node_id']);
        }
        return $this->nodeServerType($node);
    }

    public function customerServerType($customer, $template = null, $node = null)
    {
        if (is_array($customer) && !empty($customer['server_type'])) {
            return $this->normalizeServerType($customer['server_type']);
        }
        if ($template === null && is_array($customer) && !empty($customer['template_id'])) {
            $template = $this->store->find('templates', $customer['template_id']);
        }
        return $this->templateServerType($template, $node);
    }

    protected function isUmNode($node) { return $this->nodeServerType($node) === 'um'; }
    protected function isXuiNode($node) { return $this->nodeServerType($node) === 'xui'; }
    protected function isUmTemplate($template, $node = null) { return $this->templateServerType($template, $node) === 'um'; }
    protected function isXuiTemplate($template, $node = null) { return $this->templateServerType($template, $node) === 'xui'; }

    protected function customerServiceUsername($customer)
    {
        return trim((string) panel_array_get($customer, 'service_username', panel_array_get($customer, 'system_name', '')));
    }

    protected function customerServicePasswordPlain($customer)
    {
        $enc = trim((string) panel_array_get($customer, 'service_password_enc', ''));
        return $enc !== '' ? $this->decrypt($enc) : '';
    }

    protected function resellerSlugForUm($reseller)
    {
        foreach (array(panel_array_get($reseller, 'prefix', ''), panel_array_get($reseller, 'username', ''), panel_array_get($reseller, 'display_name', '')) as $value) {
            $slug = panel_slug((string) $value, true);
            if ($slug !== '') {
                return strtolower($slug);
            }
        }
        return 'reseller';
    }

    protected function umUsernameExistsLocally($username, $excludeCustomerId, $nodeId)
    {
        $username = strtolower(trim((string) $username));
        if ($username === '') {
            return false;
        }
        foreach ($this->store->all('customers') as $item) {
            if (!is_array($item)) { continue; }
            if ($excludeCustomerId !== '' && isset($item['id']) && $item['id'] === $excludeCustomerId) { continue; }
            if ($this->customerServerType($item) !== 'um') { continue; }
            $candidate = strtolower($this->customerServiceUsername($item));
            if ($candidate === '') { continue; }
            if ($candidate !== $username) { continue; }
            $itemNodeId = trim((string) panel_array_get($item, 'node_id', ''));
            if ($nodeId === '' || $itemNodeId === '' || $itemNodeId === $nodeId) {
                return true;
            }
        }
        return false;
    }

    protected function randomDigitString($minLength, $maxLength)
    {
        $minLength = max(1, (int) $minLength);
        $maxLength = max($minLength, (int) $maxLength);
        $length = mt_rand($minLength, $maxLength);
        $digits = '';
        while (strlen($digits) < $length) {
            $digits .= (string) mt_rand(0, 9);
        }
        return substr($digits, 0, $length);
    }

    protected function generateUniqueUmServiceUsername($reseller, $displayName, $node, $excludeCustomerId)
    {
        $resellerSlug = $this->resellerSlugForUm($reseller);
        $nameSlug = panel_slug((string) $displayName, true);
        if ($nameSlug === '') { $nameSlug = 'user'; }
        $maxBaseLen = 40;
        $base = $resellerSlug . '-';
        if (strlen($base) >= $maxBaseLen) {
            $base = substr($base, 0, $maxBaseLen - 1) . '-';
        }
        $allowedNameLen = max(4, $maxBaseLen - strlen($base));
        $nameSlug = substr($nameSlug, 0, $allowedNameLen);
        $adapter = null;
        if (is_array($node) && $this->isUmNode($node) && (!isset($node['status']) || $node['status'] === 'active')) {
            $adapter = $this->nodeAdapter($node);
        }
        for ($i = 0; $i < 50; $i++) {
            $candidate = strtolower($base . $nameSlug . $this->randomDigitString(3, 5));
            $candidate = preg_replace('/[^a-z0-9_-]+/', '', $candidate);
            if (strlen($candidate) < 3) { continue; }
            if ($this->umUsernameExistsLocally($candidate, (string) $excludeCustomerId, is_array($node) ? (string) panel_array_get($node, 'id', '') : '')) {
                continue;
            }
            if ($adapter && method_exists($adapter, 'userExists')) {
                $remoteExists = $adapter->userExists($candidate);
                if ($remoteExists === true) {
                    continue;
                }
            }
            return $candidate;
        }
        return '';
    }

    protected function utilityLinksTextFromJson($json)
    {
        $items = is_array($json) ? $json : panel_safe_json_decode((string) $json);
        if (!is_array($items)) {
            return '';
        }
        $lines = array();
        foreach ($items as $item) {
            if (!is_array($item)) { continue; }
            $name = trim((string) panel_array_get($item, 'name', ''));
            $url = trim((string) panel_array_get($item, 'url', ''));
            $types = strtolower(trim((string) panel_array_get($item, 'types', 'all')));
            if ($name === '' || $url === '') { continue; }
            $line = $name . '|' . $url;
            if ($types !== '' && $types !== 'all') {
                $line .= '|' . $types;
            }
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }

    protected function parseNodeUtilityLinks($text, &$errors = null)
    {
        $items = array();
        $seen = array();
        $lines = preg_split('/\r\n|\r|\n/', (string) $text);
        foreach ($lines as $index => $line) {
            $line = trim((string) $line);
            if ($line === '') { continue; }
            $parts = array_map('trim', explode('|', $line));
            $name = isset($parts[0]) ? $parts[0] : '';
            $url = isset($parts[1]) ? $parts[1] : '';
            $types = isset($parts[2]) ? strtolower($parts[2]) : 'all';
            if ($name === '' || $url === '') {
                if (is_array($errors)) { $errors['utility_links_text'][] = 'Utility links must use Name|URL or Name|URL|type format.'; }
                continue;
            }
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                if (is_array($errors)) { $errors['utility_links_text'][] = 'Utility link URL is invalid for "' . $name . '".'; }
                continue;
            }
            $allowedTypes = array();
            foreach (preg_split('/[, ]+/', $types) as $token) {
                $token = strtolower(trim((string) $token));
                if ($token === '') { continue; }
                if (in_array($token, array('xui', 'um', 'all', 'both'), true)) {
                    $allowedTypes[$token] = true;
                }
            }
            if (!$allowedTypes) {
                $allowedTypes = array('all' => true);
            }
            if (isset($allowedTypes['both'])) {
                unset($allowedTypes['both']);
                $allowedTypes['xui'] = true;
                $allowedTypes['um'] = true;
            }
            $typeValue = isset($allowedTypes['all']) ? 'all' : implode(',', array_keys($allowedTypes));
            $key = strtolower($name . '|' . $url . '|' . $typeValue);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $items[] = array('name' => $name, 'url' => $url, 'types' => $typeValue);
        }
        return $items;
    }

    protected function buildNodeUtilityLinks($node, $customer, $template)
    {
        $items = panel_safe_json_decode((string) panel_array_get($node, 'utility_links_json', '[]'));
        if (!is_array($items) || !$items) {
            return array();
        }
        $serverType = $this->customerServerType($customer, $template, $node);
        $publicUrl = !empty($customer['subscription_key']) ? $this->appLink('/user/' . $customer['subscription_key']) : '';
        $entry = $this->buildUmConnectionInfo($customer, $template, $node);
        $replacements = array(
            '{username}' => $this->customerServiceUsername($customer),
            '{user}' => $this->customerServiceUsername($customer),
            '{login}' => $this->customerServiceUsername($customer),
            '{password}' => $this->customerServicePasswordPlain($customer),
            '{profile}' => is_array($template) ? trim((string) panel_array_get($template, 'um_profile_name', panel_array_get($template, 'public_label', ''))) : '',
            '{server}' => is_array($node) ? trim((string) panel_array_get($node, 'title', '')) : '',
            '{host}' => is_array($node) ? trim((string) panel_array_get($node, 'base_url', '')) : '',
            '{public_url}' => $publicUrl,
            '{subscription_url}' => $publicUrl,
            '{connection_info}' => is_array($entry) ? trim((string) panel_array_get($entry, 'text', '')) : '',
            '{connection_file}' => is_array($entry) ? trim((string) panel_array_get($entry, 'file_url', '')) : '',
        );
        $out = array();
        foreach ($items as $item) {
            if (!is_array($item)) { continue; }
            $types = strtolower(trim((string) panel_array_get($item, 'types', 'all')));
            if ($types !== '' && $types !== 'all') {
                $allowed = array_map('trim', explode(',', $types));
                if (!in_array($serverType, $allowed, true)) {
                    continue;
                }
            }
            $name = trim((string) panel_array_get($item, 'name', ''));
            $url = strtr(trim((string) panel_array_get($item, 'url', '')), $replacements);
            if ($name === '' || $url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
                continue;
            }
            $out[] = array('name' => $name, 'url' => $url);
        }
        return $out;
    }

    protected function buildUmConnectionInfo($customer, $template, $node)
    {
        $mode = trim((string) panel_array_get($node, 'um_connection_mode', 'text'));
        $payload = array(
            'mode' => $mode === 'file' ? 'file' : 'text',
            'text' => trim((string) panel_array_get($node, 'um_connection_text', '')),
            'file_url' => trim((string) panel_array_get($node, 'um_connection_file_url', '')),
            'file_name' => trim((string) panel_array_get($node, 'um_connection_file_name', '')),
        );
        $username = $this->customerServiceUsername($customer);
        $password = $this->customerServicePasswordPlain($customer);
        $replacements = array(
            '{username}' => $username,
            '{user}' => $username,
            '{login}' => $username,
            '{password}' => $password,
            '{pass}' => $password,
            '{server}' => is_array($node) ? trim((string) panel_array_get($node, 'title', '')) : '',
            '{profile}' => is_array($template) ? trim((string) panel_array_get($template, 'um_profile_name', panel_array_get($template, 'public_label', ''))) : '',
            '{gb}' => panel_format_gb((float) panel_array_get($customer, 'traffic_gb', panel_array_get($template, 'billing_gb', 0))),
        );
        foreach (array('text', 'file_url', 'file_name') as $key) {
            $payload[$key] = strtr((string) $payload[$key], $replacements);
        }
        return $payload;
    }

    protected function maskRemovedPublicUsageEnabled()
    {
        $settings = $this->securitySettings();
        return !empty($settings['mask_removed_public_usage']);
    }

    public function customerPublicTrafficSummary($customer)
    {
        $total = isset($customer['traffic_bytes_total']) ? (float) $customer['traffic_bytes_total'] : panel_to_bytes_from_gb((float) panel_array_get($customer, 'traffic_gb', 0));
        if ($total < 0) { $total = 0; }
        $used = isset($customer['traffic_bytes_used']) ? (float) $customer['traffic_bytes_used'] : 0;
        if ($used < 0) { $used = 0; }
        $left = isset($customer['traffic_bytes_left']) ? (float) $customer['traffic_bytes_left'] : max(0, $total - $used);
        if ($left < 0) { $left = 0; }
        $state = $this->customerRuntimeState($customer);
        $masked = $state === 'removed' && $this->maskRemovedPublicUsageEnabled();
        if ($masked) {
            $used = $total;
            $left = 0;
        }
        return array(
            'masked' => $masked,
            'state' => $state,
            'total_bytes' => $total,
            'used_bytes' => $used,
            'left_bytes' => $left,
            'used_gb' => panel_to_gb_from_bytes($used),
            'left_gb' => panel_to_gb_from_bytes($left),
            'total_gb' => panel_to_gb_from_bytes($total),
        );
    }

    public function buildUmAccessLines($customer, $template, $node)
    {
        $info = $this->buildUmConnectionInfo($customer, $template, $node);
        $traffic = $this->customerPublicTrafficSummary($customer);
        $lines = array();
        $lines[] = 'Server: ' . ($node ? $node['title'] : 'Unknown');
        $lines[] = 'Profile: ' . ($template ? panel_array_get($template, 'um_profile_name', panel_array_get($template, 'public_label', 'Unknown')) : 'Unknown');
        $lines[] = 'Username: ' . $this->customerServiceUsername($customer);
        $lines[] = 'Password: ' . $this->customerServicePasswordPlain($customer);
        $lines[] = 'Used GB: ' . panel_format_gb($traffic['used_gb']);
        $lines[] = 'Left GB: ' . panel_format_gb($traffic['left_gb']);
        $lines[] = 'Expires: ' . $this->customerExpirationLabel($customer);
        if ($info['mode'] === 'file' && $info['file_url'] !== '') {
            $lines[] = 'Connection File: ' . $info['file_url'];
        } elseif ($info['text'] !== '') {
            $lines[] = 'Connection Info:';
            $lines[] = $info['text'];
        }
        return $lines;
    }

    public function buildCustomerPublicPayload($customer, $template, $node, $options = array())
    {
        $canAccess = $this->customerAllowsPublicConfigs($customer);
        $serverType = $this->customerServerType($customer, $template, $node);
        $includeConfigs = !isset($options['include_configs']) || !empty($options['include_configs']);
        $entry = array(
            'customer' => $customer,
            'template' => $template,
            'node' => $node,
            'server_type' => $serverType,
            'public_access_allowed' => $canAccess,
            'public_access_message' => $this->customerPublicAccessMessage($customer),
            'configs' => array(),
            'subscription_url' => '',
            'proxy_subscription_url' => '',
            'primary_subscription_url' => '',
            'fallback_subscription_url' => '',
            'service_username' => '',
            'service_password' => '',
            'um_connection' => null,
            'um_export_lines' => array(),
            'utility_links' => $this->buildNodeUtilityLinks($node, $customer, $template),
            'traffic' => $this->customerPublicTrafficSummary($customer),
        );
        if ($serverType === 'um') {
            $entry['service_username'] = $this->customerServiceUsername($customer);
            $entry['service_password'] = $this->customerServicePasswordPlain($customer);
            $entry['um_connection'] = $this->buildUmConnectionInfo($customer, $template, $node);
            $entry['um_export_lines'] = $this->buildUmAccessLines($customer, $template, $node);
            return $entry;
        }
        $configs = ($canAccess && $includeConfigs) ? $this->buildSubscriptionConfigs($customer, $template, $node) : array();
        $subscriptionUrl = $canAccess ? $this->appLink('/user/' . $customer['subscription_key']) : '';
        $proxySubscriptionUrl = $canAccess ? $this->buildNodeSubscriptionUrl($node, isset($customer['remote_sub_id']) ? $customer['remote_sub_id'] : (isset($customer['subscription_key']) ? $customer['subscription_key'] : '')) : '';
        $entry['configs'] = $configs;
        $entry['subscription_url'] = $subscriptionUrl;
        $entry['proxy_subscription_url'] = $proxySubscriptionUrl;
        $entry['primary_subscription_url'] = $proxySubscriptionUrl !== '' ? $proxySubscriptionUrl : $subscriptionUrl;
        $entry['fallback_subscription_url'] = ($canAccess && $proxySubscriptionUrl !== '') ? $subscriptionUrl : '';
        return $entry;
    }

    public function resellerTemplatesByType($reseller, $serverType)
    {
        $serverType = $this->normalizeServerType($serverType);
        $items = array();
        foreach ($this->resellerTemplates($reseller) as $tpl) {
            if ($this->templateServerType($tpl) === $serverType) {
                $items[] = $tpl;
            }
        }
        return $items;
    }

    protected function storeCustomerLinkForType($customer, $template, $node, $remoteClientId, $remoteEmail, $remoteSubId)
    {
        if ($this->customerServerType($customer, $template, $node) === 'um') {
            $link = $this->findCustomerLink($customer['id']);
            $payload = array(
                'customer_id' => $customer['id'],
                'template_id' => $template ? $template['id'] : '',
                'node_id' => $node ? $node['id'] : '',
                'inbound_id' => '',
                'remote_client_id' => $remoteClientId,
                'remote_email' => $remoteEmail,
                'remote_sub_id' => $remoteSubId,
                'protocol' => 'um',
                'server_type' => 'um',
            );
            if ($link) { $this->store->update('customer_links', $link['id'], $payload); }
            else { $this->store->insert('customer_links', $payload, 'lnk'); }
            return;
        }
        $this->saveCustomerLink($customer, $template, $node, $remoteClientId, $remoteEmail, $remoteSubId);
    }

    protected function saveUmCustomer($id, $reseller, $existing, $data)
    {
        $mode = $id ? 'edit' : 'create';
        $oldTraffic = $existing ? round((float) panel_array_get($existing, 'traffic_gb', 0), 2) : 0.0;
        $template = $this->store->find('templates', $data['template_id']);
        $errors = array();
        if (strlen($data['display_name']) < 2) { $errors['display_name'][] = 'Name must be at least 2 characters.'; }
        if (!$template || !$this->resellerCanUseTemplate($reseller, $data['template_id']) || !$this->isUmTemplate($template) || (isset($template['status']) && $template['status'] !== 'active')) { $errors['template_id'][] = 'Select a permitted UM profile.'; }
        $hasPhone = $data['phone'] !== '';
        $hasEmail = $data['email'] !== '';
        $hasAccessIdentity = $hasPhone || $hasEmail;
        $hasPinInput = $data['access_pin'] !== '';
        $existingHasPin = $existing && !empty($existing['access_pin_hash']);
        if ($hasPhone && !$this->isValidCustomerPhone($data['phone'])) { $errors['phone'][] = 'Phone must contain only digits and be between 6 and 20 numbers.'; }
        if ($hasEmail && !$this->isValidCustomerEmail($data['email'])) { $errors['email'][] = 'Email address is invalid.'; }
        if ($hasPinInput && !$this->isValidCustomerPin($data['access_pin'])) { $errors['access_pin'][] = 'PIN must be 1 to 6 letters or numbers.'; }
        if ($mode === 'create') {
            if ($hasAccessIdentity xor $hasPinInput) { $errors['auth'][] = 'Phone or email plus PIN must both be filled to enable /get access, or all left blank to disable it.'; }
        } else {
            if ($hasAccessIdentity && !$hasPinInput && !$existingHasPin) { $errors['auth'][] = 'Set a PIN too, or clear phone/email to disable /get access.'; }
            if (!$hasAccessIdentity && $hasPinInput) { $errors['auth'][] = 'Phone or email is required when setting a PIN.'; }
        }
        if (!in_array($data['status'], array('active', 'disabled'), true)) { $errors['status'][] = 'Status is invalid.'; }
        if ($existing && !empty($existing['template_id']) && $existing['template_id'] !== $data['template_id']) { $errors['template_id'][] = 'UM profile cannot be changed after user creation.'; }
        if ($errors) { return $this->renderCustomerForm($mode, $data, $errors, $reseller, $this->resellerTemplatesByType($reseller, 'um')); }
        $traffic = round((float) panel_array_get($template, 'billing_gb', 0), 2);
        if ($traffic <= 0) {
            $errors['template_id'][] = 'The selected UM profile has no billing GB assigned.';
            return $this->renderCustomerForm($mode, $data, $errors, $reseller, $this->resellerTemplatesByType($reseller, 'um'));
        }
        $creditDelta = round($traffic - $oldTraffic, 2);
        if ($creditDelta > 0 && (float) $reseller['credit_gb'] < $creditDelta) {
            $errors['template_id'][] = 'Not enough reseller credit left for the selected UM profile.';
            return $this->renderCustomerForm($mode, $data, $errors, $reseller, $this->resellerTemplatesByType($reseller, 'um'));
        }
        $node = $this->store->find('nodes', $template['node_id']);
        if (!$node || !$this->isUmNode($node)) {
            $errors['template_id'][] = 'The selected profile server is invalid.';
            return $this->renderCustomerForm($mode, $data, $errors, $reseller, $this->resellerTemplatesByType($reseller, 'um'));
        }
        if (isset($node['status']) && $node['status'] !== 'active') {
            $errors['template_id'][] = 'The selected server is disabled.';
            return $this->renderCustomerForm($mode, $data, $errors, $reseller, $this->resellerTemplatesByType($reseller, 'um'));
        }
        $durationDays = (int) $data['duration_days'];
        if ($durationDays < 0) { $durationDays = 0; }
        $durationMode = $data['duration_mode'] === 'first_use' ? 'first_use' : 'fixed';
        $expireAtTs = ($durationMode === 'fixed' && $durationDays > 0) ? (time() + ($durationDays * 86400)) : 0;
        $displaySlug = panel_slug($data['display_name'], true);
        if ($existing) {
            $systemName = isset($existing['system_name']) ? $existing['system_name'] : $displaySlug;
            $serviceUsername = trim((string) panel_array_get($existing, 'service_username', $systemName));
            if ($serviceUsername === '') {
                $serviceUsername = $this->generateUniqueUmServiceUsername($reseller, $data['display_name'], $node, $id ? $id : '');
                $systemName = $serviceUsername !== '' ? $serviceUsername : $systemName;
            }
            $passwordPlain = trim((string) $this->input('service_password', ''));
            if ($passwordPlain === '') { $passwordPlain = $this->customerServicePasswordPlain($existing); }
        } else {
            $serviceUsername = $this->generateUniqueUmServiceUsername($reseller, $data['display_name'], $node, '');
            $systemName = $serviceUsername !== '' ? $serviceUsername : strtolower(($this->resellerSlugForUm($reseller)) . '-' . ($displaySlug !== '' ? $displaySlug : 'user'));
            $passwordPlain = trim((string) $this->input('service_password', ''));
            if ($passwordPlain === '') { $passwordPlain = panel_random_hex(5) . panel_random_hex(3); }
        }
        if (strlen($serviceUsername) < 2) { $errors['display_name'][] = 'Could not generate a valid UM username after collision checks.'; }
        if (strlen($passwordPlain) < 1) { $errors['service_password'][] = 'Service password is required.'; }
        if ($errors) { return $this->renderCustomerForm($mode, array_merge($data, array('service_password' => $passwordPlain)), $errors, $reseller, $this->resellerTemplatesByType($reseller, 'um')); }
        $payload = array(
            'server_type' => 'um',
            'reseller_id' => $reseller['id'],
            'display_name' => $data['display_name'],
            'system_name' => $systemName,
            'template_id' => $template['id'],
            'node_id' => $node['id'],
            'traffic_gb' => $traffic,
            'traffic_bytes_total' => panel_to_bytes_from_gb($traffic),
            'traffic_bytes_used' => $existing ? (float) panel_array_get($existing, 'traffic_bytes_used', 0) : 0,
            'traffic_bytes_left' => max(0, panel_to_bytes_from_gb($traffic) - ($existing ? (float) panel_array_get($existing, 'traffic_bytes_used', 0) : 0)),
            'duration_days' => $durationDays,
            'duration_mode' => $durationMode,
            'ip_limit' => 1,
            'expires_at' => $expireAtTs > 0 ? gmdate('c', $expireAtTs) : '',
            'status' => $data['status'],
            'phone' => $data['phone'],
            'email' => $data['email'],
            'access_pin_hash' => ((!$hasAccessIdentity) ? '' : ($data['access_pin'] !== '' ? panel_password_hash($data['access_pin']) : ($existing && isset($existing['access_pin_hash']) ? $existing['access_pin_hash'] : ''))),
            'notes' => $data['notes'],
            'subscription_key' => $existing ? $existing['subscription_key'] : panel_random_hex(16),
            'uuid' => $existing ? panel_array_get($existing, 'uuid', '') : '',
            'remote_email' => $serviceUsername,
            'remote_client_id' => $serviceUsername,
            'remote_sub_id' => '',
            'last_error' => '',
            'service_username' => $serviceUsername,
            'service_password_enc' => $this->encrypt($passwordPlain),
            'um_profile_name' => trim((string) panel_array_get($template, 'um_profile_name', panel_array_get($template, 'public_label', ''))),
            'um_profile_id' => trim((string) panel_array_get($template, 'um_profile_id', '')),
            'um_remote_user_id' => $existing ? trim((string) panel_array_get($existing, 'um_remote_user_id', '')) : '',
            'um_remote_user_menu' => $existing ? trim((string) panel_array_get($existing, 'um_remote_user_menu', '')) : '',
        );
        $adapter = $this->nodeAdapter($node);
        $remote = $adapter->createOrUpdateUser(array_merge($existing ? $existing : array(), $payload, array('service_password_plain' => $passwordPlain)), $template);
        if (!empty($remote['user']) && is_array($remote['user'])) {
            $payload['um_remote_user_id'] = trim((string) panel_array_get($remote['user'], 'id', panel_array_get($payload, 'um_remote_user_id', '')));
            $payload['um_remote_user_menu'] = trim((string) panel_array_get($remote['user'], 'menu', panel_array_get($payload, 'um_remote_user_menu', '')));
            if ($payload['um_remote_user_id'] !== '') {
                $payload['remote_client_id'] = $payload['um_remote_user_id'];
            }
        }
        if (empty($remote['ok'])) {
            $this->logUmEvent('error', 'Remote UM customer sync failed during save.', array('customer_id' => $id ? $id : '', 'reseller_id' => $reseller['id'], 'template_id' => $template['id'], 'node_id' => $node ? $node['id'] : '', 'mode' => $mode, 'username' => $serviceUsername, 'message' => panel_array_get($remote, 'message', 'Unknown error.')));
            $this->flash('error', 'Customer was not saved because UM sync failed: ' . panel_array_get($remote, 'message', 'Unknown error.'));
            $this->redirect('/reseller/customers');
        }
        $this->logUmEvent('access', 'Remote UM customer sync completed during save.', array('customer_id' => $id ? $id : '', 'reseller_id' => $reseller['id'], 'template_id' => $template['id'], 'node_id' => $node ? $node['id'] : '', 'mode' => $mode, 'username' => $serviceUsername, 'message' => panel_array_get($remote, 'message', '')));
        if ($mode === 'edit') {
            $this->store->update('customers', $id, $payload);
            if ($creditDelta != 0) {
                $profileName = trim((string) panel_array_get($template, 'um_profile_name', panel_array_get($template, 'public_label', '')));
                $note = 'UM profile adjustment for customer ' . $payload['display_name'];
                if ($profileName !== '') { $note .= ' (' . $profileName . ')'; }
                $this->changeResellerCredit($reseller['id'], -$creditDelta, 'customer_edit', $note);
            }
            $customer = $this->store->find('customers', $id);
        } else {
            $customer = $this->store->insert('customers', $payload, 'cus');
            $profileName = trim((string) panel_array_get($template, 'um_profile_name', panel_array_get($template, 'public_label', '')));
            $note = 'UM profile allocation for customer ' . $payload['display_name'];
            if ($profileName !== '') { $note .= ' (' . $profileName . ')'; }
            $this->changeResellerCredit($reseller['id'], -$traffic, 'customer_create', $note);
        }
        $this->storeCustomerLinkForType($customer, $template, $node, $serviceUsername, $serviceUsername, '');
        if ($mode === 'edit') { $this->logResellerActivity($reseller['id'], 'customer.edit', $customer, array_merge(array('traffic_gb' => $traffic), $this->customerTypeContext($customer, $template, $node))); } else { $this->logResellerActivity($reseller['id'], 'customer.create', $customer, array_merge(array('traffic_gb' => $traffic), $this->customerTypeContext($customer, $template, $node))); }
        $this->flash('success', ($mode === 'edit' ? 'UM customer updated.' : 'UM customer created successfully.') . ' ' . panel_array_get($remote, 'message', ''));
        $this->redirect('/reseller/customers');
    }

    protected function encrypt($plain)
    {
        $cfg = $this->runtimeConfig(); $appKey = isset($cfg['app_key']) ? $cfg['app_key'] : '';
        if ($appKey === '' || !function_exists('openssl_encrypt')) { return base64_encode($plain); }
        $iv = function_exists('random_bytes') ? random_bytes(16) : openssl_random_pseudo_bytes(16);
        $key = hash('sha256', $appKey, true); $cipher = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv); return base64_encode($iv . $cipher);
    }
    protected function decrypt($payload)
    {
        $cfg = $this->runtimeConfig(); $appKey = isset($cfg['app_key']) ? $cfg['app_key'] : '';
        if ($appKey === '' || !function_exists('openssl_decrypt')) { return base64_decode($payload); }
        $raw = base64_decode($payload); if ($raw === false || strlen($raw) < 17) { return ''; }
        $iv = substr($raw, 0, 16); $cipher = substr($raw, 16); $key = hash('sha256', $appKey, true); return (string) openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    }

    protected function nodeAdapter($node)
    {
        $node['panel_password_plain'] = isset($node['panel_password']) ? $this->decrypt($node['panel_password']) : '';
        $node['xui_api_token_plain'] = isset($node['xui_api_token']) ? $this->decrypt($node['xui_api_token']) : '';
        $node['xui_proxy_password_plain'] = isset($node['xui_proxy_password']) ? $this->decrypt($node['xui_proxy_password']) : '';
        if ($this->nodeServerType($node) === 'um') {
            return new MikrotikUmAdapter($node, $this->storage);
        }
        return new XuiAdapter($node, $this->storage);
    }

    protected function makeUuid()
    {
        $data = function_exists('random_bytes') ? random_bytes(16) : openssl_random_pseudo_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    protected function log($event, $context)
    {
        $this->store->appendLog('audit', array('time' => panel_now(), 'event' => $event, 'context' => $context));
    }


    protected function appendSecurityLog($channel, $level, $message, $context)
    {
        $channel = preg_replace('/[^a-z0-9_-]+/i', '', strtolower((string) $channel));
        $level = preg_replace('/[^a-z0-9_-]+/i', '', strtolower((string) $level));
        if ($channel === '') { $channel = 'app'; }
        if ($level === '') { $level = 'access'; }
        $name = $channel . '_' . $level;
        $this->rotateLogFileIfNeeded($name);
        $this->store->appendLog($name, array(
            'time' => panel_now(),
            'channel' => $channel,
            'level' => $level,
            'path' => $this->requestPath,
            'message' => (string) $message,
            'context' => (array) $context,
        ));
    }

    protected function apiKeyFingerprint($key)
    {
        $key = trim((string) $key);
        if ($key === '') { return ''; }
        return substr(hash('sha256', $key), 0, 16);
    }

    protected function apiModeSummary()
    {
        $encryption = $this->apiEncryptionEnabled();
        return array(
            'enabled' => $this->apiEnabled() ? 1 : 0,
            'encryption_required' => $encryption ? 1 : 0,
            'cipher' => $encryption ? 'AES-256-CBC' : '',
            'key_derivation' => $encryption ? 'sha256("panel-api|" + API_KEY)' : '',
            'request_format' => $encryption ? 'json envelope with iv + payload' : 'plain json',
            'response_format' => $encryption ? '{"ok":true,"encrypted":1,"iv":"...","payload":"..."}' : '{"ok":true,...}',
        );
    }

    protected function logApiEvent($level, $message, $context)
    {
        $this->appendSecurityLog('api', $level, $message, (array) $context);
    }

    protected function logXuiEvent($level, $message, $context)
    {
        $this->appendSecurityLog('xui', $level, $message, (array) $context);
    }

    protected function logUmEvent($level, $message, $context)
    {
        $this->appendSecurityLog('um', $level, $message, (array) $context);
    }

    protected function logTelegramEvent($level, $message, $context)
    {
        $this->appendSecurityLog('telegram', $level, $message, (array) $context);
    }

    protected function logSyncEvent($level, $message, $context)
    {
        $this->appendSecurityLog('sync', $level, $message, (array) $context);
    }

    protected function rotateLogFileIfNeeded($name)
    {
        $dir = $this->storage . '/logs';
        $file = $dir . '/' . $name . '.log';
        if (!is_file($file)) { return; }
        $maxBytes = 512 * 1024;
        if (@filesize($file) < $maxBytes) { return; }
        for ($i = 4; $i >= 1; $i--) {
            $src = $file . '.' . $i;
            $dst = $file . '.' . ($i + 1);
            if (is_file($src)) { @rename($src, $dst); }
        }
        @rename($file, $file . '.1');
    }

    protected function availableSystemLogNames()
    {
        return array('login_access','login_error','get_access','get_error','firewall_error','xui_access','xui_error','um_access','um_error','api_access','api_error','telegram_access','telegram_error','sync_access','sync_error','cron_access','cron_error');
    }

    protected function readSystemLogRows($name, $limit)
    {
        $name = preg_replace('/[^a-z0-9_.-]+/i', '', (string) $name);
        $files = array();
        $base = $this->storage . '/logs/' . $name . '.log';
        if (is_file($base)) { $files[] = $base; }
        for ($i = 1; $i <= 5; $i++) {
            $f = $base . '.' . $i;
            if (is_file($f)) { $files[] = $f; }
        }
        $rows = array();
        foreach ($files as $file) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!is_array($lines)) { continue; }
            foreach ($lines as $line) {
                $row = json_decode($line, true);
                if (!is_array($row)) { continue; }
                if (isset($row['path']) && $row['path'] === '__adapter__') { continue; }
                if (isset($row['message']) && strpos((string) $row['message'], 'UM debug command') === 0) { continue; }
                $rows[] = $row;
            }
        }
        usort($rows, function ($a, $b) {
            return strcmp(isset($b['time']) ? $b['time'] : '', isset($a['time']) ? $a['time'] : '');
        });
        return array_slice($rows, 0, max(1, (int) $limit));
    }

    protected function adminSystemLogs()
    {
        $name = trim((string) $this->input('name', 'login_error'));
        if (!in_array($name, $this->availableSystemLogNames(), true)) { $name = 'login_error'; }
        $limit = trim((string) $this->input('limit', '200'));
        if (!ctype_digit($limit)) { $limit = '200'; }
        $limit = max(20, min(500, (int) $limit));
        $this->renderPanel('admin_logs.php', array(
            'title' => 'System logs',
            'log_names' => $this->availableSystemLogNames(),
            'selected_log_name' => $name,
            'limit' => $limit,
            'rows' => $this->readSystemLogRows($name, $limit),
        ));
    }

    protected function clearAdminLog()
    {
        $name = trim((string) $this->input('name', ''));
        if (!in_array($name, $this->availableSystemLogNames(), true)) {
            $this->flash('error', 'Log selection is invalid.');
            $this->redirect('/admin/logs');
        }
        $base = $this->storage . '/logs/' . $name . '.log';
        @unlink($base);
        for ($i = 1; $i <= 5; $i++) { @unlink($base . '.' . $i); }
        $this->flash('success', 'Selected log was cleared.');
        $this->redirect('/admin/logs?name=' . rawurlencode($name));
    }

    protected function adminTransactions()
    {
        $items = $this->store->all('credit_ledger');
        $resellerId = trim((string) $this->input('reseller_id', ''));
        $type = trim((string) $this->input('type', ''));
        if ($resellerId !== '') {
            $items = array_values(array_filter($items, function ($item) use ($resellerId) { return isset($item['reseller_id']) && $item['reseller_id'] === $resellerId; }));
        }
        if ($type !== '') {
            $items = array_values(array_filter($items, function ($item) use ($type) { return isset($item['type']) && (string) $item['type'] === (string) $type; }));
        }
        usort($items, array($this, 'sortNewest'));
        $types = array();
        foreach ($this->store->all('credit_ledger') as $item) {
            if (!empty($item['type'])) { $types[(string) $item['type']] = (string) $item['type']; }
        }
        ksort($types);
        $this->renderPanel('admin_transactions.php', array(
            'title' => 'Transactions',
            'items' => $items,
            'resellers' => $this->store->all('resellers'),
            'selected_reseller_id' => $resellerId,
            'selected_type' => $type,
            'types' => array_values($types),
        ));
    }


protected function apiEnabled()
{
    $s = $this->securitySettings();
    return !empty($s['api_enabled']);
}

protected function apiEncryptionEnabled()
{
    $s = $this->securitySettings();
    return !empty($s['api_encryption']);
}

protected function resellerApiKey($reseller)
{
    if (!is_array($reseller)) { return ''; }
    if (!empty($reseller['api_key'])) { return (string) $reseller['api_key']; }
    $generated = panel_random_hex(48);
    $this->store->update('resellers', $reseller['id'], array('api_key' => $generated));
    return $generated;
}

protected function deriveApiCryptoKey($apiKey)
{
    return hash('sha256', 'panel-api|' . (string) $apiKey, true);
}

protected function apiRequireReseller()
{
    if (!$this->apiEnabled()) {
        $this->logApiEvent('error', 'Reseller API request rejected because the API is disabled.', array('ip' => $this->clientIp(), 'method' => $this->requestMethod));
        $this->json(array('ok' => false, 'message' => 'API is disabled.'), 403);
    }
    $key = trim((string) (isset($_SERVER['HTTP_X_RESELLER_API_KEY']) ? $_SERVER['HTTP_X_RESELLER_API_KEY'] : ''));
    if ($key === '' && !empty($_SERVER['HTTP_AUTHORIZATION']) && stripos((string) $_SERVER['HTTP_AUTHORIZATION'], 'Bearer ') === 0) {
        $key = trim(substr((string) $_SERVER['HTTP_AUTHORIZATION'], 7));
    }
    if ($key === '') {
        $this->logApiEvent('error', 'Reseller API request rejected because the API key is missing.', array('ip' => $this->clientIp(), 'method' => $this->requestMethod));
        $this->json(array('ok' => false, 'message' => 'Missing API key.'), 401);
    }
    $reseller = $this->store->findBy('resellers', 'api_key', $key);
    if (!$reseller || (isset($reseller['status']) && $reseller['status'] !== 'active')) {
        $this->logApiEvent('error', 'Reseller API authentication failed.', array('ip' => $this->clientIp(), 'method' => $this->requestMethod, 'api_key_fingerprint' => $this->apiKeyFingerprint($key)));
        $this->json(array('ok' => false, 'message' => 'Invalid API key.'), 401);
    }
    return array($reseller, $key);
}

protected function apiReadPayload($apiKey, $method)
{
    $raw = (string) @file_get_contents('php://input');
    if ($method === 'GET') { return array(); }
    if ($raw === '') {
        return $_POST;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $_POST;
    }
    if ($this->apiEncryptionEnabled()) {
        if (empty($decoded['iv']) || empty($decoded['payload'])) {
            $this->json(array('ok' => false, 'message' => 'Encrypted API payload required.'), 400);
        }
        $iv = base64_decode((string) $decoded['iv'], true);
        $payload = base64_decode((string) $decoded['payload'], true);
        if ($iv === false || $payload === false || strlen($iv) !== 16) {
            $this->json(array('ok' => false, 'message' => 'Encrypted API payload is invalid.'), 400);
        }
        $plain = function_exists('openssl_decrypt') ? openssl_decrypt($payload, 'AES-256-CBC', $this->deriveApiCryptoKey($apiKey), OPENSSL_RAW_DATA, $iv) : false;
        if (!is_string($plain) || $plain === '') {
            $this->json(array('ok' => false, 'message' => 'Encrypted API payload could not be decrypted.'), 400);
        }
        $decoded = json_decode($plain, true);
        if (!is_array($decoded)) {
            $this->json(array('ok' => false, 'message' => 'Encrypted API payload JSON is invalid.'), 400);
        }
        return $decoded;
    }
    return $decoded;
}

protected function apiRespond($data, $status, $apiKey)
{
    if ($this->apiEncryptionEnabled()) {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES);
        $iv = function_exists('random_bytes') ? random_bytes(16) : openssl_random_pseudo_bytes(16);
        $cipher = openssl_encrypt($json, 'AES-256-CBC', $this->deriveApiCryptoKey($apiKey), OPENSSL_RAW_DATA, $iv);
        if ($cipher !== false) {
            $this->json(array('ok' => true, 'encrypted' => 1, 'iv' => base64_encode($iv), 'payload' => base64_encode($cipher)), $status);
        }
    }
    $this->json($data, $status);
}

protected function handleResellerApi($path, $method)
{
    list($reseller, $apiKey) = $this->apiRequireReseller();
    $payload = $this->apiReadPayload($apiKey, $method);
    $ctx = array('route' => $path, 'method' => $method, 'reseller_id' => $reseller['id'], 'ip' => $this->clientIp(), 'api_key_fingerprint' => $this->apiKeyFingerprint($apiKey));
    $oldPost = $_POST; $oldGet = $_GET; $oldReq = $_REQUEST;
    if ($method !== 'GET') {
        $_POST = is_array($payload) ? $payload : array();
        $_REQUEST = array_merge($_GET, $_POST);
    }
    if ($path === '/api/reseller/profile' && $method === 'GET') {
        $out = array('ok' => true, 'api_version' => 'v1', 'api' => $this->apiModeSummary(), 'reseller' => $this->apiResellerSummary($reseller));
        $this->logApiEvent('access', 'Reseller API profile fetched.', $ctx);
        $this->apiRespond($out, 200, $apiKey);
    }
    if ($path === '/api/reseller/templates' && $method === 'GET') {
        $templates = array();
        foreach ($this->resellerTemplates($reseller) as $tpl) {
            $node = $this->store->find('nodes', $tpl['node_id']);
            $templates[] = array(
                'id' => $tpl['id'],
                'template_id' => $tpl['id'],
                'server_type' => $this->templateServerType($tpl, $node),
                'title' => $tpl['public_label'],
                'public_label' => $tpl['public_label'],
                'status' => isset($tpl['status']) ? $tpl['status'] : 'active',
                'inbound_id' => isset($tpl['inbound_id']) ? $tpl['inbound_id'] : '',
                'inbound_name' => isset($tpl['inbound_name']) ? $tpl['inbound_name'] : '',
                'protocol' => isset($tpl['protocol']) ? $tpl['protocol'] : '',
                'client_extra_query' => isset($tpl['client_extra_query']) ? $tpl['client_extra_query'] : '',
                'billing_gb' => isset($tpl['billing_gb']) ? (float) $tpl['billing_gb'] : 0,
                'um_profile_id' => isset($tpl['um_profile_id']) ? $tpl['um_profile_id'] : '',
                'um_profile_name' => isset($tpl['um_profile_name']) ? $tpl['um_profile_name'] : '',
                'node' => $node ? $node['title'] : '',
                'node_title' => $node ? $node['title'] : '',
                'node_id' => $tpl['node_id'],
            );
        }
        $this->logApiEvent('access', 'Reseller API templates fetched.', array_merge($ctx, array('count' => count($templates))));
        $this->apiRespond(array('ok' => true, 'api_version' => 'v1', 'templates' => $templates), 200, $apiKey);
    }
    if ($path === '/api/reseller/customers' && $method === 'GET') {
        $items = $this->store->filterBy('customers', function ($item) use ($reseller) { return isset($item['reseller_id']) && $item['reseller_id'] === $reseller['id']; });
        usort($items, array($this, 'sortNewest'));
        $out = array();
        foreach ($items as $item) { $out[] = $this->apiCustomerSummary($item, false); }
        $this->logApiEvent('access', 'Reseller API customers fetched.', array_merge($ctx, array('count' => count($out))));
        $this->apiRespond(array('ok' => true, 'api_version' => 'v1', 'customers' => $out), 200, $apiKey);
    }
    if (preg_match('#^/api/reseller/customers/([^/]+)$#', $path, $m) && $method === 'GET') {
        $customer = $this->loadCustomerForApi($m[1], $reseller['id']);
        $this->logApiEvent('access', 'Reseller API customer fetched.', array_merge($ctx, array('customer_id' => $customer['id'])));
        $this->apiRespond(array('ok' => true, 'api_version' => 'v1', 'customer' => $this->apiCustomerSummary($customer, true)), 200, $apiKey);
    }
    if ($path === '/api/reseller/customers/create' && $method === 'POST') {
        $result = $this->saveCustomerApi($reseller, null);
        $level = !empty($result['ok']) ? 'access' : 'error';
        $this->logApiEvent($level, 'Reseller API customer create finished.', array_merge($ctx, array('message' => isset($result['message']) ? $result['message'] : '', 'customer_id' => panel_array_get($result, 'customer.id', ''))));
        $this->apiRespond($result, !empty($result['ok']) ? 200 : 422, $apiKey);
    }
    if (preg_match('#^/api/reseller/customers/([^/]+)/edit$#', $path, $m) && $method === 'POST') {
        $result = $this->saveCustomerApi($reseller, $m[1]);
        $level = !empty($result['ok']) ? 'access' : 'error';
        $this->logApiEvent($level, 'Reseller API customer edit finished.', array_merge($ctx, array('message' => isset($result['message']) ? $result['message'] : '', 'customer_id' => panel_array_get($result, 'customer.id', $m[1]))));
        $this->apiRespond($result, !empty($result['ok']) ? 200 : 422, $apiKey);
    }
    if (preg_match('#^/api/reseller/customers/([^/]+)/toggle$#', $path, $m) && $method === 'POST') {
        $result = $this->toggleCustomerApi($reseller, $m[1]);
        $level = !empty($result['ok']) ? 'access' : 'error';
        $this->logApiEvent($level, 'Reseller API customer toggle finished.', array_merge($ctx, array('message' => isset($result['message']) ? $result['message'] : '', 'customer_id' => panel_array_get($result, 'customer.id', $m[1]))));
        $this->apiRespond($result, !empty($result['ok']) ? 200 : 422, $apiKey);
    }
    if (preg_match('#^/api/reseller/customers/([^/]+)/delete$#', $path, $m) && $method === 'POST') {
        $result = $this->deleteCustomerApi($reseller, $m[1]);
        $level = !empty($result['ok']) ? 'access' : 'error';
        $this->logApiEvent($level, 'Reseller API customer delete finished.', array_merge($ctx, array('message' => isset($result['message']) ? $result['message'] : '', 'customer_id' => $m[1])));
        $this->apiRespond($result, !empty($result['ok']) ? 200 : 422, $apiKey);
    }
    if (preg_match('#^/api/reseller/customers/([^/]+)/sync$#', $path, $m) && $method === 'POST') {
        $result = $this->syncCustomerApi($reseller, $m[1]);
        $level = !empty($result['ok']) ? 'access' : 'error';
        $this->logApiEvent($level, 'Reseller API customer sync finished.', array_merge($ctx, array('message' => isset($result['message']) ? $result['message'] : '', 'customer_id' => panel_array_get($result, 'customer.id', $m[1]))));
        $this->apiRespond($result, !empty($result['ok']) ? 200 : 422, $apiKey);
    }
    if ($path === '/api/reseller/password' && $method === 'POST') {
        $result = $this->changeResellerPasswordApi($reseller);
        $level = !empty($result['ok']) ? 'access' : 'error';
        $this->logApiEvent($level, 'Reseller API password change finished.', array_merge($ctx, array('message' => isset($result['message']) ? $result['message'] : '')));
        $this->apiRespond($result, !empty($result['ok']) ? 200 : 422, $apiKey);
    }
    $_POST = $oldPost; $_GET = $oldGet; $_REQUEST = $oldReq;
    $this->logApiEvent('error', 'Reseller API route not found.', $ctx);
    $this->apiRespond(array('ok' => false, 'message' => 'API route not found.'), 404, $apiKey);
}

protected function apiResellerSummary($reseller)
{
    return array(
        'id' => $reseller['id'],
        'username' => $reseller['username'],
        'display_name' => $reseller['display_name'],
        'status' => isset($reseller['status']) ? $reseller['status'] : 'active',
        'prefix' => isset($reseller['prefix']) ? $reseller['prefix'] : '',
        'credit_gb' => (float) $reseller['credit_gb'],
        'fixed_duration_days' => isset($reseller['fixed_duration_days']) ? (int) $reseller['fixed_duration_days'] : $this->defaultDurationDays(),
        'max_expiration_days' => $this->resellerMaxExpirationDays($reseller),
        'max_ip_limit' => $this->resellerMaxIpLimit($reseller),
        'min_customer_traffic_gb' => isset($reseller['min_customer_traffic_gb']) ? (float) $reseller['min_customer_traffic_gb'] : 0.0,
        'max_customer_traffic_gb' => isset($reseller['max_customer_traffic_gb']) ? (float) $reseller['max_customer_traffic_gb'] : 0.0,
        'allow_fractional_traffic_gb' => $this->resellerAllowsFractionalTraffic($reseller) ? 1 : 0,
        'restrict' => !empty($reseller['restrict']) ? 1 : 0,
        'allowed_template_ids' => isset($reseller['allowed_template_ids']) && is_array($reseller['allowed_template_ids']) ? array_values($reseller['allowed_template_ids']) : array(),
        'api_enabled' => $this->apiEnabled() ? 1 : 0,
        'api_encryption' => $this->apiEncryptionEnabled() ? 1 : 0,
        'encryption_required' => $this->apiEncryptionEnabled() ? 1 : 0,
        'encryption_cipher' => $this->apiEncryptionEnabled() ? 'AES-256-CBC' : '',
        'encryption_key_derivation' => $this->apiEncryptionEnabled() ? 'sha256("panel-api|" + API_KEY)' : '',
        'request_format' => $this->apiEncryptionEnabled() ? 'json envelope with iv + payload' : 'plain json',
    );
}

protected function apiCustomerSummary($customer, $includeLinks = false)
{
    $tpl = $this->store->find('templates', isset($customer['template_id']) ? $customer['template_id'] : '');
    $node = $tpl ? $this->store->find('nodes', $tpl['node_id']) : null;
    $subscriptionKey = isset($customer['subscription_key']) ? $customer['subscription_key'] : '';
    $remoteSubId = isset($customer['remote_sub_id']) ? $customer['remote_sub_id'] : '';
    $remoteKey = $remoteSubId !== '' ? $remoteSubId : $subscriptionKey;
    $nodeSubscription = $node ? $this->buildNodeSubscriptionUrl($node, $remoteKey) : '';
    $publicSubscription = $subscriptionKey !== '' ? $this->appLink('/user/' . $subscriptionKey) : '';
    $row = array(
        'id' => $customer['id'],
        'server_type' => $this->customerServerType($customer, $tpl, $node),
        'display_name' => $customer['display_name'],
        'system_name' => $customer['system_name'],
        'status' => $customer['status'],
        'phone' => isset($customer['phone']) ? $customer['phone'] : '',
        'email' => isset($customer['email']) ? $customer['email'] : '',
        'template_id' => isset($customer['template_id']) ? $customer['template_id'] : '',
        'template_title' => $tpl && isset($tpl['public_label']) ? $tpl['public_label'] : '',
        'node_id' => isset($customer['node_id']) ? $customer['node_id'] : ($node ? $node['id'] : ''),
        'node_title' => $node ? $node['title'] : '',
        'traffic_gb' => (float) $customer['traffic_gb'],
        'used_gb' => panel_to_gb_from_bytes(isset($customer['traffic_bytes_used']) ? $customer['traffic_bytes_used'] : 0),
        'left_gb' => panel_to_gb_from_bytes(isset($customer['traffic_bytes_left']) ? $customer['traffic_bytes_left'] : 0),
        'expires_at' => isset($customer['expires_at']) ? $customer['expires_at'] : '',
        'expiration_mode' => $this->customerExpirationMode($customer),
        'expires_label' => $this->customerExpirationLabel($customer),
        'subscription_key' => $subscriptionKey,
        'remote_sub_id' => $remoteSubId,
        'remote_client_id' => isset($customer['remote_client_id']) ? $customer['remote_client_id'] : '',
        'remote_email' => isset($customer['remote_email']) ? $customer['remote_email'] : '',
        'ip_limit' => isset($customer['ip_limit']) ? (int) $customer['ip_limit'] : 0,
        'duration_days' => isset($customer['duration_days']) ? (int) $customer['duration_days'] : 0,
        'last_synced_at' => isset($customer['last_synced_at']) ? $customer['last_synced_at'] : '',
        'last_error' => isset($customer['last_error']) ? $customer['last_error'] : '',
        'service_username' => $this->customerServiceUsername($customer),
        'service_password' => $this->customerServicePasswordPlain($customer),
        'um_connection' => $this->customerServerType($customer, $tpl, $node) === 'um' ? $this->buildUmConnectionInfo($customer, $tpl, $node) : null,
    );
    if ($includeLinks) {
        if ($this->customerServerType($customer, $tpl, $node) === 'um') {
            $row['public_access_url'] = $publicSubscription;
            $row['export_url'] = $subscriptionKey !== '' ? $this->appLink('/user/' . $subscriptionKey . '/export') : '';
        } else {
            $row['subscription_url'] = $nodeSubscription;
            $row['fallback_subscription_url'] = $publicSubscription;
            $row['public_subscription_url'] = $publicSubscription;
            $row['export_url'] = $subscriptionKey !== '' ? $this->appLink('/user/' . $subscriptionKey . '/export') : '';
        }
    }
    return $row;
}

protected function loadCustomerForApi($id, $resellerId)
{
    $customer = $this->store->find('customers', $id);
    if (!$customer || $customer['reseller_id'] !== $resellerId) {
        $this->json(array('ok' => false, 'message' => 'Customer not found.'), 404);
    }
    return $customer;
}

protected function saveCustomerApi($reseller, $id)
{
    $oldAuth = isset($_SESSION['auth']) ? $_SESSION['auth'] : null;
    $_SESSION['auth'] = array('id' => $reseller['id'], 'role' => 'reseller', 'display_name' => $reseller['display_name']);
    $flashBefore = isset($_SESSION['_flash']) ? $_SESSION['_flash'] : null;
    ob_start();
    try {
        $this->saveCustomer($id);
    } catch (Exception $e) {
    }
    ob_end_clean();
    $flash = isset($_SESSION['_flash']) ? $_SESSION['_flash'] : $flashBefore;
    if ($oldAuth !== null) { $_SESSION['auth'] = $oldAuth; } else { unset($_SESSION['auth']); }
    if ($flash && isset($flash['message'])) {
        if ($flash['type'] === 'success') {
            $customers = $this->store->filterBy('customers', function ($item) use ($reseller, $id) { return isset($item['reseller_id']) && $item['reseller_id'] === $reseller['id']; });
            usort($customers, array($this, 'sortNewest'));
            $customer = null;
            if ($id) { $customer = $this->store->find('customers', $id); }
            if (!$customer && !empty($customers)) { $customer = $customers[0]; }
            unset($_SESSION['_flash']);
            return array('ok' => true, 'message' => $flash['message'], 'customer' => $customer ? $this->apiCustomerSummary($customer, true) : null);
        }
        unset($_SESSION['_flash']);
        return array('ok' => false, 'message' => $flash['message']);
    }
    return array('ok' => false, 'message' => 'Customer operation could not be completed.');
}

protected function toggleCustomerApi($reseller, $id)
{
    $oldAuth = isset($_SESSION['auth']) ? $_SESSION['auth'] : null;
    $_SESSION['auth'] = array('id' => $reseller['id'], 'role' => 'reseller', 'display_name' => $reseller['display_name']);
    ob_start();
    try { $this->toggleCustomer($id, true); } catch (Exception $e) {}
    ob_end_clean();
    $flash = isset($_SESSION['_flash']) ? $_SESSION['_flash'] : null;
    if ($oldAuth !== null) { $_SESSION['auth'] = $oldAuth; } else { unset($_SESSION['auth']); }
    if ($flash) { unset($_SESSION['_flash']); }
    $customer = $this->store->find('customers', $id);
    if ($flash && $flash['type'] === 'success') { return array('ok' => true, 'message' => $flash['message'], 'customer' => $customer ? $this->apiCustomerSummary($customer, true) : null); }
    return array('ok' => false, 'message' => $flash ? $flash['message'] : 'Customer status update failed.');
}

protected function deleteCustomerApi($reseller, $id)
{
    $oldAuth = isset($_SESSION['auth']) ? $_SESSION['auth'] : null;
    $_SESSION['auth'] = array('id' => $reseller['id'], 'role' => 'reseller', 'display_name' => $reseller['display_name']);
    ob_start();
    try { $this->deleteCustomer($id, true); } catch (Exception $e) {}
    ob_end_clean();
    $flash = isset($_SESSION['_flash']) ? $_SESSION['_flash'] : null;
    if ($oldAuth !== null) { $_SESSION['auth'] = $oldAuth; } else { unset($_SESSION['auth']); }
    if ($flash) { unset($_SESSION['_flash']); }
    if ($flash && $flash['type'] === 'success') { return array('ok' => true, 'message' => $flash['message']); }
    return array('ok' => false, 'message' => $flash ? $flash['message'] : 'Customer delete failed.');
}

protected function syncCustomerApi($reseller, $id)
{
    $oldAuth = isset($_SESSION['auth']) ? $_SESSION['auth'] : null;
    $_SESSION['auth'] = array('id' => $reseller['id'], 'role' => 'reseller', 'display_name' => $reseller['display_name']);
    ob_start();
    try { $this->syncCustomer($id, true); } catch (Exception $e) {}
    ob_end_clean();
    $flash = isset($_SESSION['_flash']) ? $_SESSION['_flash'] : null;
    if ($oldAuth !== null) { $_SESSION['auth'] = $oldAuth; } else { unset($_SESSION['auth']); }
    if ($flash) { unset($_SESSION['_flash']); }
    $customer = $this->store->find('customers', $id);
    if ($flash && $flash['type'] === 'success') { return array('ok' => true, 'message' => $flash['message'], 'customer' => $customer ? $this->apiCustomerSummary($customer, true) : null); }
    return array('ok' => false, 'message' => $flash ? $flash['message'] : 'Customer sync failed.');
}

protected function changeResellerPasswordApi($reseller)
{
    $current = trim((string) $this->input('current_password', ''));
    $new = trim((string) $this->input('new_password', ''));
    $confirm = trim((string) $this->input('confirm_password', ''));
    if ($current === '' || $new === '') { return array('ok' => false, 'message' => 'Current and new password are required.'); }
    if (!password_verify($current, isset($reseller['password_hash']) ? $reseller['password_hash'] : '')) { return array('ok' => false, 'message' => 'Current password is incorrect.'); }
    if (strlen($new) < 8) { return array('ok' => false, 'message' => 'New password must be at least 8 characters.'); }
    if ($new !== $confirm) { return array('ok' => false, 'message' => 'Password confirmation does not match.'); }
    $this->store->update('resellers', $reseller['id'], array('password_hash' => panel_password_hash($new)));
    return array('ok' => true, 'message' => 'Password updated.');
}

protected function activeNotices($audience)
{
    $items = $this->store->all('notices');
    $out = array();
    $now = time();
    foreach ($items as $item) {
        if (isset($item['status']) && $item['status'] !== 'active') { continue; }
        $target = isset($item['target']) ? $item['target'] : 'reseller';
        if ($target !== 'all' && $target !== $audience) { continue; }
        $startAt = !empty($item['start_at']) ? strtotime($item['start_at']) : 0;
        $endAt = !empty($item['end_at']) ? strtotime($item['end_at']) : 0;
        if ($startAt && $now < $startAt) { continue; }
        if ($endAt && $now > $endAt) { continue; }
        $out[] = $item;
    }
    usort($out, array($this, 'sortNewest'));
    return $out;
}

protected function adminNotices()
{
    $items = $this->store->all('notices');
    usort($items, array($this, 'sortNewest'));
    $this->renderPanel('admin_notices.php', array('title' => 'Notices', 'notices' => $items));
}

protected function adminNoticeForm($mode, $id = null)
{
    $record = array('title' => '', 'body' => '', 'target' => 'reseller', 'start_at' => '', 'end_at' => '', 'status' => 'active');
    if ($mode === 'edit') {
        $found = $this->store->find('notices', $id);
        if (!$found) { $this->flash('error', 'Notice not found.'); $this->redirect('/admin/notices'); }
        $record = array_merge($record, $found);
    }
    $this->renderPanel('admin_notice_form.php', array('title' => $mode === 'edit' ? 'Edit notice' : 'Create notice', 'mode' => $mode, 'record' => $record, 'errors' => array()));
}

protected function saveNotice($id = null)
{
    $mode = $id ? 'edit' : 'create';
    $record = $id ? $this->store->find('notices', $id) : null;
    if ($id && !$record) { $this->flash('error', 'Notice not found.'); $this->redirect('/admin/notices'); }
    $data = array('title' => trim((string) $this->input('title', '')), 'body' => trim((string) $this->input('body', '')), 'target' => trim((string) $this->input('target', 'reseller')), 'start_at' => trim((string) $this->input('start_at', '')), 'end_at' => trim((string) $this->input('end_at', '')), 'status' => trim((string) $this->input('status', 'active')));
    $errors = array();
    if (strlen($data['title']) < 2) { $errors['title'][] = 'Title must be at least 2 characters.'; }
    if (strlen($data['body']) < 2) { $errors['body'][] = 'Notice body must be at least 2 characters.'; }
    if (!in_array($data['target'], array('reseller','public','all'), true)) { $errors['target'][] = 'Target is invalid.'; }
    if (!in_array($data['status'], array('active','disabled'), true)) { $errors['status'][] = 'Status is invalid.'; }
    if ($data['start_at'] !== '' && strtotime($data['start_at']) === false) { $errors['start_at'][] = 'Start time is invalid.'; }
    if ($data['end_at'] !== '' && strtotime($data['end_at']) === false) { $errors['end_at'][] = 'End time is invalid.'; }
    if ($errors) { return $this->renderPanel('admin_notice_form.php', array('title' => $mode === 'edit' ? 'Edit notice' : 'Create notice', 'mode' => $mode, 'record' => $data, 'errors' => $errors)); }
    if ($id) { $this->store->update('notices', $id, $data); } else { $this->store->insert('notices', $data, 'ntc'); }
    $this->flash('success', $id ? 'Notice updated.' : 'Notice created.');
    $this->redirect('/admin/notices');
}

protected function deleteNotice($id)
{
    $item = $this->store->find('notices', $id);
    if (!$item) { $this->flash('error', 'Notice not found.'); $this->redirect('/admin/notices'); }
    $this->store->delete('notices', $id);
    $this->flash('success', 'Notice deleted.');
    $this->redirect('/admin/notices');
}

protected function resellerTransactions()
{
    $reseller = $this->currentReseller();
    $items = $this->store->filterBy('credit_ledger', function ($item) use ($reseller) { return isset($item['reseller_id']) && $item['reseller_id'] === $reseller['id']; });
    $type = trim((string) $this->input('type', ''));
    if ($type !== '') {
        $items = array_values(array_filter($items, function ($item) use ($type) { return isset($item['type']) && (string) $item['type'] === (string) $type; }));
    }
    usort($items, array($this, 'sortNewest'));
    $types = array();
    foreach ($this->store->filterBy('credit_ledger', function ($item) use ($reseller) { return isset($item['reseller_id']) && $item['reseller_id'] === $reseller['id']; }) as $item) {
        if (!empty($item['type'])) { $types[(string) $item['type']] = (string) $item['type']; }
    }
    ksort($types);
    $this->renderPanel('reseller_transactions.php', array(
        'title' => 'Transactions',
        'items' => $items,
        'selected_type' => $type,
        'types' => array_values($types),
    ));
}

protected function resellerActivity()
{
    $reseller = $this->currentReseller();
    $items = $this->store->filterBy('activity', function ($item) use ($reseller) { return isset($item['reseller_id']) && $item['reseller_id'] === $reseller['id']; });
    usort($items, array($this, 'sortNewest'));
    $this->renderPanel('reseller_activity.php', array('title' => 'Activity Logs', 'items' => $items));
}

protected function resellerCanEditXuiTraffic($reseller)
{
    if (!is_array($reseller)) {
        return true;
    }
    if (!array_key_exists('allow_xui_traffic_edit', $reseller)) {
        return true;
    }
    return panel_parse_bool($reseller['allow_xui_traffic_edit'], true);
}

protected function resellerSalesStats($reseller, $ledgerItems, $customers)
{
    $summary = array(
        'current_credit_gb' => (float) panel_array_get($reseller, 'credit_gb', 0),
        'customers_total' => count((array) $customers),
        'active_customers' => 0,
        'sold_total_gb' => 0.0,
        'refunded_total_gb' => 0.0,
        'topup_total_gb' => 0.0,
        'net_sales_gb' => 0.0,
        'remaining_total_gb' => 0.0,
    );
    foreach ((array) $customers as $customer) {
        $state = $this->customerRuntimeState($customer);
        if ($state === 'active') {
            $summary['active_customers']++;
        }
        if ($state !== 'removed') {
            $summary['remaining_total_gb'] += panel_to_gb_from_bytes((float) panel_array_get($customer, 'traffic_bytes_left', 0));
        }
    }

    $dailyMap = array();
    for ($i = 29; $i >= 0; $i--) {
        $key = gmdate('Y-m-d', strtotime('-' . $i . ' days'));
        $dailyMap[$key] = 0.0;
    }
    $monthlyMap = array();
    for ($i = 11; $i >= 0; $i--) {
        $key = gmdate('Y-m', strtotime(date('Y-m-01') . ' -' . $i . ' months'));
        $monthlyMap[$key] = 0.0;
    }

    foreach ((array) $ledgerItems as $item) {
        $amount = round((float) panel_array_get($item, 'amount_gb', 0), 2);
        $type = strtolower(trim((string) panel_array_get($item, 'type', '')));
        $createdAt = trim((string) panel_array_get($item, 'created_at', ''));
        $ts = $createdAt !== '' ? strtotime($createdAt) : false;
        $isSale = in_array($type, array('customer_create', 'customer_edit'), true) && $amount < 0;
        $isRefund = (($type === 'customer_delete') || ($type === 'customer_edit' && $amount > 0)) && $amount > 0;
        if ($isSale) {
            $value = round(abs($amount), 2);
            $summary['sold_total_gb'] += $value;
            if ($ts !== false) {
                $dayKey = gmdate('Y-m-d', $ts);
                $monthKey = gmdate('Y-m', $ts);
                if (array_key_exists($dayKey, $dailyMap)) { $dailyMap[$dayKey] += $value; }
                if (array_key_exists($monthKey, $monthlyMap)) { $monthlyMap[$monthKey] += $value; }
            }
        } elseif ($isRefund) {
            $summary['refunded_total_gb'] += round($amount, 2);
        } elseif ($amount > 0) {
            $summary['topup_total_gb'] += round($amount, 2);
        }
    }

    $summary['sold_total_gb'] = round($summary['sold_total_gb'], 2);
    $summary['refunded_total_gb'] = round($summary['refunded_total_gb'], 2);
    $summary['topup_total_gb'] = round($summary['topup_total_gb'], 2);
    $summary['net_sales_gb'] = round($summary['sold_total_gb'] - $summary['refunded_total_gb'], 2);
    $summary['remaining_total_gb'] = round($summary['remaining_total_gb'], 2);

    return array(
        'summary' => $summary,
        'daily' => array('labels' => array_keys($dailyMap), 'values' => array_values($dailyMap)),
        'monthly' => array('labels' => array_keys($monthlyMap), 'values' => array_values($monthlyMap)),
    );
}

protected function adminActivity()
{
    $items = $this->store->all('activity');
    $resellerId = trim((string) $this->input('reseller_id', ''));
    if ($resellerId !== '') {
        $items = array_values(array_filter($items, function ($item) use ($resellerId) { return isset($item['reseller_id']) && $item['reseller_id'] === $resellerId; }));
    }
    usort($items, array($this, 'sortNewest'));
    $this->renderPanel('admin_activity.php', array('title' => 'Reseller activity', 'items' => $items, 'resellers' => $this->store->all('resellers'), 'selected_reseller_id' => $resellerId));
}

protected function logResellerActivity($resellerId, $action, $customer, $extra)
{
    $context = (array) $extra;
    if (!isset($context['server_type']) || trim((string) $context['server_type']) === '') {
        $context = array_merge($this->customerTypeContext(is_array($customer) ? $customer : array()), $context);
    }
    $row = array(
        'reseller_id' => $resellerId,
        'action' => $action,
        'customer_id' => is_array($customer) && isset($customer['id']) ? $customer['id'] : '',
        'customer_name' => is_array($customer) && isset($customer['display_name']) ? $customer['display_name'] : '',
        'system_name' => is_array($customer) && isset($customer['system_name']) ? $customer['system_name'] : '',
        'context' => $context,
        'ip' => $this->clientIp(),
    );
    $this->store->insert('activity', $row, 'act');
}

protected function resellerProfile()
{
    $reseller = $this->currentReseller();
    $apiKey = $this->resellerApiKey($reseller);
    $reseller = $this->store->find('resellers', $reseller['id']);
    $linkToken = $this->resellerTelegramLinkToken($reseller, false);
    $reseller = $this->store->find('resellers', $reseller['id']);
    $this->renderPanel('reseller_profile.php', array(
        'title' => 'Profile',
        'reseller' => $reseller,
        'errors' => array(),
        'api_enabled' => $this->apiEnabled(),
        'api_encryption' => $this->apiEncryptionEnabled(),
        'api_key' => $apiKey,
        'telegram_settings' => $this->telegramSettings(),
        'telegram_link_token' => $linkToken,
    ));
}

protected function saveResellerPassword()
{
    $reseller = $this->currentReseller();
    $section = trim((string) $this->input('profile_section', 'password'));
    if ($section === 'telegram') {
        $tgid = trim((string) $this->input('telegram_user_id', isset($reseller['telegram_user_id']) ? $reseller['telegram_user_id'] : ''));
        $errors = array();
        if ($tgid !== '' && !ctype_digit($tgid)) {
            $errors['telegram_user_id'][] = 'Telegram user ID must contain digits only.';
        }
        if ($errors) {
            return $this->renderPanel('reseller_profile.php', array('title' => 'Profile', 'reseller' => array_replace($reseller, array('telegram_user_id' => $tgid)), 'errors' => $errors, 'api_enabled' => $this->apiEnabled(), 'api_encryption' => $this->apiEncryptionEnabled(), 'api_key' => $this->resellerApiKey($reseller), 'telegram_settings' => $this->telegramSettings(), 'telegram_link_token' => $this->resellerTelegramLinkToken($reseller, false)));
        }
        $payload = array('telegram_user_id' => $tgid);
        if (isset($_POST['regenerate_telegram_link'])) {
            $payload['telegram_link_token'] = panel_random_hex(20);
            $payload['telegram_link_expires_at'] = gmdate('c', time() + 7 * 86400);
        }
        $this->store->update('resellers', $reseller['id'], $payload);
        $this->flash('success', 'Telegram profile settings updated.');
        $this->redirect('/reseller/profile');
    }
    $current = (string) $this->input('current_password', '');
    $new = (string) $this->input('new_password', '');
    $confirm = (string) $this->input('confirm_password', '');
    $errors = array();
    if (!password_verify($current, isset($reseller['password_hash']) ? $reseller['password_hash'] : '')) { $errors['current_password'][] = 'Current password is incorrect.'; }
    if (strlen($new) < 8) { $errors['new_password'][] = 'New password must be at least 8 characters.'; }
    if ($new !== $confirm) { $errors['confirm_password'][] = 'Password confirmation does not match.'; }
    if ($errors) { return $this->renderPanel('reseller_profile.php', array('title' => 'Profile', 'reseller' => $reseller, 'errors' => $errors, 'api_enabled' => $this->apiEnabled(), 'api_encryption' => $this->apiEncryptionEnabled(), 'api_key' => $this->resellerApiKey($reseller), 'telegram_settings' => $this->telegramSettings(), 'telegram_link_token' => $this->resellerTelegramLinkToken($reseller, false))); }
    $this->store->update('resellers', $reseller['id'], array('password_hash' => panel_password_hash($new)));
    $this->flash('success', 'Password updated.');
    $this->redirect('/reseller/profile');
}

    protected function sortedTemplates() { $items = $this->store->all('templates'); usort($items, array($this, 'sortTemplate')); return $items; }
    protected function sortedNodes() { $items = $this->store->all('nodes'); usort($items, array($this, 'sortTitle')); return $items; }
    protected function sortNewest($a, $b) { return strcmp(isset($b['created_at']) ? $b['created_at'] : '', isset($a['created_at']) ? $a['created_at'] : ''); }
    protected function sortOldest($a, $b) { return strcmp(isset($a['created_at']) ? $a['created_at'] : '', isset($b['created_at']) ? $b['created_at'] : ''); }
    protected function sortTitle($a, $b) { return strcmp(isset($a['title']) ? $a['title'] : '', isset($b['title']) ? $b['title'] : ''); }
    protected function sortDisplayName($a, $b) { return strcmp(isset($a['display_name']) ? $a['display_name'] : '', isset($b['display_name']) ? $b['display_name'] : ''); }
    protected function sortByTrafficLeft($a, $b) { return (float) (isset($b['traffic_bytes_left']) ? $b['traffic_bytes_left'] : 0) - (float) (isset($a['traffic_bytes_left']) ? $a['traffic_bytes_left'] : 0); }
    protected function sortTemplate($a, $b) { $as = isset($a['sort_order']) ? (int) $a['sort_order'] : 0; $bs = isset($b['sort_order']) ? (int) $b['sort_order'] : 0; if ($as === $bs) { return strcmp(isset($a['title']) ? $a['title'] : '', isset($b['title']) ? $b['title'] : ''); } return $as > $bs ? 1 : -1; }


protected function telegramSettings()
{
    $cfg = $this->runtimeConfig();
    return array(
        'enabled' => !empty($cfg['telegram_enabled']) ? 1 : 0,
        'bot_token' => isset($cfg['telegram_bot_token']) ? trim((string) $cfg['telegram_bot_token']) : '',
        'mode' => isset($cfg['telegram_mode']) && in_array($cfg['telegram_mode'], array('webhook', 'polling'), true) ? (string) $cfg['telegram_mode'] : 'webhook',
        'webhook_secret' => isset($cfg['telegram_webhook_secret']) && trim((string) $cfg['telegram_webhook_secret']) !== '' ? trim((string) $cfg['telegram_webhook_secret']) : panel_random_hex(24),
        'proxy_enabled' => !empty($cfg['telegram_proxy_enabled']) ? 1 : 0,
        'proxy_type' => isset($cfg['telegram_proxy_type']) ? trim((string) $cfg['telegram_proxy_type']) : 'http',
        'proxy_host' => isset($cfg['telegram_proxy_host']) ? trim((string) $cfg['telegram_proxy_host']) : '',
        'proxy_port' => isset($cfg['telegram_proxy_port']) ? (int) $cfg['telegram_proxy_port'] : 0,
        'proxy_username' => isset($cfg['telegram_proxy_username']) ? (string) $cfg['telegram_proxy_username'] : '',
        'proxy_password' => isset($cfg['telegram_proxy_password']) ? (string) $cfg['telegram_proxy_password'] : '',
        'allow_reseller' => !array_key_exists('telegram_allow_reseller', $cfg) || !empty($cfg['telegram_allow_reseller']) ? 1 : 0,
        'allow_client' => !array_key_exists('telegram_allow_client', $cfg) || !empty($cfg['telegram_allow_client']) ? 1 : 0,
        'allow_admin' => !empty($cfg['telegram_allow_admin']) ? 1 : 0,
        'poll_limit' => isset($cfg['telegram_poll_limit']) ? max(1, min(100, (int) $cfg['telegram_poll_limit'])) : 20,
        'update_offset' => isset($cfg['telegram_update_offset']) ? (int) $cfg['telegram_update_offset'] : 0,
    );
}

public function telegramWebhookUrl()
{
    $s = $this->telegramSettings();
    return $this->appLink('/telegram/webhook/' . $s['webhook_secret']);
}

public function telegramPollUrl()
{
    $s = $this->telegramSettings();
    return $this->appLink('/telegram/poll/' . $s['webhook_secret']);
}

protected function telegramProxyCurlOptions(&$ch, $settings)
{
    if (empty($settings['proxy_enabled']) || empty($settings['proxy_host']) || empty($settings['proxy_port'])) {
        return;
    }
    curl_setopt($ch, CURLOPT_PROXY, $settings['proxy_host']);
    curl_setopt($ch, CURLOPT_PROXYPORT, (int) $settings['proxy_port']);
    $ptype = strtolower((string) $settings['proxy_type']);
    if ($ptype === 'socks5') {
        if (defined('CURLPROXY_SOCKS5_HOSTNAME')) {
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
        } elseif (defined('CURLPROXY_SOCKS5')) {
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
        }
    } else {
        if (defined('CURLPROXY_HTTP')) {
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
        }
    }
    if (!empty($settings['proxy_username'])) {
        curl_setopt($ch, CURLOPT_PROXYUSERPWD, $settings['proxy_username'] . ':' . $settings['proxy_password']);
    }
}

protected function telegramApiRequest($method, $payload)
{
    $settings = $this->telegramSettings();
    if (empty($settings['bot_token'])) {
        $this->logTelegramEvent('error', 'Telegram request rejected because the bot token is empty.', array('method' => $method));
        return array('ok' => false, 'message' => 'Telegram bot token is empty.');
    }
    if (!function_exists('curl_init')) {
        $this->logTelegramEvent('error', 'Telegram request rejected because cURL is unavailable.', array('method' => $method));
        return array('ok' => false, 'message' => 'cURL is required for Telegram bot support.');
    }
    $url = 'https://api.telegram.org/bot' . $settings['bot_token'] . '/' . $method;
    $ch = curl_init($url);
    $headers = array('Accept: application/json');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $tgTimeout = 40;
    if ((string) $method === 'getUpdates' && isset($payload['timeout'])) {
        $tgTimeout = max(15, min(70, ((int) $payload['timeout']) + 10));
    }
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, $tgTimeout);
    curl_setopt($ch, CURLOPT_USERAGENT, 'XUIResellerTelegramBot/1.0');
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode((array) $payload));
    $headers[] = 'Content-Type: application/json';
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $this->telegramProxyCurlOptions($ch, $settings);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $err !== '') {
        $message = 'Telegram transport error: ' . $err;
        $this->logTelegramEvent('error', 'Telegram API transport error.', array('method' => $method, 'message' => $message, 'http_code' => $code));
        return array('ok' => false, 'message' => $message);
    }
    $decoded = json_decode((string) $body, true);
    if (!is_array($decoded)) {
        $this->logTelegramEvent('error', 'Telegram API returned a non-JSON response.', array('method' => $method, 'http_code' => $code));
        return array('ok' => false, 'message' => 'Telegram returned a non-JSON response.');
    }
    if ($code >= 400 || empty($decoded['ok'])) {
        $message = isset($decoded['description']) ? $decoded['description'] : 'Telegram API request failed.';
        $this->logTelegramEvent('error', 'Telegram API request failed.', array('method' => $method, 'http_code' => $code, 'message' => $message));
        return array('ok' => false, 'message' => $message, 'result' => isset($decoded['result']) ? $decoded['result'] : null);
    }
    if ((string) $method !== 'getUpdates') {
        $this->logTelegramEvent('access', 'Telegram API request succeeded.', array('method' => $method, 'http_code' => $code));
    }
    return array('ok' => true, 'message' => 'ok', 'result' => isset($decoded['result']) ? $decoded['result'] : null);
}

protected function telegramSendMessage($chatId, $text, $replyMarkup, $disablePreview)
{
    $payload = array('chat_id' => $chatId, 'text' => (string) $text);
    if ($replyMarkup !== null) {
        $payload['reply_markup'] = $replyMarkup;
    }
    if ($disablePreview) {
        $payload['disable_web_page_preview'] = true;
    }
    return $this->telegramApiRequest('sendMessage', $payload);
}

protected function telegramEditMessage($chatId, $messageId, $text, $replyMarkup)
{
    $payload = array('chat_id' => $chatId, 'message_id' => $messageId, 'text' => (string) $text);
    if ($replyMarkup !== null) {
        $payload['reply_markup'] = $replyMarkup;
    }
    return $this->telegramApiRequest('editMessageText', $payload);
}

protected function telegramAnswerCallback($callbackId, $text)
{
    $payload = array('callback_query_id' => $callbackId);
    if ($text !== '') {
        $payload['text'] = $text;
        $payload['show_alert'] = false;
    }
    return $this->telegramApiRequest('answerCallbackQuery', $payload);
}

protected function telegramStateRecord($chatId)
{
    return $this->store->findBy('telegram_states', 'chat_id', (string) $chatId);
}

protected function telegramStateSet($chatId, $tgUserId, $flow, $step, $data)
{
    $existing = $this->telegramStateRecord($chatId);
    $payload = array('chat_id' => (string) $chatId, 'tg_user_id' => (string) $tgUserId, 'flow' => (string) $flow, 'step' => (string) $step, 'data' => (array) $data);
    if ($existing) {
        $this->store->update('telegram_states', $existing['id'], $payload);
        return $this->store->find('telegram_states', $existing['id']);
    }
    return $this->store->insert('telegram_states', $payload, 'tgs');
}

protected function telegramStateClear($chatId)
{
    $existing = $this->telegramStateRecord($chatId);
    if ($existing) {
        $this->store->delete('telegram_states', $existing['id']);
    }
}

protected function telegramBindingRecord($type, $tgUserId)
{
    $matches = $this->store->filterBy('telegram_bindings', function ($item) use ($type, $tgUserId) {
        return isset($item['type']) && $item['type'] === $type && isset($item['tg_user_id']) && (string) $item['tg_user_id'] === (string) $tgUserId;
    });
    return !empty($matches) ? $matches[0] : null;
}

protected function telegramBindEntity($type, $entityId, $tgUserId, $chatId, $username, $firstName)
{
    $existing = $this->telegramBindingRecord($type, $tgUserId);
    $payload = array(
        'type' => $type,
        'entity_id' => (string) $entityId,
        'tg_user_id' => (string) $tgUserId,
        'chat_id' => (string) $chatId,
        'tg_username' => (string) $username,
        'tg_first_name' => (string) $firstName,
        'status' => 'active',
    );
    if ($existing) {
        $this->store->update('telegram_bindings', $existing['id'], $payload);
        return $this->store->find('telegram_bindings', $existing['id']);
    }
    return $this->store->insert('telegram_bindings', $payload, 'tgb');
}

protected function telegramUnbindByUser($type, $tgUserId)
{
    $items = $this->store->filterBy('telegram_bindings', function ($item) use ($type, $tgUserId) {
        return isset($item['type']) && $item['type'] === $type && isset($item['tg_user_id']) && (string) $item['tg_user_id'] === (string) $tgUserId;
    });
    foreach ($items as $item) {
        $this->store->delete('telegram_bindings', $item['id']);
    }
}

protected function telegramFindResellerByTelegram($tgUserId)
{
    $binding = $this->telegramBindingRecord('reseller', $tgUserId);
    if ($binding && !empty($binding['entity_id'])) {
        $reseller = $this->store->find('resellers', $binding['entity_id']);
        if ($reseller && isset($reseller['status']) && $reseller['status'] === 'active') {
            return $reseller;
        }
    }
    $items = $this->store->filterBy('resellers', function ($item) use ($tgUserId) {
        return isset($item['telegram_user_id']) && (string) $item['telegram_user_id'] === (string) $tgUserId && isset($item['status']) && $item['status'] === 'active';
    });
    return !empty($items) ? $items[0] : null;
}

protected function telegramFindCustomerByTelegram($tgUserId)
{
    $binding = $this->telegramBindingRecord('customer', $tgUserId);
    if ($binding && !empty($binding['entity_id'])) {
        return $this->store->find('customers', $binding['entity_id']);
    }
    return null;
}

protected function telegramCustomerByCode($code)
{
    $code = trim((string) $code);
    if ($code === '') { return null; }
    $matches = $this->store->filterBy('customers', function ($item) use ($code) {
        return (isset($item['subscription_key']) && (string) $item['subscription_key'] === $code)
            || (isset($item['remote_sub_id']) && (string) $item['remote_sub_id'] === $code)
            || (isset($item['uuid']) && (string) $item['uuid'] === $code)
            || (isset($item['remote_email']) && (string) $item['remote_email'] === $code);
    });
    return !empty($matches) ? $matches[0] : null;
}

protected function resellerTelegramLinkToken($reseller, $force)
{
    $needsNew = $force || empty($reseller['telegram_link_token']) || empty($reseller['telegram_link_expires_at']) || strtotime($reseller['telegram_link_expires_at']) < time();
    if (!$needsNew) {
        return (string) $reseller['telegram_link_token'];
    }
    $token = panel_random_hex(20);
    $this->store->update('resellers', $reseller['id'], array(
        'telegram_link_token' => $token,
        'telegram_link_expires_at' => gmdate('c', time() + 7 * 86400),
    ));
    return $token;
}

protected function telegramFindResellerByLinkToken($token)
{
    $items = $this->store->filterBy('resellers', function ($item) use ($token) {
        return isset($item['telegram_link_token']) && (string) $item['telegram_link_token'] === (string) $token;
    });
    if (empty($items)) {
        return null;
    }
    $reseller = $items[0];
    if (!empty($reseller['telegram_link_expires_at']) && strtotime($reseller['telegram_link_expires_at']) < time()) {
        return null;
    }
    return $reseller;
}


protected function telegramNormalizeMenuCommand($text)
{
    $trimmed = trim((string) $text);
    $map = array(
        '🏠 Menu' => '/menu',
        '👥 Customers' => '/customers',
        '➕ Create' => '/create',
        '💳 Balance' => '/balance',
        '📢 Notices' => '/notices',
        '❓ Help' => '/help',
        '📊 Status' => '/status',
        '🔗 Subscriptions' => '/sub',
        '🔓 Unbind' => '/unbind',
        '🔗 Link Reseller' => '/link',
        '👤 Bind Client' => '/client',
    );
    return isset($map[$trimmed]) ? $map[$trimmed] : $trimmed;
}

protected function telegramReplyKeyboardReseller()
{
    return array(
        'keyboard' => array(
            array(array('text' => '👥 Customers'), array('text' => '➕ Create'), array('text' => '💳 Balance')),
            array(array('text' => '📢 Notices'), array('text' => '❓ Help'), array('text' => '🏠 Menu')),
        ),
        'resize_keyboard' => true,
        'is_persistent' => true,
        'input_field_placeholder' => 'Choose an action or type a command',
    );
}

protected function telegramReplyKeyboardClient()
{
    return array(
        'keyboard' => array(
            array(array('text' => '📊 Status'), array('text' => '🔗 Subscriptions')),
            array(array('text' => '🔓 Unbind'), array('text' => '❓ Help')),
        ),
        'resize_keyboard' => true,
        'is_persistent' => true,
        'input_field_placeholder' => 'Choose an action or type a command',
    );
}

protected function telegramReplyKeyboardGuest()
{
    return array(
        'keyboard' => array(
            array(array('text' => '🔗 Link Reseller'), array('text' => '👤 Bind Client')),
            array(array('text' => '❓ Help')),
        ),
        'resize_keyboard' => true,
        'is_persistent' => true,
        'input_field_placeholder' => 'Use a link or bind command',
    );
}

protected function telegramMainMenuText($reseller)
{
    return "✨ Telegram control is linked to your reseller account.

" . $this->telegramResellerSummaryText($reseller) . "

Use the buttons below or send /help for all commands.";
}

protected function telegramClientMenuText($customer)
{
    return "✅ This chat is linked to your account.

" . $this->telegramCustomerSummaryText($customer) . "

Use the buttons below to view status or subscription links.";
}

protected function telegramKeyboardCustomerActions($customer, $reseller)
{
    $buttons = array(
        array(
            array('text' => '🔄 Sync', 'callback_data' => 'rsync:' . $customer['id']),
            array('text' => '🔗 Subscriptions', 'callback_data' => 'rsub:' . $customer['id']),
        ),
        array(
            array('text' => '📋 Customers', 'callback_data' => 'rlist:1'),
            array('text' => '➕ Create', 'callback_data' => 'rcreate:1'),
        ),
    );
    if (!$reseller || empty($reseller['restrict'])) {
        $buttons[] = array(
            array('text' => ($customer['status'] === 'active' ? '⛔ Disable' : '✅ Enable'), 'callback_data' => 'rtoggle:' . $customer['id']),
            array('text' => '🗑 Delete', 'callback_data' => 'rdelete:' . $customer['id']),
        );
    }
    return array('inline_keyboard' => $buttons);
}

protected function telegramCustomerSummaryText($customer)
{
    $tpl = $this->store->find('templates', $customer['template_id']);
    $node = $tpl ? $this->store->find('nodes', $tpl['node_id']) : null;
    $lines = array();
    $lines[] = 'Customer: ' . $customer['display_name'];
    $lines[] = 'ID: ' . $customer['id'];
    $lines[] = 'Status: ' . $customer['status'];
    $lines[] = 'Type: ' . strtoupper($this->customerServerType($customer, $tpl, $node));
    $lines[] = 'Traffic: ' . panel_format_gb($customer['traffic_gb']) . ' GB';
    $lines[] = 'Used: ' . panel_format_gb(panel_to_gb_from_bytes(isset($customer['traffic_bytes_used']) ? $customer['traffic_bytes_used'] : 0)) . ' GB';
    $lines[] = 'Left: ' . panel_format_gb(panel_to_gb_from_bytes(isset($customer['traffic_bytes_left']) ? $customer['traffic_bytes_left'] : 0)) . ' GB';
    $lines[] = 'IP limit: ' . (isset($customer['ip_limit']) ? (int) $customer['ip_limit'] : 0);
    $lines[] = 'Expiration: ' . $this->customerExpirationLabel($customer);
    if ($tpl) { $lines[] = ($this->templateServerType($tpl, $node) === 'um' ? 'Profile: ' : 'Template: ') . $tpl['public_label']; }
    if ($node) { $lines[] = 'Server: ' . $node['title']; }
    return implode("\n", $lines);
}

protected function telegramResellerSummaryText($reseller)
{
    $lines = array();
    $lines[] = 'Reseller: ' . $reseller['display_name'];
    $lines[] = 'Credit: ' . panel_format_gb($reseller['credit_gb']) . ' GB';
    $lines[] = 'Max IP limit: ' . ($this->resellerMaxIpLimit($reseller) > 0 ? $this->resellerMaxIpLimit($reseller) : 'Unlimited');
    $lines[] = 'Max expiration days: ' . ($this->resellerMaxExpirationDays($reseller) > 0 ? $this->resellerMaxExpirationDays($reseller) : 'Unlimited');
    $lines[] = 'Restriction: ' . (!empty($reseller['restrict']) ? 'On' : 'Off');
    return implode("\n", $lines);
}

protected function telegramResolveResellerCustomer($reseller, $token)
{
    $token = trim((string) $token);
    $items = $this->store->filterBy('customers', function ($item) use ($reseller, $token) {
        if (!isset($item['reseller_id']) || $item['reseller_id'] !== $reseller['id']) {
            return false;
        }
        return (isset($item['id']) && (string) $item['id'] === $token)
            || (isset($item['system_name']) && (string) $item['system_name'] === $token)
            || (isset($item['display_name']) && strtolower((string) $item['display_name']) === strtolower($token))
            || (isset($item['subscription_key']) && (string) $item['subscription_key'] === $token);
    });
    return !empty($items) ? $items[0] : null;
}

protected function telegramCustomerEditPayload($customer, $changes)
{
    return array(
        'display_name' => isset($changes['display_name']) ? $changes['display_name'] : $customer['display_name'],
        'template_id' => isset($changes['template_id']) ? $changes['template_id'] : $customer['template_id'],
        'traffic_gb' => isset($changes['traffic_gb']) ? $changes['traffic_gb'] : $customer['traffic_gb'],
        'ip_limit' => isset($changes['ip_limit']) ? $changes['ip_limit'] : (isset($customer['ip_limit']) ? $customer['ip_limit'] : 0),
        'duration_days' => isset($changes['duration_days']) ? $changes['duration_days'] : (isset($customer['duration_days']) ? $customer['duration_days'] : 0),
        'duration_mode' => isset($changes['duration_mode']) ? $changes['duration_mode'] : $this->customerExpirationMode($customer),
        'status' => isset($changes['status']) ? $changes['status'] : (isset($customer['status']) ? $customer['status'] : 'active'),
        'notes' => isset($changes['notes']) ? $changes['notes'] : (isset($customer['notes']) ? $customer['notes'] : ''),
    );
}

protected function telegramRunCustomerEdit($reseller, $customer, $changes)
{
    $payload = $this->telegramCustomerEditPayload($customer, $changes);
    $oldPost = $_POST; $oldReq = $_REQUEST;
    $_POST = $payload; $_REQUEST = array_merge($_GET, $_POST);
    $result = $this->saveCustomerApi($reseller, $customer['id']);
    $_POST = $oldPost; $_REQUEST = $oldReq;
    return $result;
}

protected function telegramCommandHelpText($isReseller, $isClient)
{
    $lines = array();
    $lines[] = '✨ Available commands:';
    $lines[] = '/start or /menu - show the main menu';
    $lines[] = '/help - show this help';
    if ($isReseller) {
        $lines[] = '/balance - reseller balance and limits';
        $lines[] = '/customers [page] - list your newest customers';
        $lines[] = '/customer <id> - show one customer';
        $lines[] = '/create - guided customer creation';
        $lines[] = '/addtraffic <id> <gb> - add GB to a customer';
        $lines[] = '/settraffic <id> <total_gb> - set the total traffic';
        $lines[] = '/setip <id> <limit> - change IP limit';
        $lines[] = '/setdays <id> <days> [fixed|first_use] - change expiration';
        $lines[] = '/sync <id> - sync usage from 3x-ui';
        if (empty($isReseller['restrict'])) {
            $lines[] = '/toggle <id> - enable or disable the customer';
            $lines[] = '/delete <id> - delete the customer';
        }
        $lines[] = '/sub <id> - show subscription URLs';
        $lines[] = '/notices - show active reseller notices';
    } else {
        $lines[] = '/link <token> - link your reseller account to this Telegram user';
    }
    if ($isClient) {
        $lines[] = '/status - show your bound customer usage and expiry';
        $lines[] = '/sub - show your subscription URLs';
        $lines[] = '/unbind - unlink the customer from this Telegram chat';
    } else {
        $lines[] = '/client <subscription_key_or_uuid> - bind a customer to this Telegram chat';
    }
    $lines[] = '';
    $lines[] = 'Tip: you can use the menu buttons too.';
    return implode("
", $lines);
}

protected function telegramSendSubscriptionInfo($chatId, $customer)
{
    $tpl = $this->store->find('templates', $customer['template_id']);
    $node = $tpl ? $this->store->find('nodes', $tpl['node_id']) : null;
    $export = $this->appLink('/user/' . $customer['subscription_key'] . '/export');
    $lines = array();
    if ($this->customerServerType($customer, $tpl, $node) === 'um') {
        $lines[] = 'Access details for ' . $customer['display_name'] . ':';
        foreach ($this->buildUmAccessLines($customer, $tpl, $node) as $line) { $lines[] = $line; }
        $lines[] = 'Export: ' . $export;
    } else {
        $primary = $this->buildNodeSubscriptionUrl($node, !empty($customer['remote_sub_id']) ? $customer['remote_sub_id'] : $customer['subscription_key']);
        $fallback = $this->appLink('/user/' . $customer['subscription_key']);
        $lines[] = 'Subscription links for ' . $customer['display_name'] . ':';
        if ($primary !== '') { $lines[] = 'Primary: ' . $primary; }
        $lines[] = 'Fallback: ' . $fallback;
        $lines[] = 'Export: ' . $export;
        $configs = $this->buildSubscriptionConfigs($customer, $tpl, $node);
        if (!empty($configs)) {
            $lines[] = '';
            $lines[] = 'Configs:';
            foreach ($configs as $cfg) {
                $lines[] = $cfg;
            }
        }
    }
    return $this->telegramSendMessage($chatId, implode("\n", $lines), null, true);
}

protected function telegramHandleCreateConversation($chatId, $userId, $username, $firstName, $text, $reseller, $state)
{
    $data = isset($state['data']) && is_array($state['data']) ? $state['data'] : array();
    $step = isset($state['step']) ? $state['step'] : 'name';
    if ($step === 'name') {
        $data['display_name'] = trim((string) $text);
        if (strlen($data['display_name']) < 2) {
            return $this->telegramSendMessage($chatId, 'Please send a customer name with at least 2 characters.', null, true);
        }
        $templates = $this->resellerTemplates($reseller);
        if (empty($templates)) {
            $this->telegramStateClear($chatId);
            return $this->telegramSendMessage($chatId, 'No allowed templates are configured for this reseller.', null, true);
        }
        $list = array();
        foreach ($templates as $tpl) {
            $node = $this->store->find('nodes', $tpl['node_id']);
            $list[] = $tpl['id'] . ' = ' . $tpl['public_label'] . ' / ' . $tpl['inbound_name'] . ($node ? ' / ' . $node['title'] : '');
        }
        $this->telegramStateSet($chatId, $userId, 'create_customer', 'template', $data);
        return $this->telegramSendMessage($chatId, "Send the template ID for this customer:\n" . implode("\n", $list), null, true);
    }
    if ($step === 'template') {
        $tpl = $this->store->find('templates', trim((string) $text));
        if (!$tpl || !$this->resellerCanUseTemplate($reseller, $tpl['id'])) {
            return $this->telegramSendMessage($chatId, 'Template ID is invalid or not permitted for your reseller account.', null, true);
        }
        $data['template_id'] = $tpl['id'];
        $this->telegramStateSet($chatId, $userId, 'create_customer', 'traffic', $data);
        return $this->telegramSendMessage($chatId, 'Send total traffic in GB, for example: 5', null, true);
    }
    if ($step === 'traffic') {
        if (!is_numeric($text) || (float) $text <= 0) {
            return $this->telegramSendMessage($chatId, 'Traffic must be greater than 0 GB.', null, true);
        }
        $data['traffic_gb'] = round((float) $text, 2);
        $maxIp = $this->resellerMaxIpLimit($reseller);
        $hint = $maxIp > 0 ? ('Send IP limit between 1 and ' . $maxIp . '.') : 'Send IP limit. 0 means unlimited.';
        $this->telegramStateSet($chatId, $userId, 'create_customer', 'ip_limit', $data);
        return $this->telegramSendMessage($chatId, $hint, null, true);
    }
    if ($step === 'ip_limit') {
        if (!ctype_digit(trim((string) $text))) {
            return $this->telegramSendMessage($chatId, 'IP limit must be a whole number.', null, true);
        }
        $ip = (int) $text;
        $maxIp = $this->resellerMaxIpLimit($reseller);
        if ($maxIp > 0 && ($ip < 1 || $ip > $maxIp)) {
            return $this->telegramSendMessage($chatId, 'IP limit must be between 1 and ' . $maxIp . ' for this reseller.', null, true);
        }
        if ($maxIp <= 0 && $ip < 0) {
            return $this->telegramSendMessage($chatId, 'IP limit is invalid.', null, true);
        }
        $data['ip_limit'] = $ip;
        $maxDays = $this->resellerMaxExpirationDays($reseller);
        $hint = $maxDays > 0 ? ('Send expiration days between 1 and ' . $maxDays . '.') : 'Send expiration days. 0 means unlimited.';
        $this->telegramStateSet($chatId, $userId, 'create_customer', 'duration_days', $data);
        return $this->telegramSendMessage($chatId, $hint, null, true);
    }
    if ($step === 'duration_days') {
        if (!ctype_digit(trim((string) $text))) {
            return $this->telegramSendMessage($chatId, 'Expiration days must be a whole number.', null, true);
        }
        $days = (int) $text;
        $maxDays = $this->resellerMaxExpirationDays($reseller);
        if ($maxDays > 0 && ($days < 1 || $days > $maxDays)) {
            return $this->telegramSendMessage($chatId, 'Expiration days must be between 1 and ' . $maxDays . ' for this reseller.', null, true);
        }
        if ($maxDays <= 0 && $days < 0) {
            return $this->telegramSendMessage($chatId, 'Expiration days are invalid.', null, true);
        }
        $data['duration_days'] = $days;
        $this->telegramStateSet($chatId, $userId, 'create_customer', 'duration_mode', $data);
        return $this->telegramSendMessage($chatId, 'Send expiration mode: fixed or first_use', null, true);
    }
    if ($step === 'duration_mode') {
        $mode = strtolower(trim((string) $text));
        if (!in_array($mode, array('fixed', 'first_use'), true)) {
            return $this->telegramSendMessage($chatId, 'Expiration mode must be fixed or first_use.', null, true);
        }
        $data['duration_mode'] = $mode;
        $this->telegramStateClear($chatId);
        $oldPost = $_POST; $oldReq = $_REQUEST;
        $_POST = array(
            'display_name' => $data['display_name'],
            'phone' => isset($data['phone']) ? $data['phone'] : '',
            'access_pin' => isset($data['access_pin']) ? $data['access_pin'] : '',
            'template_id' => $data['template_id'],
            'traffic_gb' => $data['traffic_gb'],
            'ip_limit' => $data['ip_limit'],
            'duration_days' => $data['duration_days'],
            'duration_mode' => $data['duration_mode'],
            'status' => 'active',
            'notes' => 'Created via Telegram bot',
        );
        $_REQUEST = array_merge($_GET, $_POST);
        $result = $this->saveCustomerApi($reseller, null);
        $_POST = $oldPost; $_REQUEST = $oldReq;
        if (!empty($result['ok']) && !empty($result['customer'])) {
            $this->logResellerActivity($reseller['id'], 'telegram.customer.create', $this->store->find('customers', $result['customer']['id']), array('source' => 'telegram'));
            return $this->telegramSendMessage($chatId, 'Customer created.' . "\n\n" . $this->telegramCustomerSummaryText($this->store->find('customers', $result['customer']['id'])), $this->telegramKeyboardCustomerActions($this->store->find('customers', $result['customer']['id']), $reseller), true);
        }
        return $this->telegramSendMessage($chatId, 'Create failed: ' . (!empty($result['message']) ? $result['message'] : 'Unknown error.'), null, true);
    }
    $this->telegramStateClear($chatId);
    return $this->telegramSendMessage($chatId, 'The create flow was reset. Send /create to start again.', null, true);
}

protected function telegramSendCustomersPage($chatId, $reseller, $page)
{
    $items = $this->store->filterBy('customers', function ($item) use ($reseller) {
        return isset($item['reseller_id']) && $item['reseller_id'] === $reseller['id'];
    });
    usort($items, array($this, 'sortNewest'));
    $page = max(1, (int) $page);
    $perPage = 8;
    $total = count($items);
    $offset = ($page - 1) * $perPage;
    $slice = array_slice($items, $offset, $perPage);
    if (empty($slice)) {
        return $this->telegramSendMessage($chatId, 'No customers found on this page.', null, true);
    }
    $lines = array();
    $lines[] = 'Customers page ' . $page . ' / ' . max(1, ceil($total / $perPage));
    $keyboardRows = array();
    foreach ($slice as $item) {
        $lines[] = '- ' . $item['id'] . ' | ' . $item['display_name'] . ' | ' . panel_format_gb(panel_to_gb_from_bytes(isset($item['traffic_bytes_left']) ? $item['traffic_bytes_left'] : 0)) . ' GB left';
        $keyboardRows[] = array(array('text' => $item['display_name'], 'callback_data' => 'rcust:' . $item['id']));
    }
    $nav = array();
    if ($page > 1) { $nav[] = array('text' => '⬅️ Prev', 'callback_data' => 'rlist:' . ($page - 1)); }
    if ($offset + $perPage < $total) { $nav[] = array('text' => 'Next ➡️', 'callback_data' => 'rlist:' . ($page + 1)); }
    if (!empty($nav)) { $keyboardRows[] = $nav; }
    $keyboardRows[] = array(array('text' => '➕ Create customer', 'callback_data' => 'rcreate:1'));
    return $this->telegramSendMessage($chatId, implode("\n", $lines), array('inline_keyboard' => $keyboardRows), true);
}

protected function telegramProcessResellerCommand($chatId, $userId, $username, $firstName, $text, $reseller, $state)
{
    $trimmed = $this->telegramNormalizeMenuCommand($text);
    if ($state && isset($state['flow']) && $state['flow'] === 'create_customer' && $trimmed !== '' && substr($trimmed, 0, 1) !== '/') {
        return $this->telegramHandleCreateConversation($chatId, $userId, $username, $firstName, $trimmed, $reseller, $state);
    }
    $parts = preg_split('/\s+/', $trimmed);
    $cmd = strtolower(isset($parts[0]) ? $parts[0] : '');
    $arg1 = isset($parts[1]) ? $parts[1] : '';
    $arg2 = isset($parts[2]) ? $parts[2] : '';
    $arg3 = isset($parts[3]) ? $parts[3] : '';
    if ($cmd === '/start' || $cmd === '/menu') {
        return $this->telegramSendMessage($chatId, $this->telegramMainMenuText($reseller), $this->telegramReplyKeyboardReseller(), true);
    }
    if ($cmd === '/help') {
        return $this->telegramSendMessage($chatId, $this->telegramCommandHelpText($reseller, false), $this->telegramReplyKeyboardReseller(), true);
    }
    if ($cmd === '/balance') {
        return $this->telegramSendMessage($chatId, "💳 Reseller balance and limits

" . $this->telegramResellerSummaryText($reseller), $this->telegramReplyKeyboardReseller(), true);
    }
    if ($cmd === '/customers') {
        return $this->telegramSendCustomersPage($chatId, $reseller, $arg1 !== '' ? (int) $arg1 : 1);
    }
    if ($cmd === '/customer') {
        $customer = $this->telegramResolveResellerCustomer($reseller, $arg1);
        if (!$customer) { return $this->telegramSendMessage($chatId, 'Customer not found for this reseller.', $this->telegramReplyKeyboardReseller(), true); }
        return $this->telegramSendMessage($chatId, $this->telegramCustomerSummaryText($customer), $this->telegramKeyboardCustomerActions($customer, $reseller), true);
    }
    if ($cmd === '/create') {
        $this->telegramStateSet($chatId, $userId, 'create_customer', 'name', array());
        return $this->telegramSendMessage($chatId, '➕ Send the new customer name.', $this->telegramReplyKeyboardReseller(), true);
    }
    if ($cmd === '/sync') {
        $customer = $this->telegramResolveResellerCustomer($reseller, $arg1);
        if (!$customer) { return $this->telegramSendMessage($chatId, 'Customer not found.', $this->telegramReplyKeyboardReseller(), true); }
        $result = $this->syncCustomerApi($reseller, $customer['id']);
        if (!empty($result['ok']) && !empty($result['customer'])) {
            $fresh = $this->store->find('customers', $customer['id']);
            return $this->telegramSendMessage($chatId, '🔄 Usage synced.' . "

" . $this->telegramCustomerSummaryText($fresh), $this->telegramKeyboardCustomerActions($fresh, $reseller), true);
        }
        return $this->telegramSendMessage($chatId, 'Sync failed: ' . $result['message'], $this->telegramReplyKeyboardReseller(), true);
    }
    if ($cmd === '/sub') {
        $customer = $arg1 !== '' ? $this->telegramResolveResellerCustomer($reseller, $arg1) : null;
        if (!$customer) { return $this->telegramSendMessage($chatId, 'Send /sub <customer_id> to view subscription links.', $this->telegramReplyKeyboardReseller(), true); }
        return $this->telegramSendSubscriptionInfo($chatId, $customer);
    }
    if ($cmd === '/toggle') {
        $customer = $this->telegramResolveResellerCustomer($reseller, $arg1);
        if (!$customer) { return $this->telegramSendMessage($chatId, 'Customer not found.', $this->telegramReplyKeyboardReseller(), true); }
        $result = $this->toggleCustomerApi($reseller, $customer['id']);
        return $this->telegramSendMessage($chatId, !empty($result['ok']) ? ('✅ Status updated.

' . $this->telegramCustomerSummaryText($this->store->find('customers', $customer['id']))) : ('Toggle failed: ' . $result['message']), $this->telegramReplyKeyboardReseller(), true);
    }
    if ($cmd === '/delete') {
        $customer = $this->telegramResolveResellerCustomer($reseller, $arg1);
        if (!$customer) { return $this->telegramSendMessage($chatId, 'Customer not found.', $this->telegramReplyKeyboardReseller(), true); }
        $result = $this->deleteCustomerApi($reseller, $customer['id']);
        return $this->telegramSendMessage($chatId, !empty($result['ok']) ? '🗑 Customer deleted.' : ('Delete failed: ' . $result['message']), $this->telegramReplyKeyboardReseller(), true);
    }
    if ($cmd === '/addtraffic') {
        $customer = $this->telegramResolveResellerCustomer($reseller, $arg1);
        if (!$customer || !is_numeric($arg2) || (float) $arg2 <= 0) { return $this->telegramSendMessage($chatId, 'Usage: /addtraffic <customer_id> <gb>', $this->telegramReplyKeyboardReseller(), true); }
        $result = $this->telegramRunCustomerEdit($reseller, $customer, array('traffic_gb' => round((float) $customer['traffic_gb'] + (float) $arg2, 2)));
        return $this->telegramSendMessage($chatId, !empty($result['ok']) ? ('✅ Traffic updated.

' . $this->telegramCustomerSummaryText($this->store->find('customers', $customer['id']))) : ('Update failed: ' . $result['message']), $this->telegramReplyKeyboardReseller(), true);
    }
    if ($cmd === '/settraffic') {
        $customer = $this->telegramResolveResellerCustomer($reseller, $arg1);
        if (!$customer || !is_numeric($arg2) || (float) $arg2 <= 0) { return $this->telegramSendMessage($chatId, 'Usage: /settraffic <customer_id> <total_gb>', $this->telegramReplyKeyboardReseller(), true); }
        $result = $this->telegramRunCustomerEdit($reseller, $customer, array('traffic_gb' => round((float) $arg2, 2)));
        return $this->telegramSendMessage($chatId, !empty($result['ok']) ? ('✅ Traffic updated.

' . $this->telegramCustomerSummaryText($this->store->find('customers', $customer['id']))) : ('Update failed: ' . $result['message']), $this->telegramReplyKeyboardReseller(), true);
    }
    if ($cmd === '/setip') {
        $customer = $this->telegramResolveResellerCustomer($reseller, $arg1);
        if (!$customer || !ctype_digit((string) $arg2)) { return $this->telegramSendMessage($chatId, 'Usage: /setip <customer_id> <limit>', $this->telegramReplyKeyboardReseller(), true); }
        $result = $this->telegramRunCustomerEdit($reseller, $customer, array('ip_limit' => (int) $arg2));
        return $this->telegramSendMessage($chatId, !empty($result['ok']) ? ('✅ IP limit updated.

' . $this->telegramCustomerSummaryText($this->store->find('customers', $customer['id']))) : ('Update failed: ' . $result['message']), $this->telegramReplyKeyboardReseller(), true);
    }
    if ($cmd === '/setdays') {
        $customer = $this->telegramResolveResellerCustomer($reseller, $arg1);
        if (!$customer || !ctype_digit((string) $arg2)) { return $this->telegramSendMessage($chatId, 'Usage: /setdays <customer_id> <days> [fixed|first_use]', $this->telegramReplyKeyboardReseller(), true); }
        $mode = $arg3 !== '' ? strtolower($arg3) : $this->customerExpirationMode($customer);
        if (!in_array($mode, array('fixed', 'first_use'), true)) { $mode = $this->customerExpirationMode($customer); }
        $result = $this->telegramRunCustomerEdit($reseller, $customer, array('duration_days' => (int) $arg2, 'duration_mode' => $mode));
        return $this->telegramSendMessage($chatId, !empty($result['ok']) ? ('✅ Expiration updated.

' . $this->telegramCustomerSummaryText($this->store->find('customers', $customer['id']))) : ('Update failed: ' . $result['message']), $this->telegramReplyKeyboardReseller(), true);
    }
    if ($cmd === '/notices') {
        $notices = $this->activeNotices('reseller');
        if (empty($notices)) { return $this->telegramSendMessage($chatId, '📢 No active reseller notices.', $this->telegramReplyKeyboardReseller(), true); }
        $lines = array('📢 Active notices:');
        foreach ($notices as $n) { $lines[] = '• ' . $n['title'] . ': ' . preg_replace('/\s+/', ' ', trim((string) $n['body'])); }
        return $this->telegramSendMessage($chatId, implode("
", $lines), $this->telegramReplyKeyboardReseller(), true);
    }
    return $this->telegramSendMessage($chatId, $this->telegramCommandHelpText($reseller, false), $this->telegramReplyKeyboardReseller(), true);
}

protected function telegramProcessClientCommand($chatId, $userId, $username, $firstName, $text, $customer)
{
    $trimmed = $this->telegramNormalizeMenuCommand($text);
    $parts = preg_split('/\s+/', $trimmed);
    $cmd = strtolower(isset($parts[0]) ? $parts[0] : '');
    $arg1 = isset($parts[1]) ? $parts[1] : '';
    if ($cmd === '/start' || $cmd === '/menu') {
        return $this->telegramSendMessage($chatId, $this->telegramClientMenuText($customer), $this->telegramReplyKeyboardClient(), true);
    }
    if ($cmd === '/status') {
        return $this->telegramSendMessage($chatId, "📊 Account status

" . $this->telegramCustomerSummaryText($customer), $this->telegramReplyKeyboardClient(), true);
    }
    if ($cmd === '/help') {
        return $this->telegramSendMessage($chatId, $this->telegramCommandHelpText(false, true), $this->telegramReplyKeyboardClient(), true);
    }
    if ($cmd === '/sub') {
        return $this->telegramSendSubscriptionInfo($chatId, $customer);
    }
    if ($cmd === '/unbind') {
        $this->telegramUnbindByUser('customer', $userId);
        return $this->telegramSendMessage($chatId, '🔓 Customer binding removed from this Telegram user.', $this->telegramReplyKeyboardGuest(), true);
    }
    if ($cmd === '/client' && $arg1 !== '') {
        $found = $this->telegramCustomerByCode($arg1);
        if (!$found) { return $this->telegramSendMessage($chatId, 'Customer code was not found.', $this->telegramReplyKeyboardGuest(), true); }
        $this->telegramBindEntity('customer', $found['id'], $userId, $chatId, $username, $firstName);
        return $this->telegramSendMessage($chatId, '✅ This chat is now linked to ' . $found['display_name'] . ".

" . $this->telegramCustomerSummaryText($found), $this->telegramReplyKeyboardClient(), true);
    }
    return $this->telegramSendMessage($chatId, $this->telegramCommandHelpText(false, true), $this->telegramReplyKeyboardClient(), true);
}

protected function telegramHandleCallback($callback)
{
    $settings = $this->telegramSettings();
    if (empty($settings['enabled'])) { return; }
    $data = isset($callback['data']) ? (string) $callback['data'] : '';
    $from = isset($callback['from']) ? (array) $callback['from'] : array();
    $chatId = panel_array_get($callback, 'message.chat.id', '');
    $messageId = panel_array_get($callback, 'message.message_id', 0);
    $userId = isset($from['id']) ? (string) $from['id'] : '';
    $reseller = $this->telegramFindResellerByTelegram($userId);
    if (!$reseller) {
        $this->telegramAnswerCallback(isset($callback['id']) ? $callback['id'] : '', 'Not linked to a reseller account.');
        return;
    }
    if (strpos($data, 'rmenu:') === 0) {
        $action = substr($data, 6);
        $this->telegramAnswerCallback(isset($callback['id']) ? $callback['id'] : '', 'Opening...');
        if ($action === 'home') {
            $this->telegramEditMessage($chatId, $messageId, $this->telegramMainMenuText($reseller), array('inline_keyboard' => array(
                array(array('text' => '👥 Customers', 'callback_data' => 'rlist:1'), array('text' => '➕ Create', 'callback_data' => 'rcreate:1')),
                array(array('text' => '💳 Balance', 'callback_data' => 'rmenu:balance'), array('text' => '📢 Notices', 'callback_data' => 'rmenu:notices')),
            )));
            return;
        }
        if ($action === 'balance') {
            $this->telegramEditMessage($chatId, $messageId, "💳 Reseller balance and limits

" . $this->telegramResellerSummaryText($reseller), array('inline_keyboard' => array(array(array('text' => '🏠 Menu', 'callback_data' => 'rmenu:home'), array('text' => '👥 Customers', 'callback_data' => 'rlist:1')))));
            return;
        }
        if ($action === 'notices') {
            $notices = $this->activeNotices('reseller');
            $lines = array('📢 Active reseller notices');
            if (empty($notices)) { $lines[] = 'No active reseller notices.'; }
            else { foreach ($notices as $n) { $lines[] = '• ' . $n['title'] . ': ' . preg_replace('/\s+/', ' ', trim((string) $n['body'])); } }
            $this->telegramEditMessage($chatId, $messageId, implode("
", $lines), array('inline_keyboard' => array(array(array('text' => '🏠 Menu', 'callback_data' => 'rmenu:home'), array('text' => '👥 Customers', 'callback_data' => 'rlist:1')))));
            return;
        }
        return;
    }
    if (strpos($data, 'rlist:') === 0) {
        $page = (int) substr($data, 6);
        $this->telegramAnswerCallback(isset($callback['id']) ? $callback['id'] : '', 'Loading list...');
        $this->telegramSendCustomersPage($chatId, $reseller, $page > 0 ? $page : 1);
        return;
    }
    if (strpos($data, 'rcust:') === 0) {
        $customer = $this->telegramResolveResellerCustomer($reseller, substr($data, 6));
        $this->telegramAnswerCallback(isset($callback['id']) ? $callback['id'] : '', '');
        if ($customer) {
            $this->telegramEditMessage($chatId, $messageId, $this->telegramCustomerSummaryText($customer), $this->telegramKeyboardCustomerActions($customer, $reseller));
        }
        return;
    }
    if (strpos($data, 'rsync:') === 0) {
        $customer = $this->telegramResolveResellerCustomer($reseller, substr($data, 6));
        $this->telegramAnswerCallback(isset($callback['id']) ? $callback['id'] : '', 'Syncing...');
        if ($customer) {
            $this->syncCustomerApi($reseller, $customer['id']);
            $fresh = $this->store->find('customers', $customer['id']);
            $this->telegramEditMessage($chatId, $messageId, $this->telegramCustomerSummaryText($fresh), $this->telegramKeyboardCustomerActions($fresh, $reseller));
        }
        return;
    }
    if (strpos($data, 'rsub:') === 0) {
        $customer = $this->telegramResolveResellerCustomer($reseller, substr($data, 5));
        $this->telegramAnswerCallback(isset($callback['id']) ? $callback['id'] : '', '');
        if ($customer) { $this->telegramSendSubscriptionInfo($chatId, $customer); }
        return;
    }
    if (strpos($data, 'rtoggle:') === 0) {
        $customer = $this->telegramResolveResellerCustomer($reseller, substr($data, 8));
        $this->telegramAnswerCallback(isset($callback['id']) ? $callback['id'] : '', 'Updating...');
        if ($customer) {
            $this->toggleCustomerApi($reseller, $customer['id']);
            $fresh = $this->store->find('customers', $customer['id']);
            if ($fresh) {
                $this->telegramEditMessage($chatId, $messageId, $this->telegramCustomerSummaryText($fresh), $this->telegramKeyboardCustomerActions($fresh, $reseller));
            }
        }
        return;
    }
    if (strpos($data, 'rdelete:') === 0) {
        $customer = $this->telegramResolveResellerCustomer($reseller, substr($data, 8));
        $this->telegramAnswerCallback(isset($callback['id']) ? $callback['id'] : '', 'Deleting...');
        if ($customer) {
            $result = $this->deleteCustomerApi($reseller, $customer['id']);
            $this->telegramEditMessage($chatId, $messageId, !empty($result['ok']) ? 'Customer deleted.' : ('Delete failed: ' . $result['message']), null);
        }
        return;
    }
    if (strpos($data, 'rcreate:') === 0) {
        $this->telegramAnswerCallback(isset($callback['id']) ? $callback['id'] : '', '');
        $this->telegramStateSet($chatId, $userId, 'create_customer', 'name', array());
        $this->telegramSendMessage($chatId, '➕ Send the new customer name.', $this->telegramReplyKeyboardReseller(), true);
        return;
    }
    $this->telegramAnswerCallback(isset($callback['id']) ? $callback['id'] : '', 'Unknown action.');
}

protected function telegramHandleMessage($message)
{
    $settings = $this->telegramSettings();
    if (empty($settings['enabled'])) { return; }
    $chatId = panel_array_get($message, 'chat.id', '');
    $from = isset($message['from']) ? (array) $message['from'] : array();
    $userId = isset($from['id']) ? (string) $from['id'] : '';
    $username = isset($from['username']) ? (string) $from['username'] : '';
    $firstName = isset($from['first_name']) ? (string) $from['first_name'] : '';
    $text = isset($message['text']) ? (string) $message['text'] : '';
    if ($chatId === '' || $userId === '' || $text === '') { return; }
    $state = $this->telegramStateRecord($chatId);
    if (preg_match('/^\/link\s+([a-zA-Z0-9]+)$/i', $text, $m)) {
        if (empty($settings['allow_reseller'])) {
            return $this->telegramSendMessage($chatId, 'Reseller bot access is disabled.', null, true);
        }
        $reseller = $this->telegramFindResellerByLinkToken($m[1]);
        if (!$reseller) {
            return $this->telegramSendMessage($chatId, 'This link token is invalid or expired.', null, true);
        }
        $this->store->update('resellers', $reseller['id'], array('telegram_user_id' => (string) $userId, 'telegram_chat_id' => (string) $chatId, 'telegram_username' => $username, 'telegram_link_token' => '', 'telegram_link_expires_at' => ''));
        $this->telegramBindEntity('reseller', $reseller['id'], $userId, $chatId, $username, $firstName);
        return $this->telegramSendMessage($chatId, 'Telegram is now linked to reseller ' . $reseller['display_name'] . ".\n\n" . $this->telegramResellerSummaryText($reseller), null, true);
    }
    if (preg_match('/^\/(client|bind)\s+(.+)$/i', $text, $m)) {
        if (empty($settings['allow_client'])) {
            return $this->telegramSendMessage($chatId, 'Client bot access is disabled.', null, true);
        }
        $customer = $this->telegramCustomerByCode(trim((string) $m[2]));
        if (!$customer) {
            return $this->telegramSendMessage($chatId, 'Customer subscription ID, sub ID, UUID, or email was not found.', null, true);
        }
        $this->telegramBindEntity('customer', $customer['id'], $userId, $chatId, $username, $firstName);
        return $this->telegramSendMessage($chatId, 'This chat is now linked to ' . $customer['display_name'] . ".\n\n" . $this->telegramCustomerSummaryText($customer), null, true);
    }
    $reseller = $this->telegramFindResellerByTelegram($userId);
    if ($reseller && !empty($settings['allow_reseller'])) {
        return $this->telegramProcessResellerCommand($chatId, $userId, $username, $firstName, $text, $reseller, $state);
    }
    $customer = $this->telegramFindCustomerByTelegram($userId);
    if ($customer && !empty($settings['allow_client'])) {
        return $this->telegramProcessClientCommand($chatId, $userId, $username, $firstName, $text, $customer);
    }
    return $this->telegramSendMessage($chatId, "👋 Welcome.\nUse /link <token> to link a reseller account, or /client <subscription_key_or_uuid> to link a customer.\n\nYou can also use the quick buttons below.", $this->telegramReplyKeyboardGuest(), true);
}

protected function telegramProcessUpdate($update)
{
    if (!is_array($update)) { return; }
    if (isset($update['callback_query']) && is_array($update['callback_query'])) {
        $this->telegramHandleCallback($update['callback_query']);
        return;
    }
    if (isset($update['message']) && is_array($update['message'])) {
        $this->telegramHandleMessage($update['message']);
        return;
    }
}

protected function telegramWebhook($secret)
{
    $settings = $this->telegramSettings();
    if ((string) $secret !== (string) $settings['webhook_secret']) {
        $this->abort(403, 'Forbidden');
    }
    if ($this->requestMethod === 'GET') {
        $this->sendCommonHeaders('text/plain; charset=utf-8');
        echo 'telegram webhook ready';
        exit;
    }
    $body = file_get_contents('php://input');
    $decoded = json_decode((string) $body, true);
    if (!is_array($decoded)) {
        $this->logTelegramEvent('error', 'Telegram webhook received invalid JSON.', array('ip' => $this->clientIp()));
    } else {
        $this->logTelegramEvent('access', 'Telegram webhook update received.', array('ip' => $this->clientIp(), 'update_id' => panel_array_get($decoded, 'update_id', '')));
    }
    $this->telegramProcessUpdate(is_array($decoded) ? $decoded : array());
    $this->sendCommonHeaders('application/json; charset=utf-8');
    echo json_encode(array('ok' => true));
    exit;
}

protected function telegramPollRun($timeoutSeconds)
{
    $settings = $this->telegramSettings();
    $timeoutSeconds = max(0, min(55, (int) $timeoutSeconds));
    $payload = array('offset' => $settings['update_offset'], 'timeout' => $timeoutSeconds, 'limit' => $settings['poll_limit']);
    $result = $this->telegramApiRequest('getUpdates', $payload);
    if (empty($result['ok'])) {
        return $result;
    }
    $updates = is_array($result['result']) ? $result['result'] : array();
    $max = $settings['update_offset'];
    foreach ($updates as $update) {
        if (isset($update['update_id'])) {
            $max = max($max, (int) $update['update_id'] + 1);
        }
        $this->telegramProcessUpdate($update);
    }
    $cfg = $this->runtimeConfig();
    $cfg['telegram_update_offset'] = $max;
    $this->store->writeConfig('app', $cfg);
    $this->logTelegramEvent('access', 'Telegram polling cycle completed.', array('count' => count($updates), 'next_offset' => $max));
    return array('ok' => true, 'message' => 'Processed ' . count($updates) . ' update(s).', 'count' => count($updates));
}

protected function telegramPollEndpoint($secret)
{
    $settings = $this->telegramSettings();
    if ((string) $secret !== (string) $settings['webhook_secret']) {
        $this->abort(403, 'Forbidden');
    }
    $timeout = isset($_GET['timeout']) ? (int) $_GET['timeout'] : 0;
    $result = $this->telegramPollRun($timeout);
    $this->sendCommonHeaders('application/json; charset=utf-8');
    echo json_encode($result);
    exit;
}

protected function adminTelegramPoll()
{
    $result = $this->telegramPollRun(0);
    $this->flash(!empty($result['ok']) ? 'success' : 'error', isset($result['message']) ? $result['message'] : 'Telegram poll failed.');
    $this->redirect('/admin/settings');
}

protected function adminTelegramSetWebhook()
{
    $result = $this->telegramApiRequest('setWebhook', array('url' => $this->telegramWebhookUrl()));
    $this->flash(!empty($result['ok']) ? 'success' : 'error', !empty($result['ok']) ? 'Telegram webhook configured.' : ('Telegram webhook setup failed: ' . $result['message']));
    $this->redirect('/admin/settings');
}

protected function adminTelegramDeleteWebhook()
{
    $result = $this->telegramApiRequest('deleteWebhook', array('drop_pending_updates' => false));
    $this->flash(!empty($result['ok']) ? 'success' : 'error', !empty($result['ok']) ? 'Telegram webhook removed.' : ('Telegram webhook delete failed: ' . $result['message']));
    $this->redirect('/admin/settings');
}



protected function panelSyncExportEndpoint($secret = '')
{
    $settings = $this->panelSyncSettings();
    $authMode = trim((string) $secret) !== '' ? 'url' : 'header';
    if (empty($settings['enabled']) || $settings['mode'] !== 'master' || !$this->panelSyncSecretMatches($secret)) {
        $this->logSyncEvent('error', 'Panel sync export request rejected.', array('ip' => $this->clientIp(), 'auth_mode' => $authMode));
        return $this->json(array('ok' => false, 'message' => 'Forbidden.'), 403);
    }
    $this->logSyncEvent('access', 'Panel sync export payload generated.', array('ip' => $this->clientIp(), 'auth_mode' => $authMode));
    return $this->json(array(
        'ok' => true,
        'generated_at' => panel_now(),
        'source' => $this->appName(),
        'collections' => $this->buildPanelSyncExportCollections(),
    ), 200);
}

protected function panelSyncRunEndpoint($secret = '')
{
    $authMode = trim((string) $secret) !== '' ? 'url' : 'header';
    if (!$this->panelSyncSecretMatches($secret)) {
        $this->logSyncEvent('error', 'Panel sync run request rejected.', array('ip' => $this->clientIp(), 'auth_mode' => $authMode));
        return $this->json(array('ok' => false, 'message' => 'Forbidden.'), 403);
    }
    $result = $this->performRemotePanelSync(false);
    return $this->json($result, !empty($result['ok']) ? 200 : 500);
}

protected function adminRunPanelSync()
{
    $result = $this->performRemotePanelSync(true);
    $this->flash(!empty($result['ok']) ? 'success' : 'error', isset($result['message']) ? $result['message'] : 'Panel sync failed.');
    $this->redirect('/admin/settings');
}

protected function buildPanelSyncExportCollections()
{
    $collections = array(
        'nodes' => array(),
        'templates' => array(),
        'resellers' => array(),
        'customers' => array(),
        'customer_links' => array(),
    );
    foreach ($this->store->all('nodes') as $row) {
        $row['panel_password_plain'] = isset($row['panel_password']) ? $this->decrypt($row['panel_password']) : '';
        $row['xui_api_token_plain'] = isset($row['xui_api_token']) ? $this->decrypt($row['xui_api_token']) : '';
        $row['xui_proxy_password_plain'] = isset($row['xui_proxy_password']) ? $this->decrypt($row['xui_proxy_password']) : '';
        unset($row['panel_password']);
        unset($row['xui_api_token']);
        unset($row['xui_proxy_password']);
        $collections['nodes'][] = $row;
    }
    foreach (array('templates', 'resellers', 'customers', 'customer_links') as $collection) {
        foreach ($this->store->all($collection) as $row) {
            if ($collection === 'customers' && !empty($row['service_password_enc'])) {
                $row['service_password_plain'] = $this->decrypt($row['service_password_enc']);
                unset($row['service_password_enc']);
            }
            $collections[$collection][] = $row;
        }
    }
    return $collections;
}

protected function performRemotePanelSync($force)
{
    $settings = $this->panelSyncSettings();
    if (empty($settings['enabled']) || $settings['mode'] !== 'slave') {
        return array('ok' => false, 'message' => 'Panel sync is not enabled in slave mode.');
    }
    if ($settings['master_url'] === '') {
        return array('ok' => false, 'message' => 'Master panel URL is empty.');
    }
    $state = $this->panelSyncState();
    $lastRun = !empty($state['last_run_at']) ? strtotime($state['last_run_at']) : 0;
    $interval = max(60, (int) $settings['interval_seconds']);
    if (!$force && $lastRun > 0 && (time() - $lastRun) < $interval) {
        $nextDue = gmdate('c', $lastRun + $interval);
        $state['next_due_at'] = $nextDue;
        $this->writePanelSyncState($state);
        return array('ok' => true, 'skipped' => true, 'message' => 'Skipped. Next sync is due at ' . $nextDue . '.', 'next_due_at' => $nextDue);
    }

    $lockFile = $this->storage . '/locks/panel_sync.lock';
    $lock = @fopen($lockFile, 'c+');
    if (!$lock) {
        return array('ok' => false, 'message' => 'Could not open panel sync lock file.');
    }
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        return array('ok' => true, 'skipped' => true, 'message' => 'Panel sync is already running.');
    }

    $result = $this->fetchRemotePanelSyncPayload($settings);
    if (!$result['ok']) {
        flock($lock, LOCK_UN);
        fclose($lock);
        $state['last_run_at'] = panel_now();
        $state['last_status'] = 'error';
        $state['last_message'] = $result['message'];
        $state['next_due_at'] = gmdate('c', time() + $interval);
        $this->writePanelSyncState($state);
        $this->logSyncEvent('error', 'Panel sync fetch failed.', array('master_url' => $settings['master_url'], 'message' => $result['message']));
        return $result;
    }

    $merge = $this->mergeRemotePanelSyncCollections(isset($result['data']['collections']) ? $result['data']['collections'] : array(), $settings);
    flock($lock, LOCK_UN);
    fclose($lock);

    $state['last_run_at'] = panel_now();
    $state['last_status'] = !empty($merge['ok']) ? 'success' : 'error';
    $state['last_message'] = isset($merge['message']) ? $merge['message'] : 'Panel sync completed.';
    $state['last_counts'] = isset($merge['counts']) ? $merge['counts'] : array();
    $state['next_due_at'] = gmdate('c', time() + $interval);
    $this->writePanelSyncState($state);
    if (!empty($merge['ok'])) {
        $this->log('panel_sync.completed', array('master_url' => $settings['master_url'], 'counts' => $state['last_counts']));
        $this->logSyncEvent('access', 'Panel sync completed successfully.', array('master_url' => $settings['master_url'], 'counts' => $state['last_counts']));
    } else {
        $this->logSyncEvent('error', 'Panel sync merge failed.', array('master_url' => $settings['master_url'], 'message' => isset($merge['message']) ? $merge['message'] : 'Panel sync failed.'));
    }
    return $merge;
}

protected function fetchRemotePanelSyncPayload($settings)
{
    $url = rtrim((string) $settings['master_url'], '/') . '/sync/export';
    $secret = isset($settings['shared_secret']) ? (string) $settings['shared_secret'] : '';
    if ($secret === '') {
        return array('ok' => false, 'message' => 'Panel sync shared secret is empty.');
    }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 90);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json', 'User-Agent: XUI-PanelSync/1.0', 'X-Panel-Sync-Secret: ' . $secret, 'Authorization: Bearer ' . $secret));
        $this->panelSyncProxyCurlOptions($ch, $settings);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $body === '' || $err !== '') {
            return array('ok' => false, 'message' => 'Could not fetch master sync payload. ' . ($err !== '' ? $err : 'Empty response.'));
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return array('ok' => false, 'message' => 'Master sync response is not valid JSON. HTTP ' . $code . '.');
        }
        if (empty($decoded['ok'])) {
            return array('ok' => false, 'message' => isset($decoded['message']) ? $decoded['message'] : 'Master sync rejected the request.');
        }
        return array('ok' => true, 'data' => $decoded);
    }

    $opts = array('http' => array('method' => 'GET', 'timeout' => 90, 'header' => "Accept: application/json
User-Agent: XUI-PanelSync/1.0
X-Panel-Sync-Secret: " . $secret . "
Authorization: Bearer " . $secret . "
"));
    if (!empty($settings['proxy_enabled']) && !empty($settings['proxy_host']) && !empty($settings['proxy_port']) && in_array($settings['proxy_type'], array('http', 'https'), true)) {
        $opts['http']['proxy'] = 'tcp://' . $settings['proxy_host'] . ':' . (int) $settings['proxy_port'];
        $opts['http']['request_fulluri'] = true;
    }
    $context = stream_context_create($opts);
    $body = @file_get_contents($url, false, $context);
    if ($body === false || $body === '') {
        return array('ok' => false, 'message' => 'Could not fetch master sync payload.');
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return array('ok' => false, 'message' => 'Master sync response is not valid JSON.');
    }
    if (empty($decoded['ok'])) {
        return array('ok' => false, 'message' => isset($decoded['message']) ? $decoded['message'] : 'Master sync rejected the request.');
    }
    return array('ok' => true, 'data' => $decoded);
}

protected function panelSyncProxyCurlOptions(&$ch, $settings)
{
    if (empty($settings['proxy_enabled']) || empty($settings['proxy_host']) || empty($settings['proxy_port'])) {
        return;
    }
    curl_setopt($ch, CURLOPT_PROXY, $settings['proxy_host']);
    curl_setopt($ch, CURLOPT_PROXYPORT, (int) $settings['proxy_port']);
    $ptype = strtolower((string) $settings['proxy_type']);
    if ($ptype === 'socks5') {
        if (defined('CURLPROXY_SOCKS5_HOSTNAME')) {
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
        } elseif (defined('CURLPROXY_SOCKS5')) {
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
        }
    } else {
        if (defined('CURLPROXY_HTTP')) {
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
        }
    }
    if (!empty($settings['proxy_username'])) {
        curl_setopt($ch, CURLOPT_PROXYUSERPWD, $settings['proxy_username'] . ':' . $settings['proxy_password']);
    }
}

protected function mergeRemotePanelSyncCollections($collections, $settings)
{
    $counts = array('nodes' => 0, 'templates' => 0, 'resellers' => 0, 'customers' => 0, 'customer_links' => 0, 'deleted' => 0);
    $allowed = array('nodes', 'templates', 'resellers', 'customers', 'customer_links');
    foreach ($allowed as $collection) {
        $seen = array();
        $rows = isset($collections[$collection]) && is_array($collections[$collection]) ? $collections[$collection] : array();
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['id'])) { continue; }
            $id = $this->sanitizeIdentifier($row['id'], 80);
            if ($id === '') { continue; }
            $prepared = $this->preparePanelSyncRecord($collection, $row, $settings);
            if (!$prepared) { continue; }
            $this->store->write($collection, $id, $prepared);
            $seen[$id] = 1;
            $counts[$collection]++;
        }
        if (!empty($settings['prune_missing'])) {
            foreach ($this->store->all($collection) as $local) {
                if (empty($local['synced_from_panel']) || $local['synced_from_panel'] !== 'master') { continue; }
                if (!isset($seen[$local['id']])) {
                    $this->store->delete($collection, $local['id']);
                    $counts['deleted']++;
                }
            }
        }
    }
    return array('ok' => true, 'message' => 'Panel sync completed successfully.', 'counts' => $counts);
}

protected function preparePanelSyncRecord($collection, $row, $settings)
{
    if (!is_array($row) || empty($row['id'])) { return null; }
    if ($collection === 'nodes') {
        $plain = isset($row['panel_password_plain']) ? (string) $row['panel_password_plain'] : '';
        $tokenPlain = isset($row['xui_api_token_plain']) ? (string) $row['xui_api_token_plain'] : '';
        $proxyPlain = isset($row['xui_proxy_password_plain']) ? (string) $row['xui_proxy_password_plain'] : '';
        unset($row['panel_password_plain']);
        unset($row['xui_api_token_plain']);
        unset($row['xui_proxy_password_plain']);
        if ($plain !== '') {
            $row['panel_password'] = $this->encrypt($plain);
        }
        if ($tokenPlain !== '') {
            $row['xui_api_token'] = $this->encrypt($tokenPlain);
            $row['xui_api_token_hint'] = 'configured';
        }
        if ($proxyPlain !== '') {
            $row['xui_proxy_password'] = $this->encrypt($proxyPlain);
            $row['xui_proxy_password_hint'] = 'configured';
        }
    }
    if ($collection === 'customers') {
        $plain = isset($row['service_password_plain']) ? (string) $row['service_password_plain'] : '';
        unset($row['service_password_plain']);
        if ($plain !== '') {
            $row['service_password_enc'] = $this->encrypt($plain);
        }
    }
    $row['synced_from_panel'] = 'master';
    $row['synced_from_url'] = isset($settings['master_url']) ? (string) $settings['master_url'] : '';
    $row['synced_at'] = panel_now();
    return $row;
}

}
