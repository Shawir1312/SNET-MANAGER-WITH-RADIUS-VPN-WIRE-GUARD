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

    case 'sync_all_peers':
        $peerRes = wg_sync_all_peers();
        wg_sync_all_port_forwards();
        echo json_encode([
            'success' => $peerRes['success'],
            'count'   => $peerRes['count'],
            'errors'  => $peerRes['errors']
        ]);
        exit;

    case 'restart_service':
        if (function_exists('shell_exec')) {
            @shell_exec('sudo systemctl enable wg-quick@wg0 2>/dev/null');
            $out = @shell_exec('sudo systemctl restart wg-quick@wg0 2>&1');
            $status = trim((string)@shell_exec('sudo systemctl is-active wg-quick@wg0 2>/dev/null' ?: 'systemctl is-active wg-quick@wg0 2>/dev/null'));
            
            // Re-sync iptables and peers
            wg_sync_all_peers();
            wg_sync_all_port_forwards();
            
            echo json_encode([
                'success' => ($status === 'active'),
                'status'  => $status,
                'output'  => $out
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'shell_exec dinonaktifkan di PHP']);
        }
        exit;

    default:
        echo json_encode(['error' => 'Invalid action']);
        exit;
}
