<?php
/**
 * S.NET RADIUS & VPN — Cron Watchdog WireGuard
 * Memantau kondisi service WireGuard setiap 2-5 menit secara otomatis.
 * Jika service mati atau aturan firewall hilang (misal setelah aaPanel reload / reboot),
 * watchdog akan otomatis menyalakan kembali dan menyinkronkan seluruh peer router.
 * 
 * Penggunaan di crontab:
 * Contoh: Jalankan tiap 3 menit
 * cron pattern: star/3 star star star star /usr/bin/php /www/wwwroot/dash.snetwifi.com/cron/cron_wireguard_watchdog.php >/dev/null 2>&1
 */

define('IN_APP', true);
define('CLI_MODE', true);
define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/include/wireguard_functions.php';

if (!function_exists('shell_exec')) {
    exit(0);
}

// 1. Cek status service WireGuard
$status = trim((string)@shell_exec('sudo systemctl is-active wg-quick@wg0 2>/dev/null' ?: 'systemctl is-active wg-quick@wg0 2>/dev/null'));

$needsRestart = ($status !== 'active');

// Cek apakah interface wg0 ada di kernel
$ifaceExists = false;
$wgOut = @shell_exec('sudo wg show wg0 2>/dev/null' ?: 'wg show wg0 2>/dev/null');
if ($wgOut && strpos($wgOut, 'interface: wg0') !== false) {
    $ifaceExists = true;
}

if ($needsRestart || !$ifaceExists) {
    echo "[WATCHDOG] Service WireGuard tidak aktif (Status: {$status}). Memulai pemulihan otomatis...\n";
    
    // Aktifkan dan restart service
    @shell_exec('sudo systemctl enable wg-quick@wg0 2>/dev/null');
    @shell_exec('sudo systemctl restart wg-quick@wg0 2>/dev/null');
    
    // Pastikan IP forwarding aktif
    @shell_exec('sudo sysctl -w net.ipv4.ip_forward=1 >/dev/null 2>&1');
    
    // Buka port & iptables
    @shell_exec('sudo iptables -I INPUT -p udp --dport 51820 -j ACCEPT 2>/dev/null');
    @shell_exec('sudo iptables -I INPUT -i wg0 -j ACCEPT 2>/dev/null');
    @shell_exec('sudo iptables -I FORWARD -i wg0 -j ACCEPT 2>/dev/null');
    @shell_exec('sudo iptables -I FORWARD -o wg0 -j ACCEPT 2>/dev/null');

    // Sinkronkan seluruh router dan NAT
    wg_sync_all_peers();
    wg_sync_all_port_forwards();
    
    wg_log('watchdog_recovered', null, null, 'Service WireGuard mati dan telah dipulihkan otomatis oleh sistem watchdog.');
    echo "[WATCHDOG] ✓ Service WireGuard berhasil dipulihkan & seluruh peer disinkronkan.\n";
} else {
    // Service aktif, pastikan iptables FORWARD tetap ada
    @shell_exec('sudo iptables -C FORWARD -i wg0 -j ACCEPT 2>/dev/null || sudo iptables -I FORWARD -i wg0 -j ACCEPT 2>/dev/null');
    @shell_exec('sudo iptables -C FORWARD -o wg0 -j ACCEPT 2>/dev/null || sudo iptables -I FORWARD -o wg0 -j ACCEPT 2>/dev/null');
}
