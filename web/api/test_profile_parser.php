#!/usr/bin/env php
<?php
/**
 * Test MikroTik Profile Parser
 * Jalankan: php web/api/test_profile_parser.php
 */

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

function requireApiKey() {
    if (php_sapi_name() === 'cli') return;
    $provided = getRequestApiKey();
    $expected = loadApiKey();
    if ($provided === '' || !hash_equals((string)$expected, (string)$provided)) {
        http_response_code(401);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Unauthorized\n";
        exit;
    }
}

requireApiKey();

echo "=== Test MikroTik Profile Parser ===\n\n";

// Load MikroTik API
require_once __DIR__ . '/MikroTikAPI.php';

// Load config
$configFile = __DIR__ . '/../data/mikrotik.json';
if (!file_exists($configFile)) {
    die("❌ Config file not found: $configFile\n");
}

$content = file_get_contents($configFile);
$config = json_decode($content, true);

if (!isset($config['routers'][0])) {
    die("❌ No router config found\n");
}

$router = $config['routers'][0];
echo "📡 Connecting to MikroTik: {$router['ip']}:{$router['port']}\n";

// Connect
$api = new MikroTikAPI();
$connected = $api->connect($router['ip'], $router['username'], $router['password'], $router['port']);

if (!$connected) {
    die("❌ Connection failed: " . $api->getError() . "\n");
}

echo "✅ Connected successfully!\n\n";

// Get profiles
$profiles = $api->getHotspotProfiles();
echo "📋 Found " . count($profiles) . " hotspot profiles:\n\n";

// Parser function (sama seperti di voucher_api.php)
function parseMikhmonScript($script) {
    $result = [
        'price' => 0,
        'duration' => ''
    ];
    
    if (empty($script)) {
        return $result;
    }
    
    if (preg_match('/:put\s*\("([^"]+)"\)/', $script, $matches)) {
        $comment = $matches[1];
        $parts = explode(',', $comment);
        
        echo "   📝 Script parts: " . json_encode($parts) . "\n";
        
        if (count($parts) >= 5) {
            // Price ACTUAL di index 4
            if (isset($parts[4]) && is_numeric($parts[4])) {
                $result['price'] = (int)$parts[4];
                echo "   💰 Found price at index 4: {$parts[4]}\n";
            }
            // Fallback ke index 2
            elseif (isset($parts[2]) && is_numeric($parts[2])) {
                $result['price'] = (int)$parts[2];
                echo "   💰 Using fallback price at index 2: {$parts[2]}\n";
            }
            
            // Duration di index 3
            if (isset($parts[3]) && preg_match('/^\d+[hdw]$/', $parts[3])) {
                $result['duration'] = $parts[3];
                echo "   ⏱️  Found duration: {$parts[3]}\n";
            }
        }
    } else {
        echo "   ⚠️  No :put pattern found in script\n";
    }
    
    return $result;
}

// Test each profile
foreach ($profiles as $p) {
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "Profile: {$p['name']}\n";
    echo "Session Timeout: " . ($p['session-timeout'] ?? 'none') . "\n";
    echo "Rate Limit: " . ($p['rate-limit'] ?? 'none') . "\n";
    
    $onLogin = $p['on-login'] ?? '';
    if (!empty($onLogin)) {
        echo "On-Login Script: " . substr($onLogin, 0, 100) . "...\n";
        echo "\n🔍 Parsing script:\n";
        $parsed = parseMikhmonScript($onLogin);
        echo "\n✅ Result:\n";
        echo "   Price: Rp " . number_format($parsed['price'], 0, ',', '.') . "\n";
        echo "   Duration: " . ($parsed['duration'] ?: 'not found') . "\n";
    } else {
        echo "⚠️  No on-login script\n";
    }
    echo "\n";
}

$api->disconnect();
echo "✅ Done!\n";
