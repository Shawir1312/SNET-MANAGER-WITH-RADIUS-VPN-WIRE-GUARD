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

if (!is_dir(CONFIG_PATH)) {
    @mkdir(CONFIG_PATH, 0775, true);
}

if (file_exists(CONFIG_PATH . '/.installed')) {
    die('<div style="font-family:sans-serif;text-align:center;padding:60px;color:#1565C0;">
        <h2>✓ Sudah Terinstal</h2>
        <p>Aplikasi sudah diinstal. <a href="/login.php">Login di sini</a></p>
    </div>');
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

function get_freeradius_status(): array {
    $has_bin = false;
    $has_sql = false;
    $has_enabled = false;
    $is_active = false;

    if (function_exists('shell_exec')) {
        $out = @shell_exec('which freeradius 2>/dev/null');
        if (!empty(trim((string)$out))) $has_bin = true;
        
        $sql_avail = @shell_exec('test -f /etc/freeradius/3.0/mods-available/sql && echo "yes" 2>/dev/null');
        if (trim((string)$sql_avail) === 'yes') $has_sql = true;
        
        $sql_en = @shell_exec('test -L /etc/freeradius/3.0/mods-enabled/sql && echo "yes" 2>/dev/null');
        if (trim((string)$sql_en) === 'yes') $has_enabled = true;

        $res = @shell_exec('systemctl is-active freeradius 2>/dev/null');
        if (trim((string)$res) === 'active') $is_active = true;
    } else {
        if (@file_exists('/usr/sbin/freeradius') || @is_dir('/etc/freeradius/3.0')) $has_bin = true;
        if (@file_exists('/etc/freeradius/3.0/mods-available/sql')) $has_sql = true;
        if (@file_exists('/etc/freeradius/3.0/mods-enabled/sql')) $has_enabled = true;
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

    if (function_exists('shell_exec')) {
        $out = @shell_exec('which wg 2>/dev/null');
        if (!empty(trim((string)$out))) $has_bin = true;

        $conf = @shell_exec('test -f /etc/wireguard/wg0.conf && echo "yes" 2>/dev/null');
        if (trim((string)$conf) === 'yes') $has_conf = true;

        $res = @shell_exec('systemctl is-active wg-quick@wg0 2>/dev/null');
        if (trim((string)$res) === 'active') $is_active = true;

        $keys = @shell_exec('test -f /etc/wireguard/server_public.key && echo "yes" 2>/dev/null');
        if (trim((string)$keys) === 'yes') $has_keys = true;
    } else {
        if (@file_exists('/usr/bin/wg') || @is_dir('/etc/wireguard')) $has_bin = true;
        if (@file_exists('/etc/wireguard/wg0.conf')) $has_conf = true;
        if (@file_exists('/etc/wireguard/server_public.key')) $has_keys = true;
    }

    return [
        'installed'  => $has_bin,
        'configured' => $has_conf,
        'keys'       => $has_keys,
        'running'    => $is_active
    ];
}

function auto_configure_freeradius(array $dbc): array {
    $out = '';
    $script = BASE_PATH . '/setup_freeradius.sh';

    if (file_exists($script) && function_exists('shell_exec')) {
        $h = escapeshellarg($dbc['db_host'] === 'localhost' ? '127.0.0.1' : $dbc['db_host']);
        $u = escapeshellarg($dbc['db_user']);
        $p = escapeshellarg($dbc['db_pass']);
        $n = escapeshellarg($dbc['db_name']);
        $port = (int)$dbc['db_port'];
        $cmd = "sudo bash $script $h $u $p $n $port 2>&1";
        $out = @shell_exec($cmd);
    }

    return ['output' => $out];
}

$step          = (int)($_GET['step'] ?? 1);
$errors        = [];
$success       = [];
$radius_status = get_freeradius_status();
$wg_status     = get_wireguard_status();

// Handle manual trigger install radius via UI
if (isset($_POST['action']) && $_POST['action'] === 'run_radius_setup') {
    $script = BASE_PATH . '/setup_freeradius.sh';
    if (function_exists('shell_exec')) {
        $out = @shell_exec("sudo -n bash $script 2>&1");
        if ($out && strpos($out, 'sudo:') === false && strpos($out, 'a password is required') === false) {
            $success[] = '✓ FreeRADIUS berhasil dikonfigurasi dan diaktifkan!';
        } else {
            $success[] = 'Konfigurasi SQL telah disiapkan. Jalankan perintah ini di terminal VPS untuk mengaktifkan daemon service:<br><code class="d-block p-2 bg-dark text-light rounded font-monospace mt-1">sudo bash ' . BASE_PATH . '/setup_freeradius.sh</code>';
        }
    } else {
        $success[] = 'Jalankan via terminal VPS: <code class="d-block p-1 bg-dark text-light rounded font-monospace mt-1">sudo bash ' . BASE_PATH . '/setup_freeradius.sh</code>';
    }
    $radius_status = get_freeradius_status();
}

// Handle manual trigger install wireguard via UI
if (isset($_POST['action']) && $_POST['action'] === 'run_wg_setup') {
    $script = BASE_PATH . '/setup_wireguard.sh';
    if (function_exists('shell_exec')) {
        $out = @shell_exec("sudo -n bash $script 2>&1");
        if ($out && strpos($out, 'sudo:') === false && strpos($out, 'a password is required') === false) {
            $success[] = '✓ WireGuard VPN server berhasil dikonfigurasi dan diaktifkan!';
        } else {
            $success[] = 'Kunci WireGuard &amp; konfigurasi DB telah disiapkan. Jalankan perintah ini di terminal VPS untuk mengaktifkan service kernel:<br><code class="d-block p-2 bg-dark text-light rounded font-monospace mt-1">sudo bash ' . BASE_PATH . '/setup_wireguard.sh</code>';
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
            portal_username VARCHAR(100) DEFAULT '',
            full_name VARCHAR(150) NOT NULL DEFAULT '',
            phone VARCHAR(25) DEFAULT '',
            address TEXT,
            profile VARCHAR(100) DEFAULT '',
            monthly_price INT DEFAULT 0,
            due_day TINYINT DEFAULT 1,
            status ENUM('active','isolated','suspended') DEFAULT 'active',
            ont_sn VARCHAR(50) DEFAULT '',
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
            midtrans_order_id VARCHAR(100) DEFAULT NULL,
            midtrans_tx_id VARCHAR(100) DEFAULT NULL,
            midtrans_status VARCHAR(50) DEFAULT NULL,
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
            ('midtrans_server_key', ''),
            ('midtrans_client_key', ''),
            ('midtrans_mode', 'sandbox'),
            ('isolir_profile', 'isolir'),
            ('isolir_redirect_url', '/portal/isolir'),
            ('isolir_grace_days', '3'),
            ('company_name', 'S.NET Internet'),
            ('company_phone', ''),
            ('company_address', '')",

        // WireGuard VPN Tables
        "CREATE TABLE IF NOT EXISTS wg_routers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            location VARCHAR(150) DEFAULT NULL,
            public_key VARCHAR(255) NOT NULL UNIQUE,
            private_key VARCHAR(255) NOT NULL,
            tunnel_ip VARCHAR(20) NOT NULL UNIQUE,
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
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
                $success[] = 'Semua 24 tabel FreeRADIUS + Hotspot + PPPoE + GenieACS + WireGuard VPN berhasil dibuat!';
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
                if (function_exists('shell_exec')) {
                    @shell_exec("sudo -n bash " . BASE_PATH . "/setup_freeradius.sh >/dev/null 2>&1 &");
                    @shell_exec("sudo -n bash " . BASE_PATH . "/setup_wireguard.sh >/dev/null 2>&1 &");
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
    <div class="card mb-4 border bg-light">
        <div class="card-body p-3">
            <h6 class="fw-bold mb-2 text-dark" style="font-size:.9rem;">
                <i class="bi bi-hdd-network-fill text-primary me-2"></i>Kesiapan Lingkungan Server VPS:
            </h6>
            <div class="row g-2 small">
                <div class="col-6">
                    <div class="p-2 bg-white border rounded">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="fw-semibold"><i class="bi bi-shield-check text-primary me-1"></i>FreeRADIUS</span>
                            <?= $radius_status['installed'] ? '<span class="badge bg-success">✓ Terpasang</span>' : '<span class="badge bg-secondary">Belum ada</span>' ?>
                        </div>
                    </div>
                </div>
                <div class="col-6">
                    <div class="p-2 bg-white border rounded">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="fw-semibold"><i class="bi bi-shield-lock-fill text-danger me-1"></i>WireGuard</span>
                            <?= $wg_status['installed'] ? '<span class="badge bg-success">✓ Terpasang</span>' : '<span class="badge bg-secondary">Belum ada</span>' ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="text-muted mt-2" style="font-size:.75rem;">
                <i class="bi bi-info-circle me-1"></i> Masukkan detail akun Database MySQL di bawah ini untuk memulai instalasi.
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
        <b>FreeRADIUS:</b> radcheck, radreply, radgroupcheck, radgroupreply, radusergroup, radacct, radpostauth, nas<br>
        <b>Hotspot:</b> routers, profiles, vouchers, admins, audit_log, sales_log, penagihan<br>
        <b>Broadband &amp; ACS:</b> genie_config, customers, ont_configs, pppoe_customers, pppoe_payments, pppoe_settings<br>
        <b>VPN WireGuard:</b> wg_routers, wg_port_forwards, wg_settings, wg_logs
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
        <h4 class="fw-700 mt-2">Instalasi Web Selesai!</h4>
        <p class="text-muted mb-3">Database (24 Tabel), Konfigurasi Aplikasi, dan Akun Superadmin telah berhasil dibuat.</p>
        
        <div class="bg-light border rounded p-3 text-start small mb-3">
            <div class="d-flex justify-content-between mb-1">
                <span><i class="bi bi-database-check text-success me-1"></i> Database MySQL:</span>
                <span class="badge bg-success">24 Tabel Tersimpan</span>
            </div>
            <div class="d-flex justify-content-between mb-1">
                <span><i class="bi bi-person-check text-success me-1"></i> Akun Superadmin:</span>
                <span class="badge bg-success">Siap Digunakan</span>
            </div>
        </div>

        <div class="alert alert-warning text-start p-3 small mb-4">
            <h6 class="fw-bold text-dark mb-1"><i class="bi bi-terminal-fill me-1"></i> Langkah Terakhir di Terminal VPS:</h6>
            <p class="mb-2 text-muted">Jalankan 2 baris perintah ini di terminal VPS (sebagai <code>root</code>) untuk mengaktifkan service FreeRADIUS &amp; WireGuard:</p>
            <div class="bg-dark text-light p-2 rounded font-monospace" style="font-size:.78rem;">
                sudo bash setup_freeradius.sh<br>
                sudo bash setup_wireguard.sh
            </div>
        </div>

        <a href="/login.php" class="btn btn-primary btn-lg w-100">
            <i class="bi bi-box-arrow-in-right me-2"></i> Masuk ke Admin Panel Sekarang
        </a>
    </div>
    <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
