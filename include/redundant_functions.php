<?php
/**
 * S.NET RADIUS Manager — Redundant Server & Real-time Database Sync Engine (Full PHP)
 * 
 * Modul replikasi dan sinkronisasi mandiri berbasis PHP murni:
 * - Menghubungkan Cloud VPS (Master) dan Mini PC Cabang (Edge Node)
 * - Sinkronisasi dua arah: Voucher, Profil, Router, Klien NAS, radcheck, radreply, radacct
 * - Auto-reload FreeRADIUS di Node lokal saat data baru masuk
 */

if (!defined('IN_APP')) { define('IN_APP', true); }

/**
 * Pastikan tabel-tabel redundansi tersedia di database
 */
function ensure_redundant_tables(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        db_execute("CREATE TABLE IF NOT EXISTS redundant_settings (
            `key` VARCHAR(100) NOT NULL PRIMARY KEY,
            `value` TEXT NOT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        db_execute("CREATE TABLE IF NOT EXISTS redundant_nodes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            node_name VARCHAR(100) NOT NULL,
            node_code VARCHAR(50) NOT NULL UNIQUE,
            router_id INT DEFAULT 0,
            node_ip VARCHAR(50) DEFAULT NULL,
            api_key VARCHAR(64) NOT NULL,
            status ENUM('active', 'inactive') DEFAULT 'active',
            last_sync_at DATETIME DEFAULT NULL,
            last_sync_status VARCHAR(255) DEFAULT NULL,
            total_vouchers_synced INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        db_execute("CREATE TABLE IF NOT EXISTS redundant_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            node_code VARCHAR(50) NOT NULL,
            direction ENUM('push', 'pull', 'ping') NOT NULL,
            status ENUM('success', 'failed') NOT NULL,
            summary VARCHAR(255) NOT NULL,
            details TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Pastikan tabel inti RADIUS & aplikasi tersedia (apabila database Mini PC baru dibuat di aaPanel)
        db_execute("CREATE TABLE IF NOT EXISTS radcheck (
            id int(11) unsigned NOT NULL AUTO_INCREMENT,
            username varchar(64) NOT NULL DEFAULT '',
            attribute varchar(64) NOT NULL DEFAULT '',
            op char(2) NOT NULL DEFAULT ':=',
            value varchar(253) NOT NULL DEFAULT '',
            PRIMARY KEY (id),
            KEY username (username(32))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        db_execute("CREATE TABLE IF NOT EXISTS radreply (
            id int(11) unsigned NOT NULL AUTO_INCREMENT,
            username varchar(64) NOT NULL DEFAULT '',
            attribute varchar(64) NOT NULL DEFAULT '',
            op char(2) NOT NULL DEFAULT '=',
            value varchar(253) NOT NULL DEFAULT '',
            PRIMARY KEY (id),
            KEY username (username(32))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        db_execute("CREATE TABLE IF NOT EXISTS radgroupcheck (
            id int(11) unsigned NOT NULL AUTO_INCREMENT,
            groupname varchar(64) NOT NULL DEFAULT '',
            attribute varchar(64) NOT NULL DEFAULT '',
            op char(2) NOT NULL DEFAULT ':=',
            value varchar(253) NOT NULL DEFAULT '',
            PRIMARY KEY (id),
            KEY groupname (groupname(32))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        db_execute("CREATE TABLE IF NOT EXISTS radgroupreply (
            id int(11) unsigned NOT NULL AUTO_INCREMENT,
            groupname varchar(64) NOT NULL DEFAULT '',
            attribute varchar(64) NOT NULL DEFAULT '',
            op char(2) NOT NULL DEFAULT '=',
            value varchar(253) NOT NULL DEFAULT '',
            PRIMARY KEY (id),
            KEY groupname (groupname(32))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        db_execute("CREATE TABLE IF NOT EXISTS radusergroup (
            username varchar(64) NOT NULL DEFAULT '',
            groupname varchar(64) NOT NULL DEFAULT '',
            priority int(11) NOT NULL DEFAULT 1,
            KEY username (username(32))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        db_execute("CREATE TABLE IF NOT EXISTS radacct (
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
            PRIMARY KEY (radacctid),
            UNIQUE KEY acctuniqueid (acctuniqueid),
            KEY username (username),
            KEY nasipaddress (nasipaddress),
            KEY acctsessionid (acctsessionid),
            KEY acctstarttime (acctstarttime)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        db_execute("CREATE TABLE IF NOT EXISTS nas (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        db_execute("CREATE TABLE IF NOT EXISTS routers (
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
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        db_execute("CREATE TABLE IF NOT EXISTS profiles (
            id int(11) NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL,
            display_name varchar(100) DEFAULT NULL,
            validity_value int(11) DEFAULT 30,
            validity_unit varchar(32) DEFAULT 'days',
            duration_value int(11) DEFAULT 30,
            duration_unit varchar(32) DEFAULT 'days',
            quota_mb bigint(20) DEFAULT 0,
            rate_up varchar(20) DEFAULT '0',
            rate_down varchar(20) DEFAULT '0',
            price decimal(10,2) DEFAULT 0.00,
            router_id int(11) DEFAULT NULL,
            is_active tinyint(1) DEFAULT 1,
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Perbaiki tipe data jika tabel sudah pernah dibuat sebelumnya dengan ENUM
        @db_execute("ALTER TABLE profiles MODIFY COLUMN validity_unit VARCHAR(32) DEFAULT 'days', MODIFY COLUMN duration_unit VARCHAR(32) DEFAULT 'days'");

        db_execute("CREATE TABLE IF NOT EXISTS vouchers (
            id int(11) NOT NULL AUTO_INCREMENT,
            username varchar(64) NOT NULL,
            password varchar(128) NOT NULL,
            profile_id int(11) NOT NULL,
            router_id int(11) DEFAULT NULL,
            batch_id varchar(64) DEFAULT NULL,
            status varchar(20) DEFAULT 'unused',
            used_at datetime DEFAULT NULL,
            expired_at datetime DEFAULT NULL,
            generated_by int(11) DEFAULT NULL,
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY username (username),
            KEY status (status),
            KEY router_id (router_id),
            KEY batch_id (batch_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        @db_execute("ALTER TABLE vouchers MODIFY COLUMN status VARCHAR(20) DEFAULT 'unused'");
        @db_execute("ALTER TABLE vouchers ADD COLUMN IF NOT EXISTS router_id INT(11) DEFAULT NULL");
        @db_execute("ALTER TABLE vouchers ADD COLUMN IF NOT EXISTS generated_by INT(11) DEFAULT NULL");

        // Kolom pelacak replikasi: 0 = dibuat lokal di node cabang & belum terdorong ke VPS, 1 = sudah sinkron
        try {
            $hasSyncedCol = (bool)db_fetch_one("SHOW COLUMNS FROM vouchers LIKE 'synced_to_master'");
            if (!$hasSyncedCol) {
                @db_execute("ALTER TABLE vouchers ADD COLUMN synced_to_master TINYINT(1) DEFAULT 0, ADD INDEX (synced_to_master)");
                // Tandai seluruh voucher lama yang sudah ada di database sebagai sudah tersinkron
                @db_execute("UPDATE vouchers SET synced_to_master = 1 WHERE status != 'unused' OR created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
            }
        } catch (Throwable $e) {}

        try {
            $hasSalesSyncedCol = (bool)db_fetch_one("SHOW COLUMNS FROM sales_log LIKE 'synced_to_master'");
            if (!$hasSalesSyncedCol) {
                @db_execute("ALTER TABLE sales_log ADD COLUMN synced_to_master TINYINT(1) DEFAULT 0, ADD INDEX (synced_to_master)");
                @db_execute("UPDATE sales_log SET synced_to_master = 1 WHERE sold_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
            }
        } catch (Throwable $e) {}

        db_execute("CREATE TABLE IF NOT EXISTS sales_log (
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
            KEY sold_at (sold_at),
            KEY voucher_username (voucher_username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        @db_execute("ALTER TABLE sales_log ADD COLUMN IF NOT EXISTS voucher_username VARCHAR(64) DEFAULT NULL");
        @db_execute("ALTER TABLE sales_log ADD COLUMN IF NOT EXISTS profile_name VARCHAR(100) DEFAULT NULL");
        @db_execute("ALTER TABLE sales_log ADD COLUMN IF NOT EXISTS sold_by INT(11) DEFAULT NULL");

        db_execute("CREATE TABLE IF NOT EXISTS penagihan (
            id int(11) NOT NULL AUTO_INCREMENT,
            router_id int(11) NOT NULL,
            profile_id int(11) NOT NULL,
            total_pendapatan decimal(15,2) DEFAULT 0.00,
            bagian_reseller decimal(15,2) DEFAULT 0.00,
            pendapatan_bersih decimal(15,2) DEFAULT 0.00,
            estimasi_voucher int(11) DEFAULT 0,
            voucher_aktual int(11) DEFAULT 0,
            status_kecocokan varchar(20) DEFAULT 'sesuai',
            catatan text,
            ditagih_oleh int(11) DEFAULT NULL,
            tanggal date NOT NULL,
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY router_id (router_id),
            KEY profile_id (profile_id),
            KEY tanggal (tanggal)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        db_execute("CREATE TABLE IF NOT EXISTS pppoe_customers (
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
            status VARCHAR(20) DEFAULT 'active',
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
            UNIQUE KEY uk_router_user (router_id, pppoe_username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        db_execute("CREATE TABLE IF NOT EXISTS pppoe_payments (
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
            created_by INT DEFAULT NULL,
            notes TEXT,
            PRIMARY KEY (id),
            KEY customer_id (customer_id),
            KEY period (period_year, period_month)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        db_execute("CREATE TABLE IF NOT EXISTS pppoe_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) NOT NULL UNIQUE,
            setting_value TEXT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        db_execute("CREATE TABLE IF NOT EXISTS admins (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $admCount = db_fetch_one("SELECT COUNT(*) as c FROM admins");
        if (empty($admCount['c'])) {
            $defPass = password_hash('admin123', PASSWORD_BCRYPT);
            db_execute("INSERT INTO admins (username, password, full_name, role) VALUES ('admin', ?, 'Administrator', 'superadmin')", 's', [$defPass]);
        }
    } catch (Throwable $e) {}
}

/**
 * Ambil seluruh pengaturan redundansi server
 */
function get_redundant_settings(): array {
    ensure_redundant_tables();
    $defaults = [
        'redundant_mode'        => 'master',          // 'master' (Cloud VPS) atau 'node' (Mini PC Lokal)
        'node_code'             => 'NODE_LUSUO',     // Kode unik node ini (jika mode node)
        'node_name'             => 'Mini PC Desa Lusuo',
        'master_url'            => 'https://10.77.77.1', // URL Master VPS via WireGuard (gunakan https://10.77.77.1 atau https://portal.snetwifi.com)
        'api_key'               => '',               // API Key untuk autentikasi ke Master
        'auto_sync_interval'    => '1',              // Menit (1, 2, 5)
        'last_sync_at'          => '',
        'last_sync_status'      => '',
        'sync_vouchers'         => '1',
        'sync_pppoe'            => '1',
        'sync_accounting'       => '1',
    ];

    try {
        $rows = db_fetch_all("SELECT `key`, `value` FROM redundant_settings");
        foreach ($rows as $r) {
            $defaults[$r['key']] = $r['value'];
        }
    } catch (Throwable $e) {}

    return $defaults;
}

/**
 * Simpan pengaturan redundansi server
 */
function save_redundant_settings(array $settings): bool {
    ensure_redundant_tables();
    try {
        foreach ($settings as $k => $v) {
            db_execute("INSERT INTO redundant_settings (`key`, `value`) VALUES (?, ?)
                        ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)", 'ss', [$k, (string)$v]);
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Periksa apakah server saat ini berjalan sebagai Node Lokal
 */
function is_redundant_node(): bool {
    $st = get_redundant_settings();
    return ($st['redundant_mode'] ?? 'master') === 'node';
}

/**
 * Logika Penentu Eksekusi Cron Pemeliharaan (Hapus Sesi Hantu & Expire Voucher):
 * - Di Master VPS: Selalu BERJALAN AKTIF (return true).
 * - Di Node Mini PC: STANDBY PASIF saat VPS normal; OTOMATIS AMBIL ALIH (return true) HANYA jika Master VPS MATI/OFFLINE.
 */
function should_run_maintenance_cron(): bool {
    // 1. Jika ini adalah Master VPS, selalu jalankan
    if (!is_redundant_node()) {
        return true;
    }

    // 2. Jika ini adalah Node Mini PC, periksa status VPS Master
    $cfg = get_redundant_settings();
    $vpsStatus = $cfg['master_vps_status'] ?? 'online';

    // Node Cabang HANYA mengambil alih pembersihan sesi lokal jika Master VPS terkonfirmasi OFFLINE
    if ($vpsStatus === 'offline') {
        return true;
    }

    return false;
}

/**
 * Generate random secure 32-char hex API Key
 */
function generate_redundant_key(): string {
    return bin2hex(random_bytes(16));
}

/**
 * Catat log aktivitas sinkronisasi
 */
function log_redundant_sync(string $nodeCode, string $direction, string $status, string $summary, ?string $details = null): void {
    ensure_redundant_tables();
    try {
        db_execute("INSERT INTO redundant_logs (node_code, direction, status, summary, details, created_at)
                    VALUES (?, ?, ?, ?, ?, NOW())",
                    'sssss', [$nodeCode, $direction, $status, $summary, $details]);
    } catch (Throwable $e) {}
}

/**
 * Eksekusi HTTP Request ke Master VPS dengan proteksi SSL, auto-redirect, dan auto-fallback ke HTTPS jika HTTP 404
 */
function redundant_http_request(string $endpoint, array $headers = [], ?string $postBody = null, int $timeout = 30): array {
    $execute = function(string $url) use ($headers, $postBody, $timeout) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        if (defined('CURLOPT_POSTREDIR')) {
            curl_setopt($ch, CURLOPT_POSTREDIR, 7); // Preserves POST method and body across 301/302 redirects
        }
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if ($postBody !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postBody);
        }
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);
        return [
            'http_code' => $httpCode,
            'response'  => (string)$response,
            'error'     => $curlErr,
            'final_url' => $url
        ];
    };

    $res = $execute($endpoint);

    // Jika menggunakan http:// dan menerima HTTP 404 atau koneksi gagal, coba otomatis dengan https://
    if (($res['http_code'] === 404 || !empty($res['error'])) && strpos($endpoint, 'http://') === 0) {
        $httpsUrl = 'https://' . substr($endpoint, 7);
        $resHttps = $execute($httpsUrl);
        if ($resHttps['http_code'] === 200 || ($resHttps['http_code'] > 0 && empty($resHttps['error']) && $resHttps['http_code'] !== 404)) {
            // Berhasil dengan HTTPS, simpan master_url baru agar berikutnya otomatis langsung pakai HTTPS
            $parsed = parse_url($httpsUrl);
            $newBase = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '') . (!empty($parsed['port']) ? ':' . $parsed['port'] : '');
            save_redundant_settings(['master_url' => $newBase]);
            return $resHttps;
        }
    }

    return $res;
}

/**
 * PULL DATA DARI MASTER VPS (Dijalankan oleh Mini PC Node)
 * Mengambil katalog profil, voucher aktif/unused, router, dan nas dari VPS ke database lokal
 */
function redundant_pull_from_master(): array {
    ensure_redundant_tables();
    @ini_set('memory_limit', (php_sapi_name() === 'cli' ? '-1' : '1024M'));
    @set_time_limit(300);

    $cfg = get_redundant_settings();
    $masterUrl = rtrim($cfg['master_url'] ?? 'https://10.77.77.1', '/');
    $apiKey    = trim($cfg['api_key'] ?? '');
    $nodeCode  = trim($cfg['node_code'] ?? 'NODE_CABANG');

    if (!$apiKey) {
        return ['success' => false, 'message' => 'API Key belum diisi di Pengaturan Redundant.'];
    }

    $endpoint = $masterUrl . '/api/redundant_sync.php?action=pull_catalog';
    
    // HTTP Request via cURL helper
    $req = redundant_http_request($endpoint, [
        'X-Redundant-Key: ' . $apiKey,
        'X-Node-Code: ' . $nodeCode,
        'Accept: application/json'
    ], null, 120);

    $httpCode = $req['http_code'];
    $response = $req['response'];
    $curlErr  = $req['error'];

    if ($curlErr) {
        $fails = (int)($cfg['vps_fail_count'] ?? 0) + 1;
        $updates = ['vps_fail_count' => (string)$fails];
        if ($fails >= 3) {
            $updates['master_vps_status'] = 'offline';
        }
        save_redundant_settings($updates);
        log_redundant_sync($nodeCode, 'pull', 'failed', "Gagal koneksi ke Master: {$curlErr} (Percobaan {$fails}/3)");
        return ['success' => false, 'message' => "Gagal terhubung ke Master VPS ({$masterUrl}): {$curlErr} (Percobaan {$fails}/3)"];
    }

    if ($httpCode !== 200) {
        $fails = (int)($cfg['vps_fail_count'] ?? 0) + 1;
        $updates = ['vps_fail_count' => (string)$fails];
        if ($fails >= 3) {
            $updates['master_vps_status'] = 'offline';
        }
        save_redundant_settings($updates);
        $resJson = json_decode((string)$response, true);
        $errMsg = !empty($resJson['message']) ? $resJson['message'] : trim(substr(strip_tags((string)$response), 0, 150));
        log_redundant_sync($nodeCode, 'pull', 'failed', "HTTP {$httpCode}: " . ($errMsg ?: 'Koneksi ditutup oleh server'));
        return ['success' => false, 'message' => "Master VPS merespons HTTP {$httpCode}: " . ($errMsg ?: 'Koneksi ditutup oleh server')];
    }

    $data = json_decode((string)$response, true);
    if (!$data || empty($data['success'])) {
        $msg = $data['message'] ?? 'Format data JSON tidak valid dari Master.';
        log_redundant_sync($nodeCode, 'pull', 'failed', $msg);
        return ['success' => false, 'message' => $msg];
    }

    $payload = $data['payload'] ?? [];
    $vouchers = $payload['vouchers'] ?? [];
    $profiles = $payload['profiles'] ?? [];
    $routers  = $payload['routers'] ?? [];
    $nas      = $payload['nas'] ?? [];
    $radcheck = $payload['radcheck'] ?? [];
    $radreply = $payload['radreply'] ?? [];

    $db = db();
    @$db->query("SET FOREIGN_KEY_CHECKS = 0;");
    $db->begin_transaction();
    try {
        // 1. Sinkronisasi Profiles
        if (!empty($profiles)) {
            foreach ($profiles as $p) {
                $pid = (int)$p['id'];
                $pname = (string)$p['name'];
                $pprice = (float)($p['price'] ?? 0);
                $pvv = (int)($p['validity_value'] ?? 30);
                $pvu = !empty($p['validity_unit']) ? (string)$p['validity_unit'] : 'days';
                $pdv = (int)($p['duration_value'] ?? 30);
                $pdu = !empty($p['duration_unit']) ? (string)$p['duration_unit'] : 'days';
                $pru = (string)($p['rate_up'] ?? '0');
                $prd = (string)($p['rate_down'] ?? '0');
                $pqm = (int)($p['quota_mb'] ?? 0);
                $prid = !empty($p['router_id']) ? (int)$p['router_id'] : null;

                db_execute("INSERT INTO profiles (id, name, price, validity_value, validity_unit, duration_value, duration_unit, rate_up, rate_down, quota_mb, router_id)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE name=VALUES(name), price=VALUES(price), validity_value=VALUES(validity_value), validity_unit=VALUES(validity_unit), duration_value=VALUES(duration_value), duration_unit=VALUES(duration_unit), rate_up=VALUES(rate_up), rate_down=VALUES(rate_down), quota_mb=VALUES(quota_mb)",
                            'isdisisssii', [
                                $pid, $pname, $pprice, $pvv, $pvu,
                                $pdv, $pdu, $pru, $prd,
                                $pqm, $prid
                            ]);
            }
        }

        // 2. Sinkronisasi Routers & NAS
        if (!empty($routers)) {
            foreach ($routers as $r) {
                db_execute("INSERT INTO routers (id, name, ip_address, nas_ip, radius_secret, status)
                            VALUES (?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE name=VALUES(name), ip_address=VALUES(ip_address), nas_ip=VALUES(nas_ip), radius_secret=VALUES(radius_secret), status=VALUES(status)",
                            'isssss', [
                                $r['id'], $r['name'], $r['ip_address'], $r['nas_ip'], $r['radius_secret'], $r['status']
                            ]);
            }
        }

        if (!empty($nas)) {
            foreach ($nas as $n) {
                db_execute("INSERT INTO nas (id, nasname, shortname, type, secret, description)
                            VALUES (?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE nasname=VALUES(nasname), shortname=VALUES(shortname), secret=VALUES(secret)",
                            'isssss', [
                                $n['id'], $n['nasname'], $n['shortname'], $n['type'], $n['secret'], $n['description']
                            ]);
            }
        }

        // 3. Sinkronisasi Vouchers (Batching 500 baris per query agar proses kilat)
        $vCount = 0;
        if (!empty($vouchers)) {
            $hasSyncedCol = (bool)db_fetch_one("SHOW COLUMNS FROM vouchers LIKE 'synced_to_master'");
            unset($payload['vouchers']);
            $chunks = array_chunk($vouchers, 500);
            unset($vouchers);
            foreach ($chunks as $k => $chunk) {
                $values = [];
                $params = [];
                $types = '';
                foreach ($chunk as $v) {
                    $values[] = $hasSyncedCol ? "(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)" : "(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $params[] = (int)$v['id'];
                    $params[] = (string)$v['username'];
                    $params[] = (string)$v['password'];
                    $params[] = (int)$v['profile_id'];
                    $params[] = !empty($v['router_id']) ? (int)$v['router_id'] : null;
                    $params[] = !empty($v['batch_id']) ? (string)$v['batch_id'] : null;
                    $params[] = !empty($v['status']) ? (string)$v['status'] : 'unused';
                    $params[] = !empty($v['used_at']) ? (string)$v['used_at'] : null;
                    $params[] = !empty($v['expired_at']) ? (string)$v['expired_at'] : null;
                    $params[] = !empty($v['generated_by']) ? (int)$v['generated_by'] : null;
                    $params[] = !empty($v['created_at']) ? (string)$v['created_at'] : date('Y-m-d H:i:s');
                    $types .= 'issiissssis';
                }
                $syncedUpdate = $hasSyncedCol ? ", synced_to_master=1" : "";
                $syncedColSql = $hasSyncedCol ? ", synced_to_master" : "";
                $sql = "INSERT INTO vouchers (id, username, password, profile_id, router_id, batch_id, status, used_at, expired_at, generated_by, created_at{$syncedColSql})
                        VALUES " . implode(', ', $values) . "
                        ON DUPLICATE KEY UPDATE password=VALUES(password), profile_id=VALUES(profile_id), router_id=VALUES(router_id), batch_id=VALUES(batch_id), status=VALUES(status), used_at=VALUES(used_at), expired_at=VALUES(expired_at), generated_by=VALUES(generated_by){$syncedUpdate}";
                $stmt = $db->prepare($sql);
                if ($stmt) {
                    $bindArgs = [$types];
                    foreach ($params as $kp => $val) {
                        $bindArgs[] = &$params[$kp];
                    }
                    call_user_func_array([$stmt, 'bind_param'], $bindArgs);
                    $stmt->execute();
                    $stmt->close();
                }
                $vCount += count($chunk);
                unset($chunks[$k]);
            }
            unset($chunks);
        }

        // 4. Sinkronisasi radcheck & radreply FreeRADIUS (Batching 500 baris)
        if (!empty($radcheck)) {
            unset($payload['radcheck']);
            $rcChunks = array_chunk($radcheck, 500);
            unset($radcheck);
            foreach ($rcChunks as $k => $chunk) {
                $values = [];
                $params = [];
                $types = '';
                foreach ($chunk as $rc) {
                    $values[] = "(?, ?, ?, ?)";
                    $params[] = (string)$rc['username'];
                    $params[] = (string)$rc['attribute'];
                    $params[] = (string)$rc['op'];
                    $params[] = (string)$rc['value'];
                    $types .= 'ssss';
                }
                $sql = "INSERT INTO radcheck (username, attribute, op, value)
                        VALUES " . implode(', ', $values) . "
                        ON DUPLICATE KEY UPDATE value=VALUES(value)";
                $stmt = $db->prepare($sql);
                if ($stmt) {
                    $bindArgs = [$types];
                    foreach ($params as $kp => $val) {
                        $bindArgs[] = &$params[$kp];
                    }
                    call_user_func_array([$stmt, 'bind_param'], $bindArgs);
                    $stmt->execute();
                    $stmt->close();
                }
                unset($rcChunks[$k]);
            }
            unset($rcChunks);
        }

        if (!empty($radreply)) {
            unset($payload['radreply']);
            $rrChunks = array_chunk($radreply, 500);
            unset($radreply);
            foreach ($rrChunks as $k => $chunk) {
                $values = [];
                $params = [];
                $types = '';
                foreach ($chunk as $rr) {
                    $values[] = "(?, ?, ?, ?)";
                    $params[] = (string)$rr['username'];
                    $params[] = (string)$rr['attribute'];
                    $params[] = (string)$rr['op'];
                    $params[] = (string)$rr['value'];
                    $types .= 'ssss';
                }
                $sql = "INSERT INTO radreply (username, attribute, op, value)
                        VALUES " . implode(', ', $values) . "
                        ON DUPLICATE KEY UPDATE value=VALUES(value)";
                $stmt = $db->prepare($sql);
                if ($stmt) {
                    $bindArgs = [$types];
                    foreach ($params as $kp => $val) {
                        $bindArgs[] = &$params[$kp];
                    }
                    call_user_func_array([$stmt, 'bind_param'], $bindArgs);
                    $stmt->execute();
                    $stmt->close();
                }
                unset($rrChunks[$k]);
            }
            unset($rrChunks);
        }

        // 5. Sinkronisasi Akun Admin Superadmin dari VPS
        $admins = $payload['admins'] ?? [];
        if (!empty($admins)) {
            foreach ($admins as $adm) {
                db_execute("INSERT INTO admins (id, username, password, full_name, role, is_active)
                            VALUES (?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE password=VALUES(password), full_name=VALUES(full_name), role=VALUES(role), is_active=VALUES(is_active)",
                            'issssi', [$adm['id'], $adm['username'], $adm['password'], $adm['full_name'], $adm['role'], $adm['is_active']]);
            }
        }

        // 6. Sinkronisasi Riwayat Penjualan Hotspot (sales_log) (Batching 500 baris)
        $sales_log = $payload['sales_log'] ?? [];
        $salesCount = 0;
        if (!empty($sales_log)) {
            $hasSalesSyncedCol = (bool)db_fetch_one("SHOW COLUMNS FROM sales_log LIKE 'synced_to_master'");
            unset($payload['sales_log']);
            $slChunks = array_chunk($sales_log, 500);
            unset($sales_log);
            foreach ($slChunks as $k => $chunk) {
                $values = [];
                $params = [];
                $types = '';
                foreach ($chunk as $sl) {
                    $values[] = $hasSalesSyncedCol ? "(?, ?, ?, ?, ?, ?, ?, ?, ?, 1)" : "(?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $params[] = (int)$sl['id'];
                    $params[] = !empty($sl['voucher_id']) ? (int)$sl['voucher_id'] : null;
                    $params[] = (string)($sl['voucher_username'] ?? '');
                    $params[] = !empty($sl['profile_id']) ? (int)$sl['profile_id'] : null;
                    $params[] = (string)($sl['profile_name'] ?? '');
                    $params[] = !empty($sl['router_id']) ? (int)$sl['router_id'] : null;
                    $params[] = !empty($sl['sold_by']) ? (int)$sl['sold_by'] : null;
                    $params[] = (float)($sl['price'] ?? 0);
                    $params[] = !empty($sl['sold_at']) ? (string)$sl['sold_at'] : date('Y-m-d H:i:s');
                    $types .= 'iisisisds';
                }
                $syncedUpdate = $hasSalesSyncedCol ? ", synced_to_master=1" : "";
                $syncedColSql = $hasSalesSyncedCol ? ", synced_to_master" : "";
                $sql = "INSERT INTO sales_log (id, voucher_id, voucher_username, profile_id, profile_name, router_id, sold_by, price, sold_at{$syncedColSql})
                        VALUES " . implode(', ', $values) . "
                        ON DUPLICATE KEY UPDATE 
                            voucher_id = VALUES(voucher_id),
                            voucher_username = VALUES(voucher_username),
                            profile_id = VALUES(profile_id),
                            profile_name = VALUES(profile_name),
                            router_id = VALUES(router_id),
                            sold_by = VALUES(sold_by),
                            price = VALUES(price),
                            sold_at = VALUES(sold_at){$syncedUpdate}";
                $stmt = $db->prepare($sql);
                if ($stmt) {
                    $bindArgs = [$types];
                    foreach ($params as $kp => $val) {
                        $bindArgs[] = &$params[$kp];
                    }
                    call_user_func_array([$stmt, 'bind_param'], $bindArgs);
                    $stmt->execute();
                    $stmt->close();
                }
                $salesCount += count($chunk);
                unset($slChunks[$k]);
            }
            unset($slChunks);
        }

        // 7. Sinkronisasi Laporan Penagihan Reseller (penagihan)
        $penagihan = $payload['penagihan'] ?? [];
        if (!empty($penagihan)) {
            foreach ($penagihan as $pn) {
                db_execute("INSERT INTO penagihan (id, router_id, profile_id, total_pendapatan, bagian_reseller, pendapatan_bersih, estimasi_voucher, voucher_aktual, status_kecocokan, catatan, ditagih_oleh, tanggal, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE 
                                total_pendapatan = VALUES(total_pendapatan),
                                bagian_reseller = VALUES(bagian_reseller),
                                pendapatan_bersih = VALUES(pendapatan_bersih),
                                estimasi_voucher = VALUES(estimasi_voucher),
                                voucher_aktual = VALUES(voucher_aktual),
                                status_kecocokan = VALUES(status_kecocokan),
                                catatan = VALUES(catatan),
                                ditagih_oleh = VALUES(ditagih_oleh),
                                tanggal = VALUES(tanggal)",
                            'iiidddiississ', [
                                (int)$pn['id'],
                                (int)$pn['router_id'],
                                (int)$pn['profile_id'],
                                (float)($pn['total_pendapatan'] ?? 0),
                                (float)($pn['bagian_reseller'] ?? 0),
                                (float)($pn['pendapatan_bersih'] ?? 0),
                                (int)($pn['estimasi_voucher'] ?? 0),
                                (int)($pn['voucher_aktual'] ?? 0),
                                (string)($pn['status_kecocokan'] ?? 'sesuai'),
                                (string)($pn['catatan'] ?? ''),
                                !empty($pn['ditagih_oleh']) ? (int)$pn['ditagih_oleh'] : null,
                                (string)($pn['tanggal'] ?? date('Y-m-d')),
                                (string)($pn['created_at'] ?? date('Y-m-d H:i:s'))
                            ]);
            }
        }

        // 8. Sinkronisasi Pelanggan Broadband PPPoE
        $pppoe_customers = $payload['pppoe_customers'] ?? [];
        $pppoeCount = 0;
        if (!empty($pppoe_customers)) {
            foreach ($pppoe_customers as $cust) {
                db_execute("INSERT INTO pppoe_customers (id, router_id, pppoe_username, portal_password, portal_password_plain, portal_username, full_name, phone, address, profile, monthly_price, is_free, due_day, status, ont_sn, ont_vlan, ont_wifi_ssid, ont_wifi_pass, isolated_at, isolated_reason, last_paid_at, last_paid_amount, notes)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE 
                                full_name = VALUES(full_name),
                                phone = VALUES(phone),
                                address = VALUES(address),
                                profile = VALUES(profile),
                                monthly_price = VALUES(monthly_price),
                                status = VALUES(status),
                                ont_sn = VALUES(ont_sn),
                                last_paid_at = VALUES(last_paid_at),
                                last_paid_amount = VALUES(last_paid_amount)",
                            'iissssssssiisssisssssis', [
                                (int)$cust['id'],
                                (int)$cust['router_id'],
                                (string)$cust['pppoe_username'],
                                (string)($cust['portal_password'] ?? ''),
                                (string)($cust['portal_password_plain'] ?? ''),
                                (string)($cust['portal_username'] ?? ''),
                                (string)($cust['full_name'] ?? ''),
                                (string)($cust['phone'] ?? ''),
                                (string)($cust['address'] ?? ''),
                                (string)($cust['profile'] ?? ''),
                                (int)($cust['monthly_price'] ?? 0),
                                (int)($cust['is_free'] ?? 0),
                                (int)($cust['due_day'] ?? 1),
                                (string)($cust['status'] ?? 'active'),
                                (string)($cust['ont_sn'] ?? ''),
                                (int)($cust['ont_vlan'] ?? 100),
                                (string)($cust['ont_wifi_ssid'] ?? ''),
                                (string)($cust['ont_wifi_pass'] ?? ''),
                                !empty($cust['isolated_at']) ? (string)$cust['isolated_at'] : null,
                                (string)($cust['isolated_reason'] ?? ''),
                                !empty($cust['last_paid_at']) ? (string)$cust['last_paid_at'] : null,
                                (int)($cust['last_paid_amount'] ?? 0),
                                (string)($cust['notes'] ?? '')
                            ]);
                $pppoeCount++;
            }
        }

        // 9. Sinkronisasi Riwayat Pembayaran PPPoE
        $pppoe_payments = $payload['pppoe_payments'] ?? [];
        if (!empty($pppoe_payments)) {
            foreach ($pppoe_payments as $pay) {
                db_execute("INSERT INTO pppoe_payments (id, customer_id, amount, payment_method, payment_gateway, midtrans_order_id, midtrans_tx_id, midtrans_status, checkout_url, pay_code, qr_url, period_month, period_year, paid_at, notes)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE 
                                amount = VALUES(amount),
                                midtrans_status = VALUES(midtrans_status),
                                paid_at = VALUES(paid_at)",
                            'iiissssssssiiss', [
                                (int)$pay['id'],
                                (int)$pay['customer_id'],
                                (int)$pay['amount'],
                                (string)($pay['payment_method'] ?? 'cash'),
                                (string)($pay['payment_gateway'] ?? 'manual'),
                                (string)($pay['midtrans_order_id'] ?? ''),
                                (string)($pay['midtrans_tx_id'] ?? ''),
                                (string)($pay['midtrans_status'] ?? 'settlement'),
                                (string)($pay['checkout_url'] ?? ''),
                                (string)($pay['pay_code'] ?? ''),
                                (string)($pay['qr_url'] ?? ''),
                                (int)$pay['period_month'],
                                (int)$pay['period_year'],
                                (string)($pay['paid_at'] ?? date('Y-m-d H:i:s')),
                                (string)($pay['notes'] ?? '')
                            ]);
            }
        }

        // 10. Sinkronisasi Pengaturan PPPoE
        $pppoe_settings = $payload['pppoe_settings'] ?? [];
        if (!empty($pppoe_settings)) {
            foreach ($pppoe_settings as $pset) {
                db_execute("INSERT INTO pppoe_settings (setting_key, setting_value) VALUES (?, ?)
                            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
                            'ss', [$pset['setting_key'], (string)$pset['setting_value']]);
            }
        }

        $db->commit();
        @$db->query("SET FOREIGN_KEY_CHECKS = 1;");

        // Pastikan flag installed aktif di node lokal
        $installLock = __DIR__ . '/../config/.installed';
        if (!file_exists($installLock)) {
            @touch($installLock);
        }

        // 11. FreeRADIUS membaca radcheck & radreply secara realtime dari MySQL tanpa perlu restart daemon.
        // Daemon hanya perlu direstart jika ada router/nas baru pertama kali.
        if (!empty($routers) && function_exists('restart_freeradius_service')) {
            $lockRadius = __DIR__ . '/../config/.radius_synced';
            if (!file_exists($lockRadius)) {
                @touch($lockRadius);
                @restart_freeradius_service();
            }
        }

        $syncStatusMsg = "Sukses: {$vCount} voucher, {$salesCount} penjualan, {$pppoeCount} PPPoE";
        save_redundant_settings([
            'master_vps_status' => 'online',
            'vps_fail_count'    => '0',
            'last_sync_at'      => date('Y-m-d H:i:s'),
            'last_sync_status'  => $syncStatusMsg
        ]);

        log_redundant_sync($nodeCode, 'pull', 'success', "Berhasil menarik {$vCount} voucher, " . count($profiles) . " profil, {$salesCount} penjualan, {$pppoeCount} PPPoE");

        return [
            'success'       => true,
            'message'       => "Berhasil menarik {$vCount} voucher, " . count($profiles) . " profil, {$salesCount} data penjualan, dan {$pppoeCount} pelanggan PPPoE dari VPS.",
            'voucher_count' => $vCount,
            'sales_count'   => $salesCount,
            'pppoe_count'   => $pppoeCount
        ];

    } catch (Throwable $e) {
        $db->rollback();
        @$db->query("SET FOREIGN_KEY_CHECKS = 1;");
        log_redundant_sync($nodeCode, 'pull', 'failed', "Gagal import DB: " . $e->getMessage());
        return ['success' => false, 'message' => "Gagal import ke database lokal: " . $e->getMessage()];
    }
}

/**
 * PUSH DATA DARI MINI PC KE MASTER VPS (Dijalankan oleh Mini PC Node)
 * Mengirim voucher baru cabang, data pemakaian voucher lokal, accounting radacct, dan sales_log yang terjadi di cabang
 */
function redundant_push_to_master(): array {
    ensure_redundant_tables();
    @ini_set('memory_limit', (php_sapi_name() === 'cli' ? '-1' : '1024M'));
    @set_time_limit(300);

    try {
        $cfg = get_redundant_settings();
        $masterUrl = rtrim($cfg['master_url'] ?? 'https://10.77.77.1', '/');
        $apiKey    = trim($cfg['api_key'] ?? '');
        $nodeCode  = trim($cfg['node_code'] ?? 'NODE_CABANG');

        if (!$apiKey) {
            return ['success' => false, 'message' => 'API Key belum disetel.'];
        }

        // 1. Ambil sesi radacct terbaru yang dibuat dalam 24 jam terakhir di node ini
        $recentAcct = [];
        try {
            $recentAcct = db_fetch_all("SELECT * FROM radacct WHERE acctstarttime >= DATE_SUB(NOW(), INTERVAL 24 HOUR) ORDER BY radacctid DESC LIMIT 200");
        } catch (Throwable $e) {}

        // 2. Ambil status voucher yang telah digunakan di node ini
        $activeVouchers = [];
        try {
            $activeVouchers = db_fetch_all("SELECT id, username, status, used_at, expired_at FROM vouchers WHERE status IN ('active', 'expired') AND used_at IS NOT NULL ORDER BY id DESC LIMIT 200");
        } catch (Throwable $e) {}

        // 3. Ambil voucher baru yang dibuat secara lokal di Mini PC cabang yang belum terdorong ke Master (synced_to_master = 0)
        $newVouchers = [];
        $newRadcheck = [];
        $newRadreply = [];
        try {
            $hasRid = (bool)db_fetch_one("SHOW COLUMNS FROM vouchers LIKE 'router_id'");
            $ridSelect = $hasRid ? "router_id" : "NULL AS router_id";
            $hasSyncedCol = (bool)db_fetch_one("SHOW COLUMNS FROM vouchers LIKE 'synced_to_master'");
            $whereClause = $hasSyncedCol 
                ? "WHERE status = 'unused' AND (synced_to_master = 0 OR synced_to_master IS NULL) ORDER BY id DESC LIMIT 500" 
                : "WHERE status = 'unused' ORDER BY id DESC LIMIT 500";

            $newVouchers = db_fetch_all("SELECT id, username, password, profile_id, {$ridSelect}, batch_id, status, created_at FROM vouchers {$whereClause}");

            if (!empty($newVouchers)) {
                $usernames = array_column($newVouchers, 'username');
                $userChunks = array_chunk($usernames, 250);
                foreach ($userChunks as $uChunk) {
                    $uList = array_map(function($u) { return "'" . addslashes($u) . "'"; }, $uChunk);
                    $inClause = implode(',', $uList);
                    $rcRows = db_fetch_all("SELECT username, attribute, op, value FROM radcheck WHERE username IN ($inClause)");
                    if (!empty($rcRows)) {
                        $newRadcheck = array_merge($newRadcheck, $rcRows);
                    }
                    $rrRows = db_fetch_all("SELECT username, attribute, op, value FROM radreply WHERE username IN ($inClause)");
                    if (!empty($rrRows)) {
                        $newRadreply = array_merge($newRadreply, $rrRows);
                    }
                }
            }
        } catch (Throwable $e) {}

        // 4. Ambil riwayat penjualan lokal yang belum terdorong ke Master
        $recentSales = [];
        try {
            $hasSoldBy = (bool)db_fetch_one("SHOW COLUMNS FROM sales_log LIKE 'sold_by'");
            $soldBySelect = $hasSoldBy ? "sold_by" : "NULL AS sold_by";
            $hasSalesSyncedCol = (bool)db_fetch_one("SHOW COLUMNS FROM sales_log LIKE 'synced_to_master'");
            $salesWhere = $hasSalesSyncedCol 
                ? "WHERE (synced_to_master = 0 OR synced_to_master IS NULL) ORDER BY id DESC LIMIT 200" 
                : "WHERE sold_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) ORDER BY id DESC LIMIT 200";

            $recentSales = db_fetch_all("SELECT id, voucher_id, voucher_username, profile_id, profile_name, router_id, {$soldBySelect}, price, sold_at FROM sales_log {$salesWhere}");
        } catch (Throwable $e) {}

        // 5. Ambil riwayat pembayaran PPPoE lokal dalam 24 jam terakhir (jika ada)
        $recentPayments = [];
        try {
            $recentPayments = db_fetch_all("SELECT customer_id, amount, payment_method, payment_gateway, period_month, period_year, paid_at, notes FROM pppoe_payments WHERE paid_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) ORDER BY id DESC LIMIT 100");
        } catch (Throwable $e) {}

        $payload = [
            'node_code'      => $nodeCode,
            'timestamp'      => date('Y-m-d H:i:s'),
            'radacct'        => $recentAcct,
            'vouchers'       => $activeVouchers,
            'new_vouchers'   => $newVouchers,
            'sales_log'      => $recentSales,
            'pppoe_payments' => $recentPayments,
            'radcheck'       => $newRadcheck,
            'radreply'       => $newRadreply
        ];

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!$jsonPayload) {
            log_redundant_sync($nodeCode, 'push', 'failed', "Gagal encode payload JSON: " . json_last_error_msg());
            return ['success' => false, 'message' => "Gagal encode payload JSON: " . json_last_error_msg()];
        }

        $endpoint = $masterUrl . '/api/redundant_sync.php?action=push_activity';

        $req = redundant_http_request($endpoint, [
            'Content-Type: application/json',
            'X-Redundant-Key: ' . $apiKey,
            'X-Node-Code: ' . $nodeCode,
            'Accept: application/json',
            'Expect:'
        ], $jsonPayload, 60);

        $httpCode = $req['http_code'];
        $response = $req['response'];
        $curlErr  = $req['error'];

        if ($curlErr || $httpCode !== 200) {
            $errMsg = $curlErr ? $curlErr : "HTTP {$httpCode}: " . substr(strip_tags((string)$response), 0, 150);
            log_redundant_sync($nodeCode, 'push', 'failed', "Gagal push ke Master: {$errMsg}");
            return ['success' => false, 'message' => "Gagal kirim ke Master: {$errMsg}"];
        }

        $res = json_decode((string)$response, true);
        if (!empty($res['success'])) {
            // Tandai voucher dan penjualan lokal sebagai SUDAH tersinkron (synced_to_master = 1)
            if (!empty($newVouchers) && !empty($hasSyncedCol)) {
                $uList = array_map(function($v) { return "'" . addslashes($v['username']) . "'"; }, $newVouchers);
                $inClause = implode(',', $uList);
                db_execute("UPDATE vouchers SET synced_to_master = 1 WHERE username IN ($inClause)");
            }
            if (!empty($recentSales) && !empty($hasSalesSyncedCol)) {
                $sList = array_map(function($s) { return "'" . addslashes($s['voucher_username']) . "'"; }, $recentSales);
                $inClause = implode(',', $sList);
                db_execute("UPDATE sales_log SET synced_to_master = 1 WHERE voucher_username IN ($inClause)");
            }

            $summary = "Berhasil menyetor " . count($newVouchers) . " voucher baru, " . count($activeVouchers) . " status terpakai, " . count($recentSales) . " penjualan ke Master VPS";
            log_redundant_sync($nodeCode, 'push', 'success', $summary);
            return ['success' => true, 'message' => $summary];
        }

        $failMsg = $res['message'] ?? 'Respon tidak valid dari Master: ' . substr(strip_tags((string)$response), 0, 150);
        log_redundant_sync($nodeCode, 'push', 'failed', "Master VPS merespons: {$failMsg}");
        return ['success' => false, 'message' => $failMsg];

    } catch (Throwable $e) {
        log_redundant_sync($nodeCode, 'push', 'failed', "Error push: " . $e->getMessage());
        return ['success' => false, 'message' => 'Kesalahan push: ' . $e->getMessage()];
    }
}

/**
 * Jalankan sinkronisasi lengkap (PUSH aktivitas lokal dulu, lalu PULL katalog master)
 */
function redundant_run_full_sync(): array {
    $isCli = (php_sapi_name() === 'cli');

    if ($isCli) {
        echo "[1/2] Menjalankan PUSH (mengirim voucher baru & aktivitas cabang ke Master VPS)...\n";
    }
    // 1. PUSH aktivitas & voucher baru lokal ke VPS Master terlebih dahulu
    $push = redundant_push_to_master();
    if ($isCli) {
        echo "      " . (!empty($push['success']) ? '✓ PUSH Sukses: ' : '[!] PUSH Info: ') . ($push['message'] ?? '') . "\n";
    }

    if ($isCli) {
        echo "[2/2] Menjalankan PULL (mengunduh katalog & data terbaru dari Master VPS)...\n";
    }
    // 2. PULL pembaruan katalog terbaru dari VPS Master
    $pull = redundant_pull_from_master();
    if ($isCli) {
        echo "      " . (!empty($pull['success']) ? '✓ PULL Sukses: ' : '[!] PULL Info: ') . ($pull['message'] ?? '') . "\n";
    }

    if (!$pull['success'] && !$push['success']) {
        return [
            'success' => false,
            'message' => 'Gagal sinkronisasi: ' . ($push['message'] ?? '') . ' | ' . ($pull['message'] ?? ''),
            'push'    => $push,
            'pull'    => $pull
        ];
    }

    $pushNote = !empty($push['success'])
        ? ' (Push ke Master: Sukses)'
        : ' (Push ke Master: ' . ($push['message'] ?? 'tidak ada data baru') . ')';

    return [
        'success' => true,
        'message' => ($pull['message'] ?? 'Pull sukses') . $pushNote,
        'push'    => $push,
        'pull'    => $pull
    ];
}

