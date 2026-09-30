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
    if ($api->connect($router['ip_address'], $router['api_user'], $router['api_password'], (int)$router['api_port'])) {
        $online   = true;
        $ident    = $api->comm('/system/identity/print');
        $identity = $ident[0]['name'] ?? '';

        // Hitung user aktif real-time langsung dari MikroTik (Hotspot + PPPoE)
        $hsActive  = $api->comm('/ip/hotspot/active/print');
        $pppActive = $api->comm('/ppp/active/print');
        $active    = (is_array($hsActive) ? count($hsActive) : 0) + (is_array($pppActive) ? count($pppActive) : 0);

        // Update last_seen
        db_execute("UPDATE routers SET last_seen = NOW() WHERE id = ?", 'i', [$id]);
        $api->disconnect();
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$nasIp = !empty($router['nas_ip']) && $router['nas_ip'] !== '0.0.0.0/0' ? $router['nas_ip'] : $router['ip_address'];

if (!$online) {
    // Router offline / terputus: tutup semua sesi hantu menggantung di radacct
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
}

echo json_encode([
    'online'       => $online,
    'active_users' => $active,
    'identity'     => $identity,
    'error'        => $error,
]);
