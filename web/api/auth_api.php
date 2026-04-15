<?php
/**
 * Authentication API
 * Handles login with API Key validation from multiple sources
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit();
}

$action = $input['action'] ?? '';

switch ($action) {
    case 'login':
        handleLogin($input);
        break;
    
    case 'verify_session':
        verifySession($input);
        break;
    
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}

function getSessionStorePath() {
    return __DIR__ . '/../data/admin_sessions.json';
}

function loadSessions() {
    $path = getSessionStorePath();
    if (!file_exists($path)) return [];
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function saveSessions($sessions) {
    $path = getSessionStorePath();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $json = json_encode($sessions, JSON_PRETTY_PRINT);
    if ($json === false) return false;
    return file_put_contents($path, $json, LOCK_EX) !== false;
}

function pruneExpiredSessions($sessions) {
    $now = time();
    foreach ($sessions as $k => $s) {
        $exp = isset($s['expiry']) ? (int)$s['expiry'] : 0;
        if ($exp <= $now) unset($sessions[$k]);
    }
    return $sessions;
}

function handleLogin($input) {
    $username = trim($input['username'] ?? '');
    $password = $input['password'] ?? '';
    $apikey = trim($input['apikey'] ?? '');
    
    // Validate required fields
    if (empty($username) || empty($password) || empty($apikey)) {
        echo json_encode([
            'success' => false,
            'error' => 'Username, password, dan API key wajib diisi'
        ]);
        return;
    }
    
    // ========================================
    // Load credentials from multiple sources
    // ========================================
    
    $validApiKey = null;
    $validUsername = null;
    $validPassword = null;
    $validPasswordHash = null;
    $source = 'none';
    
    // 1. Try admin.json first (most reliable)
    $adminJsonPath = __DIR__ . '/../data/admin.json';
    if (file_exists($adminJsonPath)) {
        $adminJson = json_decode(file_get_contents($adminJsonPath), true);
        if ($adminJson) {
            // Check for nested "admin" structure (new format)
            if (isset($adminJson['admin'])) {
                $validUsername = $adminJson['admin']['username'] ?? 'admin';
                $validPassword = $adminJson['admin']['password'] ?? null;
                $validPasswordHash = $adminJson['admin']['password_hash'] ?? ($adminJson['admin']['passwordHash'] ?? null);
            } else {
                // Flat structure (old format)
                $validUsername = $adminJson['username'] ?? 'admin';
                $validPassword = $adminJson['password'] ?? null;
                $validPasswordHash = $adminJson['password_hash'] ?? ($adminJson['passwordHash'] ?? null);
            }
            // API key is always at root level
            $validApiKey = $adminJson['api_key'] ?? $adminJson['apikey'] ?? null;
            $source = 'admin.json';
        }
    }
    
    // 2. If no API key from admin.json, try settings.json
    if (empty($validApiKey)) {
        $settingsPath = __DIR__ . '/../data/settings.json';
        if (file_exists($settingsPath)) {
            $settings = json_decode(file_get_contents($settingsPath), true);
            if ($settings && isset($settings['acs']['api_key'])) {
                $validApiKey = $settings['acs']['api_key'];
                $source = 'settings.json';
            }
        }
    }
    
    // 3. Try .env file
    if (empty($validApiKey)) {
        $envFile = '/opt/acs/.env';
        if (file_exists($envFile)) {
            $envContent = file_get_contents($envFile);
            $envLines = explode("\n", $envContent);
            foreach ($envLines as $line) {
                $line = trim($line);
                if (empty($line) || strpos($line, '#') === 0) {
                    continue;
                }
                if (strpos($line, '=') !== false) {
                    list($key, $value) = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value);
                    if ($key === 'API_KEY') {
                        $validApiKey = $value;
                        $source = '.env';
                    }
                }
            }
        }
    }
    
    // 4. Try environment variable
    if (empty($validApiKey)) {
        $validApiKey = getenv('API_KEY');
        if (!empty($validApiKey)) {
            $source = 'env_var';
        }
    }
    
    // 5. Fallback to hardcoded default
    if (empty($validApiKey)) {
        $validApiKey = 'secret';
        $source = 'default';
    }
    
    // Default credentials if not loaded
    if (empty($validUsername)) {
        $validUsername = 'admin';
    }
    if (empty($validPassword)) {
        $validPassword = 'admin123'; // Default from install.sh
    }
    
    // ========================================
    // Validate credentials
    // ========================================
    
    $isUsernameValid = ($username === $validUsername);
    $isPasswordValid = false;
    if (!empty($validPasswordHash)) {
        $isPasswordValid = password_verify((string)$password, (string)$validPasswordHash);
    } else {
        $isPasswordValid = ($password === $validPassword);
    }
    $isApiKeyValid = ($apikey === $validApiKey);
    
    if (!$isUsernameValid || !$isPasswordValid) {
        echo json_encode([
            'success' => false,
            'error' => 'Username atau password salah'
        ]);
        return;
    }
    
    if (!$isApiKeyValid) {
        echo json_encode([
            'success' => false,
            'error' => 'API Key tidak valid'
        ]);
        return;
    }

    if ($source === 'admin.json' && empty($validPasswordHash) && !empty($validPassword)) {
        $adminJson = json_decode(@file_get_contents($adminJsonPath), true);
        if (is_array($adminJson)) {
            if (isset($adminJson['admin']) && is_array($adminJson['admin'])) {
                $adminJson['admin']['password_hash'] = password_hash((string)$password, PASSWORD_BCRYPT);
                unset($adminJson['admin']['password'], $adminJson['admin']['passwordHash']);
            } else {
                $adminJson['password_hash'] = password_hash((string)$password, PASSWORD_BCRYPT);
                unset($adminJson['password'], $adminJson['passwordHash']);
            }
            @file_put_contents($adminJsonPath, json_encode($adminJson, JSON_PRETTY_PRINT));
        }
    }
    
    // Login successful
    $sessionToken = bin2hex(random_bytes(32));
    $expiry = time() + (24 * 60 * 60); // 24 hours

    $sessions = loadSessions();
    $sessions = pruneExpiredSessions($sessions);
    $tokenHash = hash('sha256', $sessionToken);
    $sessions[$tokenHash] = [
        'username' => $username,
        'expiry' => $expiry,
        'created_at' => time(),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        'ua' => isset($_SERVER['HTTP_USER_AGENT']) ? substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 200) : null,
        'source' => $source
    ];
    saveSessions($sessions);
    
    echo json_encode([
        'success' => true,
        'message' => 'Login berhasil',
        'data' => [
            'username' => $username,
            'role' => 'Administrator',
            'token' => $sessionToken,
            'expiry' => $expiry,
            'loginTime' => date('c')
        ]
    ]);
}

function verifySession($input) {
    $token = $input['token'] ?? '';
    
    if (empty($token)) {
        echo json_encode(['success' => false, 'error' => 'Token required']);
        return;
    }
    
    $sessions = loadSessions();
    $sessions = pruneExpiredSessions($sessions);
    saveSessions($sessions);

    $tokenHash = hash('sha256', (string)$token);
    $session = $sessions[$tokenHash] ?? null;
    $valid = is_array($session) && ((int)($session['expiry'] ?? 0) > time());

    echo json_encode([
        'success' => true,
        'valid' => $valid,
        'data' => $valid ? [
            'username' => $session['username'] ?? null,
            'expiry' => (int)($session['expiry'] ?? 0)
        ] : null
    ]);
}

