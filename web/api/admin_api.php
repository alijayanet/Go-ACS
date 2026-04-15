<?php
/**
 * ACSLite Admin API
 * 
 * Endpoints:
 * - POST with action=login              - Admin login
 * - POST with action=change_password    - Change admin password
 * - POST with action=change_credentials - Change username & password
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

// ========================================
// PATHS
// ========================================
define('ADMIN_JSON_PATH', __DIR__ . '/../data/admin.json');

// ========================================
// HELPER FUNCTIONS
// ========================================

function getRequestApiKey() {
    if (!empty($_SERVER['HTTP_X_API_KEY'])) {
        return trim((string)$_SERVER['HTTP_X_API_KEY']);
    }
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach ($headers as $k => $v) {
            if (strtolower((string)$k) === 'x-api-key') {
                return trim((string)$v);
            }
        }
    }
    return '';
}

function loadApiKey() {
    if (file_exists(ADMIN_JSON_PATH)) {
        $adminJson = json_decode(file_get_contents(ADMIN_JSON_PATH), true);
        if (is_array($adminJson)) {
            $key = $adminJson['api_key'] ?? ($adminJson['apikey'] ?? null);
            if (is_string($key) && $key !== '') return $key;
        }
    }

    $settingsPath = __DIR__ . '/../data/settings.json';
    if (file_exists($settingsPath)) {
        $settings = json_decode(file_get_contents($settingsPath), true);
        $key = $settings['acs']['api_key'] ?? null;
        if (is_string($key) && $key !== '') return $key;
    }

    $envPaths = ['/opt/acs/.env', __DIR__ . '/../../.env'];
    foreach ($envPaths as $envFile) {
        if (!file_exists($envFile)) continue;
        $envContent = file_get_contents($envFile);
        foreach (explode("\n", (string)$envContent) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) continue;
            if (strpos($line, '=') === false) continue;
            list($k, $v) = explode('=', $line, 2);
            if (trim($k) === 'API_KEY') {
                $value = trim($v);
                if ($value !== '') return $value;
            }
        }
    }

    $key = getenv('API_KEY');
    if (is_string($key) && $key !== '') return $key;

    return 'secret';
}

function requireApiKey() {
    $provided = getRequestApiKey();
    $expected = loadApiKey();
    if ($provided === '' || !hash_equals((string)$expected, (string)$provided)) {
        jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
    }
}

function jsonResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function getAdminData() {
    if (!file_exists(ADMIN_JSON_PATH)) {
        // Create default admin file
        $default = ['admin' => ['username' => 'admin', 'password_hash' => password_hash('admin123', PASSWORD_BCRYPT)]];
        file_put_contents(ADMIN_JSON_PATH, json_encode($default, JSON_PRETTY_PRINT));
        return $default;
    }
    
    $content = file_get_contents(ADMIN_JSON_PATH);
    $data = json_decode($content, true);
    if (!is_array($data)) {
        return ['admin' => ['username' => 'admin', 'password_hash' => password_hash('admin123', PASSWORD_BCRYPT)]];
    }

    if (!isset($data['admin']) && (isset($data['username']) || isset($data['password']) || isset($data['password_hash']) || isset($data['passwordHash']))) {
        $data['admin'] = [
            'username' => $data['username'] ?? 'admin',
            'password' => $data['password'] ?? '',
            'password_hash' => $data['password_hash'] ?? ($data['passwordHash'] ?? '')
        ];
        unset($data['username'], $data['password'], $data['password_hash'], $data['passwordHash']);
    }

    if (!isset($data['admin']) || !is_array($data['admin'])) {
        $data['admin'] = ['username' => 'admin', 'password_hash' => password_hash('admin123', PASSWORD_BCRYPT)];
    }

    return $data;
}

function saveAdminData($data) {
    $result = file_put_contents(ADMIN_JSON_PATH, json_encode($data, JSON_PRETTY_PRINT));
    return $result !== false;
}

function verifyAdminPassword($admin, $providedPassword) {
    $providedPassword = (string)$providedPassword;
    $hash = is_array($admin) ? ($admin['password_hash'] ?? ($admin['passwordHash'] ?? '')) : '';
    if (is_string($hash) && $hash !== '') {
        return password_verify($providedPassword, $hash);
    }
    $legacy = is_array($admin) ? ($admin['password'] ?? '') : '';
    if (is_string($legacy) && $legacy !== '') {
        return hash_equals($legacy, $providedPassword);
    }
    return $providedPassword === '';
}

// ========================================
// MAIN HANDLER
// ========================================

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?: [];

// GET - Check if API is available
if ($method === 'GET') {
    jsonResponse(['success' => true, 'message' => 'Admin API is running']);
}

// POST - Handle actions
if ($method === 'POST') {
    $action = $input['action'] ?? '';
    
    // ---- LOGIN ----
    if ($action === 'login') {
        $username = $input['username'] ?? '';
        $password = $input['password'] ?? '';
        
        if (empty($username) || empty($password)) {
            jsonResponse(['success' => false, 'message' => 'Username and password required'], 400);
        }
        
        $adminData = getAdminData();
        $admin = $adminData['admin'] ?? null;

        $ok = false;
        if ($admin && ($admin['username'] ?? '') === $username) {
            $ok = verifyAdminPassword($admin, $password);
            if ($ok && empty($admin['password_hash']) && !empty($admin['password'])) {
                $adminData['admin']['password_hash'] = password_hash((string)$password, PASSWORD_BCRYPT);
                unset($adminData['admin']['password']);
                saveAdminData($adminData);
            }
        }

        if ($ok) {
            jsonResponse(['success' => true, 'message' => 'Login successful']);
        } else {
            jsonResponse(['success' => false, 'message' => 'Invalid credentials'], 401);
        }
    }
    
    // ---- CHANGE PASSWORD ----
    if ($action === 'change_password') {
        requireApiKey();
        $currentPassword = $input['current_password'] ?? '';
        $newPassword = $input['new_password'] ?? '';
        
        if (empty($currentPassword) || empty($newPassword)) {
            jsonResponse(['success' => false, 'message' => 'Current and new password required'], 400);
        }
        
        $adminData = getAdminData();
        $admin = $adminData['admin'] ?? null;
        
        if (!$admin || !verifyAdminPassword($admin, $currentPassword)) {
            jsonResponse(['success' => false, 'message' => 'Current password is incorrect'], 401);
        }
        
        // Update password
        $adminData['admin']['password_hash'] = password_hash((string)$newPassword, PASSWORD_BCRYPT);
        unset($adminData['admin']['password']);
        
        if (saveAdminData($adminData)) {
            jsonResponse(['success' => true, 'message' => 'Password updated successfully']);
        } else {
            jsonResponse(['success' => false, 'message' => 'Failed to save new password'], 500);
        }
    }
    
    // ---- CHANGE CREDENTIALS (username + password) ----
    if ($action === 'change_credentials') {
        requireApiKey();
        $currentPassword = $input['current_password'] ?? '';
        $newUsername = $input['new_username'] ?? '';
        $newPassword = $input['new_password'] ?? '';
        
        if (empty($currentPassword) || empty($newUsername) || empty($newPassword)) {
            jsonResponse(['success' => false, 'message' => 'All fields required'], 400);
        }
        
        $adminData = getAdminData();
        $admin = $adminData['admin'] ?? null;
        
        if (!$admin || !verifyAdminPassword($admin, $currentPassword)) {
            jsonResponse(['success' => false, 'message' => 'Current password is incorrect'], 401);
        }
        
        // Update credentials
        $adminData['admin']['username'] = $newUsername;
        $adminData['admin']['password_hash'] = password_hash((string)$newPassword, PASSWORD_BCRYPT);
        unset($adminData['admin']['password']);
        
        if (saveAdminData($adminData)) {
            jsonResponse(['success' => true, 'message' => 'Credentials updated successfully']);
        } else {
            jsonResponse(['success' => false, 'message' => 'Failed to save credentials'], 500);
        }
    }
    
    jsonResponse(['success' => false, 'message' => 'Unknown action'], 400);
}

jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
