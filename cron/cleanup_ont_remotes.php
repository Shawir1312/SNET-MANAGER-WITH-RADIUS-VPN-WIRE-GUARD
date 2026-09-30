<?php
/**
 * S.NET RADIUS & VPN — Cron Cleanup Akses Remote Sementara ONT (15 Menit)
 * Jalankan via crontab setiap 1-5 menit:
 * * * * * /www/server/php/84/bin/php /www/wwwroot/dash.snetwifi.com/cron/cleanup_ont_remotes.php 2>&1 | tee -a /www/wwwroot/dash.snetwifi.com/logs/cleanup_ont_remotes.log
 */
require_once __DIR__ . '/cron_logger.php';
define('IN_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../include/functions.php';

$log       = cron_logger('cleanup_ont_remotes');
$startTime = microtime(true);

// Lock — hindari tumpukan proses jika cron sebelumnya belum selesai
$lockFp = fopen(sys_get_temp_dir() . '/snet_cron_ont_remote.lock', 'c+');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    $log('Instance sebelumnya masih berjalan. Dilewati.', 'SKIP');
    exit(0);
}
cron_start_banner($log, 'cleanup_ont_remotes');

try {
    $expired = db_fetch_all("SELECT * FROM ont_remotes WHERE is_active = 1 AND expires_at <= NOW()");
    $log('Ditemukan ' . count($expired) . ' sesi remote ONT yang telah kadaluarsa.');
    $count = 0;
    foreach ($expired as $r) {
        $port = (int)$r['public_port'];
        $ip   = $r['ont_ip'];
        $tp   = (int)$r['target_port'];

        if (function_exists('shell_exec')) {
            @shell_exec("sudo ufw delete allow {$port}/tcp 2>/dev/null");
            @shell_exec("sudo iptables -D INPUT -p tcp --dport {$port} -j ACCEPT 2>/dev/null");
            @shell_exec("sudo iptables -D FORWARD -p tcp -d " . escapeshellarg($ip) . " --dport {$tp} -j ACCEPT 2>/dev/null");
            @shell_exec("sudo iptables -t nat -D PREROUTING -p tcp --dport {$port} -j DNAT --to-destination " . escapeshellarg($ip) . ":{$tp} 2>/dev/null");
            @shell_exec("sudo iptables -t nat -D POSTROUTING -p tcp -d " . escapeshellarg($ip) . " --dport {$tp} -j MASQUERADE 2>/dev/null");
        }
        db_execute("UPDATE ont_remotes SET is_active = 0 WHERE id = ?", 'i', [$r['id']]);
        $log("Sesi remote ONT {$ip}:{$tp} (port {$port}) dinonaktifkan.");
        $count++;
    }

    if ($count === 0) {
        $log('Tidak ada sesi remote ONT yang perlu dibersihkan.');
    }

    cron_end_banner($log, 'cleanup_ont_remotes', $startTime, [
        'Sesi dinonaktifkan' => $count,
    ]);
} catch (Throwable $e) {
    $log('Error: ' . $e->getMessage(), 'ERROR');
    cron_end_banner($log, 'cleanup_ont_remotes', $startTime, ['Status' => 'ERROR']);
}
