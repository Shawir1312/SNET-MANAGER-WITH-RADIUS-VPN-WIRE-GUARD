<?php
/**
 * AJAX Endpoint - Get Customer Wi-Fi (SSID & Password) live from GenieACS or Database
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../include/functions.php';

header('Content-Type: application/json');
auth_check();

$customerId = (int)($_GET['customer_id'] ?? ($_POST['customer_id'] ?? 0));
if (!$customerId) {
    echo json_encode(['success' => false, 'error' => 'Customer ID tidak valid.']);
    exit;
}

$c = db_fetch_one("SELECT id, ont_sn, ont_wifi_ssid, ont_wifi_pass, pppoe_username, full_name FROM pppoe_customers WHERE id = ?", 'i', [$customerId]);
if (!$c) {
    echo json_encode(['success' => false, 'error' => 'Pelanggan tidak ditemukan.']);
    exit;
}

$ssid = trim($c['ont_wifi_ssid'] ?? '');
$pass = trim($c['ont_wifi_pass'] ?? '');
$sn = strtoupper(trim($c['ont_sn'] ?? ''));
$source = 'database';

// Jika password kosong atau tombol cek dari ONT ditekan, coba ambil live dari GenieACS
$forceOnt = (($_GET['force'] ?? '') === '1');
if (($pass === '' || $forceOnt) && !empty($sn) && $sn !== '0' && $sn !== '-') {
    try {
        require_once __DIR__ . '/../include/GenieACS.php';
        $server = db_fetch_one("SELECT * FROM genie_config WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
        if ($server) {
            $genie = new GenieACS($server['url'], $server['username'], $server['password']);
            $devs = $genie->getDevices(json_encode([
                '$or' => [
                    ['_deviceId._SerialNumber' => $sn],
                    ['_id' => ['$regex' => $sn, '$options' => 'i']],
                    ['InternetGatewayDevice.DeviceInfo.SerialNumber._value' => $sn],
                    ['InternetGatewayDevice.DeviceInfo.X_HW_SerialNumber._value' => $sn]
                ]
            ]));

            if (!empty($devs) && isset($devs[0])) {
                $dev = $devs[0];
                $wifi = $genie->getWifi($dev);

                $ontSsid = trim($wifi['ssid_24'] ?: ($wifi['ssid_5g'] ?: ''));
                $ontPass = trim($wifi['pass_24'] ?: ($wifi['pass_5g'] ?: ''));

                if (!empty($ontPass)) {
                    $pass = $ontPass;
                    $source = 'ont';
                }
                if (!empty($ontSsid)) {
                    $ssid = $ontSsid;
                }

                // Jika ada data dari ONT, sinkronkan ke database pppoe_customers
                if (!empty($ssid) || !empty($pass)) {
                    db_execute(
                        "UPDATE pppoe_customers SET ont_wifi_ssid = ?, ont_wifi_pass = ? WHERE id = ?",
                        'ssi',
                        [$ssid ?: ($c['ont_wifi_ssid'] ?: ''), $pass ?: ($c['ont_wifi_pass'] ?: ''), $customerId]
                    );
                }
            }
        }
    } catch (Throwable $e) {}
}

echo json_encode([
    'success' => true,
    'customer_id' => $customerId,
    'sn' => $sn,
    'ssid' => $ssid,
    'pass' => $pass,
    'source' => $source
]);
exit;
