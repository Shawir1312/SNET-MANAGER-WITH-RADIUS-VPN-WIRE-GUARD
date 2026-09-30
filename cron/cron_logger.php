<?php
/**
 * S.NET — Cron Logger Helper
 * Include di semua file cron agar log tersimpan ke file + tampil di stdout.
 * Otomatis rotasi file log jika ukurannya > 5MB.
 *
 * Penggunaan:
 *   require_once __DIR__ . '/cron_logger.php';
 *   $log = cron_logger('nama_cron');
 *   $log('Pesan log saya');
 */

if (!function_exists('cron_logger')) {
    /**
     * Buat closure logger untuk cron tertentu.
     *
     * @param string $name   Nama cron (dipakai sebagai nama file log)
     * @param int    $maxMb  Ukuran maksimum file log sebelum dirotasi (default 5 MB)
     * @return callable      $log(string $msg, string $level='INFO')
     */
    function cron_logger(string $name, int $maxMb = 5): callable
    {
        $logDir  = dirname(__DIR__) . '/logs';
        $logFile = $logDir . '/' . $name . '.log';
        $maxSize = $maxMb * 1024 * 1024;

        // Pastikan direktori logs ada
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        // Rotasi log jika sudah terlalu besar
        if (file_exists($logFile) && filesize($logFile) > $maxSize) {
            $rotated = $logFile . '.' . date('Ymd_His') . '.bak';
            @rename($logFile, $rotated);
            // Hapus rotasi lama (> 5 file backup)
            $backups = glob($logDir . '/' . $name . '.log.*.bak');
            if (is_array($backups) && count($backups) > 5) {
                usort($backups, fn($a, $b) => filemtime($a) - filemtime($b));
                $toDelete = array_slice($backups, 0, count($backups) - 5);
                foreach ($toDelete as $f) @unlink($f);
            }
        }

        return function (string $msg, string $level = 'INFO') use ($logFile, $name) {
            $ts   = date('Y-m-d H:i:s');
            $line = "[{$ts}] [{$level}] [{$name}] {$msg}";
            echo $line . PHP_EOL;
            @file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        };
    }
}

if (!function_exists('cron_start_banner')) {
    /**
     * Tampilkan dan log banner mulai cron + info PHP & hostname.
     */
    function cron_start_banner(callable $log, string $name): void
    {
        $log('========================================', 'INFO');
        $log("MULAI CRON: {$name}", 'INFO');
        $log('Host : ' . (gethostname() ?: 'unknown'), 'INFO');
        $log('PHP  : ' . PHP_VERSION, 'INFO');
        $log('PID  : ' . getmypid(), 'INFO');
        $log('========================================', 'INFO');
    }
}

if (!function_exists('cron_end_banner')) {
    /**
     * Tampilkan dan log banner selesai cron + durasi eksekusi.
     *
     * @param callable $log
     * @param string   $name
     * @param float    $startTime  Nilai dari microtime(true) di awal skrip
     * @param array    $summary    Key-value ringkasan hasil, misal ['Diproses'=>5, 'Gagal'=>0]
     */
    function cron_end_banner(callable $log, string $name, float $startTime, array $summary = []): void
    {
        $elapsed = round(microtime(true) - $startTime, 2);
        $log('----------------------------------------', 'INFO');
        $log("SELESAI CRON: {$name}", 'INFO');
        $log("Durasi : {$elapsed} detik", 'INFO');
        if (!empty($summary)) {
            foreach ($summary as $k => $v) {
                $log("{$k}: {$v}", 'INFO');
            }
        }
        $log('========================================', 'INFO');
        $log('', 'INFO'); // baris kosong pemisah
    }
}
