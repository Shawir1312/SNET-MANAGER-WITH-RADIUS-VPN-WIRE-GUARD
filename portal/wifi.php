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

$cid = $_SESSION['portal_customer_id'];
$customer = db_fetch_one("SELECT * FROM pppoe_customers WHERE id = ?", 'i', [$cid]);

if (!$customer || empty($customer['ont_sn'])) {
    header("Location: index.php");
    exit;
}

$sn = $customer['ont_sn'];
$wifi_data = null;
$error = '';

$genie_server = db_fetch_one("SELECT * FROM genie_config LIMIT 1");
if ($genie_server) {
    try {
        $api = new GenieACS($genie_server['url'], $genie_server['username'], $genie_server['password']);
        $devices = $api->getDevices('{"_deviceId._SerialNumber": "'.$sn.'"}');
        
        if (!empty($devices) && isset($devices[0])) {
            $dev = $devices[0];
            $wifi_data = $api->getWifi($dev);
        } else {
            $error = 'Modem tidak ditemukan di sistem atau sedang offline.';
        }
    } catch (Exception $e) {
        $error = 'Gagal terhubung ke server manajemen modem: ' . $e->getMessage();
    }
} else {
    $error = 'Konfigurasi server manajemen modem belum diatur oleh admin.';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pengaturan WiFi - S.NET Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Inter', sans-serif; }
        body { background: #f4f7f6; color: #333; }
        .navbar {
            background: #2a5298; color: white; padding: 15px 20px;
            display: flex; justify-content: space-between; align-items: center;
        }
        .navbar a { color: white; text-decoration: none; font-weight: 500; margin-left: 15px; }
        .container { max-width: 600px; margin: 40px auto; padding: 0 20px; }
        .card { background: white; border-radius: 12px; padding: 25px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .card h3 { margin-bottom: 20px; color: #1e3c72; border-bottom: 2px solid #f0f0f0; padding-bottom: 10px; }
        
        .form-group { margin-bottom: 20px; }
        label { display: block; font-weight: 600; margin-bottom: 8px; color: #444; }
        input {
            width: 100%; padding: 12px 15px; border: 1px solid #ddd;
            border-radius: 8px; font-size: 15px; outline: none; transition: 0.3s;
        }
        input:focus { border-color: #2a5298; }
        .help-text { font-size: 12px; color: #888; margin-top: 5px; }
        
        button {
            width: 100%; padding: 14px; background: #2a5298; color: white;
            border: none; border-radius: 8px; font-size: 16px; font-weight: 600;
            cursor: pointer; transition: 0.3s; margin-top: 10px;
        }
        button:hover { background: #1e3c72; }
        
        .alert { padding: 12px; margin-bottom: 20px; border-radius: 8px; font-size: 14px; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #f87171; }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #34d399; }
        
        .nav-links a {opacity: 0.8;}
        .nav-links a.active {opacity: 1; font-weight: 600; border-bottom: 2px solid white; padding-bottom: 5px;}
    </style>
</head>
<body>
    <div class="navbar">
        <div style="font-weight: 700; font-size: 18px;">S.NET Portal</div>
        <div class="nav-links">
            <a href="index.php">Dashboard</a>
            <a href="wifi.php" class="active">Pengaturan WiFi</a>
            <a href="logout.php" style="background: rgba(255,255,255,0.2); padding: 8px 15px; border-radius: 8px;">Keluar</a>
        </div>
    </div>

    <div class="container">
        <div class="card">
            <h3>Pengaturan WiFi (SSID & Password)</h3>
            
            <?php if ($msg = flash_get('error')): ?>
                <div class="alert alert-error"><?= htmlspecialchars($msg) ?></div>
            <?php endif; ?>
            <?php if ($msg = flash_get('success')): ?>
                <div class="alert alert-success"><?= htmlspecialchars($msg) ?></div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php elseif ($wifi_data): ?>
                <p style="color: #666; margin-bottom: 20px;">Silakan ubah Nama WiFi (SSID) atau Kata Sandi Anda di bawah ini. Modem akan otomatis memproses perubahan dalam 1-2 menit.</p>
                
                <form action="process_wifi.php" method="POST">
                    <input type="hidden" name="csrf" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                    
                    <div class="form-group">
                        <label>Nama WiFi (SSID) <small style="color:#2a5298;font-weight:normal">(Otomatis untuk 2.4 GHz &amp; 5 GHz)</small></label>
                        <input type="text" name="ssid" value="<?= htmlspecialchars($wifi_data['ssid_24'] ?: ($wifi_data['ssid_5g'] ?? '')) ?>" required>
                        <div class="help-text">Nama WiFi yang sama akan aktif pada frekuensi 2.4 GHz dan 5 GHz.</div>
                    </div>
                    
                    <div class="form-group">
                        <label>Password WiFi <small style="color:#2a5298;font-weight:normal">(Berlaku untuk 2.4 GHz &amp; 5 GHz)</small></label>
                        <input type="text" name="password" value="<?= htmlspecialchars($wifi_data['pass_24'] ?: ($wifi_data['pass_5g'] ?? '')) ?>" required minlength="8">
                        <div class="help-text">Minimal 8 karakter. (Huruf besar/kecil berpengaruh)</div>
                    </div>

                    <button type="submit" onclick="return confirm('Peringatan: Jika Anda mengubah pengaturan ini, HP/Perangkat Anda akan terputus dari WiFi saat ini dan Anda harus memasukkan password baru. Lanjutkan?')">Simpan Perubahan</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
