<?php
/**
 * S.NET RADIUS & VPN — Cron Cleanup Akses Remote Sementara ONT (15 Menit)
 * Jalankan via crontab setiap 1-5 menit:
 * * * * * * php /www/wwwroot/s.shawir.id/cron/cleanup_ont_remotes.php >/dev/null 2>&1
 */
define('IN_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../include/functions.php';

try {
    $expired = db_fetch_all("SELECT * FROM ont_remotes WHERE is_active = 1 AND expires_at <= NOW()");
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
        $count++;
    }

    if ($count > 0) {
        echo date('Y-m-d H:i:s') . " - Berhasil membersihkan {$count} sesi remote ONT yang telah melewati batas 15 menit.\n";
    }
} catch (Throwable $e) {
    echo date('Y-m-d H:i:s') . " - Error: " . $e->getMessage() . "\n";
}
