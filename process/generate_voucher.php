<?php
/**
 * Process — Generate Voucher (POST handler)
 * Batch inserts into radcheck, radreply, vouchers
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../include/functions.php';

auth_check();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /index.php?page=generate_voucher');
    exit;
}

// CSRF check
if (empty($_POST['csrf']) || $_POST['csrf'] !== ($_SESSION['csrf_token'] ?? '')) {
    flash_set('error', 'Invalid CSRF token.');
    header('Location: /index.php?page=generate_voucher');
    exit;
}

// ── Validate Input ───────────────────────────────────────
$qty         = (int)post('qty', 0);
$profile_id  = (int)post('profile_id', 0);
$user_mode   = post('user_mode', 'vc');     // vc = username=password, up = separate
$char_length = (int)post('char_length', 8);
$char_type   = post('char_type', 'mix');
$prefix      = sanitize_username(post('prefix', ''));

$admin = current_admin();

if ($qty < 1 || $qty > VOUCHER_MAX_BATCH) {
    flash_set('error', "Jumlah voucher harus antara 1 dan " . VOUCHER_MAX_BATCH . ".");
    header('Location: /index.php?page=generate_voucher');
    exit;
}

if (!$profile_id) {
    flash_set('error', 'Pilih profil terlebih dahulu.');
    header('Location: /index.php?page=generate_voucher');
    exit;
}

// Load profile
$profile = db_fetch_one("SELECT * FROM profiles WHERE id = ? AND is_active = 1", 'i', [$profile_id]);
if (!$profile) {
    flash_set('error', 'Profil tidak ditemukan atau tidak aktif.');
    header('Location: /index.php?page=generate_voucher');
    exit;
}

// Router ID automatically derived from profile's router
$router_id = $profile['router_id'];
$router = null;
if ($router_id) {
    $router = get_router($router_id);
    if (!$router || !can_access_router($router_id)) {
        flash_set('error', 'Router untuk profil ini tidak ditemukan atau akses ditolak.');
        header('Location: /index.php?page=generate_voucher');
        exit;
    }
}

// ── Generate usernames ───────────────────────────────────
$batch_id   = generate_batch_id();
$duration_s = duration_to_seconds($profile['duration_value'], $profile['duration_unit']);
$quota_mb   = (int)$profile['quota_mb'];
$rate_up    = $profile['rate_up'] ?: '0';
$rate_down  = $profile['rate_down'] ?: '0';

$generated  = [];
$attempts   = 0;
$max_attempts = $qty * VOUCHER_RETRY;

// Collect existing usernames to avoid duplicates within this batch + DB
$existing_check_set = [];

while (count($generated) < $qty && $attempts < $max_attempts) {
    $attempts++;
    $username = $prefix . random_string($char_length, $char_type);
    $password = ($user_mode === 'vc') ? $username : random_string($char_length, $char_type);

    if (isset($existing_check_set[$username])) continue;

    // Check DB for uniqueness
    $exists = db_fetch_one("SELECT id FROM radcheck WHERE username = ? LIMIT 1", 's', [$username]);
    if ($exists) continue;

    $existing_check_set[$username] = true;
    $generated[] = compact('username', 'password');
}

if (empty($generated)) {
    flash_set('error', 'Gagal generate voucher. Coba kurangi jumlah atau perpanjang panjang karakter.');
    header('Location: /index.php?page=generate_voucher');
    exit;
}

// ── Batch Insert (transaction) ───────────────────────────
db_begin();
try {
    $now = date('Y-m-d H:i:s');

    $stmt_check  = db()->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', ?)");
    $stmt_check_simul = db()->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Simultaneous-Use', ':=', '1')");
    $stmt_reply1 = db()->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Session-Timeout', ':=', ?)");
    $stmt_reply2 = db()->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', ?)");
    $stmt_reply_interim = db()->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Acct-Interim-Interval', ':=', '120')");
    $stmt_quota = db()->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Total-Limit', ':=', ?)");
    $stmt_voucher = db()->prepare(
        "INSERT INTO vouchers (username, password, profile_id, router_id, batch_id, status, generated_by, created_at)
         VALUES (?, ?, ?, ?, ?, 'unused', ?, ?)"
    );

    $rate_limit_val = rate_limit_attr($rate_up, $rate_down);

    foreach ($generated as $v) {
        $u = $v['username'];
        $p = $v['password'];

        // radcheck
        $stmt_check->bind_param('ss', $u, $p);
        $stmt_check->execute();

        $stmt_check_simul->bind_param('s', $u);
        $stmt_check_simul->execute();

        // radreply: Session-Timeout
        if ($duration_s > 0) {
            $dur_str = (string)$duration_s;
            $stmt_reply1->bind_param('ss', $u, $dur_str);
            $stmt_reply1->execute();
        }

        // radreply: Mikrotik-Rate-Limit
        if ($rate_limit_val !== '0/0') {
            $stmt_reply2->bind_param('ss', $u, $rate_limit_val);
            $stmt_reply2->execute();
        }

        // radreply: Acct-Interim-Interval (Kirim update accounting ke FreeRADIUS tiap 2 menit)
        $stmt_reply_interim->bind_param('s', $u);
        $stmt_reply_interim->execute();

        // radreply: Mikrotik-Total-Limit (quota in bytes)
        if ($quota_mb > 0) {
            $quota_bytes = (string)mb_to_bytes($quota_mb);
            $stmt_quota->bind_param('ss', $u, $quota_bytes);
            $stmt_quota->execute();
        }

        // vouchers table
        $stmt_voucher->bind_param(
            'ssiisss',
            $u, $p, $profile_id, $router_id, $batch_id, $admin['id'], $now
        );
        $stmt_voucher->execute();
    }


    db_commit();

    // Audit log
    audit_log(
        'generate_voucher',
        $batch_id,
        $router_id ?? 0,
        json_encode(['qty' => count($generated), 'profile' => $profile['name'], 'char_length' => $char_length])
    );

    flash_set('success', count($generated) . " voucher berhasil di-generate! Batch: {$batch_id}");
    header("Location: /index.php?page=voucher_print&batch_id=" . urlencode($batch_id));
    exit;

} catch (Throwable $e) {
    db_rollback();
    flash_set('error', 'Gagal generate voucher: ' . $e->getMessage());
    header('Location: /index.php?page=generate_voucher');
    exit;
}
