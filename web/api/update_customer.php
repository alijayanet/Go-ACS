<?php
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

$config = ['host' => '127.0.0.1', 'port' => 3306, 'dbname' => 'acs', 'username' => 'root', 'password' => ''];

$envFile = '/opt/acs/.env';
if (file_exists($envFile)) {
    $envContent = file_get_contents($envFile);
    if (preg_match('/DB_DSN=([^:]+):([^@]+)@tcp\(([^:]+):(\d+)\)\/([^?]+)/', $envContent, $m)) {
        $config = ['username' => $m[1], 'password' => $m[2], 'host' => $m[3], 'port' => (int)$m[4], 'dbname' => $m[5]];
    }
}

try {
    $pdo = new PDO("mysql:host={$config['host']};port={$config['port']};dbname={$config['dbname']};charset=utf8mb4", $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    $pdo = new PDO("mysql:host={$config['host']};port={$config['port']};dbname={$config['dbname']};charset=utf8mb4", $config['username'], '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

echo "Updating customer CST001...\n";
$pdo->exec("UPDATE customers SET onu_serial = 'CIOT12C64178' WHERE customer_id = 'CST001'");
echo "✓ Updated!\n\n";

echo "Current customers data:\n";
echo str_repeat("-", 70) . "\n";
$stmt = $pdo->query("SELECT customer_id, name, pppoe_username, onu_serial FROM customers");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    printf("%-10s | %-20s | PPPoE: %-15s | Serial: %s\n", 
        $row['customer_id'], $row['name'], $row['pppoe_username'], $row['onu_serial'] ?: '(empty)');
}
echo str_repeat("-", 70) . "\n";
