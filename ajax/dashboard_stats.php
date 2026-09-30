<?php
/**
 * AJAX — Realtime Dashboard Stats
 * Returns live counts for Dashboard stat cards, sales, PPPoE, and per-router user counts.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../include/functions.php';

auth_check();
session_write_close();
header('Content-Type: application/json');

// Run auto-expire check (throttled)
run_auto_expire_vouchers();

// 1. Voucher & Session Stats
$total_vouchers  = (int)(db_fetch_one("SELECT COUNT(*) AS n FROM vouchers WHERE status != 'deleted'")['n'] ?? 0);
$unused_vouchers = (int)(db_fetch_one("SELECT COUNT(*) AS n FROM vouchers WHERE status = 'unused'")['n'] ?? 0);
$active_vouchers = (int)(db_fetch_one("SELECT COUNT(*) AS n FROM vouchers WHERE status = 'active'")['n'] ?? 0);
$expired_vouchers= (int)(db_fetch_one("SELECT COUNT(*) AS n FROM vouchers WHERE status = 'expired'")['n'] ?? 0);

// Active sessions from radacct joined with valid voucher masa aktif
$active_sessions = (int)(db_fetch_one("
    SELECT COUNT(DISTINCT ra.username) AS n 
    FROM radacct ra 
    LEFT JOIN vouchers v ON v.username = ra.username 
    WHERE ra.acctstoptime IS NULL 
      AND (v.id IS NULL OR (
          (v.expired_at IS NULL OR v.expired_at > NOW())
          AND v.status NOT IN ('expired', 'deleted')
      ))
")['n'] ?? 0);

// 2. Active users per router
$all_routers = get_all_routers();
$counts_query = db_fetch_all("
    SELECT 
        COALESCE(v.router_id, r.id, 0) AS router_id,
        ra.nasipaddress,
        COUNT(DISTINCT ra.username) AS cnt
    FROM radacct ra
    LEFT JOIN vouchers v ON v.username = ra.username
    LEFT JOIN routers r ON (r.ip_address = ra.nasipaddress OR r.nas_ip = ra.nasipaddress)
    WHERE ra.acctstoptime IS NULL
      AND (v.id IS NULL OR (
          (v.expired_at IS NULL OR v.expired_at > NOW())
          AND v.status NOT IN ('expired', 'deleted')
      ))
    GROUP BY router_id, ra.nasipaddress
");

$counts_by_rid = [];
$counts_by_ip  = [];
foreach ($counts_query as $cq) {
    $rid = (int)$cq['router_id'];
    $cnt = (int)$cq['cnt'];
    if ($rid > 0) {
        $counts_by_rid[$rid] = ($counts_by_rid[$rid] ?? 0) + $cnt;
    } else {
        $ip = $cq['nasipaddress'];
        $counts_by_ip[$ip] = ($counts_by_ip[$ip] ?? 0) + $cnt;
    }
}

$routers_data = [];
foreach ($all_routers as $r) {
    $rid = (int)$r['id'];
    $nas = !empty($r['nas_ip']) && $r['nas_ip'] !== '0.0.0.0/0' ? $r['nas_ip'] : $r['ip_address'];
    
    $cnt = $counts_by_rid[$rid] ?? 0;
    if ($cnt === 0) {
        $cnt += ($counts_by_ip[$r['ip_address']] ?? 0);
        if ($nas !== $r['ip_address'] && isset($counts_by_ip[$nas])) {
            $cnt += $counts_by_ip[$nas];
        }
    }
    
    // Router online status based on last_seen (within 180s)
    $lastSeen = !empty($r['last_seen']) ? strtotime($r['last_seen']) : 0;
    $isOnline = ($lastSeen > 0 && (time() - $lastSeen) < 180);

    $routers_data[] = [
        'id'           => $rid,
        'name'         => $r['name'],
        'ip'           => $r['ip_address'],
        'active_users' => $cnt,
        'online'       => $isOnline,
    ];
}


// 3. Sales Stats
$today_sales = db_fetch_one(
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(price),0) AS total FROM sales_log WHERE DATE(sold_at) = CURDATE()"
) ?? ['cnt' => 0, 'total' => 0];

$month_sales = db_fetch_one(
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(price),0) AS total FROM sales_log WHERE MONTH(sold_at) = MONTH(CURDATE()) AND YEAR(sold_at) = YEAR(CURDATE())"
) ?? ['cnt' => 0, 'total' => 0];

// Sales per router
$router_sales = db_fetch_all(
    "SELECT r.id, r.name,
            COALESCE(SUM(CASE WHEN DATE(sl.sold_at) = CURDATE() THEN sl.price ELSE 0 END), 0) AS today_total,
            COALESCE(SUM(CASE WHEN MONTH(sl.sold_at) = MONTH(CURDATE()) AND YEAR(sl.sold_at) = YEAR(CURDATE()) THEN sl.price ELSE 0 END), 0) AS month_total
     FROM routers r
     LEFT JOIN sales_log sl ON r.id = sl.router_id
     GROUP BY r.id
     ORDER BY r.name ASC"
);

$branch_sales = [];
foreach ($router_sales as $rs) {
    $branch_sales[] = [
        'id'               => (int)$rs['id'],
        'name'             => $rs['name'],
        'today_total'      => (float)$rs['today_total'],
        'month_total'      => (float)$rs['month_total'],
        'today_formatted'  => format_price((float)$rs['today_total']),
        'month_formatted'  => format_price((float)$rs['month_total']),
    ];
}

// 4. PPPoE Stats
$pppoe_total = 0;
$pppoe_active = 0;
$pppoe_isolated = 0;
$pppoe_paid_month = 0;
try {
    $cStats = db_fetch_one("SELECT 
        COUNT(*) as total,
        COALESCE(SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END), 0) as active_count,
        COALESCE(SUM(CASE WHEN status = 'isolated' THEN 1 ELSE 0 END), 0) as isolated_count
        FROM pppoe_customers");
    if ($cStats) {
        $pppoe_total = (int)$cStats['total'];
        $pppoe_active = (int)$cStats['active_count'];
        $pppoe_isolated = (int)$cStats['isolated_count'];
    }
    $pMonth = db_fetch_one("SELECT COALESCE(SUM(amount), 0) as total FROM pppoe_payments WHERE period_month = MONTH(CURDATE()) AND period_year = YEAR(CURDATE()) AND midtrans_status NOT IN ('pending','cancel','deny','expire')");
    if ($pMonth) {
        $pppoe_paid_month = (float)$pMonth['total'];
    }
} catch (Throwable $e) {}

// 5. WireGuard Stats
$wg_total_peers = 0;
$wg_online_peers = 0;
$wg_forwards_count = 0;
try {
    require_once __DIR__ . '/../include/wireguard_functions.php';
    $wgPeers = db_fetch_all("SELECT public_key FROM wg_routers");
    $wg_total_peers = count($wgPeers);
    $peerStatus = wg_get_peer_status();
    foreach ($wgPeers as $wp) {
        $k = $wp['public_key'];
        if (isset($peerStatus[$k]) && $peerStatus[$k]['connected']) {
            $wg_online_peers++;
        }
    }
    $pfRow = db_fetch_one("SELECT COUNT(*) as c FROM wg_port_forwards");
    $wg_forwards_count = (int)($pfRow['c'] ?? 0);
} catch (Throwable $e) {}

// 6. Sales chart — last 7 days
$chart_data = db_fetch_all(
    "SELECT DATE(sold_at) AS day, COUNT(*) AS cnt, COALESCE(SUM(price),0) AS revenue
     FROM sales_log WHERE sold_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
     GROUP BY DATE(sold_at) ORDER BY day ASC"
);

echo json_encode([
    'success'           => true,
    'timestamp'         => time(),
    'time_formatted'    => date('H:i:s'),
    'active_sessions'   => $active_sessions,
    'total_routers'     => count($all_routers),
    'unused_vouchers'   => $unused_vouchers,
    'active_vouchers'   => $active_vouchers,
    'expired_vouchers'  => $expired_vouchers,
    'today_sales_cnt'   => (int)$today_sales['cnt'],
    'today_sales_total' => (float)$today_sales['total'],
    'today_sales_formatted' => format_price((float)$today_sales['total']),
    'month_sales_cnt'   => (int)$month_sales['cnt'],
    'month_sales_total' => (float)$month_sales['total'],
    'month_sales_formatted' => format_price((float)$month_sales['total']),
    'pppoe'             => [
        'total'           => $pppoe_total,
        'active'          => $pppoe_active,
        'isolated'        => $pppoe_isolated,
        'paid_month'      => $pppoe_paid_month,
        'paid_month_formatted' => format_price($pppoe_paid_month),
    ],
    'wg'                => [
        'online_peers'    => $wg_online_peers,
        'total_peers'     => $wg_total_peers,
        'forwards_count'  => $wg_forwards_count,
    ],
    'routers'           => $routers_data,
    'branch_sales'      => $branch_sales,
    'chart_data'        => $chart_data,
]);
