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

while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

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
        $out = '';
        $canRun = function_exists('safe_shell_exec');

        if ($canRun) {
            // Bersihkan zombie interface jika sebelumnya nyangkut di state deactivating
            safe_shell_exec('sudo -n systemctl stop wg-quick@wg0 2>/dev/null');
            safe_shell_exec('sudo -n ip link delete wg0 2>/dev/null');

            // Aktifkan & nyalakan service secara non-blocking
            safe_shell_exec('sudo -n systemctl enable wg-quick@wg0 2>/dev/null');
            $out = (string)safe_shell_exec('sudo -n systemctl restart wg-quick@wg0 2>&1');

            // Aktifkan routing kernel & intra-WireGuard forwarding seketika
            safe_shell_exec('sudo -n sysctl -w net.ipv4.ip_forward=1 2>/dev/null');
            safe_shell_exec('sudo -n sysctl -w net.ipv4.conf.all.send_redirects=0 2>/dev/null');
            safe_shell_exec('sudo -n sysctl -w net.ipv4.conf.wg0.send_redirects=0 2>/dev/null');
            safe_shell_exec('sudo -n iptables -I FORWARD 1 -i wg0 -o wg0 -j ACCEPT 2>/dev/null');
            safe_shell_exec('sudo -n iptables -I FORWARD 1 -i wg0 -j ACCEPT 2>/dev/null');
            safe_shell_exec('sudo -n iptables -I FORWARD 1 -o wg0 -j ACCEPT 2>/dev/null');
        }

        // Cek status running (murni via Linux filesystem kernel /sys/class/net/wg0 & systemctl)
        $isActive = false;
        $status = 'inactive';
        if (@file_exists('/sys/class/net/wg0') || @is_dir('/sys/class/net/wg0') || @file_exists('/sys/devices/virtual/net/wg0')) {
            $isActive = true;
            $status = 'active';
        } elseif ($canRun) {
            $chk = trim((string)(safe_shell_exec('sudo -n systemctl is-active wg-quick@wg0 2>/dev/null') ?: safe_shell_exec('systemctl is-active wg-quick@wg0 2>/dev/null')));
            if ($chk !== '') {
                $status = $chk;
                if ($status === 'active') {
                    $isActive = true;
                }
            }
        }

        if ($isActive) {
            try {
                wg_sync_all_peers();
                wg_sync_all_port_forwards();
            } catch (\Throwable $e) {}
        }

        $isAuthErr = (stripos((string)$out, 'password') !== false || stripos((string)$out, 'a password is required') !== false || stripos((string)$out, 'sudo:') !== false);

        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode([
            'success'  => $isActive,
            'status'   => $status,
            'output'   => $out,
            'need_ssh' => !$isActive,
            'message'  => $isActive 
                ? 'Service WireGuard berhasil dijalankan dan aktif!' 
                : ($isAuthErr 
                    ? "User web server membutuhkan izin sudo NOPASSWD atau hak root. Silakan jalankan di terminal SSH VPS:\nsudo systemctl restart wg-quick@wg0"
                    : "Service WireGuard masih {$status}. Detail: " . ($out ?: 'Gunakan terminal SSH VPS: sudo systemctl restart wg-quick@wg0'))
        ]);
        exit;

    default:
        echo json_encode(['error' => 'Invalid action']);
        exit;
}
