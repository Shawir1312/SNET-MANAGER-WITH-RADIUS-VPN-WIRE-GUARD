<?php
/**
 * Process — Simpan Pengaturan Server WireGuard
 */
define('IN_APP', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../include/functions.php';
require_once __DIR__ . '/../../include/wireguard_functions.php';

auth_check();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /index.php?page=wg_settings');
    exit;
}

if (empty($_POST['csrf']) || $_POST['csrf'] !== $_SESSION['csrf_token']) {
    flash_set('error', 'Invalid CSRF token.');
    header('Location: /index.php?page=wg_settings');
    exit;
}

$keys = [
    'wg_server_endpoint',
    'wg_listen_port',
    'wg_remote_public_host',
    'wg_remote_port_range',
    'wg_subnet_prefix',
    'wg_interface',
    'wg_dns',
    'wg_mtu',
    'wg_server_pubkey',
    'wg_server_privkey'
];

try {
    $oldSettings = get_all_wg_settings();
    $oldPrefix = $oldSettings['wg_subnet_prefix'] ?? '10.66.66.';

    foreach ($keys as $k) {
        if (isset($_POST[$k])) {
            set_wg_setting($k, trim($_POST[$k]));
        }
    }

    $newSettings = get_all_wg_settings();
    $newPrefix = $newSettings['wg_subnet_prefix'] ?? '10.66.66.';

    // Jika subnet berubah, sinkronkan ke wg0.conf
    if ($oldPrefix !== $newPrefix && function_exists('shell_exec')) {
        $serverIp = $newPrefix . '1/24';
        @shell_exec("sudo sed -i -E 's/^Address[[:space:]]*=.*/Address = {$serverIp}/g' /etc/wireguard/wg0.conf 2>/dev/null");
        @shell_exec("sudo systemctl restart wg-quick@wg0 >/dev/null 2>&1 &");
    }

    wg_log('save_settings', null, null, "Update konfigurasi server WireGuard (Subnet: {$newPrefix}0/24)");
    flash_set('success', 'Pengaturan Server WireGuard & Subnet Tunnel berhasil disimpan!');

} catch (Throwable $e) {
    flash_set('error', 'Gagal menyimpan pengaturan: ' . $e->getMessage());
}

header('Location: /index.php?page=wg_settings');
exit;
