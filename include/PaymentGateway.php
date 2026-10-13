<?php
/**
 * S.NET Unified Multi-Gateway Payment Engine
 * Supports Midtrans Snap & Tripay Payment Gateway
 */
defined('IN_APP') or define('IN_APP', true);

class PaymentGateway {
    const GATEWAY_MIDTRANS = 'midtrans';
    const GATEWAY_TRIPAY   = 'tripay';

    /**
     * Jalankan migrasi database otomatis jika tabel/kolom baru belum ada
     */
    public static function initSchema(): void {
        static $initialized = false;
        if ($initialized) return;
        $initialized = true;

        try {
            // 1. Kolom tambahan di pppoe_payments
            $cols = [
                'payment_gateway' => "ALTER TABLE pppoe_payments ADD COLUMN payment_gateway VARCHAR(50) DEFAULT 'midtrans' AFTER payment_method",
                'checkout_url'    => "ALTER TABLE pppoe_payments ADD COLUMN checkout_url TEXT DEFAULT NULL AFTER midtrans_status",
                'pay_code'        => "ALTER TABLE pppoe_payments ADD COLUMN pay_code VARCHAR(100) DEFAULT NULL AFTER checkout_url",
                'qr_url'          => "ALTER TABLE pppoe_payments ADD COLUMN qr_url TEXT DEFAULT NULL AFTER pay_code"
            ];

            foreach ($cols as $colName => $sql) {
                $check = db_fetch_one("SHOW COLUMNS FROM pppoe_payments LIKE ?", 's', [$colName]);
                if (!$check) {
                    @db_execute($sql);
                }
            }

            // 2. Default settings untuk Tripay di pppoe_settings
            $defaultSettings = [
                'active_payment_gateway' => 'both',
                'tripay_mode'            => 'sandbox',
                'tripay_merchant_code'   => '',
                'tripay_api_key'         => '',
                'tripay_private_key'     => ''
            ];

            foreach ($defaultSettings as $k => $v) {
                @db_execute(
                    "INSERT IGNORE INTO pppoe_settings (setting_key, setting_value) VALUES (?, ?)",
                    'ss', [$k, $v]
                );
            }
        } catch (Throwable $e) {
            // Jangan ganggu aplikasi jika permission terbatas
        }
    }

    /**
     * Ambil seluruh konfigurasi pppoe_settings
     */
    public static function getSettings(): array {
        static $cache = null;
        if ($cache !== null) return $cache;

        self::initSchema();

        $raw = db_fetch_all("SELECT setting_key, setting_value FROM pppoe_settings");
        $settings = [];
        foreach ($raw as $r) {
            $settings[$r['setting_key']] = $r['setting_value'];
        }
        $cache = $settings;
        return $cache;
    }

    /**
     * Cek apakah Midtrans sudah dikonfigurasi
     */
    public static function isMidtransConfigured(): bool {
        $s = self::getSettings();
        return !empty($s['midtrans_server_key']) && !empty($s['midtrans_client_key']);
    }

    /**
     * Cek apakah Tripay sudah dikonfigurasi
     */
    public static function isTripayConfigured(): bool {
        $s = self::getSettings();
        return !empty($s['tripay_api_key']) && !empty($s['tripay_private_key']) && !empty($s['tripay_merchant_code']);
    }

    /**
     * Ambil daftar gateway yang aktif dan siap dipakai
     * Return: ['midtrans' => [...], 'tripay' => [...]]
     */
    public static function getAvailableGateways(): array {
        $s = self::getSettings();
        $activeMode = $s['active_payment_gateway'] ?? 'both';

        $available = [];

        $hasMidtrans = self::isMidtransConfigured();
        $hasTripay   = self::isTripayConfigured();

        if ($hasMidtrans && ($activeMode === 'both' || $activeMode === 'midtrans')) {
            $available['midtrans'] = [
                'id'          => 'midtrans',
                'name'        => 'Midtrans Snap',
                'description' => 'QRIS (GoPay, ShopeePay), Transfer Virtual Account (BCA, Mandiri, BRI, BNI), Indomaret, Alfamart',
                'mode'        => $s['midtrans_mode'] ?? 'sandbox',
                'client_key'  => $s['midtrans_client_key'] ?? '',
                'snap_url'    => ($s['midtrans_mode'] ?? 'sandbox') === 'production' 
                    ? 'https://app.midtrans.com/snap/snap.js' 
                    : 'https://app.sandbox.midtrans.com/snap/snap.js',
                'badge'       => 'Midtrans Snap',
                'icon'        => 'bi-credit-card-2-front'
            ];
        }

        if ($hasTripay && ($activeMode === 'both' || $activeMode === 'tripay')) {
            $available['tripay'] = [
                'id'          => 'tripay',
                'name'        => 'Tripay Payment Gateway',
                'description' => 'QRIS Real-time Instan, Virtual Account Semua Bank, Alfamart & Indomaret',
                'mode'        => $s['tripay_mode'] ?? 'sandbox',
                'badge'       => 'Tripay Gateway',
                'icon'        => 'bi-lightning-charge-fill'
            ];
        }

        return $available;
    }

    // =========================================================================
    // TRIPAY IMPLEMENTATION
    // =========================================================================

    public static function getTripayBaseUrl(): string {
        $s = self::getSettings();
        return (($s['tripay_mode'] ?? 'sandbox') === 'production')
            ? 'https://tripay.co.id/api/'
            : 'https://tripay.co.id/api-sandbox/';
    }

    /**
     * Dapatkan daftar kanal pembayaran Tripay yang aktif untuk merchant
     */
    public static function getTripayChannels(): array {
        $s = self::getSettings();
        $apiKey = $s['tripay_api_key'] ?? '';
        if (empty($apiKey)) {
            return self::getDefaultTripayChannels();
        }

        $url = self::getTripayBaseUrl() . 'merchant/payment-channel';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 4,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Accept: application/json'
            ]
        ]);
        $response = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if (!$err && !empty($response)) {
            $data = json_decode($response, true);
            if (isset($data['success']) && $data['success'] && !empty($data['data'])) {
                // Filter hanya yang aktif
                $channels = [];
                foreach ($data['data'] as $c) {
                    if (isset($c['active']) && !$c['active']) continue;
                    $channels[] = [
                        'code'     => $c['code'],
                        'name'     => $c['name'],
                        'group'    => $c['group'] ?? 'Lainnya',
                        'icon_url' => $c['icon_url'] ?? '',
                        'fee'      => $c['total_fee'] ?? []
                    ];
                }
                if (!empty($channels)) return $channels;
            }
        }

        return self::getDefaultTripayChannels();
    }

    /**
     * Saluran Tripay bawaan jika API channel sedang offline / lambat
     */
    public static function getDefaultTripayChannels(): array {
        return [
            ['code' => 'QRIS',      'name' => 'QRIS (Semua E-Wallet & Bank)', 'group' => 'E-Wallet', 'icon_url' => 'https://assets.tripay.co.id/upload/payment-icon/bcmZhnv7yc1583852102.png'],
            ['code' => 'BCAVA',     'name' => 'BCA Virtual Account',           'group' => 'Virtual Account', 'icon_url' => 'https://assets.tripay.co.id/upload/payment-icon/ytBKvaleGy1605261821.png'],
            ['code' => 'BRIVA',     'name' => 'BRI Virtual Account',           'group' => 'Virtual Account', 'icon_url' => 'https://assets.tripay.co.id/upload/payment-icon/m14ybe4i011583852078.png'],
            ['code' => 'BNIVA',     'name' => 'BNI Virtual Account',           'group' => 'Virtual Account', 'icon_url' => 'https://assets.tripay.co.id/upload/payment-icon/Wn2epmRPb01583852057.png'],
            ['code' => 'MANDIRIVA', 'name' => 'Mandiri Virtual Account',       'group' => 'Virtual Account', 'icon_url' => 'https://assets.tripay.co.id/upload/payment-icon/aT1bW9wM101583852035.png'],
            ['code' => 'PERMATAVA', 'name' => 'Permata Virtual Account',       'group' => 'Virtual Account', 'icon_url' => 'https://assets.tripay.co.id/upload/payment-icon/0O7tM7876a1583852123.png'],
            ['code' => 'ALFAMART',  'name' => 'Alfamart / Alfamidi',           'group' => 'Convenience Store', 'icon_url' => 'https://assets.tripay.co.id/upload/payment-icon/ec831969ce7d7fb3fc78275e63ddf2fc1608696803.png'],
            ['code' => 'INDOMARET', 'name' => 'Indomaret',                     'group' => 'Convenience Store', 'icon_url' => 'https://assets.tripay.co.id/upload/payment-icon/90cae2524a8fc3ae49265f2cb7394eb11608696753.png']
        ];
    }

    /**
     * Buat transaksi Closed Payment di Tripay
     */
    public static function createTripayTransaction(array $params): array {
        $s = self::getSettings();
        $apiKey       = $s['tripay_api_key'] ?? '';
        $privateKey   = $s['tripay_private_key'] ?? '';
        $merchantCode = $s['tripay_merchant_code'] ?? '';

        if (empty($apiKey) || empty($privateKey) || empty($merchantCode)) {
            return ['success' => false, 'error' => 'Kredensial Tripay (API Key, Private Key, Merchant Code) belum lengkap di Pengaturan.'];
        }

        $orderId = $params['order_id'];
        $amount  = (int)$params['amount'];
        $method  = $params['method'] ?? 'QRIS';

        // Signature Tripay: HMAC-SHA256(merchant_code + merchant_ref + amount, private_key)
        $signature = hash_hmac('sha256', $merchantCode . $orderId . $amount, $privateKey);

        $customerName  = !empty($params['customer_name']) ? $params['customer_name'] : 'Pelanggan PPPoE';
        $customerPhone = !empty($params['customer_phone']) ? preg_replace('/[^0-9]/', '', $params['customer_phone']) : '081234567890';
        $customerEmail = !empty($params['customer_email']) ? $params['customer_email'] : (preg_replace('/[^a-zA-Z0-9]/', '', $params['username'] ?? 'user') . '@snet.id');

        $returnUrl = $params['return_url'] ?? ('https://' . ($_SERVER['HTTP_HOST'] ?? 'dash.snetwifi.com') . '/portal/index.php?paid=1&tab=billing');

        $body = [
            'method'         => $method,
            'merchant_ref'   => $orderId,
            'amount'         => $amount,
            'customer_name'  => $customerName,
            'customer_email' => $customerEmail,
            'customer_phone' => $customerPhone,
            'order_items'    => [
                [
                    'sku'      => 'TAGIHAN-' . ($params['period_month'] ?? date('n')) . ($params['period_year'] ?? date('Y')),
                    'name'     => $params['item_name'] ?? ('Tagihan Internet ' . $orderId),
                    'price'    => $amount,
                    'quantity' => 1
                ]
            ],
            'return_url'   => $returnUrl,
            'expired_time' => time() + (24 * 3600), // 24 Jam
            'signature'    => $signature
        ];

        $url = self::getTripayBaseUrl() . 'transaction/create';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
                'Accept: application/json'
            ]
        ]);
        $response = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['success' => false, 'error' => 'Koneksi ke Tripay gagal: ' . $err];
        }

        $res = json_decode($response, true);
        if (!$res) {
            return ['success' => false, 'error' => 'Respons tidak valid dari Tripay: ' . $response];
        }

        if (empty($res['success']) || empty($res['data'])) {
            $msg = $res['message'] ?? 'Gagal membuat transaksi di Tripay';
            return ['success' => false, 'error' => $msg];
        }

        $d = $res['data'];
        return [
            'success'      => true,
            'gateway'      => 'tripay',
            'order_id'     => $orderId,
            'reference'    => $d['reference'] ?? '',
            'method'       => $d['payment_method'] ?? $method,
            'payment_name' => $d['payment_name'] ?? $method,
            'amount'       => $d['amount'] ?? $amount,
            'fee'          => $d['total_fee'] ?? 0,
            'checkout_url' => $d['checkout_url'] ?? '',
            'pay_code'     => $d['pay_code'] ?? '',
            'qr_url'       => $d['qr_url'] ?? '',
            'qr_string'    => $d['qr_string'] ?? '',
            'instructions' => $d['instructions'] ?? [],
            'expired_time' => $d['expired_time'] ?? (time() + 86400)
        ];
    }

    /**
     * Cek status transaksi Tripay berdasarkan reference atau orderId
     */
    public static function checkTripayStatus(string $reference): ?array {
        $reference = trim($reference);
        if (empty($reference)) return null;

        $s = self::getSettings();
        $apiKey = $s['tripay_api_key'] ?? '';
        if (empty($apiKey)) return null;

        $url = self::getTripayBaseUrl() . 'transaction/detail?reference=' . urlencode($reference);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Accept: application/json'
            ]
        ]);
        $response = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err || empty($response)) return null;

        $res = json_decode($response, true);
        if (isset($res['success']) && $res['success'] && !empty($res['data'])) {
            return $res['data'];
        }

        return null;
    }

    /**
     * Verifikasi webhook callback dari Tripay
     */
    public static function verifyTripayWebhook(string $rawPayload, ?string $receivedSignature): bool {
        $s = self::getSettings();
        $privateKey = $s['tripay_private_key'] ?? '';
        if (empty($privateKey) || empty($receivedSignature)) {
            return false;
        }

        $computedSignature = hash_hmac('sha256', $rawPayload, $privateKey);
        return hash_equals($computedSignature, $receivedSignature);
    }

    // =========================================================================
    // MIDTRANS IMPLEMENTATION
    // =========================================================================

    public static function getMidtransSnapUrl(): string {
        $s = self::getSettings();
        return (($s['midtrans_mode'] ?? 'sandbox') === 'production')
            ? 'https://app.midtrans.com/snap/v1/transactions'
            : 'https://app.sandbox.midtrans.com/snap/v1/transactions';
    }

    public static function createMidtransSnap(array $params): array {
        $s = self::getSettings();
        $serverKey = $s['midtrans_server_key'] ?? '';
        $clientKey = $s['midtrans_client_key'] ?? '';
        $mode      = $s['midtrans_mode'] ?? 'sandbox';

        if (empty($serverKey) || empty($clientKey)) {
            return ['success' => false, 'error' => 'Kredensial Midtrans belum dikonfigurasi di Pengaturan.'];
        }

        $orderId = $params['order_id'];
        $amount  = (int)$params['amount'];

        $payload = [
            'transaction_details' => [
                'order_id'     => $orderId,
                'gross_amount' => $amount
            ],
            'customer_details' => [
                'first_name' => $params['customer_name'] ?? 'Pelanggan PPPoE',
                'phone'      => $params['customer_phone'] ?? '',
                'notes'      => $params['item_name'] ?? 'Tagihan Internet'
            ],
            'item_details' => [
                [
                    'id'       => 'INET-' . ($params['period_month'] ?? date('n')) . ($params['period_year'] ?? date('Y')),
                    'price'    => $amount,
                    'quantity' => 1,
                    'name'     => $params['item_name'] ?? ('Tagihan Internet ' . $orderId)
                ]
            ],
            'callbacks' => [
                'finish' => $params['return_url'] ?? ('https://' . ($_SERVER['HTTP_HOST'] ?? 'dash.snetwifi.com') . '/portal/index.php?paid=1&tab=billing')
            ]
        ];

        $snapUrl = self::getMidtransSnapUrl();
        $ch = curl_init($snapUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Basic ' . base64_encode($serverKey . ':')
            ]
        ]);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['success' => false, 'error' => 'Koneksi Midtrans gagal: ' . $err];
        }

        $d = json_decode($res, true);
        if (isset($d['token'])) {
            return [
                'success'    => true,
                'gateway'    => 'midtrans',
                'token'      => $d['token'],
                'order_id'   => $orderId,
                'client_key' => $clientKey,
                'mode'       => $mode
            ];
        }

        return ['success' => false, 'error' => $d['error_messages'][0] ?? 'Gagal membuat transaksi Midtrans.'];
    }

    public static function checkMidtransStatus(string $orderId): ?array {
        return check_midtrans_order_status($orderId);
    }

    // =========================================================================
    // UNIFIED STATUS & NOTIFICATION PROCESSOR
    // =========================================================================

    /**
     * Proses pembayaran berhasil untuk gateway apapun (Tripay / Midtrans)
     */
    public static function processPaymentSuccess(int $paymentId, string $gateway, string $transactionId = ''): array {
        $payment = db_fetch_one(
            "SELECT pp.*, pc.id as cid, pc.full_name, pc.pppoe_username, pc.phone, pc.profile, pc.router_id, pc.status as cust_status
             FROM pppoe_payments pp 
             JOIN pppoe_customers pc ON pp.customer_id = pc.id 
             WHERE pp.id = ?",
            'i', [$paymentId]
        );

        if (!$payment) {
            return ['success' => false, 'message' => 'Payment record not found'];
        }

        // Tandai lunas di pppoe_payments
        db_execute(
            "UPDATE pppoe_payments 
             SET midtrans_status = 'paid', 
                 payment_method = ?,
                 payment_gateway = ?,
                 midtrans_tx_id = COALESCE(NULLIF(?, ''), midtrans_tx_id),
                 paid_at = NOW() 
             WHERE id = ?",
            'sssi', [$gateway, $gateway, $transactionId, $paymentId]
        );

        // Auto Buka Isolir jika pelanggan terisolir
        $unisoResult = unisolir_pppoe_customer((int)$payment['cid']);
        if (!$unisoResult['mikrotik_ok']) {
            audit_log('MIKROTIK_ERROR', "Auto-reaktivasi MikroTik belum berhasil untuk {$payment['pppoe_username']}");
        }

        $gatewayLabel = ($gateway === 'tripay') ? 'Sistem Online (Tripay)' : 'Sistem Online (Midtrans)';

        // Kirim notifikasi WhatsApp
        send_pppoe_payment_notification((int)$payment['id'], $gatewayLabel);

        audit_log(
            strtoupper($gateway) . '_PAID',
            "Pembayaran Online {$gatewayLabel} Lunas: {$payment['full_name']} ({$payment['pppoe_username']}) Rp " . number_format($payment['amount'], 0, ',', '.')
        );

        return ['success' => true, 'uniso' => $unisoResult];
    }
}
