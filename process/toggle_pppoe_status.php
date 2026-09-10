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
        // Ganti profile di secret ke isolir
        $api->comm('/ppp/secret/set', [
            '?name'    => $u,
            '=profile' => $isoProfile,
            '=disabled'=> 'no'
        ]);
        
        // Putus sesi aktif agar dial ulang dengan profil isolir
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
        // Ganti profile di secret ke profile paket asli
        $normalProfile = $customer['profile'] ?: 'default';
        $api->comm('/ppp/secret/set', [
            '?name'    => $u,
            '=profile' => $normalProfile,
            '=disabled'=> 'no'
        ]);
        
        // Putus sesi aktif agar dial ulang dengan profil asli
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
        // Disable secret
        $api->comm('/ppp/secret/set', [
            '?name'    => $u,
            '=disabled'=> 'yes'
        ]);

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
    if (!empty($customer['ont_sn'])) {
        $genieServer = db_fetch_one("SELECT * FROM genie_config LIMIT 1");
        if ($genieServer) {
            try {
                $gApi = new GenieACS($genieServer['url'], $genieServer['username'], $genieServer['password']);
                $devs = $gApi->getDevices('{"_deviceId._SerialNumber": "'.$customer['ont_sn'].'"}');
                if (!empty($devs) && isset($devs[0]['_id'])) {
                    $gApi->reboot($devs[0]['_id']);
                }
            } catch (Throwable $ge) {}
        }
    }

    audit_log('toggle_pppoe_status', "Pelanggan {$u} ({$customer['full_name']}) status diubah ke {$target}", $customer['router_id']);
    flash_set('success', "Pelanggan '{$customer['full_name']}' {$statusLabel}.");

} catch (Throwable $e) {
    flash_set('error', "Gagal memproses aksi status: " . $e->getMessage());
}

header("Location: /index.php?page=pppoe_customers&router_id=" . ($redirectRid ?: $customer['router_id']));
exit;
