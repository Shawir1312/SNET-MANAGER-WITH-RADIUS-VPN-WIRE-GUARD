<?php
/**
 * S.NET RADIUS & VPN — AJAX Handler untuk Detail & Konfigurasi Wi-Fi ONT
 */
define('IN_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../include/functions.php';
require_once __DIR__ . '/../include/GenieACS.php';

header('Content-Type: application/json');
auth_check();

$action    = trim($_POST['action'] ?? ($_GET['action'] ?? 'get_detail'));
$server_id = (int)($_POST['server_id'] ?? ($_GET['server_id'] ?? 0));
$dev_id    = trim($_POST['dev_id'] ?? ($_GET['dev_id'] ?? ''));

if (!$server_id || !$dev_id) {
    echo json_encode(['success' => false, 'error' => 'Server ID dan Device ID wajib disertakan.']);
    exit;
}

$server = db_fetch_one("SELECT * FROM genie_config WHERE id = ?", 'i', [$server_id]);
if (!$server) {
    echo json_encode(['success' => false, 'error' => 'Server GenieACS tidak ditemukan.']);
    exit;
}

try {
    $api = new GenieACS($server['url'], $server['username'], $server['password']);

    // ── 1. GET DETAIL LENGKAP ONT ────────────────────────────────────
    if ($action === 'get_detail') {
        $dev = $api->getDevice($dev_id);
        if (!$dev) {
            throw new Exception("Perangkat ONT tidak ditemukan di GenieACS.");
        }

        $info = $api->getInfo($dev);
        $opt  = $api->getOptical($dev);
        $wifi = $api->getWifi($dev);
        $brand = $api->detectBrandName($dev);

        echo json_encode([
            'success' => true,
            'info'    => $info,
            'optical' => $opt,
            'wifi'    => $wifi,
            'brand'   => $brand,
            'raw_id'  => $dev['_id']
        ]);
        exit;
    }

    // ── 2. UPDATE WI-FI (SSID & PASSWORD) ────────────────────────────
    if ($action === 'set_wifi') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['csrf']) || $_POST['csrf'] !== $_SESSION['csrf_token']) {
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
            exit;
        }

        $ssid24   = trim($_POST['ssid_24'] ?? '');
        $pass24   = trim($_POST['pass_24'] ?? '');
        $ssid5g   = trim($_POST['ssid_5g'] ?? '');
        $pass5g   = trim($_POST['pass_5g'] ?? '');
        $samePass = !empty($_POST['same_pass']);

        $dev = $api->getDevice($dev_id);
        if (!$dev) {
            throw new Exception("Perangkat ONT tidak ditemukan.");
        }

        $res = $api->setWifi($dev_id, $dev, $ssid24, $pass24, $ssid5g, $pass5g, $samePass);
        if (!$res && $api->error) {
            throw new Exception($api->error);
        }

        echo json_encode([
            'success' => true,
            'message' => 'Pengaturan Wi-Fi berhasil dikirim ke perangkat ONT melalui GenieACS!'
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Aksi tidak valid.']);

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
