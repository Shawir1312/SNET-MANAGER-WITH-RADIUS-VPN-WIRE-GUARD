<?php
/**
 * Process — AJAX Diagnostics & Keygen Tools for WireGuard
 */
define('IN_APP', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../include/functions.php';
require_once __DIR__ . '/../../include/wireguard_functions.php';

auth_check();
header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'generate_keys':
        $keys = wg_generate_keypair();
        echo json_encode($keys);
        exit;

    case 'get_next_ip':
        $ip = wg_get_next_tunnel_ip();
        echo json_encode(['ip' => $ip]);
        exit;

    case 'ping':
        $ip = $_GET['ip'] ?? '';
        $res = wg_ping_router($ip);
        echo json_encode($res);
        exit;

    case 'port_check':
        $ip = $_GET['ip'] ?? '';
        $port = (int)($_GET['port'] ?? 8728);
        $res = wg_test_port($ip, $port);
        echo json_encode($res);
        exit;

    case 'sync_firewall':
        wg_sync_all_port_forwards();
        echo json_encode(['success' => true]);
        exit;

    default:
        echo json_encode(['error' => 'Invalid action']);
        exit;
}
