<?php
/**
 * ACS-Lite Database Admin API
 * 
 * Endpoints:
 * - GET ?action=tables         - List all tables
 * - GET ?action=describe&table=xxx  - Describe table structure
 * - GET ?action=select&table=xxx    - Get all data from table
 * - POST action=query         - Execute custom SQL query
 * - POST action=insert        - Insert data into table
 * - POST action=create_table  - Create new table
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
            [$k, $v] = explode('=', $line, 2);
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
        jsonResponse(['success' => false, 'error' => 'Unauthorized'], 401);
    }
}

// ========================================
// DATABASE CONFIG
// ========================================
function getConfig() {
    $config = [
        'host' => '127.0.0.1',
        'port' => 3306,
        'dbname' => 'acs',
        'username' => 'root',
        'password' => ''
    ];
    
    // Try to load from .env
    $envPaths = ['/opt/acs/.env', __DIR__ . '/../../.env'];
    foreach ($envPaths as $envFile) {
        if (file_exists($envFile)) {
            $envContent = file_get_contents($envFile);
            if (preg_match('/DB_DSN=([^:]+):([^@]+)@tcp\(([^:]+):(\d+)\)\/([^?\s]+)/', $envContent, $matches)) {
                $config['username'] = $matches[1];
                $config['password'] = $matches[2];
                $config['host'] = $matches[3];
                $config['port'] = (int)$matches[4];
                $config['dbname'] = $matches[5];
            }
            break;
        }
    }
    return $config;
}

function getDB() {
    $config = getConfig();
    try {
        $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['dbname']};charset=utf8mb4";
        $pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        return $pdo;
    } catch (PDOException $e) {
        return null;
    }
}

function jsonResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

function isValidIdentifier($value) {
    return is_string($value) && preg_match('/^[A-Za-z0-9_]+$/', $value);
}

function ensureSafeIdentifier($value, $label) {
    if (!isValidIdentifier($value)) {
        jsonResponse(['success' => false, 'error' => "{$label} invalid"], 400);
    }
    return $value;
}

function ensureSafeLimit($value) {
    $limit = (int)$value;
    if ($limit < 1) $limit = 1;
    if ($limit > 5000) $limit = 5000;
    return $limit;
}

function isSqlSafeSingleStatement($sql) {
    if (!is_string($sql)) return false;
    $sql = trim($sql);
    if ($sql === '' || strlen($sql) > 20000) return false;
    if (strpos($sql, ';') !== false) return false;
    if (strpos($sql, '--') !== false) return false;
    if (strpos($sql, '/*') !== false || strpos($sql, '*/') !== false) return false;
    return true;
}

function isSqlAllowed($sqlLower) {
    $allowed = ['select', 'insert', 'update', 'delete', 'create', 'alter', 'describe', 'show'];
    foreach ($allowed as $cmd) {
        if (strpos($sqlLower, $cmd) === 0) return true;
    }
    return false;
}

function isSqlForbidden($sqlLower) {
    $forbidden = [
        'drop ',
        'truncate ',
        'grant ',
        'revoke ',
        'create user',
        'alter user',
        'rename user',
        'set password',
        'flush ',
        'load data',
        'outfile',
        'dumpfile'
    ];
    foreach ($forbidden as $bad) {
        if (strpos($sqlLower, $bad) !== false) return true;
    }
    return false;
}

// ========================================
// MAIN HANDLER
// ========================================
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

// Parse JSON body for POST
$input = [];
if ($method === 'POST') {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true) ?: $_POST;
    $action = $input['action'] ?? $action;
}

requireApiKey();

$db = getDB();
if (!$db) {
    jsonResponse(['success' => false, 'error' => 'Database connection failed'], 500);
}

try {
    switch ($action) {
        // ---- LIST TABLES ----
        case 'tables':
            $stmt = $db->query("SHOW TABLES");
            $tables = [];
            while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                $tableName = $row[0];
                // Get row count
                $countStmt = $db->query("SELECT COUNT(*) as cnt FROM `$tableName`");
                $count = $countStmt->fetch()['cnt'];
                $tables[] = ['name' => $tableName, 'rows' => (int)$count];
            }
            jsonResponse(['success' => true, 'tables' => $tables]);
            break;

        // ---- DESCRIBE TABLE ----
        case 'describe':
            $table = $_GET['table'] ?? ($input['table'] ?? '');
            if ($table === '') {
                jsonResponse(['success' => false, 'error' => 'Table name required'], 400);
            }
            $table = ensureSafeIdentifier($table, 'Table name');
            $stmt = $db->query("DESCRIBE `$table`");
            $columns = $stmt->fetchAll();
            jsonResponse(['success' => true, 'table' => $table, 'columns' => $columns]);
            break;

        // ---- SELECT ALL FROM TABLE ----
        case 'select':
            $table = $_GET['table'] ?? ($input['table'] ?? '');
            $limit = ensureSafeLimit($_GET['limit'] ?? ($input['limit'] ?? 1000));
            if ($table === '') {
                jsonResponse(['success' => false, 'error' => 'Table name required'], 400);
            }
            $table = ensureSafeIdentifier($table, 'Table name');
            $stmt = $db->query("SELECT * FROM `$table` LIMIT $limit");
            $data = $stmt->fetchAll();
            jsonResponse(['success' => true, 'table' => $table, 'data' => $data, 'count' => count($data)]);
            break;

        // ---- EXECUTE CUSTOM QUERY ----
        case 'query':
            $sql = $input['sql'] ?? '';
            if (!$sql) {
                jsonResponse(['success' => false, 'error' => 'SQL query required'], 400);
            }
            
            if (!isSqlSafeSingleStatement($sql)) {
                jsonResponse(['success' => false, 'error' => 'Unsafe SQL'], 400);
            }

            $sqlLower = strtolower(trim($sql));
            if (!isSqlAllowed($sqlLower) || isSqlForbidden($sqlLower)) {
                jsonResponse(['success' => false, 'error' => 'Query type not allowed'], 403);
            }
            
            $stmt = $db->query($sql);
            
            // Check if it's a SELECT query
            if (strpos($sqlLower, 'select') === 0 || strpos($sqlLower, 'show') === 0 || strpos($sqlLower, 'describe') === 0) {
                $data = $stmt->fetchAll();
                jsonResponse(['success' => true, 'data' => $data, 'count' => count($data)]);
            } else {
                $affected = $stmt->rowCount();
                jsonResponse(['success' => true, 'message' => "Query executed. Rows affected: $affected", 'affected' => $affected]);
            }
            break;

        // ---- INSERT DATA ----
        case 'insert':
            $table = $input['table'] ?? '';
            $data = $input['data'] ?? [];
            if (!$table || empty($data)) {
                jsonResponse(['success' => false, 'error' => 'Table and data required'], 400);
            }
            $table = ensureSafeIdentifier($table, 'Table name');
            foreach (array_keys($data) as $col) {
                ensureSafeIdentifier($col, 'Column name');
            }
            
            $columns = array_keys($data);
            $placeholders = array_fill(0, count($columns), '?');
            $sql = "INSERT INTO `$table` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $placeholders) . ")";
            
            $stmt = $db->prepare($sql);
            $stmt->execute(array_values($data));
            
            jsonResponse(['success' => true, 'message' => 'Data inserted', 'id' => $db->lastInsertId()]);
            break;

        // ---- CREATE TABLE ----
        case 'create_table':
            $tableName = $input['table_name'] ?? '';
            $columns = $input['columns'] ?? [];
            
            if (!$tableName || empty($columns)) {
                jsonResponse(['success' => false, 'error' => 'Table name and columns required'], 400);
            }
            $tableName = ensureSafeIdentifier($tableName, 'Table name');
            
            // Build CREATE TABLE SQL
            $colDefs = [];
            $primaryCount = 0;
            foreach ($columns as $col) {
                $name = $col['name'] ?? '';
                $type = $col['type'] ?? 'VARCHAR(255)';
                $nullable = ($col['nullable'] ?? true) ? '' : 'NOT NULL';
                $default = '';
                $primary = ($col['primary'] ?? false) ? 'PRIMARY KEY AUTO_INCREMENT' : '';
                
                if ($name) {
                    $name = ensureSafeIdentifier($name, 'Column name');
                    if (!is_string($type)) {
                        jsonResponse(['success' => false, 'error' => 'Column type invalid'], 400);
                    }
                    $typeTrim = trim($type);
                    if ($typeTrim === '' || strlen($typeTrim) > 64) {
                        jsonResponse(['success' => false, 'error' => 'Column type invalid'], 400);
                    }
                    if (!preg_match('/^[A-Za-z0-9_(),\s]+$/', $typeTrim)) {
                        jsonResponse(['success' => false, 'error' => 'Column type invalid'], 400);
                    }
                    if (strpos($typeTrim, '`') !== false) {
                        jsonResponse(['success' => false, 'error' => 'Column type invalid'], 400);
                    }

                    if (isset($col['default'])) {
                        $def = $col['default'];
                        if ($def === null) {
                            $default = 'DEFAULT NULL';
                        } else {
                            if (is_bool($def)) $def = $def ? '1' : '0';
                            if (is_int($def) || is_float($def)) {
                                $default = 'DEFAULT ' . $def;
                            } else {
                                $defStr = (string)$def;
                                if (strlen($defStr) > 255) {
                                    jsonResponse(['success' => false, 'error' => 'Default value too long'], 400);
                                }
                                $defStr = str_replace("'", "''", $defStr);
                                $default = "DEFAULT '{$defStr}'";
                            }
                        }
                    }

                    if ($primary !== '') {
                        $primaryCount++;
                        if ($primaryCount > 1) {
                            jsonResponse(['success' => false, 'error' => 'Only one primary key column allowed'], 400);
                        }
                        if (!preg_match('/^int/i', $typeTrim)) {
                            $typeTrim = 'INT';
                        }
                    }
                    $colDefs[] = "`$name` $type $nullable $default $primary";
                }
            }
            
            if (empty($colDefs)) {
                jsonResponse(['success' => false, 'error' => 'No valid columns defined'], 400);
            }
            
            $sql = "CREATE TABLE IF NOT EXISTS `$tableName` (\n  " . implode(",\n  ", $colDefs) . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            
            $db->exec($sql);
            jsonResponse(['success' => true, 'message' => "Table '$tableName' created", 'sql' => $sql]);
            break;

        // ---- DELETE ROW ----
        case 'delete':
            $table = $input['table'] ?? '';
            $where = $input['where'] ?? [];
            
            if (!$table || empty($where)) {
                jsonResponse(['success' => false, 'error' => 'Table and where conditions required'], 400);
            }
            $table = ensureSafeIdentifier($table, 'Table name');
            
            $conditions = [];
            $values = [];
            foreach ($where as $col => $val) {
                ensureSafeIdentifier($col, 'Column name');
                $conditions[] = "`$col` = ?";
                $values[] = $val;
            }
            
            $sql = "DELETE FROM `$table` WHERE " . implode(' AND ', $conditions);
            $stmt = $db->prepare($sql);
            $stmt->execute($values);
            
            jsonResponse(['success' => true, 'message' => 'Row(s) deleted', 'affected' => $stmt->rowCount()]);
            break;

        default:
            jsonResponse([
                'success' => true, 
                'message' => 'ACS Database Admin API',
                'endpoints' => [
                    'GET ?action=tables' => 'List all tables',
                    'GET ?action=describe&table=xxx' => 'Describe table structure',
                    'GET ?action=select&table=xxx' => 'Get data from table',
                    'POST action=query, sql=xxx' => 'Execute SQL query',
                    'POST action=insert, table=xxx, data={...}' => 'Insert row',
                    'POST action=create_table, table_name=xxx, columns=[...]' => 'Create table',
                    'POST action=delete, table=xxx, where={...}' => 'Delete row(s)'
                ]
            ]);
    }
} catch (PDOException $e) {
    jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
}
