<?php
/**
 * AJAX — Live Ping & Latency Monitoring per Router
 * Pings Google, Cloudflare, Meta, General & Game servers from each MikroTik router.
 */
define('IN_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../include/functions.php';
require_once LIB_PATH . '/routeros_api.class.php';

auth_check();
session_write_close(); // Prevent session blocking during network socket calls
header('Content-Type: application/json; charset=utf-8');

// ── Target Servers Definition ────────────────────────────────
function get_ping_targets(): array {
    return [
        // ── CDN & Global ──────────────────────────
        [
            'key'      => 'google',
            'name'     => 'Google',
            'host'     => '8.8.8.8',
            'category' => 'cdn',
            'icon'     => 'bi bi-google',
            'color'    => '#4285F4',
            'desc'     => 'Google Public DNS & CDN'
        ],
        [
            'key'      => 'cloudflare',
            'name'     => 'Cloudflare',
            'host'     => '1.1.1.1',
            'category' => 'cdn',
            'icon'     => 'bi bi-clouds',
            'color'    => '#F38020',
            'desc'     => 'Cloudflare 1.1.1.1 Global Edge'
        ],
        [
            'key'      => 'meta',
            'name'     => 'Meta (FB/WA/IG)',
            'host'     => 'facebook.com',
            'category' => 'cdn',
            'icon'     => 'bi bi-meta',
            'color'    => '#0081FB',
            'desc'     => 'Meta Worldwide Edge Network'
        ],
        [
            'key'      => 'opendns',
            'name'     => 'OpenDNS',
            'host'     => '208.67.222.222',
            'category' => 'cdn',
            'icon'     => 'bi bi-shield-check',
            'color'    => '#E04A2F',
            'desc'     => 'Cisco OpenDNS Anycast'
        ],

        // ── Server Umum & Nasional ────────────────
        [
            'key'      => 'detik',
            'name'     => 'Detik / IIX',
            'host'     => '103.247.8.8',
            'category' => 'general',
            'icon'     => 'bi bi-globe-asia-australia',
            'color'    => '#00A859',
            'desc'     => 'Server Indonesia / OpenIXP / IIX'
        ],
        [
            'key'      => 'quad9',
            'name'     => 'Quad9 DNS',
            'host'     => '9.9.9.9',
            'category' => 'general',
            'icon'     => 'bi bi-hdd-network',
            'color'    => '#6C5CE7',
            'desc'     => 'Quad9 Secure Anycast'
        ],

        // ── Game Populer (SEA & Indonesia) ────────
        [
            'key'      => 'mlbb',
            'name'     => 'Mobile Legends',
            'host'     => '161.117.84.14',
            'category' => 'game',
            'icon'     => 'bi bi-controller',
            'color'    => '#D35400',
            'desc'     => 'Moonton MLBB Server SEA'
        ],
        [
            'key'      => 'freefire',
            'name'     => 'Free Fire',
            'host'     => '103.28.54.1',
            'category' => 'game',
            'icon'     => 'bi bi-fire',
            'color'    => '#E74C3C',
            'desc'     => 'Garena Free Fire Server SEA'
        ],
        [
            'key'      => 'steam',
            'name'     => 'Steam / Dota 2',
            'host'     => '103.10.124.1',
            'category' => 'game',
            'icon'     => 'bi bi-steam',
            'color'    => '#1B2838',
            'desc'     => 'Valve Steam Singapore Relay'
        ],
        [
            'key'      => 'valorant',
            'name'     => 'Valorant (Riot)',
            'host'     => '103.246.108.1',
            'category' => 'game',
            'icon'     => 'bi bi-lightning-charge',
            'color'    => '#FA4454',
            'desc'     => 'Riot Games Valorant SEA'
        ],
        [
            'key'      => 'pubg',
            'name'     => 'PUBG Mobile',
            'host'     => '45.121.184.1',
            'category' => 'game',
            'icon'     => 'bi bi-crosshair',
            'color'    => '#F39C12',
            'desc'     => 'Tencent Gaming SEA Gateway'
        ],
    ];
}

// ── Time parsing helper ──────────────────────────────────────
function parse_routeros_ping_time($rawTime): ?float {
    if (!$rawTime) return null;
    $raw = trim((string)$rawTime);

    // Format: "14ms", "14.2ms", "14ms234us"
    if (preg_match('/^(\d+(?:\.\d+)?)\s*ms/i', $raw, $m)) {
        return round((float)$m[1], 1);
    }
    // Format: "340us"
    if (preg_match('/^(\d+)\s*us/i', $raw, $m)) {
        return round((float)$m[1] / 1000, 2);
    }
    // Format: "00:00:00.014230"
    if (preg_match('/(\d+):(\d+):(\d+(?:\.\d+)?)/', $raw, $m)) {
        $sec = (float)$m[3];
        return round($sec * 1000, 1);
    }
    // Plain number
    if (is_numeric($raw)) {
        return round((float)$raw, 1);
    }
    return null;
}

$action = sanitize($_GET['action'] ?? 'ping');

// Action: Targets Catalog
if ($action === 'targets') {
    echo json_encode(['success' => true, 'targets' => get_ping_targets()]);
    exit;
}

// Action: Ping Execution
$router_id  = (int)($_GET['router_id'] ?? 0);
$target_key = sanitize($_GET['target'] ?? '');

if ($router_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Parameter router_id tidak valid']);
    exit;
}

if (!can_access_router($router_id)) {
    echo json_encode(['success' => false, 'error' => 'Akses ke router ini ditolak']);
    exit;
}

$router = get_router($router_id);
if (!$router) {
    echo json_encode(['success' => false, 'error' => 'Router tidak ditemukan']);
    exit;
}

$api_port = (int)($router['api_port'] ?: 8728);
$all_targets = get_ping_targets();

$targetsToRun = [];
if ($target_key) {
    foreach ($all_targets as $t) {
        if ($t['key'] === $target_key) {
            $targetsToRun[] = $t;
            break;
        }
    }
    if (empty($targetsToRun)) {
        echo json_encode(['success' => false, 'error' => 'Target tidak valid']);
        exit;
    }
} else {
    $targetsToRun = $all_targets;
}

// Connect to MikroTik API
$api = new RouterosAPI();
$api->debug = false;
$api->timeout = 3;
$api->attempts = 1;

$connected = false;
try {
    ini_set('default_socket_timeout', 3);
    $connected = @$api->connect(
        $router['ip_address'],
        $router['api_user'] ?: 'admin',
        $router['api_password'] ?: '',
        $api_port
    );
} catch (Throwable $e) {
    $connected = false;
}

if (!$connected) {
    echo json_encode([
        'success'     => false,
        'router_id'   => $router_id,
        'router_name' => $router['name'],
        'ip_address'  => $router['ip_address'],
        'online'      => false,
        'error'       => 'Router API Offline (Port ' . $api_port . ' tidak merespons)',
        'timestamp'   => date('H:i:s'),
    ]);
    exit;
}

// Execute ping for targets
$results = [];
$total_ms = 0;
$valid_ms_count = 0;

foreach ($targetsToRun as $tgt) {
    $host = $tgt['host'];
    
    $res = [];
    try {
        // Send 1 ping packet with fast timeout
        $res = $api->comm('/ping', [
            'address' => $host,
            'count'   => '1',
        ]);
    } catch (Throwable $e) {
        $res = [];
    }

    $latency = null;
    $loss = 100;
    $status = 'timeout';

    if (!empty($res)) {
        $first = $res[0] ?? [];
        $st = strtolower((string)($first['status'] ?? ''));
        $received = isset($first['received']) ? (int)$first['received'] : null;

        // Check timeout / unreachable conditions
        if ($st === 'timeout' || $st === 'host unreachable' || $st === 'net unreachable' || ($received !== null && $received === 0)) {
            $latency = null;
            $loss = 100;
            $status = 'timeout';
        } else {
            $rawT = $first['time'] ?? $first['avg-rtt'] ?? $first['avg_rtt'] ?? null;
            $parsed = parse_routeros_ping_time($rawT);
            if ($parsed !== null) {
                $latency = $parsed;
                $loss = 0;
                $total_ms += $latency;
                $valid_ms_count++;

                // Status classification
                if ($latency < 50) {
                    $status = 'excellent'; // < 50 ms (Hijau)
                } elseif ($latency < 100) {
                    $status = 'good';      // 50-99 ms (Kuning / Biru)
                } elseif ($latency < 160) {
                    $status = 'fair';      // 100-159 ms (Oranye)
                } else {
                    $status = 'poor';      // >= 160 ms (Merah)
                }
            }
        }
    }

    $results[] = [
        'key'      => $tgt['key'],
        'name'     => $tgt['name'],
        'host'     => $tgt['host'],
        'category' => $tgt['category'],
        'icon'     => $tgt['icon'],
        'color'    => $tgt['color'],
        'desc'     => $tgt['desc'],
        'latency'  => $latency,
        'loss'     => $loss,
        'status'   => $status, // excellent | good | fair | poor | timeout
    ];
}

$api->disconnect();

$avg_latency = $valid_ms_count > 0 ? round($total_ms / $valid_ms_count, 1) : null;

echo json_encode([
    'success'     => true,
    'router_id'   => $router_id,
    'router_name' => $router['name'],
    'ip_address'  => $router['ip_address'],
    'online'      => true,
    'avg_latency' => $avg_latency,
    'results'     => $results,
    'timestamp'   => date('H:i:s'),
]);
