<?php
session_start();
define('IN_APP', true);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../include/functions.php';
require_once __DIR__ . '/../lib/routeros_api.class.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// 1. Validasi Autentikasi Pelanggan Portal
if (empty($_SESSION['portal_customer_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'online' => false, 'message' => 'Sesi login telah berakhir.']);
    exit;
}

$cid = (int)$_SESSION['portal_customer_id'];
$cust = db_fetch_one("SELECT id, pppoe_username, full_name, router_id, status, profile FROM pppoe_customers WHERE id = ?", 'i', [$cid]);

if (!$cust || !in_array($cust['status'], ['active', 'isolated'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'online' => false, 'message' => 'Akun pelanggan tidak aktif.']);
    exit;
}

// Bebaskan session lock agar tidak memblokir request lain
session_write_close();

$uPpp = trim($cust['pppoe_username']);
if (empty($uPpp)) {
    echo json_encode(['success' => false, 'online' => false, 'message' => 'Username PPPoE belum terkonfigurasi.']);
    exit;
}

$uClean = preg_replace('/@.*$/', '', $uPpp);
$uTarget = strtolower($uPpp);
$uTargetClean = strtolower($uClean);

// 2. Dapatkan Router MikroTik Pelanggan
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

// 3. Hubungkan ke RouterOS API
$api = new RouterosAPI();
$api->debug = false;
$api->timeout = 3.0;
$api->attempts = 1;
$api->delay = 0;

$apiPort = !empty($cRouter['api_port']) ? (int)$cRouter['api_port'] : 8728;

if (!$api->connect($cRouter['ip_address'], $cRouter['api_user'], $cRouter['api_password'], $apiPort)) {
    echo json_encode([
        'success'   => false,
        'online'    => false,
        'message'   => 'Gagal terhubung ke router MikroTik (' . ($cRouter['name'] ?? $cRouter['ip_address']) . ').'
    ]);
    exit;
}

$matchedIface = null;
$matchedId = null;
$trafficData = null;
$activeSession = null;
$curTxBytes = 0.0;
$curRxBytes = 0.0;

try {
    // 4. Cari antarmuka pelanggan di /interface/print
    $ifaces = $api->comm('/interface/print', [
        '.proplist' => '.id,name,type,running,tx-byte,rx-byte,bytes'
    ]);

    if (is_array($ifaces)) {
        foreach ($ifaces as $if) {
            $ifName = trim($if['name'] ?? '');
            $ifLower = strtolower($ifName);

            $isMatch = false;
            if ($ifLower === "<pppoe-{$uTarget}>" || $ifLower === "<pppoe-{$uTargetClean}>" ||
                $ifLower === "pppoe-{$uTarget}"   || $ifLower === "pppoe-{$uTargetClean}") {
                $isMatch = true;
            } elseif (preg_match('/^<?pppoe-' . preg_quote($uTargetClean, '/') . '(@.*)?>?$/i', $ifName)) {
                $isMatch = true;
            } elseif (strpos($ifLower, 'pppoe-') !== false && strpos($ifLower, $uTargetClean) !== false) {
                $isMatch = true;
            }

            if ($isMatch) {
                $matchedIface = $ifName;
                $matchedId = $if['.id'] ?? null;
                $curTxBytes = (float)($if['tx-byte'] ?? 0);
                $curRxBytes = (float)($if['rx-byte'] ?? 0);
                if ($curTxBytes === 0.0 && $curRxBytes === 0.0 && !empty($if['bytes'])) {
                    $bParts = explode('/', (string)$if['bytes']);
                    if (count($bParts) === 2) {
                        $curRxBytes = (float)trim($bParts[0]);
                        $curTxBytes = (float)trim($bParts[1]);
                    }
                }
                break;
            }
        }
    }

    // 5. Query /interface/monitor-traffic jika interface ditemukan
    if ($matchedIface) {
        $test = $api->comm('/interface/monitor-traffic', [
            'interface' => $matchedIface,
            'once'      => ''
        ]);
        if (!isset($test['!trap']) && isset($test[0]) && !empty($test[0])) {
            $trafficData = $test[0];
        }

        if (!$trafficData) {
            $test2 = $api->comm('/interface/monitor-traffic', [
                'interface' => $matchedIface,
                'once'      => 'yes'
            ]);
            if (!isset($test2['!trap']) && isset($test2[0]) && !empty($test2[0])) {
                $trafficData = $test2[0];
            }
        }

        if (!$trafficData && $matchedId) {
            $testId = $api->comm('/interface/monitor-traffic', [
                'interface' => $matchedId,
                'once'      => ''
            ]);
            if (!isset($testId['!trap']) && isset($testId[0]) && !empty($testId[0])) {
                $trafficData = $testId[0];
            }
        }

        if (!$trafficData) {
            $cleanCand = trim($matchedIface, '<>');
            $testClean = $api->comm('/interface/monitor-traffic', [
                'interface' => $cleanCand,
                'once'      => ''
            ]);
            if (!isset($testClean['!trap']) && isset($testClean[0]) && !empty($testClean[0])) {
                $trafficData = $testClean[0];
            }
        }
    }

    // 6. Cek Info Sesi Aktif di /ppp/active (IP, Uptime, Caller-ID)
    $pppActs = $api->comm('/ppp/active/print');
    if (is_array($pppActs)) {
        foreach ($pppActs as $pa) {
            $paName = strtolower(trim($pa['name'] ?? ''));
            if ($paName === $uTarget || $paName === $uTargetClean || strpos($paName, $uTargetClean) === 0) {
                $activeSession = [
                    'address'   => $pa['address'] ?? '',
                    'uptime'    => $pa['uptime'] ?? '',
                    'caller_id' => $pa['caller-id'] ?? '',
                    'service'   => $pa['service'] ?? 'pppoe',
                ];
                break;
            }
        }
    }

} catch (Throwable $e) {
} finally {
    $api->disconnect();
}

// 7. Delta-Based Calculation (Fallback super akurat jika monitor-traffic mengembalikan 0)
$now = microtime(true);
$deltaDlBps = 0;
$deltaUlBps = 0;
if ($matchedIface) {
    $cacheFile = sys_get_temp_dir() . '/snet_tf_' . md5($cid . '_' . $matchedIface) . '.json';
    if (file_exists($cacheFile)) {
        $prev = @json_decode(@file_get_contents($cacheFile), true);
        if ($prev && isset($prev['tx'], $prev['rx'], $prev['t'])) {
            $dt = $now - (float)$prev['t'];
            if ($dt >= 0.4 && $dt <= 20.0) {
                $dTx = max(0, $curTxBytes - (float)$prev['tx']);
                $dRx = max(0, $curRxBytes - (float)$prev['rx']);
                $deltaDlBps = (int)round(($dTx * 8) / $dt); // Tx Router = Download Pelanggan
                $deltaUlBps = (int)round(($dRx * 8) / $dt); // Rx Router = Upload Pelanggan
            }
        }
    }
    @file_put_contents($cacheFile, json_encode(['tx' => $curTxBytes, 'rx' => $curRxBytes, 't' => $now]));
}

$monDlBps = (int)($trafficData['tx-bits-per-second'] ?? 0);
$monUlBps = (int)($trafficData['rx-bits-per-second'] ?? 0);
$monDlPps = (int)($trafficData['tx-packets-per-second'] ?? 0);
$monUlPps = (int)($trafficData['rx-packets-per-second'] ?? 0);

$dlBps = ($monDlBps > 0) ? $monDlBps : $deltaDlBps;
$ulBps = ($monUlBps > 0) ? $monUlBps : $deltaUlBps;
$dlPps = ($monDlPps > 0) ? $monDlPps : (int)round($dlBps / (1500 * 8));
$ulPps = ($monUlPps > 0) ? $monUlPps : (int)round($ulBps / (1500 * 8));
$totBps = $dlBps + $ulBps;

// Fallback IP dan Uptime dari FreeRADIUS jika MikroTik tidak memberikan
$ipAddr = $activeSession['address'] ?? '';
$uptime = $activeSession['uptime'] ?? '';
$callerId = $activeSession['caller_id'] ?? '';

if (empty($ipAddr)) {
    try {
        $uPattern = $uClean . '@%';
        $radRow = db_fetch_one(
            "SELECT framedipaddress, acctsessiontime 
             FROM radacct 
             WHERE (username = ? OR username = ? OR username LIKE ?) 
               AND (acctstoptime IS NULL OR acctstoptime = '0000-00-00 00:00:00' OR acctstoptime = '') 
             ORDER BY radacctid DESC LIMIT 1",
            'sss', [$uPpp, $uClean, $uPattern]
        );
        if ($radRow) {
            $ipAddr = $radRow['framedipaddress'] ?? '';
            if (empty($uptime) && !empty($radRow['acctsessiontime'])) {
                $sec = (int)$radRow['acctsessiontime'];
                $d = floor($sec / 86400);
                $h = floor(($sec % 86400) / 3600);
                $m = floor(($sec % 3600) / 60);
                $uptime = ($d > 0 ? "{$d}d " : '') . ($h > 0 ? "{$h}h " : '') . "{$m}m";
            }
        }
    } catch (Throwable $e) {}
}

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

// 8. Output Hasil JSON
if ($matchedIface) {
    echo json_encode([
        'success'            => true,
        'online'             => true,
        'interface'          => $matchedIface,
        'download_bps'       => $dlBps,
        'upload_bps'         => $ulBps,
        'total_bps'          => $totBps,
        'download_formatted' => format_bps($dlBps),
        'upload_formatted'   => format_bps($ulBps),
        'total_formatted'    => format_bps($totBps),
        'download_pps'       => $dlPps,
        'upload_pps'         => $ulPps,
        'tx_bytes'           => $curTxBytes,
        'rx_bytes'           => $curRxBytes,
        'tx_bytes_fmt'       => format_bytes($curTxBytes),
        'rx_bytes_fmt'       => format_bytes($curRxBytes),
        'total_bytes_fmt'    => format_bytes($curTxBytes + $curRxBytes),
        'ip'                 => $ipAddr ?: '—',
        'uptime'             => $uptime ?: 'Online',
        'caller_id'          => $callerId ?: '—',
        'profile'            => $cust['profile'] ?: 'Unlimited',
        'message'            => 'Monitoring aktif',
        'timestamp'          => microtime(true)
    ]);
} else {
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
        'ip'                 => $ipAddr ?: '—',
        'uptime'             => 'Offline',
        'caller_id'          => '—',
        'profile'            => $cust['profile'] ?: 'Unlimited',
        'message'            => 'Koneksi dial PPPoE sedang offline atau modem belum terhubung ke router.',
        'timestamp'          => microtime(true)
    ]);
}
