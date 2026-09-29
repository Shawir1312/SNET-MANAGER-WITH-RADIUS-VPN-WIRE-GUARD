<?php
/**
 * CRON — Bandwidth Snapshot (Tahan Restart MikroTik)
 * =====================================================
 * Dijalankan setiap 5 menit via crontab:
 *   *\/5 * * * * php /path/to/cron/cron_bandwidth_snapshot.php >> /var/log/snet_bw_snap.log 2>&1
 *
 * Cara kerja:
 *  1. Koneksi ke setiap router aktif
 *  2. Baca semua interface PPPoE yang sedang online
 *  3. Bandingkan counter tx-byte / rx-byte vs snapshot terakhir:
 *     - Jika counter NAIK  → delta = counter_baru - counter_lama  → tambah ke committed
 *     - Jika counter TURUN (restart/reconnect) → delta = counter_baru (dimulai dari 0)
 *  4. Simpan committed + counter terbaru ke tabel pppoe_bandwidth_snapshots
 *
 * Total pemakaian bulan berjalan =
 *   (snapshot committed) + (counter_live - last_if saat snapshot)
 * → TIDAK PERNAH reset ke 0 meski MikroTik restart berkali-kali.
 */

define('CLI_MODE', true);
define('IN_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../include/functions.php';
require_once __DIR__ . '/../lib/routeros_api.class.php';

// ── Lock agar tidak jalan dua kali bersamaan ──────────────────────────────────
$lockFile = sys_get_temp_dir() . '/snet_bw_snapshot.lock';
$lockFp   = fopen($lockFile, 'c+');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    echo bwTs() . " Instance sebelumnya masih berjalan. Dilewati.\n";
    exit(0);
}

$monthYear = date('Y-m');
echo bwTs() . " Mulai snapshot bandwidth bulan $monthYear\n";

// ── Ambil semua router ──────────────────────────────────────────────────────
$routers = db_fetch_all(
    "SELECT id, name, ip_address, api_user, api_password, api_port
     FROM routers
     WHERE status = 'active' OR status IS NULL OR status = ''"
);

if (empty($routers)) {
    echo bwTs() . " Tidak ada router aktif. Selesai.\n";
    flock($lockFp, LOCK_UN);
    exit(0);
}

foreach ($routers as $router) {
    $routerId   = (int)$router['id'];
    $routerIp   = $router['ip_address'];
    $routerName = $router['name'] ?? $routerIp;
    $apiPort    = !empty($router['api_port']) ? (int)$router['api_port'] : 8728;

    echo bwTs() . " [Router: $routerName] Menghubungkan...\n";

    $api            = new RouterosAPI();
    $api->debug     = false;
    $api->timeout   = 5;
    $api->attempts  = 1;
    $api->delay     = 0;

    if (!$api->connect($routerIp, $router['api_user'], $router['api_password'], $apiPort)) {
        echo bwTs() . " [Router: $routerName] Gagal koneksi API. Dilewati.\n";
        continue;
    }

    $ifaces = $api->comm('/interface/print', [
        '.proplist' => 'name,type,tx-byte,rx-byte,running'
    ]);

    if (!is_array($ifaces) || empty($ifaces)) {
        echo bwTs() . " [Router: $routerName] Tidak ada data interface.\n";
        $api->disconnect();
        continue;
    }

    $processed = 0;
    foreach ($ifaces as $iface) {
        $ifName  = trim($iface['name'] ?? '');

        // Hanya interface PPPoE
        if (!preg_match('/^<?(pppoe-.+?)>?$/i', $ifName)) {
            continue;
        }

        // Ekstrak username bersih dari nama interface
        $username = preg_replace('/^<?(pppoe-)(.+?)>?$/i', '$2', $ifName);
        $username = preg_replace('/@.*$/', '', $username);
        $username = strtolower(trim($username));
        if (empty($username)) continue;

        // Cari pelanggan di DB (cukup match username bersih)
        $custRow = db_fetch_one(
            "SELECT id, pppoe_username FROM pppoe_customers
             WHERE LOWER(REPLACE(pppoe_username,'@', '__AT__')) = LOWER(REPLACE(?, '@', '__AT__'))
                OR LOWER(pppoe_username) = ?
             LIMIT 1",
            'ss', [$username, $username]
        );
        if (!$custRow) continue;

        $dbUsername = $custRow['pppoe_username'];

        // Counter MikroTik saat ini
        $liveTx = (float)($iface['tx-byte'] ?? 0);
        $liveRx = (float)($iface['rx-byte'] ?? 0);

        // Ambil snapshot terakhir
        $snap = db_fetch_one(
            "SELECT id, committed_dl, committed_ul, last_if_tx, last_if_rx
             FROM pppoe_bandwidth_snapshots
             WHERE router_id = ? AND username = ? AND month_year = ?",
            'iss', [$routerId, $dbUsername, $monthYear]
        );

        if (!$snap) {
            // Insert baris baru — committed mulai dari 0, simpan counter saat ini
            db()->query(sprintf(
                "INSERT INTO pppoe_bandwidth_snapshots
                    (router_id, username, month_year, committed_dl, committed_ul, last_if_tx, last_if_rx)
                 VALUES (%d, '%s', '%s', 0, 0, %s, %s)
                 ON DUPLICATE KEY UPDATE last_if_tx=%s, last_if_rx=%s",
                $routerId,
                db()->real_escape_string($dbUsername),
                db()->real_escape_string($monthYear),
                (string)(int)$liveTx, (string)(int)$liveRx,
                (string)(int)$liveTx, (string)(int)$liveRx
            ));
            echo bwTs() . " [Router: $routerName] [$dbUsername] Snapshot baru. tx=" . bwFmt($liveTx) . " rx=" . bwFmt($liveRx) . "\n";
            $processed++;
            continue;
        }

        // ── Hitung delta ──
        $lastTx = (float)$snap['last_if_tx'];
        $lastRx = (float)$snap['last_if_rx'];

        // Deteksi reset: counter lebih kecil dari terakhir
        if ($liveTx >= $lastTx) {
            $deltaTx = $liveTx - $lastTx;
        } else {
            // Reset terjadi! Counter baru dimulai dari 0
            $deltaTx = $liveTx;
            echo bwTs() . " [Router: $routerName] [$dbUsername] *** RESET TERDETEKSI tx: " . bwFmt($lastTx) . " -> " . bwFmt($liveTx) . " ***\n";
        }
        if ($liveRx >= $lastRx) {
            $deltaRx = $liveRx - $lastRx;
        } else {
            $deltaRx = $liveRx;
            echo bwTs() . " [Router: $routerName] [$dbUsername] *** RESET TERDETEKSI rx: " . bwFmt($lastRx) . " -> " . bwFmt($liveRx) . " ***\n";
        }

        $newDl = (float)$snap['committed_dl'] + $deltaTx;
        $newUl = (float)$snap['committed_ul'] + $deltaRx;

        db()->query(sprintf(
            "UPDATE pppoe_bandwidth_snapshots
             SET committed_dl=%s, committed_ul=%s, last_if_tx=%s, last_if_rx=%s, updated_at=NOW()
             WHERE id=%d",
            (string)(int)$newDl,
            (string)(int)$newUl,
            (string)(int)$liveTx,
            (string)(int)$liveRx,
            (int)$snap['id']
        ));

        if ($deltaTx > 0 || $deltaRx > 0) {
            echo bwTs() . " [Router: $routerName] [$dbUsername] dl=" . bwFmt($newDl) . " ul=" . bwFmt($newUl) . " (+".bwFmt($deltaTx)."/+".bwFmt($deltaRx).")\n";
        }
        $processed++;
    }

    $api->disconnect();
    echo bwTs() . " [Router: $routerName] Selesai. $processed pelanggan diproses.\n";
}

echo bwTs() . " Semua router selesai.\n";
flock($lockFp, LOCK_UN);

// ── Helpers ──────────────────────────────────────────────────────────────────
function bwTs(): string {
    return '[' . date('Y-m-d H:i:s') . ']';
}
function bwFmt(float $b): string {
    if ($b >= 1073741824) return round($b / 1073741824, 2) . 'GB';
    if ($b >= 1048576)    return round($b / 1048576, 2)    . 'MB';
    if ($b >= 1024)       return round($b / 1024, 2)       . 'KB';
    return (int)$b . 'B';
}
