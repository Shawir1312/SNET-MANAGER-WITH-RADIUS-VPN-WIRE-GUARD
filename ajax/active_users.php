<?php
/**
 * AJAX — Active Users (JSON)
 * Returns list of active sessions from radacct, enriched with router info.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../include/functions.php';

auth_check();
session_write_close();
header('Content-Type: application/json');

// Action: Sinkronkan seluruh user aktif langsung dari MikroTik
if (isset($_GET['action']) && $_GET['action'] === 'sync_mikrotik') {
    $filter_router = (int)($_GET['router_id'] ?? 0);
    $routers = $filter_router > 0
        ? db_fetch_all("SELECT id, name, ip_address, nas_ip, api_user, api_password, api_port FROM routers WHERE id = ? AND status = 'active'", 'i', [$filter_router])
        : db_fetch_all("SELECT id, name, ip_address, nas_ip, api_user, api_password, api_port FROM routers WHERE status = 'active'");
    
    $totalActive = 0;
    $totalRestored = 0;
    $totalCreated = 0;
    $totalClosed = 0;
    
    foreach ($routers as $r) {
        $sync = sync_router_hotspot_active($r);
        if ($sync['online']) {
            $totalActive += $sync['active_count'];
            $totalRestored += $sync['restored'];
            $totalCreated += $sync['created'];
            $totalClosed += $sync['closed'];
        }
    }
    
    echo json_encode([
        'success'       => true,
        'active_count'  => $totalActive,
        'restored'      => $totalRestored,
        'created'       => $totalCreated,
        'closed'        => $totalClosed,
        'message'       => "Berhasil sinkronisasi dengan MikroTik! Total {$totalActive} user aktif terdeteksi."
    ]);
    exit;
}

// Action: Clear all ghost sessions from offline routers
if (isset($_GET['action']) && $_GET['action'] === 'clear_offline_ghosts') {
    require_once LIB_PATH . '/routeros_api.class.php';
    $allRouters = db_fetch_all("SELECT id, name, ip_address, nas_ip, api_user, api_password, api_port FROM routers WHERE status = 'active'");
    
    $closedTotal = 0;
    foreach ($allRouters as $r) {
        $ip = $r['ip_address'];
        $nasIp = !empty($r['nas_ip']) && $r['nas_ip'] !== '0.0.0.0/0' ? $r['nas_ip'] : $ip;
        
        $isOnline = false;
        try {
            ini_set('default_socket_timeout', 2);
            $api = new RouterosAPI();
            $api->timeout = 2;
            $api->attempts = 1;
            if ($api->connect($ip, $r['api_user'], $r['api_password'], (int)$r['api_port'])) {
                $isOnline = true;
                $api->disconnect();
            }
        } catch (Throwable $e) {}
        
        if (!$isOnline) {
            $stale = db_fetch_all("SELECT radacctid FROM radacct WHERE acctstoptime IS NULL AND (nasipaddress = ? OR nasipaddress = ?)", 'ss', [$ip, $nasIp]);
            if (!empty($stale)) {
                db_execute("
                    UPDATE radacct 
                    SET acctstoptime = CASE 
                            WHEN acctupdatetime IS NOT NULL THEN acctupdatetime
                            WHEN acctsessiontime > 0 THEN DATE_ADD(acctstarttime, INTERVAL acctsessiontime SECOND)
                            ELSE NOW() 
                        END,
                        acctterminatecause = 'Router-Offline'
                    WHERE acctstoptime IS NULL AND (nasipaddress = ? OR nasipaddress = ?)
                ", 'ss', [$ip, $nasIp]);
                $closedTotal += count($stale);
            }
        }
    }
    
    echo json_encode(['success' => true, 'closed_count' => $closedTotal]);
    exit;
}

// Count-only mode for badge
$count_only = !empty($_GET['count']);

$filter_router = (int)($_GET['router_id'] ?? 0);
$access = accessible_router_ids();

// Build WHERE
$where  = [
    "ra.acctstoptime IS NULL",
    "(v.id IS NULL OR ((v.expired_at IS NULL OR v.expired_at > NOW()) AND v.status NOT IN ('expired', 'deleted')))"
];
$params = [];
$types  = '';

if ($filter_router) {
    // Get router IP and nas_ip for this router_id
    $router = db_fetch_one("SELECT ip_address, nas_ip FROM routers WHERE id = ?", 'i', [$filter_router]);
    if ($router) {
        $nasIp = !empty($router['nas_ip']) && $router['nas_ip'] !== '0.0.0.0/0' ? $router['nas_ip'] : $router['ip_address'];
        $where[]  = "(v.router_id = ? OR ra.nasipaddress = ? OR ra.nasipaddress = ?)";
        $params[] = $filter_router;
        $params[] = $router['ip_address'];
        $params[] = $nasIp;
        $types   .= 'iss';
    }
} elseif ($access !== null && !empty($access)) {
    $ips = db_fetch_all(
        "SELECT id, ip_address, nas_ip FROM routers WHERE id IN (" . implode(',', array_fill(0, count($access), '?')) . ")",
        str_repeat('i', count($access)), $access
    );
    if (!empty($ips)) {
        $ip_list = [];
        $r_ids = [];
        foreach ($ips as $r_row) {
            $r_ids[] = (int)$r_row['id'];
            $ip_list[] = $r_row['ip_address'];
            if (!empty($r_row['nas_ip']) && $r_row['nas_ip'] !== '0.0.0.0/0') {
                $ip_list[] = $r_row['nas_ip'];
            }
        }
        $ip_list = array_values(array_unique($ip_list));
        $pls_ip = implode(',', array_fill(0, count($ip_list), '?'));
        $pls_id = implode(',', array_fill(0, count($r_ids), '?'));
        
        $where[] = "(v.router_id IN ({$pls_id}) OR ra.nasipaddress IN ({$pls_ip}))";
        foreach ($r_ids as $rid) { $params[] = $rid; $types .= 'i'; }
        foreach ($ip_list as $ip) { $params[] = $ip; $types .= 's'; }
    }
}

$where_sql = 'WHERE ' . implode(' AND ', $where);

if ($count_only) {
    $cnt = (int)(db_fetch_one("
        SELECT COUNT(DISTINCT ra.username) AS n 
        FROM radacct ra 
        LEFT JOIN vouchers v ON v.username = ra.username
        {$where_sql}
    ", $types, $params)['n'] ?? 0);
    echo json_encode(['count' => $cnt]);
    exit;
}

$rows = db_fetch_all(
    "SELECT ra.radacctid, ra.username, ra.nasipaddress, ra.callingstationid, ra.framedipaddress,
            ra.acctstarttime, ra.acctinputoctets, ra.acctoutputoctets,
            COALESCE(r.name, r2.name, ra.nasipaddress) AS router_name,
            v.profile_id, v.expired_at,
            p.name AS profile, p.duration_value, p.duration_unit,
            rr.value AS session_timeout
     FROM radacct ra
     LEFT JOIN vouchers v ON v.username = ra.username
     LEFT JOIN routers r ON r.id = v.router_id
     LEFT JOIN routers r2 ON (r2.ip_address = ra.nasipaddress OR r2.nas_ip = ra.nasipaddress)
     LEFT JOIN profiles p ON p.id = v.profile_id
     LEFT JOIN radreply rr ON rr.username = ra.username AND rr.attribute = 'Session-Timeout'
     {$where_sql}
     ORDER BY ra.acctstarttime DESC
     LIMIT 1000",
    $types, $params
);


$users = array_map(function($row) {
    $validity_text = '';
    $duration_text = '';

    if (!empty($row['expired_at'])) {
        $val = strtotime($row['expired_at']) - time();
        $validity_text = $val <= 0 ? 'Habis' : seconds_to_human($val);
    }
    
    if (!empty($row['duration_value']) && $row['duration_value'] > 0) {
        $limit = duration_to_seconds((int)$row['duration_value'], $row['duration_unit']);
        $used_closed = (int)(db_fetch_one("SELECT SUM(acctsessiontime) as used FROM radacct WHERE username = ? AND acctstoptime IS NOT NULL", 's', [$row['username']])['used'] ?? 0);
        $used_active = 0;
        $active_sessions = db_fetch_all("SELECT acctstarttime FROM radacct WHERE username = ? AND acctstoptime IS NULL", 's', [$row['username']]);
        foreach ($active_sessions as $sess) {
            $used_active += max(0, time() - strtotime($sess['acctstarttime']));
        }
        $dur_rem = $limit - $used_closed - $used_active;
        $duration_text = $dur_rem <= 0 ? 'Habis' : seconds_to_human($dur_rem);
    }
    
    $sisa_waktu = '';
    if ($duration_text) {
        $sisa_waktu .= '<div style="font-size:0.7rem;margin-bottom:2px;" title="Sisa Kuota Waktu (Durasi)"><i class="bi bi-hourglass-split"></i> ' . $duration_text . '</div>';
    }
    if ($validity_text) {
        $sisa_waktu .= '<div style="font-size:0.7rem;color:var(--red);" title="Sisa Masa Aktif (Validity)"><i class="bi bi-calendar-x"></i> ' . $validity_text . '</div>';
    }
    if ($sisa_waktu === '') {
        $sisa_waktu = '<span style="font-size:0.75rem;">Unlimited</span>';
    }

    return [
        'radacctid'        => (string)$row['radacctid'],
        'username'         => $row['username'],
        'nasipaddress'     => $row['nasipaddress'],
        'router_name'      => $row['router_name'] ?? $row['nasipaddress'],
        'callingstationid' => $row['callingstationid'],
        'framedipaddress'  => $row['framedipaddress'],
        'duration'         => session_duration_human($row['acctstarttime']),
        'sisa_waktu'       => $sisa_waktu,
        'dl'               => format_bytes((int)$row['acctoutputoctets']),
        'ul'               => format_bytes((int)$row['acctinputoctets']),
        'profile'          => $row['profile'],
    ];
}, $rows);

echo json_encode(['count' => count($users), 'users' => $users]);
