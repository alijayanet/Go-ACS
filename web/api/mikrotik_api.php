<?php
/**
 * MikroTik API Endpoint
 * For ACS-Lite ISP Billing Integration
 * 
 * Endpoints:
 * - GET  ?action=test         - Test connection to router
 * - GET  ?action=profiles     - Get all PPPoE profiles
 * - GET  ?action=secrets      - Get all PPPoE users
 * - GET  ?action=active       - Get active connections
 * - POST action=isolir        - Isolir user (change to isolir profile)
 * - POST action=unisolir      - Un-isolir user (change to normal profile)
 * - POST action=change_profile - Change user profile
 * - POST action=disconnect    - Disconnect active session
 * - POST action=add_user      - Add new PPPoE user
 * - POST action=delete_user   - Delete PPPoE user
 * - POST action=save_config   - Save MikroTik configuration
 */

ini_set('display_errors', 0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/MikroTikAPI.php';

// ========================================
// CONFIGURATION
// ========================================
$CONFIG_FILE = __DIR__ . '/../data/mikrotik.json';

function loadConfig() {
    global $CONFIG_FILE;
    
    if (file_exists($CONFIG_FILE)) {
        $content = file_get_contents($CONFIG_FILE);
        return json_decode($content, true) ?: [];
    }
    
    // Default config
    return [
        'routers' => [
            [
                'id' => 'router1',
                'name' => 'Main Router',
                'ip' => '192.168.88.1',
                'port' => 8728,
                'username' => 'admin',
                'password' => '',
                'isolir_profile' => 'isolir',
                'default_profile' => 'default'
            ]
        ]
    ];
}

function saveConfig($config) {
    global $CONFIG_FILE;
    file_put_contents($CONFIG_FILE, json_encode($config, JSON_PRETTY_PRINT));
}

function jsonResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

function getRouter($routerId = null) {
    $config = loadConfig();
    
    if (!isset($config['routers']) || empty($config['routers'])) {
        return null;
    }
    
    if ($routerId) {
        foreach ($config['routers'] as $router) {
            if ($router['id'] === $routerId) {
                return $router;
            }
        }
        return null;
    }
    
    // Return first router
    return $config['routers'][0];
}

function connectToRouter($routerId = null) {
    $router = getRouter($routerId);
    
    if (!$router) {
        return ['error' => 'No router configured'];
    }
    
    $api = new MikroTikAPI();
    
    if (!$api->connect($router['ip'], $router['username'], $router['password'], $router['port'])) {
        return ['error' => 'Connection failed: ' . $api->getError()];
    }
    
    return ['api' => $api, 'router' => $router];
}

// ========================================
// MAIN HANDLER
// ========================================
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$routerId = $_GET['router'] ?? null;

// Parse JSON body for POST
$input = [];
if ($method === 'POST') {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true) ?: $_POST;
    $action = $input['action'] ?? $action;
    $routerId = $input['router'] ?? $routerId;
}

try {
    switch ($action) {
        // ---- GET CONFIG ----
        case 'config':
            $config = loadConfig();
            // Hide passwords
            foreach ($config['routers'] as &$r) {
                $r['password'] = $r['password'] ? '********' : '';
            }
            jsonResponse(['success' => true, 'config' => $config]);
            break;
            
        // ---- SAVE CONFIG ----
        case 'save_config':
            if (empty($input['routers'])) {
                jsonResponse(['success' => false, 'error' => 'No routers data'], 400);
            }
            
            $config = loadConfig();
            
            foreach ($input['routers'] as $newRouter) {
                $found = false;
                foreach ($config['routers'] as &$existingRouter) {
                    if ($existingRouter['id'] === $newRouter['id']) {
                        // Update existing, keep password if not changed
                        if ($newRouter['password'] === '********' || $newRouter['password'] === '') {
                            $newRouter['password'] = $existingRouter['password'];
                        }
                        $existingRouter = array_merge($existingRouter, $newRouter);
                        $found = true;
                        break;
                    }
                }
                
                if (!$found) {
                    $newRouter['id'] = $newRouter['id'] ?? 'router_' . time();
                    $config['routers'][] = $newRouter;
                }
            }
            
            saveConfig($config);
            jsonResponse(['success' => true, 'message' => 'Configuration saved']);
            break;
            
        // ---- TEST CONNECTION ----
        case 'test':
            $result = connectToRouter($routerId);
            
            if (isset($result['error'])) {
                jsonResponse(['success' => false, 'error' => $result['error']], 500);
            }
            
            $api = $result['api'];
            $router = $result['router'];
            
            $identity = $api->getIdentity();
            $resource = $api->getResource();
            
            $api->disconnect();
            
            jsonResponse([
                'success' => true,
                'message' => 'Connected successfully',
                'router' => [
                    'name' => $router['name'],
                    'ip' => $router['ip'],
                    'identity' => $identity,
                    'version' => $resource['=version'] ?? '',
                    'uptime' => $resource['=uptime'] ?? '',
                    'cpu_load' => $resource['=cpu-load'] ?? '',
                    'memory_used' => $resource['=total-memory'] ?? ''
                ]
            ]);
            break;
            
        // ---- GET PROFILES ----
        case 'profiles':
            $result = connectToRouter($routerId);
            if (isset($result['error'])) {
                jsonResponse(['success' => false, 'error' => $result['error']], 500);
            }
            
            $profiles = $result['api']->getPPPoEProfiles();
            $result['api']->disconnect();
            
            jsonResponse(['success' => true, 'profiles' => $profiles, 'count' => count($profiles)]);
            break;
            
        // ---- GET SECRETS (PPPoE Users) ----
        case 'secrets':
            $result = connectToRouter($routerId);
            if (isset($result['error'])) {
                jsonResponse(['success' => false, 'error' => $result['error']], 500);
            }
            
            $secrets = $result['api']->getPPPoESecrets();
            $result['api']->disconnect();
            
            // Hide passwords
            foreach ($secrets as &$s) {
                $s['password'] = '********';
            }
            
            jsonResponse(['success' => true, 'secrets' => $secrets, 'count' => count($secrets)]);
            break;
            
        // ---- GET ACTIVE CONNECTIONS ----
        case 'active':
            $result = connectToRouter($routerId);
            if (isset($result['error'])) {
                jsonResponse(['success' => false, 'error' => $result['error']], 500);
            }
            
            $active = $result['api']->getPPPoEActive();
            $result['api']->disconnect();
            
            jsonResponse(['success' => true, 'active' => $active, 'count' => count($active)]);
            break;
            
        // ---- ISOLIR USER ----
        case 'isolir':
            if (empty($input['username'])) {
                jsonResponse(['success' => false, 'error' => 'Username required'], 400);
            }
            
            $result = connectToRouter($routerId);
            if (isset($result['error'])) {
                jsonResponse(['success' => false, 'error' => $result['error']], 500);
            }
            
            $api = $result['api'];
            $router = $result['router'];
            $isolirProfile = $input['profile'] ?? $router['isolir_profile'] ?? 'isolir';
            
            $success = $api->isolirUser($input['username'], $isolirProfile);
            $error = $api->getError();
            $api->disconnect();
            
            if ($success) {
                jsonResponse(['success' => true, 'message' => "User '{$input['username']}' isolated to profile '$isolirProfile'"]);
            } else {
                jsonResponse(['success' => false, 'error' => $error], 500);
            }
            break;
            
        // ---- UN-ISOLIR USER ----
        case 'unisolir':
            if (empty($input['username'])) {
                jsonResponse(['success' => false, 'error' => 'Username required'], 400);
            }
            
            $result = connectToRouter($routerId);
            if (isset($result['error'])) {
                jsonResponse(['success' => false, 'error' => $result['error']], 500);
            }
            
            $api = $result['api'];
            $router = $result['router'];
            $normalProfile = $input['profile'] ?? $router['default_profile'] ?? 'default';
            
            $success = $api->unIsolirUser($input['username'], $normalProfile);
            $error = $api->getError();
            $api->disconnect();
            
            if ($success) {
                jsonResponse(['success' => true, 'message' => "User '{$input['username']}' restored to profile '$normalProfile'"]);
            } else {
                jsonResponse(['success' => false, 'error' => $error], 500);
            }
            break;
            
        // ---- CHANGE PROFILE ----
        case 'change_profile':
            if (empty($input['username']) || empty($input['profile'])) {
                jsonResponse(['success' => false, 'error' => 'Username and profile required'], 400);
            }
            
            $result = connectToRouter($routerId);
            if (isset($result['error'])) {
                jsonResponse(['success' => false, 'error' => $result['error']], 500);
            }
            
            $api = $result['api'];
            $success = $api->changeProfile($input['username'], $input['profile']);
            $error = $api->getError();
            $api->disconnect();
            
            if ($success) {
                jsonResponse(['success' => true, 'message' => "Profile changed to '{$input['profile']}'"]);
            } else {
                jsonResponse(['success' => false, 'error' => $error], 500);
            }
            break;
            
        // ---- DISCONNECT USER ----
        case 'disconnect':
            if (empty($input['username'])) {
                jsonResponse(['success' => false, 'error' => 'Username required'], 400);
            }
            
            $result = connectToRouter($routerId);
            if (isset($result['error'])) {
                jsonResponse(['success' => false, 'error' => $result['error']], 500);
            }
            
            $api = $result['api'];
            $success = $api->disconnectUser($input['username']);
            $api->disconnect();
            
            jsonResponse(['success' => true, 'message' => "User '{$input['username']}' disconnected"]);
            break;
            
        // ---- ADD USER ----
        case 'add_user':
            if (empty($input['username']) || empty($input['password'])) {
                jsonResponse(['success' => false, 'error' => 'Username and password required'], 400);
            }
            
            $result = connectToRouter($routerId);
            if (isset($result['error'])) {
                jsonResponse(['success' => false, 'error' => $result['error']], 500);
            }
            
            $api = $result['api'];
            $profile = $input['profile'] ?? 'default';
            $comment = $input['comment'] ?? '';
            
            $success = $api->addPPPoEUser($input['username'], $input['password'], $profile, $comment);
            $error = $api->getError();
            $api->disconnect();
            
            if ($success) {
                jsonResponse(['success' => true, 'message' => "User '{$input['username']}' added"]);
            } else {
                jsonResponse(['success' => false, 'error' => $error ?: 'Failed to add user'], 500);
            }
            break;
            
        // ---- DELETE USER ----
        case 'delete_user':
            if (empty($input['username'])) {
                jsonResponse(['success' => false, 'error' => 'Username required'], 400);
            }
            
            $result = connectToRouter($routerId);
            if (isset($result['error'])) {
                jsonResponse(['success' => false, 'error' => $result['error']], 500);
            }
            
            $api = $result['api'];
            $success = $api->deletePPPoEUser($input['username']);
            $error = $api->getError();
            $api->disconnect();
            
            if ($success) {
                jsonResponse(['success' => true, 'message' => "User '{$input['username']}' deleted"]);
            } else {
                jsonResponse(['success' => false, 'error' => $error], 500);
            }
            break;
            
        // ========================================
        // HOTSPOT ENDPOINTS
        // ========================================
        
        // ---- GET HOTSPOT ACTIVE USERS ----
        case 'hotspot_active':
            $result = connectToRouter($routerId);
            if (isset($result['error'])) {
                jsonResponse(['success' => false, 'error' => $result['error']], 500);
            }
            
            $active = $result['api']->getHotspotActive();
            $result['api']->disconnect();
            
            jsonResponse(['success' => true, 'active' => $active, 'count' => count($active)]);
            break;
            
        // ---- GET HOTSPOT USERS ----
        case 'hotspot_users':
            $result = connectToRouter($routerId);
            if (isset($result['error'])) {
                jsonResponse(['success' => false, 'error' => $result['error']], 500);
            }
            
            $users = $result['api']->getHotspotUsers();
            $result['api']->disconnect();
            
            // Hide passwords
            foreach ($users as &$u) {
                $u['password'] = '********';
            }
            
            jsonResponse(['success' => true, 'users' => $users, 'count' => count($users)]);
            break;
            
        // ---- GET HOTSPOT PROFILES ----
        case 'hotspot_profiles':
            $result = connectToRouter($routerId);
            if (isset($result['error'])) {
                jsonResponse(['success' => false, 'error' => $result['error']], 500);
            }
            
            $profiles = $result['api']->getHotspotProfiles();
            $result['api']->disconnect();
            
            jsonResponse(['success' => true, 'profiles' => $profiles, 'count' => count($profiles)]);
            break;
            
        // ---- ADD HOTSPOT USER ----
        case 'add_hotspot_user':
            if (empty($input['username']) || empty($input['password'])) {
                jsonResponse(['success' => false, 'error' => 'Username and password required'], 400);
            }
            
            $result = connectToRouter($routerId);
            if (isset($result['error'])) {
                jsonResponse(['success' => false, 'error' => $result['error']], 500);
            }
            
            $api = $result['api'];
            $profile = $input['profile'] ?? 'default';
            $mac = $input['mac'] ?? '';
            $uptime = $input['uptime'] ?? '';
            $comment = $input['comment'] ?? '';
            
            $success = $api->addHotspotUser(
                $input['username'], 
                $input['password'], 
                $profile, 
                $mac, 
                $uptime, 
                $comment
            );
            $error = $api->getError();
            $api->disconnect();
            
            if ($success) {
                jsonResponse(['success' => true, 'message' => "Hotspot user '{$input['username']}' added"]);
            } else {
                jsonResponse(['success' => false, 'error' => $error ?: 'Failed to add hotspot user'], 500);
            }
            break;
            
        // ---- DISCONNECT HOTSPOT USER ----
        case 'disconnect_hotspot':
            if (empty($input['username'])) {
                jsonResponse(['success' => false, 'error' => 'Username required'], 400);
            }
            
            $result = connectToRouter($routerId);
            if (isset($result['error'])) {
                jsonResponse(['success' => false, 'error' => $result['error']], 500);
            }
            
            $api = $result['api'];
            $success = $api->disconnectHotspotUser($input['username']);
            $api->disconnect();
            
            jsonResponse(['success' => true, 'message' => "Hotspot user '{$input['username']}' disconnected"]);
            break;
            
        // ---- DISABLE HOTSPOT USER ----
        case 'disable_hotspot_user':
            if (empty($input['username'])) {
                jsonResponse(['success' => false, 'error' => 'Username required'], 400);
            }
            
            $result = connectToRouter($routerId);
            if (isset($result['error'])) {
                jsonResponse(['success' => false, 'error' => $result['error']], 500);
            }
            
            $api = $result['api'];
            $success = $api->setHotspotUserStatus($input['username'], true); // true = disabled
            $error = $api->getError();
            $api->disconnect();
            
            if ($success) {
                jsonResponse(['success' => true, 'message' => "Hotspot user '{$input['username']}' disabled"]);
            } else {
                jsonResponse(['success' => false, 'error' => $error], 500);
            }
            break;
            
        // ---- ENABLE HOTSPOT USER ----
        case 'enable_hotspot_user':
            if (empty($input['username'])) {
                jsonResponse(['success' => false, 'error' => 'Username required'], 400);
            }
            
            $result = connectToRouter($routerId);
            if (isset($result['error'])) {
                jsonResponse(['success' => false, 'error' => $result['error']], 500);
            }
            
            $api = $result['api'];
            $success = $api->setHotspotUserStatus($input['username'], false); // false = enabled
            $error = $api->getError();
            $api->disconnect();
            
            if ($success) {
                jsonResponse(['success' => true, 'message' => "Hotspot user '{$input['username']}' enabled"]);
            } else {
                jsonResponse(['success' => false, 'error' => $error], 500);
            }
            break;
            
        // ---- DELETE HOTSPOT USER ----
        case 'delete_hotspot_user':
            if (empty($input['username'])) {
                jsonResponse(['success' => false, 'error' => 'Username required'], 400);
            }
            
            $result = connectToRouter($routerId);
            if (isset($result['error'])) {
                jsonResponse(['success' => false, 'error' => $result['error']], 500);
            }
            
            $api = $result['api'];
            $success = $api->deleteHotspotUser($input['username']);
            $error = $api->getError();
            $api->disconnect();
            
            if ($success) {
                jsonResponse(['success' => true, 'message' => "Hotspot user '{$input['username']}' deleted"]);
            } else {
                jsonResponse(['success' => false, 'error' => $error], 500);
            }
            break;
            
        // ========================================
        // HOTSPOT PROFILE ENDPOINTS
        // ========================================
        
        // ---- GET HOTSPOT SERVER PROFILES ----
        case 'hotspot_server_profiles':
            $result = connectToRouter($routerId);
            if (isset($result['error'])) {
                jsonResponse(['success' => false, 'error' => $result['error']], 500);
            }
            
            $profiles = $result['api']->getHotspotServerProfiles();
            $result['api']->disconnect();
            
            jsonResponse(['success' => true, 'profiles' => $profiles, 'count' => count($profiles)]);
            break;
            
        // ---- ADD HOTSPOT USER PROFILE ----
        case 'add_hotspot_profile':
            if (empty($input['name'])) {
                jsonResponse(['success' => false, 'error' => 'Profile name required'], 400);
            }
            
            $result = connectToRouter($routerId);
            if (isset($result['error'])) {
                jsonResponse(['success' => false, 'error' => $result['error']], 500);
            }
            
            $api = $result['api'];
            $success = $api->addHotspotUserProfile(
                $input['name'],
                $input['rate_limit'] ?? '',
                $input['session_timeout'] ?? '',
                $input['idle_timeout'] ?? '',
                $input['shared_users'] ?? '1',
                $input['keepalive_timeout'] ?? '',
                $input['status_autorefresh'] ?? '',
                $input['on_login'] ?? ''  // Pass on-login script
            );
            $error = $api->getError();
            $api->disconnect();
            
            if ($success) {
                jsonResponse(['success' => true, 'message' => "Hotspot profile '{$input['name']}' added"]);
            } else {
                jsonResponse(['success' => false, 'error' => $error ?: 'Failed to add profile'], 500);
            }
            break;
            
        // ---- DELETE HOTSPOT USER PROFILE ----
        case 'delete_hotspot_profile':
            if (empty($input['name'])) {
                jsonResponse(['success' => false, 'error' => 'Profile name required'], 400);
            }
            
            $result = connectToRouter($routerId);
            if (isset($result['error'])) {
                jsonResponse(['success' => false, 'error' => $result['error']], 500);
            }
            
            $api = $result['api'];
            $success = $api->deleteHotspotUserProfile($input['name']);
            $error = $api->getError();
            $api->disconnect();
            
            if ($success) {
                jsonResponse(['success' => true, 'message' => "Hotspot profile '{$input['name']}' deleted"]);
            } else {
                jsonResponse(['success' => false, 'error' => $error], 500);
            }
            break;
            
        // ---- ADD HOTSPOT SERVER PROFILE ----
        case 'add_server_profile':
            if (empty($input['name'])) {
                jsonResponse(['success' => false, 'error' => 'Profile name required'], 400);
            }
            
            $result = connectToRouter($routerId);
            if (isset($result['error'])) {
                jsonResponse(['success' => false, 'error' => $result['error']], 500);
            }
            
            $api = $result['api'];
            $success = $api->addHotspotServerProfile(
                $input['name'],
                $input['html_directory'] ?? 'hotspot',
                $input['dns_name'] ?? '',
                $input['login_by'] ?? 'http-chap',
                $input['smtp_server'] ?? '0.0.0.0',
                $input['split_user_domain'] ?? 'no',
                $input['rate_limit'] ?? ''
            );
            $error = $api->getError();
            $api->disconnect();
            
            if ($success) {
                jsonResponse(['success' => true, 'message' => "Server profile '{$input['name']}' added"]);
            } else {
                jsonResponse(['success' => false, 'error' => $error ?: 'Failed to add server profile'], 500);
            }
            break;
            
        // ---- DELETE HOTSPOT SERVER PROFILE ----
        case 'delete_server_profile':
            if (empty($input['name'])) {
                jsonResponse(['success' => false, 'error' => 'Profile name required'], 400);
            }
            
            $result = connectToRouter($routerId);
            if (isset($result['error'])) {
                jsonResponse(['success' => false, 'error' => $result['error']], 500);
            }
            
            $api = $result['api'];
            $success = $api->deleteHotspotServerProfile($input['name']);
            $error = $api->getError();
            $api->disconnect();
            
            if ($success) {
                jsonResponse(['success' => true, 'message' => "Server profile '{$input['name']}' deleted"]);
            } else {
                jsonResponse(['success' => false, 'error' => $error], 500);
            }
            break;
            
        // ========================================
        // PPPOE PROFILE ENDPOINTS
        // ========================================
        
        // ---- ADD PPPOE PROFILE ----
        case 'add_pppoe_profile':
            if (empty($input['name'])) {
                jsonResponse(['success' => false, 'error' => 'Profile name required'], 400);
            }
            
            $result = connectToRouter($routerId);
            if (isset($result['error'])) {
                jsonResponse(['success' => false, 'error' => $result['error']], 500);
            }
            
            $api = $result['api'];
            $success = $api->addPPPoEProfile(
                $input['name'],
                $input['rate_limit'] ?? '',
                $input['local_address'] ?? '',
                $input['remote_address'] ?? '',
                $input['dns_server'] ?? '',
                $input['session_timeout'] ?? ''
            );
            $error = $api->getError();
            $api->disconnect();
            
            if ($success) {
                jsonResponse(['success' => true, 'message' => "PPPoE profile '{$input['name']}' added"]);
            } else {
                jsonResponse(['success' => false, 'error' => $error ?: 'Failed to add profile'], 500);
            }
            break;
            
        // ---- DELETE PPPOE PROFILE ----
        case 'delete_pppoe_profile':
            if (empty($input['name'])) {
                jsonResponse(['success' => false, 'error' => 'Profile name required'], 400);
            }
            
            $result = connectToRouter($routerId);
            if (isset($result['error'])) {
                jsonResponse(['success' => false, 'error' => $result['error']], 500);
            }
            
            $api = $result['api'];
            $success = $api->deletePPPoEProfile($input['name']);
            $error = $api->getError();
            $api->disconnect();
            
            if ($success) {
                jsonResponse(['success' => true, 'message' => "PPPoE profile '{$input['name']}' deleted"]);
            } else {
                jsonResponse(['success' => false, 'error' => $error], 500);
            }
            break;
            
        // ========================================
        // ADDITIONAL TELEGRAM BOT ENDPOINTS
        // ========================================
        
        // ---- ADD SECRET (alias for add_user) ----
        case 'add_secret':
            if (empty($input['name']) || empty($input['password'])) {
                jsonResponse(['success' => false, 'error' => 'Name and password required'], 400);
            }
            
            $result = connectToRouter($routerId);
            if (isset($result['error'])) {
                jsonResponse(['success' => false, 'error' => $result['error']], 500);
            }
            
            $api = $result['api'];
            $profile = $input['profile'] ?? 'default';
            $comment = $input['comment'] ?? '';
            
            $success = $api->addPPPoEUser($input['name'], $input['password'], $profile, $comment);
            $error = $api->getError();
            $api->disconnect();
            
            if ($success) {
                jsonResponse(['success' => true, 'message' => "PPPoE user '{$input['name']}' added with profile '$profile'"]);
            } else {
                jsonResponse(['success' => false, 'error' => $error ?: 'Failed to add user'], 500);
            }
            break;
            
        // ---- DELETE SECRET (alias for delete_user) ----
        case 'delete_secret':
            if (empty($input['name'])) {
                jsonResponse(['success' => false, 'error' => 'Username required'], 400);
            }
            
            $result = connectToRouter($routerId);
            if (isset($result['error'])) {
                jsonResponse(['success' => false, 'error' => $result['error']], 500);
            }
            
            $api = $result['api'];
            $success = $api->deletePPPoEUser($input['name']);
            $error = $api->getError();
            $api->disconnect();
            
            if ($success) {
                jsonResponse(['success' => true, 'message' => "PPPoE user '{$input['name']}' deleted"]);
            } else {
                jsonResponse(['success' => false, 'error' => $error], 500);
            }
            break;
            
        // ---- GENERATE VOUCHER ----
        case 'generate_voucher':
            if (empty($input['profile'])) {
                jsonResponse(['success' => false, 'error' => 'Profile required'], 400);
            }
            
            $result = connectToRouter($routerId);
            if (isset($result['error'])) {
                jsonResponse(['success' => false, 'error' => $result['error']], 500);
            }
            
            $api = $result['api'];
            $profile = $input['profile'];
            $count = min(max(intval($input['count'] ?? 1), 1), 50); // 1-50 vouchers
            $length = intval($input['length'] ?? 5); // default 5 digit
            $prefix = $input['prefix'] ?? '';
            $comment = 'vc-Go-acs-' . date('Y-m-d');
            
            $vouchers = [];
            $chars = '0123456789'; // HANYA ANGKA 5 digit
            
            for ($i = 0; $i < $count; $i++) {
                // Generate random voucher code (angka saja)
                $code = $prefix;
                for ($j = 0; $j < $length; $j++) {
                    $code .= $chars[random_int(0, strlen($chars) - 1)];
                }
                
                // Add hotspot user with this voucher code
                $success = $api->addHotspotUser(
                    $code,           // username = voucher code
                    $code,           // password = voucher code (same)
                    $profile,        // profile
                    '',              // mac
                    '',              // uptime limit
                    $comment         // comment: vc-Go-acs-tanggal
                );
                
                if ($success) {
                    $vouchers[] = $code;
                }
            }
            
            $api->disconnect();
            
            if (count($vouchers) > 0) {
                jsonResponse([
                    'success' => true, 
                    'message' => count($vouchers) . " voucher(s) generated for profile '$profile'",
                    'profile' => $profile,
                    'count' => count($vouchers),
                    'comment' => $comment,
                    'vouchers' => $vouchers
                ]);
            } else {
                jsonResponse(['success' => false, 'error' => 'Failed to generate vouchers'], 500);
            }
            break;
            
        // ========================================
        // MIKROTIK TOOLS ENDPOINTS
        // ========================================
        
        // ---- GET RESOURCE ----
        case 'resource':
            $result = connectToRouter($routerId);
            if (isset($result['error'])) {
                jsonResponse(['success' => false, 'error' => $result['error']], 500);
            }
            
            $api = $result['api'];
            $resource = $api->getResource();
            $identity = $api->getIdentity();
            $api->disconnect();
            
            $resource['identity'] = $identity;
            
            jsonResponse(['success' => true, 'resource' => $resource]);
            break;
            
        // ---- PING HOST ----
        case 'ping':
            if (empty($input['address'])) {
                jsonResponse(['success' => false, 'error' => 'Address required'], 400);
            }
            
            $result = connectToRouter($routerId);
            if (isset($result['error'])) {
                jsonResponse(['success' => false, 'error' => $result['error']], 500);
            }
            
            $api = $result['api'];
            $count = min(intval($input['count'] ?? 4), 10);
            $pingResults = $api->ping($input['address'], $count);
            $api->disconnect();
            
            jsonResponse(['success' => true, 'results' => $pingResults, 'host' => $input['address']]);
            break;
            
        // ---- GET INTERFACES ----
        case 'interfaces':
            $result = connectToRouter($routerId);
            if (isset($result['error'])) {
                jsonResponse(['success' => false, 'error' => $result['error']], 500);
            }
            
            $interfaces = $result['api']->getInterfaces();
            $result['api']->disconnect();
            
            jsonResponse(['success' => true, 'interfaces' => $interfaces, 'count' => count($interfaces)]);
            break;
            
        // ---- GET LOG ----
        case 'log':
            $result = connectToRouter($routerId);
            if (isset($result['error'])) {
                jsonResponse(['success' => false, 'error' => $result['error']], 500);
            }
            
            $limit = min(intval($input['limit'] ?? 20), 100);
            $logs = $result['api']->getLog($limit);
            $result['api']->disconnect();
            
            jsonResponse(['success' => true, 'logs' => $logs, 'count' => count($logs)]);
            break;
            
        // ---- GET TRAFFIC ----
        case 'traffic':
            $interface = $input['interface'] ?? 'ether1';
            
            $result = connectToRouter($routerId);
            if (isset($result['error'])) {
                jsonResponse(['success' => false, 'error' => $result['error']], 500);
            }
            
            $traffic = $result['api']->getTraffic($interface);
            $result['api']->disconnect();
            
            jsonResponse(['success' => true, 'traffic' => $traffic, 'interface' => $interface]);
            break;
            
        // ---- GET DHCP LEASES ----
        case 'dhcp_leases':
            $result = connectToRouter($routerId);
            if (isset($result['error'])) {
                jsonResponse(['success' => false, 'error' => $result['error']], 500);
            }
            
            $leases = $result['api']->getDHCPLeases();
            $result['api']->disconnect();
            
            jsonResponse(['success' => true, 'leases' => $leases, 'count' => count($leases)]);
            break;
            
        default:
            jsonResponse([
                'success' => true,
                'message' => 'MikroTik API',
                'endpoints' => [
                    '=== PPPoE Endpoints ===' => '',
                    'GET ?action=test' => 'Test connection to router',
                    'GET ?action=profiles' => 'Get PPPoE profiles',
                    'GET ?action=secrets' => 'Get PPPoE users',
                    'GET ?action=active' => 'Get active PPPoE connections',
                    'POST action=isolir' => 'Isolir user (change to isolir profile)',
                    'POST action=unisolir' => 'Un-isolir user (restore profile)',
                    'POST action=change_profile' => 'Change user profile',
                    'POST action=disconnect' => 'Disconnect active PPPoE session',
                    'POST action=add_user' => 'Add new PPPoE user',
                    'POST action=delete_user' => 'Delete PPPoE user',
                    '=== PPPoE Profile Endpoints ===' => '',
                    'POST action=add_pppoe_profile' => 'Add new PPPoE profile',
                    'POST action=delete_pppoe_profile' => 'Delete PPPoE profile',
                    '=== Hotspot User Endpoints ===' => '',
                    'GET ?action=hotspot_active' => 'Get active hotspot users',
                    'GET ?action=hotspot_users' => 'Get all hotspot users',
                    'POST action=add_hotspot_user' => 'Add new hotspot user',
                    'POST action=disconnect_hotspot' => 'Disconnect active hotspot user',
                    'POST action=disable_hotspot_user' => 'Disable hotspot user',
                    'POST action=enable_hotspot_user' => 'Enable hotspot user',
                    'POST action=delete_hotspot_user' => 'Delete hotspot user',
                    '=== Hotspot Profile Endpoints ===' => '',
                    'GET ?action=hotspot_profiles' => 'Get hotspot user profiles',
                    'GET ?action=hotspot_server_profiles' => 'Get hotspot server profiles',
                    'POST action=add_hotspot_profile' => 'Add new hotspot user profile',
                    'POST action=delete_hotspot_profile' => 'Delete hotspot user profile',
                    'POST action=add_server_profile' => 'Add new hotspot server profile',
                    'POST action=delete_server_profile' => 'Delete hotspot server profile',
                    '=== Telegram Bot Endpoints ===' => '',
                    'POST action=add_secret' => 'Add PPPoE user (alias)',
                    'POST action=delete_secret' => 'Delete PPPoE user (alias)',
                    'POST action=generate_voucher' => 'Generate hotspot voucher codes',
                    '=== Config ===' => '',
                    'POST action=save_config' => 'Save router configuration'
                ]
            ]);
    }
} catch (Exception $e) {
    jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
}
