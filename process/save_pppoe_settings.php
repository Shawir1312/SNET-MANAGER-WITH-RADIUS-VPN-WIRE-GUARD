<?php
/**
 * Process — Save PPPoE & Midtrans Settings
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../include/functions.php';

auth_check();
auth_require_superadmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /index.php?page=pppoe_settings');
    exit;
}

if (empty($_POST['csrf']) || $_POST['csrf'] !== $_SESSION['csrf_token']) {
    flash_set('error', 'Invalid CSRF token.');
    header('Location: /index.php?page=pppoe_settings');
    exit;
}

$keys = [
    'isolir_profile'      => sanitize(post('isolir_profile', 'isolir')),
    'isolir_grace_days'   => (string)max(0, (int)post('isolir_grace_days', 3)),
    'isolir_redirect_url' => sanitize(post('isolir_redirect_url', '/portal/isolir.php')),
    'company_name'        => sanitize(post('company_name', 'S.NET Internet')),
    'company_phone'       => sanitize(post('company_phone', '')),
    'company_address'     => sanitize(post('company_address', '')),
    'midtrans_mode'       => in_array(post('midtrans_mode'), ['sandbox', 'production']) ? post('midtrans_mode') : 'sandbox',
    'midtrans_client_key' => trim(post('midtrans_client_key', '')),
    'midtrans_server_key' => trim(post('midtrans_server_key', '')),
    'ont_username_suffix' => sanitize(post('ont_username_suffix', '@snet')),
    'ont_wifi1_prefix'    => post('ont_wifi1_prefix', 'S.NET - '),
    'ont_wifi2_suffix'    => post('ont_wifi2_suffix', ' 5G'),
    'ont_default_wan_fh'  => (string)max(1, (int)post('ont_default_wan_fh', 2)),
    'ont_default_wan_other' => (string)max(1, (int)post('ont_default_wan_other', 1)),
    'ont_default_vlan'    => (string)max(0, (int)post('ont_default_vlan', 100)),
    'ont_enable_hotspot'  => !empty(post('ont_enable_hotspot')) ? '1' : '0',
    'ont_hotspot_vlan'    => (string)max(0, (int)post('ont_hotspot_vlan', 100)),
    'ont_hotspot_ssid2'   => trim(post('ont_hotspot_ssid2', 'S.NET @Hotspot')),
    'ont_hotspot_ssid6'   => trim(post('ont_hotspot_ssid6', 'S.NET @Hotspot 5G')),
    'ont_hotspot_slot_fh' => (string)max(1, (int)post('ont_hotspot_slot_fh', 3)),
    'ont_hotspot_slot_other' => (string)max(1, (int)post('ont_hotspot_slot_other', 2)),
];

try {
    foreach ($keys as $key => $val) {
        db_execute(
            "INSERT INTO pppoe_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = ?",
            'sss', [$key, $val, $val]
        );
    }
    
    audit_log('update_pppoe_settings', 'Pengaturan PPPoE & Midtrans berhasil disimpan');
    flash_set('success', 'Pengaturan PPPoE & Midtrans berhasil disimpan!');
} catch (Throwable $e) {
    flash_set('error', 'Gagal menyimpan pengaturan: ' . $e->getMessage());
}

header('Location: /index.php?page=pppoe_settings');
exit;
