<?php
/**
 * AJAX - Get Available / Unassigned ONTs from GenieACS
 */
ob_start();
header('Content-Type: application/json; charset=utf-8');

try {
    define('IN_APP', true);
    require_once __DIR__ . '/../config/config.php';
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../config/auth.php';
    require_once __DIR__ . '/../include/functions.php';
    require_once __DIR__ . '/../include/GenieACS.php';

    auth_start();
    if (empty($_SESSION['admin_id'])) {
        ob_end_clean();
        echo json_encode(['success' => false, 'error' => 'Sesi login telah habis. Silakan login kembali.']);
        exit;
    }

    $serverId = (int)($_GET['server_id'] ?? 0);
    $server = null;

    if ($serverId > 0) {
        $server = db_fetch_one("SELECT * FROM genie_config WHERE id = ? AND is_active = 1 LIMIT 1", 'i', [$serverId]);
    }
    if (!$server) {
        $server = db_fetch_one("SELECT * FROM genie_config WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
    }

    if (!$server) {
        ob_end_clean();
        echo json_encode(['success' => false, 'error' => 'Server GenieACS tidak ditemukan atau belum aktif.']);
        exit;
    }

    $api = new GenieACS($server['url'], $server['username'], $server['password']);
    $projection = '_id,_lastInform,_deviceId,_tags,InternetGatewayDevice.DeviceInfo.ProductClass,InternetGatewayDevice.DeviceInfo.SerialNumber,InternetGatewayDevice.DeviceInfo.Manufacturer,InternetGatewayDevice.DeviceInfo.X_HW_SerialNumber';
    $raw_devices = $api->getDevices('{}', $projection);

    if ($api->error) {
        throw new Exception($api->error);
    }

    // Ambil semua SN yang sudah terpetakan di database
    $assigned_sns = [];
    try {
        $rows = db_fetch_all("SELECT UPPER(TRIM(ont_sn)) as sn FROM pppoe_customers WHERE ont_sn != ''");
        foreach ($rows as $r) {
            if (!empty($r['sn'])) $assigned_sns[$r['sn']] = true;
        }
    } catch (Throwable $e) {}

    $onts = [];
    foreach ($raw_devices as $dev) {
        $info = $api->getInfo($dev);
        $sn = strtoupper(trim($info['serial'] ?: ($dev['_deviceId']['_SerialNumber'] ?? $dev['_id'])));
        $brand = $api->detectBrandName($dev);
        $model = $info['product'] ?: ($dev['_deviceId']['_ProductClass'] ?? 'ONT');
        
        $isAssigned = isset($assigned_sns[$sn]);
        $suggestedSlot = (stripos($brand, 'FiberHome') !== false) ? 2 : 1;

        $onts[] = [
            'id' => $dev['_id'],
            'sn' => $sn,
            'brand' => $brand,
            'model' => $model,
            'suggested_slot' => $suggestedSlot,
            'is_assigned' => $isAssigned,
            'last_inform' => $dev['_lastInform'] ?? null
        ];
    }

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'server_id' => $server['id'],
        'server_name' => $server['name'],
        'data' => $onts
    ]);

} catch (Throwable $e) {
    ob_end_clean();
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
