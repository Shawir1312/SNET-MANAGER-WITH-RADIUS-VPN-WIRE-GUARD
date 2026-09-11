<?php
/**
 * Process — Save PPPoE Manual / Cash Payment (Kasir)
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

$cid          = (int)post('customer_id');
$amount       = (float)post('amount');
$period_month = (int)post('period_month', (int)date('n'));
$period_year  = (int)post('period_year', (int)date('Y'));
$method       = sanitize(post('payment_method', 'cash'));
$notes        = sanitize(post('notes', ''));
$auto_uniso   = post('auto_unisolir', '1') === '1';
$redirectPage = post('redirect_page', 'pppoe_customers');
$redirectRid  = (int)post('router_id');

if ($cid <= 0 || $amount <= 0) {
    flash_set('error', 'Data pembayaran tidak lengkap atau nominal tidak valid.');
    header("Location: /index.php?page=$redirectPage&router_id=$redirectRid");
    exit;
}

$customer = db_fetch_one("SELECT * FROM pppoe_customers WHERE id = ?", 'i', [$cid]);
if (!$customer) {
    flash_set('error', 'Pelanggan tidak ditemukan.');
    header("Location: /index.php?page=$redirectPage&router_id=$redirectRid");
    exit;
}

try {
    $cleanU = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $customer['pppoe_username']));
    $orderId = 'MANUAL-' . date('Ymd') . '-' . substr($cleanU, 0, 5) . '-' . rand(1000, 9999);
    $admin = current_admin();
    $adminName = ($admin['full_name'] ?? '') ?: (($admin['name'] ?? '') ?: ($admin['username'] ?? 'Admin'));
    $collector = sanitize(post('collector_name', ''));
    if (empty($collector)) {
        $collector = $adminName;
    }
    $send_wa = post('send_wa', '1') === '1';
    
    if (empty($notes)) {
        $notes = "Diterima oleh {$collector} (" . ucfirst($method) . ")";
    }

    // Insert payment record
    db_execute(
        "INSERT INTO pppoe_payments (customer_id, amount, payment_method, midtrans_order_id, midtrans_status, period_month, period_year, notes, paid_at)
         VALUES (?, ?, ?, ?, 'paid', ?, ?, ?, NOW())",
        'idssiis',
        [$cid, $amount, $method, $orderId, $period_month, $period_year, $notes]
    );
    $payId = db_last_id();

    // Auto un-isolir jika pelanggan berstatus isolir
    if ($auto_uniso && $customer['status'] === 'isolated') {
        unisolir_pppoe_customer($cid);
    }

    // Kirim notifikasi WhatsApp ke pelanggan jika opsi aktif
    $waStatusTxt = '';
    if ($send_wa && $payId > 0) {
        $waRes = send_pppoe_payment_notification($payId, $collector);
        if ($waRes['success']) {
            $waStatusTxt = " Notifikasi WhatsApp bukti lunas berhasil dikirim ke pelanggan.";
        } else {
            $waStatusTxt = " (WhatsApp: " . $waRes['message'] . ")";
        }
    }

    audit_log('pppoe_payment', "Catat bayar: {$customer['pppoe_username']} Rp " . number_format($amount, 0, ',', '.') . " ($orderId) oleh {$collector}", $customer['router_id']);
    flash_set('success', "Pembayaran untuk '{$customer['full_name']}' (Periode $period_month/$period_year) sebesar Rp " . number_format($amount, 0, ',', '.') . " berhasil dicatat!{$waStatusTxt}");

} catch (Throwable $e) {
    flash_set('error', 'Gagal mencatat pembayaran: ' . $e->getMessage());
}

header("Location: /index.php?page=$redirectPage&router_id=" . ($redirectRid ?: $customer['router_id']));
exit;
