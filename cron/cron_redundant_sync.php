<?php
/**
 * S.NET RADIUS Manager — Background Cron for Redundant Database Sync
 * 
 * Penggunaan di Cron Linux (setiap 1 atau 2 menit pada Node Lokal):
 * * * * * * php /www/wwwroot/domain/cron/cron_redundant_sync.php >/dev/null 2>&1
 */

define('IN_APP', true);
define('CLI_MODE', true);
define('BASE_PATH', dirname(__DIR__));

if (php_sapi_name() !== 'cli') {
    die("Script ini hanya dapat dijalankan melalui CLI (terminal/cron).\n");
}

require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/include/functions.php';
require_once BASE_PATH . '/include/redundant_functions.php';

$cfg = get_redundant_settings();

// Hanya berjalan jika mode server adalah 'node'
if (($cfg['redundant_mode'] ?? 'master') !== 'node') {
    echo "[" . date('Y-m-d H:i:s') . "] Server disetel sebagai MASTER. Cron sinkronisasi tidak dijalankan.\n";
    exit(0);
}

echo "====================================================================\n";
echo " S.NET RADIUS — Auto-Sync Redundant Node ke Master VPS\n";
echo " Node Code  : " . ($cfg['node_code'] ?? 'UNKNOWN') . "\n";
echo " Master URL : " . ($cfg['master_url'] ?? 'http://10.77.77.1') . "\n";
echo " Waktu      : " . date('Y-m-d H:i:s') . "\n";
echo "====================================================================\n";

$res = redundant_run_full_sync();

if ($res['success']) {
    echo " ✓ SUKSES: " . $res['message'] . "\n";
} else {
    echo " [!] GAGAL: " . $res['message'] . "\n";
}
