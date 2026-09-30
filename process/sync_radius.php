<?php
/**
 * Process — Sync RADIUS Data
 * Membangun ulang seluruh data FreeRADIUS:
 * 1. Hotspot Vouchers -> radcheck, radreply
 * 2. PPPoE Customers -> radcheck, radreply, radusergroup
 * 3. Routers & NAS -> tabel nas (IP & RADIUS Secret)
 * 4. Restart / Reload service FreeRADIUS
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../include/functions.php';

auth_check();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /index.php?page=backup');
    exit;
}

// CSRF check
if (empty($_POST['csrf']) || $_POST['csrf'] !== ($_SESSION['csrf_token'] ?? '')) {
    flash_set('error', 'Token CSRF tidak valid. Silakan coba lagi.');
    header('Location: /index.php?page=backup');
    exit;
}

$admin = current_admin();
if ($admin['role'] !== 'superadmin') {
    flash_set('error', 'Hanya superadmin yang dapat melakukan sinkronisasi FreeRADIUS.');
    header('Location: /index.php?page=backup');
    exit;
}

@set_time_limit(300);
@ini_set('memory_limit', '256M');

db_begin();
try {
    $db = db();

    // 1. Bersihkan tabel autentikasi RADIUS
    $db->query("TRUNCATE TABLE radcheck");
    $db->query("TRUNCATE TABLE radreply");
    $db->query("TRUNCATE TABLE radusergroup");

    // ── 2. SINKRONISASI HOTSPOT VOUCHERS ──────────────────────
    $vouchers = db_fetch_all("
        SELECT v.username, v.password, p.duration_value, p.duration_unit, p.quota_mb, p.rate_up, p.rate_down 
        FROM vouchers v 
        JOIN profiles p ON v.profile_id = p.id 
        WHERE v.status IN ('unused', 'active')
    ");

    $voucherCount = 0;
    foreach ($vouchers as $v) {
        $u = trim($v['username']);
        $p = trim($v['password'] ?? '');
        if (!$u) continue;
        if (!$p) $p = $u; // Hotspot code-only mode: password is same as username

        // radcheck: Password & Single-Session
        db_execute("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', ?)", 'ss', [$u, $p]);
        db_execute("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Simultaneous-Use', ':=', '1')", 's', [$u]);

        // radreply: Session-Timeout
        $dur_s = duration_to_seconds((int)$v['duration_value'], (string)$v['duration_unit']);
        if ($dur_s > 0) {
            db_execute("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Session-Timeout', ':=', ?)", 'ss', [$u, (string)$dur_s]);
        }

        // radreply: Mikrotik-Rate-Limit
        $rl = rate_limit_attr($v['rate_up'] ?: '0', $v['rate_down'] ?: '0');
        if ($rl !== '0/0') {
            db_execute("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', ?)", 'ss', [$u, $rl]);
        }

        // radreply: Mikrotik-Total-Limit
        $qm = (int)$v['quota_mb'];
        if ($qm > 0) {
            $qb = (string)mb_to_bytes($qm);
            db_execute("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Total-Limit', ':=', ?)", 'ss', [$u, $qb]);
        }

        $voucherCount++;
    }

    // ── 3. SINKRONISASI PPPOE RUMAHAN ─────────────────────────
    $pppoeCount = 0;
    try {
        $pppoe_cust = db_fetch_all("SELECT * FROM pppoe_customers");
        foreach ($pppoe_cust as $c) {
            $u = trim($c['pppoe_username'] ?? '');
            $p = trim($c['portal_password_plain'] ?: $c['portal_password'] ?: $c['pppoe_username']);
            $status  = $c['status'] ?? 'active';
            $profile = trim($c['profile'] ?? 'default');
            if (!$u) continue;

            if ($status !== 'suspended') {
                if ($p) {
                    db_execute("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', ?)", 'ss', [$u, $p]);
                }
                db_execute("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Simultaneous-Use', ':=', '1')", 's', [$u]);

                $actualProfile = ($status === 'isolated') ? 'ISOLIR' : $profile;
                db_execute("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Framed-Protocol', ':=', 'PPP')", 's', [$u]);
                db_execute("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Group', ':=', ?)", 'ss', [$u, $actualProfile]);
                db_execute("INSERT INTO radusergroup (username, groupname, priority) VALUES (?, ?, 1)", 'ss', [$u, $actualProfile]);
            } else {
                db_execute("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Auth-Type', ':=', 'Reject')", 's', [$u]);
            }
            $pppoeCount++;
        }
    } catch (Throwable $ePppoe) {
        // Abaikan jika tabel pppoe_customers belum ada
    }

    // ── 4. SINKRONISASI ROUTER & TABEL NAS ────────────────────
    $nasCount = 0;
    try {
        $db->query("TRUNCATE TABLE nas");
        $routers = db_fetch_all("SELECT * FROM routers WHERE (status != 'inactive' OR status IS NULL) AND radius_secret != ''");
        foreach ($routers as $r) {
            $name   = trim($r['name'] ?? 'MikroTik');
            $secret = trim($r['radius_secret'] ?? '');
            $nas_ip = trim($r['nas_ip'] ?? '');
            if (!$nas_ip || $nas_ip === '0.0.0.0/0') {
                $nas_ip = trim($r['ip_address'] ?? '0.0.0.0/0');
            }

            if ($secret) {
                // Daftarkan IP router utama
                db_execute("INSERT INTO nas (nasname, shortname, type, secret, description) VALUES (?, ?, 'other', ?, ?)",
                    'ssss', [$nas_ip, $name, $secret, $r['location'] ?: $name]);
                $nas_id = db_last_id();
                db_execute("UPDATE routers SET nas_id = ? WHERE id = ?", 'ii', [$nas_id, $r['id']]);

                // Jika nas_ip bukan 0.0.0.0/0 dan berbeda dengan ip_address, daftarkan juga ip_address
                $routerIp = trim($r['ip_address'] ?? '');
                if ($routerIp && $routerIp !== $nas_ip && $routerIp !== '0.0.0.0/0') {
                    db_execute("INSERT INTO nas (nasname, shortname, type, secret, description) VALUES (?, ?, 'other', ?, ?)",
                        'ssss', [$routerIp, $name . '_ip', $secret, $r['location'] ?: $name]);
                }
                $nasCount++;
            }
        }
    } catch (Throwable $eNas) {
        // Abaikan jika tabel nas/routers bermasalah
    }

    db_commit();

    // ── 5. RESTART SERVICE FREERADIUS ────────────────────────
    $restartMsg = '';
    @exec('sudo systemctl restart freeradius 2>&1', $out1, $ret1);
    if ($ret1 !== 0) {
        @exec('systemctl restart freeradius 2>&1', $out2, $ret2);
        if ($ret2 !== 0) {
            @exec('service freeradius restart 2>&1', $out3, $ret3);
        }
    }

    audit_log('sync_radius', 'all', 0, "Sinkronisasi FreeRADIUS: {$voucherCount} voucher, {$pppoeCount} PPPoE, {$nasCount} router NAS");

    flash_set('success', "Sinkronisasi FreeRADIUS Berhasil! {$voucherCount} voucher hotspot, {$pppoeCount} akun PPPoE, dan {$nasCount} router NAS telah terdaftar aktif di FreeRADIUS.");
} catch (Throwable $e) {
    db_rollback();
    flash_set('error', 'Terjadi kesalahan saat sinkronisasi: ' . $e->getMessage());
}

header('Location: /index.php?page=backup');
exit;
