<?php
/**
 * Process — Set Titik Awal Penagihan (Reset Baseline ke 0)
 * Superadmin-only action to mark all past vouchers as settled, setting current target to 0.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../include/functions.php';

auth_check();
auth_require_superadmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /index.php?page=penagihan_report');
    exit;
}

if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf'])) {
    flash_set('error', 'Token keamanan (CSRF) tidak valid.');
    header('Location: /index.php?page=penagihan_report');
    exit;
}

try {
    $admin = current_admin();
    $admin_id = $admin['id'] ?? 1;
    $count = set_all_resellers_baseline($admin_id);
    
    flash_set('success', "<strong>Berhasil menyetel titik awal baru!</strong><br>Seluruh riwayat voucher masa lalu telah ditandai lunas/selesai. Target tagihan semua reseller kini dimulai dari <strong>0 voucher</strong>.");
} catch (Throwable $e) {
    flash_set('error', 'Gagal menyetel titik awal: ' . $e->getMessage());
}

header('Location: /index.php?page=penagihan_report');
exit;
