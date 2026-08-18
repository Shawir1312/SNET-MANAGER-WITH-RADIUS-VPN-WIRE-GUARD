<?php
/**
 * Process — Hapus Router WireGuard
 */
define('IN_APP', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../include/functions.php';
require_once __DIR__ . '/../../include/wireguard_functions.php';

auth_check();

$id = (int)($_GET['id'] ?? 0);
$csrf = $_GET['csrf'] ?? '';

if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
    flash_set('error', 'Invalid CSRF token.');
    header('Location: /index.php?page=wg_routers');
    exit;
}

$router = db_fetch_one("SELECT * FROM wg_routers WHERE id = ?", 'i', [$id]);
if (!$router) {
    flash_set('error', 'Router tidak ditemukan.');
    header('Location: /index.php?page=wg_routers');
    exit;
}

try {
    // 1. Hapus aturan port forward iptables terkait
    $pfs = db_fetch_all("SELECT * FROM wg_port_forwards WHERE router_id = ?", 'i', [$id]);
    foreach ($pfs as $pf) {
        $finalIp = !empty($pf['target_ip']) ? $pf['target_ip'] : $router['tunnel_ip'];
        wg_remove_port_forward((int)$pf['public_port'], $finalIp, (int)$pf['target_port'], $pf['protocol']);
    }

    // 2. Hapus peer dari WireGuard Linux
    wg_sync_remove_peer($router['public_key']);

    // 3. Hapus dari DB
    db_execute("DELETE FROM wg_routers WHERE id = ?", 'i', [$id]);

    wg_log('delete_router', $id, $router['name'], "IP: {$router['tunnel_ip']}");
    flash_set('success', "Router '{$router['name']}' berhasil dihapus dari sistem.");

} catch (Throwable $e) {
    flash_set('error', 'Gagal menghapus router: ' . $e->getMessage());
}

header('Location: /index.php?page=wg_routers');
exit;
