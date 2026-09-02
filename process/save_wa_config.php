<?php
/**
 * Save WhatsApp Config & Templates Handler
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../include/functions.php';

auth_require_superadmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: /index.php?page=pppoe_whatsapp");
    exit;
}

csrf_verify();

$action = post('action');

if ($action === 'save_config') {
    $provider  = post('provider', 'waweb');
    $api_token = trim(post('api_token', ''));
    $api_url   = trim(post('api_url', ''));
    $device_id = trim(post('device_id', ''));
    $is_active = (int)post('is_active', 0);

    if (empty($api_url)) {
        if ($provider === 'waweb') {
            $api_url = 'http://127.0.0.1:3000/api/send';
        } elseif ($provider === 'fonnte') {
            $api_url = 'https://api.fonnte.com/send';
        } else {
            $api_url = 'https://api.ultramsg.com';
        }
    }

    // Pastikan tipe kolom provider adalah VARCHAR(50) agar tidak terjadi data truncation
    try {
        db_execute("ALTER TABLE wa_config MODIFY COLUMN provider VARCHAR(50) DEFAULT 'waweb'");
    } catch (Exception $e) {}

    $existing = db_fetch_one("SELECT id FROM wa_config LIMIT 1");
    if ($existing) {
        db_execute(
            "UPDATE wa_config SET provider = ?, api_token = ?, api_url = ?, device_id = ?, is_active = ? WHERE id = ?",
            'ssssii',
            [$provider, $api_token, $api_url, $device_id, $is_active, $existing['id']]
        );
    } else {
        db_execute(
            "INSERT INTO wa_config (provider, api_token, api_url, device_id, is_active) VALUES (?, ?, ?, ?, ?)",
            'ssssi',
            [$provider, $api_token, $api_url, $device_id, $is_active]
        );
    }

    audit_log('UPDATE_WA_CONFIG', "Pengaturan WhatsApp Gateway diubah ($provider)");
    flash_set('success', 'Konfigurasi WhatsApp Gateway berhasil disimpan!');
    header("Location: /index.php?page=pppoe_whatsapp&tab=config");
    exit;
}

if ($action === 'save_template') {
    $code    = trim(post('code', ''));
    $name    = trim(post('name', ''));
    $message = trim(post('message', ''));

    if (empty($code) || empty($message)) {
        flash_set('error', 'Kode dan isi template tidak boleh kosong.');
        header("Location: /index.php?page=pppoe_whatsapp&tab=templates");
        exit;
    }

    db_execute(
        "UPDATE wa_templates SET name = ?, message = ? WHERE code = ?",
        'sss',
        [$name, $message, $code]
    );

    audit_log('UPDATE_WA_TEMPLATE', "Template WhatsApp '$code' diperbarui");
    flash_set('success', "Template '$name' berhasil disimpan!");
    header("Location: /index.php?page=pppoe_whatsapp&tab=templates");
    exit;
}

header("Location: /index.php?page=pppoe_whatsapp");
exit;
