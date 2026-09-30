<?php
/**
 * AJAX — Router Status Check
 * Tests API connectivity and counts active users per router.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../include/functions.php';
require_once LIB_PATH . '/routeros_api.class.php';

auth_check();
session_write_close(); // Prevent session locking during slow API calls
header('Content-Type: application/json');

$id = (int)($_GET['id'] ?? 0);
if (!$id || !can_access_router($id)) {
    echo json_encode(['online' => false, 'active_users' => 0, 'error' => 'Access denied']);
    exit;
}

$router = get_router($id);
if (!$router) {
    echo json_encode(['online' => false, 'active_users' => 0, 'error' => 'Router not found']);
    exit;
}

// Try API connection
$online   = false;
$identity = '';
$error    = '';
$active   = 0;

try {
    ini_set('default_socket_timeout', 3);
    $api = new RouterosAPI();
    $api->debug = false;
    $api->timeout = 3;
    $api->attempts = 1;
    if ($api->connect($router['ip_address'], $router['api_user'], $router['api_password'], (int)$router['api_port'])) {
        $online   = true;
        $ident    = $api->comm('/system/identity/print');
        $identity = $ident[0]['name'] ?? '';

        // Update last_seen
        db_execute("UPDATE routers SET last_seen = NOW() WHERE id = ?", 'i', [$id]);
        
        // Ambil user aktif langsung dari MikroTik Hotspot
        $hsActive = $api->comm('/ip/hotspot/active/print');
        $api->disconnect();
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$nasIp = !empty($router['nas_ip']) && $router['nas_ip'] !== '0.0.0.0/0' ? $router['nas_ip'] : $router['ip_address'];

if ($online) {
    // Sinkronkan status voucher yang baru login jika ada
    sync_active_vouchers();

    if (isset($hsActive) && is_array($hsActive)) {
        $active = count($hsActive);
        
        // Cek apakah jumlah di radacct sesuai dengan MikroTik
        $activeRad = (int)(db_fetch_one("
            SELECT COUNT(DISTINCT ra.username) AS n 
            FROM radacct ra
            LEFT JOIN vouchers v ON v.username = ra.username
            WHERE (ra.nasipaddress = ? OR ra.nasipaddress = ? OR v.router_id = ?)
              AND ra.acctstoptime IS NULL
              AND (v.id IS NULL OR ((v.expired_at IS NULL OR v.expired_at > NOW()) AND v.status NOT IN ('expired', 'deleted')))
        ", 'ssi', [$router['ip_address'], $nasIp, $id])['n'] ?? 0);

        if ($active !== $activeRad) {
            // Lakukan sinkronisasi dua arah agar radacct dan Winbox 100% klop
            sync_router_hotspot_active($router);
        }
    } else {
        // Fallback jika API print gagal
        $active = (int)(db_fetch_one("
            SELECT COUNT(DISTINCT ra.username) AS n 
            FROM radacct ra
            LEFT JOIN vouchers v ON v.username = ra.username
            WHERE (ra.nasipaddress = ? OR ra.nasipaddress = ? OR v.router_id = ?)
              AND ra.acctstoptime IS NULL
              AND (v.id IS NULL OR ((v.expired_at IS NULL OR v.expired_at > NOW()) AND v.status NOT IN ('expired', 'deleted')))
        ", 'ssi', [$router['ip_address'], $nasIp, $id])['n'] ?? 0);
    }
}

if (!$online) {
    // Toleransi Gangguan Sesaat (Grace Period 3 Menit / 180 Detik):
    // Jika router hanya terputus sejenak (5-10 detik atau < 3 menit), JANGAN tutup sesi user!
    $lastSeenTime = !empty($router['last_seen']) ? strtotime($router['last_seen']) : 0;
    $secondsSinceLastSeen = $lastSeenTime > 0 ? (time() - $lastSeenTime) : 999999;

    // Jika last_seen belum tercatat di routers, cek waktu update accounting terakhir
    if ($lastSeenTime === 0) {
        $latestAcct = db_fetch_one(
            "SELECT MAX(COALESCE(acctupdatetime, acctstarttime)) as latest FROM radacct WHERE (nasipaddress = ? OR nasipaddress = ?) AND acctstoptime IS NULL",
            'ss', [$router['ip_address'], $nasIp]
        );
        if (!empty($latestAcct['latest'])) {
            $secondsSinceLastSeen = time() - strtotime($latestAcct['latest']);
        }
    }

    if ($secondsSinceLastSeen >= 180) {
        // Router terkonfirmasi benar-benar mati/putus (> 3 menit): tutup sesi hantu di radacct
        db_execute("
            UPDATE radacct 
            SET acctstoptime = CASE 
                    WHEN acctupdatetime IS NOT NULL THEN acctupdatetime
                    WHEN acctsessiontime > 0 THEN DATE_ADD(acctstarttime, INTERVAL acctsessiontime SECOND)
                    ELSE NOW() 
                END,
                acctterminatecause = 'Router-Offline'
            WHERE acctstoptime IS NULL 
              AND (nasipaddress = ? OR nasipaddress = ?)
        ", 'ss', [$router['ip_address'], $nasIp]);
        $active = 0;
    } else {
        // Gangguan sesaat (5-10 detik atau < 3 menit): Sesi tetap aman dipertahankan dari FreeRADIUS & Masa Aktif Voucher
        $active = (int)(db_fetch_one("
            SELECT COUNT(DISTINCT ra.username) AS n 
            FROM radacct ra
            LEFT JOIN vouchers v ON v.username = ra.username
            WHERE (ra.nasipaddress = ? OR ra.nasipaddress = ? OR v.router_id = ?)
              AND ra.acctstoptime IS NULL
              AND (v.id IS NULL OR ((v.expired_at IS NULL OR v.expired_at > NOW()) AND v.status NOT IN ('expired', 'deleted')))
        ", 'ssi', [$router['ip_address'], $nasIp, $id])['n'] ?? 0);
    }
}


echo json_encode([
    'online'       => $online,
    'active_users' => $active,
    'identity'     => $identity,
    'error'        => $error,
]);
