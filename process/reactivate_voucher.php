<?php
/**
 * Process — Reactivate / Restore Expired Voucher(s)
 * Mengaktifkan kembali voucher yang expired (misal akibat salah hitung saat migrasi/restart)
 * Memulihkan data ke radcheck, radreply, dan tabel vouchers.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../include/functions.php';

auth_check();

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
       || (isset($_GET['format']) && $_GET['format'] === 'json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Metode request tidak diizinkan.']);
        exit;
    }
    header('Location: /index.php?page=voucher_list');
    exit;
}

// CSRF check
$csrf = $_POST['csrf'] ?? '';
if (empty($csrf) || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Token CSRF tidak valid. Silakan refresh halaman.']);
        exit;
    }
    flash_set('error', 'Token CSRF tidak valid. Silakan coba lagi.');
    header('Location: /index.php?page=voucher_list');
    exit;
}

$action = post('action', 'reactivate'); // 'reactivate' or 'reset'
$reset_usage = ($action === 'reset');
$extend_hours = max(0, (int)post('extend_hours', 0));

// Target IDs
$ids = [];
if (!empty($_POST['voucher_id'])) {
    $ids = [(int)$_POST['voucher_id']];
} elseif (!empty($_POST['voucher_ids'])) {
    if (is_array($_POST['voucher_ids'])) {
        $ids = array_map('intval', $_POST['voucher_ids']);
    } else {
        $ids = array_map('intval', array_filter(explode(',', (string)$_POST['voucher_ids'])));
    }
}

if (empty($ids)) {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Tidak ada voucher yang dipilih.']);
        exit;
    }
    flash_set('error', 'Tidak ada voucher yang dipilih untuk dipulihkan.');
    header('Location: /index.php?page=voucher_list');
    exit;
}

$access = accessible_router_ids();
$success_count = 0;
$fail_count = 0;
$last_message = '';

foreach ($ids as $vid) {
    if ($vid <= 0) continue;

    // Check operator permission
    if ($access !== null) {
        $chk = db_fetch_one("SELECT router_id FROM vouchers WHERE id = ?", 'i', [$vid]);
        if (!$chk || ($chk['router_id'] !== null && !in_array($chk['router_id'], $access))) {
            $fail_count++;
            continue;
        }
    }

    $res = reactivate_voucher($vid, $reset_usage, $extend_hours);
    if ($res['success']) {
        $success_count++;
        $last_message = $res['message'];
    } else {
        $fail_count++;
        $last_message = $res['message'];
    }
}

if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode([
        'success'       => ($success_count > 0),
        'message'       => $success_count === 1 ? $last_message : "Berhasil memulihkan {$success_count} voucher." . ($fail_count > 0 ? " ({$fail_count} gagal)" : ""),
        'success_count' => $success_count,
        'fail_count'    => $fail_count,
    ]);
    exit;
}

if ($success_count > 0) {
    $msg = $success_count === 1 ? $last_message : "Berhasil mengaktifkan kembali {$success_count} voucher.";
    if ($fail_count > 0) {
        $msg .= " ({$fail_count} voucher gagal diproses).";
    }
    flash_set('success', $msg);
} else {
    flash_set('error', 'Gagal memulihkan voucher: ' . ($last_message ?: 'Terjadi kesalahan sistem.'));
}

$redirect = post('redirect', '/index.php?page=voucher_list');
header('Location: ' . $redirect);
exit;

