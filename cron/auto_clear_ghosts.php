<?php
/**
 * CRON - Auto Clear Ghost Sessions via Mikrotik API
 * Dijalankan setiap menit untuk menyinkronkan sesi aktif di web panel 
 * dengan daftar aktif secara REAL-TIME di Mikrotik Winbox.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../include/functions.php';
require_once __DIR__ . '/../lib/routeros_api.class.php';

// Hindari tumpukan proses (process stampede) jika proses sebelumnya belum selesai
$lockFp = fopen(sys_get_temp_dir() . '/snet_cron_ghosts.lock', 'c+');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    echo "[" . date('Y-m-d H:i:s') . "] Instance auto_clear_ghosts sebelumnya masih berjalan. Dilewati.\n";
    exit(0);
}

$routers = db_fetch_all("SELECT id, name, ip_address, nas_ip, api_user, api_password, api_port FROM routers WHERE status = 'active'");

echo "[" . date('Y-m-d H:i:s') . "] Memulai sinkronisasi API pendeteksi sesi hantu...\n";

foreach ($routers as $router) {
    $ip = $router['ip_address'];
    $nas_ip = !empty($router['nas_ip']) && $router['nas_ip'] !== '0.0.0.0/0' ? $router['nas_ip'] : $ip;
    $api = new RouterosAPI();
    $api->timeout = 2; // Timeout sangat cepat
    $api->attempts = 1; // Hanya coba 1 kali, jangan diulang-ulang agar tidak macet
    $api->delay = 0;
    
    echo " -> Mengecek Router: {$router['name']} ($ip) ... ";
    
    // 1. Coba Konek API
    if ($api->connect($ip, $router['api_user'], $router['api_password'], (int)$router['api_port'])) {
        echo "TERHUBUNG.\n";
        
        $active_usernames = [];
        
        // Ambil dari Hotspot
        $hs_active = $api->comm('/ip/hotspot/active/print');
        if (is_array($hs_active)) {
            foreach ($hs_active as $hs) {
                if (!empty($hs['user'])) {
                    $active_usernames[] = $hs['user'];
                }
            }
        }
        
        // Ambil dari PPP (PPPoE) jika ada
        $ppp_active = $api->comm('/ppp/active/print');
        if (is_array($ppp_active)) {
            foreach ($ppp_active as $ppp) {
                if (!empty($ppp['name'])) {
                    $active_usernames[] = $ppp['name'];
                }
            }
        }
        
        $api->disconnect();
        
        // 2. Ambil sesi yang dianggap aktif oleh RADIUS untuk router ini
        $radius_active = db_fetch_all("
            SELECT radacctid, username, acctstarttime, acctsessiontime 
            FROM radacct 
            WHERE acctstoptime IS NULL 
              AND (nasipaddress = ? OR nasipaddress = ?)
        ", 'ss', [$ip, $nas_ip]);
        
        $closed_count = 0;
        
        foreach ($radius_active as $ra) {
            $u = $ra['username'];
            
            // 3. KOMPARASI: Jika di RADIUS dibilang aktif, tapi di API tidak ada, berarti HANTU!
            if (!in_array($u, $active_usernames)) {
                
                // Cek apakah ini False Start (0 byte/0 sec)
                if ((int)$ra['acctsessiontime'] === 0) {
                    $check_zero = db_fetch_one("SELECT acctinputoctets FROM radacct WHERE radacctid = ?", 'i', [$ra['radacctid']]);
                    if ($check_zero && (int)$check_zero['acctinputoctets'] === 0) {
                        // Hapus dan refund voucher
                        db_execute("DELETE FROM radacct WHERE radacctid = ?", 'i', [$ra['radacctid']]);
                        $has_other = db_fetch_one("SELECT radacctid FROM radacct WHERE username = ?", 's', [$u]);
                        if (!$has_other) {
                            db_execute("UPDATE vouchers SET status = 'unused', used_at = NULL, expired_at = NULL WHERE username = ? AND status = 'active'", 's', [$u]);
                            db_execute("DELETE FROM sales_log WHERE voucher_username = ? ORDER BY id DESC LIMIT 1", 's', [$u]);
                        }
                        $closed_count++;
                        continue;
                    }
                }
                
                // Jika sudah ada traffic tapi mati di tengah jalan
                db_execute("
                    UPDATE radacct 
                    SET acctstoptime = CASE 
                            WHEN acctsessiontime > 0 THEN DATE_ADD(acctstarttime, INTERVAL acctsessiontime SECOND)
                            ELSE acctstarttime 
                        END,
                        acctterminatecause = 'NAS-Error-API-Sync'
                    WHERE radacctid = ?
                ", 'i', [$ra['radacctid']]);
                
                $closed_count++;
            }
        }
        
        if ($closed_count > 0) {
            echo "    [+] Menutup paksa $closed_count sesi hantu yang tidak ada di memori Mikrotik.\n";
        }
        
    } else {
        echo "GAGAL (Koneksi API bermasalah / Offline / RTO).\n";
        echo "    [!] Melewati router ini. Jika ini RTO, data aman. Jika mati lampu, sesi hantu akan dibersihkan otomatis tanpa memotong sisa waktu saat router menyala kembali.\n";
    }
}
echo "[" . date('Y-m-d H:i:s') . "] Sinkronisasi API selesai.\n";
