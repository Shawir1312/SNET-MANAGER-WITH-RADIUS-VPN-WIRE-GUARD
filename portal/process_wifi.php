<?php
session_start();
if (!isset($_SESSION['portal_customer_id'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../include/functions.php';
require_once __DIR__ . '/../include/GenieACS.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: wifi.php");
    exit;
}

$cid = $_SESSION['portal_customer_id'];
$customer = db_fetch_one("SELECT * FROM pppoe_customers WHERE id = ?", 'i', [$cid]);

if (!$customer || empty($customer['ont_sn'])) {
    header("Location: index.php");
    exit;
}

$ssid = trim(post('ssid'));
if (empty($ssid)) {
    $ssid = trim($customer['ont_wifi_ssid'] ?? '');
    if (empty($ssid)) {
        $ssid = 'S.NET - ' . explode(' ', $customer['full_name'])[0];
    }
}
$password = trim(post('password'));
$ssid_5g = $ssid;
$password_5g = $password;

if (strlen($password) < 8) {
    flash_set('error', 'Password WiFi harus minimal 8 karakter.');
    header("Location: wifi.php");
    exit;
}

$genie_server = db_fetch_one("SELECT * FROM genie_config LIMIT 1");
if ($genie_server) {
    try {
        $api = new GenieACS($genie_server['url'], $genie_server['username'], $genie_server['password']);
        $sn = $customer['ont_sn'];
        $cleanSn = strtoupper(trim($sn));
        $devices = $api->getDevices(json_encode([
            '$or' => [
                ['_deviceId._SerialNumber' => $cleanSn],
                ['_id' => ['$regex' => $cleanSn, '$options' => 'i']],
                ['InternetGatewayDevice.DeviceInfo.SerialNumber._value' => $cleanSn],
                ['InternetGatewayDevice.DeviceInfo.X_HW_SerialNumber._value' => $cleanSn]
            ]
        ]));
        
        if (!empty($devices) && isset($devices[0])) {
            $dev = $devices[0];
            $devId = $dev['_id'];
            
            // Proses Set WiFi: Terapkan SSID dan Password yang sama ke 2.4G dan 5G
            $success = $api->setWifi($devId, $dev, $ssid, $password, $ssid, $password, true);
            
            if ($success) {
                db_execute("UPDATE pppoe_customers SET ont_wifi_ssid = ?, ont_wifi_pass = ? WHERE id = ?", 'ssi', [$ssid, $password, $cid]);
                flash_set('success', 'Pengaturan WiFi berhasil dikirim ke modem (2.4G & 5G). Perangkat Anda mungkin akan terputus sebentar, silakan hubungkan ulang.');
            } else {
                flash_set('error', 'Gagal menyimpan pengaturan WiFi ke modem: ' . $api->error);
            }
        } else {
            flash_set('error', 'Modem tidak ditemukan atau sedang offline.');
        }
    } catch (Exception $e) {
        flash_set('error', 'Terjadi kesalahan sistem: ' . $e->getMessage());
    }
} else {
    flash_set('error', 'Konfigurasi GenieACS belum disetting.');
}

header("Location: wifi.php");
exit;
