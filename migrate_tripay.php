<?php
/**
 * Migration Script: Multi-Payment Gateway (Tripay & Midtrans)
 * Jalankan sekali untuk memastikan kolom & pengaturan siap:
 * php migrate_tripay.php
 */
define('IN_APP', true);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/include/PaymentGateway.php';

echo "=== Memulai Migrasi Multi-Payment Gateway (Tripay) ===\n";

try {
    PaymentGateway::initSchema();
    echo "✅ Skema tabel pppoe_payments dan pppoe_settings berhasil diverifikasi & diperbarui!\n";
} catch (Throwable $e) {
    echo "❌ Terjadi kesalahan migrasi: " . $e->getMessage() . "\n";
    exit(1);
}

echo "=== Migrasi Selesai Sukses ===\n";
