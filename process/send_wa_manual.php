<?php
/**
 * Send WhatsApp Message Handler (Manual & Test)
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../include/functions.php';
require_once __DIR__ . '/../include/WhatsAppGateway.php';

auth_require_superadmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: /index.php?page=pppoe_whatsapp");
    exit;
}

csrf_verify();

$phone       = trim(post('phone', ''));
$message     = trim(post('message', ''));
$customer_id = (int)post('customer_id', 0);
$is_test     = (int)post('is_test', 0);
$redirect    = post('redirect', '');

if (empty($phone) || empty($message)) {
    flash_set('error', 'Nomor telepon dan isi pesan tidak boleh kosong.');
    header("Location: " . ($redirect ?: "/index.php?page=pppoe_whatsapp&tab=" . ($is_test ? 'config' : 'send')));
    exit;
}

$recipient_name = '';
if ($customer_id > 0) {
    $cust = db_fetch_one("SELECT full_name FROM pppoe_customers WHERE id = ?", 'i', [$customer_id]);
    if ($cust) {
        $recipient_name = $cust['full_name'];
    }
}

$wa = WhatsAppGateway::getInstance();
$result = $wa->send($phone, $message, $customer_id ?: null, $is_test ? 'test' : 'manual', $recipient_name);

if ($result['success']) {
    flash_set('success', 'Pesan WhatsApp berhasil dikirim ke ' . htmlspecialchars($phone));
} else {
    flash_set('error', 'Gagal mengirim WhatsApp: ' . htmlspecialchars($result['message']));
}

header("Location: " . ($redirect ?: "/index.php?page=pppoe_whatsapp&tab=" . ($is_test ? 'config' : 'logs')));
exit;
