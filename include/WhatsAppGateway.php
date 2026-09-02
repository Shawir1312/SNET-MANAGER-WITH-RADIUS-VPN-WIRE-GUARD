<?php
/**
 * S.NET RADIUS & PPPoE Manager
 * Universal WhatsApp Gateway Library
 * Supports: Fonnte, Ultramsg, Green API, Generic REST API
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
                    provider ENUM('fonnte','ultramsg','greenapi','generic') DEFAULT 'fonnte',
                    api_url VARCHAR(255) DEFAULT 'https://api.fonnte.com/send',
                    api_token VARCHAR(255) DEFAULT '',
                    device_id VARCHAR(100) DEFAULT '',
                    is_active TINYINT(1) DEFAULT 1,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            }
            $row = db_fetch_one("SELECT * FROM wa_config WHERE is_active = 1 LIMIT 1");
            if ($row) {
                $this->config = $row;
            } else {
                $this->config = [
                    'provider' => 'fonnte',
                    'api_url' => 'https://api.fonnte.com/send',
                    'api_token' => '',
                    'device_id' => '',
                    'is_active' => 1
                ];
            }
        } catch (Exception $e) {
            $this->config = [
                'provider' => 'fonnte',
                'api_url' => 'https://api.fonnte.com/send',
                'api_token' => '',
                'device_id' => '',
                'is_active' => 0
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
     * Kirim pesan teks atau gambar WhatsApp
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

        $res = null;
        $httpCode = 0;
        $errorStr = '';

        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
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
        
        // Cek isi response spesifik Fonnte/Ultramsg
        if ($isSuccess && !empty($res)) {
            $json = json_decode($res, true);
            if (isset($json['status']) && $json['status'] === false) {
                $isSuccess = false;
                $this->error = $json['reason'] ?? $json['message'] ?? 'Gagal terkirim dari server provider';
            } elseif (isset($json['sent']) && $json['sent'] === false) {
                $isSuccess = false;
                $this->error = $json['message'] ?? 'Gagal terkirim';
            }
        } else {
            $this->error = !empty($errorStr) ? $errorStr : "HTTP Error $httpCode";
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
        $placeholders = [
            '{nama}' => $data['full_name'] ?? $data['name'] ?? 'Pelanggan',
            '{username}' => $data['pppoe_username'] ?? $data['username'] ?? '',
            '{tagihan}' => isset($data['monthly_price']) ? 'Rp ' . number_format((float)$data['monthly_price'], 0, ',', '.') : (isset($data['amount']) ? 'Rp ' . number_format((float)$data['amount'], 0, ',', '.') : 'Rp 0'),
            '{jatuh_tempo}' => isset($data['due_day']) ? 'Tanggal ' . $data['due_day'] . ' ' . ($data['month_name'] ?? date('F Y')) : ($data['due_date'] ?? date('d M Y')),
            '{bulan}' => $data['month_name'] ?? date('F Y'),
            '{link_portal}' => $data['link_portal'] ?? ('https://' . ($_SERVER['HTTP_HOST'] ?? 'dash.snetwifi.com') . '/portal/isolir.php?user=' . urlencode($data['pppoe_username'] ?? '')),
            '{link_receipt}' => $data['link_receipt'] ?? '',
            '{cs_phone}' => $data['cs_phone'] ?? '081234567890',
            '{no_invoice}' => $data['no_invoice'] ?? $data['midtrans_order_id'] ?? ('INV-' . date('Ymd') . '-001'),
            '{waktu_bayar}' => $data['waktu_bayar'] ?? date('d M Y, H:i') . ' WIB',
            '{nama_layanan}' => $data['company_name'] ?? (defined('APP_COMPANY') ? APP_COMPANY : 'S.NET Internet')
        ];

        return str_replace(array_keys($placeholders), array_values($placeholders), $template);
    }

    /**
     * Ambil template dari database berdasarkan kode
     */
    public static function getTemplate(string $code): ?array {
        try {
            return db_fetch_one("SELECT * FROM wa_templates WHERE code = ? AND is_active = 1 LIMIT 1", 's', [$code]);
        } catch (Exception $e) {
            return null;
        }
    }
}
