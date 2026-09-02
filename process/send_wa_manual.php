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
$cust = null;
if ($customer_id > 0) {
    $cust = db_fetch_one("SELECT * FROM pppoe_customers WHERE id = ?", 'i', [$customer_id]);
    if ($cust) {
        $recipient_name = $cust['full_name'];
    }
}

// Auto-replace placeholders jika pesan berisi tag {variabel}
if (str_contains($message, '{')) {
    $settings_raw = db_fetch_all("SELECT setting_key, setting_value FROM pppoe_settings");
    $settings = [];
    foreach ($settings_raw as $s) {
        $settings[$s['setting_key']] = $s['setting_value'];
    }
    $companyName = $settings['company_name'] ?? (defined('APP_COMPANY') ? APP_COMPANY : 'S.NET Internet');
    $csPhone = $settings['company_phone'] ?? '';

    $lastPayment = null;
    if ($customer_id > 0) {
        $lastPayment = db_fetch_one("SELECT * FROM pppoe_payments WHERE customer_id = ? ORDER BY id DESC LIMIT 1", 'i', [$customer_id]);
    }

    $monthNames = [
        1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',5=>'Mei',6=>'Juni',
        7=>'Juli',8=>'Agustus',9=>'September',10=>'Oktober',11=>'November',12=>'Desember'
    ];
    $curMonth = (int)date('n');
    $curYear = (int)date('Y');
    $curMonthName = $monthNames[$curMonth] . ' ' . $curYear;

    $receiptLink = '';
    $invoiceNo = 'INV-' . date('Ymd') . '-001';
    if ($lastPayment) {
        $receiptLink = 'https://' . ($_SERVER['HTTP_HOST'] ?? 's.shawir.id') . '/portal/receipt.php?id=' . $lastPayment['id'];
        $invoiceNo = $lastPayment['midtrans_order_id'] ?: ('INV-' . str_pad($lastPayment['id'], 6, '0', STR_PAD_LEFT));
    } elseif ($customer_id > 0) {
        $receiptLink = 'https://' . ($_SERVER['HTTP_HOST'] ?? 's.shawir.id') . '/portal/receipt.php?id=' . $customer_id;
        $invoiceNo = 'INV-' . date('Ymd') . '-' . str_pad($customer_id, 3, '0', STR_PAD_LEFT);
    }

    $message = WhatsAppGateway::renderTemplate($message, [
        'full_name' => $cust['full_name'] ?? ($recipient_name ?: 'Pelanggan'),
        'pppoe_username' => $cust['pppoe_username'] ?? '',
        'monthly_price' => $cust['monthly_price'] ?? 0,
        'amount' => $lastPayment['amount'] ?? ($cust['monthly_price'] ?? 0),
        'due_day' => $cust['due_day'] ?? 1,
        'month_name' => $curMonthName,
        'link_receipt' => $receiptLink,
        'link_portal' => 'https://' . ($_SERVER['HTTP_HOST'] ?? 's.shawir.id') . '/portal/isolir.php?user=' . urlencode($cust['pppoe_username'] ?? ''),
        'no_invoice' => $invoiceNo,
        'waktu_bayar' => date('d M Y, H:i') . ' WIB',
        'cs_phone' => $csPhone,
        'company_name' => $companyName
    ]);
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
