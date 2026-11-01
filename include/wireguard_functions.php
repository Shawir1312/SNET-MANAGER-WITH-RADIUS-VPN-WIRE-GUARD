<?php
/**
 * S.NET RADIUS Manager — WireGuard VPN Helper Functions
 * Mengelola Peer, Keypair, Script MikroTik, Port Forwarding, dan Status Live.
 */

if (!defined('IN_APP') && !defined('BASE_PATH')) {
    // Prevent direct execution if outside app
}

require_once __DIR__ . '/functions.php';

if (!function_exists('safe_shell_exec')) {
    function safe_shell_exec(string $cmd): ?string {
        if (!function_exists('shell_exec')) return null;
        $disabled = (string)ini_get('disable_functions');
        if ($disabled !== '') {
            $list = array_map('trim', explode(',', $disabled));
            if (in_array('shell_exec', $list, true)) return null;
        }
        try {
            $out = @shell_exec($cmd);
            return ($out !== false && $out !== null) ? (string)$out : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}

/**
 * Ambil semua pengaturan WireGuard dari tabel wg_settings
 */
function get_all_wg_settings(): array {
    $defaults = [
        'wg_interface'          => 'wg0',
        'wg_server_endpoint'    => $_SERVER['SERVER_ADDR'] ?? '127.0.0.1:51820',
        'wg_remote_public_host' => '',
        'wg_remote_port_range'  => '20000-58000',
        'wg_server_pubkey'      => '',
        'wg_server_privkey'     => '',
        'wg_subnet_prefix'      => '10.66.66.',
        'wg_listen_port'        => '51820',
        'wg_dns'                => '1.1.1.1, 8.8.8.8',
        'wg_mtu'                => '1420',
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
 * Ambil host publik / IP asli / domain khusus akses remote (NAT & ONT)
 * Mendukung akses langsung bypass Cloudflare Tunnel
 */
function get_remote_public_host(): string {
    $settings = get_all_wg_settings();
    if (!empty($settings['wg_remote_public_host'])) {
        return trim($settings['wg_remote_public_host']);
    }
    if (!empty($settings['wg_server_endpoint'])) {
        $epHost = explode(':', $settings['wg_server_endpoint'])[0];
        if ($epHost && $epHost !== '127.0.0.1' && $epHost !== 'localhost') {
            return $epHost;
        }
    }
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '127.0.0.1');
    if (strpos($host, ':') !== false) $host = explode(':', $host)[0];
    return $host;
}

/**
 * Ambil satu setting WireGuard
 */
function get_wg_setting(string $key, string $default = ''): string {
    $all = get_all_wg_settings();
    return $all[$key] ?? $default;
}

/**
 * Normalisasi format subnet (misal '10.66.66.0/24' atau '10.10.10.' menjadi '10.66.66.')
 */
function wg_normalize_subnet_prefix(string $input): string {
    $input = trim($input);
    $clean = preg_replace('/\/\d+$/', '', $input); // Hapus /24 dsb
    $clean = preg_replace('/\.0$/', '', $clean);    // Hapus .0 di ujung
    $clean = rtrim($clean, '.') . '.';

    $parts = explode('.', rtrim($clean, '.'));
    if (count($parts) === 3 && is_numeric($parts[0]) && is_numeric($parts[1]) && is_numeric($parts[2])) {
        return $clean;
    }
    return '10.66.66.';
}

/**
 * Simpan satu setting WireGuard
 */
function set_wg_setting(string $key, string $value): void {
    if ($key === 'wg_subnet_prefix') {
        $value = wg_normalize_subnet_prefix($value);
    }
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
    if (function_exists('safe_shell_exec')) {
        $privKey = trim((string)safe_shell_exec('wg genkey 2>/dev/null'));
        if ($privKey) {
            $pubKey = trim((string)safe_shell_exec('echo ' . escapeshellarg($privKey) . ' | wg pubkey 2>/dev/null'));
        }
    } elseif (function_exists('shell_exec')) {
        try {
            $privKey = trim((string)@shell_exec('wg genkey 2>/dev/null'));
            if ($privKey) {
                $pubKey = trim((string)@shell_exec('echo ' . escapeshellarg($privKey) . ' | wg pubkey 2>/dev/null'));
            }
        } catch (\Throwable $e) {}
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
                $lans = normalize_lan_subnets($or['lan_subnets']);
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
    $listenPort = !empty($router['listen_port']) ? (int)$router['listen_port'] : (13230 + ((int)($router['id'] ?? 1) % 1000));

    $cfg = "# =====================================================================\n"
         . "# S.NET RADIUS & VPN — Skrip Konfigurasi WireGuard MikroTik (RouterOS v7)\n"
         . "# Nama Router : {$router['name']}\n"
         . "# IP Tunnel   : {$router['tunnel_ip']}/24\n"
         . "# Listen Port : {$listenPort}\n"
         . "# Dibuat Pada : " . date('Y-m-d H:i:s') . "\n"
         . "# =====================================================================\n\n"
         . "# 1. Buat Interface WireGuard\n"
         . "/interface wireguard\n"
         . "add name=wg-snet listen-port={$listenPort} private-key=\"{$router['private_key']}\" comment=\"S.NET WireGuard VPN Tunnel\"\n\n"
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

    $cfg .= "\n\n# 5. Firewall & NAT Masquerade (Wajib agar VPS bisa Ping & Remote ONT / Subnet Lokal)\n"
          . "/ip firewall filter\n"
          . "add chain=input in-interface=wg-snet action=accept place-before=0 comment=\"Allow SNET WireGuard Inbound Input\"\n"
          . "add chain=forward in-interface=wg-snet action=accept place-before=0 comment=\"Allow SNET WireGuard Inbound Forward\"\n"
          . "/ip firewall nat\n"
          . "add chain=srcnat src-address=" . rtrim($subnetPrefix, '.') . ".0/24 action=masquerade place-before=0 comment=\"Masquerade WireGuard VPS to LAN & ONT\"\n";

    if (!empty($router['lan_subnets'])) {
        foreach (explode(',', $router['lan_subnets']) as $ls) {
            $ls = trim($ls);
            if ($ls) {
                $cfg .= "add chain=srcnat dst-address={$ls} action=masquerade place-before=0 comment=\"Masquerade to {$ls}\"\n";
            }
        }
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
    $dns = trim($settings['wg_dns'] ?? '1.1.1.1, 8.8.8.8');
    $mtu = (int)($settings['wg_mtu'] ?? 1420);

    $tunnelSubnet = rtrim($subnetPrefix, '.') . '.0/24';
    $allowedList = [$tunnelSubnet];

    // Sertakan subnet LAN router lain jika ada
    try {
        $otherRouters = db_fetch_all("SELECT lan_subnets FROM wg_routers WHERE id != ?", 'i', [(int)($router['id'] ?? 0)]);
        foreach ($otherRouters as $or) {
            if (!empty($or['lan_subnets'])) {
                $normLans = normalize_lan_subnets($or['lan_subnets']);
                foreach ($normLans as $lan) {
                    if (!in_array($lan, $allowedList, true)) {
                        $allowedList[] = $lan;
                    }
                }
            }
        }
    } catch (Throwable $e) {}

    $allowedStr = implode(', ', $allowedList);
    $cleanIp = preg_replace('/\/\d+$/', '', trim($router['tunnel_ip']));

    $conf = "[Interface]\n"
          . "PrivateKey = " . trim($router['private_key']) . "\n"
          . "Address = {$cleanIp}/32\n";

    if (!empty($dns)) {
        $conf .= "DNS = {$dns}\n";
    }
    if ($mtu > 0) {
        $conf .= "MTU = {$mtu}\n";
    }

    $conf .= "\n[Peer]\n"
          . "PublicKey = " . trim($serverPubkey) . "\n"
          . "Endpoint = " . trim($endpoint) . "\n"
          . "AllowedIPs = {$allowedStr}\n"
          . "PersistentKeepalive = 25\n";

    return $conf;
}

/**
 * Ambil status live seluruh peer WireGuard dari system `wg show wg0 dump`
 */
function wg_get_peer_status(): array {
    $status = [];
    $canRun = function_exists('safe_shell_exec');

    $settings = get_all_wg_settings();
    $iface = $settings['wg_interface'] ?? 'wg0';

    $output = '';
    if ($canRun) {
        $output = safe_shell_exec('sudo -n wg show ' . escapeshellarg($iface) . ' dump 2>/dev/null');
        if (!$output) {
            $output = safe_shell_exec('wg show ' . escapeshellarg($iface) . ' dump 2>/dev/null');
        }
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
 * Eksekusi penambahan peer ke WireGuard Linux via skrip & kernel langsung
 */
function wg_sync_add_peer(string $pubkey, string $tunnelIp): bool {
    $script = BASE_PATH . '/scripts/wg-add-peer.sh';
    $sysScript = '/usr/local/bin/wg-add-peer.sh';
    $ipWithMask = strpos($tunnelIp, '/') !== false ? $tunnelIp : $tunnelIp . '/32';

    // 1. Daftarkan langsung ke kernel wg0 live (dengan persistent-keepalive 25s)
    if (function_exists('safe_shell_exec')) {
        safe_shell_exec('wg set wg0 peer ' . escapeshellarg($pubkey) . ' allowed-ips ' . escapeshellarg($ipWithMask) . ' persistent-keepalive 25 2>/dev/null || sudo -n wg set wg0 peer ' . escapeshellarg($pubkey) . ' allowed-ips ' . escapeshellarg($ipWithMask) . ' persistent-keepalive 25 2>/dev/null');
    }

    // 2. Persist ke /etc/wireguard/wg0.conf via helper script
    if (function_exists('safe_shell_exec')) {
        $cmd = sprintf('sudo -n %s %s %s 2>&1 || sudo -n bash %s %s %s 2>&1 || bash %s %s %s 2>&1',
            escapeshellarg($sysScript), escapeshellarg($pubkey), escapeshellarg($ipWithMask),
            escapeshellarg($script), escapeshellarg($pubkey), escapeshellarg($ipWithMask),
            escapeshellarg($script), escapeshellarg($pubkey), escapeshellarg($ipWithMask)
        );
        safe_shell_exec($cmd);
    }

    return true;
}

/**
 * Normalisasi input Subnet LAN (bisa pisah koma, spasi, enter)
 * dan mengoreksi host bits (contoh: 192.168.9.1/24 -> 192.168.9.0/24)
 * agar valid di routing kernel Linux dan WireGuard AllowedIPs.
 */
function normalize_lan_subnets(?string $raw): array {
    if (empty($raw)) return [];
    $tokens = preg_split('/[,\s;]+/', trim($raw));
    $cleaned = [];

    foreach ($tokens as $t) {
        $t = trim($t);
        if (!$t) continue;

        if (strpos($t, '/') === false) {
            if (filter_var($t, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $cleaned[] = $t . '/32';
            }
            continue;
        }

        list($ip, $mask) = explode('/', $t, 2);
        $ip = trim($ip);
        $mask = (int)trim($mask);

        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || $mask < 0 || $mask > 32) {
            continue;
        }

        if ($mask === 32) {
            $cleaned[] = $ip . '/32';
            continue;
        }

        // Hitung network address murni (hapus host bits)
        $ipLong = ip2long($ip);
        $netmask = $mask == 0 ? 0 : (~((1 << (32 - $mask)) - 1) & 0xFFFFFFFF);
        $networkLong = $ipLong & $netmask;
        $networkIp = long2ip($networkLong);

        $cleanCidr = $networkIp . '/' . $mask;
        if (!in_array($cleanCidr, $cleaned, true)) {
            $cleaned[] = $cleanCidr;
        }
    }

    return $cleaned;
}

/**
 * Eksekusi update peer WireGuard Linux
 */
function wg_sync_update_peer(string $pubkey, string $tunnelIp, ?string $lanSubnets = null): bool {
    $script = BASE_PATH . '/scripts/wg-update-peer.sh';
    $sysScript = '/usr/local/bin/wg-update-peer.sh';
    $tunnelCidr = strpos($tunnelIp, '/') !== false ? $tunnelIp : $tunnelIp . '/32';
    $allowed = [$tunnelCidr];

    // Normalisasi daftar subnet LAN agar kebal salah ketik host bits (192.168.9.1/24 -> 192.168.9.0/24)
    $normSubnets = normalize_lan_subnets($lanSubnets);
    foreach ($normSubnets as $sub) {
        $allowed[] = $sub;
        if (function_exists('safe_shell_exec')) {
            // Tambahkan rute network ke kernel Linux
            safe_shell_exec('ip route replace ' . escapeshellarg($sub) . ' dev wg0 2>/dev/null || sudo -n ip route replace ' . escapeshellarg($sub) . ' dev wg0 2>/dev/null');
        }
    }

    // Pasang juga host route /32 untuk setiap IP spesifik yang diinput agar ping gateway router LAN selalu reply
    if (!empty($lanSubnets)) {
        $rawTokens = preg_split('/[,\s;]+/', trim($lanSubnets));
        foreach ($rawTokens as $rt) {
            $rt = trim($rt);
            if (!$rt) continue;
            $rawIp = explode('/', $rt)[0];
            if (filter_var($rawIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                if (function_exists('safe_shell_exec')) {
                    safe_shell_exec('ip route replace ' . escapeshellarg($rawIp . '/32') . ' dev wg0 2>/dev/null || sudo -n ip route replace ' . escapeshellarg($rawIp . '/32') . ' dev wg0 2>/dev/null');
                }
            }
        }
    }

    $allowedStr = implode(',', array_unique($allowed));

    // Update live kernel (dengan persistent-keepalive 25s)
    if (function_exists('safe_shell_exec')) {
        safe_shell_exec('wg set wg0 peer ' . escapeshellarg($pubkey) . ' allowed-ips ' . escapeshellarg($allowedStr) . ' persistent-keepalive 25 2>/dev/null || sudo -n wg set wg0 peer ' . escapeshellarg($pubkey) . ' allowed-ips ' . escapeshellarg($allowedStr) . ' persistent-keepalive 25 2>/dev/null');
    }

    // Persist via script
    if (function_exists('safe_shell_exec')) {
        $cmd = sprintf('sudo -n %s %s %s 2>&1 || sudo -n bash %s %s %s 2>&1 || bash %s %s %s 2>&1',
            escapeshellarg($sysScript), escapeshellarg($pubkey), escapeshellarg($allowedStr),
            escapeshellarg($script), escapeshellarg($pubkey), escapeshellarg($allowedStr),
            escapeshellarg($script), escapeshellarg($pubkey), escapeshellarg($allowedStr)
        );
        safe_shell_exec($cmd);
    }

    return true;
}

/**
 * Eksekusi penghapusan peer dari WireGuard Linux
 */
function wg_sync_remove_peer(string $pubkey): bool {
    $script = BASE_PATH . '/scripts/wg-remove-peer.sh';
    $sysScript = '/usr/local/bin/wg-remove-peer.sh';

    if (function_exists('safe_shell_exec')) {
        safe_shell_exec('wg set wg0 peer ' . escapeshellarg($pubkey) . ' remove 2>/dev/null || sudo -n wg set wg0 peer ' . escapeshellarg($pubkey) . ' remove 2>/dev/null');
        $cmd = sprintf('sudo -n %s %s 2>&1 || sudo -n bash %s %s 2>&1 || bash %s %s 2>&1',
            escapeshellarg($sysScript), escapeshellarg($pubkey),
            escapeshellarg($script), escapeshellarg($pubkey),
            escapeshellarg($script), escapeshellarg($pubkey)
        );
        safe_shell_exec($cmd);
    }

    return true;
}

/**
 * Sinkronisasi seluruh router / peer dari database wg_routers ke WireGuard Linux & wg0.conf
 */
function wg_sync_all_peers(): array {
    $synced = 0;
    $errors = [];
    try {
        // 1. Pastikan kernel IP forwarding & iptables antar-peer aktif di tingkat atas
        if (function_exists('safe_shell_exec')) {
            $settings = get_all_wg_settings();
            $subnetPrefix = $settings['wg_subnet_prefix'] ?? '10.77.77.';
            $subnetCidr = rtrim($subnetPrefix, '.') . '.0/24';

            safe_shell_exec('sudo -n sysctl -w net.ipv4.ip_forward=1 2>/dev/null');
            safe_shell_exec('sudo -n sysctl -w net.ipv4.conf.all.send_redirects=0 2>/dev/null');
            safe_shell_exec('sudo -n sysctl -w net.ipv4.conf.wg0.send_redirects=0 2>/dev/null');
            safe_shell_exec('sudo -n sysctl -w net.ipv4.conf.all.rp_filter=2 2>/dev/null');
            safe_shell_exec('sudo -n sysctl -w net.ipv4.conf.wg0.rp_filter=2 2>/dev/null');

            // Izinkan forwarding antar-peer di baris paling atas
            safe_shell_exec('sudo -n iptables -I FORWARD 1 -i wg0 -o wg0 -j ACCEPT 2>/dev/null');
            safe_shell_exec('sudo -n iptables -I FORWARD 1 -i wg0 -j ACCEPT 2>/dev/null');
            safe_shell_exec('sudo -n iptables -I FORWARD 1 -o wg0 -j ACCEPT 2>/dev/null');

            // Jangan masquerade traffic internal antar-client VPN (10.77.77.x -> 10.77.77.y) agar ping & routing asli jalan
            safe_shell_exec(sprintf('sudo -n iptables -t nat -I POSTROUTING 1 -s %s -d %s -j ACCEPT 2>/dev/null', escapeshellarg($subnetCidr), escapeshellarg($subnetCidr)));
        }

        $routers = db_fetch_all("SELECT * FROM wg_routers");
        foreach ($routers as $r) {
            $pub = trim($r['public_key'] ?? '');
            $ip  = trim($r['tunnel_ip'] ?? '');
            $lan = trim($r['lan_subnets'] ?? '');
            if (!$pub || !$ip) continue;

            // Auto-perbaiki subnet LAN yang formatnya ada host bits atau beda pemisah
            if (!empty($lan)) {
                $normArr = normalize_lan_subnets($lan);
                $normStr = implode(', ', $normArr);
                if ($normStr !== $lan) {
                    try {
                        db_execute("UPDATE wg_routers SET lan_subnets = ? WHERE id = ?", 'si', [$normStr, (int)$r['id']]);
                        $lan = $normStr;
                    } catch (Throwable $e) {}
                }
            }

            $ok = wg_sync_add_peer($pub, $ip);
            if (!empty($lan)) {
                wg_sync_update_peer($pub, $ip, $lan);
            }
            if ($ok) {
                $synced++;
            } else {
                $errors[] = "Gagal sinkron peer: {$r['name']} ({$ip})";
            }
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }

    return [
        'success' => count($errors) === 0,
        'count'   => $synced,
        'errors'  => $errors
    ];
}

/**
 * Tambah iptables port forwarding NAT (Remote Winbox / Webfig)
 */
function wg_add_port_forward(int $publicPort, string $tunnelIp, int $targetPort, string $protocol = 'tcp'): void {
    $protocol = strtolower($protocol) === 'udp' ? 'udp' : 'tcp';
    if (!function_exists('safe_shell_exec')) return;

    // Buka di UFW jika UFW aktif
    safe_shell_exec(sprintf('sudo -n ufw allow %d/%s 2>/dev/null', $publicPort, $protocol));
    // INPUT ACCEPT
    safe_shell_exec(sprintf('sudo -n iptables -I INPUT -p %s --dport %d -j ACCEPT 2>/dev/null', $protocol, $publicPort));
    // FORWARD ACCEPT (masuk dan keluar)
    safe_shell_exec(sprintf('sudo -n iptables -I FORWARD -p %s -d %s --dport %d -j ACCEPT 2>/dev/null', $protocol, escapeshellarg($tunnelIp), $targetPort));
    safe_shell_exec('sudo -n iptables -I FORWARD -i wg0 -j ACCEPT 2>/dev/null');
    safe_shell_exec('sudo -n iptables -I FORWARD -o wg0 -j ACCEPT 2>/dev/null');
    // PREROUTING DNAT
    safe_shell_exec(sprintf('sudo -n iptables -t nat -A PREROUTING -p %s --dport %d -j DNAT --to-destination %s:%d 2>/dev/null', $protocol, $publicPort, escapeshellarg($tunnelIp), $targetPort));
    // POSTROUTING MASQUERADE
    safe_shell_exec(sprintf('sudo -n iptables -t nat -A POSTROUTING -p %s -d %s --dport %d -j MASQUERADE 2>/dev/null', $protocol, escapeshellarg($tunnelIp), $targetPort));
}

/**
 * Hapus iptables port forwarding NAT
 */
function wg_remove_port_forward(int $publicPort, string $tunnelIp, int $targetPort, string $protocol = 'tcp'): void {
    $protocol = strtolower($protocol) === 'udp' ? 'udp' : 'tcp';
    if (!function_exists('safe_shell_exec')) return;

    safe_shell_exec(sprintf('sudo -n ufw delete allow %d/%s 2>/dev/null', $publicPort, $protocol));
    safe_shell_exec(sprintf('sudo -n iptables -D INPUT -p %s --dport %d -j ACCEPT 2>/dev/null', $protocol, $publicPort));
    safe_shell_exec(sprintf('sudo -n iptables -D FORWARD -p %s -d %s --dport %d -j ACCEPT 2>/dev/null', $protocol, escapeshellarg($tunnelIp), $targetPort));
    safe_shell_exec(sprintf('sudo -n iptables -t nat -D PREROUTING -p %s --dport %d -j DNAT --to-destination %s:%d 2>/dev/null', $protocol, $publicPort, escapeshellarg($tunnelIp), $targetPort));
    safe_shell_exec(sprintf('sudo -n iptables -t nat -D POSTROUTING -p %s -d %s --dport %d -j MASQUERADE 2>/dev/null', $protocol, escapeshellarg($tunnelIp), $targetPort));
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

    if (!function_exists('safe_shell_exec')) {
        return ['success' => false, 'output' => 'Fungsi shell_exec tidak aktif di PHP.'];
    }

    $cmd = sprintf('ping -c 3 -W 2 %s 2>&1', escapeshellarg($ip));
    $output = safe_shell_exec($cmd);
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

if (!function_exists('format_relative_time')) {
    function format_relative_time(?int $timestamp): string {
        if (!$timestamp || $timestamp <= 0) return 'Belum pernah';
        $diff = time() - $timestamp;
        if ($diff < 5) return 'Baru saja';
        if ($diff < 60) return $diff . ' detik lalu';
        if ($diff < 3600) return floor($diff / 60) . ' menit lalu';
        if ($diff < 86400) return floor($diff / 3600) . ' jam lalu';
        return floor($diff / 86400) . ' hari lalu';
    }
}
