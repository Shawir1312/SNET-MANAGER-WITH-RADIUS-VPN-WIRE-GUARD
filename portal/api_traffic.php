<?php
session_start();
define('IN_APP', true);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../include/functions.php';
require_once __DIR__ . '/../lib/routeros_api.class.php';

header('Content-Type: application/json; charset=utf-8');

// 1. Validasi Autentikasi Pelanggan Portal
if (empty($_SESSION['portal_customer_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'online' => false, 'message' => 'Sesi login telah berakhir. Silakan login kembali.']);
    exit;
}

$cid = (int)$_SESSION['portal_customer_id'];
$cust = db_fetch_one("SELECT id, pppoe_username, full_name, router_id, status, profile FROM pppoe_customers WHERE id = ?", 'i', [$cid]);

if (!$cust || !in_array($cust['status'], ['active', 'isolated'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'online' => false, 'message' => 'Akun pelanggan tidak aktif.']);
    exit;
}

// Lepas session lock secepatnya agar request paralel pelanggan lain tidak tertahan
session_write_close();

$uPpp = trim($cust['pppoe_username']);
if (empty($uPpp)) {
    echo json_encode(['success' => false, 'online' => false, 'message' => 'Username PPPoE belum terkonfigurasi.']);
    exit;
}

$uClean = preg_replace('/@.*$/', '', $uPpp);

// 2. Dapatkan Router MikroTik
$cRouter = null;
if (!empty($cust['router_id'])) {
    $cRouter = db_fetch_one("SELECT * FROM routers WHERE id = ? AND (status = 'active' OR status IS NULL OR status = '') LIMIT 1", 'i', [(int)$cust['router_id']]);
    if (!$cRouter) {
        $cRouter = db_fetch_one("SELECT * FROM routers WHERE id = ? LIMIT 1", 'i', [(int)$cust['router_id']]);
    }
}
if (!$cRouter) {
    $cRouter = db_fetch_one("SELECT * FROM routers WHERE status = 'active' ORDER BY id ASC LIMIT 1");
    if (!$cRouter) {
        $cRouter = db_fetch_one("SELECT * FROM routers ORDER BY id ASC LIMIT 1");
    }
}

if (!$cRouter) {
    echo json_encode(['success' => false, 'online' => false, 'message' => 'Router MikroTik belum tersedia.']);
    exit;
}

// 3. Sambungkan ke RouterOS API
$api = new RouterosAPI();
$api->debug = false;
$api->timeout = 2.5; // timeout 2.5 detik agar respon cepat
$api->attempts = 1;
$api->delay = 0;

$apiPort = !empty($cRouter['api_port']) ? (int)$cRouter['api_port'] : 8728;

if (!$api->connect($cRouter['ip_address'], $cRouter['api_user'], $cRouter['api_password'], $apiPort)) {
    echo json_encode([
        'success'   => false,
        'online'    => false,
        'message'   => 'Tidak dapat terhubung ke Router MikroTik (' . ($cRouter['name'] ?? $cRouter['ip_address']) . ').'
    ]);
    exit;
}

$foundIface = null;
$trafficData = null;
$activeSession = null;

try {
    // 4. Cari Info Sesi Aktif di /ppp/active (IP, Uptime, Caller-ID)
    $acts = $api->comm('/ppp/active/print', ['?name' => $uPpp]);
    if (empty($acts) && $uClean !== $uPpp) {
        $acts = $api->comm('/ppp/active/print', ['?name' => $uClean]);
    }

    if (!empty($acts) && is_array($acts)) {
        $activeSession = $acts[0];
    }

    // 5. Cek Interface PPPoE Pelanggan
    // Prioritas 1: Interface yang dikirimkan oleh browser jika masih valid
    $reqIface = trim($_GET['interface'] ?? $_POST['interface'] ?? '');
    if ($reqIface && (stripos($reqIface, $uClean) !== false || stripos($reqIface, $uPpp) !== false)) {
        $test = $api->comm('/interface/monitor-traffic', [
            'interface' => $reqIface,
            'once'      => 'true'
        ]);
        if (!isset($test['!trap']) && isset($test[0])) {
            $foundIface = $reqIface;
            $trafficData = $test[0];
        }
    }

    // Prioritas 2: Cek kandidat nama interface standar MikroTik RouterOS
    if (!$foundIface) {
        $candidates = [
            "<pppoe-{$uPpp}>",
            "<pppoe-{$uClean}>",
            "pppoe-{$uPpp}",
            "pppoe-{$uClean}"
        ];
        $candidates = array_unique($candidates);

        foreach ($candidates as $cand) {
            $test = $api->comm('/interface/monitor-traffic', [
                'interface' => $cand,
                'once'      => 'true'
            ]);
            if (!isset($test['!trap']) && isset($test[0])) {
                $foundIface = $cand;
                $trafficData = $test[0];
                break;
            }
        }
    }

    // Prioritas 3: Scan interface jika format nama interface berbeda
    if (!$foundIface) {
        $ifaces = $api->comm('/interface/print', [
            '.proplist' => 'name,type,running'
        ]);

        if (is_array($ifaces)) {
            $uTarget = strtolower($uPpp);
            $uTargetClean = strtolower($uClean);

            foreach ($ifaces as $if) {
                $ifName = trim($if['name'] ?? '');
                $ifLower = strtolower($ifName);

                $match = false;
                if ($ifLower === "<pppoe-{$uTarget}>" || $ifLower === "<pppoe-{$uTargetClean}>" ||
                    $ifLower === "pppoe-{$uTarget}"   || $ifLower === "pppoe-{$uTargetClean}") {
                    $match = true;
                } elseif (preg_match('/^<?pppoe-' . preg_quote($uTargetClean, '/') . '(@.*)?>?$/i', $ifName)) {
                    $match = true;
                } elseif (strpos($ifLower, 'pppoe-') !== false && strpos($ifLower, $uTargetClean) !== false) {
                    $match = true;
                }

                if ($match) {
                    $test = $api->comm('/interface/monitor-traffic', [
                        'interface' => $ifName,
                        'once'      => 'true'
                    ]);
                    if (!isset($test['!trap']) && isset($test[0])) {
                        $foundIface = $ifName;
                        $trafficData = $test[0];
                        break;
                    }
                }
            }
        }
    }

} catch (Throwable $e) {
    // Tangani exception koneksi aman
} finally {
    $api->disconnect();
}

// Helper formatting bps lokal jika belum ada di include
if (!function_exists('format_bps')) {
    function format_bps($bps, int $precision = 2): string {
        $bps = (float)$bps;
        if ($bps <= 0) return '0 bps';
        if ($bps >= 1000000000) return round($bps / 1000000000, $precision) . ' Gbps';
        if ($bps >= 1000000) return round($bps / 1000000, $precision) . ' Mbps';
        if ($bps >= 1000) return round($bps / 1000, $precision) . ' Kbps';
        return round($bps, $precision) . ' bps';
    }
}

// 6. Output Hasil JSON
if ($foundIface && $trafficData) {
    // Pada router MikroTik untuk interface pppoe client:
    // tx-bits-per-second: data dari router ke pelanggan = DOWNLOAD
    // rx-bits-per-second: data dari pelanggan ke router = UPLOAD
    $dlBps = (int)($trafficData['tx-bits-per-second'] ?? 0);
    $ulBps = (int)($trafficData['rx-bits-per-second'] ?? 0);
    $dlPps = (int)($trafficData['tx-packets-per-second'] ?? 0);
    $ulPps = (int)($trafficData['rx-packets-per-second'] ?? 0);
    $totBps = $dlBps + $ulBps;

    $ipAddr = $activeSession['address'] ?? '';
    $uptime = $activeSession['uptime'] ?? '';
    $callerId = $activeSession['caller-id'] ?? '';

    // Fallback IP/Uptime dari radacct jika di MikroTik kosong
    if (empty($ipAddr)) {
        try {
            $radRow = db_fetch_one(
                "SELECT framedipaddress, acctsessiontime 
                 FROM radacct 
                 WHERE (username = ? OR username = ?) 
                   AND (acctstoptime IS NULL OR acctstoptime = '0000-00-00 00:00:00' OR acctstoptime = '') 
                 ORDER BY radacctid DESC LIMIT 1",
                'ss', [$uPpp, $uClean]
            );
            if ($radRow) {
                $ipAddr = $radRow['framedipaddress'] ?? '';
                if (empty($uptime) && !empty($radRow['acctsessiontime'])) {
                    $uptime = format_uptime_seconds((int)$radRow['acctsessiontime']);
                }
            }
        } catch (Throwable $e) {}
    }

    echo json_encode([
        'success'            => true,
        'online'             => true,
        'interface'          => $foundIface,
        'download_bps'       => $dlBps,
        'upload_bps'         => $ulBps,
        'total_bps'          => $totBps,
        'download_formatted' => format_bps($dlBps),
        'upload_formatted'   => format_bps($ulBps),
        'total_formatted'    => format_bps($totBps),
        'download_pps'       => $dlPps,
        'upload_pps'         => $ulPps,
        'ip'                 => $ipAddr ?: 'Dynamic IP',
        'uptime'             => $uptime ?: 'Online',
        'caller_id'          => $callerId ?: '—',
        'profile'            => $cust['profile'] ?: 'Unlimited',
        'timestamp'          => microtime(true)
    ]);
} else {
    // Sesi tidak ditemukan atau PPPoE offline
    echo json_encode([
        'success'            => true,
        'online'             => false,
        'interface'          => null,
        'download_bps'       => 0,
        'upload_bps'         => 0,
        'total_bps'          => 0,
        'download_formatted' => '0 bps',
        'upload_formatted'   => '0 bps',
        'total_formatted'    => '0 bps',
        'download_pps'       => 0,
        'upload_pps'         => 0,
        'ip'                 => '—',
        'uptime'             => 'Offline',
        'caller_id'          => '—',
        'profile'            => $cust['profile'] ?: 'Unlimited',
        'message'            => 'Koneksi dial PPPoE sedang offline atau modem tidak terhubung ke router.',
        'timestamp'          => microtime(true)
    ]);
}
