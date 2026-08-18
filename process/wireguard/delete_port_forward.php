<?php
/**
 * Process — Hapus Port Forwarding (NAT)
 */
define('IN_APP', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../include/functions.php';
require_once __DIR__ . '/../../include/wireguard_functions.php';

auth_check();

$id   = (int)($_GET['id'] ?? 0);
$csrf = $_GET['csrf'] ?? '';

if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
    flash_set('error', 'Invalid CSRF token.');
    header('Location: /index.php?page=wg_port_forwarding');
    exit;
}

$pf = db_fetch_one("SELECT pf.*, r.name as router_name, r.tunnel_ip as router_tunnel_ip 
                    FROM wg_port_forwards pf 
                    JOIN wg_routers r ON pf.router_id = r.id 
                    WHERE pf.id = ?", 'i', [$id]);

if (!$pf) {
    flash_set('error', 'Aturan port forwarding tidak ditemukan.');
    header('Location: /index.php?page=wg_port_forwarding');
    exit;
}

try {
    $targetIp = !empty($pf['target_ip']) ? $pf['target_ip'] : $pf['router_tunnel_ip'];
    
    // Hapus dari iptables
    wg_remove_port_forward((int)$pf['public_port'], $targetIp, (int)$pf['target_port'], $pf['protocol']);

    // Hapus dari DB
    db_execute("DELETE FROM wg_port_forwards WHERE id = ?", 'i', [$id]);

    wg_log('delete_port_forward', $pf['router_id'], $pf['router_name'], "Pub: {$pf['public_port']}");
    flash_set('success', "Port forwarding port {$pf['public_port']} berhasil dihapus.");

} catch (Throwable $e) {
    flash_set('error', 'Gagal menghapus port forwarding: ' . $e->getMessage());
}

$redirect = $_GET['redirect'] ?? '';
$routerId = (int)($_GET['router_id'] ?? 0);
if ($redirect === 'router_detail' && $routerId > 0) {
    header("Location: /index.php?page=wg_router_detail&id={$routerId}");
} else {
    header('Location: /index.php?page=wg_port_forwarding');
}
exit;
