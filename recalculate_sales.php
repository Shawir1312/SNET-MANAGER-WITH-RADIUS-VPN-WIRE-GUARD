<?php
/**
 * CLI Tool — Reset & Hitung Ulang Pendapatan Penjualan Hotspot & Reseller
 * 
 * Penggunaan via SSH / Terminal VPS:
 *   php recalculate_sales.php
 * Atau tanpa prompt konfirmasi:
 *   php recalculate_sales.php --force
 */

if (php_sapi_name() !== 'cli') {
    // Jika diakses via browser web, arahkan ke web panel
    require_once __DIR__ . '/config/config.php';
    require_once __DIR__ . '/config/auth.php';
    if (!isset($_SESSION['admin'])) {
        header('Location: /login.php');
        exit;
    }
    header('Location: /index.php?page=report_sales');
    exit;
}

define('CLI_MODE', true);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/include/functions.php';

// Inisialisasi session admin untuk CLI audit log
$_SESSION['admin'] = [
    'id'       => 1,
    'username' => 'terminal_cli',
    'role'     => 'superadmin'
];

echo "\n";
echo "===============================================================\n";
echo "      S.NET RADIUS — RESET & HITUNG ULANG PENDAPATAN           \n";
echo "===============================================================\n";
echo "Perhatian: Tindakan ini akan:\n";
echo " 1. Mengosongkan data lama di tabel 'sales_log' & 'penagihan'.\n";
echo " 2. Mencocokkan sesi login riil di FreeRADIUS (radacct).\n";
echo " 3. Menghitung ulang riwayat penjualan dari awal (1 voucher = 1 transaksi).\n";
echo "===============================================================\n\n";

$is_force = in_array('--force', $argv, true) || in_array('-f', $argv, true) || in_array('--yes', $argv, true);

if (!$is_force) {
    echo "Apakah Anda yakin ingin melanjutkan? (ketik 'ya' untuk konfirmasi): ";
    $handle = fopen("php://stdin", "r");
    $line = trim(fgets($handle));
    fclose($handle);

    if (strtolower($line) !== 'ya' && strtolower($line) !== 'y') {
        echo "\n[BATAL] Operasi dibatalkan oleh pengguna.\n\n";
        exit(0);
    }
}

echo "\n[1/4] Memeriksa koneksi database & kolom profil...\n";
try {
    ensure_profile_columns();
    echo "      ✓ Database terhubung & skema siap.\n";
} catch (Throwable $e) {
    echo "      ✗ Gagal koneksi database: " . $e->getMessage() . "\n";
    exit(1);
}

echo "[2/4] Membaca data lama & membersihkan tabel...\n";
try {
    $stats = rebuild_sales_and_penagihan();
    echo "      ✓ Riwayat sales_log dibersihkan: " . number_format($stats['cleared_sales']) . " baris.\n";
    echo "      ✓ Riwayat penagihan dibersihkan: " . number_format($stats['cleared_penagihan']) . " baris.\n";
} catch (Throwable $e) {
    echo "      ✗ Terjadi kesalahan saat proses reset: " . $e->getMessage() . "\n";
    exit(1);
}

echo "[3/4] Sinkronisasi sesi FreeRADIUS & verifikasi status voucher...\n";
echo "      ✓ Voucher disinkronkan dari radacct: " . number_format($stats['vouchers_synced']) . " voucher.\n";
echo "      ✓ Voucher expired diperbarui: " . number_format($stats['vouchers_expired']) . " voucher.\n";

echo "[4/4] Membangun kembali catatan penjualan bersih...\n";
echo "      ✓ Total transaksi riil dipulihkan: " . number_format($stats['sales_rebuilt']) . " transaksi.\n";
echo "      ✓ Total omset penjualan: Rp " . number_format($stats['total_revenue'], 0, ',', '.') . "\n";
echo "      ✓ Cabang/router terdampak: " . number_format($stats['routers_affected']) . " router.\n";

echo "\n===============================================================\n";
echo " SUKSES! Data pendapatan telah dihitung ulang secara akurat.\n";
echo " Silakan cek kembali Dashboard dan Menu Laporan Penjualan.\n";
echo "===============================================================\n\n";
exit(0);
