<?php
/**
 * Unified Payment Webhook / Notification Handler
 * Supports: Midtrans Snap & Tripay Payment Gateway
 * 
 * Endpoints:
 * - Midtrans: https://domain/portal/payment_webhook.php (or ?gateway=midtrans)
 * - Tripay:   https://domain/portal/payment_webhook.php (or ?gateway=tripay)
 */
define('IN_APP', true);
header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../include/functions.php';
require_once __DIR__ . '/../lib/routeros_api.class.php';
require_once __DIR__ . '/../include/WhatsAppGateway.php';
require_once __DIR__ . '/../include/PaymentGateway.php';

// Ambil payload mentah dari HTTP body
$rawPayload = file_get_contents('php://input');
$data = json_decode($rawPayload, true);

if (empty($rawPayload) && empty($_POST)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Empty payload']);
    exit;
}

// Deteksi Gateway (Query param ?gateway= atau deteksi otomatis header/payload)
$reqGateway = strtolower(trim($_GET['gateway'] ?? ''));

$isTripay = ($reqGateway === 'tripay') 
    || isset($_SERVER['HTTP_X_CALLBACK_SIGNATURE'])
    || isset($_SERVER['HTTP_X_CALLBACK_EVENT'])
    || (is_array($data) && isset($data['merchant_ref']) && isset($data['reference']));

$settings = PaymentGateway::getSettings();

// =============================================================================
// 1. TRIPAY WEBHOOK HANDLER
// =============================================================================
if ($isTripay) {
    $tripaySignature = $_SERVER['HTTP_X_CALLBACK_SIGNATURE'] ?? '';
    $tripayEvent     = $_SERVER['HTTP_X_CALLBACK_EVENT'] ?? 'payment_status';

    $privateKey = $settings['tripay_private_key'] ?? '';
    if (empty($privateKey)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Tripay Private Key not configured']);
        exit;
    }

    // Validasi Signature Tripay: HMAC-SHA256 dari raw input menggunakan Private Key
    $computedSig = hash_hmac('sha256', $rawPayload, $privateKey);
    if (!hash_equals($computedSig, $tripaySignature)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid Tripay callback signature']);
        exit;
    }

    if ($tripayEvent !== 'payment_status') {
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'Unrecognized event ignored']);
        exit;
    }

    $merchantRef   = $data['merchant_ref'] ?? '';
    $reference     = $data['reference'] ?? '';
    $status        = strtoupper($data['status'] ?? '');
    $totalAmount   = (int)($data['total_amount'] ?? 0);

    if (empty($merchantRef)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing merchant_ref']);
        exit;
    }

    // Cari data transaksi di database pppoe_payments
    $payment = db_fetch_one(
        "SELECT pp.*, pc.id as cid, pc.full_name, pc.pppoe_username, pc.phone, pc.profile, pc.router_id 
         FROM pppoe_payments pp 
         JOIN pppoe_customers pc ON pp.customer_id = pc.id 
         WHERE pp.midtrans_order_id = ? OR pp.midtrans_tx_id = ?",
        'ss', [$merchantRef, $reference]
    );

    if (!$payment) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Payment record not found']);
        exit;
    }

    // Update status transaksi
    if ($status === 'PAID') {
        PaymentGateway::processPaymentSuccess((int)$payment['id'], 'tripay', $reference);
    } elseif ($status === 'EXPIRED') {
        db_execute("UPDATE pppoe_payments SET midtrans_status = 'expire' WHERE id = ?", 'i', [$payment['id']]);
    } elseif (in_array($status, ['FAILED', 'REFUND'])) {
        db_execute("UPDATE pppoe_payments SET midtrans_status = 'cancel' WHERE id = ?", 'i', [$payment['id']]);
    }

    http_response_code(200);
    echo json_encode(['success' => true]);
    exit;
}

// =============================================================================
// 2. MIDTRANS WEBHOOK HANDLER
// =============================================================================
$serverKey = $settings['midtrans_server_key'] ?? '';
if (empty($serverKey)) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Midtrans Server Key not configured']);
    exit;
}

// Verifikasi SHA-512 Signature Key Midtrans
$orderId           = $data['order_id'] ?? '';
$statusCode        = $data['status_code'] ?? '';
$grossAmount       = $data['gross_amount'] ?? '';
$signatureKey      = $data['signature_key'] ?? '';
$transactionStatus = $data['transaction_status'] ?? '';
$fraudStatus       = $data['fraud_status'] ?? '';
$transactionId     = $data['transaction_id'] ?? '';

$expectedSignature = hash('sha512', $orderId . $statusCode . $grossAmount . $serverKey);

if ($signatureKey !== $expectedSignature) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid signature']);
    exit;
}

// Cari transaksi di database
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

// Proses Berdasarkan Status Transaksi
if (in_array($transactionStatus, ['settlement', 'capture']) && in_array($fraudStatus, ['accept', ''])) {
    PaymentGateway::processPaymentSuccess((int)$payment['id'], 'midtrans', $transactionId);
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
