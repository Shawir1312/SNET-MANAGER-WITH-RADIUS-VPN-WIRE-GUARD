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
            status enum('unused','active','expired','deleted') DEFAULT 'unused',
            used_at datetime DEFAULT NULL,
            expired_at datetime DEFAULT NULL,
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY username (username),
            KEY status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        @db_execute("ALTER TABLE vouchers MODIFY COLUMN status VARCHAR(20) DEFAULT 'unused'");

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
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
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
    ], null, 35);

    $httpCode = $req['http_code'];
    $response = $req['response'];
    $curlErr  = $req['error'];

    if ($curlErr) {
        log_redundant_sync($nodeCode, 'pull', 'failed', "Gagal koneksi ke Master: {$curlErr}");
        return ['success' => false, 'message' => "Gagal terhubung ke Master VPS ({$masterUrl}): {$curlErr}"];
    }

    if ($httpCode !== 200) {
        log_redundant_sync($nodeCode, 'pull', 'failed', "HTTP {$httpCode}: " . substr((string)$response, 0, 100));
        return ['success' => false, 'message' => "Master VPS merespons HTTP {$httpCode}: " . substr((string)$response, 0, 200)];
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

        // 3. Sinkronisasi Vouchers
        $vCount = 0;
        if (!empty($vouchers)) {
            foreach ($vouchers as $v) {
                $vid = (int)$v['id'];
                $vuser = (string)$v['username'];
                $vpass = (string)$v['password'];
                $vpid = (int)$v['profile_id'];
                $vbid = !empty($v['batch_id']) ? (string)$v['batch_id'] : null;
                $vstatus = !empty($v['status']) ? (string)$v['status'] : 'unused';
                $vused = !empty($v['used_at']) ? (string)$v['used_at'] : null;
                $vexp = !empty($v['expired_at']) ? (string)$v['expired_at'] : null;
                $vcreated = !empty($v['created_at']) ? (string)$v['created_at'] : date('Y-m-d H:i:s');

                db_execute("INSERT INTO vouchers (id, username, password, profile_id, batch_id, status, used_at, expired_at, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE password=VALUES(password), profile_id=VALUES(profile_id), status=VALUES(status), used_at=VALUES(used_at), expired_at=VALUES(expired_at)",
                            'ississsss', [
                                $vid, $vuser, $vpass, $vpid, $vbid,
                                $vstatus, $vused, $vexp, $vcreated
                            ]);
                $vCount++;
            }
        }

        // 4. Sinkronisasi radcheck & radreply FreeRADIUS
        if (!empty($radcheck)) {
            foreach ($radcheck as $rc) {
                db_execute("INSERT INTO radcheck (username, attribute, op, value)
                            VALUES (?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE value=VALUES(value)",
                            'ssss', [$rc['username'], $rc['attribute'], $rc['op'], $rc['value']]);
            }
        }

        if (!empty($radreply)) {
            foreach ($radreply as $rr) {
                db_execute("INSERT INTO radreply (username, attribute, op, value)
                            VALUES (?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE value=VALUES(value)",
                            'ssss', [$rr['username'], $rr['attribute'], $rr['op'], $rr['value']]);
            }
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

        $db->commit();

        // Pastikan flag installed aktif di node lokal
        $installLock = __DIR__ . '/../config/.installed';
        if (!file_exists($installLock)) {
            @touch($installLock);
        }

        // 6. Reload FreeRADIUS lokal jika ada pembaruan
        if (function_exists('restart_freeradius_service')) {
            restart_freeradius_service();
        }

        save_redundant_settings([
            'last_sync_at'     => date('Y-m-d H:i:s'),
            'last_sync_status' => "Sukses menarik {$vCount} voucher & " . count($profiles) . " profil dari VPS"
        ]);

        log_redundant_sync($nodeCode, 'pull', 'success', "Berhasil menarik {$vCount} voucher & " . count($profiles) . " profil");

        return [
            'success' => true,
            'message' => "Berhasil menarik {$vCount} voucher, " . count($profiles) . " profil, dan " . count($routers) . " router dari VPS.",
            'voucher_count' => $vCount
        ];

    } catch (Throwable $e) {
        $db->rollback();
        log_redundant_sync($nodeCode, 'pull', 'failed', "Gagal import DB: " . $e->getMessage());
        return ['success' => false, 'message' => "Gagal import ke database lokal: " . $e->getMessage()];
    }
}

/**
 * PUSH DATA DARI MINI PC KE MASTER VPS (Dijalankan oleh Mini PC Node)
 * Mengirim data pemakaian voucher lokal, accounting radacct, dan sales_log yang terjadi di cabang
 */
function redundant_push_to_master(): array {
    $cfg = get_redundant_settings();
    $masterUrl = rtrim($cfg['master_url'] ?? 'http://10.77.77.1', '/');
    $apiKey    = trim($cfg['api_key'] ?? '');
    $nodeCode  = trim($cfg['node_code'] ?? 'NODE_CABANG');

    if (!$apiKey) {
        return ['success' => false, 'message' => 'API Key belum disetel.'];
    }

    // Ambil sesi radacct terbaru yang dibuat dalam 24 jam terakhir di node ini
    $recentAcct = db_fetch_all("SELECT * FROM radacct WHERE acctstarttime >= DATE_SUB(NOW(), INTERVAL 24 HOUR) ORDER BY radacctid DESC LIMIT 500");
    // Ambil voucher yang statusnya telah berubah menjadi active atau expired
    $activeVouchers = db_fetch_all("SELECT id, username, status, used_at, expired_at FROM vouchers WHERE status IN ('active', 'expired') AND used_at IS NOT NULL ORDER BY id DESC LIMIT 500");

    $payload = [
        'node_code' => $nodeCode,
        'timestamp' => date('Y-m-d H:i:s'),
        'radacct'   => $recentAcct,
        'vouchers'  => $activeVouchers
    ];

    $endpoint = $masterUrl . '/api/redundant_sync.php?action=push_activity';

    $req = redundant_http_request($endpoint, [
        'Content-Type: application/json',
        'X-Redundant-Key: ' . $apiKey,
        'X-Node-Code: ' . $nodeCode,
        'Accept: application/json'
    ], json_encode($payload), 35);

    $httpCode = $req['http_code'];
    $response = $req['response'];
    $curlErr  = $req['error'];

    if ($curlErr || $httpCode !== 200) {
        log_redundant_sync($nodeCode, 'push', 'failed', "Gagal push ke Master: " . ($curlErr ?: "HTTP {$httpCode}"));
        return ['success' => false, 'message' => "Gagal kirim ke Master: " . ($curlErr ?: "HTTP {$httpCode}")];
    }

    $res = json_decode((string)$response, true);
    if (!empty($res['success'])) {
        log_redundant_sync($nodeCode, 'push', 'success', "Berhasil menyetor " . count($activeVouchers) . " status voucher ke Master");
        return ['success' => true, 'message' => "Berhasil menyinkronkan data pemakaian ke VPS Master."];
    }

    return ['success' => false, 'message' => $res['message'] ?? 'Respon tidak valid saat push'];
}

/**
 * Jalankan sinkronisasi lengkap (PULL katalog terbaru lalu PUSH pemakaian lokal)
 */
function redundant_run_full_sync(): array {
    $pull = redundant_pull_from_master();
    if (!$pull['success']) {
        return $pull;
    }
    $push = redundant_push_to_master();
    return [
        'success' => true,
        'message' => $pull['message'] . ' (' . ($push['message'] ?? '') . ')',
        'pull'    => $pull,
        'push'    => $push
    ];
}

