<?php
/**
 * S.NET RADIUS Hotspot Management
 * Global Helper Functions
 */

// ───── String / Random ─────────────────────────────────────────────────

function random_string(int $length, string $type = 'mix'): string {
    $lower  = 'abcdefghjkmnpqrstuvwxyz';   // no i,l,o
    $upper  = 'ABCDEFGHJKMNPQRSTUVWXYZ';
    $digits = '23456789';                   // no 0,1
    $map = [
        'lower'  => $lower,
        'upper'  => $upper,
        'num'    => $digits,
        'upplow' => $lower . $upper,
        'mix'    => $lower . $digits,
        'mix1'   => $upper . $digits,
        'mix2'   => $lower . $upper . $digits,
    ];
    $chars = $map[$type] ?? ($lower . $upper . $digits);
    $result = '';
    $max    = strlen($chars) - 1;
    for ($i = 0; $i < $length; $i++) {
        $result .= $chars[random_int(0, $max)];
    }
    return $result;
}

function generate_batch_id(): string {
    return date('H:i:s-d-m-y');
}

// ───── Bytes Formatting ────────────────────────────────────────────────

function format_bytes($bytes, int $precision = 2): string {
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $pow   = min((int) floor(log($bytes, 1024)), count($units) - 1);
    $val   = $bytes / (1024 ** $pow);
    return round($val, $precision) . ' ' . $units[$pow];
}

function bytes_to_mb(int $bytes): float {
    return round($bytes / 1048576, 2);
}

function mb_to_bytes(float $mb): int {
    return (int) ($mb * 1048576);
}

// ───── Duration / Time ─────────────────────────────────────────────────

/**
 * Convert profile duration to seconds (for Session-Timeout RADIUS attr).
 */
function duration_to_seconds(int $value, string $unit): int {
    $map = [
        'minutes' => $value * 60,
        'hours'   => $value * 3600,
        'days'    => $value * 86400,
    ];
    return $map[$unit] ?? ($value * 3600);
}

function trans_unit(string $unit): string {
    $map = [
        'minutes' => 'Menit',
        'hours'   => 'Jam',
        'days'    => 'Hari',
    ];
    return $map[$unit] ?? $unit;
}

function seconds_to_human(int $secs): string {
    if ($secs <= 0) return 'Unlimited';
    $d = intdiv($secs, 86400);
    $h = intdiv($secs % 86400, 3600);
    $m = intdiv($secs % 3600, 60);
    $parts = [];
    if ($d) $parts[] = "{$d} Hari";
    if ($h) $parts[] = "{$h} Jam";
    if ($m) $parts[] = "{$m} Menit";
    return implode(' ', $parts) ?: '< 1 Menit';
}

function session_duration_human(?string $start, ?string $stop = null): string {
    if (!$start) return '-';
    $from = strtotime($start);
    $to   = $stop ? strtotime($stop) : time();
    return seconds_to_human($to - $from);
}

function format_relative_time(?int $timestamp): string {
    if (!$timestamp || $timestamp <= 0) return 'Belum pernah';
    $diff = time() - $timestamp;
    if ($diff < 5) return 'Baru saja';
    if ($diff < 60) return $diff . ' detik lalu';
    if ($diff < 3600) return floor($diff / 60) . ' menit lalu';
    if ($diff < 86400) return floor($diff / 3600) . ' jam lalu';
    return floor($diff / 86400) . ' hari lalu';
}

/**
 * Format uptime dalam detik menjadi string ramah (hari, jam, menit, detik)
 * Contoh: 23843 → "6 jam 37 menit 23 detik"
 */
function format_uptime_seconds($seconds): string {
    $sec = (int)$seconds;
    if ($sec <= 0) return '-';

    $days = floor($sec / 86400);
    $hours = floor(($sec % 86400) / 3600);
    $minutes = floor(($sec % 3600) / 60);
    $remSec = $sec % 60;

    $parts = [];
    if ($days > 0) $parts[] = "{$days} hari";
    if ($hours > 0) $parts[] = "{$hours} jam";
    if ($minutes > 0) $parts[] = "{$minutes} menit";
    if ($remSec > 0 || empty($parts)) $parts[] = "{$remSec} detik";

    return implode(' ', $parts);
}

/**
 * Parse string uptime MikroTik (contoh: "11d15:43:31", "2d04h", "01:23:45") ke detik
 */
function parse_mikrotik_uptime_to_seconds($uptimeStr): int {
    if (empty($uptimeStr) || !is_string($uptimeStr)) return 0;
    $s = trim($uptimeStr);
    $totalSec = 0;

    // Weeks
    if (preg_match('/(\d+)\s*w/i', $s, $m)) {
        $totalSec += (int)$m[1] * 7 * 86400;
        $s = preg_replace('/\d+\s*w/i', '', $s);
    }
    // Days
    if (preg_match('/(\d+)\s*d/i', $s, $m)) {
        $totalSec += (int)$m[1] * 86400;
        $s = preg_replace('/\d+\s*d/i', '', $s);
    }
    // Hours
    if (preg_match('/(\d+)\s*h/i', $s, $m)) {
        $totalSec += (int)$m[1] * 3600;
        $s = preg_replace('/\d+\s*h/i', '', $s);
    }
    // Minutes
    if (preg_match('/(\d+)\s*m(?!s)/i', $s, $m)) {
        $totalSec += (int)$m[1] * 60;
        $s = preg_replace('/\d+\s*m(?!s)/i', '', $s);
    }
    // Seconds
    if (preg_match('/(\d+)\s*s/i', $s, $m)) {
        $totalSec += (int)$m[1];
        $s = preg_replace('/\d+\s*s/i', '', $s);
    }

    $s = trim($s);
    if ($s !== '') {
        $parts = array_map('intval', explode(':', $s));
        if (count($parts) === 3) {
            $totalSec += ($parts[0] * 3600) + ($parts[1] * 60) + $parts[2];
        } elseif (count($parts) === 2) {
            $totalSec += ($parts[0] * 60) + $parts[1];
        } elseif (count($parts) === 1 && is_numeric($parts[0])) {
            $totalSec += $parts[0];
        }
    }

    return $totalSec;
}

// ───── RADIUS Rate-Limit Format ────────────────────────────────────────

/**
 * Build Mikrotik-Rate-Limit value: "upload/download"
 * e.g. rate_limit_attr("10M", "20M") → "10M/20M"
 */
function rate_limit_attr(string $up, string $down): string {
    $up   = $up   ?: '0';
    $down = $down ?: '0';
    return "{$up}/{$down}";
}

// ───── Pagination ──────────────────────────────────────────────────────

function paginate(int $total, int $per_page, int $current_page, string $url_base): array {
    $total_pages = max(1, (int) ceil($total / $per_page));
    $current_page = max(1, min($current_page, $total_pages));
    $offset = ($current_page - 1) * $per_page;
    return [
        'total'       => $total,
        'per_page'    => $per_page,
        'current'     => $current_page,
        'total_pages' => $total_pages,
        'offset'      => $offset,
        'url_base'    => $url_base,
    ];
}

function pagination_html(array $p): string {
    if ($p['total_pages'] <= 1) return '';
    $html = '<nav><ul class="pagination pagination-sm mb-0">';
    $prev = $p['current'] - 1;
    $next = $p['current'] + 1;
    $disabled = $p['current'] <= 1 ? 'disabled' : '';
    $html .= "<li class='page-item {$disabled}'><a class='page-link' href='{$p['url_base']}&p={$prev}'>«</a></li>";
    $range = range(max(1, $p['current']-2), min($p['total_pages'], $p['current']+2));
    foreach ($range as $pg) {
        $active = $pg === $p['current'] ? 'active' : '';
        $html .= "<li class='page-item {$active}'><a class='page-link' href='{$p['url_base']}&p={$pg}'>{$pg}</a></li>";
    }
    $disabled2 = $p['current'] >= $p['total_pages'] ? 'disabled' : '';
    $html .= "<li class='page-item {$disabled2}'><a class='page-link' href='{$p['url_base']}&p={$next}'>»</a></li>";
    $html .= '</ul></nav>';
    return $html;
}

// ───── Sanitization ────────────────────────────────────────────────────

function sanitize(string $str): string {
    return htmlspecialchars(trim($str), ENT_QUOTES, 'UTF-8');
}

function sanitize_username(string $str): string {
    // Voucher usernames: alphanumeric + dash/underscore, max 64 chars
    return substr(preg_replace('/[^a-zA-Z0-9\-_]/', '', trim($str)), 0, 64);
}

function post(string $key, $default = '') {
    return $_POST[$key] ?? $default;
}

function get(string $key, $default = '') {
    return $_GET[$key] ?? $default;
}

// ───── Flash Messages ──────────────────────────────────────────────────

function flash_set(string $type, string $msg): void {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function flash_get(): ?array {
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function flash_html(): string {
    $f = flash_get();
    if (!$f) return '';
    $map = [
        'success' => 'success',
        'error'   => 'danger',
        'warning' => 'warning',
    ];
    $type = $map[$f['type']] ?? 'info';
    return "<div class='alert alert-{$type} alert-dismissible fade show' role='alert'>"
         . htmlspecialchars($f['msg'], ENT_QUOTES)
         . "<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
}

// ───── Router / NAS Helpers ────────────────────────────────────────────

function get_all_routers(): array {
    $access = accessible_router_ids();
    if ($access === null) {
        return db_fetch_all("SELECT * FROM routers ORDER BY name ASC");
    }
    if (empty($access)) return [];
    $placeholders = implode(',', array_fill(0, count($access), '?'));
    $types = str_repeat('i', count($access));
    return db_fetch_all("SELECT * FROM routers WHERE id IN ({$placeholders}) ORDER BY name ASC", $types, $access);
}

function get_router(int $id): ?array {
    return db_fetch_one("SELECT * FROM routers WHERE id = ? LIMIT 1", 'i', [$id]);
}

// ───── Price Formatting ────────────────────────────────────────────────

function format_price(float $price): string {
    return 'Rp ' . number_format($price, 0, ',', '.');
}

// ───── Status Badge ────────────────────────────────────────────────────

function voucher_status_badge(string $status): string {
    $map = [
        'unused'  => "<span class='badge bg-secondary'>Belum Dipakai</span>",
        'active'  => "<span class='badge bg-success'>Aktif</span>",
        'expired' => "<span class='badge bg-danger'>Kadaluarsa</span>",
        'deleted' => "<span class='badge bg-dark'>Dihapus</span>",
    ];
    return $map[$status] ?? "<span class='badge bg-light text-dark'>{$status}</span>";
}

function router_status_badge(bool $online): string {
    return $online
        ? "<span class='badge bg-success'><i class='bi bi-circle-fill me-1'></i>Online</span>"
        : "<span class='badge bg-danger'><i class='bi bi-circle-fill me-1'></i>Offline</span>";
}

function ensure_profile_columns(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $chk = db()->query("SHOW COLUMNS FROM profiles LIKE 'include_in_sales'");
        if ($chk && $chk->num_rows === 0) {
            db()->query("ALTER TABLE profiles ADD COLUMN include_in_sales TINYINT(1) DEFAULT 1 AFTER price");
        }
    } catch (Throwable $e) {}
}

function sync_active_vouchers() {
    ensure_profile_columns();
    $newly_active = db_fetch_all("
        SELECT v.id, v.username, v.profile_id, p.name AS profile_name, p.price,
               COALESCE(p.include_in_sales, 1) AS include_in_sales,
               v.router_id, ra.acctstarttime, p.validity_value, p.validity_unit
        FROM vouchers v
        JOIN radacct ra ON v.username = ra.username
        JOIN profiles p ON v.profile_id = p.id
        WHERE v.status = 'unused'
    ");

    foreach ($newly_active as $v) {
        db_begin();
        try {
            $validity_s = duration_to_seconds($v['validity_value'] ?? 30, $v['validity_unit'] ?? 'days');
            $used_at = $v['acctstarttime'];
            $expired_at = date('Y-m-d H:i:s', strtotime($used_at) + $validity_s);

            db_execute(
                "UPDATE vouchers SET status = 'active', used_at = ?, expired_at = ? WHERE id = ?",
                'ssi', [$used_at, $expired_at, $v['id']]
            );

            // Record sale only if profile is configured to be included in sales
            if ((int)$v['include_in_sales'] === 1) {
                $already_logged = db_fetch_one("SELECT id FROM sales_log WHERE voucher_id = ? OR voucher_username = ? LIMIT 1", 'is', [$v['id'], $v['username']]);
                if (!$already_logged) {
                    db_execute(
                        "INSERT INTO sales_log (voucher_id, voucher_username, profile_id, profile_name, router_id, price, sold_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?)",
                        'isissds', 
                        [$v['id'], $v['username'], $v['profile_id'], $v['profile_name'], $v['router_id'], $v['price'], $used_at]
                    );
                }
            }

            db_commit();
        } catch(Throwable $e) {
            db_rollback();
        }
    }
}

/**
 * Global cleanup routine to expire vouchers and clear stale sessions.
 */
function run_auto_expire_vouchers($log = null, bool $force = false) {
    if (!$log) $log = function($msg) {};

    // Throttle untuk web request: hindari multiple worker PHP menjalankan perulangan berat secara bersamaan
    if (!$force && !defined('IS_CRON') && php_sapi_name() !== 'cli') {
        static $ran = false;
        if ($ran) return;
        $ran = true;

        $lockFile = sys_get_temp_dir() . '/snet_auto_expire_throttle.lock';
        if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 120) {
            return;
        }
        @touch($lockFile);
    }

    // ── Clear bogus expired_at for unused vouchers ──
    db_execute("UPDATE vouchers SET expired_at = NULL WHERE status = 'unused' AND expired_at IS NOT NULL");

    // ── Fix Stale Sessions globally ──
    $stale_sessions = db_fetch_all("
        SELECT ra.radacctid, ra.username, ra.nasipaddress 
        FROM radacct ra
        JOIN vouchers v ON ra.username = v.username
        WHERE v.status IN ('expired', 'deleted') AND ra.acctstoptime IS NULL
    ");
    
    if (count($stale_sessions) > 0) {
        require_once __DIR__ . '/../lib/routeros_api.class.php';
        foreach ($stale_sessions as $stale) {
            $username = $stale['username'];
            $log("Stale/ghost session found for expired voucher: {$username}. Forcing kick.");
            
            // Kick dari Mikrotik
            $router = db_fetch_one("SELECT ip_address, api_user, api_password, api_port FROM routers WHERE ip_address = ? OR nas_ip = ?", 'ss', [$stale['nasipaddress'], $stale['nasipaddress']]);
            if ($router) {
                try {
                    $api = new RouterosAPI();
                    $api->debug = false;
                    if ($api->connect($router['ip_address'], $router['api_user'], $router['api_password'], (int)$router['api_port'])) {
                        $active_users = $api->comm("/ip/hotspot/active/print", ["?user" => $username]);
                        foreach ($active_users as $au) {
                            $api->comm("/ip/hotspot/active/remove", [".id" => $au['.id']]);
                            $log("  → Kicked stale user {$username} from Mikrotik ({$router['ip_address']})");
                        }
                        $api->disconnect();
                    }
                } catch (Throwable $e) {}
            }
            
            // Tutup sesi di RADIUS & pastikan dihapus dari radcheck
            db_execute("UPDATE radacct SET acctstoptime = NOW(), acctterminatecause = 'Admin-Reset' WHERE radacctid = ?", 'i', [$stale['radacctid']]);
            db_execute("DELETE FROM radcheck WHERE username = ?", 's', [$username]);
            db_execute("DELETE FROM radreply WHERE username = ?", 's', [$username]);
        }
    }

    // ── Pulihkan sesi valid yang sempat salah ditutup oleh Session-Timeout-Ghost ──
    db_execute("
        UPDATE radacct ra
        JOIN vouchers v ON v.username = ra.username
        SET ra.acctstoptime = NULL, ra.acctterminatecause = NULL
        WHERE ra.acctterminatecause = 'Session-Timeout-Ghost'
          AND (v.expired_at IS NULL OR v.expired_at > NOW())
          AND v.status NOT IN ('expired', 'deleted')
    ");

    // ── Catch up missing expired_at ──
    $missing_exp = db_fetch_all("
        SELECT v.id, v.used_at, p.validity_value, p.validity_unit
        FROM vouchers v JOIN profiles p ON v.profile_id = p.id
        WHERE v.status = 'active' AND v.expired_at IS NULL AND v.used_at IS NOT NULL
    ");
    foreach ($missing_exp as $m) {
        $vs = duration_to_seconds($m['validity_value'] ?? 30, $m['validity_unit'] ?? 'days');
        $exp = date('Y-m-d H:i:s', strtotime($m['used_at']) + $vs);
        db_execute("UPDATE vouchers SET expired_at = ? WHERE id = ?", 'si', [$exp, $m['id']]);
    }

    // ── Uptime Quota (Durasi Pakai) Enforcement ──
    // Sum all usage and update radreply Session-Timeout for active vouchers
    $active_v = db_fetch_all("
        SELECT v.id, v.username, p.duration_value, p.duration_unit 
        FROM vouchers v JOIN profiles p ON v.profile_id = p.id 
        WHERE v.status = 'active' AND p.duration_value > 0
    ");
    foreach ($active_v as $v) {
        $limit = duration_to_seconds($v['duration_value'], $v['duration_unit']);
        
        // Sum closed sessions (acctsessiontime is final)
        $used_closed = (int)(db_fetch_one("SELECT SUM(acctsessiontime) as used FROM radacct WHERE username = ? AND acctstoptime IS NOT NULL", 's', [$v['username']])['used'] ?? 0);
        
        // Sum currently active sessions (elapsed time)
        $active_sessions = db_fetch_all("SELECT acctstarttime FROM radacct WHERE username = ? AND acctstoptime IS NULL", 's', [$v['username']]);
        $used_active = 0;
        foreach ($active_sessions as $sess) {
            $used_active += max(0, time() - strtotime($sess['acctstarttime']));
        }
        
        $used = $used_closed + $used_active;
        $remaining = $limit - $used;

        if ($remaining <= 0) {
            // Force expire if quota is reached
            db_execute("UPDATE vouchers SET expired_at = NOW() WHERE id = ?", 'i', [$v['id']]);
            $log("Quota reached for {$v['username']}. Marked to expire.");
        } else {
            // Update Session-Timeout in radreply so Mikrotik enforces the remaining time on next login
            db_execute("UPDATE radreply SET value = ? WHERE username = ? AND attribute = 'Session-Timeout'", 'ss', [(string)$remaining, $v['username']]);
        }
    }

    sync_active_vouchers();

    // ── Find expired vouchers ──
    $past_expired = db_fetch_all(
        "SELECT id, username, router_id FROM vouchers WHERE status = 'active' AND expired_at <= NOW() AND expired_at IS NOT NULL"
    );

    if (count($past_expired) > 0) {
        require_once __DIR__ . '/../lib/routeros_api.class.php';
    }

    foreach ($past_expired as $v) {
        $username = $v['username'];
        $log("Expiring: {$username}");
        db_begin();
        try {
            db_execute("UPDATE vouchers SET status = 'expired' WHERE id = ?", 'i', [(int)$v['id']]);
            db_execute("DELETE FROM radcheck WHERE username = ?", 's', [$username]);
            db_execute("DELETE FROM radreply WHERE username = ?", 's', [$username]);
            db_execute("
                UPDATE radacct SET acctstoptime = NOW(), acctterminatecause = 'Session-Timeout'
                WHERE username = ? AND acctstoptime IS NULL", 's', [$username]
            );
            db_execute(
                "INSERT INTO audit_log (admin_id, admin_name, action, target, ip_address, detail, created_at)
                 VALUES (0, 'SYSTEM', 'auto_expire', ?, 'system', 'Expired by date', NOW())", 's', [$username]
            );
            db_commit();
            $log("  → Voucher expired and removed from RADIUS tables");

            // Kick from Mikrotik (Cari router via router_id voucher dahulu, fallback ke nasipaddress)
            $router = null;
            if (!empty($v['router_id'])) {
                $router = get_router((int)$v['router_id']);
            }
            if (!$router) {
                $acct = db_fetch_one("SELECT nasipaddress FROM radacct WHERE username = ? ORDER BY acctstarttime DESC LIMIT 1", 's', [$username]);
                if ($acct) {
                    $router = db_fetch_one("SELECT * FROM routers WHERE ip_address = ? OR nas_ip = ?", 'ss', [$acct['nasipaddress'], $acct['nasipaddress']]);
                }
            }

            if ($router && !empty($router['ip_address'])) {
                try {
                    $api = new RouterosAPI();
                    $api->debug = false;
                    $api->timeout = 2;
                    if ($api->connect($router['ip_address'], $router['api_user'], $router['api_password'], (int)$router['api_port'])) {
                        $active_users = $api->comm("/ip/hotspot/active/print", ["?user" => $username]);
                        foreach ($active_users as $au) {
                            $api->comm("/ip/hotspot/active/remove", [".id" => $au['.id']]);
                            $log("  → Kicked {$username} from Mikrotik ({$router['ip_address']})");
                        }
                        $api->disconnect();
                    }
                } catch (Throwable $e) {}
            }
        } catch (Throwable $e) {
            db_rollback();
        }
    }

    // ── Hard Delete Expired Vouchers > 3 Months ──
    try {
        $deleted = db_execute("DELETE FROM vouchers WHERE status = 'expired' AND expired_at < DATE_SUB(NOW(), INTERVAL 3 MONTH)");
        if ($deleted > 0) {
            $log("Auto-deleted {$deleted} vouchers that expired more than 3 months ago.");
        }
    } catch (Throwable $e) {
        $log("Error auto-deleting old vouchers: " . $e->getMessage());
    }
}

/**
 * Sinkronisasi dua arah sesi aktif Hotspot antara MikroTik dan FreeRADIUS radacct
 * Menjamin jumlah user di Web App dan Winbox selalu 100% konsisten.
 * @return array ['online' => bool, 'active_count' => int, 'restored' => int, 'created' => int, 'closed' => int]
 */
function sync_router_hotspot_active(array $router): array {
    $result = [
        'online'       => false,
        'active_count' => 0,
        'restored'     => 0,
        'created'      => 0,
        'closed'       => 0,
    ];

    require_once __DIR__ . '/../lib/routeros_api.class.php';

    $ip = $router['ip_address'];
    $nasIp = !empty($router['nas_ip']) && $router['nas_ip'] !== '0.0.0.0/0' ? $router['nas_ip'] : $ip;
    $routerId = (int)$router['id'];

    $api = new RouterosAPI();
    $api->timeout = 3;
    $api->attempts = 1;
    $api->debug = false;

    if (!$api->connect($ip, $router['api_user'], $router['api_password'], (int)$router['api_port'])) {
        return $result;
    }

    $result['online'] = true;
    db_execute("UPDATE routers SET last_seen = NOW() WHERE id = ?", 'i', [$routerId]);

    // Ambil data aktif dari Hotspot MikroTik
    $hsActive = $api->comm('/ip/hotspot/active/print');
    if (!is_array($hsActive)) {
        $hsActive = [];
    }

    // Ambil dari PPP jika ada
    $pppActive = $api->comm('/ppp/active/print');
    if (!is_array($pppActive)) {
        $pppActive = [];
    }

    $api->disconnect();

    $mikrotikUsers = [];
    $activeUsernames = [];

    foreach ($hsActive as $hs) {
        $u = $hs['user'] ?? '';
        if ($u !== '') {
            $mikrotikUsers[$u] = $hs;
            $activeUsernames[] = $u;
        }
    }

    foreach ($pppActive as $ppp) {
        $u = $ppp['name'] ?? '';
        if ($u !== '') {
            $activeUsernames[] = $u;
        }
    }

    $result['active_count'] = count($hsActive);

    // 1. REKONSILIASI KELUAR: Jika di radacct dibilang aktif untuk router ini, tapi di MikroTik TIDAK ADA -> tutup sesi ghost
    $radiusActive = db_fetch_all("
        SELECT ra.radacctid, ra.username, ra.acctstarttime, ra.acctsessiontime 
        FROM radacct ra
        LEFT JOIN vouchers v ON v.username = ra.username
        WHERE ra.acctstoptime IS NULL 
          AND (ra.nasipaddress = ? OR ra.nasipaddress = ? OR v.router_id = ?)
    ", 'ssi', [$ip, $nasIp, $routerId]);

    foreach ($radiusActive as $ra) {
        $u = $ra['username'];
        if (!in_array($u, $activeUsernames)) {
            // Tutup sesi ghost
            db_execute("
                UPDATE radacct 
                SET acctstoptime = CASE 
                        WHEN acctsessiontime > 0 THEN DATE_ADD(acctstarttime, INTERVAL acctsessiontime SECOND)
                        ELSE acctstarttime 
                    END,
                    acctterminatecause = 'NAS-Error-API-Sync'
                WHERE radacctid = ?
            ", 'i', [$ra['radacctid']]);
            $result['closed']++;
        }
    }

    // 2. REKONSILIASI MASUK: Jika di MikroTik user AKTIF, pastikan ada sesi aktif (acctstoptime IS NULL) di radacct
    foreach ($mikrotikUsers as $u => $hs) {
        $hasOpen = db_fetch_one("SELECT radacctid FROM radacct WHERE username = ? AND acctstoptime IS NULL LIMIT 1", 's', [$u]);
        if ($hasOpen) {
            continue; // Sudah ada sesi aktif
        }

        // Cek apakah ada sesi baru saja tertutup (misal karena Session-Timeout-Ghost)
        $recentlyClosed = db_fetch_one("
            SELECT radacctid 
            FROM radacct 
            WHERE username = ? 
            ORDER BY radacctid DESC LIMIT 1
        ", 's', [$u]);

        if ($recentlyClosed) {
            // Reopen sesi
            db_execute("UPDATE radacct SET acctstoptime = NULL, acctterminatecause = '', nasipaddress = ? WHERE radacctid = ?", 'si', [$nasIp, $recentlyClosed['radacctid']]);
            $result['restored']++;
        } else {
            // Belum ada baris radacct sama sekali, buatkan baris baru
            $uptimeSec = parse_mikrotik_uptime_to_seconds($hs['uptime'] ?? '');
            $startTime = date('Y-m-d H:i:s', time() - max(0, $uptimeSec));
            $bytesIn   = (int)($hs['bytes-in'] ?? 0);
            $bytesOut  = (int)($hs['bytes-out'] ?? 0);
            $clientIp  = $hs['address'] ?? '';
            $clientMac = $hs['mac-address'] ?? '';
            $sessId    = $hs['.id'] ?? uniqid('hs_');

            db_execute("
                INSERT INTO radacct (
                    acctsessionid, acctuniqueid, username, nasipaddress,
                    framedipaddress, callingstationid, acctstarttime,
                    acctsessiontime, acctinputoctets, acctoutputoctets,
                    acctterminatecause
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '')",
                'sssssssiii',
                [$sessId, md5($u . $sessId . time()), $u, $nasIp, $clientIp, $clientMac, $startTime, $uptimeSec, $bytesIn, $bytesOut]
            );
            $result['created']++;
        }

        // Pastikan status voucher aktif jika unused
        db_execute("UPDATE vouchers SET status = 'active', used_at = COALESCE(used_at, NOW()) WHERE username = ? AND status = 'unused'", 's', [$u]);
    }

    return $result;
}

/**
 * Verify CSRF Token
 */
function csrf_verify() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $token = $_POST['csrf'] ?? $_GET['csrf'] ?? '';
    if (empty($token) || $token !== ($_SESSION['csrf_token'] ?? '')) {
        flash_set('error', 'Invalid CSRF token.');
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/index.php'));
        exit;
    }
}

/**
 * Format time elapsed
 */
function time_elapsed_string($datetime, $full = false) {
    if (!$datetime) return '';
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $weeks = floor($diff->d / 7);
    $days = $diff->d - ($weeks * 7);

    $string = array(
        'y' => array('val' => $diff->y, 'label' => 'tahun'),
        'm' => array('val' => $diff->m, 'label' => 'bulan'),
        'w' => array('val' => $weeks, 'label' => 'minggu'),
        'd' => array('val' => $days, 'label' => 'hari'),
        'h' => array('val' => $diff->h, 'label' => 'jam'),
        'i' => array('val' => $diff->i, 'label' => 'menit'),
        's' => array('val' => $diff->s, 'label' => 'detik'),
    );
    
    $result = [];
    foreach ($string as $k => $v) {
        if ($v['val']) {
            $result[] = $v['val'] . ' ' . $v['label'];
        }
    }

    if (!$full) $result = array_slice($result, 0, 1);
    return $result ? implode(', ', $result) . ' yang lalu' : 'baru saja';
}

// Helper for V1 Portal Migration
if (!function_exists('h')) {
    function h($str) {
        return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('csrfField')) {
    function csrfField() {
        return '<input type="hidden" name="csrf" value="' . ($_SESSION['csrf_token'] ?? '') . '">';
    }
}

if (!function_exists('logoB64')) {
    function logoB64() {
        $p = __DIR__ . '/../assets/img/logo.png';
        return file_exists($p) ? 'data:image/png;base64,' . base64_encode(file_get_contents($p)) : '';
    }
}

/**
 * Sinkronisasi konfigurasi profil ke seluruh voucher yang ada (unused & active),
 * memperbarui atribut radreply, menghitung ulang masa aktif (expired_at),
 * dan memutuskan (kick) sesi aktif di MikroTik jika diinginkan.
 *
 * @param int  $profile_id
 * @param bool $disconnect_active
 * @return array ['total_vouchers' => int, 'active_updated' => int, 'kicked_sessions' => int]
 */
function sync_profile_to_vouchers(int $profile_id, bool $disconnect_active = true): array {
    $profile = db_fetch_one("SELECT * FROM profiles WHERE id = ?", 'i', [$profile_id]);
    if (!$profile) {
        return ['total_vouchers' => 0, 'active_updated' => 0, 'kicked_sessions' => 0];
    }

    $rate_up        = $profile['rate_up'] ?: '0';
    $rate_down      = $profile['rate_down'] ?: '0';
    $rate_limit_val = rate_limit_attr($rate_up, $rate_down);
    $quota_mb       = (int)$profile['quota_mb'];
    $duration_s     = duration_to_seconds($profile['duration_value'], $profile['duration_unit']);
    $validity_s     = duration_to_seconds($profile['validity_value'] ?? 30, $profile['validity_unit'] ?? 'days');

    // Ambil semua voucher dengan profile_id ini yang berstatus unused atau active
    $vouchers = db_fetch_all("
        SELECT id, username, status, used_at, router_id
        FROM vouchers
        WHERE profile_id = ? AND status IN ('unused', 'active')
    ", 'i', [$profile_id]);

    if (empty($vouchers)) {
        return ['total_vouchers' => 0, 'active_updated' => 0, 'kicked_sessions' => 0];
    }

    $total_vouchers = count($vouchers);
    $active_updated = 0;
    $usernames = [];

    db_begin();
    try {
        $stmt_del_attr = db()->prepare("DELETE FROM radreply WHERE username = ? AND attribute = ?");
        $stmt_ins_attr = db()->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, ?, ?, ?)");

        $delete_reply = function(string $u, string $attr) use ($stmt_del_attr) {
            $stmt_del_attr->bind_param('ss', $u, $attr);
            $stmt_del_attr->execute();
        };

        $set_reply = function(string $u, string $attr, string $op, string $val) use ($delete_reply, $stmt_ins_attr) {
            $delete_reply($u, $attr);
            $stmt_ins_attr->bind_param('ssss', $u, $attr, $op, $val);
            $stmt_ins_attr->execute();
        };

        foreach ($vouchers as $v) {
            $u = $v['username'];
            $usernames[] = $u;

            // 1. Update Mikrotik-Rate-Limit
            if ($rate_limit_val !== '0/0') {
                $set_reply($u, 'Mikrotik-Rate-Limit', '=', $rate_limit_val);
            } else {
                $delete_reply($u, 'Mikrotik-Rate-Limit');
            }

            // 2. Update Mikrotik-Total-Limit (Kuota Data)
            if ($quota_mb > 0) {
                $quota_bytes = (string)mb_to_bytes($quota_mb);
                $set_reply($u, 'Mikrotik-Total-Limit', ':=', $quota_bytes);
            } else {
                $delete_reply($u, 'Mikrotik-Total-Limit');
            }

            // 3. Status-specific updates
            if ($v['status'] === 'unused') {
                // Session-Timeout
                if ($duration_s > 0) {
                    $set_reply($u, 'Session-Timeout', ':=', (string)$duration_s);
                } else {
                    $delete_reply($u, 'Session-Timeout');
                }
            } elseif ($v['status'] === 'active') {
                $active_updated++;

                // Jika used_at belum terisi tapi sudah aktif, coba cari dari radacct
                $used_at = $v['used_at'];
                if (empty($used_at)) {
                    $first_acct = db_fetch_one("SELECT acctstarttime FROM radacct WHERE username = ? ORDER BY acctstarttime ASC LIMIT 1", 's', [$u]);
                    if ($first_acct && !empty($first_acct['acctstarttime'])) {
                        $used_at = $first_acct['acctstarttime'];
                        db_execute("UPDATE vouchers SET used_at = ? WHERE id = ?", 'si', [$used_at, (int)$v['id']]);
                    }
                }

                // Hitung ulang expired_at (Masa Aktif / Validity)
                if ($used_at) {
                    $new_expired = date('Y-m-d H:i:s', strtotime($used_at) + $validity_s);
                    db_execute("UPDATE vouchers SET expired_at = ? WHERE id = ?", 'si', [$new_expired, (int)$v['id']]);
                }

                // Hitung ulang Session-Timeout (Durasi Pakai)
                if ($duration_s > 0) {
                    $used_closed = (int)(db_fetch_one("SELECT SUM(acctsessiontime) as used FROM radacct WHERE username = ? AND acctstoptime IS NOT NULL", 's', [$u])['used'] ?? 0);
                    $active_sessions = db_fetch_all("SELECT acctstarttime FROM radacct WHERE username = ? AND acctstoptime IS NULL", 's', [$u]);
                    $used_active = 0;
                    foreach ($active_sessions as $sess) {
                        $used_active += max(0, time() - strtotime($sess['acctstarttime']));
                    }
                    $used = $used_closed + $used_active;
                    $remaining = $duration_s - $used;

                    if ($remaining <= 0) {
                        db_execute("UPDATE vouchers SET expired_at = NOW() WHERE id = ?", 'i', [(int)$v['id']]);
                    } else {
                        $set_reply($u, 'Session-Timeout', ':=', (string)$remaining);
                    }
                } else {
                    $delete_reply($u, 'Session-Timeout');
                }
            }
        }

        db_commit();
    } catch (Throwable $e) {
        db_rollback();
        throw $e;
    }

    // 4. Kick sesi aktif di MikroTik jika diminta
    $kicked_sessions = 0;
    if ($disconnect_active && !empty($usernames)) {
        if (!defined('LIB_PATH'))    define('LIB_PATH', dirname(__DIR__) . '/lib');
        if (!defined('COA_PORT'))    define('COA_PORT', 3799);
        if (!defined('COA_TIMEOUT')) define('COA_TIMEOUT', 5);

        require_once LIB_PATH . '/radius_coa.php';

        $active_nas = db_fetch_all("
            SELECT ra.radacctid, ra.username, ra.nasipaddress, ra.acctsessionid
            FROM radacct ra
            JOIN vouchers v ON ra.username = v.username
            WHERE v.profile_id = ? AND ra.acctstoptime IS NULL
        ", 'i', [$profile_id]);

        if (!empty($active_nas)) {
            $sessions_by_nas = [];
            foreach ($active_nas as $sess) {
                $sessions_by_nas[$sess['nasipaddress']][] = $sess;
            }

            foreach ($sessions_by_nas as $nas_ip => $sess_list) {
                $router = db_fetch_one(
                    "SELECT * FROM routers WHERE ip_address = ? OR nas_ip = ? LIMIT 1",
                    'ss', [$nas_ip, $nas_ip]
                );

                if (!$router) continue;

                $coa_failed_users = [];
                $coa = null;
                try {
                    $coa = new RadiusCoA($router['ip_address'], $router['radius_secret'], COA_PORT, COA_TIMEOUT);
                } catch (Throwable $e) {}

                foreach ($sess_list as $sess) {
                    $sess_user = $sess['username'];
                    $sess_id   = $sess['acctsessionid'] ?? null;
                    $disconnected = false;

                    if ($coa) {
                        try {
                            if ($coa->disconnect($sess_user, $sess_id)) {
                                $disconnected = true;
                                $kicked_sessions++;
                            }
                        } catch (Throwable $e) {}
                    }

                    if (!$disconnected) {
                        $coa_failed_users[] = $sess_user;
                    }
                }

                if (!empty($coa_failed_users)) {
                    try {
                        require_once LIB_PATH . '/routeros_api.class.php';
                        $api = new RouterosAPI();
                        $api->debug = false;
                        if ($api->connect($router['ip_address'], $router['api_user'], $router['api_password'], (int)$router['api_port'])) {
                            foreach ($coa_failed_users as $cf_user) {
                                $active = $api->comm('/ip/hotspot/active/print', ['?user' => $cf_user]);
                                if (!empty($active)) {
                                    foreach ($active as $au) {
                                        if (isset($au['.id'])) {
                                            $api->comm('/ip/hotspot/active/remove', ['.id' => $au['.id']]);
                                            $kicked_sessions++;
                                        }
                                    }
                                }
                            }
                            $api->disconnect();
                        }
                    } catch (Throwable $e) {}
                }
            }
        }
    }

    // 5. Jalankan run_auto_expire_vouchers jika ada voucher yang tanggal kadaluarsanya menjadi lampau
    run_auto_expire_vouchers();

    return [
        'total_vouchers'  => $total_vouchers,
        'active_updated'  => $active_updated,
        'kicked_sessions' => $kicked_sessions,
    ];
}

/**
 * Cek status transaksi langsung ke Midtrans REST API
 */
function check_midtrans_order_status(string $orderId): ?array {
    $orderId = trim($orderId);
    if (empty($orderId)) return null;

    $settings_raw = db_fetch_all("SELECT setting_key, setting_value FROM pppoe_settings WHERE setting_key IN ('midtrans_server_key', 'midtrans_mode')");
    $settings = [];
    foreach ($settings_raw as $s) {
        $settings[$s['setting_key']] = $s['setting_value'];
    }

    $serverKey = $settings['midtrans_server_key'] ?? '';
    if (empty($serverKey)) return null;

    $mode = $settings['midtrans_mode'] ?? 'sandbox';
    $baseUrl = ($mode === 'production') 
        ? 'https://api.midtrans.com/v2/' 
        : 'https://api.sandbox.midtrans.com/v2/';

    $url = $baseUrl . urlencode($orderId) . '/status';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 2, // Timeout cepat untuk mencegah thread PHP-FPM menggantung
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode($serverKey . ':')
        ]
    ]);

    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err || empty($res)) return null;

    $data = json_decode($res, true);
    if (!is_array($data) || empty($data['transaction_status'])) return null;

    return $data;
}

/**
 * Buka isolir pelanggan PPPoE secara menyeluruh:
 * - Update database status menjadi 'active'
 * - Update MikroTik secret profile kembali ke normal
 * - Kick active session di MikroTik agar dial ulang dan lepas dari IP isolir (10.10.99.x)
 * - Sync FreeRADIUS (radcheck, radreply, radusergroup)
 * - Reboot ONT via GenieACS jika terdapat Serial Number
 */
function unisolir_pppoe_customer(int $customerId): array {
    $customer = db_fetch_one("SELECT * FROM pppoe_customers WHERE id = ?", 'i', [$customerId]);
    if (!$customer) {
        return ['success' => false, 'message' => 'Pelanggan tidak ditemukan.'];
    }

    $u = $customer['pppoe_username'];
    $normalProfile = !empty($customer['profile']) ? $customer['profile'] : 'default';
    $routerId = (int)$customer['router_id'];

    // 1. Update Database Status
    db_execute(
        "UPDATE pppoe_customers SET status = 'active', isolated_at = NULL, isolated_reason = '' WHERE id = ?",
        'i', [$customerId]
    );

    // 2. Update MikroTik Secret & Kick Active Session
    $mtSuccess = false;
    $mtMsg = '';
    $router = db_fetch_one("SELECT * FROM routers WHERE id = ?", 'i', [$routerId]);
    if ($router) {
        try {
            $apiFile = (defined('LIB_PATH') ? LIB_PATH : __DIR__ . '/../lib') . '/routeros_api.class.php';
            if (file_exists($apiFile)) {
                require_once $apiFile;
            }
            if (class_exists('RouterosAPI')) {
                $api = new RouterosAPI();
                $api->debug = false;
                $api->timeout = 2; // Timeout pendek
                $api->attempts = 1;
                $api->delay = 0;
                if ($api->connect($router['ip_address'], $router['api_user'], $router['api_password'], (int)$router['api_port'])) {
                    // Update secret profile ke normal
                    $secs = $api->comm('/ppp/secret/print', ['?name' => $u]);
                    if (!empty($secs) && isset($secs[0]['.id'])) {
                        $api->comm('/ppp/secret/set', [
                            '.id'      => $secs[0]['.id'],
                            'profile'  => $normalProfile,
                            'disabled' => 'no'
                        ]);
                    } else {
                        $api->comm('/ppp/secret/add', [
                            'name'     => $u,
                            'password' => (string)rand(10000, 99999),
                            'profile'  => $normalProfile,
                            'service'  => 'pppoe',
                            'disabled' => 'no'
                        ]);
                    }

                    // PENTING: Putus / kick sesi aktif agar dial ulang dengan profile dan IP normal
                    $acts = $api->comm('/ppp/active/print', ['?name' => $u]);
                    foreach ($acts as $a) {
                        if (isset($a['.id'])) {
                            $api->comm('/ppp/active/remove', ['.id' => $a['.id']]);
                        }
                    }

                    $api->disconnect();
                    $mtSuccess = true;
                    $mtMsg = "MikroTik sync berhasil.";
                } else {
                    $mtMsg = 'Gagal terhubung ke router MikroTik.';
                }
            }
        } catch (Throwable $e) {
            $mtMsg = 'MikroTik error: ' . $e->getMessage();
        }
    }

    // 3. Update FreeRADIUS (radreply & radusergroup)
    try {
        $chkReply = db_fetch_one("SELECT id FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Group'", 's', [$u]);
        if ($chkReply) {
            db_execute("UPDATE radreply SET value = ? WHERE username = ? AND attribute = 'Mikrotik-Group'", 'ss', [$normalProfile, $u]);
        } else {
            db_execute("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Group', ':=', ?)", 'ss', [$u, $normalProfile]);
        }

        $chkGrp = db_fetch_one("SELECT id FROM radusergroup WHERE username = ?", 's', [$u]);
        if ($chkGrp) {
            db_execute("UPDATE radusergroup SET groupname = ? WHERE username = ?", 'ss', [$normalProfile, $u]);
        } else {
            db_execute("INSERT INTO radusergroup (username, groupname, priority) VALUES (?, ?, 1)", 'ss', [$u, $normalProfile]);
        }
    } catch (Throwable $e) {}

    // 4. Trigger Reboot ONT via GenieACS jika ONT SN terdaftar
    $ontRebooted = false;
    if (!empty($customer['ont_sn'])) {
        $sn = trim($customer['ont_sn']);
        try {
            $genieClassFile = __DIR__ . '/GenieACS.php';
            if (file_exists($genieClassFile)) {
                require_once $genieClassFile;
            }
            if (class_exists('GenieACS')) {
                $genieServer = null;
                if ($router && !empty($router['genie_server_id'])) {
                    $genieServer = db_fetch_one("SELECT * FROM genie_config WHERE id = ? AND is_active = 1", 'i', [$router['genie_server_id']]);
                }
                if (!$genieServer) {
                    $genieServer = db_fetch_one("SELECT * FROM genie_config WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
                }
                if (!$genieServer) {
                    $genieServer = db_fetch_one("SELECT * FROM genie_config ORDER BY id ASC LIMIT 1");
                }

                if ($genieServer) {
                    $gApi = new GenieACS($genieServer['url'], $genieServer['username'], $genieServer['password']);
                    $devs = $gApi->getDevices('{"_deviceId._SerialNumber": "'.$sn.'"}');
                    if (empty($devs)) {
                        $devs = $gApi->getDevices('{"_deviceId._SerialNumber": {"$regex": "'.preg_quote($sn).'", "$options": "i"}}');
                    }
                    if (!empty($devs) && isset($devs[0]['_id'])) {
                        $ontRebooted = $gApi->reboot($devs[0]['_id']);
                    }
                }
            }
        } catch (Throwable $e) {}
    }

    return [
        'success'      => true,
        'username'     => $u,
        'profile'      => $normalProfile,
        'mikrotik_ok'  => $mtSuccess,
        'ont_rebooted' => $ontRebooted,
        'message'      => "Isolir pelanggan $u berhasil dibuka. Profil kembali ke $normalProfile."
    ];
}

/**
 * Rekonsiliasi Otomatis Buka Isolir:
 * 1. Cek transaksi Midtrans pending, sinkronkan dengan API Midtrans jika sudah settlement.
 * 2. Cek semua pelanggan berstatus 'isolated' yang sudah memiliki pembayaran lunas (atau berstatus gratis).
 * 3. Otomatis jalankan unisolir_pppoe_customer() untuk mengembalikan ke profil normal & kick sesi isolir.
 */
function auto_unisolir_paid_customers(?int $router_id = null, bool $force = false): int {
    // ── Throttle Lock: Maksimal jalan 1x per 3 menit untuk mencegah server overload & high iowait ──
    if (!$force && !defined('IS_CRON') && php_sapi_name() !== 'cli') {
        static $localRan = false;
        if ($localRan) return 0;
        $localRan = true;

        $lockFile = sys_get_temp_dir() . '/snet_unisolir_throttle.lock';
        if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 180) {
            return 0; // Skip jika sudah jalan dalam 3 menit terakhir
        }
        @touch($lockFile);
    }

    // ── Self-healing Index: pastikan database memiliki index optimal ──
    try {
        $idxCheck = db_fetch_one("SHOW INDEX FROM pppoe_payments WHERE Key_name = 'idx_pay_cust_period'");
        if (!$idxCheck) {
            @db_execute("ALTER TABLE pppoe_payments ADD INDEX idx_pay_cust_period (customer_id, period_year, period_month)");
            @db_execute("ALTER TABLE pppoe_payments ADD INDEX idx_pay_status (midtrans_status, payment_method)");
            @db_execute("ALTER TABLE pppoe_payments ADD INDEX idx_pay_order_id (midtrans_order_id)");
        }
        $idxCust = db_fetch_one("SHOW INDEX FROM pppoe_customers WHERE Key_name = 'idx_cust_router_status'");
        if (!$idxCust) {
            @db_execute("ALTER TABLE pppoe_customers ADD INDEX idx_cust_router_status (router_id, status)");
        }
    } catch (Throwable $e) {}

    // 1. Cek pembayaran pending Midtrans yang aktif dalam 48 jam terakhir (maksimal 5 transaksi)
    try {
        $todayStr = date('Ymd');
        $yestStr  = date('Ymd', strtotime('-1 day'));
        $pendingSql = "SELECT pp.*, pc.id as cid, pc.status as cust_status 
                       FROM pppoe_payments pp 
                       JOIN pppoe_customers pc ON pp.customer_id = pc.id 
                       WHERE pp.midtrans_status = 'pending' 
                         AND pp.payment_method = 'midtrans' 
                         AND (pp.midtrans_order_id LIKE 'INV-{$todayStr}%' OR pp.midtrans_order_id LIKE 'INV-{$yestStr}%')";
        if ($router_id && $router_id > 0) {
            $pendingSql .= " AND pc.router_id = " . (int)$router_id;
        }
        $pendingSql .= " ORDER BY pp.id DESC LIMIT 5";
        $pendings = db_fetch_all($pendingSql);

        foreach ($pendings as $p) {
            $st = check_midtrans_order_status($p['midtrans_order_id']);
            if ($st && isset($st['transaction_status'])) {
                $txStatus = $st['transaction_status'];
                $fraudStatus = $st['fraud_status'] ?? '';
                if (in_array($txStatus, ['settlement', 'capture']) && in_array($fraudStatus, ['accept', ''])) {
                    db_execute("UPDATE pppoe_payments SET midtrans_status = 'paid', midtrans_tx_id = ? WHERE id = ?", 'si', [$st['transaction_id'] ?? '', $p['id']]);
                    if ($p['cust_status'] === 'isolated') {
                        unisolir_pppoe_customer((int)$p['cid']);
                    }
                    send_pppoe_payment_notification((int)$p['id'], 'Sistem Online (Midtrans)');
                } elseif (in_array($txStatus, ['cancel', 'deny', 'expire'])) {
                    db_execute("UPDATE pppoe_payments SET midtrans_status = ? WHERE id = ?", 'si', [$txStatus, $p['id']]);
                }
            }
        }
    } catch (Throwable $e) {}

    // 2. Scan pelanggan yang masih 'isolated' padahal sudah lunas atau gratis
    $isoSql = "SELECT pc.* FROM pppoe_customers pc WHERE pc.status = 'isolated'";
    $params = [];
    $types = "";
    if ($router_id && $router_id > 0) {
        $isoSql .= " AND pc.router_id = ?";
        $params[] = $router_id;
        $types .= "i";
    }

    $isolatedList = db_fetch_all($isoSql, $types, $params);
    $unisolatedCount = 0;

    $m1 = (int)date('n');
    $y1 = (int)date('Y');
    $m2 = $m1 - 1;
    $y2 = $y1;
    if ($m2 == 0) { $m2 = 12; $y2--; }

    foreach ($isolatedList as $cust) {
        // Cek jika bebas iuran / gratis
        if ((isset($cust['is_free']) && (int)$cust['is_free'] === 1) || (float)$cust['monthly_price'] <= 0) {
            unisolir_pppoe_customer((int)$cust['id']);
            $unisolatedCount++;
            continue;
        }

        // Cek pembayaran lunas di bulan berjalan atau bulan sebelumnya
        $paidCheck = db_fetch_one(
            "SELECT COUNT(*) as c FROM pppoe_payments 
             WHERE customer_id = ? 
               AND ((period_month = ? AND period_year = ?) OR (period_month = ? AND period_year = ?))
               AND (midtrans_status = 'paid' OR payment_method = 'cash' OR (midtrans_status NOT IN ('pending','cancel','deny','expire') AND midtrans_status IS NOT NULL))",
            'iiiii', [$cust['id'], $m1, $y1, $m2, $y2]
        );

        if ($paidCheck && (int)$paidCheck['c'] > 0) {
            unisolir_pppoe_customer((int)$cust['id']);
            $unisolatedCount++;
        }
    }

    return $unisolatedCount;
}

/**
 * Kirim notifikasi WhatsApp bukti pembayaran berhasil / lunas ke pelanggan
 * Berlaku untuk pembayaran cash/manual oleh teknisi/kasir maupun online (Midtrans)
 */
function send_pppoe_payment_notification(int $paymentId, ?string $adminOrCollector = null, bool $force = false): array {
    $pay = db_fetch_one(
        "SELECT pp.*, pc.full_name, pc.pppoe_username, pc.phone, pc.profile 
         FROM pppoe_payments pp 
         JOIN pppoe_customers pc ON pp.customer_id = pc.id 
         WHERE pp.id = ?",
        'i', [$paymentId]
    );

    if (!$pay) {
        return ['success' => false, 'message' => 'Data pembayaran tidak ditemukan.'];
    }

    if (empty($pay['phone'])) {
        return ['success' => false, 'message' => 'Nomor WhatsApp pelanggan tidak tercatat.'];
    }

    $receiptNo = $pay['midtrans_order_id'] ?: ('INV-' . str_pad($pay['id'], 6, '0', STR_PAD_LEFT));

    if (!$force) {
        // Cegah duplikasi: cek apakah notifikasi dengan no_invoice ini sudah pernah sukses dikirim ke pelanggan
        $alreadySent = db_fetch_one(
            "SELECT id FROM wa_logs 
             WHERE customer_id = ? 
               AND message_type = 'payment_success' 
               AND status = 'success' 
               AND message_text LIKE ? 
             LIMIT 1",
            'is', [$pay['customer_id'], '%' . $receiptNo . '%']
        );
        if ($alreadySent) {
            return ['success' => true, 'message' => 'Notifikasi pembayaran sudah pernah terkirim sebelumnya.'];
        }
    }

    require_once __DIR__ . '/WhatsAppGateway.php';
    $template = WhatsAppGateway::getTemplate('payment_success');
    if (!$template) {
        return ['success' => false, 'message' => 'Template pesan payment_success tidak ditemukan.'];
    }

    $monthNames = [
        1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',5=>'Mei',6=>'Juni',
        7=>'Juli',8=>'Agustus',9=>'September',10=>'Oktober',11=>'November',12=>'Desember'
    ];
    $monthLabel = ($monthNames[(int)$pay['period_month']] ?? $pay['period_month']) . ' ' . $pay['period_year'];

    // Ambil setting perusahaan
    $settings_raw = db_fetch_all("SELECT setting_key, setting_value FROM pppoe_settings");
    $settings = [];
    foreach ($settings_raw as $s) {
        $settings[$s['setting_key']] = $s['setting_value'];
    }
    $companyName = $settings['company_name'] ?? (defined('APP_COMPANY') ? APP_COMPANY : 'S.NET Internet');
    $csPhone     = $settings['company_phone'] ?? '081234567890';

    $collector = $adminOrCollector;
    if (empty($collector)) {
        if ($pay['payment_method'] === 'midtrans') {
            $collector = 'Sistem Online (Midtrans)';
        } elseif (!empty($pay['notes'])) {
            $collector = $pay['notes'];
        } else {
            $collector = 'Kasir / Petugas';
        }
    }

    $receiptLink = 'https://' . WhatsAppGateway::getAppDomain() . '/portal/receipt.php?id=' . $pay['id'];

    $waktuBayar = !empty($pay['paid_at']) 
        ? date('d M Y, H:i', strtotime($pay['paid_at'])) . ' WIB' 
        : date('d M Y, H:i') . ' WIB';

    $msgBody = WhatsAppGateway::renderTemplate($template['message'], [
        'full_name'      => $pay['full_name'],
        'pppoe_username' => $pay['pppoe_username'],
        'amount'         => $pay['amount'],
        'monthly_price'  => $pay['amount'],
        'month_name'     => $monthLabel,
        'no_invoice'     => $receiptNo,
        'waktu_bayar'    => $waktuBayar,
        'link_receipt'   => $receiptLink,
        'company_name'   => $companyName,
        'cs_phone'       => $csPhone,
        'metode'         => strtoupper($pay['payment_method'] ?: 'CASH'),
        'payment_method' => strtoupper($pay['payment_method'] ?: 'CASH'),
        'diterima_oleh'  => $collector,
        'admin_name'     => $collector,
        'notes'          => $pay['notes'] ?: ''
    ]);

    $wa = WhatsAppGateway::getInstance();
    $res = $wa->send($pay['phone'], $msgBody, (int)$pay['customer_id'], 'payment_success', $pay['full_name']);

    if ($res['success']) {
        audit_log('wa_payment_sent', "Notifikasi WA bayar lunas dikirim ke {$pay['full_name']} ({$pay['phone']}) - #{$receiptNo}");
    }

    return $res;
}

/**
 * Menghitung ringkasan penagihan reseller berdasarkan router dan profile.
 * Skema baru:
 * - Menentukan waktu penagihan terakhir (last_billed_at) untuk profile & router ini.
 * - Menghitung voucher yang laku (status active/expired) SEJAK penagihan terakhir.
 * - Mengambil sisa voucher laku / selisih (tekor/lebih) dari penagihan terakhir.
 * - Menghasilkan voucher_aktual = max(0, vcr_baru + sisa_sebelumnya).
 * - Jika $ignore_previous_deficit true, sisa tekor dari masa lalu diabaikan.
 */
function get_reseller_billing_summary(int $router_id, int $profile_id, bool $ignore_previous_deficit = false): array {
    // 1. Cari penagihan terakhir untuk profile dan router ini
    $last_bill = db_fetch_one(
        "SELECT id, voucher_aktual, estimasi_voucher, status_kecocokan, created_at, tanggal 
         FROM penagihan 
         WHERE profile_id = ? AND router_id = ? 
         ORDER BY created_at DESC, id DESC 
         LIMIT 1",
        'ii',
        [$profile_id, $router_id]
    );

    $last_billed_at = null;
    $sisa_sebelumnya = 0;
    $last_status = null;

    if ($last_bill) {
        $last_billed_at = $last_bill['created_at'];
        $last_status = $last_bill['status_kecocokan'];
        if (!$ignore_previous_deficit) {
            if ($last_status === 'tekor') {
                $sisa_sebelumnya = max(0, (int)$last_bill['voucher_aktual'] - (int)$last_bill['estimasi_voucher']);
            } elseif ($last_status === 'lebih') {
                $sisa_sebelumnya = - max(0, (int)$last_bill['estimasi_voucher'] - (int)$last_bill['voucher_aktual']);
            } else {
                $sisa_sebelumnya = 0;
            }
        }
    }

    // 2. Hitung voucher yang laku
    if ($last_billed_at) {
        // Voucher yang laku sejak penagihan terakhir
        $vcr_baru = (int)(db_fetch_one(
            "SELECT COUNT(*) as c FROM vouchers 
             WHERE profile_id = ? 
               AND (router_id = ? OR router_id IS NULL)
               AND status IN ('active', 'expired') 
               AND COALESCE(used_at, created_at) > ?",
            'iis',
            [$profile_id, $router_id, $last_billed_at]
        )['c'] ?? 0);
    } else {
        // Belum pernah ditagih sama sekali: hitung semua voucher yang sudah laku di router ini
        $vcr_baru = (int)(db_fetch_one(
            "SELECT COUNT(*) as c FROM vouchers 
             WHERE profile_id = ? 
               AND (router_id = ? OR router_id IS NULL)
               AND status IN ('active', 'expired')",
            'ii',
            [$profile_id, $router_id]
        )['c'] ?? 0);
    }

    $voucher_aktual = max(0, $vcr_baru + $sisa_sebelumnya);

    $tekor_count = (int)(db_fetch_one(
        "SELECT COUNT(*) as c FROM penagihan WHERE profile_id = ? AND router_id = ? AND status_kecocokan = 'tekor'", 
        'ii', 
        [$profile_id, $router_id]
    )['c'] ?? 0);

    return [
        'last_bill'         => $last_bill,
        'last_billed_at'    => $last_billed_at,
        'last_billed_date'  => $last_billed_at ? date('d/m/Y H:i', strtotime($last_billed_at)) : null,
        'last_status'       => $last_status,
        'vcr_baru'          => $vcr_baru,
        'sisa_sebelumnya'   => $sisa_sebelumnya,
        'voucher_aktual'    => $voucher_aktual,
        'unbilled_vouchers' => $voucher_aktual,
        'tekor_count'       => $tekor_count
    ];
}

/**
 * Pastikan kolom portal_password_plain tersedia di tabel pppoe_customers
 * agar password portal pelanggan dapat dilihat oleh Admin/Operator saat lupa sandi.
 */
function ensure_portal_password_schema(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $col = db_fetch_one("SHOW COLUMNS FROM pppoe_customers LIKE 'portal_password_plain'");
        if (!$col) {
            db_execute("ALTER TABLE pppoe_customers ADD COLUMN portal_password_plain VARCHAR(255) DEFAULT '' AFTER portal_password");
        }
        // Sinkronisasi otomatis data password yang masih kosong dari FreeRADIUS radcheck jika ada
        db_execute("UPDATE pppoe_customers pc 
                    JOIN radcheck rc ON rc.username = pc.pppoe_username AND rc.attribute = 'Cleartext-Password'
                    SET pc.portal_password_plain = rc.value
                    WHERE (pc.portal_password_plain IS NULL OR pc.portal_password_plain = '') AND rc.value != ''");
    } catch (Throwable $e) {}
}

ensure_portal_password_schema();

/**
 * Cek status layanan FreeRADIUS di server
 * Memeriksa status systemd / proses, port UDP 1812 & 1813, versi, uptime, dan PID
 */
function get_freeradius_status(): array {
    $status = [
        'is_active'       => false,
        'status_label'    => 'Nonaktif',
        'service_name'    => 'freeradius',
        'pid'             => null,
        'version'         => null,
        'port_1812'       => false, // UDP Auth
        'port_1813'       => false, // UDP Acct
        'uptime'          => null,
        'uptime_human'    => null,
        'memory_human'    => null,
        'systemd_state'   => 'unknown',
        'details'         => '',
    ];

    // 1. Cek Service via systemctl (Ubuntu / Debian / CentOS)
    $serviceNames = ['freeradius', 'radiusd'];
    $activeService = null;
    $systemdState = 'unknown';

    foreach ($serviceNames as $svc) {
        $out = @shell_exec("systemctl is-active {$svc} 2>/dev/null");
        if ($out !== null) {
            $state = trim($out);
            if ($state === 'active') {
                $status['is_active'] = true;
                $activeService = $svc;
                $systemdState = 'active';
                break;
            } elseif ($state !== '' && $systemdState === 'unknown') {
                $systemdState = $state;
                $activeService = $svc;
            }
        }
    }

    if (!$activeService) {
        $activeService = 'freeradius';
    }
    $status['service_name'] = $activeService;
    $status['systemd_state'] = $systemdState;

    // 2. Fallback cek proses PID jika systemctl gagal atau membatasi non-root
    $pid = null;
    if (!$status['is_active']) {
        $pidOut = @shell_exec("pgrep -x freeradius 2>/dev/null || pgrep -x radiusd 2>/dev/null || pidof freeradius 2>/dev/null || pidof radiusd 2>/dev/null");
        if ($pidOut) {
            $pids = preg_split('/\s+/', trim($pidOut));
            if (!empty($pids[0]) && is_numeric($pids[0])) {
                $pid = (int)$pids[0];
                $status['is_active'] = true;
                $status['systemd_state'] = 'running (process detected)';
            }
        }
    }

    // 3. Ambil PID, Uptime, dan Memory jika active
    if ($status['is_active']) {
        $status['status_label'] = 'Aktif (Running)';

        // Ambil info dari systemctl show
        $props = @shell_exec("systemctl show {$activeService} --property=MainPID,ActiveEnterTimestamp,MemoryCurrent 2>/dev/null");
        if ($props) {
            $lines = explode("\n", trim($props));
            foreach ($lines as $line) {
                if (strpos($line, '=') !== false) {
                    list($key, $val) = explode('=', $line, 2);
                    $key = trim($key);
                    $val = trim($val);
                    if ($key === 'MainPID' && is_numeric($val) && (int)$val > 0) {
                        $pid = (int)$val;
                    } elseif ($key === 'ActiveEnterTimestamp' && !empty($val)) {
                        $ts = strtotime($val);
                        if ($ts > 0) {
                            $diff = time() - $ts;
                            $status['uptime'] = $val;
                            $days  = floor($diff / 86400);
                            $hours = floor(($diff % 86400) / 3600);
                            $mins  = floor(($diff % 3600) / 60);
                            if ($days > 0) {
                                $status['uptime_human'] = "{$days} hari {$hours} jam";
                            } elseif ($hours > 0) {
                                $status['uptime_human'] = "{$hours} jam {$mins} menit";
                            } else {
                                $status['uptime_human'] = "{$mins} menit";
                            }
                        }
                    } elseif ($key === 'MemoryCurrent' && is_numeric($val) && (int)$val > 0) {
                        $status['memory_human'] = round(((int)$val) / (1024 * 1024), 1) . ' MB';
                    }
                }
            }
        }

        // Jika PID belum dapat dari systemctl, gunakan pgrep
        if (!$pid) {
            $pidOut = @shell_exec("pgrep -x {$activeService} 2>/dev/null || pidof {$activeService} 2>/dev/null");
            if ($pidOut) {
                $pids = preg_split('/\s+/', trim($pidOut));
                if (!empty($pids[0]) && is_numeric($pids[0])) {
                    $pid = (int)$pids[0];
                }
            }
        }

        // Ambil memory dari /proc jika MemoryCurrent kosong
        if ($pid && empty($status['memory_human']) && file_exists("/proc/{$pid}/status")) {
            $procStatus = @file_get_contents("/proc/{$pid}/status");
            if ($procStatus && preg_match('/VmRSS:\s+(\d+)\s+kB/i', $procStatus, $mRss)) {
                $status['memory_human'] = round(((int)$mRss[1]) / 1024, 1) . ' MB';
            }
        }
    } else {
        $status['status_label'] = 'Nonaktif / Berhenti';
    }

    $status['pid'] = $pid;

    // 4. Deteksi Port UDP 1812 (Auth) & 1813 (Acct)
    $has1812 = false;
    $has1813 = false;

    foreach (['/proc/net/udp', '/proc/net/udp6'] as $udpFile) {
        if (@file_exists($udpFile)) {
            $udpContent = @file_get_contents($udpFile);
            if ($udpContent !== false) {
                if (strpos($udpContent, ':0714') !== false) {
                    $has1812 = true;
                }
                if (strpos($udpContent, ':0715') !== false) {
                    $has1813 = true;
                }
            }
        }
    }

    if (!$has1812 || !$has1813) {
        $netOut = @shell_exec("ss -uln 2>/dev/null || netstat -uln 2>/dev/null");
        if ($netOut) {
            if (preg_match('/[:\s]1812\b/', $netOut)) {
                $has1812 = true;
            }
            if (preg_match('/[:\s]1813\b/', $netOut)) {
                $has1813 = true;
            }
        }
    }

    // Jika service jelas aktif tapi query soket tidak diizinkan oleh OS, default port aktif
    if ($status['is_active'] && (!$has1812 && !$has1813)) {
        $has1812 = true;
        $has1813 = true;
    }

    $status['port_1812'] = $has1812;
    $status['port_1813'] = $has1813;

    // 5. FreeRADIUS Version
    $verOut = @shell_exec("freeradius -v 2>&1 || /usr/sbin/freeradius -v 2>&1 || /usr/local/sbin/freeradius -v 2>&1 || radiusd -v 2>&1");
    if ($verOut) {
        $firstLine = strtok(trim($verOut), "\r\n");
        if ($firstLine && (stripos($firstLine, 'freeradius') !== false || stripos($firstLine, 'version') !== false)) {
            $status['version'] = $firstLine;
        }
    }

    return $status;
}

/**
 * Restart service FreeRADIUS
 * Menggunakan opsi sudo -n (non-interactive) agar TIDAK PERNAH menggantung / timeout jika butuh password
 */
function restart_freeradius_service(): array {
    $outputs = [];

    // 1. Coba via sudo -n systemctl (non-blocking)
    @exec('sudo -n /usr/bin/systemctl restart freeradius 2>&1', $out1, $ret1);
    if ($ret1 === 0) {
        return ['success' => true, 'message' => 'Service FreeRADIUS berhasil direstart via systemctl.'];
    }
    $outputs[] = implode(' ', (array)$out1);

    // 2. Coba via systemctl langsung tanpa sudo
    @exec('systemctl restart freeradius 2>&1', $out2, $ret2);
    if ($ret2 === 0) {
        return ['success' => true, 'message' => 'Service FreeRADIUS berhasil direstart.'];
    }
    $outputs[] = implode(' ', (array)$out2);

    // 3. Coba service freeradius restart via sudo -n
    @exec('sudo -n /usr/sbin/service freeradius restart 2>&1', $out3, $ret3);
    if ($ret3 === 0) {
        return ['success' => true, 'message' => 'Service FreeRADIUS berhasil direstart via service.'];
    }
    $outputs[] = implode(' ', (array)$out3);

    // 4. Coba service freeradius restart langsung
    @exec('service freeradius restart 2>&1', $out4, $ret4);
    if ($ret4 === 0) {
        return ['success' => true, 'message' => 'Service FreeRADIUS berhasil direstart.'];
    }
    $outputs[] = implode(' ', (array)$out4);

    // 5. Cek apakah service radiusd (nama di CentOS/RHEL)
    @exec('sudo -n systemctl restart radiusd 2>&1', $out5, $ret5);
    if ($ret5 === 0) {
        return ['success' => true, 'message' => 'Service radiusd berhasil direstart.'];
    }

    $combinedErr = trim(implode(' | ', array_filter($outputs)));
    $webUser = trim(@shell_exec('whoami 2>/dev/null') ?: 'www');
    if (!$webUser) $webUser = 'www';

    $isAuthErr = (stripos($combinedErr, 'password is required') !== false || stripos($combinedErr, 'Interactive authentication required') !== false || stripos($combinedErr, 'Access denied') !== false);

    if ($isAuthErr) {
        $msg = "Izin sudo diperlukan agar user web server (<strong>{$webUser}</strong>) dapat merestart service. Jalankan 1 kali perintah ini di terminal SSH server:<br><code class='d-block my-2 p-2 bg-dark text-white rounded font-monospace' style='font-size:0.82rem;'>echo '{$webUser} ALL=(ALL) NOPASSWD: /usr/bin/systemctl restart freeradius, /usr/bin/systemctl start freeradius, /usr/bin/systemctl stop freeradius, /usr/bin/systemctl status freeradius, /usr/sbin/service freeradius *' | sudo tee /etc/sudoers.d/freeradius &amp;&amp; sudo chmod 0440 /etc/sudoers.d/freeradius</code>";
    } else {
        $msg = 'Gagal merestart FreeRADIUS dari web server. ' . ($combinedErr ? "Detail: {$combinedErr}" : 'Izin sudo diperlukan.');
    }

    return [
        'success' => false,
        'message' => $msg
    ];
}

/**
 * Sinkronisasi cepat seluruh data aplikasi ke tabel mesin FreeRADIUS:
 * - Hotspot Vouchers -> radcheck, radreply (Batch Insert 500 baris = instan < 1 detik)
 * - PPPoE Customers -> radcheck, radreply, radusergroup
 * - Router NAS -> tabel nas
 * - Restart / reload service FreeRADIUS (non-blocking)
 */
function execute_radius_sync(): array {
    @set_time_limit(300);
    @ini_set('memory_limit', '512M');

    // Lepaskan session lock agar request web browser tidak freeze / hanging
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $db = db();

    // 1. Bersihkan tabel autentikasi RADIUS seketika
    $db->query("TRUNCATE TABLE `radcheck`");
    $db->query("TRUNCATE TABLE `radreply`");
    $db->query("TRUNCATE TABLE `radusergroup`");

    // ── 2. SINKRONISASI HOTSPOT VOUCHERS (BATCH INSERT CEPAT) ──
    $vouchers = db_fetch_all("
        SELECT v.username, v.password, p.duration_value, p.duration_unit, p.quota_mb, p.rate_up, p.rate_down 
        FROM vouchers v 
        JOIN profiles p ON v.profile_id = p.id 
        WHERE v.status IN ('unused', 'active')
    ");

    $batchRadcheck = [];
    $batchRadreply = [];
    $voucherCount = 0;

    $flushRadcheck = function() use (&$batchRadcheck, $db) {
        if (!empty($batchRadcheck)) {
            $sql = "INSERT INTO `radcheck` (`username`, `attribute`, `op`, `value`) VALUES " . implode(",\n", $batchRadcheck);
            $db->query($sql);
            $batchRadcheck = [];
        }
    };

    $flushRadreply = function() use (&$batchRadreply, $db) {
        if (!empty($batchRadreply)) {
            $sql = "INSERT INTO `radreply` (`username`, `attribute`, `op`, `value`) VALUES " . implode(",\n", $batchRadreply);
            $db->query($sql);
            $batchRadreply = [];
        }
    };

    foreach ($vouchers as $v) {
        $u = trim($v['username']);
        $p = trim($v['password'] ?? '');
        if (!$u) continue;
        if (!$p) $p = $u;

        $safeU = "'" . $db->real_escape_string($u) . "'";
        $safeP = "'" . $db->real_escape_string($p) . "'";

        $batchRadcheck[] = "($safeU, 'Cleartext-Password', ':=', $safeP)";
        $batchRadcheck[] = "($safeU, 'Simultaneous-Use', ':=', '1')";

        $dur_s = duration_to_seconds((int)$v['duration_value'], (string)$v['duration_unit']);
        if ($dur_s > 0) {
            $batchRadreply[] = "($safeU, 'Session-Timeout', ':=', '{$dur_s}')";
        }

        $rl = rate_limit_attr($v['rate_up'] ?: '0', $v['rate_down'] ?: '0');
        if ($rl !== '0/0') {
            $safeRl = "'" . $db->real_escape_string($rl) . "'";
            $batchRadreply[] = "($safeU, 'Mikrotik-Rate-Limit', '=', $safeRl)";
        }

        $qm = (int)$v['quota_mb'];
        if ($qm > 0) {
            $qb = (string)mb_to_bytes($qm);
            $batchRadreply[] = "($safeU, 'Mikrotik-Total-Limit', ':=', '{$qb}')";
        }

        $voucherCount++;

        if (count($batchRadcheck) >= 500) {
            $flushRadcheck();
        }
        if (count($batchRadreply) >= 500) {
            $flushRadreply();
        }
    }
    $flushRadcheck();
    $flushRadreply();

    // ── 3. SINKRONISASI PPPOE RUMAHAN ──
    $pppoeCount = 0;
    $batchUsergroup = [];
    $flushUsergroup = function() use (&$batchUsergroup, $db) {
        if (!empty($batchUsergroup)) {
            $sql = "INSERT INTO `radusergroup` (`username`, `groupname`, `priority`) VALUES " . implode(",\n", $batchUsergroup);
            $db->query($sql);
            $batchUsergroup = [];
        }
    };

    try {
        $pppoe_cust = db_fetch_all("SELECT * FROM pppoe_customers");
        foreach ($pppoe_cust as $c) {
            $u = trim($c['pppoe_username'] ?? '');
            $p = trim($c['portal_password_plain'] ?: $c['portal_password'] ?: $c['pppoe_username']);
            $status  = $c['status'] ?? 'active';
            $profile = trim($c['profile'] ?? 'default');
            if (!$u) continue;

            $safeU = "'" . $db->real_escape_string($u) . "'";

            if ($status !== 'suspended') {
                if ($p) {
                    $safeP = "'" . $db->real_escape_string($p) . "'";
                    $batchRadcheck[] = "($safeU, 'Cleartext-Password', ':=', $safeP)";
                }
                $batchRadcheck[] = "($safeU, 'Simultaneous-Use', ':=', '1')";

                $actualProfile = ($status === 'isolated') ? 'ISOLIR' : $profile;
                $safeProf = "'" . $db->real_escape_string($actualProfile) . "'";

                $batchRadreply[] = "($safeU, 'Framed-Protocol', ':=', 'PPP')";
                $batchRadreply[] = "($safeU, 'Mikrotik-Group', ':=', $safeProf)";
                $batchUsergroup[] = "($safeU, $safeProf, 1)";
            } else {
                $batchRadcheck[] = "($safeU, 'Auth-Type', ':=', 'Reject')";
            }
            $pppoeCount++;

            if (count($batchRadcheck) >= 500) {
                $flushRadcheck();
            }
            if (count($batchRadreply) >= 500) {
                $flushRadreply();
            }
            if (count($batchUsergroup) >= 500) {
                $flushUsergroup();
            }
        }
        $flushRadcheck();
        $flushRadreply();
        $flushUsergroup();
    } catch (Throwable $ePppoe) {}

    // ── 4. SINKRONISASI ROUTER & TABEL NAS ──
    $nasCount = 0;
    try {
        $db->query("TRUNCATE TABLE `nas`");
        $routers = db_fetch_all("SELECT * FROM routers WHERE (status != 'inactive' OR status IS NULL) AND radius_secret != ''");
        foreach ($routers as $r) {
            $name   = trim($r['name'] ?? 'MikroTik');
            $secret = trim($r['radius_secret'] ?? '');
            $nas_ip = trim($r['nas_ip'] ?? '');
            if (!$nas_ip || $nas_ip === '0.0.0.0/0') {
                $nas_ip = trim($r['ip_address'] ?? '0.0.0.0/0');
            }

            if ($secret) {
                db_execute("INSERT INTO nas (nasname, shortname, type, secret, description) VALUES (?, ?, 'other', ?, ?)",
                    'ssss', [$nas_ip, $name, $secret, $r['location'] ?: $name]);
                $nas_id = db_last_id();
                db_execute("UPDATE routers SET nas_id = ? WHERE id = ?", 'ii', [$nas_id, $r['id']]);

                $routerIp = trim($r['ip_address'] ?? '');
                if ($routerIp && $routerIp !== $nas_ip && $routerIp !== '0.0.0.0/0') {
                    db_execute("INSERT INTO nas (nasname, shortname, type, secret, description) VALUES (?, ?, 'other', ?, ?)",
                        'ssss', [$routerIp, $name . '_ip', $secret, $r['location'] ?: $name]);
                }
                $nasCount++;
            }
        }
    } catch (Throwable $eNas) {}

    // ── 5. RESTART SERVICE FREERADIUS (NON-BLOCKING) ──
    $restartRes = restart_freeradius_service();

    // Reopen session jika perlu
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }

    audit_log('sync_radius', 'all', 0, "Sinkronisasi FreeRADIUS: {$voucherCount} voucher, {$pppoeCount} PPPoE, {$nasCount} router NAS");

    return [
        'voucher_count' => $voucherCount,
        'pppoe_count'   => $pppoeCount,
        'nas_count'     => $nasCount,
        'restart'       => $restartRes,
    ];
}

/**
 * Reset & Hitung Ulang Data Pendapatan (Sales Log & Penagihan Reseller)
 * - Menghapus data sales_log dan penagihan yang amburadul / duplikat.
 * - Memverifikasi sesi login FreeRADIUS di radacct untuk voucher aktif/terpakai.
 * - Membangun kembali catatan penjualan bersih (1 voucher = 1 transaksi riil, bebas duplikasi).
 */
function rebuild_sales_and_penagihan(): array {
    ensure_profile_columns();

    $stats = [
        'cleared_sales'     => 0,
        'cleared_penagihan' => 0,
        'vouchers_synced'   => 0,
        'vouchers_expired'  => 0,
        'sales_rebuilt'     => 0,
        'total_revenue'     => 0.0,
        'routers_affected'  => 0,
    ];

    // 1. Catat jumlah data lama
    $old_sales = (int)(db_fetch_one("SELECT COUNT(*) as c FROM sales_log")['c'] ?? 0);
    $old_penagihan = (int)(db_fetch_one("SELECT COUNT(*) as c FROM penagihan")['c'] ?? 0);
    $stats['cleared_sales'] = $old_sales;
    $stats['cleared_penagihan'] = $old_penagihan;

    // 2. Sinkronkan voucher 'unused' yang ternyata sudah punya catatan login di radacct
    $unused_with_sessions = db_fetch_all("
        SELECT v.id, v.username, v.profile_id, p.validity_value, p.validity_unit,
               MIN(ra.acctstarttime) as first_login
        FROM vouchers v
        JOIN radacct ra ON v.username = ra.username
        JOIN profiles p ON v.profile_id = p.id
        WHERE v.status = 'unused' AND ra.acctstarttime IS NOT NULL
        GROUP BY v.id, v.username, v.profile_id, p.validity_value, p.validity_unit
    ");

    foreach ($unused_with_sessions as $uws) {
        $validity_s = duration_to_seconds($uws['validity_value'] ?? 30, $uws['validity_unit'] ?? 'days');
        $first_login = $uws['first_login'];
        $exp_time = strtotime($first_login) + $validity_s;
        $expired_at = date('Y-m-d H:i:s', $exp_time);
        $new_status = (time() >= $exp_time) ? 'expired' : 'active';

        db_execute(
            "UPDATE vouchers SET status = ?, used_at = ?, expired_at = ? WHERE id = ?",
            'sssi',
            [$new_status, $first_login, $expired_at, $uws['id']]
        );
        $stats['vouchers_synced']++;
    }

    // 3. Update voucher 'active' yang sudah habis masa aktifnya menjadi 'expired'
    $expired_update = db_execute("
        UPDATE vouchers 
        SET status = 'expired' 
        WHERE status = 'active' 
          AND expired_at IS NOT NULL 
          AND expired_at <= NOW()
    ");
    $stats['vouchers_expired'] = (int)$expired_update;

    // 4. Kosongkan tabel sales_log dan penagihan
    db_execute("DELETE FROM sales_log");
    try { db()->query("ALTER TABLE sales_log AUTO_INCREMENT = 1"); } catch (Throwable $e) {}

    db_execute("DELETE FROM penagihan");
    try { db()->query("ALTER TABLE penagihan AUTO_INCREMENT = 1"); } catch (Throwable $e) {}

    // 5. Ambil semua voucher yang valid terpakai (active & expired) yang tidak berstatus deleted
    // LEFT JOIN dengan MIN(acctstarttime) sebagai fallback jika used_at kosong
    $valid_vouchers = db_fetch_all("
        SELECT v.id AS voucher_id,
               v.username AS voucher_username,
               v.profile_id,
               p.name AS profile_name,
               COALESCE(v.router_id, p.router_id, 0) AS router_id,
               v.generated_by AS sold_by,
               COALESCE(p.price, 0) AS price,
               COALESCE(p.include_in_sales, 1) AS include_in_sales,
               COALESCE(v.used_at, ra_min.first_login, v.created_at) AS sold_at
        FROM vouchers v
        JOIN profiles p ON v.profile_id = p.id
        LEFT JOIN (
            SELECT username, MIN(acctstarttime) AS first_login
            FROM radacct
            GROUP BY username
        ) ra_min ON v.username = ra_min.username
        WHERE v.status IN ('active', 'expired')
          AND v.status != 'deleted'
        ORDER BY sold_at ASC, v.id ASC
    ");

    $batch = [];
    $total_rev = 0.0;
    $unique_routers = [];

    db_begin();
    try {
        foreach ($valid_vouchers as $vcr) {
            if ((int)$vcr['include_in_sales'] !== 1) {
                continue;
            }

            $price = (float)$vcr['price'];
            $rid = (int)$vcr['router_id'];
            if ($rid > 0) {
                $unique_routers[$rid] = true;
            }
            $total_rev += $price;

            $batch[] = [
                'voucher_id'       => (int)$vcr['voucher_id'],
                'voucher_username' => $vcr['voucher_username'],
                'profile_id'       => (int)$vcr['profile_id'],
                'profile_name'     => $vcr['profile_name'] ?? 'Hotspot',
                'router_id'        => $rid > 0 ? $rid : null,
                'sold_by'          => !empty($vcr['sold_by']) ? (int)$vcr['sold_by'] : null,
                'price'            => $price,
                'sold_at'          => $vcr['sold_at'] ?: date('Y-m-d H:i:s'),
            ];

            if (count($batch) >= 100) {
                _insert_sales_log_batch($batch);
                $stats['sales_rebuilt'] += count($batch);
                $batch = [];
            }
        }

        if (!empty($batch)) {
            _insert_sales_log_batch($batch);
            $stats['sales_rebuilt'] += count($batch);
            $batch = [];
        }

        db_commit();
    } catch (Throwable $e) {
        db_rollback();
        throw $e;
    }

    $stats['total_revenue'] = $total_rev;
    $stats['routers_affected'] = count($unique_routers);

    // 6. Catat riwayat audit
    if (function_exists('audit_log')) {
        $detail = sprintf(
            "Dihapus: %d riwayat penjualan & %d riwayat penagihan. Dihitung ulang: %d transaksi penjualan dipulihkan, total omset: Rp %s.",
            $stats['cleared_sales'],
            $stats['cleared_penagihan'],
            $stats['sales_rebuilt'],
            number_format($stats['total_revenue'], 0, ',', '.')
        );
        try {
            audit_log('rebuild_sales', "Reset & Hitung Ulang Pendapatan", 0, $detail);
        } catch (Throwable $e) {}
    }

    return $stats;
}

/**
 * Batch insert ke sales_log
 */
function _insert_sales_log_batch(array $rows): void {
    if (empty($rows)) return;
    $placeholders = [];
    $types = '';
    $params = [];

    foreach ($rows as $r) {
        $placeholders[] = "(?, ?, ?, ?, ?, ?, ?, ?)";
        $types .= "isisisds";
        $params[] = $r['voucher_id'];
        $params[] = $r['voucher_username'];
        $params[] = $r['profile_id'];
        $params[] = $r['profile_name'];
        $params[] = $r['router_id'];
        $params[] = $r['sold_by'];
        $params[] = $r['price'];
        $params[] = $r['sold_at'];
    }

    $sql = "INSERT INTO sales_log (voucher_id, voucher_username, profile_id, profile_name, router_id, sold_by, price, sold_at) VALUES " . implode(', ', $placeholders);
    db_execute($sql, $types, $params);
}


