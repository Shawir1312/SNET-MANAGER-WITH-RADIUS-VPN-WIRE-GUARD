#!/usr/bin/env php
<?php
/**
 * S.NET RADIUS Manager — Cron: Auto-Expire Vouchers
 * Run via crontab: * /5 * * * * php /var/www/html/radius-hotspot/cron/expire_vouchers.php
 *
 * Checks radacct for sessions that have exceeded their Session-Timeout,
 * marks the corresponding vouchers as expired, and removes from radcheck/radreply.
 */

define('CLI_MODE', true);
define('BASE_PATH', dirname(__DIR__));
define('CONFIG_PATH', BASE_PATH . '/config');
define('LIB_PATH', BASE_PATH . '/lib');

// Load local DB config if exists
if (file_exists(CONFIG_PATH . '/db_local.php')) {
    require_once CONFIG_PATH . '/db_local.php';
} else {
    require_once CONFIG_PATH . '/database.php';
}
// Remaining constants
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');
if (!defined('APP_TIMEZONE')) define('APP_TIMEZONE', 'Asia/Jayapura');
date_default_timezone_set(APP_TIMEZONE);

require_once CONFIG_PATH . '/database.php';
require_once BASE_PATH . '/include/functions.php';

$log = function(string $msg) {
    $ts = date('Y-m-d H:i:s');
    $line = "[{$ts}] {$msg}";
    echo $line . "\n";
    // Append to log file
    $logdir = BASE_PATH . '/logs';
    if (!is_dir($logdir)) mkdir($logdir, 0755, true);
    file_put_contents($logdir . '/cron.log', $line . "\n", FILE_APPEND);
};

// ── Execute Centralized Cleanup ──────────────────────────────────────────────
$lockFp = fopen(sys_get_temp_dir() . '/snet_cron_expire.lock', 'c+');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    $log("Instance expire_vouchers sebelumnya masih berjalan. Dilewati.");
    exit(0);
}

run_auto_expire_vouchers($log, true);

$log("=== expire_vouchers cron finished ===\n");
