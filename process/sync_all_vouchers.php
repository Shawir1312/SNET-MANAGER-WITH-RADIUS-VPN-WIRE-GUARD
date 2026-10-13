<?php
/**
 * Process — Sync All Profiles to All Vouchers in RADIUS
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../include/functions.php';

auth_check();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /index.php?page=profile_list');
    exit;
}

if (empty($_POST['csrf']) || $_POST['csrf'] !== ($_SESSION['csrf_token'] ?? '')) {
    flash_set('error', 'Invalid CSRF token.');
    header('Location: /index.php?page=profile_list');
    exit;
}

try {
    $res = sync_all_profiles_to_vouchers(false);
    $msg = "Berhasil menyinkronkan {$res['profile_count']} profil ke {$res['total_vouchers']} voucher ({$res['active_updated']} voucher aktif dihitung ulang). Seluruh atribut Session-Timeout di FreeRADIUS telah diperbarui sesuai durasi profil saat ini.";
    flash_set('success', $msg);
    audit_log('sync_all_profiles', 'all', 0, $msg);
} catch (Throwable $e) {
    flash_set('error', 'Gagal menyinkronkan voucher: ' . $e->getMessage());
}

header('Location: /index.php?page=profile_list');
exit;
