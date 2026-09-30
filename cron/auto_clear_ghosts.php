<?php
/**
 * CRON - Auto Clear Ghost Sessions via Mikrotik API
 * Dijalankan setiap menit untuk menyinkronkan sesi aktif di web panel 
 * dengan daftar aktif secara REAL-TIME di Mikrotik Winbox.
 */
require_once __DIR__ . '/cron_logger.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../include/functions.php';
require_once __DIR__ . '/../lib/routeros_api.class.php';

$log       = cron_logger('auto_clear_ghosts');
$startTime = microtime(true);

// Hindari tumpukan proses (process stampede) jika proses sebelumnya belum selesai
$lockFp = fopen(sys_get_temp_dir() . '/snet_cron_ghosts.lock', 'c+');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    $log('Instance sebelumnya masih berjalan. Dilewati.', 'SKIP');
    exit(0);
}
cron_start_banner($log, 'auto_clear_ghosts');

$routers = db_fetch_all("SELECT id, name, ip_address, nas_ip, api_user, api_password, api_port FROM routers WHERE status = 'active'");

$log('Total router aktif: ' . count($routers));
$totalClosed   = 0;
$totalRestored = 0;
$totalRouters  = 0;

foreach ($routers as $router) {
    $ip = $router['ip_address'];
    $nas_ip = !empty($router['nas_ip']) && $router['nas_ip'] !== '0.0.0.0/0' ? $router['nas_ip'] : $ip;
    
    $log("Mengecek & Sinkronisasi Router: {$router['name']} ($ip) ...");
    
    $sync = sync_router_hotspot_active($router);
    
    if ($sync['online']) {
        $log("Router {$router['name']} ($ip) TERHUBUNG. User aktif MikroTik: {$sync['active_count']}. Dipulihkan: {$sync['restored']}, Dibuat: {$sync['created']}, Ghost Ditutup: {$sync['closed']}.");
        $totalClosed   += $sync['closed'];
        $totalRestored += ($sync['restored'] + $sync['created']);
        $totalRouters++;
    } else {
        $log("Router {$router['name']} ($ip) GAGAL koneksi API (Offline/RTO).", 'WARN');
        
        // Toleransi Gangguan Sesaat (Grace Period 3 Menit / 180 Detik):
        $lastSeenTime = !empty($router['last_seen']) ? strtotime($router['last_seen']) : 0;
        $secondsSinceLastSeen = $lastSeenTime > 0 ? (time() - $lastSeenTime) : 999999;

        if ($lastSeenTime === 0) {
            $latestAcct = db_fetch_one(
                "SELECT MAX(COALESCE(acctupdatetime, acctstarttime)) as latest FROM radacct WHERE (nasipaddress = ? OR nasipaddress = ?) AND acctstoptime IS NULL",
                'ss', [$ip, $nas_ip]
            );
            if (!empty($latestAcct['latest'])) {
                $secondsSinceLastSeen = time() - strtotime($latestAcct['latest']);
            }
        }

        if ($secondsSinceLastSeen < 180) {
            $log("Router {$router['name']} ($ip) RTO/tidak merespon API, tapi baru terlihat online {$secondsSinceLastSeen}s lalu. Sesi dipertahankan (Grace Period 3 menit).", 'INFO');
            continue;
        }

        // Router terkonfirmasi mati > 3 menit: bersihkan sesi menggantung (ghost sessions) di router ini
        $offline_sessions = db_fetch_all("
            SELECT radacctid, username, acctstarttime, acctupdatetime, acctsessiontime 
            FROM radacct 
            WHERE acctstoptime IS NULL 
              AND (nasipaddress = ? OR nasipaddress = ?)
        ", 'ss', [$ip, $nas_ip]);
        
        $offline_count = count($offline_sessions);
        if ($offline_count > 0) {
            $log("Menutup paksa {$offline_count} sesi hantu di router {$router['name']} karena router OFFLINE > 3 menit.", 'INFO');
            db_execute("
                UPDATE radacct 
                SET acctstoptime = CASE 
                        WHEN acctupdatetime IS NOT NULL THEN acctupdatetime
                        WHEN acctsessiontime > 0 THEN DATE_ADD(acctstarttime, INTERVAL acctsessiontime SECOND)
                        ELSE NOW() 
                    END,
                    acctterminatecause = 'Router-Offline'
                WHERE acctstoptime IS NULL 
                  AND (nasipaddress = ? OR nasipaddress = ?)
            ", 'ss', [$ip, $nas_ip]);
            $totalClosed += $offline_count;
        }
    }
}
cron_end_banner($log, 'auto_clear_ghosts', $startTime, [
    'Router diproses' => $totalRouters,
    'Sesi aktif dipulihkan/dibuat' => $totalRestored,
    'Total sesi hantu ditutup' => $totalClosed,
]);

