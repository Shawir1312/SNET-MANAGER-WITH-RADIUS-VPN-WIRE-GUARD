<?php
/**
 * AJAX Endpoint - Get Resellers (Profiles) for a specific Router
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../include/functions.php';

auth_check();

$router_id = (int)get('router_id');

header('Content-Type: application/json');

if ($router_id <= 0) {
    echo json_encode([]);
    exit;
}

$type = get('type', 'reseller');
$reseller_cond = ($type === 'reseller') ? "AND p.reseller_percent > 0" : "";

$sql = "SELECT p.*, r.name as router_name
        FROM profiles p
        LEFT JOIN routers r ON p.router_id = r.id
        WHERE (p.router_id = ? OR p.router_id IS NULL) 
          $reseller_cond
          AND p.is_active = 1
        ORDER BY p.name ASC";
$profiles = db_fetch_all($sql, 'i', [$router_id]);

foreach ($profiles as &$p) {
    $pid = (int)$p['id'];
    $summary = get_reseller_billing_summary($router_id, $pid);
    $p['last_billed_at']    = $summary['last_billed_at'];
    $p['last_billed_date']  = $summary['last_billed_date'];
    $p['last_status']       = $summary['last_status'];
    $p['vcr_baru']          = $summary['vcr_baru'];
    $p['sisa_sebelumnya']   = $summary['sisa_sebelumnya'];
    $p['voucher_aktual']    = $summary['voucher_aktual'];
    $p['unbilled_vouchers'] = $summary['voucher_aktual'];
    $p['tekor_count']       = $summary['tekor_count'];
}


echo json_encode($profiles);
