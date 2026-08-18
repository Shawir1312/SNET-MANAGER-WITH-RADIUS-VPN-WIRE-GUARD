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
    'wg_subnet_prefix',
    'wg_interface',
    'wg_dns',
    'wg_mtu',
    'wg_server_pubkey',
    'wg_server_privkey'
];

try {
    foreach ($keys as $k) {
        if (isset($_POST[$k])) {
            set_wg_setting($k, trim($_POST[$k]));
        }
    }

    wg_log('save_settings', null, null, "Update konfigurasi server WireGuard");
    flash_set('success', 'Pengaturan Server WireGuard berhasil disimpan.');

} catch (Throwable $e) {
    flash_set('error', 'Gagal menyimpan pengaturan: ' . $e->getMessage());
}

header('Location: /index.php?page=wg_settings');
exit;
