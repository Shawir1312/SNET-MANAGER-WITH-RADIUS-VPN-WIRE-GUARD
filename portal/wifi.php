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
            $wifi_data = $api->getWifi($dev);
        } else {
            $error = 'Modem sedang offline atau belum tersambung ke sistem.';
        }
    } catch (Exception $e) {
        $error = 'Gagal terhubung ke server manajemen modem: ' . $e->getMessage();
    }
} else {
    $error = 'Konfigurasi server manajemen modem belum diatur oleh admin.';
}

$curSsid = trim(($wifi_data['ssid_24'] ?? '') ?: (($wifi_data['ssid_5g'] ?? '') ?: ($customer['ont_wifi_ssid'] ?? '')));
$curPass = trim(($wifi_data['pass_24'] ?? '') ?: (($wifi_data['pass_5g'] ?? '') ?: ($customer['ont_wifi_pass'] ?? '')));

if (!empty($curPass) && empty($customer['ont_wifi_pass'])) {
    db_execute("UPDATE pppoe_customers SET ont_wifi_pass = ? WHERE id = ?", 'si', [$curPass, $cid]);
    $customer['ont_wifi_pass'] = $curPass;
}
if (!empty($curSsid) && empty($customer['ont_wifi_ssid'])) {
    db_execute("UPDATE pppoe_customers SET ont_wifi_ssid = ? WHERE id = ?", 'si', [$curSsid, $cid]);
    $customer['ont_wifi_ssid'] = $curSsid;
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
        
        button.btn-submit {
            width: 100%; padding: 14px; background: #2a5298; color: white;
            border: none; border-radius: 8px; font-size: 16px; font-weight: 600;
            cursor: pointer; transition: 0.3s; margin-top: 10px;
        }
        button.btn-submit:hover { background: #1e3c72; }
        
        .alert { padding: 12px; margin-bottom: 20px; border-radius: 8px; font-size: 14px; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #f87171; }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #34d399; }
        
        .nav-links a {opacity: 0.8;}
        .nav-links a.active {opacity: 1; font-weight: 600; border-bottom: 2px solid white; padding-bottom: 5px;}

        .pw-wrapper { position: relative; }
        .btn-pw-toggle {
            position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
            background: #e2e8f0; border: 1px solid #cbd5e1; border-radius: 6px;
            padding: 4px 10px; font-size: 11px; cursor: pointer; color: #475569; font-weight: 600;
        }
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

            <!-- Data Wi-Fi Saat Ini -->
            <div style="background:#F0FDF4; border:1px solid #BBF7D0; border-radius:10px; padding:15px; margin-bottom:20px;">
                <div style="font-size:12px; font-weight:700; color:#166534; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:10px;">
                    📡 Data Wi-Fi Aktif Saat Ini
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                    <div style="background:#ffffff; padding:10px 12px; border-radius:8px; border:1px solid #DCFCE7;">
                        <div style="font-size:11px; color:#6B7280; font-weight:600; text-transform:uppercase;">SSID Saat Ini</div>
                        <div style="font-size:15px; font-weight:700; color:#1F2937; margin-top:3px; word-break:break-all;">
                            <?= htmlspecialchars($curSsid ?: '(Belum diatur)') ?>
                        </div>
                    </div>
                    <div style="background:#ffffff; padding:10px 12px; border-radius:8px; border:1px solid #DCFCE7;">
                        <div style="font-size:11px; color:#6B7280; font-weight:600; text-transform:uppercase;">Password Saat Ini</div>
                        <div style="display:flex; align-items:center; gap:6px; margin-top:3px;">
                            <span id="curPassDisplay" style="font-family:monospace; font-size:15px; font-weight:700; color:#15803D;" data-val="<?= htmlspecialchars($curPass) ?>" data-show="0">
                                <?= !empty($curPass) ? '••••••••' : '(Belum tersimpan)' ?>
                            </span>
                            <?php if (!empty($curPass)): ?>
                            <button type="button" onclick="toggleCurPass()" id="btnToggleCurPass" style="width:auto; padding:2px 6px; margin:0; background:none; border:none; cursor:pointer; font-size:14px; color:#4B5563;" title="Lihat/Sembunyikan Sandi">👁️</button>
                            <button type="button" onclick="copyCurPass()" id="btnCopyCurPass" style="width:auto; padding:2px 6px; margin:0; background:none; border:none; cursor:pointer; font-size:14px; color:#15803D;" title="Salin Sandi">📋</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($error && empty($curSsid)): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php else: ?>
                <?php if ($error): ?>
                    <div class="alert alert-error" style="font-size:13px; margin-bottom:15px;">
                        ⚠️ <?= htmlspecialchars($error) ?>. Anda tetap dapat menyimpan konfigurasi WiFi baru ke database.
                    </div>
                <?php endif; ?>

                <p style="color: #666; margin-bottom: 20px; font-size:14px;">Silakan ubah Nama WiFi (SSID) atau Kata Sandi Anda di bawah ini. Modem akan otomatis memproses perubahan dalam 1-2 menit.</p>
                
                <form action="process_wifi.php" method="POST">
                    <input type="hidden" name="csrf" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                    
                    <div class="form-group">
                        <label>Nama WiFi Baru (SSID) <small style="color:#2a5298;font-weight:normal">(Otomatis untuk 2.4 GHz &amp; 5 GHz)</small></label>
                        <input type="text" name="ssid" value="<?= htmlspecialchars($curSsid) ?>" required placeholder="Contoh: <?= htmlspecialchars($curSsid ?: 'Nama WiFi Anda') ?>">
                        <div class="help-text">Nama WiFi yang sama akan aktif pada frekuensi 2.4 GHz dan 5 GHz.</div>
                    </div>
                    
                    <div class="form-group">
                        <label>Password WiFi Baru <small style="color:#2a5298;font-weight:normal">(Berlaku untuk 2.4 GHz &amp; 5 GHz)</small></label>
                        <div class="pw-wrapper">
                            <input type="password" name="password" id="inputPassword" value="<?= htmlspecialchars($curPass) ?>" required minlength="8" placeholder="Minimal 8 karakter" style="padding-right:90px; font-family:monospace; font-weight:700;">
                            <button type="button" class="btn-pw-toggle" id="btnToggleInputPass" onclick="toggleInputPass()">Lihat</button>
                        </div>
                        <div class="help-text">Minimal 8 karakter. (Huruf besar/kecil berpengaruh)</div>
                    </div>

                    <button type="submit" class="btn-submit" onclick="return confirm('Peringatan: Jika Anda mengubah pengaturan ini, HP/Perangkat Anda akan terputus dari WiFi saat ini dan Anda harus memasukkan password baru. Lanjutkan?')">Simpan Perubahan</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <script>
    function toggleCurPass() {
        const el = document.getElementById('curPassDisplay');
        const btn = document.getElementById('btnToggleCurPass');
        if (!el) return;
        const val = el.dataset.val || '';
        if (!val) return;
        if (el.dataset.show === '1') {
            el.textContent = '••••••••';
            el.dataset.show = '0';
            if (btn) btn.textContent = '👁️';
        } else {
            el.textContent = val;
            el.dataset.show = '1';
            if (btn) btn.textContent = '🙈';
        }
    }

    function copyCurPass() {
        const el = document.getElementById('curPassDisplay');
        const val = el ? (el.dataset.val || '') : '';
        if (!val) {
            alert('Tidak ada password tersimpan untuk disalin.');
            return;
        }
        navigator.clipboard.writeText(val).then(() => {
            alert('Password Wi-Fi (' + val + ') berhasil disalin ke clipboard!');
        }).catch(() => {
            alert('Password Wi-Fi saat ini: ' + val);
        });
    }

    function toggleInputPass() {
        const inp = document.getElementById('inputPassword');
        const btn = document.getElementById('btnToggleInputPass');
        if (!inp) return;
        if (inp.type === 'password') {
            inp.type = 'text';
            if (btn) btn.textContent = 'Sembunyikan';
        } else {
            inp.type = 'password';
            if (btn) btn.textContent = 'Lihat';
        }
    }
    </script>
</body>
</html>
