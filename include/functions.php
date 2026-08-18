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
                db_execute(
                    "INSERT INTO sales_log (voucher_id, voucher_username, profile_id, profile_name, router_id, price, sold_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?)",
                    'isissds', 
                    [$v['id'], $v['username'], $v['profile_id'], $v['profile_name'], $v['router_id'], $v['price'], $used_at]
                );
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
function run_auto_expire_vouchers($log = null) {
    if (!$log) $log = function($msg) {};

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
        "SELECT id, username FROM vouchers WHERE status = 'active' AND expired_at <= NOW() AND expired_at IS NOT NULL"
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

            // Kick from Mikrotik
            $acct = db_fetch_one("SELECT nasipaddress FROM radacct WHERE username = ? ORDER BY acctstarttime DESC LIMIT 1", 's', [$username]);
            if ($acct) {
                $router = db_fetch_one("SELECT ip_address, api_user, api_password, api_port FROM routers WHERE ip_address = ? OR nas_ip = ?", 'ss', [$acct['nasipaddress'], $acct['nasipaddress']]);
                if ($router) {
                    try {
                        $api = new RouterosAPI();
                        $api->debug = false;
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
