<?php
/**
 * Process — Send PPPoE Payment Receipt via WhatsApp
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../include/functions.php';

auth_check();

$id = (int)get('id');
$csrf = get('csrf');
$redirect = get('redirect', 'pppoe_payments');

if ($id <= 0 || empty($csrf) || $csrf !== $_SESSION['csrf_token']) {
    flash_set('error', 'Token CSRF atau parameter tidak valid.');
    header("Location: /index.php?page=$redirect");
    exit;
}

$admin = current_admin();
$adminName = $admin['full_name'] ?: ($admin['username'] ?? 'Admin');

$res = send_pppoe_payment_notification($id, $adminName, true);

if ($res['success']) {
    flash_set('success', '✅ Notifikasi WhatsApp bukti pembayaran berhasil dikirim ke pelanggan.');
} else {
    flash_set('error', '❌ Gagal mengirim WhatsApp: ' . $res['message']);
}

header("Location: /index.php?page=$redirect");
exit;
