<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';

echo "<h2>Menjalankan Migrasi Database Fase 4 & 5 (PPPoE, Portal & WhatsApp Gateway)</h2>";

// 1. Kolom pppoe_customers
$checkPassword = db_fetch_one("SHOW COLUMNS FROM pppoe_customers LIKE 'portal_password'");
if (!$checkPassword) {
    db_execute("ALTER TABLE pppoe_customers ADD COLUMN portal_password VARCHAR(255) DEFAULT '' AFTER pppoe_username");
    echo "✓ Kolom portal_password berhasil ditambahkan.<br>";
}

$checkUsername = db_fetch_one("SHOW COLUMNS FROM pppoe_customers LIKE 'portal_username'");
if (!$checkUsername) {
    db_execute("ALTER TABLE pppoe_customers ADD COLUMN portal_username VARCHAR(100) DEFAULT '' AFTER portal_password");
    echo "✓ Kolom portal_username berhasil ditambahkan.<br>";
}

$checkOntSn = db_fetch_one("SHOW COLUMNS FROM pppoe_customers LIKE 'ont_sn'");
if (!$checkOntSn) {
    db_execute("ALTER TABLE pppoe_customers ADD COLUMN ont_sn VARCHAR(100) DEFAULT '' AFTER address");
    echo "✓ Kolom ont_sn berhasil ditambahkan.<br>";
}

$checkIsolatedAt = db_fetch_one("SHOW COLUMNS FROM pppoe_customers LIKE 'isolated_at'");
if (!$checkIsolatedAt) {
    db_execute("ALTER TABLE pppoe_customers ADD COLUMN isolated_at DATETIME DEFAULT NULL AFTER status");
    echo "✓ Kolom isolated_at berhasil ditambahkan.<br>";
}

$checkIsolatedReason = db_fetch_one("SHOW COLUMNS FROM pppoe_customers LIKE 'isolated_reason'");
if (!$checkIsolatedReason) {
    db_execute("ALTER TABLE pppoe_customers ADD COLUMN isolated_reason VARCHAR(255) DEFAULT '' AFTER isolated_at");
    echo "✓ Kolom isolated_reason berhasil ditambahkan.<br>";
}

$checkIsFree = db_fetch_one("SHOW COLUMNS FROM pppoe_customers LIKE 'is_free'");
if (!$checkIsFree) {
    db_execute("ALTER TABLE pppoe_customers ADD COLUMN is_free TINYINT(1) DEFAULT 0 AFTER monthly_price");
    echo "✓ Kolom is_free (Pelanggan Gratis) berhasil ditambahkan.<br>";
}

// 2. Tabel WhatsApp Gateway
db_execute("CREATE TABLE IF NOT EXISTS wa_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    provider ENUM('waweb','fonnte','ultramsg','greenapi','generic') DEFAULT 'waweb',
    api_url VARCHAR(255) DEFAULT 'http://127.0.0.1:3000/api/send',
    api_token VARCHAR(255) DEFAULT '',
    device_id VARCHAR(100) DEFAULT '',
    is_active TINYINT(1) DEFAULT 1,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
echo "✓ Tabel wa_config siap.<br>";

// Insert default wa_config if empty
$waCfg = db_fetch_one("SELECT id FROM wa_config LIMIT 1");
if (!$waCfg) {
    db_execute("INSERT INTO wa_config (provider, api_url, api_token, is_active) VALUES ('waweb', 'http://127.0.0.1:3000/api/send', '', 1)");
    echo "✓ Data default wa_config diinisialisasi.<br>";
}

db_execute("CREATE TABLE IF NOT EXISTS wa_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    message TEXT NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
echo "✓ Tabel wa_templates siap.<br>";

db_execute("CREATE TABLE IF NOT EXISTS wa_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT DEFAULT NULL,
    phone VARCHAR(30) NOT NULL,
    recipient_name VARCHAR(150) DEFAULT '',
    message_type VARCHAR(50) DEFAULT 'general',
    message_text TEXT NOT NULL,
    status ENUM('success','failed','pending') DEFAULT 'pending',
    response_payload TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
echo "✓ Tabel wa_logs siap.<br>";

// Inisialisasi Template Pesan Default jika kosong
$templates = [
    [
        'code' => 'reminder_h3',
        'name' => 'Pengingat Tagihan (H-3 Jatuh Tempo)',
        'message' => "Halo Kak {nama} ({username}),\n\nKami informasikan bahwa tagihan internet {nama_layanan} untuk bulan {bulan} sebesar *{tagihan}* akan jatuh tempo pada *{jatuh_tempo}*.\n\nMohon lakukan pembayaran tepat waktu agar kenyamanan berinternet tetap terjaga.\n\nPortal & Bayar Online: {link_portal}\nTerima kasih atas kerja samanya. 🙏"
    ],
    [
        'code' => 'reminder_h1',
        'name' => 'Pengingat Tagihan (H-1 Jatuh Tempo)',
        'message' => "Halo Kak {nama},\n\nTagihan internet {nama_layanan} Anda sebesar *{tagihan}* akan jatuh tempo *BESOK ({jatuh_tempo})*.\n\nUntuk menghindari gangguan / isolir otomatis oleh sistem, silakan melakukan pembayaran melalui transfer atau portal online:\n{link_portal}\n\nTerima kasih! 🙏"
    ],
    [
        'code' => 'reminder_h0',
        'name' => 'Pemberitahuan Hari Jatuh Tempo (Hari H)',
        'message' => "Yth. Pelanggan {nama_layanan},\nKak {nama} ({username})\n\nHari ini adalah batas tanggal jatuh tempo pembayaran tagihan internet Anda sebesar *{tagihan}*.\n\nSilakan segera selesaikan pembayaran hari ini. Bayar mudah via QRIS/VA melalui portal:\n{link_portal}\n\nTerima kasih atas perhatiannya. 😊"
    ],
    [
        'code' => 'isolir',
        'name' => 'Pemberitahuan Layanan Terisolir',
        'message' => "Pemberitahuan: Layanan Internet Terisolir ⚠️\n\nYth. Kak {nama} ({username}),\nLayanan internet {nama_layanan} Anda saat ini telah dinonaktifkan sementara karena melewati batas waktu jatuh tempo.\n\nTotal Tunggakan: *{tagihan}*\n\nAgar koneksi aktif kembali secara otomatis dalam hitungan detik, silakan bayar sekarang melalui tautan berikut:\n{link_portal}\n\nButuh bantuan? Hubungi WhatsApp CS kami: {cs_phone}"
    ],
    [
        'code' => 'payment_success',
        'name' => 'Konfirmasi Pembayaran Lunas',
        'message' => "Terima Kasih! Pembayaran Berhasil ✅\n\nYth. Kak {nama},\nPembayaran tagihan internet {nama_layanan} bulan {bulan} sebesar *{tagihan}* telah kami terima pada {waktu_bayar}.\n\nNo. Kwitansi: #{no_invoice}\nStatus: *LUNAS*\nKoneksi internet Anda aktif dan siap digunakan.\n\nLihat Kwitansi Digital: {link_receipt}\nTerima kasih telah setia bersama {nama_layanan}! ✨"
    ]
];

foreach ($templates as $t) {
    $exist = db_fetch_one("SELECT id FROM wa_templates WHERE code = ?", 's', [$t['code']]);
    if (!$exist) {
        db_execute("INSERT INTO wa_templates (code, name, message, is_active) VALUES (?, ?, ?, 1)", 'sss', [$t['code'], $t['name'], $t['message']]);
        echo "✓ Template <b>{$t['name']}</b> ditambahkan.<br>";
    }
}

echo "<br><h3 style='color:green'>🎉 Seluruh Migrasi Database PPPoE & WhatsApp Selesai!</h3>";

