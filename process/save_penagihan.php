<?php
/**
 * Process - Save Penagihan (Billing Report)
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../include/functions.php';

auth_check();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /index.php?page=penagihan_report');
    exit;
}

if (empty($_POST['csrf']) || $_POST['csrf'] !== $_SESSION['csrf_token']) {
    flash_set('error', 'Invalid CSRF.');
    header('Location: /index.php?page=penagihan_report');
    exit;
}

$router_id = (int)post('router_id');
$profile_id = (int)post('profile_id');
$total_pendapatan = (float)post('total_pendapatan');
$catatan = sanitize(post('catatan'));
$ignore_previous = (post('ignore_previous') === '1');
$admin_id = current_admin()['id'];
$tanggal = date('Y-m-d');

if ($router_id <= 0 || $profile_id <= 0 || $total_pendapatan <= 0) {
    flash_set('error', 'Data tidak valid.');
    header('Location: /index.php?page=penagihan_report');
    exit;
}

try {
    // 1. Get profile data
    $profile = db_fetch_one("SELECT * FROM profiles WHERE id = ?", 'i', [$profile_id]);
    if (!$profile) {
        throw new Exception("Profile/Reseller tidak ditemukan.");
    }
    
    $price = (float)$profile['price'];
    $percent = (float)$profile['reseller_percent'];
    
    // 2. Calculate values
    $bagian_reseller = $total_pendapatan * ($percent / 100);
    $pendapatan_bersih = $total_pendapatan - $bagian_reseller;
    $estimasi_voucher = $price > 0 ? floor($total_pendapatan / $price) : 0;
    
    // 3. Calculate actual used vouchers vs billed using the new scheme
    $summary = get_reseller_billing_summary($router_id, $profile_id, $ignore_previous);
    $voucher_aktual = $summary['voucher_aktual'];
    
    // 4. Determine status
    if ($ignore_previous && $voucher_aktual === 0) {
        $voucher_aktual = $estimasi_voucher;
        $status = 'sesuai';
    } else {
        if ($estimasi_voucher == $voucher_aktual) {
            $status = 'sesuai';
        } elseif ($estimasi_voucher < $voucher_aktual) {
            $status = 'tekor';
        } else {
            $status = 'lebih';
        }
    }
    
    // 5. Save to database
    db_execute(
        "INSERT INTO penagihan (router_id, profile_id, total_pendapatan, bagian_reseller, pendapatan_bersih, estimasi_voucher, voucher_aktual, status_kecocokan, catatan, ditagih_oleh, tanggal) 
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        'iidddiissis',
        [
            $router_id, $profile_id, $total_pendapatan, $bagian_reseller, $pendapatan_bersih, 
            $estimasi_voucher, $voucher_aktual, $status, $catatan, $admin_id, $tanggal
        ]
    );
    
    // 6. Audit log
    audit_log('tambah_penagihan', "Reseller ID: {$profile_id} - Rp " . number_format($total_pendapatan, 0, ',', '.') . " (Status: {$status}, Est: {$estimasi_voucher}, Target: {$voucher_aktual})", $router_id);
    
    $detail_msg = "";
    if ($status === 'tekor') {
        $selisih = $voucher_aktual - $estimasi_voucher;
        $detail_msg = " (Tekor {$selisih} voucher akan dicatat dan dibawa ke penagihan berikutnya)";
    } elseif ($status === 'lebih') {
        $selisih = $estimasi_voucher - $voucher_aktual;
        $detail_msg = " (Kelebihan {$selisih} voucher akan diperhitungkan di penagihan berikutnya)";
    } else {
        $detail_msg = " (Setoran pas/lunas)";
    }
    flash_set('success', "Laporan penagihan berhasil disimpan! Status: " . strtoupper($status) . $detail_msg);

    
} catch (Throwable $e) {
    flash_set('error', 'Gagal menyimpan penagihan: ' . $e->getMessage());
}

header('Location: /index.php?page=penagihan_report');
