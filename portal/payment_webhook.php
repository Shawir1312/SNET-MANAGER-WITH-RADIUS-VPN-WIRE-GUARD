<?php
/**
 * Midtrans Payment Webhook / Notification Handler
 * Endpoint URL: https://domain-anda/portal/payment_webhook.php
 */
define('IN_APP', true);
header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../include/functions.php';
require_once __DIR__ . '/../lib/routeros_api.class.php';
require_once __DIR__ . '/../include/WhatsAppGateway.php';

// 1. Ambil payload JSON dari Midtrans
$rawPayload = file_get_contents('php://input');
$data = json_decode($rawPayload, true);

if (empty($data)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Empty payload']);
    exit;
}

// 2. Load settings dari database
$settings = [];
$rawSettings = db_fetch_all("SELECT setting_key, setting_value FROM pppoe_settings");
foreach ($rawSettings as $s) {
    $settings[$s['setting_key']] = $s['setting_value'];
}

$serverKey = $settings['midtrans_server_key'] ?? '';
if (empty($serverKey)) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Midtrans Server Key not configured']);
    exit;
}

// 3. Verifikasi SHA-512 Signature Key
$orderId = $data['order_id'] ?? '';
$statusCode = $data['status_code'] ?? '';
$grossAmount = $data['gross_amount'] ?? '';
$signatureKey = $data['signature_key'] ?? '';
$transactionStatus = $data['transaction_status'] ?? '';
$fraudStatus = $data['fraud_status'] ?? '';
$transactionId = $data['transaction_id'] ?? '';

$expectedSignature = hash('sha512', $orderId . $statusCode . $grossAmount . $serverKey);

if ($signatureKey !== $expectedSignature) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid signature']);
    exit;
}

// 4. Cari transaksi di database
$payment = db_fetch_one(
    "SELECT pp.*, pc.id as cid, pc.full_name, pc.pppoe_username, pc.phone, pc.profile, pc.router_id 
     FROM pppoe_payments pp 
     JOIN pppoe_customers pc ON pp.customer_id = pc.id 
     WHERE pp.midtrans_order_id = ?",
    's', [$orderId]
);

if (!$payment) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Payment record not found']);
    exit;
}

// 5. Proses Berdasarkan Status Transaksi
if (in_array($transactionStatus, ['settlement', 'capture']) && in_array($fraudStatus, ['accept', ''])) {
    // ── PEMBAYARAN SUKSES / LUNAS ──
    db_execute(
        "UPDATE pppoe_payments SET midtrans_status = 'paid', midtrans_tx_id = ? WHERE id = ?",
        'si', [$transactionId, $payment['id']]
    );

    // Auto Buka Isolir di Database, MikroTik (secret & active kick), FreeRADIUS, dan GenieACS
    $unisoResult = unisolir_pppoe_customer((int)$payment['cid']);
    if (!$unisoResult['mikrotik_ok']) {
        audit_log('MIKROTIK_ERROR', "Auto-reaktivasi MikroTik belum berhasil untuk {$payment['pppoe_username']}");
    }

    // Kirim notifikasi WhatsApp konfirmasi pembayaran lunas
    send_pppoe_payment_notification((int)$payment['id'], 'Sistem Online (Midtrans)');

    audit_log('MIDTRANS_PAID', "Pembayaran Online Midtrans Lunas: {$payment['full_name']} ({$payment['pppoe_username']}) Rp " . number_format($payment['amount'], 0, ',', '.'));

} elseif (in_array($transactionStatus, ['cancel', 'deny', 'expire'])) {
    db_execute(
        "UPDATE pppoe_payments SET midtrans_status = ? WHERE id = ?",
        'si', [$transactionStatus, $payment['id']]
    );
} elseif ($transactionStatus === 'pending') {
    db_execute(
        "UPDATE pppoe_payments SET midtrans_status = 'pending' WHERE id = ?",
        'i', [$payment['id']]
    );
}

http_response_code(200);
echo json_encode(['status' => 'success', 'message' => 'Notification processed successfully']);
