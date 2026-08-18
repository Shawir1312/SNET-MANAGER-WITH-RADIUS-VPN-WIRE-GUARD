<?php
/**
 * S.NET RADIUS & VPN — AJAX Handler untuk Akses Remote Sementara ONT (15 Menit)
 */
define('IN_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../include/functions.php';
require_once __DIR__ . '/../include/wireguard_functions.php';

header('Content-Type: application/json');
auth_check();

// Pastikan tabel ont_remotes tersedia
try {
    db_execute("CREATE TABLE IF NOT EXISTS ont_remotes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ont_sn VARCHAR(100) NOT NULL,
        ont_name VARCHAR(150) DEFAULT NULL,
        ont_ip VARCHAR(50) NOT NULL,
        target_port INT NOT NULL DEFAULT 80,
        public_port INT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NOT NULL,
        is_active TINYINT(1) DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

/**
 * Bersihkan remote port forward yang sudah kadaluarsa (otomatis terhapus setelah 15 menit)
 */
function cleanup_expired_ont_remotes(): void {
    try {
        $expired = db_fetch_all("SELECT * FROM ont_remotes WHERE is_active = 1 AND expires_at <= NOW()");
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
        }
    } catch (Throwable $e) {}
}

// Jalankan pembersihan berkala
cleanup_expired_ont_remotes();

$action = trim($_POST['action'] ?? ($_GET['action'] ?? 'status'));

// ── 1. START / BUKA AKSES REMOTE SEMENTARA (15 MENIT) ────────────────
if ($action === 'start') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit;
    }

    $sn   = trim($_POST['sn'] ?? '');
    $ip   = trim($_POST['ip'] ?? '');
    $name = trim($_POST['name'] ?? 'ONT Device');
    $tp   = (int)($_POST['target_port'] ?? 80);
    if ($tp <= 0) $tp = 80;

    if (!$sn || !$ip || $ip === '-' || !filter_var($ip, FILTER_VALIDATE_IP)) {
        echo json_encode(['success' => false, 'error' => 'IP Address ONT tidak valid atau belum terdeteksi.']);
        exit;
    }

    try {
        // Cek jika remote aktif untuk ONT ini sudah ada
        $active = db_fetch_one("SELECT * FROM ont_remotes WHERE ont_sn = ? AND is_active = 1 AND expires_at > NOW() ORDER BY id DESC LIMIT 1", 's', [$sn]);

        if ($active) {
            $pubPort = (int)$active['public_port'];
            $remSec  = max(0, strtotime($active['expires_at']) - time());
        } else {
            // Cari port publik 5 angka acak yang tersedia di rentang 20000 - 58000
            $usedPorts = [];
            $rows = db_fetch_all("SELECT public_port FROM ont_remotes WHERE is_active = 1 AND expires_at > NOW()");
            foreach ($rows as $r) $usedPorts[] = (int)$r['public_port'];
            $rowsPf = db_fetch_all("SELECT public_port FROM wg_port_forwards");
            foreach ($rowsPf as $r) $usedPorts[] = (int)$r['public_port'];

            // Generate port 5 digit acak yang belum digunakan
            do {
                $pubPort = mt_rand(20000, 58000);
            } while (in_array($pubPort, $usedPorts, true));

            // Durasi 15 menit
            $expiresAt = date('Y-m-d H:i:s', time() + 900);

            db_execute(
                "INSERT INTO ont_remotes (ont_sn, ont_name, ont_ip, target_port, public_port, expires_at, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)",
                'sssiss',
                [$sn, $name, $ip, $tp, $pubPort, $expiresAt]
            );

            // Aktifkan iptables NAT & UFW
            if (function_exists('shell_exec')) {
                @shell_exec("sudo ufw allow {$pubPort}/tcp 2>/dev/null");
                @shell_exec("sudo iptables -I INPUT -p tcp --dport {$pubPort} -j ACCEPT 2>/dev/null");
                @shell_exec("sudo iptables -I FORWARD -p tcp -d " . escapeshellarg($ip) . " --dport {$tp} -j ACCEPT 2>/dev/null");
                @shell_exec("sudo iptables -I FORWARD -i wg0 -j ACCEPT 2>/dev/null");
                @shell_exec("sudo iptables -I FORWARD -o wg0 -j ACCEPT 2>/dev/null");
                @shell_exec("sudo iptables -t nat -A PREROUTING -p tcp --dport {$pubPort} -j DNAT --to-destination " . escapeshellarg($ip) . ":{$tp} 2>/dev/null");
                @shell_exec("sudo iptables -t nat -A POSTROUTING -p tcp -d " . escapeshellarg($ip) . " --dport {$tp} -j MASQUERADE 2>/dev/null");
            }

            $remSec = 900;
        }

        // Tentukan host publik (domain atau IP server yang bisa diakses langsung)
        $vpsHost = get_remote_public_host();
        $remoteUrl = "http://{$vpsHost}:{$pubPort}";

        echo json_encode([
            'success'           => true,
            'sn'                => $sn,
            'name'              => $name,
            'ip'                => $ip,
            'public_port'       => $pubPort,
            'remote_url'        => $remoteUrl,
            'remaining_seconds' => $remSec,
            'expires_at'        => date('H:i:s', time() + $remSec),
            'message'           => "Akses Remote ONT aktif selama 15 menit pada port {$pubPort}!"
        ]);

    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => 'Gagal membuat sesi remote: ' . $e->getMessage()]);
    }
    exit;
}

// ── 2. CLOSE / TUTUP REMOTE MANUAL SEBELUM 15 MENIT ─────────────────
if ($action === 'close') {
    $sn = trim($_POST['sn'] ?? '');
    if (!$sn) {
        echo json_encode(['success' => false, 'error' => 'SN wajib diisi.']);
        exit;
    }

    try {
        $remotes = db_fetch_all("SELECT * FROM ont_remotes WHERE ont_sn = ? AND is_active = 1", 's', [$sn]);
        foreach ($remotes as $r) {
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
        }

        echo json_encode(['success' => true, 'message' => 'Akses remote ONT berhasil ditutup.']);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── 3. STATUS / CEK DAFTAR REMOTE AKTIF ──────────────────────────────
if ($action === 'status') {
    try {
        $active = db_fetch_all("SELECT *, (UNIX_TIMESTAMP(expires_at) - UNIX_TIMESTAMP(NOW())) as rem_sec FROM ont_remotes WHERE is_active = 1 AND expires_at > NOW()");
        $result = [];

        $vpsHost = get_remote_public_host();

        foreach ($active as $a) {
            $result[$a['ont_sn']] = [
                'id'                => $a['id'],
                'sn'                => $a['ont_sn'],
                'name'              => $a['ont_name'],
                'ip'                => $a['ont_ip'],
                'public_port'       => (int)$a['public_port'],
                'remote_url'        => "http://{$vpsHost}:{$a['public_port']}",
                'remaining_seconds' => max(0, (int)$a['rem_sec'])
            ];
        }

        echo json_encode(['success' => true, 'active_remotes' => $result]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Aksi tidak dikenal.']);
