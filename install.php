<?php
/**
 * S.NET RADIUS Manager — Installation Wizard
 * Run this once to set up database tables and first admin.
 * After completion, config/.installed is created to prevent re-running.
 */

define('BASE_PATH', __DIR__);
define('CONFIG_PATH', __DIR__ . '/config');
define('APP_NAME', 'S.NET RADIUS Manager');
define('APP_COMPANY', 'PT Network Inovation Solutions');
define('APP_TIMEZONE', 'Asia/Jayapura');
date_default_timezone_set(APP_TIMEZONE);

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
ob_start();

if (!is_dir(CONFIG_PATH)) {
    @mkdir(CONFIG_PATH, 0775, true);
}

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$step   = (int)($_GET['step'] ?? ($_POST['step'] ?? 1));

if (file_exists(CONFIG_PATH . '/.installed')) {
    // Jangan blokir jika sedang panggil API service atau sedang di layar penyelesaian (step 6 / 7)
    if (!in_array($action, ['service_status', 'install_bg_service', 'get_bg_log'], true) && ($step !== 7 && $step !== 6)) {
        die('<div style="font-family:sans-serif;text-align:center;padding:60px;color:#1565C0;">
            <h2>✓ Sudah Terinstal</h2>
            <p>Aplikasi sudah diinstal. <a href="/login.php">Login di sini</a></p>
        </div>');
    }
}

if (!is_writable(CONFIG_PATH)) {
    die('<div style="font-family:sans-serif;text-align:center;padding:60px;color:#D32F2F;">
        <h2><span style="font-size:3rem;">🔒</span><br> Akses Ditolak (Permission Denied)</h2>
        <p>Folder <strong>config/</strong> tidak dapat ditulisi oleh sistem. Ini menyebabkan instalasi gagal menyimpan pengaturan.</p>
        <p style="background:#f8d7da;padding:15px;border-radius:8px;display:inline-block;text-align:left;font-family:monospace;font-size:0.9rem;">
        <strong>Cara mengatasi via SSH / Terminal:</strong><br><br>
        chown -R www:www ' . BASE_PATH . '<br>
        chmod -R 775 ' . CONFIG_PATH . '<br>
        </p>
        <p>Setelah menjalankan perintah di atas, silakan <em>refresh</em> halaman ini.</p>
    </div>');
}

// Helper: Periksa apakah shell_exec dapat dijalankan tanpa terblokir php.ini
function can_run_shell_exec(): bool {
    if (!function_exists('shell_exec')) return false;
    $disabled = (string)ini_get('disable_functions');
    if ($disabled !== '') {
        $list = array_map('trim', explode(',', $disabled));
        if (in_array('shell_exec', $list, true)) return false;
    }
    return true;
}

function get_freeradius_status(): array {
    $has_bin = false;
    $has_sql = false;
    $has_enabled = false;
    $is_active = false;

    if (can_run_shell_exec()) {
        try {
            $out = @shell_exec('which freeradius 2>/dev/null');
            if (!empty(trim((string)$out))) $has_bin = true;
            
            $sql_avail = @shell_exec('test -f /etc/freeradius/3.0/mods-available/sql && echo "yes" 2>/dev/null');
            if (trim((string)$sql_avail) === 'yes') $has_sql = true;
            
            $sql_en = @shell_exec('test -L /etc/freeradius/3.0/mods-enabled/sql && echo "yes" 2>/dev/null');
            if (trim((string)$sql_en) === 'yes') $has_enabled = true;

            $res = @shell_exec('systemctl is-active freeradius 2>/dev/null');
            if (trim((string)$res) === 'active') $is_active = true;
        } catch (\Throwable $e) {}
    }

    // Fallback deteksi filesystem & proc tanpa shell_exec
    if (@file_exists('/usr/sbin/freeradius') || @file_exists('/usr/bin/freeradius') || @is_dir('/etc/freeradius/3.0') || @is_dir('/etc/raddb')) {
        $has_bin = true;
    }
    if (@file_exists('/etc/freeradius/3.0/mods-available/sql') || @file_exists('/etc/raddb/mods-available/sql')) {
        $has_sql = true;
    }
    if (@file_exists('/etc/freeradius/3.0/mods-enabled/sql') || @file_exists('/etc/raddb/mods-enabled/sql')) {
        $has_enabled = true;
    }

    // Deteksi socket port UDP 1812 (0714) / 1813 (0715) di /proc/net/udp
    if (!$is_active) {
        foreach (['/proc/net/udp', '/proc/net/udp6'] as $udp) {
            if (@file_exists($udp)) {
                $content = @file_get_contents($udp);
                if ($content !== false && (strpos($content, ':0714') !== false || strpos($content, ':0715') !== false)) {
                    $is_active = true;
                    $has_bin = true;
                    break;
                }
            }
        }
    }

    // Deteksi PID file freeradius
    if (!$is_active) {
        foreach (['/run/freeradius/freeradius.pid', '/var/run/freeradius/freeradius.pid', '/run/radiusd/radiusd.pid'] as $pidF) {
            if (@file_exists($pidF)) {
                $pid = trim((string)@file_get_contents($pidF));
                if (is_numeric($pid) && (int)$pid > 0 && @file_exists("/proc/{$pid}")) {
                    $is_active = true;
                    $has_bin = true;
                    break;
                }
            }
        }
    }

    return [
        'installed'   => $has_bin,
        'sql_module'  => $has_sql,
        'sql_enabled' => $has_enabled,
        'running'     => $is_active
    ];
}

function get_wireguard_status(): array {
    $has_bin = false;
    $has_conf = false;
    $is_active = false;
    $has_keys = false;

    if (can_run_shell_exec()) {
        try {
            $out = @shell_exec('which wg 2>/dev/null');
            if (!empty(trim((string)$out))) $has_bin = true;

            $conf = @shell_exec('test -f /etc/wireguard/wg0.conf && echo "yes" 2>/dev/null');
            if (trim((string)$conf) === 'yes') $has_conf = true;

            $res = @shell_exec('systemctl is-active wg-quick@wg0 2>/dev/null');
            if (trim((string)$res) === 'active') $is_active = true;

            $keys = @shell_exec('test -f /etc/wireguard/server_public.key && echo "yes" 2>/dev/null');
            if (trim((string)$keys) === 'yes') $has_keys = true;
        } catch (\Throwable $e) {}
    }

    // Fallback deteksi filesystem tanpa shell_exec
    if (@file_exists('/usr/bin/wg') || @file_exists('/usr/sbin/wg') || @is_dir('/etc/wireguard')) {
        $has_bin = true;
    }
    if (@file_exists('/etc/wireguard/wg0.conf')) {
        $has_conf = true;
    }
    if (@file_exists('/etc/wireguard/server_public.key')) {
        $has_keys = true;
    }

    // Deteksi Linux network interface wg0 di /sys/class/net/wg0
    if (!$is_active) {
        if (@file_exists('/sys/class/net/wg0') || @is_dir('/sys/class/net/wg0') || @file_exists('/sys/devices/virtual/net/wg0')) {
            $is_active = true;
            $has_bin = true;
        }
    }

    // Deteksi socket port UDP 51820 (hex: CA6C) di /proc/net/udp
    if (!$is_active) {
        foreach (['/proc/net/udp', '/proc/net/udp6'] as $udp) {
            if (@file_exists($udp)) {
                $content = @file_get_contents($udp);
                if ($content !== false && stripos($content, ':CA6C') !== false) {
                    $is_active = true;
                    $has_bin = true;
                    break;
                }
            }
        }
    }

    return [
        'installed'  => $has_bin,
        'configured' => $has_conf,
        'keys'       => $has_keys,
        'running'    => $is_active
    ];
}

function get_whatsapp_status(): array {
    $has_node    = false;
    $has_modules = false;
    $has_service = false;
    $is_active   = false;
    $port_open   = false;

    if (can_run_shell_exec()) {
        try {
            $out = @shell_exec('which node 2>/dev/null');
            if (!empty(trim((string)$out))) $has_node = true;

            $svc = @shell_exec('test -f /etc/systemd/system/snet-wa.service && echo "yes" 2>/dev/null');
            if (trim((string)$svc) === 'yes') $has_service = true;

            $res = @shell_exec('systemctl is-active snet-wa 2>/dev/null');
            if (trim((string)$res) === 'active') $is_active = true;
        } catch (\Throwable $e) {}
    }

    if (@file_exists('/usr/bin/node') || @file_exists('/usr/local/bin/node')) {
        $has_node = true;
    }
    if (@file_exists('/etc/systemd/system/snet-wa.service')) {
        $has_service = true;
    }

    if (is_dir(BASE_PATH . '/wa-service/node_modules')) {
        $has_modules = true;
    }

    // Cek responsivitas port 3000
    $fp = @fsockopen('127.0.0.1', 3000, $errno, $errstr, 0.4);
    if ($fp) {
        $port_open = true;
        $is_active = true;
        fclose($fp);
    }

    return [
        'installed'   => ($has_node && $has_modules),
        'has_node'    => $has_node,
        'has_service' => $has_service,
        'port_open'   => $port_open,
        'running'     => ($is_active || $port_open)
    ];
}

// ── AJAX Endpoint: Status Layanan Real-time ──
if ($action === 'service_status') {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $rad = get_freeradius_status();
    $wg  = get_wireguard_status();
    $wa  = get_whatsapp_status();

    echo json_encode([
        'freeradius' => [
            'installed' => $rad['installed'],
            'running'   => $rad['running'],
            'label'     => $rad['running'] ? '✓ Running (Aktif)' : ($rad['installed'] ? 'Terpasang (Nonaktif)' : 'Belum Terpasang'),
            'badge'     => $rad['running'] ? 'bg-success' : ($rad['installed'] ? 'bg-warning text-dark' : 'bg-danger')
        ],
        'wireguard' => [
            'installed' => $wg['installed'],
            'running'   => $wg['running'],
            'label'     => $wg['running'] ? '✓ Running (Aktif)' : ($wg['installed'] ? 'Terpasang (Nonaktif)' : 'Belum Terpasang'),
            'badge'     => $wg['running'] ? 'bg-success' : ($wg['installed'] ? 'bg-warning text-dark' : 'bg-danger')
        ],
        'whatsapp' => [
            'installed' => $wa['installed'],
            'running'   => $wa['running'],
            'label'     => $wa['running'] ? '✓ Running (Aktif)' : ($wa['has_node'] ? 'Node.js OK (Service Mati)' : 'Belum Terpasang'),
            'badge'     => $wa['running'] ? 'bg-success' : ($wa['has_node'] ? 'bg-warning text-dark' : 'bg-danger')
        ],
        'all_running' => ($rad['running'] && $wg['running'] && $wa['running'])
    ]);
    exit;
}

// ── AJAX Endpoint: Jalankan Instalasi di Background ──
if ($action === 'install_bg_service') {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $svc = $_POST['service'] ?? ($_GET['service'] ?? '');

    // Gunakan direktori logs di dalam project agar aman dari pembatasan open_basedir
    $logDir = BASE_PATH . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }

    $logFiles = [
        'freeradius' => $logDir . '/install_freeradius.log',
        'wireguard'  => $logDir . '/install_wireguard.log',
        'whatsapp'   => $logDir . '/install_whatsapp.log',
        'all'        => $logDir . '/install_all.log'
    ];

    $logFile = $logFiles[$svc] ?? ($logDir . '/install_misc.log');

    // Cek apakah shell_exec diizinkan oleh PHP
    if (!can_run_shell_exec()) {
        $msg = "Fungsi 'shell_exec' dinonaktifkan di konfigurasi PHP server (aaPanel disable_functions).";
        @file_put_contents($logFile, "[PERINGATAN] " . $msg . "\nSilakan jalankan perintah aktivasi manual melalui terminal SSH di bawah.\n");
        echo json_encode([
            'success'  => false,
            'need_ssh' => true,
            'service'  => $svc,
            'message'  => $msg
        ]);
        exit;
    }

    if ($svc === 'freeradius') {
        @file_put_contents($logFile, "=== Memulai Instalasi FreeRADIUS pada " . date('Y-m-d H:i:s') . " ===\n");
        @shell_exec("nohup sudo -n bash " . escapeshellarg(BASE_PATH . "/setup_freeradius.sh") . " >> " . escapeshellarg($logFile) . " 2>&1 &");
        echo json_encode(['success' => true, 'service' => 'freeradius', 'message' => 'Instalasi FreeRADIUS dijalankan di latar belakang...']);
        exit;
    } elseif ($svc === 'wireguard') {
        @file_put_contents($logFile, "=== Memulai Instalasi WireGuard pada " . date('Y-m-d H:i:s') . " ===\n");
        @shell_exec("nohup sudo -n bash " . escapeshellarg(BASE_PATH . "/setup_wireguard.sh") . " >> " . escapeshellarg($logFile) . " 2>&1 &");
        echo json_encode(['success' => true, 'service' => 'wireguard', 'message' => 'Instalasi WireGuard dijalankan di latar belakang...']);
        exit;
    } elseif ($svc === 'whatsapp') {
        @file_put_contents($logFile, "=== Memulai Instalasi WhatsApp Gateway pada " . date('Y-m-d H:i:s') . " ===\n");
        @shell_exec("nohup sudo -n bash " . escapeshellarg(BASE_PATH . "/setup_wa_service.sh") . " >> " . escapeshellarg($logFile) . " 2>&1 &");
        echo json_encode(['success' => true, 'service' => 'whatsapp', 'message' => 'Instalasi WhatsApp Service dijalankan di latar belakang...']);
        exit;
    } elseif ($svc === 'all') {
        @file_put_contents($logFile, "=== Memulai Instalasi Semua Layanan pada " . date('Y-m-d H:i:s') . " ===\n");
        $allCmd = "bash " . escapeshellarg(BASE_PATH . "/setup_freeradius.sh") . " && bash " . escapeshellarg(BASE_PATH . "/setup_wireguard.sh") . " && bash " . escapeshellarg(BASE_PATH . "/setup_wa_service.sh");
        @shell_exec("nohup sudo -n bash -c " . escapeshellarg($allCmd) . " >> " . escapeshellarg($logFile) . " 2>&1 &");
        echo json_encode(['success' => true, 'service' => 'all', 'message' => 'Seluruh layanan sedang diinstal berurutan di latar belakang...']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Layanan tidak valid']);
    exit;
}

// ── AJAX Endpoint: Ambil Live Log Instalasi ──
if ($action === 'get_bg_log') {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $svc = $_GET['service'] ?? '';
    $logDir = BASE_PATH . '/logs';
    $logFiles = [
        'freeradius' => $logDir . '/install_freeradius.log',
        'wireguard'  => $logDir . '/install_wireguard.log',
        'whatsapp'   => $logDir . '/install_whatsapp.log',
        'all'        => $logDir . '/install_all.log'
    ];
    $logFile = $logFiles[$svc] ?? '';
    $out = '';
    if ($logFile && file_exists($logFile)) {
        $lines = @file($logFile);
        if ($lines) {
            $out = implode('', array_slice($lines, -40));
        }
    }
    echo json_encode(['log' => $out]);
    exit;
}

function auto_configure_freeradius(array $dbc): array {
    $out = '';
    $script = BASE_PATH . '/setup_freeradius.sh';

    if (file_exists($script) && can_run_shell_exec()) {
        try {
            $h = escapeshellarg($dbc['db_host'] === 'localhost' ? '127.0.0.1' : $dbc['db_host']);
            $u = escapeshellarg($dbc['db_user']);
            $p = escapeshellarg($dbc['db_pass']);
            $n = escapeshellarg($dbc['db_name']);
            $port = (int)$dbc['db_port'];
            $cmd = "sudo bash $script $h $u $p $n $port 2>&1";
            $out = @shell_exec($cmd);
        } catch (\Throwable $e) {}
    }

    return ['output' => $out];
}

$step          = (int)($_GET['step'] ?? 1);
$errors        = [];
$success       = [];
$radius_status = get_freeradius_status();
$wg_status     = get_wireguard_status();
$wa_status     = get_whatsapp_status();

// Handle manual trigger install radius via UI
if (isset($_POST['action']) && $_POST['action'] === 'run_radius_setup') {
    $script = BASE_PATH . '/setup_freeradius.sh';
    if (can_run_shell_exec()) {
        try {
            $out = @shell_exec("sudo -n bash $script 2>&1");
            if ($out && strpos($out, 'sudo:') === false && strpos($out, 'a password is required') === false) {
                $success[] = '✓ FreeRADIUS berhasil dikonfigurasi dan diaktifkan!';
            } else {
                $success[] = 'Konfigurasi SQL telah disiapkan. Jalankan perintah ini di terminal VPS untuk mengaktifkan daemon service:<br><code class="d-block p-2 bg-dark text-light rounded font-monospace mt-1">sudo bash ' . BASE_PATH . '/setup_freeradius.sh</code>';
            }
        } catch (\Throwable $e) {
            $success[] = 'Jalankan via terminal VPS: <code class="d-block p-1 bg-dark text-light rounded font-monospace mt-1">sudo bash ' . BASE_PATH . '/setup_freeradius.sh</code>';
        }
    } else {
        $success[] = 'Jalankan via terminal VPS: <code class="d-block p-1 bg-dark text-light rounded font-monospace mt-1">sudo bash ' . BASE_PATH . '/setup_freeradius.sh</code>';
    }
    $radius_status = get_freeradius_status();
}

// Handle manual trigger install wireguard via UI
if (isset($_POST['action']) && $_POST['action'] === 'run_wg_setup') {
    $script = BASE_PATH . '/setup_wireguard.sh';
    if (can_run_shell_exec()) {
        try {
            $out = @shell_exec("sudo -n bash $script 2>&1");
            if ($out && strpos($out, 'sudo:') === false && strpos($out, 'a password is required') === false) {
                $success[] = '✓ WireGuard VPN server berhasil dikonfigurasi dan diaktifkan!';
            } else {
                $success[] = 'Kunci WireGuard &amp; konfigurasi DB telah disiapkan. Jalankan perintah ini di terminal VPS untuk mengaktifkan service kernel:<br><code class="d-block p-2 bg-dark text-light rounded font-monospace mt-1">sudo bash ' . BASE_PATH . '/setup_wireguard.sh</code>';
            }
        } catch (\Throwable $e) {
            $success[] = 'Jalankan via terminal VPS: <code class="d-block p-1 bg-dark text-light rounded font-monospace mt-1">sudo bash ' . BASE_PATH . '/setup_wireguard.sh</code>';
        }
    } else {
        $success[] = 'Jalankan via terminal VPS: <code class="d-block p-1 bg-dark text-light rounded font-monospace mt-1">sudo bash ' . BASE_PATH . '/setup_wireguard.sh</code>';
    }
    $wg_status = get_wireguard_status();
}

// ── Step 1: DB Test ─────────────────────────────────────
if ($step === 2 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $db_host = trim($_POST['db_host'] ?? 'localhost');
    $db_user = trim($_POST['db_user'] ?? '');
    $db_pass = $_POST['db_pass'] ?? '';
    $db_name = trim($_POST['db_name'] ?? 'radius');
    $db_port = (int)($_POST['db_port'] ?? 3306);

    try {
        // Connect WITHOUT db_name first to attempt creation
        @$conn_init = new mysqli($db_host, $db_user, $db_pass, "", $db_port);
        if ($conn_init->connect_error) throw new Exception($conn_init->connect_error);
        
        // Create database if not exists
        $esc_db = $conn_init->real_escape_string($db_name);
        if (!$conn_init->query("CREATE DATABASE IF NOT EXISTS `$esc_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
            throw new Exception("Gagal membuat database: " . $conn_init->error);
        }
        $conn_init->close();

        // Now connect to the newly created database
        @$conn = new mysqli($db_host, $db_user, $db_pass, $db_name, $db_port);
        if ($conn->connect_error) throw new Exception($conn->connect_error);
        $conn->set_charset('utf8mb4');

        // Save to session & write db_local.php immediately
        $_SESSION['install_db'] = compact('db_host','db_user','db_pass','db_name','db_port');

        $env = "<?php\n"
            . "define('DB_HOST', '" . addslashes($db_host) . "');\n"
            . "define('DB_USER', '" . addslashes($db_user) . "');\n"
            . "define('DB_PASS', '" . addslashes($db_pass) . "');\n"
            . "define('DB_NAME', '" . addslashes($db_name) . "');\n"
            . "define('DB_PORT', " . (int)$db_port . ");\n"
            . "define('DB_CHARSET', 'utf8mb4');\n";
        @file_put_contents(CONFIG_PATH . '/db_local.php', $env);

        $success[] = 'Koneksi database berhasil!';
        $step = 3;
    } catch (Exception $e) {
        $errors[] = 'Gagal koneksi: ' . $e->getMessage();
        $step = 2;
    }
}

// ── Step 2: Create Tables ───────────────────────────────
if ($step === 4 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbc = $_SESSION['install_db'] ?? null;
    if (!$dbc && file_exists(CONFIG_PATH . '/db_local.php')) {
        @include CONFIG_PATH . '/db_local.php';
        if (defined('DB_HOST')) {
            $dbc = [
                'db_host' => DB_HOST,
                'db_user' => DB_USER,
                'db_pass' => DB_PASS,
                'db_name' => DB_NAME,
                'db_port' => DB_PORT
            ];
            $_SESSION['install_db'] = $dbc;
        }
    }

    if (!$dbc) {
        $errors[] = 'Data koneksi database belum tersedia. Silakan masukkan data database kembali.';
        $step = 1;
    } else {
        try {
            $conn = new mysqli($dbc['db_host'], $dbc['db_user'], $dbc['db_pass'], $dbc['db_name'], $dbc['db_port']);
            if ($conn->connect_error) throw new Exception($conn->connect_error);
            $conn->set_charset('utf8mb4');
            $conn->query("SET FOREIGN_KEY_CHECKS = 0");

    $sqls = [
        // FreeRADIUS standard tables
        "CREATE TABLE IF NOT EXISTS radcheck (
            id int(11) unsigned NOT NULL AUTO_INCREMENT,
            username varchar(64) NOT NULL DEFAULT '',
            attribute varchar(64) NOT NULL DEFAULT '',
            op char(2) NOT NULL DEFAULT ':=',
            value varchar(253) NOT NULL DEFAULT '',
            PRIMARY KEY (id),
            KEY username (username(32))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS radreply (
            id int(11) unsigned NOT NULL AUTO_INCREMENT,
            username varchar(64) NOT NULL DEFAULT '',
            attribute varchar(64) NOT NULL DEFAULT '',
            op char(2) NOT NULL DEFAULT '=',
            value varchar(253) NOT NULL DEFAULT '',
            PRIMARY KEY (id),
            KEY username (username(32))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS radgroupcheck (
            id int(11) unsigned NOT NULL AUTO_INCREMENT,
            groupname varchar(64) NOT NULL DEFAULT '',
            attribute varchar(64) NOT NULL DEFAULT '',
            op char(2) NOT NULL DEFAULT ':=',
            value varchar(253) NOT NULL DEFAULT '',
            PRIMARY KEY (id),
            KEY groupname (groupname(32))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS radgroupreply (
            id int(11) unsigned NOT NULL AUTO_INCREMENT,
            groupname varchar(64) NOT NULL DEFAULT '',
            attribute varchar(64) NOT NULL DEFAULT '',
            op char(2) NOT NULL DEFAULT '=',
            value varchar(253) NOT NULL DEFAULT '',
            PRIMARY KEY (id),
            KEY groupname (groupname(32))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS radusergroup (
            username varchar(64) NOT NULL DEFAULT '',
            groupname varchar(64) NOT NULL DEFAULT '',
            priority int(11) NOT NULL DEFAULT 1,
            KEY username (username(32))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS radacct (
            radacctid bigint(21) NOT NULL AUTO_INCREMENT,
            acctsessionid varchar(64) NOT NULL DEFAULT '',
            acctuniqueid varchar(32) NOT NULL DEFAULT '',
            username varchar(64) NOT NULL DEFAULT '',
            realm varchar(64) DEFAULT '',
            nasipaddress varchar(15) NOT NULL DEFAULT '',
            nasportid varchar(15) DEFAULT NULL,
            nasporttype varchar(32) DEFAULT NULL,
            acctstarttime datetime DEFAULT NULL,
            acctupdatetime datetime DEFAULT NULL,
            acctstoptime datetime DEFAULT NULL,
            acctinterval int(12) DEFAULT NULL,
            acctsessiontime int(12) unsigned DEFAULT NULL,
            acctauthentic varchar(32) DEFAULT NULL,
            connectinfo_start varchar(50) DEFAULT NULL,
            connectinfo_stop varchar(50) DEFAULT NULL,
            acctinputoctets bigint(20) DEFAULT NULL,
            acctoutputoctets bigint(20) DEFAULT NULL,
            calledstationid varchar(50) NOT NULL DEFAULT '',
            callingstationid varchar(50) NOT NULL DEFAULT '',
            acctterminatecause varchar(32) NOT NULL DEFAULT '',
            servicetype varchar(32) DEFAULT NULL,
            framedprotocol varchar(32) DEFAULT NULL,
            framedipaddress varchar(15) NOT NULL DEFAULT '',
            framedipv6address varchar(45) NOT NULL DEFAULT '',
            framedipv6prefix varchar(45) NOT NULL DEFAULT '',
            framedinterfaceid varchar(44) NOT NULL DEFAULT '',
            delegatedipv6prefix varchar(45) NOT NULL DEFAULT '',
            class varchar(64) DEFAULT NULL,
            PRIMARY KEY (radacctid),
            UNIQUE KEY acctuniqueid (acctuniqueid),
            KEY username (username),
            KEY nasipaddress (nasipaddress),
            KEY acctsessionid (acctsessionid),
            KEY acctstarttime (acctstarttime),
            KEY acctstoptime (acctstoptime),
            KEY acctupdatetime (acctupdatetime),
            KEY framedipaddress (framedipaddress)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS radpostauth (
            id int(11) NOT NULL AUTO_INCREMENT,
            username varchar(64) NOT NULL DEFAULT '',
            pass varchar(64) NOT NULL DEFAULT '',
            reply varchar(32) NOT NULL DEFAULT '',
            authdate timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS nas (
            id int(10) NOT NULL AUTO_INCREMENT,
            nasname varchar(128) NOT NULL,
            shortname varchar(32) DEFAULT NULL,
            type varchar(30) DEFAULT 'other',
            ports int(5) DEFAULT NULL,
            secret varchar(60) NOT NULL DEFAULT 'secret',
            server varchar(64) DEFAULT NULL,
            community varchar(50) DEFAULT NULL,
            description varchar(200) DEFAULT 'RADIUS Client',
            PRIMARY KEY (id),
            KEY nasname (nasname)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Custom app tables
        "CREATE TABLE IF NOT EXISTS routers (
            id int(11) NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL,
            ip_address varchar(45) NOT NULL,
            nas_id int(11) DEFAULT NULL,
            nas_ip varchar(128) DEFAULT '0.0.0.0/0',
            api_user varchar(64) DEFAULT 'admin',
            api_password varchar(128) DEFAULT '',
            api_port smallint(5) unsigned DEFAULT 8728,
            radius_secret varchar(128) NOT NULL,
            location text DEFAULT NULL,
            status enum('active','inactive') DEFAULT 'active',
            genie_server_id INT DEFAULT NULL,
            default_vlan INT DEFAULT 100,
            last_seen datetime DEFAULT NULL,
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY ip_address (ip_address)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS profiles (
            id int(11) NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL,
            display_name varchar(100) DEFAULT NULL,
            validity_value int(11) DEFAULT 30,
            validity_unit enum('minutes','hours','days') DEFAULT 'days',
            duration_value int(11) DEFAULT 30,
            duration_unit enum('minutes','hours','days') DEFAULT 'days',
            quota_mb bigint(20) DEFAULT 0,
            rate_up varchar(20) DEFAULT '0',
            rate_down varchar(20) DEFAULT '0',
            price decimal(10,2) DEFAULT 0.00,
            include_in_sales tinyint(1) DEFAULT 1,
            reseller_percent decimal(5,2) DEFAULT 0.00,
            router_id int(11) DEFAULT NULL,
            description text DEFAULT NULL,
            is_active tinyint(1) DEFAULT 1,
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS vouchers (
            id int(11) NOT NULL AUTO_INCREMENT,
            username varchar(64) NOT NULL,
            password varchar(128) NOT NULL,
            profile_id int(11) NOT NULL,
            router_id int(11) DEFAULT NULL,
            batch_id varchar(64) DEFAULT NULL,
            status enum('unused','active','expired','deleted') DEFAULT 'unused',
            used_at datetime DEFAULT NULL,
            expired_at datetime DEFAULT NULL,
            generated_by int(11) DEFAULT NULL,
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY username (username),
            KEY batch_id (batch_id),
            KEY status (status),
            KEY profile_id (profile_id),
            KEY router_id (router_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS admins (
            id int(11) NOT NULL AUTO_INCREMENT,
            username varchar(64) NOT NULL,
            password varchar(255) NOT NULL,
            full_name varchar(100) DEFAULT NULL,
            role enum('superadmin','operator') DEFAULT 'operator',
            router_access json DEFAULT NULL,
            is_active tinyint(1) DEFAULT 1,
            last_login datetime DEFAULT NULL,
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS audit_log (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            admin_id int(11) DEFAULT NULL,
            admin_name varchar(64) DEFAULT NULL,
            action varchar(50) DEFAULT NULL,
            target varchar(100) DEFAULT NULL,
            router_id int(11) DEFAULT NULL,
            detail text DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY admin_id (admin_id),
            KEY created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS sales_log (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            voucher_id int(11) DEFAULT NULL,
            voucher_username varchar(64) DEFAULT NULL,
            profile_id int(11) DEFAULT NULL,
            profile_name varchar(100) DEFAULT NULL,
            router_id int(11) DEFAULT NULL,
            sold_by int(11) DEFAULT NULL,
            price decimal(10,2) DEFAULT 0.00,
            sold_at timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY router_id (router_id),
            KEY sold_at (sold_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        
        "CREATE TABLE IF NOT EXISTS penagihan (
            id int(11) NOT NULL AUTO_INCREMENT,
            router_id int(11) NOT NULL,
            profile_id int(11) NOT NULL,
            total_pendapatan decimal(15,2) DEFAULT 0.00,
            bagian_reseller decimal(15,2) DEFAULT 0.00,
            pendapatan_bersih decimal(15,2) DEFAULT 0.00,
            estimasi_voucher int(11) DEFAULT 0,
            voucher_aktual int(11) DEFAULT 0,
            status_kecocokan enum('sesuai','tekor','lebih') DEFAULT 'sesuai',
            catatan text,
            ditagih_oleh int(11) NOT NULL,
            tanggal date NOT NULL,
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY router_id (router_id),
            KEY profile_id (profile_id),
            KEY tanggal (tanggal)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // ── V2 Addons: PPPoE, GenieACS, Portal ──
        "CREATE TABLE IF NOT EXISTS genie_config (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) DEFAULT 'GenieACS',
            url VARCHAR(255) DEFAULT 'http://localhost:7557',
            username VARCHAR(100) DEFAULT '',
            password VARCHAR(255) DEFAULT '',
            is_active TINYINT(1) DEFAULT 1,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS customers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            customer_id VARCHAR(30) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            full_name VARCHAR(150) NOT NULL,
            phone VARCHAR(20) DEFAULT '',
            address TEXT,
            genie_device_id VARCHAR(255) DEFAULT '',
            device_serial VARCHAR(100) DEFAULT '',
            device_brand ENUM('FiberHome','CData','Huawei','ZTE','Unknown') DEFAULT 'Unknown',
            device_model VARCHAR(100) DEFAULT '',
            ont_tag VARCHAR(100) DEFAULT '',
            router_id INT DEFAULT NULL,
            is_active TINYINT(1) DEFAULT 1,
            notes TEXT,
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (router_id) REFERENCES routers(id) ON DELETE SET NULL,
            FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS ont_configs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            customer_id INT NOT NULL,
            genie_device_id VARCHAR(255) NOT NULL,
            config_type ENUM('wifi','wan','binding') NOT NULL,
            config_name VARCHAR(150) DEFAULT '',
            config_data TEXT NOT NULL,
            push_status ENUM('success','failed','pending') DEFAULT 'success',
            push_count INT DEFAULT 1,
            last_pushed TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS pppoe_customers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            router_id INT NOT NULL,
            pppoe_username VARCHAR(100) NOT NULL,
            portal_password VARCHAR(255) DEFAULT '',
            portal_password_plain VARCHAR(255) DEFAULT '',
            portal_username VARCHAR(100) DEFAULT '',
            full_name VARCHAR(150) NOT NULL DEFAULT '',
            phone VARCHAR(25) DEFAULT '',
            address TEXT,
            profile VARCHAR(100) DEFAULT '',
            monthly_price INT DEFAULT 0,
            is_free TINYINT(1) DEFAULT 0,
            due_day TINYINT DEFAULT 1,
            status ENUM('active','isolated','suspended') DEFAULT 'active',
            ont_sn VARCHAR(50) DEFAULT '',
            ont_vlan INT DEFAULT 100,
            ont_wifi_ssid VARCHAR(100) DEFAULT '',
            ont_wifi_pass VARCHAR(100) DEFAULT '',
            isolated_at DATETIME DEFAULT NULL,
            isolated_reason VARCHAR(255) DEFAULT '',
            last_paid_at DATE DEFAULT NULL,
            last_paid_amount INT DEFAULT 0,
            notes TEXT,
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_router_user (router_id, pppoe_username),
            FOREIGN KEY (router_id) REFERENCES routers(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS pppoe_payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            customer_id INT NOT NULL,
            amount INT NOT NULL,
            payment_method VARCHAR(50) DEFAULT 'cash',
            payment_gateway VARCHAR(50) DEFAULT 'midtrans',
            midtrans_order_id VARCHAR(100) DEFAULT NULL,
            midtrans_tx_id VARCHAR(100) DEFAULT NULL,
            midtrans_status VARCHAR(50) DEFAULT NULL,
            checkout_url TEXT DEFAULT NULL,
            pay_code VARCHAR(100) DEFAULT NULL,
            qr_url TEXT DEFAULT NULL,
            period_month TINYINT NOT NULL,
            period_year SMALLINT NOT NULL,
            paid_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            notes VARCHAR(255) DEFAULT '',
            created_by INT DEFAULT NULL,
            FOREIGN KEY (customer_id) REFERENCES pppoe_customers(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS pppoe_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) NOT NULL UNIQUE,
            setting_value TEXT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "INSERT IGNORE INTO pppoe_settings (setting_key, setting_value) VALUES
            ('active_payment_gateway', 'both'),
            ('midtrans_server_key', ''),
            ('midtrans_client_key', ''),
            ('midtrans_mode', 'sandbox'),
            ('tripay_mode', 'sandbox'),
            ('tripay_merchant_code', ''),
            ('tripay_api_key', ''),
            ('tripay_private_key', ''),
            ('isolir_profile', 'isolir'),
            ('isolir_redirect_url', '/portal/isolir'),
            ('isolir_grace_days', '3'),
            ('company_name', 'S.NET Internet'),
            ('company_phone', ''),
            ('company_address', ''),
            ('ont_username_suffix', '@snet'),
            ('ont_wifi1_prefix', 'S.NET - '),
            ('ont_wifi2_suffix', ' 5G'),
            ('ont_default_wan_fh', '2'),
            ('ont_default_wan_other', '1'),
            ('ont_default_vlan', '100'),
            ('ont_enable_hotspot', '0'),
            ('ont_hotspot_vlan', '100'),
            ('ont_hotspot_ssid2', 'S.NET @Hotspot'),
            ('ont_hotspot_ssid6', 'S.NET @Hotspot 5G'),
            ('ont_hotspot_slot_fh', '3'),
            ('ont_hotspot_slot_other', '2')",

        // WireGuard VPN Tables
        "CREATE TABLE IF NOT EXISTS wg_routers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            location VARCHAR(150) DEFAULT NULL,
            public_key VARCHAR(255) NOT NULL UNIQUE,
            private_key VARCHAR(255) NOT NULL,
            tunnel_ip VARCHAR(20) NOT NULL UNIQUE,
            listen_port INT DEFAULT 13231,
            lan_subnets TEXT DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS wg_settings (
            `key` VARCHAR(100) NOT NULL PRIMARY KEY,
            `value` TEXT NOT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "INSERT IGNORE INTO wg_settings (`key`, `value`) VALUES
            ('wg_interface', 'wg0'),
            ('wg_server_endpoint', '127.0.0.1:51820'),
            ('wg_server_pubkey', ''),
            ('wg_server_privkey', ''),
            ('wg_subnet_prefix', '10.66.66.'),
            ('wg_listen_port', '51820'),
            ('wg_dns', '1.1.1.1, 8.8.8.8'),
            ('wg_mtu', '1420')",

        "CREATE TABLE IF NOT EXISTS wg_port_forwards (
            id INT AUTO_INCREMENT PRIMARY KEY,
            router_id INT NOT NULL,
            public_port INT NOT NULL,
            target_port INT NOT NULL,
            target_ip VARCHAR(20) DEFAULT NULL,
            protocol VARCHAR(10) NOT NULL DEFAULT 'tcp',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_port_proto (public_port, protocol),
            FOREIGN KEY (router_id) REFERENCES wg_routers(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS wg_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event VARCHAR(100) NOT NULL,
            router_id INT DEFAULT NULL,
            router_name VARCHAR(100) DEFAULT NULL,
            details TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS ont_remotes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ont_sn VARCHAR(100) NOT NULL,
            ont_name VARCHAR(150) DEFAULT NULL,
            ont_ip VARCHAR(50) NOT NULL,
            target_port INT NOT NULL DEFAULT 80,
            public_port INT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            is_active TINYINT(1) DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS wa_config (
            id INT AUTO_INCREMENT PRIMARY KEY,
            provider VARCHAR(50) DEFAULT 'waweb',
            api_url VARCHAR(255) DEFAULT 'http://127.0.0.1:3000/api/send',
            api_token VARCHAR(255) DEFAULT '',
            device_id VARCHAR(100) DEFAULT '',
            is_active TINYINT(1) DEFAULT 1,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS wa_templates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(50) NOT NULL UNIQUE,
            name VARCHAR(100) NOT NULL,
            message TEXT NOT NULL,
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS wa_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            customer_id INT DEFAULT NULL,
            phone VARCHAR(30) NOT NULL,
            recipient_name VARCHAR(150) DEFAULT '',
            message_type VARCHAR(50) DEFAULT 'general',
            message_text TEXT NOT NULL,
            status ENUM('success','failed','pending') DEFAULT 'pending',
            response_payload TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // ── V3 Addons: Bandwidth Snapshot (tahan restart MikroTik) ──
        "CREATE TABLE IF NOT EXISTS pppoe_bandwidth_snapshots (
            id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
            router_id       INT             NOT NULL,
            username        VARCHAR(128)    NOT NULL,
            month_year      CHAR(7)         NOT NULL COMMENT 'Format: YYYY-MM',
            committed_dl    BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Akumulasi tx-byte (download pelanggan)',
            committed_ul    BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Akumulasi rx-byte (upload pelanggan)',
            last_if_tx      BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Counter tx-byte interface saat checkpoint terakhir',
            last_if_rx      BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Counter rx-byte interface saat checkpoint terakhir',
            updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_router_user_month (router_id, username, month_year),
            KEY idx_username_month (username, month_year)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Akumulasi bandwidth bulanan tahan restart MikroTik'"
    ];

            $failed = false;
            foreach ($sqls as $sql) {
                if (!$conn->query($sql)) {
                    $errors[] = 'SQL Error: ' . $conn->error;
                    $failed = true;
                    break;
                }
            }
            $conn->query("SET FOREIGN_KEY_CHECKS = 1");

            if (!$failed) {
                $success[] = 'Semua tabel berhasil dibuat! (FreeRADIUS + Hotspot + PPPoE + GenieACS + WireGuard VPN + WhatsApp Gateway + Bandwidth Snapshot = 25 tabel)';
                $step = 5;
            } else {
                $step = 4;
            }
        } catch (Exception $e) {
            $errors[] = 'Gagal membuat tabel: ' . $e->getMessage();
            $step = 4;
        }
    }
}

// ── Step 3: Create first admin ──────────────────────────
if ($step === 6 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbc = $_SESSION['install_db'] ?? null;
    if (!$dbc && file_exists(CONFIG_PATH . '/db_local.php')) {
        @include CONFIG_PATH . '/db_local.php';
        if (defined('DB_HOST')) {
            $dbc = [
                'db_host' => DB_HOST,
                'db_user' => DB_USER,
                'db_pass' => DB_PASS,
                'db_name' => DB_NAME,
                'db_port' => DB_PORT
            ];
            $_SESSION['install_db'] = $dbc;
        }
    }

    $adm_user = trim($_POST['adm_user'] ?? '');
    $adm_name = trim($_POST['adm_name'] ?? '');
    $adm_pass = $_POST['adm_pass'] ?? '';
    $adm_pass2 = $_POST['adm_pass2'] ?? '';

    if (!$dbc) {
        $errors[] = 'Data koneksi database belum tersedia. Silakan masukkan data database kembali.';
        $step = 1;
    } elseif (!$adm_user || !$adm_pass) {
        $errors[] = 'Username dan password admin wajib diisi.';
        $step = 6;
    } elseif ($adm_pass !== $adm_pass2) {
        $errors[] = 'Password tidak sama.';
        $step = 6;
    } elseif (strlen($adm_pass) < 6) {
        $errors[] = 'Password minimal 6 karakter.';
        $step = 6;
    } else {
        try {
            $conn = new mysqli($dbc['db_host'], $dbc['db_user'], $dbc['db_pass'], $dbc['db_name'], $dbc['db_port']);
            if ($conn->connect_error) throw new Exception($conn->connect_error);
            $conn->set_charset('utf8mb4');
            $hash = password_hash($adm_pass, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO admins (username, password, full_name, role) VALUES (?,?,?,'superadmin') ON DUPLICATE KEY UPDATE password=VALUES(password), full_name=VALUES(full_name), role='superadmin'");
            $stmt->bind_param('sss', $adm_user, $hash, $adm_name);
            if ($stmt->execute()) {
                // Write config
                $env = "<?php\n"
                    . "define('DB_HOST', '" . addslashes($dbc['db_host']) . "');\n"
                    . "define('DB_USER', '" . addslashes($dbc['db_user']) . "');\n"
                    . "define('DB_PASS', '" . addslashes($dbc['db_pass']) . "');\n"
                    . "define('DB_NAME', '" . addslashes($dbc['db_name']) . "');\n"
                    . "define('DB_PORT', " . (int)$dbc['db_port'] . ");\n"
                    . "define('DB_CHARSET', 'utf8mb4');\n";
                file_put_contents(CONFIG_PATH . '/db_local.php', $env);
                touch(CONFIG_PATH . '/.installed');

                // Auto-configure WireGuard Server Keys in DB
                try {
                    $endpointHost = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '127.0.0.1');
                    if (strpos($endpointHost, ':') !== false) $endpointHost = explode(':', $endpointHost)[0];
                    $wgEndpoint = $endpointHost . ':51820';
                    
                    $resKey = $conn->query("SELECT value FROM wg_settings WHERE `key` = 'wg_server_pubkey'");
                    $chkKey = $resKey ? $resKey->fetch_assoc() : null;
                    if (empty($chkKey['value'])) {
                        $serverPriv = base64_encode(random_bytes(32));
                        $serverPub  = base64_encode(hash('sha256', $serverPriv . '_snet_wg_pub', true));
                        $conn->query("INSERT INTO wg_settings (`key`, `value`) VALUES ('wg_server_pubkey', '{$serverPub}'), ('wg_server_privkey', '{$serverPriv}'), ('wg_server_endpoint', '{$wgEndpoint}') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
                    }
                } catch (Throwable $e) {}

                // Auto-configure FreeRADIUS & WireGuard quietly if sudo -n is available
                if (can_run_shell_exec()) {
                    try {
                        @shell_exec("sudo -n bash " . BASE_PATH . "/setup_freeradius.sh >/dev/null 2>&1 &");
                        @shell_exec("sudo -n bash " . BASE_PATH . "/setup_wireguard.sh >/dev/null 2>&1 &");
                    } catch (\Throwable $e) {}
                }

                $step = 7; // done
            } else {
                $errors[] = 'Gagal buat admin: ' . $conn->error;
                $step = 6;
            }
        } catch (Exception $e) {
            $errors[] = 'Gagal membuat admin: ' . $e->getMessage();
            $step = 6;
        }
    }
}

// ── HTML ─────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalasi — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="login-body" style="align-items:flex-start;padding:40px 20px;">
<div class="login-card" style="max-width:540px;margin:auto;">
    <div class="login-logo">
        <div style="display:inline-flex;align-items:center;justify-content:center;background:#ffffff;padding:8px 18px;border-radius:12px;box-shadow:0 4px 16px rgba(0,0,0,0.15);margin-bottom:8px;">
            <img src="/assets/img/logo.png?v=<?= filemtime(__DIR__ . '/assets/img/logo.png') ?>" alt="Logo" style="height:50px;object-fit:contain;display:block;">
        </div>
        <h5>Instalasi <?= APP_NAME ?></h5>
        <p><?= APP_COMPANY ?></p>
    </div>
    <div class="login-divider"></div>

    <?php foreach ($errors as $e): ?>
    <div class="alert alert-danger"><i class="bi bi-x-circle me-2"></i><?= $e ?></div>
    <?php endforeach; ?>
    <?php foreach ($success as $s): ?>
    <div class="alert alert-success"><i class="bi bi-check-circle me-2"></i><?= $s ?></div>
    <?php endforeach; ?>

    <!-- Step Indicator -->
    <div class="d-flex gap-2 mb-4">
        <?php foreach (['DB & Server', 'Tabel', 'Admin', 'Selesai'] as $i => $label): ?>
        <?php $n = $i+1; $active = ($step >= $n*2) ? 'bg-primary' : ($step >= $n*2-1 ? 'bg-primary' : 'bg-secondary'); ?>
        <div class="flex-fill text-center">
            <div class="badge <?= $active ?> w-100 mb-1" style="padding:8px;"><?= $n ?></div>
            <small style="font-size:.7rem;"><?= $label ?></small>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if ($step <= 2): ?>
    <!-- Step 1: Server Readiness Overview -->
    <div class="card mb-4 border bg-light shadow-sm">
        <div class="card-body p-3">
            <h6 class="fw-bold mb-2 text-dark" style="font-size:.9rem;">
                <i class="bi bi-hdd-network-fill text-primary me-2"></i>Kesiapan Layanan Server VPS:
            </h6>
            <div class="row g-2 small">
                <div class="col-4">
                    <div class="p-2 bg-white border rounded text-center">
                        <div class="fw-semibold text-truncate small"><i class="bi bi-shield-check text-primary me-1"></i>FreeRADIUS</div>
                        <div class="mt-1"><?= $radius_status['running'] ? '<span class="badge bg-success">✓ Running</span>' : ($radius_status['installed'] ? '<span class="badge bg-warning text-dark">Terpasang</span>' : '<span class="badge bg-secondary">Belum ada</span>') ?></div>
                    </div>
                </div>
                <div class="col-4">
                    <div class="p-2 bg-white border rounded text-center">
                        <div class="fw-semibold text-truncate small"><i class="bi bi-shield-lock-fill text-danger me-1"></i>WireGuard</div>
                        <div class="mt-1"><?= $wg_status['running'] ? '<span class="badge bg-success">✓ Running</span>' : ($wg_status['installed'] ? '<span class="badge bg-warning text-dark">Terpasang</span>' : '<span class="badge bg-secondary">Belum ada</span>') ?></div>
                    </div>
                </div>
                <div class="col-4">
                    <div class="p-2 bg-white border rounded text-center">
                        <div class="fw-semibold text-truncate small"><i class="bi bi-whatsapp text-success me-1"></i>WhatsApp</div>
                        <div class="mt-1"><?= $wa_status['running'] ? '<span class="badge bg-success">✓ Running</span>' : ($wa_status['has_node'] ? '<span class="badge bg-warning text-dark">Node.js OK</span>' : '<span class="badge bg-secondary">Belum ada</span>') ?></div>
                    </div>
                </div>
            </div>
            <div class="text-muted mt-2" style="font-size:.75rem;">
                <i class="bi bi-info-circle me-1 text-primary"></i> Layanan yang belum aktif dapat diinstal otomatis di latar belakang pada akhir wizard instalasi. Masukkan detail Database MySQL di bawah ini:
            </div>
        </div>
    </div>

    <h6 class="fw-700 mb-3"><i class="bi bi-database me-2 text-blue"></i>Konfigurasi Database MySQL</h6>
    <form method="POST" action="install.php?step=2">
        <div class="mb-3">
            <label class="form-label">Host DB</label>
            <input type="text" class="form-control" name="db_host" value="localhost" required>
        </div>
        <div class="row g-2 mb-3">
            <div class="col">
                <label class="form-label">User DB</label>
                <input type="text" class="form-control" name="db_user" value="radius" required>
            </div>
            <div class="col">
                <label class="form-label">Password DB</label>
                <input type="password" class="form-control" name="db_pass">
            </div>
        </div>
        <div class="row g-2 mb-4">
            <div class="col">
                <label class="form-label">Nama Database</label>
                <input type="text" class="form-control" name="db_name" value="radius" required>
            </div>
            <div class="col">
                <label class="form-label">Port</label>
                <input type="number" class="form-control" name="db_port" value="3306">
            </div>
        </div>
        <button class="btn btn-primary w-100">Test &amp; Lanjut <i class="bi bi-arrow-right ms-2"></i></button>
    </form>

    <?php elseif ($step === 3 || $step === 4): ?>
    <!-- Step 2: Create Tables -->
    <h6 class="fw-700 mb-3"><i class="bi bi-table me-2 text-blue"></i>Buat Tabel Database</h6>
    <p class="text-muted">Klik tombol di bawah untuk membuat semua tabel yang diperlukan (FreeRADIUS + Hotspot + PPPoE + WireGuard VPN).</p>
    <div class="bg-light rounded p-3 mb-3" style="font-size:.75rem;font-family:monospace;line-height:1.6;">
        <b>FreeRADIUS (8):</b> radcheck, radreply, radgroupcheck, radgroupreply, radusergroup, radacct, radpostauth, nas<br>
        <b>Hotspot (7):</b> routers, profiles, vouchers, admins, audit_log, sales_log, penagihan<br>
        <b>Broadband &amp; ACS (6):</b> genie_config, customers, ont_configs, pppoe_customers, pppoe_payments, pppoe_settings<br>
        <b>VPN WireGuard (4):</b> wg_routers, wg_port_forwards, wg_settings, wg_logs<br>
        <b>ONT Remote (1):</b> ont_remotes &nbsp;|&nbsp; <b>WhatsApp (3):</b> wa_config, wa_templates, wa_logs<br>
        <b>🆕 Bandwidth Snapshot (1):</b> pppoe_bandwidth_snapshots
    </div>
    <form method="POST" action="install.php?step=4">
        <button class="btn btn-primary w-100">Buat Tabel <i class="bi bi-arrow-right ms-2"></i></button>
    </form>

    <?php elseif ($step === 5 || $step === 6): ?>
    <!-- Step 3: First Admin -->
    <h6 class="fw-700 mb-3"><i class="bi bi-person-plus me-2 text-blue"></i>Buat Akun Superadmin</h6>
    <form method="POST" action="install.php?step=6">
        <div class="mb-3">
            <label class="form-label">Username</label>
            <input type="text" class="form-control" name="adm_user" value="admin" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Nama Lengkap</label>
            <input type="text" class="form-control" name="adm_name" value="Administrator">
        </div>
        <div class="mb-3">
            <label class="form-label">Password</label>
            <input type="password" class="form-control" name="adm_pass" minlength="6" required>
            <div class="form-text">Minimal 6 karakter</div>
        </div>
        <div class="mb-4">
            <label class="form-label">Ulangi Password</label>
            <input type="password" class="form-control" name="adm_pass2" required>
        </div>
        <button class="btn btn-primary w-100">Selesai Instalasi <i class="bi bi-check-lg ms-2"></i></button>
    </form>

    <?php elseif ($step === 7): ?>
    <!-- Done -->
    <div class="text-center py-3">
        <div style="font-size:3.5rem; color: #2E7D32;">✓</div>
        <h4 class="fw-700 mt-2">Instalasi Selesai!</h4>
        <p class="text-muted mb-3">Database (25 Tabel), Konfigurasi Aplikasi, dan Akun Superadmin telah berhasil dibuat.</p>

        <div class="bg-light border rounded p-3 text-start small mb-3">
            <div class="d-flex justify-content-between mb-1">
                <span><i class="bi bi-database-check text-success me-1"></i> Database MySQL:</span>
                <span class="badge bg-success">25 Tabel Tersimpan</span>
            </div>
            <div class="d-flex justify-content-between mb-1">
                <span><i class="bi bi-person-check text-success me-1"></i> Akun Superadmin:</span>
                <span class="badge bg-success">Siap Digunakan</span>
            </div>
        </div>

        <!-- Step 1: Service VPS — Verifikasi & Background Installer -->
        <div class="card border-0 shadow-sm mb-3 text-start">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center py-2 px-3">
                <span class="fw-bold"><i class="bi bi-hdd-rack-fill me-2"></i>Status Layanan Inti VPS</span>
                <button type="button" class="btn btn-sm btn-light py-0 px-2 fw-bold text-primary" onclick="refreshServiceStatus()">
                    <i class="bi bi-arrow-clockwise me-1"></i>Refresh
                </button>
            </div>
            <div class="card-body p-3">
                <p class="text-muted small mb-3">
                    Sistem memantau kesiapan FreeRADIUS, WireGuard VPN, dan WhatsApp Gateway secara real-time. Anda dapat memasang dan mengaktifkannya langsung dari tombol di bawah (berjalan otomatis di latar belakang):
                </p>

                <!-- Service 1: FreeRADIUS -->
                <div class="border rounded p-3 mb-2 bg-light d-flex flex-wrap justify-content-between align-items-center gap-2" id="boxRadius">
                    <div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="fw-bold text-dark"><i class="bi bi-shield-check text-primary me-1"></i>FreeRADIUS Server</span>
                            <span id="badgeRadius" class="badge <?= $radius_status['running'] ? 'bg-success' : ($radius_status['installed'] ? 'bg-warning text-dark' : 'bg-danger') ?>">
                                <?= $radius_status['running'] ? '✓ Running (Aktif)' : ($radius_status['installed'] ? 'Terpasang (Nonaktif)' : 'Belum Terpasang') ?>
                            </span>
                        </div>
                        <div class="text-muted small mt-1">Port 1812/1813 UDP — Autentikasi Voucher Hotspot &amp; PPPoE</div>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="btnRadius" onclick="triggerInstall('freeradius')">
                        <i class="bi bi-play-circle me-1"></i>Instal / Jalankan di Background
                    </button>
                </div>

                <!-- Service 2: WireGuard VPN -->
                <div class="border rounded p-3 mb-2 bg-light d-flex flex-wrap justify-content-between align-items-center gap-2" id="boxWg">
                    <div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="fw-bold text-dark"><i class="bi bi-shield-lock-fill text-danger me-1"></i>WireGuard VPN</span>
                            <span id="badgeWg" class="badge <?= $wg_status['running'] ? 'bg-success' : ($wg_status['installed'] ? 'bg-warning text-dark' : 'bg-danger') ?>">
                                <?= $wg_status['running'] ? '✓ Running (Aktif)' : ($wg_status['installed'] ? 'Terpasang (Nonaktif)' : 'Belum Terpasang') ?>
                            </span>
                        </div>
                        <div class="text-muted small mt-1">Port 51820 UDP — Tunnel VPN Router MikroTik &amp; Remote ONT</div>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="btnWg" onclick="triggerInstall('wireguard')">
                        <i class="bi bi-play-circle me-1"></i>Instal / Jalankan di Background
                    </button>
                </div>

                <!-- Service 3: WhatsApp Web Gateway -->
                <div class="border rounded p-3 mb-3 bg-light d-flex flex-wrap justify-content-between align-items-center gap-2" id="boxWa">
                    <div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="fw-bold text-dark"><i class="bi bi-whatsapp text-success me-1"></i>WhatsApp Gateway</span>
                            <span id="badgeWa" class="badge <?= $wa_status['running'] ? 'bg-success' : ($wa_status['has_node'] ? 'bg-warning text-dark' : 'bg-danger') ?>">
                                <?= $wa_status['running'] ? '✓ Running (Aktif)' : ($wa_status['has_node'] ? 'Node.js OK (Service Mati)' : 'Belum Terpasang') ?>
                            </span>
                        </div>
                        <div class="text-muted small mt-1">Port 3000 TCP — Engine Kirim Pesan &amp; Notifikasi Tagihan (Baileys)</div>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="btnWa" onclick="triggerInstall('whatsapp')">
                        <i class="bi bi-play-circle me-1"></i>Instal / Jalankan di Background
                    </button>
                </div>

                <!-- Tombol Pasang Semua Sekaligus -->
                <button type="button" class="btn btn-primary w-100 fw-bold py-2 shadow-sm mb-2" id="btnInstallAll" onclick="triggerInstallAll()">
                    <i class="bi bi-lightning-charge-fill me-1"></i>Instal &amp; Aktifkan Semua Layanan di Background Sekaligus
                </button>

                <!-- Terminal Log Real-time -->
                <div id="logConsoleContainer" class="mt-3" style="display:none;">
                    <div class="d-flex justify-content-between align-items-center bg-dark text-white px-3 py-1 rounded-top small">
                        <span class="font-monospace"><i class="bi bi-terminal me-1 text-success"></i>Live Output Instalasi:</span>
                        <span id="logStatusSpinner" class="spinner-border spinner-border-sm text-light"></span>
                    </div>
                    <pre id="logConsole" class="bg-black text-light p-3 rounded-bottom font-monospace" style="max-height:180px;overflow-y:auto;font-size:.73rem;white-space:pre-wrap;margin:0;"></pre>
                </div>

                <!-- Petunjuk Terminal SSH (Opsi Eksekusi Manual) -->
                <div class="mt-3 p-2 bg-light border rounded small">
                    <div class="fw-bold text-dark mb-1"><i class="bi bi-terminal me-1"></i>Opsi Terminal SSH (Jika Web Server Butuh Hak Akses Root):</div>
                    <div class="text-muted mb-1" style="font-size:.75rem;">Jika tombol di atas terhalang izin sudo www-data, jalankan perintah ini di terminal SSH:</div>
                    <div class="bg-dark text-light p-2 rounded font-monospace" style="font-size:.73rem;user-select:all;">
                        sudo bash <?= BASE_PATH ?>/setup_freeradius.sh &amp;&amp; sudo bash <?= BASE_PATH ?>/setup_wireguard.sh &amp;&amp; sudo bash <?= BASE_PATH ?>/setup_wa_service.sh
                    </div>
                    <div class="text-muted mt-1" style="font-size:.72rem;"><i class="bi bi-info-circle text-primary me-1"></i>Halaman ini mendeteksi service otomatis. Begitu perintah di terminal selesai, badge di atas akan langsung berubah hijau tanpa perlu reload.</div>
                </div>
            </div>
        </div>

        <!-- Step 2: Cron Jobs -->
        <div class="alert alert-info text-start p-3 small mb-4">
            <h6 class="fw-bold text-dark mb-2"><i class="bi bi-clock-history me-1"></i> Langkah 2 — Pasang Cron Job (cPanel / crontab -e):</h6>
            <p class="text-muted mb-2">Salin seluruh baris di bawah ini ke crontab server Anda. Ganti path PHP sesuai server Anda (contoh: <code>/usr/bin/php</code> atau <code>/www/server/php/81/bin/php</code>).</p>
            <div class="bg-dark text-light p-2 rounded font-monospace" style="font-size:.73rem;line-height:1.8;">
<pre class="mb-0 text-light"><?php
$phpBin = PHP_BINARY ?: '/usr/bin/php';
$base   = BASE_PATH;
echo "# ── S.NET Manager Cron Jobs ──\n";
echo "# [1] Bersihkan sesi hantu radacct vs MikroTik (setiap 1 menit)\n";
echo "* * * * *   {$phpBin} {$base}/cron/auto_clear_ghosts.php &gt;&gt; /var/log/snet_ghosts.log 2&gt;&amp;1\n\n";
echo "# [2] Expire voucher yang habis masa berlaku (setiap 5 menit)\n";
echo "*/5 * * * * {$phpBin} {$base}/cron/expire_vouchers.php &gt;&gt; /var/log/snet_expire.log 2&gt;&amp;1\n\n";
echo "# [3] Snapshot bandwidth bulanan tahan restart MikroTik (setiap 5 menit)\n";
echo "*/5 * * * * {$phpBin} {$base}/cron/cron_bandwidth_snapshot.php &gt;&gt; /var/log/snet_bw_snap.log 2&gt;&amp;1\n\n";
echo "# [4] Bersihkan akses remote ONT yang sudah kedaluwarsa (setiap 1 menit)\n";
echo "* * * * *   {$phpBin} {$base}/cron/cleanup_ont_remotes.php &gt;/dev/null 2&gt;&amp;1\n\n";
echo "# [5] Kirim reminder tagihan via WhatsApp (setiap hari jam 08:00)\n";
echo "0 8 * * *   {$phpBin} {$base}/cron/cron_pppoe_reminder.php &gt;&gt; /var/log/snet_wa_reminder.log 2&gt;&amp;1";
?></pre>
            </div>
            <div class="mt-2 text-muted" style="font-size:.75rem;">
                <i class="bi bi-shield-check text-success me-1"></i>
                Semua cron menggunakan <strong>lock file</strong> — aman dijalankan bersamaan, tidak akan tumpuk proses.
            </div>
        </div>

        <a href="/login.php" class="btn btn-primary btn-lg w-100" id="btnFinish" onclick="return confirmFinish(event)">
            <i class="bi bi-box-arrow-in-right me-2"></i> Masuk ke Admin Panel Sekarang
        </a>
    </div>
    <?php endif; ?>
</div>

<script>
let logInterval = null;
let lastServiceStatus = null;

function refreshServiceStatus() {
    fetch('install.php?action=service_status')
        .then(r => r.json())
        .then(data => {
            lastServiceStatus = data;
            if (data.freeradius) {
                const b = document.getElementById('badgeRadius');
                if (b) {
                    b.className = 'badge ' + data.freeradius.badge;
                    b.innerText = data.freeradius.label;
                }
            }
            if (data.wireguard) {
                const b = document.getElementById('badgeWg');
                if (b) {
                    b.className = 'badge ' + data.wireguard.badge;
                    b.innerText = data.wireguard.label;
                }
            }
            if (data.whatsapp) {
                const b = document.getElementById('badgeWa');
                if (b) {
                    b.className = 'badge ' + data.whatsapp.badge;
                    b.innerText = data.whatsapp.label;
                }
            }
            if (data.all_running) {
                const btnAll = document.getElementById('btnInstallAll');
                if (btnAll) {
                    btnAll.className = 'btn btn-success w-100 fw-bold py-2 shadow-sm mb-2';
                    btnAll.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i>Semua Layanan Sudah Aktif (Running)';
                }
            }
        }).catch(err => console.error(err));
}

// Auto-check status setiap 2.5 detik jika berada di step 7
if (document.getElementById('badgeRadius')) {
    setInterval(refreshServiceStatus, 2500);
}

function triggerInstall(service) {
    const consoleBox = document.getElementById('logConsoleContainer');
    if (consoleBox) consoleBox.style.display = 'block';

    const pre = document.getElementById('logConsole');
    if (pre) pre.innerText = '>>> Mengirim instruksi eksekusi latar belakang untuk ' + service + '...\n';

    const fd = new FormData();
    fd.append('action', 'install_bg_service');
    fd.append('service', service);

    fetch('install.php?action=install_bg_service', { method: 'POST', body: fd })
        .then(async r => {
            const raw = await r.text();
            try {
                return JSON.parse(raw);
            } catch(e) {
                return { success: false, raw: raw.replace(/<[^>]*>?/gm, '').trim() };
            }
        })
        .then(res => {
            if (res.need_ssh || !res.success) {
                if (pre) {
                    var scriptTarget = service === 'wireguard' ? 'setup_wireguard.sh' : (service === 'whatsapp' ? 'setup_wa_service.sh' : 'setup_freeradius.sh');
                    if (service === 'all') scriptTarget = 'setup_freeradius.sh && sudo bash <?= BASE_PATH ?>/setup_wireguard.sh && sudo bash <?= BASE_PATH ?>/setup_wa_service.sh';

                    pre.innerText = '>>> [PERINGATAN HAK AKSES ROOT / APACHE / AAPANEL]\n'
                        + (res.message || res.raw || 'Eksekusi langsung tidak diizinkan oleh PHP server.') + '\n\n'
                        + '>>> Web server (user www) tidak dapat menjalankan apt-get / instalasi paket root tanpa password.\n'
                        + '>>> Silakan buka Terminal SSH VPS Anda dan jalankan perintah ini (cukup copy-paste):\n\n'
                        + 'sudo bash <?= BASE_PATH ?>/' + scriptTarget + '\n\n'
                        + '>>> Halaman ini mendeteksi service otomatis. Begitu perintah di terminal selesai, status di atas akan langsung berubah menjadi HIJAU (Running)!\n';
                }
            } else {
                if (pre) pre.innerText += '>>> ' + (res.message || 'Proses berjalan di latar belakang...') + '\n';
                startLogPolling(service);
            }
        }).catch(err => {
            if (pre) pre.innerText += '>>> Info: ' + err + '\n';
        });
}

function triggerInstallAll() {
    triggerInstall('all');
}

function startLogPolling(service) {
    if (logInterval) clearInterval(logInterval);
    const spinner = document.getElementById('logStatusSpinner');
    if (spinner) spinner.style.display = 'inline-block';

    let count = 0;
    logInterval = setInterval(() => {
        count++;
        fetch('install.php?action=get_bg_log&service=' + service)
            .then(async r => {
                const raw = await r.text();
                try {
                    return JSON.parse(raw);
                } catch(e) {
                    return { log: raw };
                }
            })
            .then(data => {
                const pre = document.getElementById('logConsole');
                if (pre && data.log) {
                    pre.innerText = data.log;
                    pre.scrollTop = pre.scrollHeight;
                }
                refreshServiceStatus();
            }).catch(e => console.error(e));

        if (count > 90) { // Stop polling setelah ~3 menit
            clearInterval(logInterval);
            if (spinner) spinner.style.display = 'none';
        }
    }, 2000);
}

function confirmFinish(e) {
    if (lastServiceStatus && !lastServiceStatus.all_running) {
        var unactive = [];
        if (!lastServiceStatus.freeradius.running) unactive.push('FreeRADIUS');
        if (!lastServiceStatus.wireguard.running) unactive.push('WireGuard');
        if (!lastServiceStatus.whatsapp.running) unactive.push('WhatsApp');

        var ok = confirm('PERHATIAN:\nLayanan (' + unactive.join(', ') + ') belum berstatus Running.\n\nAnda tetap dapat masuk ke Admin Panel dan menyalakan/mengonfigurasinya nanti di menu Pengaturan.\n\nLanjutkan masuk ke Admin Panel sekarang?');
        if (!ok) {
            e.preventDefault();
            return false;
        }
    }
    return true;
}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
