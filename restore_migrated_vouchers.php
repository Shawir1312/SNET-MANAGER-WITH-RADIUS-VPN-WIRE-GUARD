<?php
/**
 * S.NET RADIUS Manager — Migration & Recovery Tool: Restore Migrated Vouchers
 * 
 * Skrip ini menganalisis & memulihkan voucher yang salah ter-kadaluarsa (expired prematur)
 * akibat downtime migrasi VPS / server restart, di mana sesi menggantung radacct dihitung
 * sebagai durasi aktif hingga menghabiskan kuota waktu (Session-Timeout).
 * 
 * Dapat dijalankan via Web: https://portal.snetwifi.com/restore_migrated_vouchers.php
 * Atau via Terminal: sudo php restore_migrated_vouchers.php --fix
 */
define('CLI_MODE', php_sapi_name() === 'cli');

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/include/functions.php';

if (!CLI_MODE) {
    require_once __DIR__ . '/config/auth.php';
    // Akses web hanya untuk admin yang sudah login
    if (!is_logged_in()) {
        $adminCount = db_fetch_one("SELECT COUNT(*) AS n FROM admins");
        if ($adminCount && (int)$adminCount['n'] > 0) {
            auth_check();
        }
    }
}

$db = db();
$action = $_POST['action'] ?? (CLI_MODE && isset($argv[1]) && $argv[1] === '--fix' ? 'apply_restore_active' : '');
if (CLI_MODE && isset($argv[1]) && $argv[1] === '--reset') {
    $action = 'apply_reset_unused';
}

// ── 1. CARI VOUCHER YANG KADALUARSA PREMATUR ─────────────────────────────────
// Kriteria:
// Status 'expired', memiliki used_at, dan:
// a. Masa aktif profil (validity) aslinya sebenarnya belum habis (DATE_ADD(used_at, validity) > NOW()), ATAU
// b. Ter-expire dalam 3 hari terakhir (periode migrasi)
$query = "
    SELECT v.id, v.username, v.password, v.status, v.used_at, v.expired_at, v.created_at,
           v.profile_id, p.name AS profile_name, p.validity_value, p.validity_unit,
           p.duration_value, p.duration_unit, p.quota_mb, p.rate_up, p.rate_down,
           r.name AS router_name,
           (
               SELECT SUM(acctsessiontime) 
               FROM radacct 
               WHERE username = v.username AND acctstoptime IS NOT NULL
           ) AS total_used_seconds,
           (
               SELECT COUNT(*) 
               FROM radacct 
               WHERE username = v.username AND acctstoptime IS NULL
           ) AS ghost_session_count
    FROM vouchers v
    JOIN profiles p ON v.profile_id = p.id
    LEFT JOIN routers r ON v.router_id = r.id
    WHERE v.status = 'expired'
      AND v.used_at >= '2026-10-31 00:00:00'
    ORDER BY v.expired_at DESC
";
$candidate_vouchers = db_fetch_all($query);

// ── 2. EKSEKUSI PEMULIHAN JIKA DIMINTA ────────────────────────────────────────
$restore_results = [];
if (in_array($action, ['apply_restore_active', 'apply_reset_unused', 'restore_single'])) {
    $reset_mode = ($action === 'apply_reset_unused');
    $target_ids = [];

    if ($action === 'restore_single' && !empty($_POST['target_id'])) {
        $target_ids = [(int)$_POST['target_id']];
        $reset_mode = !empty($_POST['single_reset']);
    } else {
        $target_ids = array_column($candidate_vouchers, 'id');
    }

    $restored_count = 0;
    $failed_count   = 0;

    foreach ($target_ids as $vid) {
        $res = reactivate_voucher($vid, $reset_mode, 0);
        if ($res['success']) {
            $restored_count++;
        } else {
            $failed_count++;
        }
    }

    $restore_results = [
        'executed' => true,
        'action'   => $action,
        'restored' => $restored_count,
        'failed'   => $failed_count,
        'mode'     => $reset_mode ? 'Reset ke Belum Digunakan (Unused)' : 'Diaktifkan Kembali (Active)',
    ];

    // Refresh list setelah eksekusi
    $candidate_vouchers = db_fetch_all($query);
}

// ── 3. MODE CLI OUTPUT ────────────────────────────────────────────────────────
if (CLI_MODE) {
    echo "====================================================================\n";
    echo "  S.NET RADIUS Manager — Pemulihan Voucher Expired Migrasi\n";
    echo "====================================================================\n";
    echo "Ditemukan " . count($candidate_vouchers) . " voucher yang terindikasi salah kadaluarsa.\n\n";

    if (!empty($restore_results['executed'])) {
        echo "✓ HASIL EKSEKUSI:\n";
        echo "  - Berhasil dipulihkan : {$restore_results['restored']} voucher\n";
        echo "  - Gagal               : {$restore_results['failed']} voucher\n";
        echo "  - Mode                : {$restore_results['mode']}\n\n";
        exit(0);
    }

    if (empty($candidate_vouchers)) {
        echo "Tidak ada voucher yang perlu dipulihkan. Semua voucher dalam kondisi normal.\n";
        exit(0);
    }

    printf("%-12s | %-12s | %-16s | %-16s | %s\n", "USERNAME", "PROFIL", "LOGIN PERTAMA", "KADALUARSA", "SEHARUSNYA BERAKHIR");
    echo str_repeat("-", 85) . "\n";
    foreach ($candidate_vouchers as $cv) {
        $val_s = duration_to_seconds((int)$cv['validity_value'], $cv['validity_unit']);
        $should_exp = date('d/m/y H:i', strtotime($cv['used_at']) + $val_s);
        printf(
            "%-12s | %-12s | %-16s | %-16s | %s\n",
            $cv['username'],
            substr($cv['profile_name'], 0, 12),
            date('d/m/y H:i', strtotime($cv['used_at'])),
            date('d/m/y H:i', strtotime($cv['expired_at'])),
            $should_exp
        );
    }
    echo "\nUntuk memulihkan semua voucher ke status AKTIF kembali:\n";
    echo "  sudo php restore_migrated_vouchers.php --fix\n\n";
    echo "Untuk mereset semua voucher ke status BELUM DIGUNAKAN (Unused):\n";
    echo "  sudo php restore_migrated_vouchers.php --reset\n";
    exit(0);
}

// ── 4. MODE WEB UI ────────────────────────────────────────────────────────────
$page_title = "Pemulihan Voucher Migrasi";
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> — S.NET Manager</title>
    <link rel="stylesheet" href="/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/assets/css/app.css">
    <style>
        body { background-color: #f4f6f9; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        .hero-banner { background: linear-gradient(135deg, #1565C0 0%, #0d47a1 100%); color: white; border-radius: 12px; padding: 24px; margin-bottom: 24px; box-shadow: 0 4px 14px rgba(21, 101, 192, 0.2); }
        .card-custom { border: none; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.06); }
        .font-mono { font-family: SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
    </style>
</head>
<body class="py-4">
<div class="container" style="max-width: 1100px;">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <a href="/index.php?page=voucher_list" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Kembali ke Daftar Voucher
        </a>
        <a href="/index.php?page=backup" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-shield-check me-1"></i> Backup & FreeRADIUS Tools
        </a>
    </div>

    <div class="hero-banner">
        <div class="d-flex align-items-center gap-3">
            <div style="font-size: 2.8rem;"><i class="bi bi-magic"></i></div>
            <div>
                <h3 class="fw-bold mb-1">Alat Pemulihan Voucher Pasca-Migrasi</h3>
                <p class="mb-0 text-white-50">
                    Mendeteksi dan mengaktifkan kembali voucher yang salah kadaluarsa saat proses migrasi VPS atau restart server, serta menyinkronkan kembali autentikasi ke FreeRADIUS.
                </p>
            </div>
        </div>
    </div>

    <?php if (!empty($restore_results['executed'])): ?>
    <div class="alert alert-success alert-dismissible fade show card-custom mb-4" role="alert">
        <div class="d-flex align-items-center gap-2">
            <i class="bi bi-check-circle-fill fs-4 text-success"></i>
            <div>
                <h6 class="fw-bold mb-0">Pemulihan Voucher Selesai!</h6>
                <div>Berhasil memulihkan <strong><?= $restore_results['restored'] ?></strong> voucher ke mode <strong><?= $restore_results['mode'] ?></strong>. Auth radcheck &amp; radreply telah disinkronkan.</div>
            </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="card card-custom mb-4">
        <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
            <div>
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="bi bi-ticket-detailed text-primary me-2"></i>Daftar Voucher Terindikasi Salah Kadaluarsa
                </h5>
                <small class="text-muted">Total: <?= count($candidate_vouchers) ?> voucher terdeteksi</small>
            </div>
            <?php if (!empty($candidate_vouchers)): ?>
            <div class="d-flex gap-2">
                <form method="POST" onsubmit="return confirm('Aktifkan kembali SEMUA voucher di bawah ini? Voucher akan dapat login kembali di MikroTik.');">
                    <input type="hidden" name="action" value="apply_restore_active">
                    <button type="submit" class="btn btn-success fw-bold">
                        <i class="bi bi-arrow-clockwise me-1"></i> Pulihkan Semua ke Status AKTIF
                    </button>
                </form>
                <form method="POST" onsubmit="return confirm('PERINGATAN: Reset semua voucher di bawah ini ke status BELUM DIGUNAKAN (Unused)?');">
                    <input type="hidden" name="action" value="apply_reset_unused">
                    <button type="submit" class="btn btn-outline-warning btn-sm">
                        <i class="bi bi-arrow-repeat me-1"></i> Reset ke Unused
                    </button>
                </form>
            </div>
            <?php endif; ?>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Username</th>
                        <th>Profil & Router</th>
                        <th>Login Pertama</th>
                        <th>Kadaluarsa Palsu (DB)</th>
                        <th>Masa Berlaku Asli</th>
                        <th>Durasi Terpakai Riil</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($candidate_vouchers)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">
                            <i class="bi bi-check2-circle text-success display-4 d-block mb-2"></i>
                            <div class="fw-bold">Tidak ada voucher yang bermasalah!</div>
                            <small>Semua voucher aktif berjalan normal dan tidak ada yang salah ter-kadaluarsa.</small>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($candidate_vouchers as $cv): 
                        $val_s = duration_to_seconds((int)$cv['validity_value'], $cv['validity_unit']);
                        $real_exp_ts = strtotime($cv['used_at']) + $val_s;
                        $real_exp_str = date('d/m/Y H:i', $real_exp_ts);
                        $is_still_valid = ($real_exp_ts > time());
                        $used_sec = (int)($cv['total_used_seconds'] ?? 0);
                        $dur_limit = duration_to_seconds((int)$cv['duration_value'], $cv['duration_unit']);
                    ?>
                    <tr>
                        <td>
                            <span class="font-mono fw-bold text-primary"><?= htmlspecialchars($cv['username']) ?></span>
                            <?php if ($cv['ghost_session_count'] > 0): ?>
                            <span class="badge bg-warning-subtle text-warning border ms-1" title="Terdapat sesi menggantung radacct dari VPS lama">Ghost</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="fw-bold"><?= htmlspecialchars($cv['profile_name']) ?></div>
                            <small class="text-muted"><?= htmlspecialchars($cv['router_name'] ?? 'Semua Router') ?></small>
                        </td>
                        <td>
                            <i class="bi bi-box-arrow-in-right text-muted me-1"></i>
                            <?= date('d/m/y H:i', strtotime($cv['used_at'])) ?>
                        </td>
                        <td>
                            <span class="badge bg-danger-subtle text-danger border">
                                <i class="bi bi-clock-history me-1"></i><?= date('d/m/y H:i', strtotime($cv['expired_at'])) ?>
                            </span>
                        </td>
                        <td>
                            <div class="fw-bold <?= $is_still_valid ? 'text-success' : 'text-danger' ?>">
                                <?= $real_exp_str ?>
                            </div>
                            <small class="text-muted">
                                <?= $cv['validity_value'] ?> <?= $cv['validity_unit'] ?> sejak login
                            </small>
                        </td>
                        <td>
                            <div class="fw-semibold">
                                <?= seconds_to_human($used_sec) ?>
                                <?php if ($dur_limit > 0): ?>
                                <small class="text-muted">/ <?= seconds_to_human($dur_limit) ?></small>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="text-end">
                            <form method="POST" class="d-inline" onsubmit="return confirm('Aktifkan kembali voucher <?= htmlspecialchars($cv['username']) ?>?');">
                                <input type="hidden" name="action" value="restore_single">
                                <input type="hidden" name="target_id" value="<?= $cv['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-success">
                                    <i class="bi bi-arrow-clockwise me-1"></i> Pulihkan
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Informasi Penjelasan Teknis -->
    <div class="card card-custom border-start border-primary border-4 p-4">
        <h6 class="fw-bold text-dark mb-2"><i class="bi bi-info-circle-fill text-primary me-2"></i>Mengapa Voucher Sempat Kadaluarsa Saat Pindah VPS?</h6>
        <p class="text-muted mb-2" style="font-size: 0.9rem;">
            1. <strong>Sesi Menggantung (Ghost Session)</strong>: Saat database dicadangkan dari VPS lama, voucher yang sedang online memiliki sesi <code>radacct</code> tanpa waktu berhenti (<code>acctstoptime IS NULL</code>). Ketika dipulihkan di VPS baru yang baru dinyalakan beberapa jam kemudian, kalkulasi lama menganggap pengguna online tanpa henti selama downtime migrasi sehingga kuota durasi habis.
        </p>
        <p class="text-muted mb-2" style="font-size: 0.9rem;">
            2. <strong>Perbaikan yang Telah Diterapkan</strong>: Algoritma kini telah diperbarui dengan fungsi aman <code>get_active_session_used_seconds()</code> yang memeriksa laporan berkala (interim update) MikroTik dan tidak akan lagi menambah durasi fiktif jika server mati/restart.
        </p>
        <p class="text-muted mb-0" style="font-size: 0.9rem;">
            3. <strong>Penyelarasan Timezone</strong>: Pastikan timezone VPS Ubuntu sama dengan sistem aplikasi (<code>Asia/Jayapura / WIT</code>) dengan menjalankan perintah:
            <code class="d-block mt-1 p-2 bg-light rounded text-dark font-mono">sudo timedatectl set-timezone Asia/Jayapura && sudo systemctl restart freeradius mariadb</code>
        </p>
    </div>

</div>

<script src="/assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>

