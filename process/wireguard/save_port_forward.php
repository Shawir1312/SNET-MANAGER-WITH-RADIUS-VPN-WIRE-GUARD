<?php
/**
 * Process — Simpan Port Forwarding (NAT)
 */
define('IN_APP', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../include/functions.php';
require_once __DIR__ . '/../../include/wireguard_functions.php';

auth_check();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /index.php?page=wg_port_forwarding');
    exit;
}

if (empty($_POST['csrf']) || $_POST['csrf'] !== $_SESSION['csrf_token']) {
    flash_set('error', 'Invalid CSRF token.');
    header('Location: /index.php?page=wg_port_forwarding');
    exit;
}

$router_id   = (int)post('router_id');
$public_port = (int)post('public_port');
$target_port = (int)post('target_port');
$target_ip   = trim(post('target_ip', ''));
$protocol    = strtolower(post('protocol', 'tcp')) === 'udp' ? 'udp' : 'tcp';

if (!$router_id || !$public_port || !$target_port) {
    flash_set('error', 'Router, Port Publik, dan Target Port wajib diisi.');
    header('Location: /index.php?page=wg_port_forwarding');
    exit;
}

$router = db_fetch_one("SELECT * FROM wg_routers WHERE id = ?", 'i', [$router_id]);
if (!$router) {
    flash_set('error', 'Router tidak ditemukan.');
    header('Location: /index.php?page=wg_port_forwarding');
    exit;
}

$finalTargetIp = $target_ip ?: $router['tunnel_ip'];

try {
    // Cek duplikasi public port & protocol
    $exist = db_fetch_one("SELECT id FROM wg_port_forwards WHERE public_port = ? AND protocol = ?", 'is', [$public_port, $protocol]);
    if ($exist) {
        throw new Exception("Port publik {$public_port}/{$protocol} sudah digunakan pada aturan lain.");
    }

    db_execute(
        "INSERT INTO wg_port_forwards (router_id, public_port, target_port, target_ip, protocol) VALUES (?, ?, ?, ?, ?)",
        'iiiss',
        [$router_id, $public_port, $target_port, $target_ip ?: null, $protocol]
    );

    // Terapkan ke iptables Linux
    wg_add_port_forward($public_port, $finalTargetIp, $target_port, $protocol);

    wg_log('add_port_forward', $router_id, $router['name'], "Pub: {$public_port} -> {$finalTargetIp}:{$target_port} ({$protocol})");
    flash_set('success', "Port Forwarding port {$public_port} -> {$finalTargetIp}:{$target_port} berhasil dibuat dan diaktifkan!");

} catch (Throwable $e) {
    flash_set('error', 'Gagal membuat port forwarding: ' . $e->getMessage());
}

header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/index.php?page=wg_port_forwarding'));
exit;
