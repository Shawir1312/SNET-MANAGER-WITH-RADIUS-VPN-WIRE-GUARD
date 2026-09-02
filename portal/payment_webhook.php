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

    db_execute(
        "UPDATE pppoe_customers SET status = 'active', isolated_at = NULL, isolated_reason = '' WHERE id = ?",
        'i', [$payment['cid']]
    );

    // Reaktivasi di MikroTik
    $router = db_fetch_one("SELECT * FROM routers WHERE id = ?", 'i', [$payment['router_id']]);
    if ($router) {
        try {
            $api = new RouterosAPI();
            $api->debug = false;
            if ($api->connect($router['ip_address'], $router['api_user'], $router['api_password'], (int)$router['api_port'])) {
                $profile = !empty($payment['profile']) ? $payment['profile'] : 'default';
                
                // Ubah profile secret MikroTik kembali ke normal
                $api->comm('/ppp/secret/set', [
                    '?name' => $payment['pppoe_username'],
                    '=profile' => $profile
                ]);

                // Disconnect sesi aktif isolir agar dial ulang langsung normal
                $activeSessions = $api->comm('/ppp/active/print', [
                    '?name' => $payment['pppoe_username']
                ]);
                foreach ($activeSessions as $act) {
                    if (isset($act['.id'])) {
                        $api->comm('/ppp/active/remove', ['=.id' => $act['.id']]);
                    }
                }

                $api->disconnect();
            }
        } catch (Exception $e) {
            // Log error reaktivasi
            audit_log('MIKROTIK_ERROR', "Auto-reaktivasi MikroTik gagal untuk {$payment['pppoe_username']}: " . $e->getMessage());
        }
    }

    // Kirim notifikasi WhatsApp konfirmasi pembayaran lunas
    if (!empty($payment['phone'])) {
        try {
            $template = WhatsAppGateway::getTemplate('payment_success');
            if ($template) {
                $monthNames = [
                    1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',5=>'Mei',6=>'Juni',
                    7=>'Juli',8=>'Agustus',9=>'September',10=>'Oktober',11=>'November',12=>'Desember'
                ];
                $wa = WhatsAppGateway::getInstance();
                $receiptLink = 'https://' . ($_SERVER['HTTP_HOST'] ?? 's.shawir.id') . '/portal/receipt.php?id=' . $payment['id'];
                $msgBody = WhatsAppGateway::renderTemplate($template['message'], [
                    'full_name' => $payment['full_name'],
                    'pppoe_username' => $payment['pppoe_username'],
                    'amount' => $payment['amount'],
                    'month_name' => ($monthNames[$payment['period_month']] ?? $payment['period_month']) . ' ' . $payment['period_year'],
                    'no_invoice' => $orderId,
                    'waktu_bayar' => date('d M Y, H:i') . ' WIB',
                    'link_receipt' => $receiptLink,
                    'company_name' => $settings['company_name'] ?? (defined('APP_COMPANY') ? APP_COMPANY : 'S.NET Internet')
                ]);
                $wa->send($payment['phone'], $msgBody, $payment['cid'], 'payment_success', $payment['full_name']);
            }
        } catch (Exception $e) {}
    }

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
