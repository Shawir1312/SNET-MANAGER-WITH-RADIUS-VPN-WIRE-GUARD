<?php
/**
 * PPPoE Customers — Save to DB and Mikrotik
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../include/functions.php';

auth_check();
csrf_verify();

$id = (int)post('id');
$selRid = (int)post('router_id');
$username = trim(post('pppoe_username'));
$password = post('pppoe_password'); // Can be empty on edit
$full_name = trim(post('full_name'));
$phone = trim(post('phone'));
$address = trim(post('address'));
$profile = trim(post('profile'));
$is_free = (int)post('is_free', 0);
$monthly_price = $is_free ? 0 : (int)post('monthly_price');
$due_day = (int)post('due_day');
$status = post('status') ?: 'active';
$ont_sn = trim(post('ont_sn'));
$notes = trim(post('notes'));
$old_username = post('old_username');
$portal_username = trim(post('portal_username'));
$portal_password = post('portal_password');

if (!$selRid || !$username || !$full_name || !$profile) {
    flash_set('error', 'Semua form dengan tanda (*) wajib diisi.');
    header('Location: ' . ($id ? "/index.php?page=pppoe_edit&router_id=$selRid&id=$id" : "/index.php?page=pppoe_add&router_id=$selRid"));
    exit;
}

// Pastikan kolom is_free ada di tabel
try {
    $col = db_fetch_one("SHOW COLUMNS FROM pppoe_customers LIKE 'is_free'");
    if (!$col) {
        db_execute("ALTER TABLE pppoe_customers ADD COLUMN is_free TINYINT(1) DEFAULT 0 AFTER monthly_price");
    }
} catch (Exception $e) {}

$routers = get_all_routers();
$selRouter = null;
foreach ($routers as $r) {
    if ($r['id'] == $selRid) { $selRouter = $r; break; }
}

if (!$selRouter) {
    flash_set('error', 'Router tidak valid.');
    header('Location: /index.php?page=pppoe_customers');
    exit;
}

try {
    // 1. Sync ke MikroTik
    require_once __DIR__ . '/../lib/routeros_api.class.php';
    $api = new RouterosAPI();
    $api->debug = false;
    
    if ($api->connect($selRouter['ip_address'], $selRouter['api_user'], $selRouter['api_password'], (int)$selRouter['api_port'])) {
        $actualProfile = $status === 'isolated' ? 'isolir' : $profile; // Jika diisolir, set ke profil isolir

        if ($id && $old_username) {
            // EDIT
            $secs = $api->comm('/ppp/secret/print', ['?name' => $old_username]);
            if (!empty($secs)) {
                $cmd = [
                    '/ppp/secret/set',
                    '.id' => $secs[0]['.id'],
                    'name' => $username,
                    'profile' => $actualProfile,
                    'disabled' => $status === 'suspended' ? 'yes' : 'no'
                ];
                if ($password) $cmd['password'] = $password;
                $api->comm(null, $cmd);
            } else {
                // If somehow missing in mikrotik, recreate it
                $cmd = [
                    '/ppp/secret/add',
                    'name' => $username,
                    'profile' => $actualProfile,
                    'service' => 'pppoe',
                    'disabled' => $status === 'suspended' ? 'yes' : 'no'
                ];
                if ($password) $cmd['password'] = $password;
                $api->comm(null, $cmd);
            }

            // Jika username berubah atau di-isolir/suspend, kick sesi lama
            if ($old_username !== $username || $status !== 'active') {
                $acts = $api->comm('/ppp/active/print', ['?name' => $old_username]);
                foreach ($acts as $a) {
                    $api->comm('/ppp/active/remove', ['.id' => $a['.id']]);
                }
            }

        } else {
            // ADD
            if (!$password) $password = substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, 6);
            
            $cmd = [
                '/ppp/secret/add',
                'name' => $username,
                'password' => $password,
                'profile' => $actualProfile,
                'service' => 'pppoe',
                'disabled' => $status === 'suspended' ? 'yes' : 'no'
            ];
            $api->comm(null, $cmd);
        }
        $api->disconnect();
    } else {
        throw new Exception("Gagal terhubung ke Router API.");
    }

    // 2. Save ke Database
    if ($id) {
        // UPDATE
        $sql = "UPDATE pppoe_customers SET 
                pppoe_username = ?, full_name = ?, phone = ?, address = ?, 
                profile = ?, monthly_price = ?, is_free = ?, due_day = ?, status = ?, ont_sn = ?, notes = ?, portal_username = ?";
        $params = [$username, $full_name, $phone, $address, $profile, $monthly_price, $is_free, $due_day, $status, $ont_sn, $notes, $portal_username];
        $types = "sssssiiissss";
        
        if ($portal_password !== '') {
            $sql .= ", portal_password = ?";
            $params[] = password_hash($portal_password, PASSWORD_DEFAULT);
            $types .= "s";
        }
        
        if ($status === 'isolated') {
            $sql .= ", isolated_at = NOW(), isolated_reason = 'Manual isolated'";
        } elseif ($status === 'active') {
            $sql .= ", isolated_at = NULL, isolated_reason = ''";
        }
        
        $sql .= " WHERE id = ?";
        $params[] = $id;
        $types .= "i";
        
        db_execute($sql, $types, $params);
        flash_set('success', "Pelanggan {$full_name} berhasil diubah.");
    } else {
        // INSERT
        $sql = "INSERT INTO pppoe_customers (
            router_id, pppoe_username, portal_username, portal_password, full_name, phone, address, profile, monthly_price, is_free, due_day, status, ont_sn, notes
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $params = [$selRid, $username, $portal_username, password_hash($portal_password, PASSWORD_DEFAULT), $full_name, $phone, $address, $profile, $monthly_price, $is_free, $due_day, $status, $ont_sn, $notes];
        $types = "isssssssiiisss";
        
        db_execute($sql, $types, $params);
        flash_set('success', "Pelanggan {$full_name} berhasil ditambahkan. Pass PPPoE: {$password}");
    }

} catch (Exception $e) {
    flash_set('error', 'Terjadi kesalahan: ' . $e->getMessage());
}

header("Location: /index.php?page=pppoe_customers&router_id=$selRid");
exit;
