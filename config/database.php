<?php
/**
 * S.NET RADIUS Hotspot Management
 * Database Configuration & Connection
 */

// Load local config created by installer if it exists
if (!defined('DB_HOST') && file_exists(__DIR__ . '/db_local.php')) {
    require_once __DIR__ . '/db_local.php';
}

if (!defined('DB_HOST'))    define('DB_HOST',    getenv('RADIUS_DB_HOST')   ?: 'localhost');
if (!defined('DB_USER'))    define('DB_USER',    getenv('RADIUS_DB_USER')   ?: 'radius');
if (!defined('DB_PASS'))    define('DB_PASS',    getenv('RADIUS_DB_PASS')   ?: 'radpass');
if (!defined('DB_NAME'))    define('DB_NAME',    getenv('RADIUS_DB_NAME')   ?: 'radius');
if (!defined('DB_PORT'))    define('DB_PORT',    (int)(getenv('RADIUS_DB_PORT') ?: 3306));
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

// Singleton DB connection
function db(): mysqli {
    static $conn = null;
    if ($conn === null) {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        try {
            $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
            $conn->set_charset(DB_CHARSET);
            // Sinkronkan session time_zone MySQL dengan time_zone PHP (Asia/Jayapura)
            $tzOffset = date('P');
            @$conn->query("SET time_zone = '{$tzOffset}'");
        } catch (mysqli_sql_exception $e) {
            if (defined('CLI_MODE') && CLI_MODE) {
                die("DB Connection failed: " . $e->getMessage() . "\n");
            }
            http_response_code(503);
            die(json_encode(['error' => 'Database connection failed.']));
        }
    }
    return $conn;
}

/**
 * Execute a prepared statement and return MySQLi result or bool.
 * Usage: db_query("SELECT * FROM routers WHERE id = ?", "i", [$id])
 */
function db_query(string $sql, string $types = '', array $params = []) {
    $stmt = db()->prepare($sql);
    if ($types && $params) {
        $bind_params = [];
        $bind_params[] = $types;
        foreach ($params as $key => $value) {
            $bind_params[] = &$params[$key];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind_params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result === false) {
        return true; // for INSERT/UPDATE/DELETE
    }
    return $result;
}

/**
 * Fetch all rows from a prepared query.
 */
function db_fetch_all(string $sql, string $types = '', array $params = []): array {
    $result = db_query($sql, $types, $params);
    if ($result instanceof mysqli_result) {
        return $result->fetch_all(MYSQLI_ASSOC);
    }
    return [];
}

/**
 * Fetch a single row.
 */
function db_fetch_one(string $sql, string $types = '', array $params = []): ?array {
    $result = db_query($sql, $types, $params);
    if ($result instanceof mysqli_result) {
        $row = $result->fetch_assoc();
        return $row ?: null;
    }
    return null;
}

/**
 * Execute INSERT/UPDATE/DELETE and return affected rows.
 */
function db_execute(string $sql, string $types = '', array $params = []): int {
    $stmt = db()->prepare($sql);
    if ($types && $params) {
        $bind_params = [];
        $bind_params[] = $types;
        foreach ($params as $key => $value) {
            $bind_params[] = &$params[$key];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind_params);
    }
    $stmt->execute();
    return $stmt->affected_rows;
}

/**
 * Return last insert ID.
 */
function db_last_id(): int {
    return (int) db()->insert_id;
}

/**
 * Begin / commit / rollback transaction helpers.
 */
function db_begin(): void    { db()->begin_transaction(); }
function db_commit(): void   { db()->commit(); }
function db_rollback(): void { db()->rollback(); }
