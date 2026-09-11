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
        CURLOPT_TIMEOUT        => 6,
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
                $api->timeout = 3;
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
                } else {
                    $mtMsg = 'Gagal terhubung ke router MikroTik.';
                }
            }
        } catch (Throwable $e) {
            $mtMsg = 'MikroTik error: ' . $e->getMessage();
        }
    }

    // 3. Sync FreeRADIUS ke profil normal
    try {
        db_execute("DELETE FROM radcheck WHERE username = ? AND attribute = 'Auth-Type'", 's', [$u]);
        
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
                    if (empty($devs)) {
                        $devs = $gApi->getDevices('{"_id": {"$regex": "'.preg_quote($sn).'", "$options": "i"}}');
                    }
                    if (empty($devs)) {
                        $devs = $gApi->searchDevices($sn);
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
function auto_unisolir_paid_customers(?int $router_id = null): int {
    // 1. Cek pembayaran pending Midtrans terbaru (maksimal 20 transaksi pending terakhir)
    try {
        $pendingSql = "SELECT pp.*, pc.id as cid, pc.status as cust_status 
                       FROM pppoe_payments pp 
                       JOIN pppoe_customers pc ON pp.customer_id = pc.id 
                       WHERE pp.midtrans_status = 'pending' 
                         AND pp.payment_method = 'midtrans' 
                         AND pp.midtrans_order_id != ''";
        if ($router_id && $router_id > 0) {
            $pendingSql .= " AND pc.router_id = " . (int)$router_id;
        }
        $pendingSql .= " ORDER BY pp.id DESC LIMIT 20";
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
function send_pppoe_payment_notification(int $paymentId, ?string $adminOrCollector = null): array {
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

    $receiptLink = 'https://' . ($_SERVER['HTTP_HOST'] ?? 's.shawir.id') . '/portal/receipt.php?id=' . $pay['id'];

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



