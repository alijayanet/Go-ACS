<?php
// Test MikroTik API Connection
$host = '192.168.8.1';
$port = 8728;
$timeout = 5;

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

echo "Testing connection to MikroTik API...\n";
echo "Host: $host\n";
echo "Port: $port\n\n";

// Test 1: Ping (via exec)
echo "=== Test 1: Ping ===\n";
exec("ping -c 3 $host 2>&1", $output, $return);
echo implode("\n", $output) . "\n\n";

// Test 2: Socket connection
echo "=== Test 2: Socket Connection ===\n";
$socket = @fsockopen($host, $port, $errno, $errstr, $timeout);

if ($socket) {
    echo "✅ SUCCESS! Connected to $host:$port\n";
    fclose($socket);
} else {
    echo "❌ FAILED! Cannot connect to $host:$port\n";
    echo "Error ($errno): $errstr\n";
}

echo "\n=== Test 3: MikroTik API Class ===\n";
require_once __DIR__ . '/../api/MikroTikAPI.php';

$api = new MikroTikAPI();
$connected = $api->connect($host, 'admin', '1234', $port);

if ($connected) {
    echo "✅ MikroTik API connected successfully!\n";
    $identity = $api->getIdentity();
    echo "Router Identity: $identity\n";
    $api->disconnect();
} else {
    echo "❌ MikroTik API connection failed!\n";
    echo "Error: " . $api->getError() . "\n";
}
