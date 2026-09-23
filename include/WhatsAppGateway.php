<?php
/**
 * S.NET RADIUS & PPPoE Manager
 * Universal WhatsApp Gateway Library
 * Supports: S.NET WA Web (Baileys Engine), Fonnte, Ultramsg, Green API, Generic REST API
 */

class WhatsAppGateway {
    private static ?self $instance = null;
    private array $config = [];
    private string $error = '';

    public function __construct(?array $config = null) {
        if ($config !== null) {
            $this->config = $config;
        } else {
            $this->loadConfig();
        }
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function loadConfig(): void {
        try {
            $tableExists = db_fetch_one("SHOW TABLES LIKE 'wa_config'");
            if (!$tableExists) {
                db_execute("CREATE TABLE IF NOT EXISTS wa_config (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    provider VARCHAR(50) DEFAULT 'waweb',
                    api_url VARCHAR(255) DEFAULT 'http://127.0.0.1:3000/api/send',
                    api_token VARCHAR(255) DEFAULT '',
                    device_id VARCHAR(100) DEFAULT '',
                    is_active TINYINT(1) DEFAULT 1,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            } else {
                try {
                    db_execute("ALTER TABLE wa_config MODIFY COLUMN provider VARCHAR(50) DEFAULT 'waweb'");
                } catch (Exception $e) {}
            }
            $row = db_fetch_one("SELECT * FROM wa_config WHERE is_active = 1 LIMIT 1");
            if ($row) {
                $this->config = $row;
            } else {
                $this->config = [
                    'provider' => 'waweb',
                    'api_url' => 'http://127.0.0.1:3000/api/send',
                    'api_token' => '',
                    'device_id' => '',
                    'is_active' => 1
                ];
            }
        } catch (Exception $e) {
            $this->config = [
                'provider' => 'waweb',
                'api_url' => 'http://127.0.0.1:3000/api/send',
                'api_token' => '',
                'device_id' => '',
                'is_active' => 1
            ];
        }
    }

    public function getLastError(): string {
        return $this->error;
    }

    public function isConfigured(): bool {
        $provider = $this->config['provider'] ?? 'waweb';
        if ($provider === 'waweb') {
            return true; // Self-hosted Baileys doesn't require cloud token
        }
        return !empty($this->config['api_token']);
    }

    /**
     * Normalisasi nomor telepon ke format internasional (contoh: 08123456789 -> 628123456789)
     */
    public static function normalizePhone(string $phone): string {
        $clean = preg_replace('/\D/', '', $phone);
        if (empty($clean)) return '';

        if (str_starts_with($clean, '08')) {
            return '62' . substr($clean, 1);
        } elseif (str_starts_with($clean, '8')) {
            return '62' . $clean;
        } elseif (str_starts_with($clean, '62')) {
            return $clean;
        }
        return $clean;
    }

    /**
     * Dapatkan domain aplikasi untuk link portal yang valid baik di HTTP maupun CLI / Cron
     */
    public static function getAppDomain(): string {
        if (!empty($_SERVER['HTTP_HOST'])) {
            return $_SERVER['HTTP_HOST'];
        }

        if (defined('APP_URL') && !empty(APP_URL)) {
            $parsed = parse_url(APP_URL, PHP_URL_HOST);
            if ($parsed) return $parsed;
            return preg_replace('#^https?://#', '', rtrim(APP_URL, '/'));
        }

        try {
            $domSetting = db_fetch_one("SELECT setting_value FROM pppoe_settings WHERE setting_key = 'company_domain' OR setting_key = 'app_url' LIMIT 1");
            if ($domSetting && !empty($domSetting['setting_value'])) {
                $val = trim($domSetting['setting_value']);
                return preg_replace('#^https?://#', '', rtrim($val, '/'));
            }
        } catch (Throwable $e) {}

        return 's.shawir.id';
    }

    /**
     * Kirim pesan teks atau gambar WhatsApp dengan Smart Retry untuk S.NET WA Web
     */
    public function send(string $phone, string $message, ?int $customerId = null, string $type = 'general', string $recipientName = '', ?string $imageUrl = null): array {
        $targetPhone = self::normalizePhone($phone);
        if (empty($targetPhone)) {
            $this->error = 'Nomor telepon tidak valid';
            $this->log($customerId, $phone, $recipientName, $type, $message, 'failed', 'Nomor telepon kosong / tidak valid');
            return ['success' => false, 'message' => $this->error];
        }

        if (!$this->isConfigured()) {
            $this->error = 'WhatsApp Gateway belum dikonfigurasi (API Token kosong)';
            $this->log($customerId, $targetPhone, $recipientName, $type, $message, 'failed', $this->error);
            return ['success' => false, 'message' => $this->error];
        }

        $provider = $this->config['provider'] ?? 'waweb';
        $apiUrl = $this->config['api_url'] ?: 'http://127.0.0.1:3000/api/send';
        $token = $this->config['api_token'] ?? '';
        $deviceId = $this->config['device_id'] ?? '';

        $maxAttempts = ($provider === 'waweb') ? 2 : 1;
        $attempt = 0;
        $isSuccess = false;
        $res = null;
        $httpCode = 0;
        $errorStr = '';

        while ($attempt < $maxAttempts && !$isSuccess) {
            $attempt++;
            if ($attempt > 1) {
                // Jeda 2 detik sebelum retry jika engine lokal sedang reconnecting
                sleep(2);
            }

            try {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                // Beri timeout 30 detik untuk mengakomodasi antrean aman pengiriman massal
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 6);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

                if ($provider === 'waweb') {
                    // S.NET Self-Hosted Baileys Engine (Port 3000)
                    $targetUrl = str_contains($apiUrl, '/api/send') ? $apiUrl : (rtrim($apiUrl, '/') . '/api/send');
                    curl_setopt($ch, CURLOPT_URL, $targetUrl);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                    
                    $postData = [
                        'phone' => $targetPhone,
                        'message' => $message
                    ];
                    if (!empty($imageUrl)) {
                        $postData['image_url'] = $imageUrl;
                    }
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
                } elseif ($provider === 'fonnte') {
                    curl_setopt($ch, CURLOPT_URL, $apiUrl);
                    curl_setopt($ch, CURLOPT_POST, true);
                    $postFields = [
                        'target' => $targetPhone,
                        'message' => $message,
                        'countryCode' => '62'
                    ];
                    if (!empty($imageUrl)) {
                        $postFields['url'] = $imageUrl;
                    }
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'Authorization: ' . $token
                    ]);
                } elseif ($provider === 'ultramsg') {
                    $endpoint = !empty($imageUrl) ? (rtrim($apiUrl, '/') . "/{$deviceId}/messages/image") : (rtrim($apiUrl, '/') . "/{$deviceId}/messages/chat");
                    curl_setopt($ch, CURLOPT_URL, $endpoint);
                    curl_setopt($ch, CURLOPT_POST, true);
                    $postFields = [
                        'token' => $token,
                        'to' => '+' . $targetPhone
                    ];
                    if (!empty($imageUrl)) {
                        $postFields['image'] = $imageUrl;
                        $postFields['caption'] = $message;
                    } else {
                        $postFields['body'] = $message;
                    }
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
                } elseif ($provider === 'greenapi') {
                    $endpoint = rtrim($apiUrl, '/') . "/waInstance{$deviceId}/sendMessage/{$token}";
                    curl_setopt($ch, CURLOPT_URL, $endpoint);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                        'chatId' => $targetPhone . '@c.us',
                        'message' => $message
                    ]));
                } else {
                    // Generic JSON API
                    curl_setopt($ch, CURLOPT_URL, $apiUrl);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'Content-Type: application/json',
                        'Authorization: Bearer ' . $token
                    ]);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                        'phone' => $targetPhone,
                        'target' => $targetPhone,
                        'to' => $targetPhone,
                        'message' => $message,
                        'image_url' => $imageUrl
                    ]));
                }

                $res = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $errorStr = curl_error($ch);
                curl_close($ch);
            } catch (Exception $e) {
                $errorStr = $e->getMessage();
            }

            $isSuccess = ($httpCode >= 200 && $httpCode < 300) && empty($errorStr);

            // Cek isi response spesifik
            if ($isSuccess && !empty($res)) {
                $json = json_decode($res, true);
                if (isset($json['status']) && $json['status'] === false) {
                    $isSuccess = false;
                    $this->error = $json['reason'] ?? $json['message'] ?? 'Gagal terkirim dari server provider';
                } elseif (isset($json['sent']) && $json['sent'] === false) {
                    $isSuccess = false;
                    $this->error = $json['message'] ?? 'Gagal terkirim';
                } elseif (isset($json['success']) && $json['success'] === false) {
                    $isSuccess = false;
                    $this->error = $json['message'] ?? 'Gagal terkirim dari engine WhatsApp';
                }
            } else {
                $this->error = !empty($errorStr) ? $errorStr : "HTTP Error $httpCode";
            }
        }

        $logStatus = $isSuccess ? 'success' : 'failed';
        $this->log($customerId, $targetPhone, $recipientName, $type, $message, $logStatus, $res ?: $this->error);

        return [
            'success' => $isSuccess,
            'message' => $isSuccess ? 'Pesan WhatsApp berhasil dikirim.' : $this->error,
            'response' => $res
        ];
    }

    /**
     * Catat log pengiriman ke database
     */
    private function log(?int $customerId, string $phone, string $recipientName, string $type, string $message, string $status, string $payload): void {
        try {
            db_execute(
                "INSERT INTO wa_logs (customer_id, phone, recipient_name, message_type, message_text, status, response_payload) VALUES (?, ?, ?, ?, ?, ?, ?)",
                'issssss',
                [$customerId, $phone, $recipientName, $type, $message, $status, $payload]
            );
        } catch (Exception $e) {}
    }

    /**
     * Render template pesan dengan variabel dinamis
     */
    public static function renderTemplate(string $template, array $data): string {
        $appDomain = self::getAppDomain();

        $placeholders = [
            '{nama}' => $data['full_name'] ?? $data['name'] ?? 'Pelanggan',
            '{username}' => $data['pppoe_username'] ?? $data['username'] ?? '',
            '{tagihan}' => isset($data['monthly_price']) ? 'Rp ' . number_format((float)$data['monthly_price'], 0, ',', '.') : (isset($data['amount']) ? 'Rp ' . number_format((float)$data['amount'], 0, ',', '.') : 'Rp 0'),
            '{jatuh_tempo}' => isset($data['due_day']) ? 'Tanggal ' . $data['due_day'] . ' ' . ($data['month_name'] ?? date('F Y')) : ($data['due_date'] ?? date('d M Y')),
            '{bulan}' => $data['month_name'] ?? date('F Y'),
            '{link_portal}' => $data['link_portal'] ?? ('https://' . $appDomain . '/portal/isolir.php?user=' . urlencode($data['pppoe_username'] ?? '')),
            '{link_receipt}' => !empty($data['link_receipt']) ? $data['link_receipt'] : ('https://' . $appDomain . '/portal/receipt.php'),
            '{cs_phone}' => $data['cs_phone'] ?? '081234567890',
            '{no_invoice}' => $data['no_invoice'] ?? $data['midtrans_order_id'] ?? ('INV-' . date('Ymd') . '-001'),
            '{waktu_bayar}' => $data['waktu_bayar'] ?? date('d M Y, H:i') . ' WIB',
            '{nama_layanan}' => $data['company_name'] ?? (defined('APP_COMPANY') ? APP_COMPANY : 'S.NET Internet'),
            '{company_name}' => $data['company_name'] ?? (defined('APP_COMPANY') ? APP_COMPANY : 'S.NET Internet'),
            '{portal_username}' => $data['portal_username'] ?? ($data['pppoe_username'] ?? ''),
            '{portal_password}' => $data['portal_password'] ?? '',
            '{paket}' => $data['profile'] ?? ($data['paket'] ?? ''),
            '{diterima_oleh}' => $data['diterima_oleh'] ?? $data['collector_name'] ?? $data['admin_name'] ?? 'Kasir / Petugas',
            '{petugas}' => $data['diterima_oleh'] ?? $data['collector_name'] ?? $data['admin_name'] ?? 'Kasir / Petugas',
            '{metode}' => strtoupper($data['payment_method'] ?? $data['metode'] ?? 'CASH'),
            '{catatan}' => $data['notes'] ?? ''
        ];

        return str_replace(array_keys($placeholders), array_values($placeholders), $template);
    }

    /**
     * Ambil template dari database berdasarkan kode
     */
    public static function getTemplate(string $code): ?array {
        try {
            $tmpl = db_fetch_one("SELECT * FROM wa_templates WHERE code = ? AND is_active = 1 LIMIT 1", 's', [$code]);
            if ($tmpl) return $tmpl;

            if ($code === 'welcome_customer') {
                return [
                    'code' => 'welcome_customer',
                    'name' => 'Pemberitahuan Pelanggan Baru & Akses Portal',
                    'message' => "Halo Kak {nama}, Selamat Datang di {company_name}! 🎉\n\nLayanan internet PPPoE Anda telah aktif. Berikut adalah rincian akun dan akses Portal Pelanggan Anda:\n\n🌐 *Detail Layanan:*\n• Nama: {nama}\n• Paket: {paket}\n• Jatuh Tempo: Tgl {jatuh_tempo} setiap bulan\n• Biaya Bulanan: {tagihan}\n\n🔑 *Akses Portal Pelanggan:*\nAnda dapat mengganti nama & sandi WiFi sendiri, mengecek tagihan, serta bayar bulanan secara online di:\n• Link Portal: {link_portal}\n• Username: *{portal_username}*\n• Password: *{portal_password}*\n\nSimpan informasi ini dengan baik. Jika butuh bantuan, hubungi kami: {cs_phone}. Terima kasih! 🙏"
                ];
            }

            if ($code === 'payment_success') {
                return [
                    'code' => 'payment_success',
                    'name' => 'Konfirmasi Pembayaran Lunas',
                    'message' => "Terima Kasih! Pembayaran Berhasil ✅\n\nYth. Kak {nama},\nPembayaran tagihan internet {nama_layanan} bulan {bulan} sebesar *{tagihan}* telah kami terima pada {waktu_bayar}.\n\nNo. Kwitansi: #{no_invoice}\nMetode: *{metode}*\nPenerima: {diterima_oleh}\nStatus: *LUNAS*\nKoneksi internet Anda aktif dan siap digunakan.\n\nLihat Kwitansi Digital: {link_receipt}\nTerima kasih telah setia bersama {nama_layanan}! ✨"
                ];
            }
            return null;
        } catch (Exception $e) {
            return null;
        }
    }
}
