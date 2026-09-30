<?php
/**
 * Process - Delete Usage Log
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../include/functions.php';

auth_check();

$username = get('username');
$nasipaddress = get('nasipaddress');
$from = get('from');
$to = get('to');
$month = get('month');

$admin = current_admin();

if ($admin['role'] !== 'superadmin') {
    flash_set('error', 'Hanya superadmin yang dapat menghapus data pemakaian.');
    header('Location: /index.php?page=report_usage');
    exit;
}

if (!$username) {
    flash_set('error', 'Username tidak valid.');
    header('Location: /index.php?page=report_usage');
    exit;
}

try {
    $where = ['username = ?'];
    $params = [$username];
    $types = 's';
    
    if ($nasipaddress) {
        $where[] = 'nasipaddress = ?';
        $params[] = $nasipaddress;
        $types .= 's';
    }
    
    if ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
        $from_dt = "$month-01 00:00:00";
        $to_dt   = date('Y-m-t 23:59:59', strtotime($from_dt));
        $where[] = "((acctstarttime >= ? AND acctstarttime <= ?) OR (acctstoptime >= ? AND acctstoptime <= ?) OR (acctstoptime IS NULL))";
        $params[] = $from_dt;
        $params[] = $to_dt;
        $params[] = $from_dt;
        $params[] = $to_dt;
        $types .= 'ssss';
    } elseif ($from && $to) {
        $where[] = 'DATE(acctstarttime) BETWEEN ? AND ?';
        $params[] = $from;
        $params[] = $to;
        $types .= 'ss';
    }
    
    $where_sql = 'WHERE ' . implode(' AND ', $where);
    
    db_execute("DELETE FROM radacct {$where_sql}", $types, $params);
    
    audit_log('hapus_pemakaian', "Menghapus log pemakaian untuk user: {$username}");
    
    flash_set('success', 'Data pemakaian berhasil dihapus.');
    
} catch (Throwable $e) {
    flash_set('error', 'Gagal menghapus data pemakaian: ' . $e->getMessage());
}

$url = '/index.php?page=report_usage';
if ($month) {
    $url .= '&month=' . urlencode($month);
} elseif ($from && $to) {
    $url .= '&from=' . urlencode($from) . '&to=' . urlencode($to);
}
header('Location: ' . $url);
