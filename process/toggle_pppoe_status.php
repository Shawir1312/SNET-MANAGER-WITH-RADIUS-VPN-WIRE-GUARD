<?php
/**
 * Process — Quick Action: Toggle PPPoE Customer Status (Isolir / Buka Isolir / Suspend)
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../include/functions.php';
require_once __DIR__ . '/../lib/routeros_api.class.php';
require_once __DIR__ . '/../include/GenieACS.php';

auth_check();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /index.php?page=pppoe_customers');
    exit;
}

if (empty($_POST['csrf']) || $_POST['csrf'] !== $_SESSION['csrf_token']) {
    flash_set('error', 'Invalid CSRF token.');
    header('Location: /index.php?page=pppoe_customers');
    exit;
}

$id = (int)post('customer_id');
$target = post('target_status'); // 'active', 'isolated', 'suspended'
$redirectRid = (int)post('router_id');

if ($id <= 0 || !in_array($target, ['active', 'isolated', 'suspended'])) {
    flash_set('error', 'Parameter aksi tidak valid.');
    header("Location: /index.php?page=pppoe_customers&router_id=$redirectRid");
    exit;
}

$customer = db_fetch_one("SELECT * FROM pppoe_customers WHERE id = ?", 'i', [$id]);
if (!$customer) {
    flash_set('error', 'Pelanggan tidak ditemukan.');
    header("Location: /index.php?page=pppoe_customers&router_id=$redirectRid");
    exit;
}

$router = db_fetch_one("SELECT * FROM routers WHERE id = ?", 'i', [$customer['router_id']]);
if (!$router) {
    flash_set('error', 'Router untuk pelanggan ini tidak ditemukan.');
    header("Location: /index.php?page=pppoe_customers&router_id=$redirectRid");
    exit;
}

// Ambil profil isolir dari settings
$isoSetting = db_fetch_one("SELECT setting_value FROM pppoe_settings WHERE setting_key = 'isolir_profile'");
$isoProfile = ($isoSetting && !empty($isoSetting['setting_value'])) ? $isoSetting['setting_value'] : 'isolir';

try {
    $api = new RouterosAPI();
    $api->debug = false;
    
    if (!$api->connect($router['ip_address'], $router['api_user'], $router['api_password'], (int)$router['api_port'])) {
        throw new Exception("Gagal terhubung ke MikroTik ({$router['name']}).");
    }

    $u = $customer['pppoe_username'];

    if ($target === 'isolated') {
        // 1. Pastikan profile isolir ada di MikroTik; jika belum ada, buatkan otomatis
        $checkProf = $api->comm('/ppp/profile/print', ['?name' => $isoProfile]);
        if (empty($checkProf)) {
            $api->comm('/ppp/profile/add', [
                'name'         => $isoProfile,
                'rate-limit'   => '256k/256k',
                'address-list' => 'ISOLIR',
                'comment'      => 'Auto-created by S.NET Manager'
            ]);
        }

        // 2. Cari ID secret di MikroTik & update profile ke isolir
        $secs = $api->comm('/ppp/secret/print', ['?name' => $u]);
        $res = null;
        if (!empty($secs) && isset($secs[0]['.id'])) {
            $res = $api->comm('/ppp/secret/set', [
                '.id'      => $secs[0]['.id'],
                'profile'  => $isoProfile,
                'disabled' => 'no'
            ]);
        } else {
            // Jika secret belum ada di MikroTik, buatkan langsung dengan profil isolir
            $res = $api->comm('/ppp/secret/add', [
                'name'     => $u,
                'password' => (string)rand(10000, 99999),
                'profile'  => $isoProfile,
                'service'  => 'pppoe',
                'disabled' => 'no'
            ]);
        }
        
        if (!empty($res['!trap'])) {
            $msg = $res['!trap'][0]['message'] ?? 'MikroTik menolak perubahan profile';
            throw new Exception("MikroTik Error: " . $msg);
        }

        // 3. Putus sesi aktif agar dial ulang dengan profil isolir
        $acts = $api->comm('/ppp/active/print', ['?name' => $u]);
        foreach ($acts as $a) {
            if (isset($a['.id'])) $api->comm('/ppp/active/remove', ['.id' => $a['.id']]);
        }

        db_execute(
            "UPDATE pppoe_customers SET status = 'isolated', isolated_at = NOW(), isolated_reason = 'Isolir manual oleh admin' WHERE id = ?",
            'i', [$id]
        );

        // Sync FreeRADIUS
        try {
            db_execute("DELETE FROM radcheck WHERE username = ? AND attribute = 'Auth-Type'", 's', [$u]);
            db_execute("UPDATE radreply SET value = ? WHERE username = ? AND attribute = 'Mikrotik-Group'", 'ss', [$isoProfile, $u]);
            db_execute("UPDATE radusergroup SET groupname = ? WHERE username = ?", 'ss', [$isoProfile, $u]);
        } catch (Throwable $re) {}

        $statusLabel = "berhasil DIISOLIR";

    } elseif ($target === 'active') {
        $normalProfile = $customer['profile'] ?: 'default';
        
        // Cari ID secret di MikroTik & kembalikan ke profil normal
        $secs = $api->comm('/ppp/secret/print', ['?name' => $u]);
        $res = null;
        if (!empty($secs) && isset($secs[0]['.id'])) {
            $res = $api->comm('/ppp/secret/set', [
                '.id'      => $secs[0]['.id'],
                'profile'  => $normalProfile,
                'disabled' => 'no'
            ]);
        } else {
            $res = $api->comm('/ppp/secret/add', [
                'name'     => $u,
                'password' => (string)rand(10000, 99999),
                'profile'  => $normalProfile,
                'service'  => 'pppoe',
                'disabled' => 'no'
            ]);
        }
        
        if (!empty($res['!trap'])) {
            $msg = $res['!trap'][0]['message'] ?? 'MikroTik menolak perubahan profile';
            throw new Exception("MikroTik Error: " . $msg);
        }

        // Putus sesi aktif agar dial ulang dengan profil normal
        $acts = $api->comm('/ppp/active/print', ['?name' => $u]);
        foreach ($acts as $a) {
            if (isset($a['.id'])) $api->comm('/ppp/active/remove', ['.id' => $a['.id']]);
        }

        db_execute(
            "UPDATE pppoe_customers SET status = 'active', isolated_at = NULL, isolated_reason = '' WHERE id = ?",
            'i', [$id]
        );

        // Sync FreeRADIUS
        try {
            db_execute("DELETE FROM radcheck WHERE username = ? AND attribute = 'Auth-Type'", 's', [$u]);
            db_execute("UPDATE radreply SET value = ? WHERE username = ? AND attribute = 'Mikrotik-Group'", 'ss', [$normalProfile, $u]);
            db_execute("UPDATE radusergroup SET groupname = ? WHERE username = ?", 'ss', [$normalProfile, $u]);
        } catch (Throwable $re) {}

        $statusLabel = "berhasil DIAKTIFKAN / BUKA ISOLIR";

    } elseif ($target === 'suspended') {
        $secs = $api->comm('/ppp/secret/print', ['?name' => $u]);
        if (!empty($secs) && isset($secs[0]['.id'])) {
            $api->comm('/ppp/secret/set', [
                '.id'      => $secs[0]['.id'],
                'disabled' => 'yes'
            ]);
        }

        $acts = $api->comm('/ppp/active/print', ['?name' => $u]);
        foreach ($acts as $a) {
            if (isset($a['.id'])) $api->comm('/ppp/active/remove', ['.id' => $a['.id']]);
        }

        db_execute("UPDATE pppoe_customers SET status = 'suspended' WHERE id = ?", 'i', [$id]);

        // Sync FreeRADIUS
        try {
            db_execute("DELETE FROM radcheck WHERE username = ? AND attribute = 'Auth-Type'", 's', [$u]);
            db_execute("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Auth-Type', ':=', 'Reject')", 's', [$u]);
        } catch (Throwable $re) {}

        $statusLabel = "berhasil DISUSPEND (Dinonaktifkan)";
    }

    $api->disconnect();

    // Trigger reboot ONT via GenieACS jika ada SN
    $ontStatusMsg = '';
    if (!empty($customer['ont_sn'])) {
        $sn = trim($customer['ont_sn']);
        $genieServer = null;
        if (!empty($router['genie_server_id'])) {
            $genieServer = db_fetch_one("SELECT * FROM genie_config WHERE id = ? AND is_active = 1", 'i', [$router['genie_server_id']]);
        }
        if (!$genieServer) {
            $genieServer = db_fetch_one("SELECT * FROM genie_config WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
        }
        if (!$genieServer) {
            $genieServer = db_fetch_one("SELECT * FROM genie_config ORDER BY id ASC LIMIT 1");
        }

        if ($genieServer) {
            try {
                $gApi = new GenieACS($genieServer['url'], $genieServer['username'], $genieServer['password']);
                // 1. Coba pencarian SN persis
                $devs = $gApi->getDevices('{"_deviceId._SerialNumber": "'.$sn.'"}');
                // 2. Coba pencarian regex SN
                if (empty($devs)) {
                    $devs = $gApi->getDevices('{"_deviceId._SerialNumber": {"$regex": "'.preg_quote($sn).'", "$options": "i"}}');
                }
                // 3. Coba pencarian di _id perangkat (OUI-ProductClass-SerialNumber)
                if (empty($devs)) {
                    $devs = $gApi->getDevices('{"_id": {"$regex": "'.preg_quote($sn).'", "$options": "i"}}');
                }
                // 4. Coba searchDevices global
                if (empty($devs)) {
                    $devs = $gApi->searchDevices($sn);
                }

                if (!empty($devs) && isset($devs[0]['_id'])) {
                    $rebootOk = $gApi->reboot($devs[0]['_id']);
                    if ($rebootOk) {
                        $ontStatusMsg = " &amp; ONT ($sn) diperintahkan reboot";
                    } else {
                        $ontStatusMsg = " &amp; gagal kirim reboot ONT: " . ($gApi->error ?: 'Task ditolak');
                    }
                } else {
                    $ontStatusMsg = " (ONT $sn tidak ditemukan di GenieACS)";
                }
            } catch (Throwable $ge) {
                $ontStatusMsg = " (Gagal hubungi GenieACS: " . $ge->getMessage() . ")";
            }
        } else {
            $ontStatusMsg = " (Server GenieACS belum dikonfigurasi)";
        }
    }

    audit_log('toggle_pppoe_status', "Pelanggan {$u} ({$customer['full_name']}) status diubah ke {$target}", $customer['router_id']);
    flash_set('success', "Pelanggan '{$customer['full_name']}' {$statusLabel}{$ontStatusMsg}.");

} catch (Throwable $e) {
    flash_set('error', "Gagal memproses aksi status: " . $e->getMessage());
}

header("Location: /index.php?page=pppoe_customers&router_id=" . ($redirectRid ?: $customer['router_id']));
exit;
