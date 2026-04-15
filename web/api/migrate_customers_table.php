<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

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
    $adminJsonPath = __DIR__ . '/../data/admin.json';
    if (file_exists($adminJsonPath)) {
        $adminJson = json_decode(file_get_contents($adminJsonPath), true);
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

function denyAccess() {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

if (php_sapi_name() !== 'cli') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $isLocal = ($ip === '127.0.0.1' || $ip === '::1');
    if (!$isLocal) {
        $provided = getRequestApiKey();
        $expected = loadApiKey();
        if ($provided === '' || !hash_equals((string)$expected, (string)$provided)) {
            denyAccess();
        }
    }
}

function tryConnection($config) {
    try {
        $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['dbname']};charset=utf8mb4";
        $pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
        return $pdo;
    } catch (PDOException $e) {
        return null; // $e->getMessage();
    }
}

// 1. Try env file first
$config = [
    'host' => '127.0.0.1',
    'port' => 3306,
    'dbname' => 'acs',
    'username' => 'root',
    'password' => ''
];

$envFile = '/opt/acs/.env';
if (file_exists($envFile)) {
    echo "Found .env at $envFile\n";
    $envContent = file_get_contents($envFile);
    if (preg_match('/DB_DSN=([^:]+):([^@]+)@tcp\(([^:]+):(\d+)\)\/([^?]+)/', $envContent, $matches)) {
        $config['username'] = $matches[1];
        $config['password'] = $matches[2];
        $config['host'] = $matches[3];
        $config['port'] = (int)$matches[4];
        $config['dbname'] = $matches[5];
    }
} else {
    echo ".env not found at $envFile\n";
}

echo "Trying connection with User: {$config['username']}, Pass: {$config['password']}...\n";
$pdo = tryConnection($config);

if (!$pdo) {
    // 2. Try empty password
    echo "Connection failed. Trying empty password...\n";
    $config['password'] = '';
    $pdo = tryConnection($config);
}

if (!$pdo) {
    echo "FATAL: Could not connect to database.\n";
    exit(1);
}

echo "Connected successfully.\n";

try {
    // 1. Add columns if not exist
    $columns = $pdo->query("SHOW COLUMNS FROM customers")->fetchAll(PDO::FETCH_COLUMN);
    
    if (!in_array('portal_username', $columns)) {
        echo "Adding portal_username column...\n";
        $pdo->exec("ALTER TABLE customers ADD COLUMN portal_username VARCHAR(50) UNIQUE AFTER pppoe_password");
    } else {
        echo "portal_username column already exists.\n";
    }

    if (!in_array('portal_password', $columns)) {
        echo "Adding portal_password column...\n";
        $pdo->exec("ALTER TABLE customers ADD COLUMN portal_password VARCHAR(255) AFTER portal_username");
    } else {
        echo "portal_password column already exists.\n";
    }

    // 2. Migrate existing users
    echo "Migrating existing users...\n";
    $stmt = $pdo->query("SELECT id, customer_id, phone, pppoe_username, portal_username FROM customers");
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $defaultPassHash = password_hash('123456', PASSWORD_BCRYPT);
    $updates = 0;

    foreach ($customers as $cust) {
        if (empty($cust['portal_username'])) {
            // Determine username: PPPoE > CustomerID
            $newUsername = !empty($cust['pppoe_username']) ? $cust['pppoe_username'] : $cust['customer_id'];
            
            // Handle collision
            $check = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE portal_username = ?");
            $check->execute([$newUsername]);
            if ($check->fetchColumn() > 0) {
                 $newUsername = $cust['customer_id']; // Fallback
            }

            $updateStmt = $pdo->prepare("UPDATE customers SET portal_username = ?, portal_password = ? WHERE id = ?");
            try {
                $updateStmt->execute([$newUsername, $defaultPassHash, $cust['id']]);
                echo "Updated user ID {$cust['id']}: Login = $newUsername\n";
                $updates++;
            } catch (Exception $e) {
                echo "Failed to update user ID {$cust['id']}: " . $e->getMessage() . "\n";
            }
        }
    }

    echo "Migration completed. Updated $updates users.\n";

} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
}
