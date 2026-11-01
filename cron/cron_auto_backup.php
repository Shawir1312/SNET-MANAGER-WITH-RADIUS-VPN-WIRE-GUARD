<?php
/**
 * S.NET RADIUS Manager — Cron Backup Database Otomatis
 * Dijalankan harian via crontab (misal setiap jam 02:00 dini hari):
 * 0 2 * * * php /path/to/cron/cron_auto_backup.php >/dev/null 2>&1
 */

define('CLI_MODE', true);
define('IN_APP', true);

require_once __DIR__ . '/cron_logger.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../include/redundant_functions.php';

$log = cron_logger('cron_auto_backup');
$startTime = microtime(true);

// Node guard: hanya dijalankan di Master VPS
if (function_exists('is_redundant_node') && is_redundant_node()) {
    $log('Server ini adalah Node Cabang Lokal. Backup database pusat hanya dijalankan di Master VPS.', 'INFO');
    exit(0);
}

@set_time_limit(600);
@ini_set('memory_limit', '512M');

cron_start_banner($log, 'cron_auto_backup');

$backupDir = dirname(__DIR__) . '/backups';
if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0755, true);
}

$dateStr = date('Ymd_His');
$filename = "snet_auto_backup_{$dateStr}.sql.gz";
$filePath = $backupDir . '/' . $filename;

$log("Memulai backup otomatis database ke: {$filename} ...");

try {
    $db = db();
    $tables = [];
    $res = $db->query("SHOW TABLES");
    while ($r = $res->fetch_row()) {
        $tables[] = $r[0];
    }

    $gz = gzopen($filePath, 'w9');
    if (!$gz) {
        throw new Exception("Gagal membuat file backup di direktori {$backupDir}");
    }

    gzwrite($gz, "-- S.NET RADIUS Manager Auto Backup\n");
    gzwrite($gz, "-- Tanggal: " . date('Y-m-d H:i:s') . "\n");
    gzwrite($gz, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

    $totalRows = 0;
    foreach ($tables as $table) {
        gzwrite($gz, "DROP TABLE IF EXISTS `{$table}`;\n");
        $cr = $db->query("SHOW CREATE TABLE `{$table}`");
        if ($cr && $row = $cr->fetch_row()) {
            gzwrite($gz, $row[1] . ";\n\n");
        }

        $resRows = $db->query("SELECT * FROM `{$table}`");
        if (!$resRows || $resRows->num_rows === 0) {
            continue;
        }

        $cols = [];
        $fields = $resRows->fetch_fields();
        foreach ($fields as $f) {
            $cols[] = '`' . $f->name . '`';
        }
        $colStr = implode(',', $cols);

        while ($r = $resRows->fetch_assoc()) {
            $vals = array_map(function($v) use ($db) {
                return $v === null ? 'NULL' : "'" . $db->real_escape_string((string)$v) . "'";
            }, array_values($r));
            gzwrite($gz, "INSERT INTO `{$table}` ({$colStr}) VALUES (" . implode(',', $vals) . ");\n");
            $totalRows++;
        }
        gzwrite($gz, "\n");
    }

    gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
    gzclose($gz);

    $fileSize = round(filesize($filePath) / 1024 / 1024, 2);
    $log("Backup BERHASIL! File: {$filename} ({$fileSize} MB, {$totalRows} baris data).", 'INFO');

    // Rotasi: Hapus backup otomatis lama yang lebih dari 7 hari
    $files = glob($backupDir . '/snet_auto_backup_*.sql.gz');
    if ($files && count($files) > 7) {
        usort($files, fn($a, $b) => filemtime($a) - filemtime($b));
        $toDelete = array_slice($files, 0, count($files) - 7);
        foreach ($toDelete as $df) {
            @unlink($df);
            $log("Menghapus backup lama: " . basename($df));
        }
    }

    cron_end_banner($log, 'cron_auto_backup', $startTime, ['Status' => 'OK', 'Ukuran' => "{$fileSize} MB"]);

} catch (Throwable $e) {
    $log("GAGAL membuat backup: " . $e->getMessage(), 'ERROR');
    if (file_exists($filePath)) @unlink($filePath);
    exit(1);
}
