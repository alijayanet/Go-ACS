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
    $adminJsonPath = __DIR__ . '/data/admin.json';
    if (file_exists($adminJsonPath)) {
        $adminJson = json_decode(file_get_contents($adminJsonPath), true);
        if (is_array($adminJson)) {
            $key = $adminJson['api_key'] ?? ($adminJson['apikey'] ?? null);
            if (is_string($key) && $key !== '') return $key;
        }
    }

    $settingsPath = __DIR__ . '/data/settings.json';
    if (file_exists($settingsPath)) {
        $settings = json_decode(file_get_contents($settingsPath), true);
        $key = $settings['acs']['api_key'] ?? null;
        if (is_string($key) && $key !== '') return $key;
    }

    $envPaths = ['/opt/acs/.env', __DIR__ . '/../.env'];
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

$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['action'] = 'status';
include __DIR__ . '/api/radius_api.php';
?>
