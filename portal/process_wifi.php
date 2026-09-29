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
$password = trim(post('password'));
$ssid_5g = trim(post('ssid_5g')) ?: $ssid;
$password_5g = trim(post('password_5g')) ?: $password;

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
        $devices = $api->getDevices('{"_deviceId._SerialNumber": "'.$sn.'"}');
        
        if (!empty($devices) && isset($devices[0])) {
            $dev = $devices[0];
            $devId = $dev['_id'];
            
            // Proses Set WiFi
            $success = $api->setWifi($devId, $dev, $ssid, $password, $ssid_5g, $password_5g);
            
            if ($success) {
                flash_set('success', 'Pengaturan WiFi sedang dikirim ke modem. Perangkat Anda mungkin akan terputus sebentar, silakan hubungkan ulang dengan password baru.');
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
