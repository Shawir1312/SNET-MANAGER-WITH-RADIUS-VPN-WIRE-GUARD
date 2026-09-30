<?php
/**
 * S.NET RADIUS & VPN — CLI Script: Sinkronisasi Semua Peer & NAT WireGuard dari Database
 * 
 * Penggunaan di Terminal VPS:
 * sudo php /path/to/scripts/wg-sync-all.php
 */

define('IN_APP', true);
define('CLI_MODE', true);
define('BASE_PATH', dirname(__DIR__));

if (php_sapi_name() !== 'cli') {
    die("Script ini hanya dapat dijalankan melalui CLI (terminal).\n");
}

require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/include/wireguard_functions.php';

echo "========================================================\n";
echo "   S.NET VPN WireGuard — Sinkronisasi Seluruh Peer\n";
echo "========================================================\n";

if (!function_exists('shell_exec')) {
    echo "[ERROR] Fungsi shell_exec() tidak diizinkan di php.ini.\n";
    exit(1);
}

// 1. Sinkronisasi Peers
echo "[1/2] Menyinkronkan daftar peer router dari database...\n";
$res = wg_sync_all_peers();
echo "      ✓ Berhasil menyinkronkan: " . ($res['count'] ?? 0) . " router.\n";

if (!empty($res['errors'])) {
    foreach ($res['errors'] as $err) {
        echo "      [!] PERINGATAN: $err\n";
    }
}

// 2. Sinkronisasi NAT Port Forwarding
echo "[2/2] Menyinkronkan port forwarding iptables...\n";
wg_sync_all_port_forwards();
echo "      ✓ Port forwarding iptables berhasil diterapkan.\n";

// 3. Tampilkan live status WireGuard
echo "\n--- Live Status WireGuard Kernel (wg show) ---\n";
passthru('sudo wg show 2>/dev/null || wg show 2>/dev/null');
echo "\n========================================================\n";
echo " ✓ Sinkronisasi selesai!\n";
echo "========================================================\n";
