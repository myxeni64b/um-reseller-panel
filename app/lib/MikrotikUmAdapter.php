<?php
class MikrotikUmAdapter
{
    protected $node;
    protected $storageRoot;
    protected $timeout;
    protected $connectTimeout;
    protected $retryAttempts;
    protected $baseUrl;
    protected $restBaseUrl;
    protected $verifyPeer = true;
    protected $verifyHost = 2;
    protected $lastError = '';
    protected $apiMode = 'rest';
    protected $internalHost = '';
    protected $internalPort = 0;
    protected $internalSsl = false;

    public function __construct($node, $storageRoot)
    {
        $this->node = is_array($node) ? $node : array();
        $this->storageRoot = rtrim((string) $storageRoot, '/');
        $this->timeout = isset($this->node['request_timeout']) ? max(5, (int) $this->node['request_timeout']) : 20;
        $this->connectTimeout = isset($this->node['connect_timeout']) ? max(3, (int) $this->node['connect_timeout']) : 8;
        $this->retryAttempts = isset($this->node['retry_attempts']) ? max(1, (int) $this->node['retry_attempts']) : 2;
        $this->baseUrl = rtrim(trim((string) panel_array_get($this->node, 'base_url', '')), '/');
        $this->restBaseUrl = $this->baseUrl !== '' ? ($this->baseUrl . '/rest') : '';
        $allowInsecure = isset($this->node['allow_insecure_tls']) ? panel_parse_bool($this->node['allow_insecure_tls'], false) : false;
        $this->verifyPeer = !$allowInsecure;
        $this->verifyHost = $allowInsecure ? 0 : 2;

        $mode = strtolower(trim((string) panel_array_get($this->node, 'um_api_mode', 'rest')));
        $this->apiMode = in_array($mode, array('rest', 'internal'), true) ? $mode : 'rest';
        $this->internalSsl = panel_parse_bool(panel_array_get($this->node, 'um_api_ssl', false), false);
        $this->internalHost = trim((string) panel_array_get($this->node, 'um_api_host', ''));
        if ($this->internalHost === '' && $this->baseUrl !== '') {
            $parts = @parse_url($this->baseUrl);
            if (is_array($parts) && !empty($parts['host'])) {
                $this->internalHost = (string) $parts['host'];
            }
        }
        $portValue = trim((string) panel_array_get($this->node, 'um_api_port', ''));
        if ($portValue !== '' && ctype_digit($portValue)) {
            $port = (int) $portValue;
            if ($port > 0 && $port <= 65535) {
                $this->internalPort = $port;
            }
        }
        if ($this->internalPort <= 0) {
            $parts = $this->baseUrl !== '' ? @parse_url($this->baseUrl) : array();
            if ($this->apiMode === 'internal' && is_array($parts) && !empty($parts['port'])) {
                $candidatePort = (int) $parts['port'];
                if ($candidatePort > 0 && $candidatePort <= 65535 && !in_array($candidatePort, array(80, 443), true)) {
                    $this->internalPort = $candidatePort;
                }
            }
        }
        if ($this->internalPort <= 0) {
            $this->internalPort = $this->internalSsl ? 8729 : 8728;
        }
    }

    public function error()
    {
        return $this->lastError;
    }

    protected function umCustomerName()
    {
        $customer = trim((string) panel_array_get($this->node, 'um_customer', ''));
        if ($customer !== '') {
            return $customer;
        }
        $customer = trim((string) panel_array_get($this->node, 'panel_username', ''));
        return $customer !== '' ? $customer : 'admin';
    }

    public function userExists($identity)
    {
        $user = $this->findUser($identity);
        return !empty($user['id']);
    }

    public function ping()
    {
        $resource = $this->selectedPrint('/system/resource', array(), array('version', 'board-name', 'platform', 'uptime'));
        if (!$resource['ok']) {
            return array('ok' => false, 'message' => $resource['message']);
        }
        $um = $this->selectedPrint('/user-manager', array(), array('enabled', 'use-profiles'));
        if (!$um['ok']) {
            return array('ok' => false, 'message' => 'Router connection succeeded, but User Manager probe failed: ' . $um['message']);
        }
        $settingsRow = $this->firstRow($um['data']);
        $enabledValue = strtolower(trim((string) panel_array_get($settingsRow, 'enabled', 'yes')));
        $message = 'UM ' . $this->modeLabel() . ' connection successful.';
        if (!in_array($enabledValue, array('yes', 'true', '1'), true)) {
            $message .= ' User Manager appears disabled on the router.';
        }
        return array(
            'ok' => true,
            'message' => $message,
            'data' => array(
                'api_mode' => $this->apiMode,
                'resource' => $resource['data'],
                'settings' => $um['data'],
            ),
        );
    }

    public function listProfiles()
    {
        $menus = array('/user-manager/profile', '/tool/user-manager/profile');
        $queries = array(
            array('.id', 'name', 'name-for-users', 'starts-when', 'validity', 'price'),
            array(),
        );
        $last = array('ok' => false, 'message' => 'Could not load UM profiles.');
        foreach ($menus as $menu) {
            foreach ($queries as $proplist) {
                $last = $this->selectedPrint($menu, array(), $proplist);
                if (!empty($last['ok']) && is_array(panel_array_get($last, 'data', null))) {
                    $rows = (array) panel_array_get($last, 'data', array());
                    if (!empty($rows)) {
                        return $last;
                    }
                    if ($menu === '/user-manager/profile') {
                        return $last;
                    }
                }
                $msg = strtolower(trim((string) panel_array_get($last, 'message', '')));
                if ($msg !== '' && strpos($msg, 'no such command or directory') === false && strpos($msg, 'bad command name') === false) {
                    return $last;
                }
            }
        }
        return $last;
    }

    public function createOrUpdateUser($customer, $template)
    {
        if ($this->apiMode === 'internal') {
            return $this->createOrUpdateUserInternal($customer, $template);
        }

        $username = trim((string) panel_array_get($customer, 'service_username', panel_array_get($customer, 'system_name', '')));
        $password = trim((string) panel_array_get($customer, 'service_password_plain', panel_array_get($customer, 'service_password', '')));
        if ($username === '' || $password === '') {
            return array('ok' => false, 'message' => 'UM username or password is missing.');
        }

        $user = $this->findUserByName($username);
        if (empty($user['id'])) {
            $user = $this->findUser($customer);
        }
        $disabled = panel_array_get($customer, 'status', 'active') !== 'active' ? 'yes' : 'no';
        $basePayload = array(
            'password' => $password,
            'comment' => trim((string) panel_array_get($customer, 'display_name', '')),
            'disabled' => $disabled,
        );
        $sharedUsers = (int) panel_array_get($customer, 'ip_limit', 1);
        if ($sharedUsers > 0) {
            $basePayload['shared-users'] = $sharedUsers;
        }

        if (!empty($user['id'])) {
            $payloadVariants = $this->existingUserPayloadVariants($user, $username, $basePayload);
            $res = $this->setFirstAvailableVariants($this->prioritizeMenus(panel_array_get($user, 'menu', ''), $this->userMenus()), $user['id'], $payloadVariants);
            if (empty($res['ok']) && $this->isNotFoundItemMessage(trim((string) panel_array_get($res, 'message', '')))) {
                $user = $this->findUserByName($username);
                if (!empty($user['id'])) {
                    $res = $this->setFirstAvailableVariants($this->prioritizeMenus(panel_array_get($user, 'menu', ''), $this->userMenus()), $user['id'], $payloadVariants);
                }
            }
            if (empty($res['ok']) && $this->apiMode !== 'internal') {
                $res = $this->setUserByReference($username, $payloadVariants, panel_array_get($user, 'menu', ''), $user['id']);
            }
        } else {
            $payloadVariants = $this->newUserPayloadVariants($username, $basePayload);
            $res = $this->addFirstAvailableVariants($this->userMenus(), $payloadVariants);
            if (empty($res['ok']) && $this->isAlreadyExistsMessage(trim((string) panel_array_get($res, 'message', '')))) {
                $user = $this->findUserByName($username);
                if (!empty($user['id'])) {
                    $payloadVariants = $this->existingUserPayloadVariants($user, $username, $basePayload);
                    $res = $this->setFirstAvailableVariants($this->prioritizeMenus(panel_array_get($user, 'menu', ''), $this->userMenus()), $user['id'], $payloadVariants);
                    if (empty($res['ok']) && $this->apiMode !== 'internal') {
                        $res = $this->setUserByReference($username, $payloadVariants, panel_array_get($user, 'menu', ''), $user['id']);
                    }
                } else if ($this->apiMode !== 'internal') {
                    $res = $this->setUserByReference($username, $payloadVariants, '', '');
                }
            }
        }
        if (!$res['ok']) {
            return $res;
        }

        $createdId = $this->extractResultItemId($res);
        if ($createdId === '' && !empty($user['id'])) {
            $createdId = (string) $user['id'];
        }
        $verify = $this->waitForUserAfterMutation($customer, $username, $createdId, !empty($res['menu']) ? (string) $res['menu'] : '');

        $assign = $this->ensureUserProfile(!empty($verify['id']) ? $verify : array_merge(is_array($customer) ? $customer : array(), array('service_username' => $username, 'um_remote_user_id' => $createdId)), trim((string) panel_array_get($template, 'um_profile_name', panel_array_get($template, 'public_label', ''))));
        if (!$assign['ok']) {
            return $assign;
        }

        if (empty($verify['id'])) {
            $verify = $this->waitForUserAfterMutation($customer, $username, $createdId, !empty($res['menu']) ? (string) $res['menu'] : '');
        }
        if (empty($verify['id'])) {
            $verify = array(
                'id' => $createdId,
                'menu' => !empty($res['menu']) ? (string) $res['menu'] : $this->userMenus()[0],
                'row' => array('name' => $username, 'username' => $username),
            );
        }

        return array('ok' => true, 'message' => 'UM user synced successfully through ' . $this->modeLabel() . '.', 'data' => $res['data'], 'user' => $verify, 'profile' => panel_array_get($assign, 'data', array()), 'result_id' => $createdId);
    }

    public function setUserDisabled($identity, $disabled)
    {
        if ($this->apiMode === 'internal') {
            return $this->setUserDisabledInternal($identity, $disabled);
        }

        $user = $this->findUser($identity);
        $username = $this->identityUsername($identity, $user);
        $desiredDisabled = $disabled ? true : false;
        $currentState = $this->currentUserDisabledState($user);
        if ($currentState !== null && $currentState === $desiredDisabled) {
            return array('ok' => true, 'message' => 'UM user state already matches the requested value.', 'user_exists' => true, 'user' => $user);
        }

        $action = $disabled ? 'disable' : 'enable';
        $menus = $this->prioritizeMenus(panel_array_get($user, 'menu', ''), $this->userMenus());
        $last = array('ok' => false, 'message' => 'UM ' . $action . ' command failed.');

        $cmd = $this->commandUserAction($action, $identity, $user);
        if (!empty($cmd['ok'])) {
            $probe = $this->findUser($identity);
            if (!empty($probe['id']) || !empty($probe['row'])) {
                $state = $this->currentUserDisabledState($probe);
                if ($state === null || $state === $desiredDisabled) {
                    $cmd['user'] = $probe;
                    $cmd['user_exists'] = true;
                    return $cmd;
                }
            }
            $cmd['user'] = !empty($user) ? $user : $probe;
            $cmd['user_exists'] = true;
            return $cmd;
        }
        $last = $cmd;

        if (!empty($user['id'])) {
            $res = $this->setFirstAvailable($menus, $user['id'], array('disabled' => $disabled ? 'yes' : 'no'));
            if (!empty($res['ok'])) {
                $probe = $this->findUser($identity);
                $state = $this->currentUserDisabledState($probe);
                if (!empty($probe['id']) || !empty($probe['row'])) {
                    if ($state === null || $state === $desiredDisabled) {
                        $res['user'] = $probe;
                        $res['user_exists'] = true;
                        return $res;
                    }
                }
                $res['user'] = $user;
                $res['user_exists'] = true;
                return $res;
            }
            $last = $res;
        }
        if ($username !== '') {
            $payloads = array(
                array('disabled' => $disabled ? 'yes' : 'no'),
                array('disabled' => $disabled ? 'true' : 'false'),
            );
            $res = $this->setUserByReference($username, $payloads, panel_array_get($user, 'menu', ''), !empty($user['id']) ? (string) $user['id'] : '');
            if (!empty($res['ok'])) {
                $probe = $this->findUser(array_merge(is_array($identity) ? $identity : array(), array('service_username' => $username)));
                $state = $this->currentUserDisabledState($probe);
                if (!empty($probe['id']) || !empty($probe['row'])) {
                    if ($state === null || $state === $desiredDisabled) {
                        $res['user'] = $probe;
                        $res['user_exists'] = true;
                        return $res;
                    }
                }
                $res['user'] = !empty($user) ? $user : $probe;
                $res['user_exists'] = true;
                return $res;
            }
            $last = $res;
        }

        $probe = $this->findUser(array_merge(is_array($identity) ? $identity : array(), array('service_username' => $username, 'username' => $username, 'name' => $username, 'user' => $username, 'login' => $username)));
        if (!empty($probe['id']) || !empty($probe['row'])) {
            $state = $this->currentUserDisabledState($probe);
            if ($state !== null && $state === $desiredDisabled) {
                return array('ok' => true, 'message' => 'UM user state verified after update.', 'user_exists' => true, 'user' => $probe);
            }
            return array('ok' => false, 'message' => 'UM ' . $action . ' command failed.', 'user_exists' => true, 'user' => $probe);
        }
        return array('ok' => false, 'message' => 'UM user not found by lookup across supported user fields, ids, and menus.', 'user_exists' => false);
    }

    public function deleteUser($identity)
    {
        if ($this->apiMode === 'internal') {
            return $this->deleteUserInternal($identity);
        }

        $user = $this->findUser($identity);
        $username = $this->identityUsername($identity, $user);
        if (empty($user['id']) && $username === '') {
            return array('ok' => true, 'message' => 'UM user already absent.', 'user_exists' => false);
        }

        $this->clearUserProfiles($identity, $username);

        $cmd = $this->commandUserAction('remove', $identity, $user);
        if (!empty($cmd['ok'])) {
            $probe = $this->findUser(array_merge(is_array($identity) ? $identity : array(), array('service_username' => $username, 'username' => $username, 'name' => $username, 'user' => $username, 'login' => $username)));
            if (empty($probe['id']) && empty($probe['row'])) {
                $cmd['user'] = $user;
                $cmd['user_exists'] = false;
                return $cmd;
            }
        }

        if (!empty($user['id'])) {
            $res = $this->removeFirstAvailable($this->prioritizeMenus(panel_array_get($user, 'menu', ''), $this->userMenus()), $user['id']);
            if (!empty($res['ok'])) {
                $probe = $this->findUser(array_merge(is_array($identity) ? $identity : array(), array('service_username' => $username, 'username' => $username, 'name' => $username, 'user' => $username, 'login' => $username)));
                if (empty($probe['id']) && empty($probe['row'])) {
                    $res['user'] = $user;
                    $res['user_exists'] = false;
                    return $res;
                }
            }
        }
        if ($username !== '' || !empty($user['id'])) {
            $res = $this->removeUserByReference($username, panel_array_get($user, 'menu', ''), !empty($user['id']) ? (string) $user['id'] : '');
            if (!empty($res['ok'])) {
                $probe = $this->findUser(array_merge(is_array($identity) ? $identity : array(), array('service_username' => $username, 'username' => $username, 'name' => $username, 'user' => $username, 'login' => $username)));
                if (empty($probe['id']) && empty($probe['row'])) {
                    $res['user'] = !empty($user) ? $user : array('id' => '', 'menu' => $this->userMenus()[0], 'row' => array('name' => $username, 'username' => $username));
                    $res['user_exists'] = false;
                    return $res;
                }
            }
        }
        $probe = $this->findUser(array_merge(is_array($identity) ? $identity : array(), array('service_username' => $username, 'username' => $username, 'name' => $username, 'user' => $username, 'login' => $username)));
        if (empty($probe['id']) && empty($probe['row'])) {
            return array('ok' => true, 'message' => 'UM user already absent.', 'user_exists' => false);
        }
        return array('ok' => false, 'message' => 'UM user remove failed.', 'user_exists' => true, 'user' => $probe);
    }

    public function getUserUsage($identity)
    {
        if ($this->apiMode === 'internal') {
            return $this->getUserUsageInternal($identity);
        }

        $user = $this->findUser($identity);
        $username = $this->identityUsername($identity, $user);
        $used = 0.0;
        $usedFromUser = false;
        if (!empty($user['row']) && is_array($user['row'])) {
            $rowUsed = $this->rowUsageBytes($user['row']);
            if ($rowUsed !== null) {
                $used = (float) $rowUsed;
                $usedFromUser = true;
            }
        }

        $expires = '';
        $profiles = $this->findRowsForUser($this->userProfileMenus(), $identity, array('.id', 'profile', 'user', 'username', 'name', 'login', 'end-time', 'state', 'user-id', 'user_id'));
        if (!empty($profiles['ok']) && is_array($profiles['data'])) {
            foreach ($profiles['data'] as $row) {
                $end = trim((string) panel_array_get($row, 'end-time', ''));
                if ($end !== '' && ($expires === '' || strtotime($end) > strtotime($expires))) {
                    $expires = $end;
                }
            }
        }

        $sess = array('ok' => false, 'data' => array());
        if (!$usedFromUser) {
            $sess = $this->findRowsForUser($this->sessionMenus(), $identity, array('user', 'username', 'name', 'login', 'upload', 'download', 'uptime', 'started', 'ended', 'status', 'user-id', 'user_id'));
            if (!empty($sess['ok']) && is_array($sess['data'])) {
                foreach ($sess['data'] as $row) {
                    $used += $this->byteValue(panel_array_get($row, 'upload', 0));
                    $used += $this->byteValue(panel_array_get($row, 'download', 0));
                }
            }
        }

        $existsByRelatedRows = (!empty($profiles['ok']) && !empty($profiles['data'])) || (!empty($sess['ok']) && !empty($sess['data']));
        if (empty($user['id']) && empty($user['row']) && !$existsByRelatedRows) {
            return array('ok' => false, 'message' => 'UM user not found by lookup across supported user fields, ids, and menus.', 'user_exists' => false, 'used_bytes' => 0);
        }
        if (empty($user['id']) && $username !== '') {
            $user = array('id' => '', 'menu' => $this->userMenus()[0], 'row' => array('name' => $username, 'username' => $username));
        }
        return array('ok' => true, 'message' => $usedFromUser ? 'UM usage loaded from user totals.' : 'UM usage loaded.', 'user_exists' => true, 'used_bytes' => $used, 'expires_at' => $expires, 'data' => array('user' => $user['row']), 'user' => $user);
    }

    protected function internalRequest($command, $args = array(), $queries = array(), $proplist = array())
    {
        $attrs = (array) $args;
        if (!empty($proplist)) {
            $attrs['.proplist'] = implode(',', array_values((array) $proplist));
        }
        return $this->apiCommand($command, $attrs, (array) $queries);
    }

    protected function internalExecuteCandidates($candidates)
    {
        $last = array('ok' => false, 'message' => 'MikroTik API request failed.');
        foreach ((array) $candidates as $candidate) {
            $command = isset($candidate[0]) ? (string) $candidate[0] : '';
            $args = isset($candidate[1]) && is_array($candidate[1]) ? $candidate[1] : array();
            $queries = isset($candidate[2]) && is_array($candidate[2]) ? $candidate[2] : array();
            $proplist = isset($candidate[3]) && is_array($candidate[3]) ? $candidate[3] : array();
            $result = $this->internalRequest($command, $args, $queries, $proplist);
            if (!empty($result['ok'])) {
                return $result;
            }
            $last = $result;
        }
        return $last;
    }

    protected function internalIdentityUsername($identity)
    {
        return trim((string) panel_array_get($identity, 'service_username', panel_array_get($identity, 'system_name', panel_array_get($identity, 'remote_username', panel_array_get($identity, 'username', '')))));
    }

    protected function internalUserMenus()
    {
        return array('/user-manager/user', '/tool/user-manager/user');
    }

    protected function internalUserProfileMenus()
    {
        return array('/user-manager/user-profile', '/tool/user-manager/user-profile');
    }

    protected function internalLiteralValue($row, $key, $default = '')
    {
        if (is_array($row) && array_key_exists($key, $row)) {
            return $row[$key];
        }
        return panel_array_get($row, $key, $default);
    }

    protected function internalRowId($row)
    {
        $id = $this->internalLiteralValue($row, '.id', '');
        if ($id === '') {
            $id = $this->internalLiteralValue($row, 'id', '');
        }
        return trim((string) $id);
    }

    protected function internalFindUserById($userId)
    {
        $userId = trim((string) $userId);
        if ($userId === '') {
            return array('ok' => false, 'message' => 'User not found.', 'user' => null);
        }
        $proplist = array('.id', 'name', 'disabled', 'shared-users', 'download-used', 'upload-used', 'last-seen');
        $last = array('ok' => false, 'message' => 'User not found.', 'user' => null);
        foreach ($this->internalUserMenus() as $menu) {
            $result = $this->internalRequest($menu . '/print', array(), array('.id=' . $userId), $proplist);
            if (empty($result['ok'])) {
                $last = array('ok' => false, 'message' => panel_array_get($result, 'message', 'Could not query User Manager users.'), 'user' => null);
                continue;
            }
            foreach ((array) panel_array_get($result, 'data', array()) as $row) {
                if ($this->internalRowId($row) === $userId) {
                    return array('ok' => true, 'message' => 'User found.', 'user' => $row, 'id' => $userId, 'menu' => $menu);
                }
            }
            $last = array('ok' => false, 'message' => 'User not found.', 'user' => null);
        }
        return $last;
    }

protected function internalFindUserByName($username)
{
    $username = trim((string) $username);
    if ($username === '') {
        return array('ok' => false, 'message' => 'User not found.', 'user' => null);
    }
    $proplist = array('.id', 'name', 'disabled', 'shared-users', 'download-used', 'upload-used', 'last-seen');
    $last = array('ok' => false, 'message' => 'User not found.', 'user' => null);
    foreach ($this->internalUserMenus() as $menu) {
        $result = $this->internalRequest($menu . '/print', array(), array('name=' . $username), $proplist);
        if (empty($result['ok'])) {
            $last = array('ok' => false, 'message' => panel_array_get($result, 'message', 'Could not query User Manager users.'), 'user' => null);
            continue;
        }
        foreach ((array) panel_array_get($result, 'data', array()) as $row) {
            if ((string) $this->internalLiteralValue($row, 'name', '') === $username) {
                return array('ok' => true, 'message' => 'User found.', 'user' => $row, 'id' => $this->internalRowId($row), 'menu' => $menu);
            }
        }
        $last = array('ok' => false, 'message' => 'User not found.', 'user' => null);
    }
    return $last;
}

protected function internalFindUserProfiles($username)
    {
        $username = trim((string) $username);
        $proplist = array('.id', 'user', 'profile', 'state', 'end-time');
        $last = array('ok' => false, 'message' => 'Could not query User Manager user profiles.', 'rows' => array());
        foreach ($this->internalUserProfileMenus() as $menu) {
            $result = $this->internalRequest($menu . '/print', array(), array('user=' . $username), $proplist);
            if (!empty($result['ok'])) {
                return array('ok' => true, 'message' => 'User profiles loaded.', 'rows' => (array) panel_array_get($result, 'data', array()), 'menu' => $menu);
            }
            $last = array('ok' => false, 'message' => panel_array_get($result, 'message', 'Could not query User Manager user profiles.'), 'rows' => array());
        }
        return $last;
    }

    protected function internalFindUserProfile($username, $profileName)
    {
        $profiles = $this->internalFindUserProfiles($username);
        if (empty($profiles['ok'])) {
            return array('ok' => false, 'message' => 'User profile not found.', 'row' => null);
        }
        foreach ((array) panel_array_get($profiles, 'rows', array()) as $row) {
            if ((string) panel_array_get($row, 'profile', '') === (string) $profileName) {
                return array('ok' => true, 'message' => 'User profile found.', 'row' => $row);
            }
        }
        return array('ok' => false, 'message' => 'User profile not found.', 'row' => null);
    }

    protected function internalAssignUserProfile($username, $profileName, $replaceOthers)
    {
        if ($replaceOthers) {
            $profiles = $this->internalFindUserProfiles($username);
            if (!empty($profiles['ok'])) {
                foreach ((array) panel_array_get($profiles, 'rows', array()) as $row) {
                    if ((string) panel_array_get($row, 'profile', '') !== $profileName && !empty($row['.id'])) {
                        $this->internalExecuteCandidates(array(
                            array('/user-manager/user-profile/remove', array('.id' => (string) $row['.id'])),
                            array('/tool/user-manager/user-profile/remove', array('.id' => (string) $row['.id'])),
                        ));
                    }
                }
            }
        }
        $existing = $this->internalFindUserProfile($username, $profileName);
        if (!empty($existing['ok']) && !empty($existing['row'])) {
            return array('ok' => true, 'message' => 'UM profile already assigned.', 'data' => $existing['row']);
        }
        $assign = $this->internalExecuteCandidates(array(
            array('/user-manager/user-profile/add', array('user' => $username, 'profile' => $profileName)),
            array('/tool/user-manager/user-profile/add', array('user' => $username, 'profile' => $profileName)),
        ));
        if (empty($assign['ok'])) {
            return array('ok' => false, 'message' => panel_array_get($assign, 'message', 'Profile assignment failed.'));
        }
        $existing = $this->internalFindUserProfile($username, $profileName);
        return array('ok' => !empty($existing['ok']), 'message' => !empty($existing['ok']) ? 'UM profile assigned.' : 'Profile assignment could not be verified.', 'data' => !empty($existing['row']) ? $existing['row'] : array());
    }

protected function createOrUpdateUserInternal($customer, $template)
{
    $username = $this->internalIdentityUsername($customer);
    $password = trim((string) panel_array_get($customer, 'service_password_plain', panel_array_get($customer, 'service_password', '')));
    $profileName = trim((string) panel_array_get($template, 'um_profile_name', panel_array_get($template, 'public_label', '')));
    $sharedUsers = max(1, (int) panel_array_get($customer, 'ip_limit', 1));
    $disabled = panel_array_get($customer, 'status', 'active') === 'active' ? 'false' : 'true';
    if ($username === '' || $password === '' || $profileName === '') {
        return array('ok' => false, 'message' => 'UM username, password, or profile name is missing.');
    }

    $providerMeta = isset($customer['provider_meta_json']) ? panel_safe_json_decode($customer['provider_meta_json']) : array();
    $user = $this->internalFindUserByName($username);
    if ((empty($user['ok']) || empty($user['user'])) && !empty($providerMeta['user_id'])) {
        $byId = $this->internalFindUserById((string) $providerMeta['user_id']);
        if (!empty($byId['ok']) && !empty($byId['user'])) {
            $user = $byId;
        }
    }

    if (!empty($user['ok']) && !empty($user['user'])) {
        $userRow = (array) $user['user'];
        $userId = $this->internalRowId($userRow);
        if ($userId === '') {
            return array('ok' => false, 'message' => 'Could not resolve User Manager user id for update.');
        }
        $patch = array(
            '.id' => $userId,
            'disabled' => $disabled,
            'shared-users' => (string) $sharedUsers,
        );
        if ($password !== '') {
            $patch['password'] = $password;
        }
        $update = $this->internalExecuteCandidates(array(
            array('/user-manager/user/set', $patch),
            array('/tool/user-manager/user/set', $patch),
        ));
        if (empty($update['ok'])) {
            return array('ok' => false, 'message' => panel_array_get($update, 'message', 'Could not update User Manager user.'));
        }
        $assigned = $this->internalAssignUserProfile($username, $profileName, true);
        if (empty($assigned['ok'])) {
            return $assigned;
        }
        $fresh = $this->internalFindUserByName($username);
        $freshRow = !empty($fresh['user']) ? (array) $fresh['user'] : $userRow;
        $freshId = $this->internalRowId($freshRow);
        return array(
            'ok' => true,
            'message' => 'UM user updated successfully through internal API.',
            'user' => array('id' => $freshId !== '' ? $freshId : $userId, 'menu' => !empty($fresh['menu']) ? $fresh['menu'] : '/user-manager/user', 'row' => $freshRow),
            'profile' => panel_array_get($assigned, 'data', array()),
            'result_id' => $freshId !== '' ? $freshId : $userId,
            'data' => $freshRow,
        );
    }

    $create = $this->internalExecuteCandidates(array(
        array('/user-manager/user/add', array(
            'name' => $username,
            'password' => $password,
            'shared-users' => (string) $sharedUsers,
            'disabled' => $disabled,
        )),
        array('/tool/user-manager/user/add', array(
            'name' => $username,
            'password' => $password,
            'shared-users' => (string) $sharedUsers,
            'disabled' => $disabled,
        )),
    ));
    if (empty($create['ok'])) {
        $message = trim((string) panel_array_get($create, 'message', 'Could not create User Manager user.'));
        if (strpos(strtolower($message), 'already exists') !== false) {
            $user = $this->internalFindUserByName($username);
            if (!empty($user['ok']) && !empty($user['user'])) {
                $customer['provider_meta_json'] = json_encode(array('user_id' => $this->internalRowId($user['user'])));
                return $this->createOrUpdateUserInternal($customer, $template);
            }
        }
        return array('ok' => false, 'message' => $message !== '' ? $message : 'Could not create User Manager user.');
    }

    $assigned = $this->internalAssignUserProfile($username, $profileName, false);
    if (empty($assigned['ok'])) {
        $fresh = $this->internalFindUserByName($username);
        if (!empty($fresh['user'])) {
            $freshId = $this->internalRowId($fresh['user']);
            if ($freshId !== '') {
                $this->internalExecuteCandidates(array(
                    array('/user-manager/user/remove', array('.id' => $freshId)),
                    array('/tool/user-manager/user/remove', array('.id' => $freshId)),
                ));
            }
        }
        return $assigned;
    }

    $fresh = $this->internalFindUserByName($username);
    if (empty($fresh['ok']) || empty($fresh['user'])) {
        return array('ok' => false, 'message' => 'UM create/update returned success but the user could not be found afterwards.');
    }
    $freshRow = (array) $fresh['user'];
    $freshId = $this->internalRowId($freshRow);
    return array(
        'ok' => true,
        'message' => 'UM user created successfully through internal API.',
        'user' => array('id' => $freshId, 'menu' => !empty($fresh['menu']) ? $fresh['menu'] : '/user-manager/user', 'row' => $freshRow),
        'profile' => panel_array_get($assigned, 'data', array()),
        'result_id' => $freshId,
        'data' => $freshRow,
    );
}

protected function setUserDisabledInternal($identity, $disabled)
{
    $username = $this->internalIdentityUsername($identity);
    $user = $this->internalFindUserByName($username);
    if (empty($user['ok']) || empty($user['user'])) {
        return array('ok' => false, 'message' => 'UM user not found.', 'user_exists' => false);
    }
    $row = (array) $user['user'];
    $userId = $this->internalRowId($row);
    if ($userId === '') {
        return array('ok' => false, 'message' => 'Could not resolve UM user id for status update.', 'user_exists' => true, 'user' => array('id' => '', 'menu' => panel_array_get($user, 'menu', '/user-manager/user'), 'row' => $row));
    }
    $desired = $disabled ? 'true' : 'false';
    $update = $this->internalExecuteCandidates(array(
        array('/user-manager/user/set', array('.id' => $userId, 'disabled' => $desired)),
        array('/tool/user-manager/user/set', array('.id' => $userId, 'disabled' => $desired)),
    ));
    if (empty($update['ok'])) {
        return array('ok' => false, 'message' => panel_array_get($update, 'message', 'UM ' . ($disabled ? 'disable' : 'enable') . ' command failed.'), 'user_exists' => true, 'user' => array('id' => $userId, 'menu' => panel_array_get($user, 'menu', '/user-manager/user'), 'row' => $row));
    }
    $fresh = $this->internalFindUserByName($username);
    $freshRow = !empty($fresh['user']) ? (array) $fresh['user'] : $row;
    $freshId = $this->internalRowId($freshRow);
    $freshDisabled = strtolower(trim((string) $this->internalLiteralValue($freshRow, 'disabled', '')));
    $isDisabled = in_array($freshDisabled, array('yes', 'true', '1'), true);
    if ($freshDisabled !== '' && $isDisabled !== (bool) $disabled) {
        return array('ok' => false, 'message' => 'UM ' . ($disabled ? 'disable' : 'enable') . ' verification failed.', 'user_exists' => true, 'user' => array('id' => $freshId !== '' ? $freshId : $userId, 'menu' => !empty($fresh['menu']) ? $fresh['menu'] : panel_array_get($user, 'menu', '/user-manager/user'), 'row' => $freshRow));
    }
    return array('ok' => true, 'message' => 'UM user state updated.', 'user_exists' => true, 'user' => array('id' => $freshId !== '' ? $freshId : $userId, 'menu' => !empty($fresh['menu']) ? $fresh['menu'] : panel_array_get($user, 'menu', '/user-manager/user'), 'row' => $freshRow));
}

protected function deleteUserInternal($identity)
{
    $username = $this->internalIdentityUsername($identity);
    $providerMeta = isset($identity['provider_meta_json']) ? panel_safe_json_decode($identity['provider_meta_json']) : array();
    $user = $this->internalFindUserByName($username);
    if ((empty($user['ok']) || empty($user['user'])) && !empty($providerMeta['user_id'])) {
        $byId = $this->internalFindUserById((string) $providerMeta['user_id']);
        if (!empty($byId['ok']) && !empty($byId['user'])) {
            $user = $byId;
        }
    }
    $userId = !empty($user['user']) ? $this->internalRowId($user['user']) : trim((string) panel_array_get($providerMeta, 'user_id', ''));
    if ($userId === '') {
        return array('ok' => true, 'message' => 'UM user already absent.', 'user_exists' => false);
    }
    $profiles = $this->internalFindUserProfiles($username);
    if (!empty($profiles['ok'])) {
        foreach ((array) panel_array_get($profiles, 'rows', array()) as $profileRow) {
            $profileId = $this->internalRowId($profileRow);
            if ($profileId === '') {
                continue;
            }
            $this->internalExecuteCandidates(array(
                array('/user-manager/user-profile/remove', array('.id' => $profileId)),
                array('/tool/user-manager/user-profile/remove', array('.id' => $profileId)),
            ));
        }
    }
    $deleted = $this->internalExecuteCandidates(array(
        array('/user-manager/user/remove', array('.id' => $userId)),
        array('/tool/user-manager/user/remove', array('.id' => $userId)),
        array('/user-manager/user/remove', array('numbers' => $userId)),
        array('/tool/user-manager/user/remove', array('numbers' => $userId)),
    ));
    if (empty($deleted['ok'])) {
        return array('ok' => false, 'message' => panel_array_get($deleted, 'message', 'UM user remove failed.'), 'user_exists' => true, 'user' => array('id' => $userId, 'menu' => !empty($user['menu']) ? $user['menu'] : '/user-manager/user', 'row' => !empty($user['user']) ? $user['user'] : array('name' => $username)));
    }
    $probe = $this->internalFindUserByName($username);
    if (!empty($probe['ok']) && !empty($probe['user'])) {
        $probeId = $this->internalRowId($probe['user']);
        return array('ok' => false, 'message' => 'UM user remove failed.', 'user_exists' => true, 'user' => array('id' => $probeId !== '' ? $probeId : $userId, 'menu' => !empty($probe['menu']) ? $probe['menu'] : '/user-manager/user', 'row' => (array) $probe['user']));
    }
    return array('ok' => true, 'message' => 'UM user removed successfully.', 'user_exists' => false);
}

protected function getUserUsageInternal($identity)
{
    $username = $this->internalIdentityUsername($identity);
    $user = $this->internalFindUserByName($username);
    if (empty($user['ok']) || empty($user['user'])) {
        return array('ok' => false, 'message' => 'UM user not found.', 'user_exists' => false, 'used_bytes' => 0);
    }
    $row = (array) $user['user'];
    $used = $this->rowUsageBytes($row);
    $usedFromUser = $used !== null;
    if ($used === null) {
        $used = 0.0;
    }
    $expires = '';
    $lastSeen = trim((string) $this->internalLiteralValue($row, 'last-seen', ''));
    $remoteStatus = null;
    $disabled = $this->currentUserDisabledState(array('row' => $row));
    if ($disabled !== null) {
        $remoteStatus = $disabled ? 'disabled' : 'active';
    }
    $profiles = $this->internalFindUserProfiles($username);
    if (!empty($profiles['ok'])) {
        foreach ((array) panel_array_get($profiles, 'rows', array()) as $profileRow) {
            $end = trim((string) panel_array_get($profileRow, 'end-time', ''));
            if ($end !== '' && ($expires === '' || strtotime($end) > strtotime($expires))) {
                $expires = $end;
            }
        }
    }
    if (!$usedFromUser) {
        $sessions = $this->findRowsForUser($this->sessionMenus(), $identity, array('user', 'username', 'name', 'login', 'upload', 'download', 'uploaded', 'downloaded', 'tx-bytes', 'rx-bytes', 'tx', 'rx', 'total-upload', 'total-download', 'uptime', 'started', 'ended', 'status', 'user-id', 'user_id'));
        if (!empty($sessions['ok']) && is_array(panel_array_get($sessions, 'data', null))) {
            foreach ((array) panel_array_get($sessions, 'data', array()) as $sessionRow) {
                $sessionUsed = $this->rowUsageBytes($sessionRow);
                if ($sessionUsed !== null) {
                    $used += (float) $sessionUsed;
                } else {
                    $used += $this->byteValue(panel_array_get($sessionRow, 'upload', 0));
                    $used += $this->byteValue(panel_array_get($sessionRow, 'download', 0));
                }
                foreach (array('ended', 'started') as $timeField) {
                    $candidate = trim((string) panel_array_get($sessionRow, $timeField, ''));
                    if ($candidate !== '' && ($lastSeen === '' || @strtotime($candidate) > @strtotime($lastSeen))) {
                        $lastSeen = $candidate;
                    }
                }
            }
        }
    }
    return array(
        'ok' => true,
        'message' => $usedFromUser ? 'UM usage loaded from user totals.' : 'UM usage loaded.',
        'user_exists' => true,
        'used_bytes' => (float) $used,
        'expires_at' => $expires,
        'last_online_at' => $lastSeen,
        'remote_status' => $remoteStatus,
        'data' => array('user' => $row),
        'user' => array('id' => $this->internalRowId($row), 'menu' => !empty($user['menu']) ? $user['menu'] : '/user-manager/user', 'row' => $row),
    );
}

    protected function ensureUserProfile($identity, $profileName)
    {
        if ($profileName === '') {
            return array('ok' => false, 'message' => 'UM profile name is empty.');
        }
        $username = $this->identityUsername($identity);
        $rows = $this->findRowsForUser($this->userProfileMenus(), $identity, array('.id', 'user', 'username', 'name', 'login', 'profile', 'state', 'end-time', 'user-id', 'user_id'));
        if (!empty($rows['ok']) && is_array($rows['data'])) {
            foreach ($rows['data'] as $row) {
                if (strcasecmp(trim((string) panel_array_get($row, 'profile', '')), $profileName) === 0) {
                    return array('ok' => true, 'message' => 'UM profile already assigned.', 'data' => $row);
                }
            }
        }

        $activate = $this->createAndActivateProfile($username, $profileName, $identity);
        if (!empty($activate['ok'])) {
            return $activate;
        }

        $payloadVariants = $this->userProfilePayloadVariants($username, $profileName, is_array($identity) ? $identity : array());
        return $this->addFirstAvailableVariants($this->userProfileMenus(), $payloadVariants);
    }

    protected function waitForUserAfterMutation($identity, $username, $createdId, $preferredMenu)
    {
        $delays = array(0, 150000, 250000, 400000, 700000, 1000000);
        $menus = $preferredMenu !== '' ? array($preferredMenu) : $this->userMenus();
        foreach ($delays as $delay) {
            if ($delay > 0) {
                usleep($delay);
            }
            if ($createdId !== '') {
                $found = $this->findUserById($createdId, $menus);
                if (!empty($found['id'])) {
                    return $found;
                }
            }
            $identityProbe = is_array($identity) ? $identity : array();
            if ($createdId !== '') {
                $identityProbe['um_remote_user_id'] = $createdId;
            }
            if ($preferredMenu !== '') {
                $identityProbe['um_remote_user_menu'] = $preferredMenu;
            }
            if ($username !== '') {
                $identityProbe['service_username'] = $username;
                $identityProbe['username'] = $username;
                $identityProbe['name'] = $username;
                $identityProbe['user'] = $username;
                $identityProbe['login'] = $username;
            }
            $found = $this->findUser($identityProbe);
            if (!empty($found['id']) || !empty($found['row'])) {
                return $found;
            }
        }
        return array('id' => '', 'row' => array(), 'menu' => $preferredMenu !== '' ? $preferredMenu : $this->userMenus()[0]);
    }

    protected function setUserByReference($username, $payloadVariants, $preferredMenu = '', $userId = '')
    {
        $variants = array();
        foreach ((array) $payloadVariants as $payload) {
            if (!is_array($payload)) {
                continue;
            }
            if ($userId !== '') {
                foreach (array(array('.id' => $userId), array('numbers' => $userId), array('user-id' => $userId), array('user_id' => $userId)) as $ref) {
                    $variants[] = array_merge($ref, $payload);
                }
            }
            if ($username !== '') {
                $refs = array(
                    array('numbers' => $username),
                    array('name' => $username),
                    array('username' => $username),
                    array('user' => $username),
                    array('login' => $username),
                );
                if ($this->apiMode !== 'internal') {
                    $customer = $this->umCustomerName();
                    array_unshift($refs,
                        array('customer' => $customer, 'numbers' => $username),
                        array('customer' => $customer, 'name' => $username),
                        array('customer' => $customer, 'username' => $username),
                        array('customer' => $customer, 'user' => $username),
                        array('customer' => $customer, 'login' => $username)
                    );
                }
                foreach ($refs as $ref) {
                    $variants[] = array_merge($ref, $payload);
                }
            }
        }
        return $this->setFirstAvailableVariants($this->prioritizeMenus($preferredMenu, $this->userMenus()), '', $this->dedupePayloadVariants($variants));
    }

    protected function commandUserAction($action, $identity, $user = array())
    {
        $action = strtolower(trim((string) $action));
        if (!in_array($action, array('enable', 'disable', 'remove'), true)) {
            return array('ok' => false, 'message' => 'Unsupported UM action.');
        }
        if ($this->apiMode === 'internal') {
            $username = $this->identityUsername($identity, $user);
            if ((!is_array($user) || empty($user['id'])) && $username !== '') {
                $resolved = $this->internalFindUserByName($username);
                if (!empty($resolved['ok']) && !empty($resolved['user'])) {
                    $row = (array) $resolved['user'];
                    $user = array('id' => trim((string) panel_array_get($row, '.id', '')), 'row' => $row, 'menu' => !empty($resolved['menu']) ? (string) $resolved['menu'] : '/user-manager/user');
                }
            }
            $userId = is_array($user) && !empty($user['id']) ? trim((string) $user['id']) : trim((string) $this->identityValue(is_array($identity) ? $identity : array(), array('um_remote_user_id', 'remote_user_id', 'remote_id', '.id')));
            if ($action === 'remove') {
                if ($userId === '' && $username === '') {
                    return array('ok' => true, 'message' => 'UM user already absent.', 'user_exists' => false);
                }
                if ($userId !== '') {
                    $res = $this->internalExecuteCandidates(array(
                        array('/user-manager/user/remove', array('.id' => $userId)),
                        array('/tool/user-manager/user/remove', array('.id' => $userId)),
                    ));
                    if (!empty($res['ok'])) {
                        return array('ok' => true, 'message' => 'UM user removed.', 'user_exists' => false, 'user' => $user);
                    }
                    return array('ok' => false, 'message' => panel_array_get($res, 'message', 'UM user remove failed.'), 'user_exists' => true, 'user' => $user);
                }
                return array('ok' => false, 'message' => 'UM user remove failed.', 'user_exists' => true, 'user' => $user);
            }
            if ($userId === '') {
                return array('ok' => false, 'message' => 'UM user not found.', 'user_exists' => false);
            }
            $desired = $action === 'disable' ? 'true' : 'false';
            $res = $this->internalExecuteCandidates(array(
                array('/user-manager/user/set', array('.id' => $userId, 'disabled' => $desired)),
                array('/tool/user-manager/user/set', array('.id' => $userId, 'disabled' => $desired)),
            ));
            if (!empty($res['ok'])) {
                return array('ok' => true, 'message' => 'UM user state updated.', 'user_exists' => true, 'user' => $user);
            }
            return array('ok' => false, 'message' => panel_array_get($res, 'message', 'UM ' . $action . ' command failed.'), 'user_exists' => true, 'user' => $user);
        }

        $username = $this->identityUsername($identity, $user);
        if ((!is_array($user) || empty($user['id'])) && $username !== '') {
            $resolved = $this->findUserByName($username);
            if (!empty($resolved['id'])) {
                $user = $resolved;
            }
        }
        $userId = '';
        if (is_array($user) && !empty($user['id'])) {
            $userId = trim((string) $user['id']);
        }
        if ($userId === '') {
            $userId = trim((string) $this->identityValue(is_array($identity) ? $identity : array(), array('um_remote_user_id', 'remote_user_id', 'remote_id', '.id')));
        }
        $last = array('ok' => false, 'message' => 'UM ' . $action . ' command failed.');
        foreach (array('/tool/user-manager/user/' . $action, '/user-manager/user/' . $action) as $command) {
            $variants = array();
            if ($userId !== '') {
                $variants[] = array('numbers' => $userId);
                $variants[] = array('.id' => $userId);
            }
            if ($this->apiMode !== 'internal' && $username !== '') {
                $customer = $this->umCustomerName();
                $variants[] = array('customer' => $customer, 'numbers' => $username);
                $variants[] = array('customer' => $customer, 'user' => $username);
                $variants[] = array('customer' => $customer, 'name' => $username);
                $variants[] = array('numbers' => $username);
                $variants[] = array('user' => $username);
                $variants[] = array('name' => $username);
                $variants[] = array('username' => $username);
            }
            foreach ($this->dedupePayloadVariants($variants) as $payload) {
                $res = $this->selectedCommand($command, $payload);
                if (!empty($res['ok'])) {
                    $res['menu'] = str_replace('/' . $action, '', $command);
                    return $res;
                }
                $last = $res;
                $msg = strtolower(trim((string) panel_array_get($res, 'message', '')));
                if ($msg !== '' && ($this->isPayloadCompatibilityMessage($msg) || $this->isNotFoundItemMessage($msg) || $this->isMenuNotFoundMessage($msg))) {
                    continue;
                }
                if ($msg !== '') {
                    return $res;
                }
            }
        }
        return $last;
    }

    protected function removeUserByReference($username, $preferredMenu = '', $userId = '')
    {
        $last = array('ok' => false, 'message' => 'UM remove failed.');
        if ($this->apiMode === 'internal') {
            if ($userId === '' && trim((string) $username) !== '') {
                $resolved = $this->internalFindUserByName($username);
                if (!empty($resolved['ok']) && !empty($resolved['user'])) {
                    $userId = (string) panel_array_get($resolved['user'], '.id', '');
                }
            }
            if ($userId === '') {
                return array('ok' => false, 'message' => 'UM remove failed.');
            }
            return $this->removeFirstAvailable($this->prioritizeMenus($preferredMenu, $this->userMenus()), $userId);
        }
        $customer = $this->umCustomerName();
        foreach ($this->prioritizeMenus($preferredMenu, $this->userMenus()) as $menu) {
            $payloads = array();
            if ($userId !== '') {
                $payloads[] = array('.id' => $userId);
                $payloads[] = array('numbers' => $userId);
                $payloads[] = array('user-id' => $userId);
                $payloads[] = array('user_id' => $userId);
            }
            if ($username !== '') {
                $payloads[] = array('customer' => $customer, 'numbers' => $username);
                $payloads[] = array('customer' => $customer, 'name' => $username);
                $payloads[] = array('customer' => $customer, 'username' => $username);
                $payloads[] = array('customer' => $customer, 'user' => $username);
                $payloads[] = array('numbers' => $username);
                $payloads[] = array('name' => $username);
                $payloads[] = array('username' => $username);
                $payloads[] = array('user' => $username);
                $payloads[] = array('login' => $username);
            }
            foreach ($this->dedupePayloadVariants($payloads) as $payload) {
                $res = $this->selectedRemoveByPayload($menu, $payload);
                if (!empty($res['ok'])) {
                    $res['menu'] = $menu;
                    return $res;
                }
                $last = $res;
                $msg = strtolower(trim((string) panel_array_get($res, 'message', '')));
                if ($msg !== '' && !$this->isPayloadCompatibilityMessage($msg) && !$this->isMenuNotFoundMessage($msg)) {
                    return $res;
                }
            }
        }
        return $last;
    }

    protected function clearUserProfiles($identity, $username)
    {
        $rows = $this->findRowsForUser($this->userProfileMenus(), $identity, array('.id', 'user', 'username', 'name', 'login', 'profile'));
        if (!empty($rows['ok']) && is_array($rows['data'])) {
            foreach ($rows['data'] as $row) {
                $profileId = trim((string) panel_array_get($row, '.id', ''));
                if ($profileId !== '') {
                    $menu = trim((string) panel_array_get($row, '__menu', panel_array_get($rows, 'menu', '')));
                    if ($menu === '') { $menu = $this->userProfileMenus()[0]; }
                    $this->removeFirstAvailable(array($menu), $profileId);
                }
            }
        }
        if ($username !== '') {
            $this->clearProfilesByCommand($username);
        }
    }

    protected function clearProfilesByCommand($username)
    {
        if ($this->apiMode === 'internal') {
            $profiles = $this->internalFindUserProfiles($username);
            if (!empty($profiles['ok']) && !empty($profiles['state']['user_profiles'])) {
                foreach ((array) $profiles['state']['user_profiles'] as $row) {
                    if (!empty($row['.id'])) {
                        $this->internalExecuteCandidates(array(
                            array('/user-manager/user-profile/remove', array('.id' => (string) $row['.id'])),
                            array('/tool/user-manager/user-profile/remove', array('.id' => (string) $row['.id'])),
                        ));
                    }
                }
                return array('ok' => true, 'message' => 'UM profiles cleared.');
            }
            return array('ok' => true, 'message' => 'UM profiles already clear.');
        }
        $customer = $this->umCustomerName();
        foreach (array('/tool/user-manager/user/clear-profiles', '/user-manager/user/clear-profiles') as $command) {
            foreach (array(array('customer' => $customer, 'numbers' => $username), array('customer' => $customer, 'user' => $username), array('customer' => $customer, 'username' => $username), array('numbers' => $username), array('user' => $username), array('username' => $username)) as $payload) {
                $res = $this->selectedCommand($command, $payload);
                if (!empty($res['ok'])) {
                    return $res;
                }
            }
        }
        return array('ok' => false, 'message' => 'UM clear profiles command failed.');
    }

    protected function createAndActivateProfile($username, $profileName, $identity)
    {
        if ($this->apiMode === 'internal') {
            $assigned = $this->internalAssignUserProfile($username, $profileName, false);
            if (!empty($assigned['ok'])) {
                return array('ok' => true, 'message' => 'UM profile assigned.', 'data' => panel_array_get($assigned, 'data', array()));
            }
            return $assigned;
        }
        $customer = $this->umCustomerName();
        $userId = trim((string) $this->identityValue($identity, array('id', 'um_remote_user_id', 'remote_user_id', 'remote_id', '.id')));
        $variants = array(
            array('customer' => $customer, 'profile' => $profileName, 'numbers' => $username),
            array('customer' => $customer, 'profile' => $profileName, 'user' => $username),
            array('customer' => $customer, 'profile' => $profileName, 'username' => $username),
            array('customer' => $customer, 'profile' => $profileName, 'name' => $username),
        );
        if ($userId !== '') {
            array_unshift($variants, array('customer' => $customer, 'profile' => $profileName, 'numbers' => $userId));
        }
        foreach (array('/tool/user-manager/user/create-and-activate-profile', '/user-manager/user/create-and-activate-profile') as $command) {
            foreach ($variants as $payload) {
                $res = $this->selectedCommand($command, $payload);
                if (!empty($res['ok'])) {
                    return array('ok' => true, 'message' => 'UM profile assigned.', 'data' => is_array(panel_array_get($res, 'data', null)) ? panel_array_get($res, 'data', array()) : array());
                }
                $msg = strtolower(trim((string) panel_array_get($res, 'message', '')));
                if ($msg !== '' && !$this->isPayloadCompatibilityMessage($msg) && !$this->isMenuNotFoundMessage($msg)) {
                    return $res;
                }
            }
        }
        return array('ok' => false, 'message' => 'UM profile assignment failed.');
    }

    protected function findUser($identity)
    {
        $storedId = trim((string) $this->identityValue($identity, array('um_remote_user_id', 'remote_user_id', 'remote_id', '.id')));
        $storedMenu = trim((string) $this->identityValue($identity, array('um_remote_user_menu', 'remote_user_menu', '__menu')));
        if ($storedId !== '') {
            $menus = $storedMenu !== '' ? array($storedMenu) : $this->userMenus();
            $byId = $this->findUserById($storedId, $menus);
            if (!empty($byId['id'])) {
                return $byId;
            }
        }
        $username = $this->identityUsername($identity);
        return $this->findUserByName($username);
    }

    protected function findUserById($userId, $menus = null)
    {
        $userId = trim((string) $userId);
        if ($userId === '') {
            return array('id' => '', 'row' => array(), 'menu' => $this->userMenus()[0]);
        }
        $proplistVariants = $this->userPrintProplistVariants();
        $lastMessage = '';
        foreach ((array) ($menus === null ? $this->userMenus() : $menus) as $menu) {
            foreach ($proplistVariants as $proplist) {
                foreach (array(array('.id' => $userId), array()) as $conditions) {
                    $res = $this->selectedPrint($menu, $conditions, $proplist);
                    if (!empty($res['ok']) && is_array($res['data'])) {
                        foreach ($res['data'] as $row) {
                            $rowId = trim((string) panel_array_get($row, '.id', panel_array_get($row, 'id', '')));
                            if ($rowId !== '' && $rowId === $userId) {
                                return array('id' => $rowId, 'row' => $row, 'menu' => $menu);
                            }
                        }
                    }
                    $msg = strtolower(trim((string) panel_array_get($res, 'message', '')));
                    if ($msg !== '') {
                        $lastMessage = $msg;
                    }
                    if ($msg !== '' && $this->isPropertyCompatibilityMessage($msg)) {
                        continue;
                    }
                    if ($msg !== '' && !$this->isMenuNotFoundMessage($msg)) {
                        break;
                    }
                }
            }
        }
        if ($lastMessage !== '') {
            $this->lastError = $lastMessage;
        }
        return array('id' => '', 'row' => array(), 'menu' => is_array($menus) && !empty($menus) ? reset($menus) : $this->userMenus()[0]);
    }

    protected function findUserByName($username)
    {
        $username = trim((string) $username);
        if ($username === '') {
            return array('id' => '', 'row' => array(), 'menu' => $this->userMenus()[0]);
        }
        if ($this->apiMode === 'internal') {
            $found = $this->internalFindUserByName($username);
            if (!empty($found['ok']) && !empty($found['user'])) {
                $row = (array) $found['user'];
                return array('id' => trim((string) panel_array_get($row, '.id', '')), 'row' => $row, 'menu' => !empty($found['menu']) ? (string) $found['menu'] : $this->userMenus()[0]);
            }
            return array('id' => '', 'row' => array(), 'menu' => $this->userMenus()[0]);
        }

        $proplistVariants = $this->userPrintProplistVariants();
        $conditions = array(
            array('name' => $username),
            array('username' => $username),
            array('user' => $username),
            array('login' => $username),
            array('customer' => $username),
            array('subscriber' => $username),
            array('actual-login' => $username),
        );
        $lastMessage = '';

        foreach ($this->userMenus() as $menu) {
            foreach ($proplistVariants as $proplist) {
                foreach ($conditions as $condition) {
                    $res = $this->selectedPrint($menu, $condition, $proplist);
                    if (!empty($res['ok']) && is_array($res['data'])) {
                        foreach ($res['data'] as $row) {
                            if ($this->rowMatchesUser($row, $username)) {
                                return array('id' => trim((string) panel_array_get($row, '.id', panel_array_get($row, 'id', ''))), 'row' => $row, 'menu' => $menu);
                            }
                        }
                    }
                    $msg = strtolower(trim((string) panel_array_get($res, 'message', '')));
                    if ($msg !== '') {
                        $lastMessage = $msg;
                    }
                    if ($msg !== '' && $this->isPropertyCompatibilityMessage($msg)) {
                        continue;
                    }
                    if ($msg !== '' && !$this->isMenuNotFoundMessage($msg)) {
                        break;
                    }
                }

                $res = $this->selectedPrint($menu, array(), $proplist);
                if (!empty($res['ok']) && is_array($res['data'])) {
                    foreach ($res['data'] as $row) {
                        if ($this->rowMatchesUser($row, $username)) {
                            return array('id' => trim((string) panel_array_get($row, '.id', panel_array_get($row, 'id', ''))), 'row' => $row, 'menu' => $menu);
                        }
                    }
                }
                $msg = strtolower(trim((string) panel_array_get($res, 'message', '')));
                if ($msg !== '') {
                    $lastMessage = $msg;
                }
                if ($msg !== '' && $this->isPropertyCompatibilityMessage($msg)) {
                    continue;
                }
                if ($msg !== '' && !$this->isMenuNotFoundMessage($msg)) {
                    break;
                }
            }
        }

        if ($lastMessage !== '') {
            $this->lastError = $lastMessage;
        }
        return array('id' => '', 'row' => array(), 'menu' => $this->userMenus()[0]);
    }

    protected function findRowsForUser($menus, $identity, $proplist)
    {
        $username = $this->identityUsername($identity);
        $userId = trim((string) $this->identityValue($identity, array('um_remote_user_id', 'remote_user_id', 'remote_id', '.id')));
        $matches = array();
        $last = array('ok' => false, 'message' => 'UM user rows not found.', 'data' => array());
        $conditions = array();
        if ($userId !== '') {
            $conditions[] = array('.id' => $userId);
            $conditions[] = array('user-id' => $userId);
            $conditions[] = array('user_id' => $userId);
        }
        foreach (array('user', 'username', 'name', 'login', 'customer', 'subscriber', 'actual-login') as $field) {
            if ($username !== '') {
                $conditions[] = array($field => $username);
            }
        }
        $proplistVariants = $this->genericProplistVariants($proplist);
        foreach ((array) $menus as $menu) {
            foreach ($proplistVariants as $proplistVariant) {
                foreach ($conditions as $condition) {
                    $res = $this->selectedPrint($menu, $condition, $proplistVariant);
                    if (!empty($res['ok']) && is_array($res['data'])) {
                        foreach ($res['data'] as $idx => $row) {
                            if (!is_array($row)) {
                                continue;
                            }
                            $row['__menu'] = $menu;
                            if ($this->rowMatchesUser($row, $username, $userId)) {
                                $matches[] = $row;
                            }
                        }
                        if (!empty($matches)) {
                            return array('ok' => true, 'message' => 'ok', 'data' => $matches, 'menu' => $menu);
                        }
                    }
                    $last = $res;
                    $msg = strtolower(trim((string) panel_array_get($res, 'message', '')));
                    if ($msg !== '' && $this->isPropertyCompatibilityMessage($msg)) {
                        continue;
                    }
                    if ($msg !== '' && !$this->isMenuNotFoundMessage($msg)) {
                        break;
                    }
                }

                $res = $this->selectedPrint($menu, array(), $proplistVariant);
                if (!empty($res['ok']) && is_array($res['data'])) {
                    foreach ($res['data'] as $idx => $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $row['__menu'] = $menu;
                        if ($this->rowMatchesUser($row, $username, $userId)) {
                            $matches[] = $row;
                        }
                    }
                    if (!empty($matches)) {
                        return array('ok' => true, 'message' => 'ok', 'data' => $matches, 'menu' => $menu);
                    }
                }
                $last = $res;
                $msg = strtolower(trim((string) panel_array_get($res, 'message', '')));
                if ($msg !== '' && $this->isPropertyCompatibilityMessage($msg)) {
                    continue;
                }
                if ($msg !== '' && !$this->isMenuNotFoundMessage($msg)) {
                    break;
                }
            }
        }
        return $last;
    }

    protected function rowMatchesUser($row, $username, $userId = '')
    {
        if (!is_array($row)) {
            return false;
        }
        $userId = trim((string) $userId);
        if ($userId !== '') {
            foreach (array('.id', 'id', 'user-id', 'user_id') as $field) {
                $value = trim((string) panel_array_get($row, $field, ''));
                if ($value !== '' && $value === $userId) {
                    return true;
                }
            }
        }
        $username = trim((string) $username);
        if ($username === '') {
            return false;
        }
        $needle = strtolower($username);
        foreach (array('user', 'username', 'name', 'login', 'customer', 'subscriber', 'actual-login', 'caller-id', 'caller_id', 'comment') as $field) {
            $value = trim((string) panel_array_get($row, $field, ''));
            if ($value === '') {
                continue;
            }
            $lower = strtolower($value);
            if ($lower === $needle) {
                return true;
            }
            if (strpos($lower, $needle) !== false || strpos($needle, $lower) !== false) {
                return true;
            }
        }
        return false;
    }

    protected function identityUsername($identity, $user = array())
    {
        $fromUser = trim((string) panel_array_get($user, 'row.name', ''));
        if ($fromUser === '') {
            if (is_array($user) && !empty($user['row']) && is_array($user['row'])) {
                foreach (array('name', 'username', 'user', 'login', 'customer', 'subscriber', 'actual-login') as $field) {
                    $value = trim((string) panel_array_get($user['row'], $field, ''));
                    if ($value !== '') {
                        return $value;
                    }
                }
            }
        } else {
            return $fromUser;
        }
        return trim((string) $this->identityValue($identity, array('service_username', 'remote_email', 'system_name', 'username', 'user', 'name', 'login')));
    }

    protected function identityValue($identity, $keys)
    {
        if (!is_array($identity)) {
            return is_string($identity) ? $identity : '';
        }
        foreach ((array) $keys as $key) {
            if (array_key_exists($key, $identity) && trim((string) $identity[$key]) !== '') {
                return $identity[$key];
            }
        }
        return '';
    }

    protected function prioritizeMenus($preferred, $menus)
    {
        $out = array();
        $seen = array();
        $preferred = trim((string) $preferred);
        if ($preferred !== '') {
            $out[] = $preferred;
            $seen[$preferred] = true;
        }
        foreach ((array) $menus as $menu) {
            $menu = trim((string) $menu);
            if ($menu === '' || isset($seen[$menu])) {
                continue;
            }
            $out[] = $menu;
            $seen[$menu] = true;
        }
        return $out;
    }

    protected function currentUserDisabledState($user)
    {
        if (!is_array($user) || empty($user['row']) || !is_array($user['row'])) {
            return null;
        }
        foreach (array('disabled', 'is-disabled', 'is_disabled') as $field) {
            $value = trim((string) panel_array_get($user['row'], $field, ''));
            if ($value === '') {
                continue;
            }
            $value = strtolower($value);
            return in_array($value, array('yes', 'true', '1'), true);
        }
        return null;
    }

    protected function rowUsageBytes($row)
    {
        if (!is_array($row)) {
            return null;
        }
        $pairs = array(
            array('upload-used', 'download-used'),
            array('total-upload', 'total-download'),
            array('upload', 'download'),
            array('uploaded', 'downloaded'),
            array('tx-bytes', 'rx-bytes'),
            array('tx', 'rx'),
        );
        foreach ($pairs as $pair) {
            $up = trim((string) panel_array_get($row, $pair[0], ''));
            $down = trim((string) panel_array_get($row, $pair[1], ''));
            if ($up === '' && $down === '') {
                continue;
            }
            return $this->byteValue($up) + $this->byteValue($down);
        }
        return null;
    }

    protected function userMenus()
    {
        return array('/user-manager/user', '/tool/user-manager/user');
    }

    protected function userProfileMenus()
    {
        return array('/user-manager/user-profile', '/tool/user-manager/user-profile', '/user-manager/user/profile', '/tool/user-manager/user/profile');
    }

    protected function sessionMenus()
    {
        return array('/user-manager/session', '/tool/user-manager/session');
    }

    protected function isAlreadyExistsMessage($message)
    {
        $message = strtolower(trim((string) $message));
        if ($message === '') {
            return false;
        }
        foreach (array('already exists', 'username already exists', 'failure: username already exists', 'entry already exists', 'duplicate') as $needle) {
            if (strpos($message, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    protected function isMenuNotFoundMessage($message)
    {
        $message = strtolower(trim((string) $message));
        if ($message === '') {
            return false;
        }
        foreach (array('no such command or directory', 'bad command name', 'unknown command', 'not found') as $needle) {
            if (strpos($message, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    protected function printFirstAvailable($menus, $conditions, $proplist, $allowEmpty)
    {
        $last = array('ok' => false, 'message' => 'UM request failed.', 'data' => array());
        foreach ((array) $menus as $menu) {
            $res = $this->selectedPrint($menu, $conditions, $proplist);
            if (!empty($res['ok'])) {
                $rows = is_array(panel_array_get($res, 'data', null)) ? (array) panel_array_get($res, 'data', array()) : array();
                foreach ($rows as $idx => $row) {
                    if (is_array($row)) {
                        $rows[$idx]['__menu'] = $menu;
                    }
                }
                $res['data'] = $rows;
                $res['menu'] = $menu;
                if (!empty($rows) || $allowEmpty) {
                    return $res;
                }
                $last = $res;
                continue;
            }
            $last = $res;
            $msg = strtolower(trim((string) panel_array_get($res, 'message', '')));
            if ($msg !== '' && $this->isNotFoundItemMessage($msg)) {
                continue;
            }
            if ($msg !== '' && !$this->isMenuNotFoundMessage($msg)) {
                return $res;
            }
        }
        return $last;
    }

    protected function addFirstAvailable($menus, $payload)
    {
        $last = array('ok' => false, 'message' => 'UM add failed.');
        foreach ((array) $menus as $menu) {
            $res = $this->selectedAdd($menu, $payload);
            if (!empty($res['ok'])) {
                $res['menu'] = $menu;
                return $res;
            }
            $last = $res;
            $msg = strtolower(trim((string) panel_array_get($res, 'message', '')));
            if ($msg !== '' && $this->isNotFoundItemMessage($msg)) {
                continue;
            }
            if ($msg !== '' && !$this->isMenuNotFoundMessage($msg)) {
                return $res;
            }
        }
        return $last;
    }

    protected function setFirstAvailable($menus, $id, $payload)
    {
        $last = array('ok' => false, 'message' => 'UM update failed.');
        foreach ((array) $menus as $menu) {
            $res = $this->selectedSet($menu, $id, $payload);
            if (!empty($res['ok'])) {
                $res['menu'] = $menu;
                return $res;
            }
            $last = $res;
            $msg = strtolower(trim((string) panel_array_get($res, 'message', '')));
            if ($msg !== '' && $this->isNotFoundItemMessage($msg)) {
                continue;
            }
            if ($msg !== '' && !$this->isMenuNotFoundMessage($msg)) {
                return $res;
            }
        }
        return $last;
    }

    protected function addFirstAvailableVariants($menus, $payloadVariants)
    {
        $variants = array_values(array_filter((array) $payloadVariants, 'is_array'));
        if (empty($variants)) {
            return array('ok' => false, 'message' => 'UM add failed: no payload variants available.');
        }
        $last = array('ok' => false, 'message' => 'UM add failed.');
        foreach ((array) $menus as $menu) {
            foreach ($variants as $payload) {
                $res = $this->selectedAdd($menu, $payload);
                if (!empty($res['ok'])) {
                    $res['menu'] = $menu;
                    return $res;
                }
                $last = $res;
                $msg = strtolower(trim((string) panel_array_get($res, 'message', '')));
                if ($msg !== '' && $this->isPayloadCompatibilityMessage($msg)) {
                    continue;
                }
                if ($msg !== '' && !$this->isMenuNotFoundMessage($msg)) {
                    return $res;
                }
            }
        }
        return $last;
    }

    protected function setFirstAvailableVariants($menus, $id, $payloadVariants)
    {
        $variants = array_values(array_filter((array) $payloadVariants, 'is_array'));
        if (empty($variants)) {
            return array('ok' => false, 'message' => 'UM update failed: no payload variants available.');
        }
        $last = array('ok' => false, 'message' => 'UM update failed.');
        foreach ((array) $menus as $menu) {
            foreach ($variants as $payload) {
                $res = $this->selectedSet($menu, $id, $payload);
                if (!empty($res['ok'])) {
                    $res['menu'] = $menu;
                    return $res;
                }
                $last = $res;
                $msg = strtolower(trim((string) panel_array_get($res, 'message', '')));
                if ($msg !== '' && $this->isPayloadCompatibilityMessage($msg)) {
                    continue;
                }
                if ($msg !== '' && !$this->isMenuNotFoundMessage($msg)) {
                    return $res;
                }
            }
        }
        return $last;
    }

    protected function isPayloadCompatibilityMessage($message)
    {
        $message = strtolower(trim((string) $message));
        if ($message === '') {
            return false;
        }
        foreach (array('unknown parameter', 'input does not match any value', 'expected end of command', 'bad command name', 'failure: unknown parameter') as $needle) {
            if (strpos($message, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    protected function isPropertyCompatibilityMessage($message)
    {
        return $this->isPayloadCompatibilityMessage($message);
    }

    protected function userPrintProplistVariants()
    {
        if ($this->apiMode === 'internal') {
            return array(
                array('.id', 'name', 'disabled', 'shared-users', 'download-used', 'upload-used', 'last-seen'),
                array(),
            );
        }
        return array(
            array('.id', 'name', 'disabled', 'comment', 'shared-users'),
            array('.id', 'name', 'user', 'disabled', 'comment', 'shared-users'),
            array('.id', 'name', 'username', 'disabled', 'comment', 'shared-users'),
            array('.id', 'name', 'login', 'disabled', 'comment', 'shared-users'),
            array('.id', 'name', 'customer', 'subscriber', 'actual-login', 'disabled', 'comment', 'shared-users'),
            array(),
        );
    }

    protected function genericProplistVariants($proplist)
    {
        $variants = array();
        if (is_array($proplist) && !empty($proplist)) {
            $variants[] = array_values($proplist);
            $base = array();
            foreach ((array) $proplist as $field) {
                $field = trim((string) $field);
                if ($field === '' || in_array($field, array('username', 'login', 'customer', 'subscriber', 'actual-login'), true)) {
                    continue;
                }
                $base[] = $field;
            }
            if (!empty($base)) {
                $variants[] = array_values(array_unique($base));
            }
        }
        $variants[] = array();
        $out = array();
        $seen = array();
        foreach ($variants as $variant) {
            $key = md5(json_encode(array_values($variant)));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = array_values($variant);
        }
        return $out;
    }

    protected function extractResultItemId($result)
    {
        if (!is_array($result)) {
            return '';
        }
        foreach (array('ret', 'result_id') as $field) {
            $value = trim((string) panel_array_get($result, $field, ''));
            if ($value !== '') {
                return $value;
            }
        }
        $done = panel_array_get($result, 'done', array());
        if (is_array($done)) {
            foreach (array('ret', '.id', 'id') as $field) {
                $value = trim((string) panel_array_get($done, $field, ''));
                if ($value !== '') {
                    return $value;
                }
            }
        }
        $data = panel_array_get($result, 'data', null);
        if (is_array($data)) {
            foreach (array('.id', 'id', 'ret') as $field) {
                $value = trim((string) panel_array_get($data, $field, ''));
                if ($value !== '') {
                    return $value;
                }
            }
            if (isset($data[0]) && is_array($data[0])) {
                foreach (array('.id', 'id', 'ret') as $field) {
                    $value = trim((string) panel_array_get($data[0], $field, ''));
                    if ($value !== '') {
                        return $value;
                    }
                }
            }
        }
        return '';
    }

    protected function existingUserPayloadVariants($user, $username, $basePayload)
    {
        $variants = array();
        $base = is_array($basePayload) ? $basePayload : array();
        if ($this->apiMode !== 'internal') {
            $base['customer'] = $this->umCustomerName();
            $variants[] = $base;
        }
        $variants[] = (array) $basePayload;
        return $this->dedupePayloadVariants($variants);
    }

    protected function newUserPayloadVariants($username, $basePayload)
    {
        $variants = array();
        $base = is_array($basePayload) ? $basePayload : array();
        if ($this->apiMode === 'internal') {
            $variants[] = $base + array('name' => $username);
            return $this->dedupePayloadVariants($variants);
        }
        $customer = $this->umCustomerName();

        $variants[] = $base + array('customer' => $customer, 'username' => $username, 'name' => $username);
        $variants[] = $base + array('customer' => $customer, 'username' => $username);
        $variants[] = $base + array('customer' => $customer, 'name' => $username);
        $variants[] = $base + array('subscriber' => $customer, 'username' => $username, 'name' => $username);
        $variants[] = $base + array('subscriber' => $customer, 'username' => $username);
        $variants[] = $base + array('username' => $username, 'name' => $username);
        $variants[] = $base + array('username' => $username);
        $variants[] = $base + array('name' => $username);
        return $this->dedupePayloadVariants($variants);
    }

    protected function userProfilePayloadVariants($username, $profileName, $identity)
    {
        $variants = array();
        $base = array('profile' => $profileName);
        $userId = trim((string) $this->identityValue($identity, array('id', 'um_remote_user_id', 'remote_user_id', 'remote_id', '.id')));
        if ($userId !== '') {
            $variants[] = $base + array('user-id' => $userId);
            $variants[] = $base + array('user_id' => $userId);
            $variants[] = $base + array('user' => $userId);
        }
        foreach ($this->identityFieldsFromRow(is_array($identity) ? panel_array_get($identity, 'row', array()) : array()) as $field) {
            $candidate = $base;
            $candidate[$field] = $username;
            $variants[] = $candidate;
        }
        foreach (array('user', 'username', 'name', 'login') as $field) {
            $candidate = $base;
            $candidate[$field] = $username;
            $variants[] = $candidate;
        }
        return $this->dedupePayloadVariants($variants);
    }

    protected function identityFieldsFromRow($row)
    {
        $fields = array();
        if (is_array($row)) {
            foreach (array('name', 'username', 'user', 'login') as $field) {
                if (array_key_exists($field, $row) && trim((string) $row[$field]) !== '') {
                    $fields[] = $field;
                }
            }
        }
        if (empty($fields)) {
            $fields = array('name', 'username', 'user', 'login');
        }
        return array_values(array_unique($fields));
    }

    protected function dedupePayloadVariants($variants)
    {
        $out = array();
        $seen = array();
        foreach ((array) $variants as $variant) {
            if (!is_array($variant) || empty($variant)) {
                continue;
            }
            ksort($variant);
            $key = md5(json_encode($variant));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $variant;
        }
        return $out;
    }

    protected function removeFirstAvailable($menus, $id)
    {
        $last = array('ok' => false, 'message' => 'UM remove failed.');
        foreach ((array) $menus as $menu) {
            $res = $this->selectedRemove($menu, $id);
            if (!empty($res['ok'])) {
                $res['menu'] = $menu;
                return $res;
            }
            $last = $res;
            $msg = strtolower(trim((string) panel_array_get($res, 'message', '')));
            if ($msg !== '' && !$this->isMenuNotFoundMessage($msg)) {
                return $res;
            }
        }
        return $last;
    }

    protected function selectedPrint($menu, $conditions, $proplist)
    {
        if ($this->apiMode === 'internal') {
            return $this->apiPrint($menu, $conditions, $proplist);
        }
        return $this->restPrint($menu, $conditions, $proplist);
    }

    protected function selectedAdd($menu, $payload)
    {
        if ($this->apiMode === 'internal') {
            return $this->apiAdd($menu, $payload);
        }
        return $this->restAdd($menu, $payload);
    }

    protected function selectedSet($menu, $id, $payload)
    {
        if ($this->apiMode === 'internal') {
            return $this->apiSet($menu, $id, $payload);
        }
        return $this->restSet($menu, $id, $payload);
    }

    protected function selectedRemove($menu, $id)
    {
        if ($this->apiMode === 'internal') {
            return $this->apiRemove($menu, $id);
        }
        return $this->restRemove($menu, $id);
    }

    protected function selectedRemoveByPayload($menu, $payload)
    {
        if ($this->apiMode === 'internal') {
            return $this->apiCommand($this->normalizeMenu($menu) . '/remove', (array) $payload, array());
        }
        return $this->restRequest('POST', $this->restMenuPath($menu) . '/remove', (array) $payload);
    }

    protected function selectedCommand($command, $payload)
    {
        if ($this->apiMode === 'internal') {
            return $this->apiCommand($command, (array) $payload, array());
        }
        return $this->restRequest('POST', $this->restBaseUrl . $this->normalizeMenu($command), (array) $payload);
    }

    protected function restPrint($menu, $conditions, $proplist)
    {
        $body = array();
        if (!empty($proplist)) {
            $body['.proplist'] = array_values((array) $proplist);
        }
        if (!empty($conditions)) {
            $body['.query'] = array();
            foreach ((array) $conditions as $key => $value) {
                $body['.query'][] = trim((string) $key) . '=' . (string) $value;
            }
        }
        $result = $this->restRequest('POST', $this->restMenuPath($menu) . '/print', $body);
        if ($result['ok']) {
            $result['data'] = $this->normalizeRows($result['data']);
        }
        return $result;
    }

    protected function restAdd($menu, $payload)
    {
        $attempts = array(
            array('method' => 'PUT', 'url' => $this->restMenuPath($menu), 'body' => $payload),
            array('method' => 'POST', 'url' => $this->restMenuPath($menu) . '/add', 'body' => $payload),
        );
        return $this->restAttemptSequence($attempts);
    }

    protected function restSet($menu, $id, $payload)
    {
        $body = (array) $payload;
        $attempts = array();
        if (trim((string) $id) !== '') {
            $body['.id'] = (string) $id;
            $attempts[] = array('method' => 'PATCH', 'url' => $this->restMenuPath($menu) . '/' . rawurlencode((string) $id), 'body' => $payload);
        }
        $attempts[] = array('method' => 'POST', 'url' => $this->restMenuPath($menu) . '/set', 'body' => $body);
        return $this->restAttemptSequence($attempts);
    }

    protected function restRemove($menu, $id)
    {
        $attempts = array(
            array('method' => 'DELETE', 'url' => $this->restMenuPath($menu) . '/' . rawurlencode((string) $id), 'body' => null),
            array('method' => 'POST', 'url' => $this->restMenuPath($menu) . '/remove', 'body' => array('.id' => (string) $id)),
        );
        return $this->restAttemptSequence($attempts);
    }

    protected function restAttemptSequence($attempts)
    {
        $last = array('ok' => false, 'message' => 'RouterOS REST request failed.');
        foreach ((array) $attempts as $attempt) {
            $last = $this->restRequest($attempt['method'], $attempt['url'], array_key_exists('body', $attempt) ? $attempt['body'] : null);
            if (!empty($last['ok'])) {
                return $last;
            }
            $msg = strtolower(trim((string) panel_array_get($last, 'message', '')));
            if ($msg !== '' && strpos($msg, 'no such command or directory') === false && strpos($msg, 'not acceptable') === false && strpos($msg, 'bad request') === false) {
                return $last;
            }
        }
        return $last;
    }

    protected function restRequest($method, $url, $body)
    {
        if ($this->restBaseUrl === '') {
            $result = array('ok' => false, 'message' => 'UM REST base URL is empty.');
            return $result;
        }
        $method = strtoupper((string) $method);
        $last = array('ok' => false, 'message' => 'RouterOS REST request failed.');
        $attempt = 0;
        while ($attempt < $this->retryAttempts) {
            $attempt++;
            if (function_exists('curl_init')) {
                $last = $this->restRequestCurl($method, $url, $body);
            } else {
                $last = $this->restRequestStream($method, $url, $body);
            }
            if (!empty($last['ok'])) {
                return $last;
            }
            if ($attempt < $this->retryAttempts) {
                usleep(200000 * $attempt);
            }
        }
        return $last;
    }

    protected function restRequestCurl($method, $url, $body)
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return array('ok' => false, 'message' => 'Could not initialize cURL.');
        }
        $headers = array('Accept: application/json');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->verifyPeer);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->verifyHost);
        curl_setopt($ch, CURLOPT_USERAGENT, 'XUI-Reseller-UM/2.0');
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, trim((string) panel_array_get($this->node, 'panel_username', '')) . ':' . (string) panel_array_get($this->node, 'panel_password_plain', ''));
        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }
        if ($body !== null) {
            $payload = json_encode((array) $body, JSON_UNESCAPED_SLASHES);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            $headers[] = 'Content-Type: application/json';
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $err !== '') {
            $this->lastError = $err !== '' ? $err : 'Empty response.';
            return array('ok' => false, 'message' => $this->lastError, 'status_code' => $code);
        }
        return $this->decodeRestResponse($raw, $code);
    }

    protected function restRequestStream($method, $url, $body)
    {
        $headers = array(
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode(trim((string) panel_array_get($this->node, 'panel_username', '')) . ':' . (string) panel_array_get($this->node, 'panel_password_plain', '')),
            'User-Agent: XUI-Reseller-UM/2.0',
        );
        $opts = array(
            'http' => array(
                'method' => $method,
                'timeout' => $this->timeout,
                'ignore_errors' => true,
                'header' => implode("\r\n", $headers),
            ),
        );
        if ($body !== null) {
            $opts['http']['content'] = json_encode((array) $body, JSON_UNESCAPED_SLASHES);
            $opts['http']['header'] .= "\r\nContent-Type: application/json";
        }
        if (stripos($url, 'https://') === 0) {
            $opts['ssl'] = array(
                'verify_peer' => $this->verifyPeer,
                'verify_peer_name' => $this->verifyPeer,
                'allow_self_signed' => !$this->verifyPeer,
            );
        }
        $context = stream_context_create($opts);
        $raw = @file_get_contents($url, false, $context);
        $code = 0;
        if (!empty($http_response_header) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $code = (int) $m[1];
        }
        if ($raw === false) {
            return array('ok' => false, 'message' => 'RouterOS REST request failed.', 'status_code' => $code);
        }
        return $this->decodeRestResponse($raw, $code);
    }

    protected function decodeRestResponse($raw, $code)
    {
        $decoded = json_decode((string) $raw, true);
        if ($code >= 400) {
            $message = is_array($decoded) ? (string) panel_array_get($decoded, 'detail', panel_array_get($decoded, 'message', 'RouterOS REST request failed.')) : 'RouterOS REST request failed.';
            $this->lastError = $message;
            return array('ok' => false, 'message' => $message, 'status_code' => $code, 'data' => is_array($decoded) ? $decoded : null);
        }
        if ($decoded === null && trim((string) $raw) !== '' && strtolower(trim((string) $raw)) !== 'null') {
            return array('ok' => true, 'message' => 'ok', 'status_code' => $code, 'data' => array('raw' => (string) $raw));
        }
        return array('ok' => true, 'message' => 'ok', 'status_code' => $code, 'data' => is_array($decoded) ? $decoded : array());
    }

    protected function apiPrint($menu, $conditions, $proplist)
    {
        $attrs = array();
        if (!empty($proplist)) {
            $attrs['.proplist'] = implode(',', array_values((array) $proplist));
        }
        $queries = array();
        foreach ((array) $conditions as $key => $value) {
            $queries[] = trim((string) $key) . '=' . (string) $value;
        }
        return $this->apiCommand($this->normalizeMenu($menu) . '/print', $attrs, $queries);
    }

    protected function apiAdd($menu, $payload)
    {
        return $this->apiCommand($this->normalizeMenu($menu) . '/add', (array) $payload, array());
    }

    protected function apiSet($menu, $id, $payload)
    {
        $attrs = (array) $payload;
        if (trim((string) $id) !== '') {
            $attrs['.id'] = (string) $id;
        }
        return $this->apiCommand($this->normalizeMenu($menu) . '/set', $attrs, array());
    }

    protected function apiRemove($menu, $id)
    {
        return $this->apiCommand($this->normalizeMenu($menu) . '/remove', array('.id' => (string) $id), array());
    }

    protected function apiCommand($command, $attrs, $queries)
    {
        $normalizedCommand = $this->normalizeMenu($command);
        $login = $this->apiConnectAndLogin();
        if (empty($login['ok']) || empty($login['conn'])) {
            $result = array('ok' => false, 'message' => panel_array_get($login, 'message', 'Could not connect to MikroTik API.'));
            return $result;
        }
        $conn = $login['conn'];
        $words = array($normalizedCommand);
        foreach ((array) $attrs as $key => $value) {
            $words[] = '=' . $key . '=' . $this->apiScalar($value);
        }
        foreach ((array) $queries as $queryWord) {
            $queryWord = trim((string) $queryWord);
            if ($queryWord !== '') {
                $words[] = '?' . $queryWord;
            }
        }
        $write = $this->apiWriteSentence($conn, $words);
        if (!$write['ok']) {
            @fclose($conn);
            return $write;
        }
        $result = $this->apiReadResult($conn);
        @fclose($conn);
        return $result;
    }

    protected function apiConnectAndLogin()
    {
        $conn = $this->apiOpenSocket();
        if (!$conn) {
            return array('ok' => false, 'message' => $this->lastError !== '' ? $this->lastError : 'Could not open MikroTik API socket.');
        }
        $login = $this->apiLoginPost643($conn);
        if (!empty($login['ok'])) {
            return array('ok' => true, 'conn' => $conn);
        }
        @fclose($conn);

        $conn = $this->apiOpenSocket();
        if (!$conn) {
            return array('ok' => false, 'message' => $this->lastError !== '' ? $this->lastError : 'Could not open MikroTik API socket.');
        }
        $legacy = $this->apiLoginLegacy($conn);
        if (!empty($legacy['ok'])) {
            return array('ok' => true, 'conn' => $conn);
        }
        @fclose($conn);
        return array('ok' => false, 'message' => panel_array_get($legacy, 'message', panel_array_get($login, 'message', 'MikroTik API login failed.')));
    }

    protected function apiOpenSocket()
    {
        if ($this->internalHost === '') {
            $this->lastError = 'UM internal API host is empty.';
            return false;
        }
        $port = $this->internalPort > 0 ? $this->internalPort : ($this->internalSsl ? 8729 : 8728);
        $transport = ($this->internalSsl ? 'ssl' : 'tcp') . '://' . $this->internalHost . ':' . $port;
        $options = array();
        if ($this->internalSsl) {
            $ssl = array(
                'verify_peer' => $this->verifyPeer,
                'verify_peer_name' => $this->verifyPeer,
                'allow_self_signed' => !$this->verifyPeer,
                'SNI_enabled' => true,
            );
            if (!$this->verifyPeer) {
                $ssl['ciphers'] = 'DEFAULT:@SECLEVEL=0';
            }
            $options['ssl'] = $ssl;
        }
        $context = stream_context_create($options);
        $errno = 0;
        $errstr = '';
        $conn = @stream_socket_client($transport, $errno, $errstr, $this->connectTimeout, STREAM_CLIENT_CONNECT, $context);
        if ($conn === false && $this->internalSsl && !$this->verifyPeer) {
            $options['ssl']['ciphers'] = 'ADH:@SECLEVEL=0';
            $context = stream_context_create($options);
            $conn = @stream_socket_client($transport, $errno, $errstr, $this->connectTimeout, STREAM_CLIENT_CONNECT, $context);
        }
        if ($conn === false) {
            $this->lastError = trim($errstr) !== '' ? trim($errstr) : ('Could not connect to ' . $transport . '.');
            return false;
        }
        stream_set_timeout($conn, $this->timeout);
        return $conn;
    }

    protected function apiLoginPost643($conn)
    {
        $username = trim((string) panel_array_get($this->node, 'panel_username', ''));
        $password = (string) panel_array_get($this->node, 'panel_password_plain', '');
        $write = $this->apiWriteSentence($conn, array('/login', '=name=' . $username, '=password=' . $password));
        if (!$write['ok']) {
            return $write;
        }
        $result = $this->apiReadResult($conn);
        if (!empty($result['ok'])) {
            return array('ok' => true);
        }
        return array('ok' => false, 'message' => panel_array_get($result, 'message', 'MikroTik API login failed.'));
    }

    protected function apiLoginLegacy($conn)
    {
        $username = trim((string) panel_array_get($this->node, 'panel_username', ''));
        $password = (string) panel_array_get($this->node, 'panel_password_plain', '');
        $write = $this->apiWriteSentence($conn, array('/login'));
        if (!$write['ok']) {
            return $write;
        }
        $challenge = $this->apiReadResult($conn);
        $ret = trim((string) panel_array_get($challenge, 'ret', ''));
        if ($ret === '') {
            return array('ok' => false, 'message' => panel_array_get($challenge, 'message', 'Legacy MikroTik API challenge failed.'));
        }
        $response = '00' . md5(chr(0) . $password . pack('H*', $ret));
        $write = $this->apiWriteSentence($conn, array('/login', '=name=' . $username, '=response=' . $response));
        if (!$write['ok']) {
            return $write;
        }
        $result = $this->apiReadResult($conn);
        if (!empty($result['ok'])) {
            return array('ok' => true);
        }
        return array('ok' => false, 'message' => panel_array_get($result, 'message', 'Legacy MikroTik API login failed.'));
    }

    protected function apiWriteSentence($conn, $words)
    {
        foreach ((array) $words as $word) {
            if (!$this->apiWriteWord($conn, (string) $word)) {
                return array('ok' => false, 'message' => 'Could not write MikroTik API sentence.');
            }
        }
        if (!$this->apiWriteWord($conn, '')) {
            return array('ok' => false, 'message' => 'Could not terminate MikroTik API sentence.');
        }
        return array('ok' => true);
    }

    protected function apiWriteWord($conn, $word)
    {
        $payload = $this->apiEncodeLength(strlen($word)) . $word;
        $written = 0;
        $length = strlen($payload);
        while ($written < $length) {
            $chunk = @fwrite($conn, substr($payload, $written));
            if ($chunk === false || $chunk === 0) {
                return false;
            }
            $written += $chunk;
        }
        return true;
    }

    protected function apiEncodeLength($length)
    {
        $length = (int) $length;
        if ($length < 0x80) {
            return chr($length);
        }
        if ($length < 0x4000) {
            $length |= 0x8000;
            return chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        }
        if ($length < 0x200000) {
            $length |= 0xC00000;
            return chr(($length >> 16) & 0xFF) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        }
        if ($length < 0x10000000) {
            $length |= 0xE0000000;
            return chr(($length >> 24) & 0xFF) . chr(($length >> 16) & 0xFF) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        }
        return chr(0xF0) . chr(($length >> 24) & 0xFF) . chr(($length >> 16) & 0xFF) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
    }

    protected function apiReadResult($conn)
    {
        $rows = array();
        $done = array();
        $errorMessage = '';
        $ret = '';
        while (!feof($conn)) {
            $sentence = $this->apiReadSentence($conn);
            if ($sentence === false) {
                break;
            }
            if (empty($sentence)) {
                continue;
            }
            $kind = $sentence[0];
            $assoc = $this->apiSentenceAssoc(array_slice($sentence, 1));
            if ($kind === '!re') {
                $rows[] = $assoc;
                continue;
            }
            if ($kind === '!trap' || $kind === '!fatal') {
                $msg = trim((string) panel_array_get($assoc, 'message', 'RouterOS API trap.'));
                if ($msg !== '') {
                    $errorMessage = $msg;
                }
                if ($kind === '!fatal') {
                    break;
                }
                continue;
            }
            if ($kind === '!done') {
                $done = $assoc;
                if (isset($assoc['ret'])) {
                    $ret = (string) $assoc['ret'];
                }
                break;
            }
        }
        if ($errorMessage !== '') {
            $this->lastError = $errorMessage;
            return array('ok' => false, 'message' => $errorMessage, 'data' => $rows, 'ret' => $ret);
        }
        return array('ok' => true, 'message' => 'ok', 'data' => $rows, 'done' => $done, 'ret' => $ret);
    }

    protected function apiReadSentence($conn)
    {
        $words = array();
        while (true) {
            $len = $this->apiReadLength($conn);
            if ($len === false) {
                return false;
            }
            if ($len === 0) {
                break;
            }
            $word = '';
            $remaining = $len;
            while ($remaining > 0) {
                $chunk = @fread($conn, $remaining);
                if ($chunk === false || $chunk === '') {
                    $meta = stream_get_meta_data($conn);
                    if (!empty($meta['timed_out'])) {
                        $this->lastError = 'Timed out while reading MikroTik API response.';
                    }
                    return false;
                }
                $word .= $chunk;
                $remaining -= strlen($chunk);
            }
            $words[] = $word;
        }
        return $words;
    }

    protected function apiReadLength($conn)
    {
        $c = @fread($conn, 1);
        if ($c === false || $c === '') {
            return false;
        }
        $b = ord($c);
        if (($b & 0x80) === 0x00) {
            return $b;
        }
        if (($b & 0xC0) === 0x80) {
            $c2 = @fread($conn, 1);
            if ($c2 === false || $c2 === '') { return false; }
            return (($b & ~0xC0) << 8) + ord($c2);
        }
        if (($b & 0xE0) === 0xC0) {
            $rest = @fread($conn, 2);
            if ($rest === false || strlen($rest) !== 2) { return false; }
            return (($b & ~0xE0) << 16) + (ord($rest[0]) << 8) + ord($rest[1]);
        }
        if (($b & 0xF0) === 0xE0) {
            $rest = @fread($conn, 3);
            if ($rest === false || strlen($rest) !== 3) { return false; }
            return (($b & ~0xF0) << 24) + (ord($rest[0]) << 16) + (ord($rest[1]) << 8) + ord($rest[2]);
        }
        if (($b & 0xF8) === 0xF0) {
            $rest = @fread($conn, 4);
            if ($rest === false || strlen($rest) !== 4) { return false; }
            return (ord($rest[0]) << 24) + (ord($rest[1]) << 16) + (ord($rest[2]) << 8) + ord($rest[3]);
        }
        return false;
    }

    protected function apiSentenceAssoc($words)
    {
        $row = array();
        foreach ((array) $words as $word) {
            if ($word === '' || $word[0] !== '=') {
                continue;
            }
            $trimmed = substr($word, 1);
            $pos = strpos($trimmed, '=');
            if ($pos === false) {
                $row[$trimmed] = '';
                continue;
            }
            $key = substr($trimmed, 0, $pos);
            $value = substr($trimmed, $pos + 1);
            $row[$key] = $value;
        }
        return $row;
    }

    protected function restMenuPath($menu)
    {
        return $this->restBaseUrl . $this->normalizeMenu($menu);
    }

    protected function normalizeMenu($menu)
    {
        $menu = '/' . ltrim(trim((string) $menu), '/');
        return rtrim($menu, '/');
    }

    protected function normalizeRows($data)
    {
        if (is_array($data) && isset($data[0]) && is_array($data[0])) {
            return $data;
        }
        if (is_array($data) && !empty($data)) {
            $isAssoc = false;
            foreach (array_keys($data) as $key) {
                if (!is_int($key)) {
                    $isAssoc = true;
                    break;
                }
            }
            if ($isAssoc) {
                return array($data);
            }
        }
        return array();
    }

    protected function firstRow($rows)
    {
        return (is_array($rows) && !empty($rows[0]) && is_array($rows[0])) ? $rows[0] : array();
    }

    protected function byteValue($value)
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        $value = trim((string) $value);
        if ($value === '') {
            return 0.0;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }
        if (preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*(B|KiB|MiB|GiB|TiB|KB|MB|GB|TB)$/i', $value, $m)) {
            $num = (float) $m[1];
            $unit = strtoupper($m[2]);
            $map = array('B' => 1, 'KIB' => 1024, 'MIB' => 1048576, 'GIB' => 1073741824, 'TIB' => 1099511627776, 'KB' => 1000, 'MB' => 1000000, 'GB' => 1000000000, 'TB' => 1000000000000);
            return isset($map[$unit]) ? ($num * $map[$unit]) : $num;
        }
        return 0.0;
    }

    protected function apiScalar($value)
    {
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }
        if ($value === null) {
            return '';
        }
        if (is_array($value)) {
            return implode(',', $value);
        }
        return (string) $value;
    }

    protected function modeLabel()
    {
        if ($this->apiMode === 'internal') {
            return $this->internalSsl ? 'Internal API-SSL' : 'Internal API';
        }
        $scheme = '';
        if ($this->baseUrl !== '') {
            $parts = @parse_url($this->baseUrl);
            if (is_array($parts) && !empty($parts['scheme'])) {
                $scheme = strtolower((string) $parts['scheme']);
            }
        }
        return $scheme === 'http' ? 'REST HTTP' : 'REST HTTPS';
    }

}
