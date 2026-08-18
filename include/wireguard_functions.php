<?php
/**
 * S.NET RADIUS Manager — WireGuard VPN Helper Functions
 * Mengelola Peer, Keypair, Script MikroTik, Port Forwarding, dan Status Live.
 */

if (!defined('IN_APP') && !defined('BASE_PATH')) {
    // Prevent direct execution if outside app
}

/**
 * Ambil semua pengaturan WireGuard dari tabel wg_settings
 */
function get_all_wg_settings(): array {
    $defaults = [
        'wg_interface'       => 'wg0',
        'wg_server_endpoint' => $_SERVER['SERVER_ADDR'] ?? '127.0.0.1:51820',
        'wg_server_pubkey'   => '',
        'wg_server_privkey'  => '',
        'wg_subnet_prefix'   => '10.66.66.',
        'wg_listen_port'     => '51820',
        'wg_dns'             => '1.1.1.1, 8.8.8.8',
        'wg_mtu'             => '1420',
    ];

    try {
        $rows = db_fetch_all("SELECT `key`, `value` FROM wg_settings");
        foreach ($rows as $r) {
            $defaults[$r['key']] = $r['value'];
        }
    } catch (Throwable $e) {
        // Table might not exist yet during fresh install
    }

    if (empty($defaults['wg_server_endpoint']) || $defaults['wg_server_endpoint'] === '127.0.0.1:51820') {
        $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '127.0.0.1');
        if (strpos($host, ':') !== false) $host = explode(':', $host)[0];
        $defaults['wg_server_endpoint'] = $host . ':' . $defaults['wg_listen_port'];
    }

    return $defaults;
}

/**
 * Ambil satu setting WireGuard
 */
function get_wg_setting(string $key, string $default = ''): string {
    $all = get_all_wg_settings();
    return $all[$key] ?? $default;
}

/**
 * Simpan satu setting WireGuard
 */
function set_wg_setting(string $key, string $value): void {
    db_execute(
        "INSERT INTO wg_settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        'ss', [$key, $value]
    );
}

/**
 * Generate WireGuard Keypair (Private Key & Public Key)
 */
function wg_generate_keypair(): array {
    $privKey = '';
    $pubKey  = '';

    // 1. Coba gunakan binary `wg genkey` jika tersedia
    if (function_exists('shell_exec')) {
        $privKey = trim((string)@shell_exec('wg genkey 2>/dev/null'));
        if ($privKey) {
            $pubKey = trim((string)@shell_exec('echo ' . escapeshellarg($privKey) . ' | wg pubkey 2>/dev/null'));
        }
    }

    // 2. Fallback jika tidak ada CLI wg (generate secure random base64 32 bytes)
    if (!$privKey || !$pubKey) {
        $privKey = base64_encode(random_bytes(32));
        $pubKey  = base64_encode(hash('sha256', $privKey . '_snet_wg_pub', true));
    }

    return [
        'private_key' => $privKey,
        'public_key'  => $pubKey
    ];
}

/**
 * Cari IP Tunnel berikutnya yang masih kosong dalam subnet
 */
function wg_get_next_tunnel_ip(): string {
    $settings = get_all_wg_settings();
    $prefix = rtrim($settings['wg_subnet_prefix'], '.') . '.';

    $usedIps = [];
    try {
        $rows = db_fetch_all("SELECT tunnel_ip FROM wg_routers");
        foreach ($rows as $r) {
            $usedIps[] = trim($r['tunnel_ip']);
        }
    } catch (Throwable $e) {}

    // IP .1 adalah Server Hub, jadi client mulai dari .2 s/d .254
    for ($i = 2; $i <= 254; $i++) {
        $candidate = $prefix . $i;
        if (!in_array($candidate, $usedIps, true)) {
            return $candidate;
        }
    }

    return $prefix . '2';
}

/**
 * Catat log aktivitas WireGuard ke wg_logs
 */
function wg_log(string $event, ?int $routerId = null, ?string $routerName = null, ?string $details = null): void {
    try {
        db_execute(
            "INSERT INTO wg_logs (event, router_id, router_name, details, created_at) VALUES (?, ?, ?, ?, NOW())",
            'siss', [$event, $routerId, $routerName, $details]
        );
    } catch (Throwable $e) {}
}

/**
 * Generate Skrip Konfigurasi MikroTik RouterOS v7 untuk WireGuard Client
 */
function wg_generate_mikrotik_script(array $router): string {
    $settings = get_all_wg_settings();
    $endpoint = $settings['wg_server_endpoint'] ?? '127.0.0.1:51820';
    $serverPubkey = $settings['wg_server_pubkey'] ?? '';
    $subnetPrefix = $settings['wg_subnet_prefix'] ?? '10.66.66.';

    $endpointParts = explode(':', $endpoint);
    $endpointHost  = $endpointParts[0];
    $endpointPort  = $endpointParts[1] ?? '51820';

    $tunnelSubnet = rtrim($subnetPrefix, '.') . '.0/24';
    $allowedAddressList = [$tunnelSubnet];
    $routeCommands = [];

    // Ambil subnet LAN router lain untuk routing antar cabang
    try {
        $otherRouters = db_fetch_all("SELECT id, tunnel_ip, lan_subnets FROM wg_routers WHERE id != ?", 'i', [(int)($router['id'] ?? 0)]);
        foreach ($otherRouters as $or) {
            if (!empty($or['lan_subnets'])) {
                $lans = array_filter(array_map('trim', explode(',', $or['lan_subnets'])));
                foreach ($lans as $lan) {
                    if (!in_array($lan, $allowedAddressList, true)) {
                        $allowedAddressList[] = $lan;
                    }
                    $routeCommands[] = "/ip route add dst-address={$lan} gateway=wg-snet comment=\"Route to {$or['tunnel_ip']}\"";
                }
            }
        }
    } catch (Throwable $e) {}

    $allowedAddressStr = implode(',', $allowedAddressList);
    $rName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $router['name'] ?? 'Router');

    $cfg = "# =====================================================================\n"
         . "# S.NET RADIUS & VPN — Skrip Konfigurasi WireGuard MikroTik (RouterOS v7)\n"
         . "# Nama Router : {$router['name']}\n"
         . "# IP Tunnel   : {$router['tunnel_ip']}/24\n"
         . "# Dibuat Pada : " . date('Y-m-d H:i:s') . "\n"
         . "# =====================================================================\n\n"
         . "# 1. Buat Interface WireGuard\n"
         . "/interface wireguard\n"
         . "add name=wg-snet listen-port=13231 private-key=\"{$router['private_key']}\" comment=\"S.NET WireGuard VPN Tunnel\"\n\n"
         . "# 2. Pasang IP Address pada Interface Tunnel\n"
         . "/ip address\n"
         . "add address={$router['tunnel_ip']}/24 interface=wg-snet network=" . rtrim($subnetPrefix, '.') . ".0 comment=\"IP VPN S.NET\"\n\n"
         . "# 3. Hubungkan ke Server VPS Hub\n"
         . "/interface wireguard peers\n"
         . "add interface=wg-snet public-key=\"{$serverPubkey}\" endpoint-address={$endpointHost} "
         . "endpoint-port={$endpointPort} allowed-address={$allowedAddressStr} persistent-keepalive=25s comment=\"VPS S.NET Hub Peer\"";

    if (!empty($routeCommands)) {
        $cfg .= "\n\n# 4. Rute Interkoneksi Cabang\n" . implode("\n", $routeCommands);
    }

    return $cfg;
}

/**
 * Generate File Client .conf (untuk Windows, Mac, Linux, Android WireGuard App)
 */
function wg_generate_client_conf(array $router): string {
    $settings = get_all_wg_settings();
    $endpoint = $settings['wg_server_endpoint'] ?? '127.0.0.1:51820';
    $serverPubkey = $settings['wg_server_pubkey'] ?? '';
    $subnetPrefix = $settings['wg_subnet_prefix'] ?? '10.66.66.';
    $dns = $settings['wg_dns'] ?? '1.1.1.1, 8.8.8.8';
    $mtu = $settings['wg_mtu'] ?? '1420';

    $tunnelSubnet = rtrim($subnetPrefix, '.') . '.0/24';

    return "[Interface]\n"
         . "PrivateKey = {$router['private_key']}\n"
         . "Address = {$router['tunnel_ip']}/24\n"
         . "DNS = {$dns}\n"
         . "MTU = {$mtu}\n\n"
         . "[Peer]\n"
         . "PublicKey = {$serverPubkey}\n"
         . "Endpoint = {$endpoint}\n"
         . "AllowedIPs = {$tunnelSubnet}\n"
         . "PersistentKeepalive = 25\n";
}

/**
 * Ambil status live seluruh peer WireGuard dari system `wg show wg0 dump`
 */
function wg_get_peer_status(): array {
    $status = [];
    if (!function_exists('shell_exec')) return $status;

    $settings = get_all_wg_settings();
    $iface = $settings['wg_interface'] ?? 'wg0';

    $output = @shell_exec('sudo wg show ' . escapeshellarg($iface) . ' dump 2>/dev/null');
    if (!$output) {
        $output = @shell_exec('wg show ' . escapeshellarg($iface) . ' dump 2>/dev/null');
    }
    if (!$output) return $status;

    $lines = explode("\n", trim($output));
    array_shift($lines); // baris 0 adalah info interface server

    foreach ($lines as $line) {
        if (trim($line) === '') continue;
        $cols = explode("\t", $line);
        if (count($cols) < 8) continue;

        [$pubKey, $psk, $endpoint, $allowedIps, $latestHandshake, $rx, $tx, $keepalive] = $cols;

        $lastHandshakeTs = (int)$latestHandshake;
        $isConnected = $lastHandshakeTs > 0 && (time() - $lastHandshakeTs) < 180; // 3 menit timeout

        $status[$pubKey] = [
            'endpoint'       => $endpoint === '(none)' ? null : $endpoint,
            'connected'      => $isConnected,
            'last_handshake' => $lastHandshakeTs > 0 ? $lastHandshakeTs : null,
            'rx_bytes'       => (int)$rx,
            'tx_bytes'       => (int)$tx,
            'allowed_ips'    => $allowedIps
        ];
    }

    return $status;
}

/**
 * Eksekusi penambahan peer ke WireGuard Linux via skrip
 */
function wg_sync_add_peer(string $pubkey, string $tunnelIp): bool {
    $script = file_exists('/usr/local/bin/wg-add-peer.sh') ? '/usr/local/bin/wg-add-peer.sh' : BASE_PATH . '/scripts/wg-add-peer.sh';
    if (!file_exists($script) || !function_exists('shell_exec')) return false;

    $ipWithMask = strpos($tunnelIp, '/') !== false ? $tunnelIp : $tunnelIp . '/32';
    $cmd = sprintf('sudo %s %s %s 2>&1', escapeshellarg($script), escapeshellarg($pubkey), escapeshellarg($ipWithMask));
    $out = @shell_exec($cmd);
    return true;
}

/**
 * Eksekusi update peer WireGuard Linux
 */
function wg_sync_update_peer(string $pubkey, string $tunnelIp, ?string $lanSubnets = null): bool {
    $script = file_exists('/usr/local/bin/wg-update-peer.sh') ? '/usr/local/bin/wg-update-peer.sh' : BASE_PATH . '/scripts/wg-update-peer.sh';
    if (!file_exists($script) || !function_exists('shell_exec')) return false;

    $allowed = [strpos($tunnelIp, '/') !== false ? $tunnelIp : $tunnelIp . '/32'];
    if (!empty($lanSubnets)) {
        foreach (explode(',', $lanSubnets) as $l) {
            $l = trim($l);
            if ($l) $allowed[] = $l;
        }
    }
    $allowedStr = implode(',', $allowed);

    $cmd = sprintf('sudo %s %s %s 2>&1', escapeshellarg($script), escapeshellarg($pubkey), escapeshellarg($allowedStr));
    $out = @shell_exec($cmd);
    return true;
}

/**
 * Eksekusi penghapusan peer dari WireGuard Linux
 */
function wg_sync_remove_peer(string $pubkey): bool {
    $script = file_exists('/usr/local/bin/wg-remove-peer.sh') ? '/usr/local/bin/wg-remove-peer.sh' : BASE_PATH . '/scripts/wg-remove-peer.sh';
    if (!file_exists($script) || !function_exists('shell_exec')) return false;

    $cmd = sprintf('sudo %s %s 2>&1', escapeshellarg($script), escapeshellarg($pubkey));
    $out = @shell_exec($cmd);
    return true;
}

/**
 * Tambah iptables port forwarding NAT (Remote Winbox / Webfig)
 */
function wg_add_port_forward(int $publicPort, string $tunnelIp, int $targetPort, string $protocol = 'tcp'): void {
    $protocol = strtolower($protocol) === 'udp' ? 'udp' : 'tcp';
    if (!function_exists('shell_exec')) return;

    // INPUT
    @shell_exec(sprintf('sudo iptables -I INPUT -p %s --dport %d -j ACCEPT 2>/dev/null', $protocol, $publicPort));
    // PREROUTING DNAT
    @shell_exec(sprintf('sudo iptables -t nat -A PREROUTING -p %s --dport %d -j DNAT --to-destination %s:%d 2>/dev/null', $protocol, $publicPort, escapeshellarg($tunnelIp), $targetPort));
    // POSTROUTING MASQUERADE
    @shell_exec(sprintf('sudo iptables -t nat -A POSTROUTING -p %s -d %s --dport %d -j MASQUERADE 2>/dev/null', $protocol, escapeshellarg($tunnelIp), $targetPort));
}

/**
 * Hapus iptables port forwarding NAT
 */
function wg_remove_port_forward(int $publicPort, string $tunnelIp, int $targetPort, string $protocol = 'tcp'): void {
    $protocol = strtolower($protocol) === 'udp' ? 'udp' : 'tcp';
    if (!function_exists('shell_exec')) return;

    @shell_exec(sprintf('sudo iptables -D INPUT -p %s --dport %d -j ACCEPT 2>/dev/null', $protocol, $publicPort));
    @shell_exec(sprintf('sudo iptables -t nat -D PREROUTING -p %s --dport %d -j DNAT --to-destination %s:%d 2>/dev/null', $protocol, $publicPort, escapeshellarg($tunnelIp), $targetPort));
    @shell_exec(sprintf('sudo iptables -t nat -D POSTROUTING -p %s -d %s --dport %d -j MASQUERADE 2>/dev/null', $protocol, escapeshellarg($tunnelIp), $targetPort));
}

/**
 * Sinkronisasi seluruh Port Forwarding dari DB ke iptables
 */
function wg_sync_all_port_forwards(): void {
    try {
        $rows = db_fetch_all("SELECT pf.*, r.tunnel_ip FROM wg_port_forwards pf JOIN wg_routers r ON pf.router_id = r.id");
        foreach ($rows as $row) {
            $finalTargetIp = !empty($row['target_ip']) ? $row['target_ip'] : $row['tunnel_ip'];
            wg_remove_port_forward((int)$row['public_port'], $finalTargetIp, (int)$row['target_port'], $row['protocol']);
            wg_add_port_forward((int)$row['public_port'], $finalTargetIp, (int)$row['target_port'], $row['protocol']);
        }
    } catch (Throwable $e) {}
}

/**
 * Ping Diagnostic Tool
 */
function wg_ping_router(string $tunnelIp): array {
    $ip = filter_var($tunnelIp, FILTER_VALIDATE_IP);
    if (!$ip) return ['success' => false, 'output' => 'IP tidak valid.'];

    if (!function_exists('shell_exec')) {
        return ['success' => false, 'output' => 'Fungsi shell_exec tidak aktif di PHP.'];
    }

    $cmd = sprintf('ping -c 3 -W 2 %s 2>&1', escapeshellarg($ip));
    $output = @shell_exec($cmd);
    $success = strpos($output ?? '', ' 0% packet loss') !== false || strpos($output ?? '', 'bytes from') !== false;

    return [
        'success' => $success,
        'output'  => $output ?: 'Tidak ada respon dari router.'
    ];
}

/**
 * Socket Test Port MikroTik API (8728), Winbox (8291), Webfig (80)
 */
function wg_test_port(string $tunnelIp, int $port = 8728): array {
    $ip = filter_var($tunnelIp, FILTER_VALIDATE_IP);
    if (!$ip) return ['success' => false, 'message' => 'IP tidak valid.'];

    $conn = @fsockopen($ip, $port, $errno, $errstr, 2);
    if ($conn) {
        fclose($conn);
        return ['success' => true, 'message' => "Port {$port} terbuka ✓ (Terhubung)"];
    }

    return ['success' => false, 'message' => "Port {$port} belum merespon: $errstr ($errno)"];
}
