<?php
/**
 * Process — Reset & Rebuild Sales Log & Penagihan
 * Superadmin-only action to wipe messy sales/penagihan and rebuild from real used vouchers.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../include/functions.php';

auth_check();
auth_require_superadmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /index.php?page=report_sales');
    exit;
}

if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf'])) {
    flash_set('error', 'Token keamanan (CSRF) tidak valid.');
    header('Location: /index.php?page=report_sales');
    exit;
}

try {
    $res = rebuild_sales_and_penagihan();
    
    $msg = sprintf(
        "<strong>Reset &amp; Hitung Ulang Berhasil!</strong><br>" .
        "• Data lama dibersihkan: %d riwayat penjualan &amp; %d data penagihan.<br>" .
        "• Voucher login disinkronkan: %d voucher.<br>" .
        "• Transaksi riil dipulihkan: <strong>%d transaksi unik</strong>.<br>" .
        "• Total omset penjualan: <strong>%s</strong>.",
        $res['cleared_sales'],
        $res['cleared_penagihan'],
        $res['vouchers_synced'],
        $res['sales_rebuilt'],
        format_price($res['total_revenue'])
    );
    
    flash_set('success', $msg);
} catch (Throwable $e) {
    flash_set('error', 'Gagal memproses reset dan hitung ulang: ' . $e->getMessage());
}

header('Location: /index.php?page=report_sales');
exit;
