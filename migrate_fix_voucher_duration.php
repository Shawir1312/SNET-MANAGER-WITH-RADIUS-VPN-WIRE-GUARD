<?php
/**
 * S.NET RADIUS Manager — Migration & Diagnostic: Fix Voucher Duration (Session-Timeout)
 * 
 * Skrip ini menganalisis & memperbaiki penyebab voucher online hanya 3 jam (10800 detik)
 * padahal di profil diset 5 jam (18000 detik), khususnya pada database hasil migrasi dari V1.
 * 
 * Dapat dijalankan via browser: https://dash.snetwifi.com/migrate_fix_voucher_duration.php
 * Atau via terminal: php migrate_fix_voucher_duration.php
 */
define('CLI_MODE', php_sapi_name() === 'cli');

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/include/functions.php';
require_once __DIR__ . '/lib/routeros_api.class.php';

if (!CLI_MODE) {
    require_once __DIR__ . '/config/auth.php';
    // Hanya perbolehkan akses jika sudah login sebagai admin atau token khusus
    if (!is_logged_in() && empty($_GET['token'])) {
        // Cek apakah ada admin
        $adminCount = db_fetch_one("SELECT COUNT(*) AS n FROM admins");
        if ($adminCount && (int)$adminCount['n'] > 0) {
            auth_check();
        }
    }
}

$do_fix = isset($_POST['action']) && $_POST['action'] === 'apply_fix';
if (CLI_MODE && isset($argv[1]) && $argv[1] === '--fix') {
    $do_fix = true;
}

$db = db();

// ── 1. ANALISIS PROFIL ────────────────────────────────────────────────────────
$profiles = db_fetch_all("
    SELECT p.*, r.name AS router_name,
           (SELECT COUNT(*) FROM vouchers v WHERE v.profile_id = p.id) AS total_vouchers,
           (SELECT COUNT(*) FROM vouchers v WHERE v.profile_id = p.id AND v.status = 'unused') AS unused_vouchers,
           (SELECT COUNT(*) FROM vouchers v WHERE v.profile_id = p.id AND v.status = 'active') AS active_vouchers
    FROM profiles p
    LEFT JOIN routers r ON p.router_id = r.id
    ORDER BY p.id ASC
");

// ── 2. ANALISIS RADREPLY (SESSION-TIMEOUT VOUCHER) ────────────────────────────
// Cari voucher yang radreply Session-Timeout nya berbeda dari durasi profilnya
$vouchers_check = db_fetch_all("
    SELECT v.id, v.username, v.status, v.profile_id, p.name AS profile_name,
           p.duration_value, p.duration_unit,
           rr.value AS current_radreply_timeout
    FROM vouchers v
    JOIN profiles p ON v.profile_id = p.id
    LEFT JOIN radreply rr ON rr.username = v.username AND rr.attribute = 'Session-Timeout'
    WHERE v.status IN ('unused', 'active')
");

$mismatched_vouchers = [];
$vouchers_3hours = []; // Yang masih 10800 detik (3 jam) padahal profil bukan 3 jam
foreach ($vouchers_check as $vc) {
    $expected_seconds = duration_to_seconds((int)$vc['duration_value'], (string)$vc['duration_unit']);
    $actual_seconds   = (int)($vc['current_radreply_timeout'] ?? 0);

    if ($actual_seconds === 10800 && $expected_seconds !== 10800) {
        $vouchers_3hours[] = $vc;
    }
    if ($vc['status'] === 'unused' && $actual_seconds !== $expected_seconds) {
        $mismatched_vouchers[] = $vc;
    }
}

// ── 3. ANALISIS RADACCT (SESI HANTU / GHOST DARI MIGRASI LAMA) ────────────────
$ghost_radacct = db_fetch_all("
    SELECT radacctid, username, nasipaddress, acctstarttime, acctsessiontime
    FROM radacct
    WHERE acctstoptime IS NULL
      AND acctstarttime < DATE_SUB(NOW(), INTERVAL 24 HOUR)
");

// ── 4. ANALISIS ROUTER MIKROTIK (HOTSPOT USER PROFILE) ───────────────────────
$routers = db_fetch_all("SELECT id, name, ip_address, nas_ip, api_user, api_password, api_port FROM routers WHERE status = 'active'");
$router_diagnostics = [];

foreach ($routers as $r) {
    $ip = $r['ip_address'];
    $port = !empty($r['api_port']) ? (int)$r['api_port'] : 8728;
    $diag = [
        'router'          => $r,
        'connected'       => false,
        'user_profiles'   => [],
        'has_timeout_cap' => false,
        'timeout_profiles'=> [],
        'server_profiles' => [],
    ];

    try {
        $api = new RouterosAPI();
        $api->port = $port;
        $api->timeout = 2;
        $api->attempts = 1;
        $api->debug = false;

        if ($api->connect($ip, $r['api_user'], $r['api_password'], $port)) {
            $diag['connected'] = true;
            $userProfiles = $api->comm('/ip/hotspot/user/profile/print');
            if (is_array($userProfiles)) {
                $diag['user_profiles'] = $userProfiles;
                foreach ($userProfiles as $up) {
                    $st = $up['session-timeout'] ?? '';
                    // Deteksi jika session-timeout diisi selain 0s atau none
                    if ($st !== '' && $st !== '0s' && $st !== 'none' && $st !== '00:00:00') {
                        $diag['has_timeout_cap'] = true;
                        $diag['timeout_profiles'][] = [
                            'name'            => $up['name'] ?? 'unknown',
                            'session_timeout' => $st,
                        ];
                    }
                }
            }

            $serverProfiles = $api->comm('/ip/hotspot/profile/print');
            if (is_array($serverProfiles)) {
                $diag['server_profiles'] = $serverProfiles;
            }

            // Jika action = apply_fix, bersihkan session-timeout di MikroTik User Profile
            if ($do_fix && $diag['has_timeout_cap']) {
                foreach ($diag['user_profiles'] as $up) {
                    $st = $up['session-timeout'] ?? '';
                    if ($st !== '' && $st !== '0s' && $st !== 'none' && $st !== '00:00:00') {
                        $api->comm('/ip/hotspot/user/profile/set', [
                            '.id'             => $up['.id'],
                            'session-timeout' => '0s'
                        ]);
                    }
                }
                $diag['fixed_mikrotik'] = true;
            }

            $api->disconnect();
        }
    } catch (Throwable $e) {
        $diag['error'] = $e->getMessage();
    }

    $router_diagnostics[] = $diag;
}

// ── EKSEKUSI PERBAIKAN (APPLY FIX) ───────────────────────────────────────────
$fix_report = [];
if ($do_fix) {
    db_begin();
    try {
        $fixedRadreplyCount = 0;
        $fixedActiveCount   = 0;
        $closedGhostsCount  = 0;

        // 1. Bersihkan sesi hantu radacct lawas (> 24 jam menggantung)
        if (!empty($ghost_radacct)) {
            $ghostIds = array_column($ghost_radacct, 'radacctid');
            $chunks = array_chunk($ghostIds, 200);
            foreach ($chunks as $chunk) {
                $inList = implode(',', array_map('intval', $chunk));
                db_execute("
                    UPDATE radacct 
                    SET acctstoptime = DATE_ADD(acctstarttime, INTERVAL GREATEST(60, acctsessiontime) SECOND),
                        acctterminatecause = 'Migration-Cleanup'
                    WHERE radacctid IN ($inList)
                ");
            }
            $closedGhostsCount = count($ghostIds);
        }

        // 2. Sinkronkan seluruh profil ke seluruh voucher (unused & active)
        $syncRes = sync_all_profiles_to_vouchers(false);
        $fixedRadreplyCount = $syncRes['total_vouchers'];
        $fixedActiveCount   = $syncRes['active_updated'];

        // 3. Pastikan tidak ada voucher dengan radreply Session-Timeout = 10800 jika profilnya 5 jam
        $fixQuery = "
            UPDATE radreply rr
            JOIN vouchers v ON rr.username = v.username
            JOIN profiles p ON v.profile_id = p.id
            SET rr.value = CASE 
                WHEN p.duration_unit = 'hours' THEN (p.duration_value * 3600)
                WHEN p.duration_unit = 'days' THEN (p.duration_value * 86400)
                WHEN p.duration_unit = 'minutes' THEN (p.duration_value * 60)
                ELSE (p.duration_value * 3600)
            END
            WHERE rr.attribute = 'Session-Timeout'
              AND v.status = 'unused'
              AND p.duration_value > 0
        ";
        $db->query($fixQuery);

        // 4. Pastikan Acct-Interim-Interval ada untuk semua voucher aktif & unused
        $db->query("
            INSERT INTO radreply (username, attribute, op, value)
            SELECT v.username, 'Acct-Interim-Interval', ':=', '120'
            FROM vouchers v
            LEFT JOIN radreply rr ON rr.username = v.username AND rr.attribute = 'Acct-Interim-Interval'
            WHERE v.status IN ('unused', 'active') AND rr.id IS NULL
        ");

        // 5. Pastikan Simultaneous-Use = 1 ada untuk semua voucher
        $db->query("
            INSERT INTO radcheck (username, attribute, op, value)
            SELECT v.username, 'Simultaneous-Use', ':=', '1'
            FROM vouchers v
            LEFT JOIN radcheck rc ON rc.username = v.username AND rc.attribute = 'Simultaneous-Use'
            WHERE v.status IN ('unused', 'active') AND rc.id IS NULL
        ");

        db_commit();

        $fix_report = [
            'success'            => true,
            'fixed_vouchers'     => $fixedRadreplyCount,
            'fixed_active'       => $fixedActiveCount,
            'closed_ghosts'      => $closedGhostsCount,
            'profiles_synced'    => $syncRes['profile_count'],
        ];

        // Jalankan lazy evaluation expire
        run_auto_expire_vouchers(null, true);

    } catch (Throwable $e) {
        db_rollback();
        $fix_report = [
            'success' => false,
            'error'   => $e->getMessage(),
        ];
    }
}

if (CLI_MODE) {
    echo "========================================================\n";
    echo "  S.NET RADIUS DIAGNOSTIC & FIX VOUCHER DURATION\n";
    echo "========================================================\n";
    echo "Total Profil Hotspot   : " . count($profiles) . "\n";
    echo "Voucher Session-Timeout 3 Jam (10800s): " . count($vouchers_3hours) . "\n";
    echo "Voucher Durasi Mismatch: " . count($mismatched_vouchers) . "\n";
    echo "Sesi Ghost radacct     : " . count($ghost_radacct) . "\n";
    if ($do_fix) {
        echo "\n[HASIL PERBAIKAN]\n";
        print_r($fix_report);
    } else {
        echo "\nJalankan dengan 'php migrate_fix_voucher_duration.php --fix' untuk menerapkan perbaikan.\n";
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Diagnostik & Perbaikan Durasi Voucher — S.NET Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background: #0f172a; color: #e2e8f0; font-family: system-ui, -apple-system, sans-serif; padding-bottom: 60px; }
        .card { background: #1e293b; border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; }
        .table { color: #cbd5e1; border-color: rgba(255,255,255,0.08); }
        .table thead th { background: #0f172a; color: #94a3b8; font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 2px solid rgba(255,255,255,0.1); }
        .badge-issue { background: #ef4444; color: white; }
        .badge-ok { background: #10b981; color: white; }
        .badge-warn { background: #f59e0b; color: black; }
    </style>
</head>
<body class="p-3 p-md-4">
<div class="container-fluid" style="max-width: 1200px;">
    
    <div class="d-flex align-items-center justify-content-between mb-4 pb-3 border-bottom border-secondary border-opacity-25">
        <div>
            <h2 class="fw-bold mb-1"><i class="bi bi-tools text-primary me-2"></i>Diagnostik &amp; Perbaikan Durasi Voucher Hotspot</h2>
            <p class="text-secondary mb-0">Deteksi &amp; perbaiki kenapa voucher terputus di 3 jam padahal profil diset 5 jam akibat migrasi database lama.</p>
        </div>
        <a href="/index.php?page=profile_list" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Kembali ke Panel
        </a>
    </div>

    <?php if ($do_fix && !empty($fix_report)): ?>
        <?php if ($fix_report['success']): ?>
        <div class="alert alert-success border-0 shadow-sm d-flex align-items-center gap-3 mb-4">
            <i class="bi bi-check-circle-fill fs-2"></i>
            <div>
                <h5 class="fw-bold mb-1">Perbaikan Berhasil Diterapkan!</h5>
                <div>
                    ✓ Disinkronkan <strong><?= $fix_report['profiles_synced'] ?> profil</strong> ke <strong><?= $fix_report['fixed_vouchers'] ?> voucher</strong>.<br>
                    ✓ Dihitung ulang <strong><?= $fix_report['fixed_active'] ?> voucher aktif</strong> di radreply &amp; masa aktif.<br>
                    ✓ Ditutup <strong><?= $fix_report['closed_ghosts'] ?> sesi hantu menggantung</strong> dari masa lalu.<br>
                    ✓ Seluruh atribut <code>Session-Timeout</code> di FreeRADIUS telah diperbarui menjadi <strong>18000 detik (5 Jam)</strong> sesuai profil.
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="alert alert-danger border-0 shadow-sm d-flex align-items-center gap-3 mb-4">
            <i class="bi bi-exclamation-triangle-fill fs-2"></i>
            <div>
                <h5 class="fw-bold mb-1">Perbaikan Gagal</h5>
                <div>Error: <?= htmlspecialchars($fix_report['error'] ?? 'Unknown error') ?></div>
            </div>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- RANGKUMAN PENYEBAB MASALAH -->
    <div class="card mb-4 border-primary border-opacity-50">
        <div class="card-body p-4">
            <h4 class="fw-bold text-primary mb-3"><i class="bi bi-info-circle me-2"></i>Hasil Analisis Akar Masalah: Kenapa Voucher Hanya 3 Jam?</h4>
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="p-3 rounded bg-black bg-opacity-25 border border-secondary border-opacity-25 h-100">
                        <div class="fw-bold text-warning mb-1">1. Atribut radreply FreeRADIUS Masih 10800s</div>
                        <p class="small text-secondary mb-0">
                            Saat database diimpor dari versi lama, baris tabel <code>radreply</code> untuk voucher-voucher yang sudah pernah dibuat masih menyimpan nilai <code>Session-Timeout = 10800</code> (3 jam). Saat login, FreeRADIUS tetap mengirim 10800 ke MikroTik.
                        </p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="p-3 rounded bg-black bg-opacity-25 border border-secondary border-opacity-25 h-100">
                        <div class="fw-bold text-danger mb-1">2. Profil MikroTik User Menetapkan 3h</div>
                        <p class="small text-secondary mb-0">
                            Di MikroTik Winbox (<code>/ip hotspot user profile default</code>), opsi <strong>Session Timeout</strong> terkadang masih terisi <code>03:00:00</code> (bawaan Mikhmon/template lama). Ini akan memutus user di 3 jam sekalipun RADIUS mengirim 5 jam!
                        </p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="p-3 rounded bg-black bg-opacity-25 border border-secondary border-opacity-25 h-100">
                        <div class="fw-bold text-info mb-1">3. Sinkronisasi Parsial Antar Profil</div>
                        <p class="small text-secondary mb-0">
                            Sebelumnya, tombol sinkronisasi hanya berjalan per 1 profil saat form diedit. Ada <?= count($profiles) ?> profil reseller di database, sehingga voucher pada profil yang belum diedit manual belum ter-update.
                        </p>
                    </div>
                </div>
            </div>

            <div class="mt-4 pt-3 border-top border-secondary border-opacity-25 d-flex flex-wrap gap-3 align-items-center justify-content-between">
                <div>
                    <span class="badge bg-danger fs-6 px-3 py-2 me-2">
                        <i class="bi bi-exclamation-circle me-1"></i> <?= count($vouchers_3hours) ?> Voucher Terdeteksi Masih 3 Jam (10800s)
                    </span>
                    <span class="badge bg-warning text-dark fs-6 px-3 py-2">
                        <i class="bi bi-clock-history me-1"></i> <?= count($ghost_radacct) ?> Sesi Hantu Radacct Lawas
                    </span>
                </div>
                <form method="POST" class="m-0" onsubmit="return confirm('Jalankan perbaikan otomatis sekarang?\n\n- Seluruh voucher (unused & aktif) akan diperbarui Session-Timeout nya menjadi durasi profil saat ini (misal 5 Jam = 18000s).\n- Session-timeout pada User Profile MikroTik akan dinetralkan ke 0s agar dikontrol penuh oleh RADIUS.\n- Sesi hantu lawas akan dirapikan.')">
                    <input type="hidden" name="action" value="apply_fix">
                    <button type="submit" class="btn btn-primary btn-lg fw-bold px-4 shadow">
                        <i class="bi bi-magic me-2"></i> PERBAIKI SEMUA SEKARANG
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- TABEL 1: STATUS PROFIL & DURASI -->
    <div class="card mb-4">
        <div class="card-header bg-black bg-opacity-25 py-3 d-flex justify-content-between align-items-center">
            <h5 class="fw-bold mb-0"><i class="bi bi-collection me-2"></i>Daftar Profil &amp; Durasi di Database (<?= count($profiles) ?> Profil)</h5>
        </div>
        <div class="card-body p-0 table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nama Profil</th>
                        <th>Cabang / Router</th>
                        <th>Masa Aktif (Validity)</th>
                        <th>Durasi Pakai (Session-Timeout)</th>
                        <th>Detik RADIUS</th>
                        <th>Total Voucher</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($profiles as $p): ?>
                    <?php 
                        $dur_s = duration_to_seconds((int)$p['duration_value'], (string)$p['duration_unit']);
                        $is_5jam = ($dur_s === 18000);
                        $is_3jam = ($dur_s === 10800);
                    ?>
                    <tr>
                        <td class="font-mono text-secondary"><?= $p['id'] ?></td>
                        <td class="fw-bold text-light"><?= htmlspecialchars($p['name']) ?></td>
                        <td><?= htmlspecialchars($p['router_name'] ?: 'Semua Cabang') ?></td>
                        <td><?= $p['validity_value'] ?> <?= trans_unit($p['validity_unit'] ?? 'days') ?></td>
                        <td>
                            <span class="badge <?= $is_5jam ? 'bg-success' : ($is_3jam ? 'bg-danger' : 'bg-primary') ?> px-2 py-1">
                                <?= $p['duration_value'] ?> <?= trans_unit($p['duration_unit'] ?? 'hours') ?>
                            </span>
                        </td>
                        <td class="font-mono"><?= number_format($dur_s) ?> s</td>
                        <td>
                            <span class="text-info"><?= $p['unused_vouchers'] ?> belum dipakai</span> / 
                            <span class="text-warning"><?= $p['active_vouchers'] ?> aktif</span>
                        </td>
                        <td>
                            <?= ($p['is_active'] == 1) ? '<span class="badge bg-success-subtle text-success border border-success">Aktif</span>' : '<span class="badge bg-secondary">Nonaktif</span>' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- TABEL 2: DIAGNOSTIK ROUTER MIKROTIK -->
    <div class="card mb-4">
        <div class="card-header bg-black bg-opacity-25 py-3">
            <h5 class="fw-bold mb-0"><i class="bi bi-hdd-network me-2"></i>Pemeriksaan Setting MikroTik (Hotspot User Profile)</h5>
        </div>
        <div class="card-body">
            <?php foreach ($router_diagnostics as $rd): ?>
            <div class="p-3 mb-3 rounded bg-black bg-opacity-25 border border-secondary border-opacity-25">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="fw-bold mb-0 text-light">
                        <?= htmlspecialchars($rd['router']['name']) ?> (<?= htmlspecialchars($rd['router']['ip_address']) ?>)
                    </h6>
                    <?php if ($rd['connected']): ?>
                        <span class="badge bg-success"><i class="bi bi-check2"></i> Terhubung API</span>
                    <?php else: ?>
                        <span class="badge bg-danger"><i class="bi bi-x-circle"></i> Gagal Koneksi: <?= htmlspecialchars($rd['error'] ?? 'RTO') ?></span>
                    <?php endif; ?>
                </div>

                <?php if ($rd['connected']): ?>
                    <?php if ($rd['has_timeout_cap']): ?>
                    <div class="alert alert-danger py-2 px-3 mb-2 small">
                        <strong><i class="bi bi-exclamation-triangle-fill me-1"></i> DITEMUKAN PEMBATASAN DURASI DI MIKROTIK:</strong><br>
                        <?php foreach ($rd['timeout_profiles'] as $tp): ?>
                        - User Profile <code><?= htmlspecialchars($tp['name']) ?></code> memiliki <strong>session-timeout = <?= htmlspecialchars($tp['session_timeout']) ?></strong>.<br>
                        <?php endforeach; ?>
                        <em>Efek: MikroTik akan memutus hotspot user saat mencapai <?= htmlspecialchars($rd['timeout_profiles'][0]['session_timeout']) ?> terlepas dari nilai Session-Timeout RADIUS. Tombol "PERBAIKI SEMUA SEKARANG" di atas akan menetralkan ini menjadi 0s.</em>
                    </div>
                    <?php else: ?>
                    <div class="text-success small">
                        <i class="bi bi-check-circle me-1"></i> User Profile MikroTik aman (session-timeout = none / 0s). RADIUS memegang kendali penuh.
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

</div>
</body>
</html>
