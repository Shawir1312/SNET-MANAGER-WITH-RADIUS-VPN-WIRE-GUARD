<?php
/**
 * S.NET RADIUS & VPN — Diagnostic & Auto-Fix Tool for Router NAS / RADIUS Connection
 * 
 * Penggunaan di Terminal VPS:
 * sudo php /www/wwwroot/portal.snetwifi.com/scripts/debug_radius_router.php
 * sudo php /www/wwwroot/portal.snetwifi.com/scripts/debug_radius_router.php --fix
 */

define('IN_APP', true);
define('CLI_MODE', true);
define('BASE_PATH', dirname(__DIR__));

if (php_sapi_name() !== 'cli') {
    die("Script ini hanya dapat dijalankan melalui CLI (terminal).\n");
}

require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/include/functions.php';
require_once BASE_PATH . '/include/wireguard_functions.php';

$isFix = in_array('--fix', $argv, true);

echo "====================================================================\n";
echo "   S.NET RADIUS Manager — Diagnostik & Perbaikan RADIUS Client NAS\n";
echo "====================================================================\n\n";

// 1. Ambil data WireGuard Peers
echo "[1] Memeriksa Data Router di WireGuard Hub (wg_routers)...\n";
$wgRouters = db_fetch_all("SELECT id, name, tunnel_ip, public_key, lan_subnets FROM wg_routers ORDER BY id ASC");
if (empty($wgRouters)) {
    echo "    [!] Tidak ada data di tabel wg_routers.\n";
} else {
    foreach ($wgRouters as $wgr) {
        echo "    • ID: {$wgr['id']} | Nama: {$wgr['name']} | IP Tunnel: {$wgr['tunnel_ip']} | LAN: " . ($wgr['lan_subnets'] ?: '-') . "\n";
    }
}
echo "\n";

// 2. Ambil data RADIUS Routers
echo "[2] Memeriksa Data Router di Manajemen RADIUS (routers)...\n";
$routers = db_fetch_all("SELECT id, name, ip_address, nas_ip, radius_secret, status, api_user, api_port FROM routers ORDER BY id ASC");
if (empty($routers)) {
    echo "    [!] Tidak ada data di tabel routers.\n";
} else {
    foreach ($routers as $r) {
        $st = $r['status'] ?: 'active';
        echo "    • ID: {$r['id']} | Nama: {$r['name']} | IP (API): {$r['ip_address']} | NAS IP: {$r['nas_ip']} | Secret: {$r['radius_secret']} | Status: {$st}\n";
    }
}
echo "\n";

// 3. Ambil data FreeRADIUS Client (tabel nas)
echo "[3] Memeriksa Tabel Klien FreeRADIUS (tabel nas)...\n";
$nasList = db_fetch_all("SELECT id, nasname, shortname, secret, description FROM nas ORDER BY id ASC");
if (empty($nasList)) {
    echo "    [!] Tabel nas kosong! FreeRADIUS tidak akan mengenali router mana pun!\n";
} else {
    foreach ($nasList as $nas) {
        echo "    • ID: {$nas['id']} | NAS Name (IP): {$nas['nasname']} | Shortname: {$nas['shortname']} | Secret: {$nas['secret']}\n";
    }
}
echo "\n";

// 4. Deteksi Masalah Khusus Router (PANGEO / LUSUO dll)
echo "[4] Analisis Kecocokan IP & Secret:\n";
$issues = [];

// Periksa apakah setiap IP di wg_routers sudah ada di tabel nas
foreach ($wgRouters as $wgr) {
    $wgIp = trim($wgr['tunnel_ip'] ?? '');
    if (!$wgIp || $wgIp === '10.77.77.1') continue;

    // Cek apakah ada di tabel routers
    $matchRouter = null;
    foreach ($routers as $r) {
        if ($r['ip_address'] === $wgIp || $r['nas_ip'] === $wgIp || stripos($r['name'], $wgr['name']) !== false || stripos($wgr['name'], $r['name']) !== false) {
            $matchRouter = $r;
            break;
        }
    }

    // Cek apakah ada di tabel nas
    $matchNas = null;
    foreach ($nasList as $n) {
        if ($n['nasname'] === $wgIp) {
            $matchNas = $n;
            break;
        }
    }

    if (!$matchRouter) {
        $issues[] = [
            'type' => 'missing_in_routers',
            'msg'  => "Router '{$wgr['name']}' ({$wgIp}) ada di WireGuard tapi TIDAK DITEMUKAN di menu Router / NAS (tabel routers).",
            'wg'   => $wgr
        ];
        echo "    [X] Router '{$wgr['name']}' ({$wgIp}) belum terdaftar di tabel routers!\n";
    } else {
        if ($matchRouter['ip_address'] !== $wgIp && $matchRouter['nas_ip'] !== $wgIp) {
            $issues[] = [
                'type' => 'ip_mismatch',
                'msg'  => "Router '{$matchRouter['name']}' memiliki IP lama ({$matchRouter['ip_address']} / NAS: {$matchRouter['nas_ip']}), seharusnya: {$wgIp}",
                'router' => $matchRouter,
                'correct_ip' => $wgIp
            ];
            echo "    [X] Router '{$matchRouter['name']}': IP di database ({$matchRouter['ip_address']}) tidak cocok dengan IP WireGuard ({$wgIp})!\n";
        }

        if (!$matchNas) {
            $issues[] = [
                'type' => 'missing_in_nas',
                'msg'  => "IP {$wgIp} ('{$matchRouter['name']}') belum masuk ke tabel 'nas' FreeRADIUS.",
                'router' => $matchRouter,
                'correct_ip' => $wgIp
            ];
            echo "    [X] IP {$wgIp} ('{$matchRouter['name']}') TIDAK DITEMUKAN di tabel nas FreeRADIUS!\n";
        } else {
            if ($matchNas['secret'] !== $matchRouter['radius_secret']) {
                $issues[] = [
                    'type' => 'secret_mismatch',
                    'msg'  => "Secret di tabel nas ('{$matchNas['secret']}') beda dengan tabel routers ('{$matchRouter['radius_secret']}').",
                    'nas'  => $matchNas,
                    'router' => $matchRouter
                ];
                echo "    [X] Secret tidak sama antara tabel nas dan routers untuk {$wgIp}!\n";
            } else {
                echo "    [✓] Router '{$wgr['name']}' ({$wgIp}) cocok di tabel routers dan nas.\n";
            }
        }
    }
}

// 5. Cek Log Error FreeRADIUS
echo "\n[5] Memeriksa 15 baris terakhir log FreeRADIUS...\n";
if (function_exists('shell_exec')) {
    $logOut = trim((string)shell_exec('tail -n 25 /var/log/freeradius/radius.log 2>/dev/null || journalctl -u freeradius -n 25 --no-pager 2>/dev/null'));
    if ($logOut) {
        $lines = explode("\n", $logOut);
        $recent = array_slice($lines, -15);
        foreach ($recent as $l) {
            echo "    " . $l . "\n";
        }
    } else {
        echo "    (Log kosong atau tidak dapat diakses)\n";
    }
}

// 6. Eksekusi Auto-Fix jika diminta
echo "\n====================================================================\n";
if (empty($issues)) {
    echo " ✓ HASIL: Semua data router dan tabel NAS FreeRADIUS sudah sinkron!\n";
    echo "====================================================================\n";
    echo "\nSaran Pengaturan di MikroTik Winbox (S.NET PANGEO):\n";
    echo "1. Buka menu RADIUS -> Server <10.77.77.1>\n";
    echo "2. Pada kolom 'Src. Address', ubah dari '0.0.0.0' menjadi: 10.77.77.4\n";
    echo "3. Pastikan kolom 'Secret' sama persis dengan yang ada di web portal.\n";
    echo "4. Klik Apply dan OK.\n";
    echo "5. Pastikan rule firewall input accept WireGuard sudah dipasang di MikroTik PANGEO:\n";
    echo "   /ip firewall filter add chain=input in-interface=wg-snet action=accept place-before=0 comment=\"Allow SNET WireGuard Inbound Input\"\n";
} else {
    echo " [!] Ditemukan " . count($issues) . " masalah sinkronisasi RADIUS!\n";
    if (!$isFix) {
        echo " Jalankan perintah berikut untuk memperbaikinya secara otomatis:\n";
        echo " sudo php " . __FILE__ . " --fix\n";
        echo "====================================================================\n";
    } else {
        echo " Melakukan perbaikan otomatis sekarang...\n";
        echo "--------------------------------------------------------------------\n";

        foreach ($issues as $iss) {
            if ($iss['type'] === 'ip_mismatch') {
                $r = $iss['router'];
                $newIp = $iss['correct_ip'];
                db_execute("UPDATE routers SET ip_address = ?, nas_ip = ? WHERE id = ?", 'ssi', [$newIp, $newIp, (int)$r['id']]);
                echo "  ✓ Memperbarui IP router '{$r['name']}' menjadi {$newIp}\n";
            } elseif ($iss['type'] === 'missing_in_routers') {
                $wg = $iss['wg'];
                $defaultSecret = 's1312'; // Default secret dari screenshot user
                db_execute("INSERT INTO routers (name, ip_address, nas_ip, radius_secret, status) VALUES (?, ?, ?, ?, 'active')",
                    'ssss', [$wg['name'], $wg['tunnel_ip'], $wg['tunnel_ip'], $defaultSecret]);
                echo "  ✓ Menambahkan router '{$wg['name']}' ({$wg['tunnel_ip']}) ke tabel routers\n";
            }
        }

        // Jalankan sinkronisasi ulang tabel NAS dari routers
        echo "  ✓ Memperbarui tabel 'nas' FreeRADIUS...\n";
        $allR = db_fetch_all("SELECT * FROM routers WHERE (status != 'inactive' OR status IS NULL) AND radius_secret != ''");
        foreach ($allR as $ar) {
            $rIp = trim($ar['ip_address'] ?? '');
            $nIp = trim($ar['nas_ip'] ?? '') ?: $rIp;
            $sec = trim($ar['radius_secret'] ?? '');
            $nm  = trim($ar['name'] ?? 'MikroTik');

            if ($rIp) {
                // Hapus entry lama jika ada
                db_execute("DELETE FROM nas WHERE nasname = ? OR nasname = ?", 'ss', [$rIp, $nIp]);
                // Masukkan NAS IP
                db_execute("INSERT INTO nas (nasname, shortname, type, secret, description) VALUES (?, ?, 'other', ?, ?)",
                    'ssss', [$nIp, $nm, $sec, $nm]);
                $nasId = db_last_id();
                db_execute("UPDATE routers SET nas_id = ? WHERE id = ?", 'ii', [$nasId, (int)$ar['id']]);

                if ($rIp !== $nIp) {
                    db_execute("INSERT INTO nas (nasname, shortname, type, secret, description) VALUES (?, ?, 'other', ?, ?)",
                        'ssss', [$rIp, $nm . '_ip', $sec, $nm]);
                }
            }
        }

        // Tambahkan juga subnet /24 tunnel agar kebal jika MikroTik mengirim dari IP lain
        $settings = get_all_wg_settings();
        $prefix = $settings['wg_subnet_prefix'] ?? '10.77.77.';
        $tunnelSubnet = rtrim($prefix, '.') . '.0/24';
        $existSubnet = db_fetch_one("SELECT id FROM nas WHERE nasname = ?", 's', [$tunnelSubnet]);
        if (!$existSubnet) {
            // Gunakan secret umum router pertama jika ada
            $firstSecret = $allR[0]['radius_secret'] ?? 's1312';
            db_execute("INSERT INTO nas (nasname, shortname, type, secret, description) VALUES (?, 'wg_tunnel_subnet', 'other', ?, 'Seluruh Subnet Tunnel VPN')",
                'ss', [$tunnelSubnet, $firstSecret]);
            echo "  ✓ Mendaftarkan subnet tunnel {$tunnelSubnet} ke FreeRADIUS NAS\n";
        }

        // Restart FreeRADIUS service
        echo "  ✓ Merestart service FreeRADIUS...\n";
        if (function_exists('restart_freeradius_service')) {
            restart_freeradius_service();
        } elseif (function_exists('shell_exec')) {
            shell_exec('sudo systemctl restart freeradius 2>/dev/null || systemctl restart freeradius 2>/dev/null');
        }

        echo "\n====================================================================\n";
        echo " ✓ PERBAIKAN SELESAI! Service FreeRADIUS telah diperbarui & direstart.\n";
        echo "====================================================================\n";
    }
}

