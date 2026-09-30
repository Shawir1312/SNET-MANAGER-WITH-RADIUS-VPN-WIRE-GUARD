<?php
/**
 * Process — Sync RADIUS Data
 * Membangun ulang seluruh data FreeRADIUS:
 * 1. Hotspot Vouchers -> radcheck, radreply
 * 2. PPPoE Customers -> radcheck, radreply, radusergroup
 * 3. Routers & NAS -> tabel nas (IP & RADIUS Secret)
 * 4. Restart / Reload service FreeRADIUS
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../include/functions.php';

auth_check();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /index.php?page=backup');
    exit;
}

// CSRF check
if (empty($_POST['csrf']) || $_POST['csrf'] !== ($_SESSION['csrf_token'] ?? '')) {
    flash_set('error', 'Token CSRF tidak valid. Silakan coba lagi.');
    header('Location: /index.php?page=backup');
    exit;
}

$admin = current_admin();
if ($admin['role'] !== 'superadmin') {
    flash_set('error', 'Hanya superadmin yang dapat melakukan sinkronisasi FreeRADIUS.');
    header('Location: /index.php?page=backup');
    exit;
}

try {
    $resSync = execute_radius_sync();
    $voucherCount = $resSync['voucher_count'];
    $pppoeCount   = $resSync['pppoe_count'];
    $nasCount     = $resSync['nas_count'];

    if ($resSync['restart']['success']) {
        flash_set('success', "Sinkronisasi FreeRADIUS Berhasil! {$voucherCount} voucher hotspot, {$pppoeCount} akun PPPoE, dan {$nasCount} router NAS telah terdaftar aktif di FreeRADIUS. Service FreeRADIUS aktif &amp; berhasil direload.");
    } else {
        flash_set('warning', "Sinkronisasi Database Berhasil ({$voucherCount} voucher, {$pppoeCount} PPPoE, {$nasCount} router NAS). Namun service FreeRADIUS belum dapat direstart otomatis oleh web server. Silakan buka menu Pengaturan &rarr; Status Service FreeRADIUS atau jalankan 'sudo systemctl restart freeradius' di terminal.");
    }
} catch (Throwable $e) {
    flash_set('error', 'Terjadi kesalahan saat sinkronisasi: ' . $e->getMessage());
}

header('Location: /index.php?page=backup');
exit;

