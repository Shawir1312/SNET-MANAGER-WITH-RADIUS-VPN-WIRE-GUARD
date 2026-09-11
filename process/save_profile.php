<?php
/**
 * Process — Save Profile (Add/Edit)
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../include/functions.php';

auth_check();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /index.php?page=profile_list'); exit; }
if (empty($_POST['csrf']) || $_POST['csrf'] !== $_SESSION['csrf_token']) {
    flash_set('error', 'Invalid CSRF.'); header('Location: /index.php?page=profile_list'); exit;
}

$id             = (int)post('id');
$name           = sanitize(post('name'));
$display_name   = sanitize(post('display_name', ''));
$validity_value = max(1, (int)post('validity_value', 30));
$validity_unit  = in_array(post('validity_unit'), ['minutes','hours','days']) ? post('validity_unit') : 'days';
$duration_value = max(0, (int)post('duration_value', 30)); // 0 = unlimited
$duration_unit  = in_array(post('duration_unit'), ['minutes','hours','days']) ? post('duration_unit') : 'hours';
$quota_mb       = max(0, (int)post('quota_mb', 0));
$rate_up        = sanitize(post('rate_up', '0'));
$rate_down      = sanitize(post('rate_down', '0'));
$price            = max(0, (float)post('price', 0));
$include_in_sales = post('include_in_sales', '1') === '0' ? 0 : 1;
$reseller_percent = max(0, min(100, (float)post('reseller_percent', 0)));
$router_id        = post('router_id') !== '' ? (int)post('router_id') : null;
$description      = sanitize(post('description', ''));
$is_active        = post('is_active', '1') === '1' ? 1 : 0;

if (!$name) {
    flash_set('error', 'Nama profil wajib diisi.');
    header('Location: /index.php?page=' . ($id ? 'profile_edit&id='.$id : 'profile_add')); exit;
}

ensure_profile_columns();

try {
    if ($id > 0) {
        db_execute(
            "UPDATE profiles SET name=?, display_name=?, validity_value=?, validity_unit=?, duration_value=?, duration_unit=?, quota_mb=?,
             rate_up=?, rate_down=?, price=?, include_in_sales=?, reseller_percent=?, router_id=?, description=?, is_active=? WHERE id=?",
            'ssisisissdidsiii',
            [$name, $display_name, $validity_value, $validity_unit, $duration_value, $duration_unit, $quota_mb,
             $rate_up, $rate_down, $price, $include_in_sales, $reseller_percent, $router_id, $description, $is_active, $id]
        );

        $sync_msg = "";
        $sync_vouchers     = post('sync_existing_vouchers') === '1';
        $disconnect_active = post('disconnect_active') === '1';

        if ($sync_vouchers) {
            $sync_res = sync_profile_to_vouchers($id, $disconnect_active);
            $sync_msg = " Perubahan disinkronkan ke {$sync_res['total_vouchers']} voucher ({$sync_res['active_updated']} voucher aktif dihitung ulang).";
            if ($sync_res['kicked_sessions'] > 0) {
                $sync_msg .= " {$sync_res['kicked_sessions']} sesi online di-kick dari MikroTik.";
            }
        }

        flash_set('success', "Profil '{$name}' berhasil diperbarui.{$sync_msg}");
    } else {
        db_execute(
            "INSERT INTO profiles (name, display_name, validity_value, validity_unit, duration_value, duration_unit, quota_mb, rate_up, rate_down, price, include_in_sales, reseller_percent, router_id, description, is_active)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
            'ssisisissdidsii',
            [$name, $display_name, $validity_value, $validity_unit, $duration_value, $duration_unit, $quota_mb,
             $rate_up, $rate_down, $price, $include_in_sales, $reseller_percent, $router_id, $description, $is_active]
        );
        flash_set('success', "Profil '{$name}' berhasil ditambahkan.");
    }
    audit_log($id ? 'edit_profile' : 'add_profile', $name, $router_id ?? 0);
} catch (Throwable $e) {
    flash_set('error', 'Gagal simpan profil: ' . $e->getMessage());
}

header('Location: /index.php?page=profile_list');
