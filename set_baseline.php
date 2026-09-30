<?php
/**
 * CLI Tool — Set Titik Awal Penagihan Reseller (Nol-kan Tagihan Lalu)
 * 
 * Penggunaan via SSH / Terminal VPS:
 *   php set_baseline.php
 */

if (php_sapi_name() !== 'cli') {
    require_once __DIR__ . '/config/config.php';
    require_once __DIR__ . '/config/auth.php';
    if (!isset($_SESSION['admin'])) {
        header('Location: /login.php');
        exit;
    }
    header('Location: /index.php?page=penagihan_report');
    exit;
}

define('CLI_MODE', true);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/include/functions.php';

$_SESSION['admin'] = [
    'id'       => 1,
    'username' => 'terminal_cli',
    'role'     => 'superadmin'
];

echo "\n===============================================================\n";
echo "    S.NET RADIUS — SETEL TITIK AWAL PENAGIHAN RESELLER KE 0    \n";
echo "===============================================================\n";
echo "Proses ini akan menandai seluruh riwayat voucher masa lalu sebagai\n";
echo "lunas/selesai. Target tagihan semua reseller akan dimulai dari 0 vcr.\n";
echo "===============================================================\n\n";

try {
    $count = set_all_resellers_baseline(1);
    echo "✓ Berhasil menyetel baseline untuk {$count} router-reseller!\n";
    echo "✓ Target tagihan seluruh reseller sekarang dimulai dari 0 voucher.\n\n";
    echo "Silakan refresh halaman Laporan Penagihan di browser.\n\n";
    exit(0);
} catch (Throwable $e) {
    echo "✗ Gagal: " . $e->getMessage() . "\n\n";
    exit(1);
}
