<?php
/**
 * Auto-Sync ONU Serial Numbers dari GenieACS ke Tabel Customers
 * Mencocokkan berdasarkan PPPoE Username
 * 
 * Script ini akan:
 * 1. Fetch semua devices dari GenieACS
 * 2. Ekstrak PPPoE username dari setiap device
 * 3. Update onu_serial di tabel customers yang cocok dengan PPPoE username
 */

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
    header('Content-Type: text/plain; charset=utf-8');
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

echo "===========================================\n";
echo " Auto-Sync ONU Serial ke Tabel Customers\n";
echo "===========================================\n\n";

// Database Config
$config = [
    'host' => '127.0.0.1',
    'port' => 3306,
    'dbname' => 'acs',
    'username' => 'root',
    'password' => ''
];

// Load from .env if exists
$envFile = '/opt/acs/.env';
if (file_exists($envFile)) {
    echo "📄 Loading config from .env...\n";
    $envContent = file_get_contents($envFile);
    if (preg_match('/DB_DSN=([^:]+):([^@]+)@tcp\(([^:]+):(\d+)\)\/([^?]+)/', $envContent, $matches)) {
        $config['username'] = $matches[1];
        $config['password'] = $matches[2];
        $config['host'] = $matches[3];
        $config['port'] = (int)$matches[4];
        $config['dbname'] = $matches[5];
    }
}

// Connect to Database
try {
    $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['dbname']};charset=utf8mb4";
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    echo "✓ Database connected\n";
} catch (PDOException $e) {
    // Try with empty password
    try {
        $pdo = new PDO("mysql:host={$config['host']};port={$config['port']};dbname={$config['dbname']};charset=utf8mb4", 
            $config['username'], '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        echo "✓ Database connected (empty password)\n";
    } catch (PDOException $e2) {
        die("❌ Database connection failed: " . $e2->getMessage() . "\n");
    }
}

// GenieACS API
$genieAcsUrl = 'http://127.0.0.1:7547/devices';
$projection = 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Username';

echo "🔗 Fetching devices from GenieACS...\n";
echo "   URL: $genieAcsUrl\n\n";

// Fetch devices
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $genieAcsUrl . '?projection=_id,' . $projection);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || !$response) {
    die("❌ Failed to fetch devices from GenieACS (HTTP $httpCode)\n");
}

$devices = json_decode($response, true);
if (!is_array($devices) || count($devices) === 0) {
    die("❌ No devices found in GenieACS\n");
}

echo "✓ Found " . count($devices) . " devices\n\n";
echo "Processing...\n";
echo str_repeat("-", 70) . "\n";

$updated = 0;
$skipped = 0;
$errors = 0;

foreach ($devices as $device) {
    $serial = $device['_id'] ?? null;
    
    // Extract PPPoE Username
    $pppoeUser = null;
    if (isset($device['InternetGatewayDevice']['WANDevice']['1']['WANConnectionDevice']['1']['WANPPPConnection']['1']['Username']['_value'])) {
        $pppoeUser = $device['InternetGatewayDevice']['WANDevice']['1']['WANConnectionDevice']['1']['WANPPPConnection']['1']['Username']['_value'];
    }
    
    if (!$serial) {
        $skipped++;
        continue;
    }
    
    if (!$pppoeUser) {
        echo "⊘ [$serial] No PPPoE username\n";
        $skipped++;
        continue;
    }
    
    // Update customer
    try {
        // Only update if onu_serial is empty or NULL
        $stmt = $pdo->prepare("UPDATE customers SET onu_serial = ? WHERE pppoe_username = ? AND (onu_serial IS NULL OR onu_serial = '')");
        $stmt->execute([$serial, $pppoeUser]);
        
        if ($stmt->rowCount() > 0) {
            echo "✓ [$serial] Updated for PPPoE: $pppoeUser\n";
            $updated++;
        } else {
            // Check if customer exists
            $checkStmt = $pdo->prepare("SELECT customer_id, onu_serial FROM customers WHERE pppoe_username = ?");
            $checkStmt->execute([$pppoeUser]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                echo "⊘ [$serial] Customer {$existing['customer_id']} already has serial: {$existing['onu_serial']}\n";
            } else {
                echo "⊘ [$serial] No customer found with PPPoE: $pppoeUser\n";
            }
            $skipped++;
        }
    } catch (PDOException $e) {
        echo "❌ [$serial] Error: " . $e->getMessage() . "\n";
        $errors++;
    }
}

echo str_repeat("-", 70) . "\n";
echo "\n===========================================\n";
echo " Sync Completed!\n";
echo "===========================================\n";
echo "✓ Updated: $updated\n";
echo "⊘ Skipped: $skipped\n";
echo "❌ Errors:  $errors\n";
echo "===========================================\n";
