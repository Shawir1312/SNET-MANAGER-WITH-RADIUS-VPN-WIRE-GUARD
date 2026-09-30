<?php
/**
 * S.NET V2 — Backup & Restore
 * Halaman backup database V2 dan restore data dari V1
 */
$page_title = 'Backup & Restore';
auth_require_superadmin();

// Tabel yang boleh di-restore dari V1 (whitelist ketat)
$V1_SAFE_TABLES = [
    'radcheck','radreply','radgroupcheck','radgroupreply',
    'radusergroup','radacct','radpostauth','nas',
    'routers','profiles','vouchers','admins',
    'audit_log','sales_log','penagihan'
];

// Tabel HANYA milik V2 — TIDAK BOLEH dihapus saat restore V1
$V2_ONLY_TABLES = [
    'pppoe_customers','pppoe_payments','pppoe_settings',
    'genie_config','customers','ont_configs','ont_remotes',
    'wg_routers','wg_settings','wg_port_forwards','wg_logs',
    'wa_config','wa_templates','wa_logs',
    'pppoe_bandwidth_snapshots'
];

$msg_ok    = '';
$msg_error = '';

// Helper: query langsung tanpa prepared statement (untuk nama tabel)
function bkQuery(string $sql) {
    return db()->query($sql);
}

// Deteksi jika server membuang POST karena melewati batas post_max_size
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $maxPost = ini_get('post_max_size');
    $msg_error = "File yang diupload melebihi batas 'post_max_size' server ($maxPost). Gunakan file terkompresi .sql.gz (ukuran ~5 MB) atau naikkan post_max_size & upload_max_filesize di php.ini / aaPanel.";
}

// ── HANDLE: Sinkronisasi FreeRADIUS Langsung (Anti 404 & Cepat) ──
if (isset($_POST['action']) && $_POST['action'] === 'sync_radius') {
    $csrf = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
        $msg_error = 'Token keamanan (CSRF) tidak valid.';
    } else {
        try {
            $resSync = execute_radius_sync();
            $vc = $resSync['voucher_count'];
            $pc = $resSync['pppoe_count'];
            $nc = $resSync['nas_count'];

            if ($resSync['restart']['success']) {
                $msg_ok = "Sinkronisasi FreeRADIUS Berhasil! {$vc} voucher hotspot, {$pc} akun PPPoE, dan {$nc} router NAS telah terdaftar di FreeRADIUS. Service FreeRADIUS aktif &amp; berhasil direload.";
            } else {
                $msg_ok = "Sinkronisasi Database Berhasil! {$vc} voucher hotspot, {$pc} akun PPPoE, dan {$nc} router NAS telah terdaftar di FreeRADIUS.";
                $msg_error = "Catatan: FreeRADIUS belum dapat direload otomatis oleh web server. Silakan buka menu Pengaturan &rarr; Status Service FreeRADIUS atau jalankan 'sudo systemctl restart freeradius' di terminal SSH.";
            }
        } catch (Throwable $e) {
            $msg_error = 'Terjadi kesalahan saat sinkronisasi: ' . $e->getMessage();
        }
    }
}

// ── HANDLE: Restore file SQL dari V1 ─────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'restore_v1') {
    @set_time_limit(600);
    @ini_set('memory_limit', '512M');

    $fileUpload = $_FILES['sql_file'] ?? null;
    $errCode    = $fileUpload['error'] ?? UPLOAD_ERR_NO_FILE;

    if ($errCode === UPLOAD_ERR_INI_SIZE || $errCode === UPLOAD_ERR_FORM_SIZE) {
        $maxUp = ini_get('upload_max_filesize');
        $msg_error = "File melebihi batas upload PHP server ($maxUp). Solusi: Download versi .sql.gz dari V1 (hanya ~5 MB) atau ubah upload_max_filesize di pengaturan PHP server.";
    } elseif ($errCode === UPLOAD_ERR_NO_FILE || empty($fileUpload['tmp_name'])) {
        $msg_error = 'Pilih file SQL terlebih dahulu.';
    } elseif ($errCode !== UPLOAD_ERR_OK) {
        $msg_error = "Upload gagal dengan kode error PHP: $errCode.";
    } elseif ($fileUpload['size'] > 300 * 1024 * 1024) {
        $msg_error = 'Ukuran file terlalu besar (maksimal 300 MB).';
    } else {
        $tmpFile  = $fileUpload['tmp_name'];
        $origName = $fileUpload['name'];
        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        if ($ext !== 'sql' && $ext !== 'gz') {
            $msg_error = 'Hanya file format .sql atau .sql.gz yang diperbolehkan.';
        } else {
            // Lepaskan session lock agar request lain / klik link tidak freeze / ngehang
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            $fp = @gzopen($tmpFile, 'r');
            if (!$fp) {
                $msg_error = 'Gagal membuka file SQL untuk dibaca.';
            } else {
                $db = db();

                // Optimasi performa bulk insert MariaDB / InnoDB
                $db->query("SET innodb_lock_wait_timeout = 5;");
                $db->query("SET autocommit = 1;");
                $db->query("SET unique_checks = 0;");
                $db->query("SET foreign_key_checks = 0;");

                $executedCount = 0;
                $skippedCount  = 0;
                $errorCount    = 0;
                $errorDetails  = [];
                $buf           = '';

                while (!gzeof($fp)) {
                    $line = gzgets($fp, 65536);
                    if ($line === false) break;

                    $t = trim($line);
                    // Lewati baris kosong atau komentar
                    if ($t === '' || (isset($t[0]) && $t[0] === '-' && isset($t[1]) && $t[1] === '-') || (isset($t[0]) && $t[0] === '/' && isset($t[1]) && $t[1] === '*')) {
                        continue;
                    }

                    $buf .= $line;

                    // Query berakhir jika baris diakhiri titik koma (;)
                    if (substr($t, -1) === ';') {
                        $stmt = trim($buf);
                        $buf  = '';

                        if ($stmt === '') continue;

                        $isAllowed = false;
                        if (preg_match('/^SET\s+(NAMES|FOREIGN_KEY_CHECKS)/i', $stmt)) {
                            $isAllowed = true;
                        } elseif (preg_match('/^(INSERT\s+INTO|REPLACE\s+INTO|DELETE\s+FROM)\s+[`"]?([a-zA-Z0-9_]+)[`"]?/i', $stmt, $m)) {
                            $tbl = strtolower($m[2]);
                            if (in_array($tbl, $V1_SAFE_TABLES, true) && !in_array($tbl, $V2_ONLY_TABLES, true)) {
                                $isAllowed = true;
                            }
                        }

                        if (!$isAllowed) {
                            $skippedCount++;
                            continue;
                        }

                        // Optimasi cepat: Ganti DELETE FROM seluruh tabel menjadi TRUNCATE agar instan (0.001 detik)
                        if (preg_match('/^DELETE\s+FROM\s+[`"]?([a-zA-Z0-9_]+)[`"]?\s*$/i', $stmt, $mDel)) {
                            $tblDel = strtolower($mDel[1]);
                            if (in_array($tblDel, $V1_SAFE_TABLES, true) && !in_array($tblDel, $V2_ONLY_TABLES, true)) {
                                try {
                                    $db->query("TRUNCATE TABLE `" . $db->real_escape_string($tblDel) . "`");
                                    $executedCount++;
                                    continue;
                                } catch (Throwable $eTrunc) {
                                    // Fallback ke DELETE jika truncate gagal
                                }
                            }
                        }

                        try {
                            $db->query($stmt);
                            $executedCount++;
                        } catch (Throwable $e) {
                            $errorCount++;
                            if (count($errorDetails) < 5) {
                                $errorDetails[] = $e->getMessage();
                            }
                        }
                    }
                }
                gzclose($fp);

                // Kembalikan setting MariaDB
                $db->query("SET unique_checks = 1;");
                $db->query("SET foreign_key_checks = 1;");
                $db->query("SET innodb_lock_wait_timeout = 50;");

                // Kembalikan session untuk pesan status
                if (session_status() !== PHP_SESSION_ACTIVE) {
                    @session_start();
                }

                if ($errorCount > 0) {
                    $msg_error = "Restore selesai dengan $errorCount error ($executedCount query berhasil, $skippedCount query dilewati): " . implode('; ', $errorDetails);
                } else {
                    $msg_ok = "Restore data dari V1 BERHASIL! $executedCount query berhasil dijalankan ($skippedCount query dilewati/bukan tabel aman).";
                }
            }
        }
    }
}

// ── HANDLE: Export backup penuh V2 ───────────────────────
if (isset($_POST['action']) && in_array($_POST['action'], ['export_v2_backup', 'export_v2_backup_gz'])) {
    @set_time_limit(600);
    @ini_set('memory_limit', '512M');

    $isGz = ($_POST['action'] === 'export_v2_backup_gz');

    $res = bkQuery("SHOW TABLES");
    if (!$res) { die('Gagal membaca daftar tabel.'); }

    $tables = [];
    while ($r = $res->fetch_row()) { $tables[] = $r[0]; }

    $filename = 'snet_v2_backup_' . date('Ymd_His') . ($isGz ? '.sql.gz' : '.sql');
    header('Content-Type: ' . ($isGz ? 'application/gzip' : 'application/sql; charset=UTF-8'));
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store');

    $tmpFile = null;
    $gzOut   = null;
    if ($isGz) {
        $tmpFile = tempnam(sys_get_temp_dir(), 'snet_v2_gz_');
        $gzOut   = gzopen($tmpFile, 'wb6');
    }

    $writeSql = function(string $text) use ($isGz, $gzOut) {
        if ($isGz && $gzOut) {
            gzwrite($gzOut, $text);
        } else {
            echo $text;
        }
    };

    $db = db();
    $writeSql("-- ============================================================\n");
    $writeSql("-- S.NET V2 Full Backup\n");
    $writeSql("-- Dibuat: " . date('Y-m-d H:i:s') . "\n");
    $writeSql("-- Total tabel: " . count($tables) . "\n");
    $writeSql("-- ============================================================\n\n");
    $writeSql("SET NAMES utf8mb4;\n");
    $writeSql("SET FOREIGN_KEY_CHECKS = 0;\n\n");

    foreach ($tables as $table) {
        $safeTable = $db->real_escape_string($table);
        $writeSql("-- ── Tabel: $table ──\n");

        // SHOW CREATE TABLE
        $crRes = $db->query("SHOW CREATE TABLE `" . $safeTable . "`");
        if ($crRes) {
            $cr = $crRes->fetch_row();
            if (!empty($cr[1])) {
                $writeSql($cr[1] . ";\n");
            }
            $crRes->free();
        }

        // Data
        $dataRes = $db->query("SELECT * FROM `" . $safeTable . "`");
        if (!$dataRes || $dataRes->num_rows === 0) {
            $writeSql("-- (tabel kosong)\n\n");
            if ($dataRes) $dataRes->free();
            continue;
        }

        // Nama kolom
        $cols = [];
        $finfo = $dataRes->fetch_fields();
        foreach ($finfo as $fi) {
            $cols[] = "`" . $fi->name . "`";
        }
        $colStr = implode(", ", $cols);

        // Baris data — batch 200
        $batch = [];
        while ($row = $dataRes->fetch_row()) {
            $vals = [];
            foreach ($row as $v) {
                $vals[] = ($v === null) ? "NULL" : ("'" . $db->real_escape_string($v) . "'");
            }
            $batch[] = "(" . implode(", ", $vals) . ")";
            if (count($batch) >= 200) {
                $writeSql("INSERT INTO `" . $safeTable . "` ($colStr) VALUES\n" . implode(",\n", $batch) . ";\n");
                $batch = [];
            }
        }
        if (!empty($batch)) {
            $writeSql("INSERT INTO `" . $safeTable . "` ($colStr) VALUES\n" . implode(",\n", $batch) . ";\n");
        }
        $dataRes->free();
        $writeSql("\n");
    }

    $writeSql("SET FOREIGN_KEY_CHECKS = 1;\n");
    $writeSql("-- ── Selesai ──\n");

    if ($isGz && $gzOut) {
        gzclose($gzOut);
        readfile($tmpFile);
        @unlink($tmpFile);
    }
    exit;
}

// ── Ambil daftar tabel untuk tampilan ────────────────────
$allTbls  = [];
$allRes   = bkQuery("SHOW TABLES");
if ($allRes) {
    while ($r = $allRes->fetch_row()) { $allTbls[] = $r[0]; }
}

// Statistik untuk Sinkronisasi RADIUS
$statVouchers = (int)(db_fetch_one("SELECT COUNT(*) AS n FROM vouchers WHERE status IN ('unused', 'active')")['n'] ?? 0);
$statPppoe    = 0;
try {
    $statPppoe = (int)(db_fetch_one("SELECT COUNT(*) AS n FROM pppoe_customers")['n'] ?? 0);
} catch (Throwable $e) {}
$statRouters  = (int)(db_fetch_one("SELECT COUNT(*) AS n FROM routers WHERE status = 'active'")['n'] ?? 0);
$statRadcheck = (int)(db_fetch_one("SELECT COUNT(*) AS n FROM radcheck")['n'] ?? 0);
$statNas      = (int)(db_fetch_one("SELECT COUNT(*) AS n FROM nas")['n'] ?? 0);
$radStatus    = get_freeradius_status();

include __DIR__ . '/../../include/header.php';
?>
<div class="page-header">
    <div>
        <h1 class="page-title"><i class="bi bi-cloud-arrow-down me-2 text-primary"></i>Backup &amp; Restore</h1>
        <p class="page-subtitle">Backup database V2 dan restore data dari V1</p>
    </div>
</div>

<?php if ($msg_ok): ?>
<div class="alert alert-success d-flex gap-2 align-items-start">
    <i class="bi bi-check-circle-fill fs-5 mt-1"></i>
    <div><?= htmlspecialchars($msg_ok) ?></div>
</div>
<?php endif; ?>
<?php if ($msg_error): ?>
<div class="alert alert-danger d-flex gap-2 align-items-start">
    <i class="bi bi-x-circle-fill fs-5 mt-1"></i>
    <div><?= htmlspecialchars($msg_error) ?></div>
</div>
<?php endif; ?>

<div class="row g-4">

<!-- RESTORE DARI V1 -->
<div class="col-12 col-lg-7">
<div class="card border-primary">
    <div class="card-header bg-primary text-white">
        <h5 class="card-title mb-0"><i class="bi bi-box-arrow-in-down me-2"></i>Restore dari V1</h5>
    </div>
    <div class="card-body">
        <div class="alert alert-info" style="font-size:.85rem;">
            <i class="bi bi-info-circle me-2"></i>
            <strong>Cara dapat file SQL:</strong> Buka <strong>V1 &rarr; Pengaturan &rarr; Backup &amp; Migrasi ke V2</strong>, download file SQL-nya (atau <code>.sql.gz</code>), lalu upload di sini.
        </div>
        <form method="POST" action="index.php?page=backup" enctype="multipart/form-data" id="restoreForm">
            <input type="hidden" name="action" value="restore_v1">
            <div class="mb-3">
                <label class="form-label fw-bold">File SQL dari V1 <span class="text-danger">*</span></label>
                <input type="file" class="form-control" name="sql_file" id="sqlFileInput" accept=".sql,.gz" required>
                <div class="form-text text-muted">Mendukung file <strong>.sql</strong> atau <strong>.sql.gz</strong> (terkompresi). Maksimal <strong>300 MB</strong>.</div>
            </div>
            <div class="alert alert-success mb-2" style="font-size:.83rem;">
                <i class="bi bi-shield-check me-2"></i><strong>TIDAK AKAN TERHAPUS:</strong>
                Pelanggan PPPoE Rumahan, WireGuard VPN, WhatsApp Gateway, Data ONT, Bandwidth Snapshot
            </div>
            <div class="alert alert-warning mb-3" style="font-size:.83rem;">
                <i class="bi bi-exclamation-triangle me-2"></i><strong>AKAN DIGANTI dari V1:</strong>
                Voucher hotspot, Profil paket, Data router/NAS, Riwayat sesi RADIUS, Akun admin
            </div>
            <button type="submit" class="btn btn-primary w-100 btn-lg" id="btnRestore">
                <i class="bi bi-cloud-arrow-down me-2"></i>Upload &amp; Restore Sekarang
            </button>
        </form>
    </div>
</div>
</div>

<!-- BACKUP V2 -->
<div class="col-12 col-lg-5">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="card-title mb-0"><i class="bi bi-download me-2"></i>Backup Penuh V2</h5>
            <span class="badge bg-secondary"><?= count($allTbls) ?> tabel</span>
        </div>
        <div class="card-body">
            <p class="text-muted small mb-2">Download backup seluruh database V2. Simpan sebelum melakukan restore.</p>
            <div class="bg-light rounded p-2 mb-3" style="max-height:240px;overflow-y:auto;">
                <?php foreach ($allTbls as $tn):
                    $r   = bkQuery("SELECT COUNT(*) AS n FROM `" . db()->real_escape_string($tn) . "`");
                    $cnt = $r ? (int)($r->fetch_assoc()['n'] ?? 0) : 0;
                    if ($r) $r->free();
                ?>
                <div class="d-flex justify-content-between py-1 border-bottom" style="font-size:.75rem;">
                    <span class="font-monospace"><?= htmlspecialchars($tn) ?></span>
                    <span class="text-muted"><?= number_format($cnt) ?> baris</span>
                </div>
                <?php endforeach; ?>
            </div>
            <form method="POST" action="index.php?page=backup" class="d-flex gap-2">
                <button type="submit" name="action" value="export_v2_backup" class="btn btn-outline-primary flex-fill">
                    <i class="bi bi-filetype-sql me-1"></i>.SQL Biasa
                </button>
                <button type="submit" name="action" value="export_v2_backup_gz" class="btn btn-success flex-fill" title="Hemat ukuran hingga 90%">
                    <i class="bi bi-file-earmark-zip me-1"></i>.SQL.GZ (Kecil)
                </button>
            </form>
        </div>
    </div>

    <div class="card mt-3">
        <div class="card-header"><h6 class="card-title mb-0"><i class="bi bi-diagram-2 me-1"></i>Alur Migrasi V1 &rarr; V2</h6></div>
        <div class="card-body" style="font-size:.83rem;">
            <div class="d-flex align-items-center gap-2 mb-2">
                <span class="badge bg-secondary">V1</span>
                <span>Pengaturan &rarr; Backup &amp; Migrasi ke V2</span>
            </div>
            <div class="text-center text-muted my-1">&#8595; Download .sql atau .sql.gz</div>
            <div class="d-flex align-items-center gap-2 mb-2">
                <span class="badge bg-primary">V2</span>
                <span>Pengaturan &rarr; Backup &amp; Restore &rarr; Upload</span>
            </div>
            <div class="text-center text-success fw-bold mt-1">&#10003; Selesai!</div>
        </div>
    </div>
</div>

</div>

<!-- SINKRONISASI FREERADIUS -->
<div class="row mt-4">
    <div class="col-12">
        <div class="card border-warning shadow-sm" id="sinkronisasi-radius">
            <div class="card-header bg-warning bg-opacity-25 d-flex flex-wrap justify-content-between align-items-center gap-2 py-3">
                <h5 class="card-title mb-0 text-dark fw-bold">
                    <i class="bi bi-arrow-repeat me-2 text-warning"></i>Sinkronisasi FreeRADIUS &amp; MikroTik
                </h5>
                <div class="d-flex align-items-center gap-2">
                    <?php if ($radStatus['is_active']): ?>
                        <span class="badge bg-success px-2 py-1"><i class="bi bi-check-circle-fill me-1"></i>FreeRADIUS: AKTIF (Port 1812/1813 OK)</span>
                    <?php else: ?>
                        <span class="badge bg-danger px-2 py-1"><i class="bi bi-exclamation-triangle-fill me-1"></i>FreeRADIUS: NONAKTIF</span>
                    <?php endif; ?>
                    <span class="badge bg-warning text-dark"><i class="bi bi-shield-check me-1"></i>Menu Wajib Pasca Migrasi</span>
                </div>
            </div>
            <div class="card-body p-4">
                <?php if (!$radStatus['is_active']): ?>
                    <div class="alert alert-danger d-flex gap-2 align-items-start mb-3" style="font-size:.85rem;">
                        <i class="bi bi-exclamation-octagon-fill fs-5 mt-1 flex-shrink-0"></i>
                        <div>
                            <strong>Service FreeRADIUS Terdeteksi Nonaktif!</strong><br>
                            Service FreeRADIUS di server saat ini tidak berjalan. Anda dapat menyinkronkan data database di bawah terlebih dahulu, lalu hidupkan kembali FreeRADIUS melalui menu <a href="index.php?page=general" class="alert-link text-decoration-underline">Pengaturan &rarr; Status Service FreeRADIUS</a> atau via SSH (<code>sudo systemctl start freeradius</code>).
                        </div>
                    </div>
                <?php endif; ?>
                <div class="alert alert-info d-flex gap-2 align-items-start mb-3" style="font-size:.85rem;">
                    <i class="bi bi-info-circle-fill fs-5 mt-1 flex-shrink-0 text-primary"></i>
                    <div>
                        <strong>Kenapa Router / RADIUS tidak mau konek setelah migrasi?</strong><br>
                        Setelah import data dari database V1, mesin FreeRADIUS membutuhkan sinkronisasi ulang agar seluruh daftar voucher hotspot, pelanggan PPPoE, dan IP Router (tabel <code>nas</code>) terdaftar resmi di layanan RADIUS server.
                    </div>
                </div>

                <!-- Status Angka Sinkronisasi -->
                <div class="row g-2 mb-4 text-center">
                    <div class="col-6 col-md-3">
                        <div class="p-3 border rounded bg-light">
                            <div class="fw-bold fs-4 text-primary"><?= number_format($statVouchers) ?></div>
                            <div class="text-muted small">Voucher Siap Sync</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 border rounded bg-light">
                            <div class="fw-bold fs-4 text-success"><?= number_format($statPppoe) ?></div>
                            <div class="text-muted small">PPPoE Siap Sync</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 border rounded bg-light">
                            <div class="fw-bold fs-4 text-danger"><?= number_format($statRouters) ?></div>
                            <div class="text-muted small">Router NAS Aktif</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 border rounded bg-light">
                            <div class="fw-bold fs-4 text-dark"><?= number_format($statRadcheck) ?></div>
                            <div class="text-muted small">Akun Terdaftar di FreeRADIUS</div>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="border rounded p-3 h-100 bg-white">
                            <h6 class="fw-bold text-primary mb-1"><i class="bi bi-ticket-perforated me-1"></i>1. Hotspot Vouchers</h6>
                            <p class="text-muted small mb-0">Membangun ulang seluruh akun voucher aktif/unused ke tabel <code>radcheck</code> dan <code>radreply</code> (password, rate limit, kuota, session timeout).</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="border rounded p-3 h-100 bg-white">
                            <h6 class="fw-bold text-success mb-1"><i class="bi bi-house-door me-1"></i>2. PPPoE Rumahan</h6>
                            <p class="text-muted small mb-0">Menyinkronkan seluruh akun PPPoE Rumahan ke <code>radcheck</code>, <code>radreply</code>, dan <code>radusergroup</code> tanpa menghapus data pelanggan.</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="border rounded p-3 h-100 bg-white">
                            <h6 class="fw-bold text-danger mb-1"><i class="bi bi-hdd-network me-1"></i>3. Router &amp; NAS Secret</h6>
                            <p class="text-muted small mb-0">Mendaftarkan IP router dan RADIUS secret ke tabel <code>nas</code> FreeRADIUS agar MikroTik diizinkan berkomunikasi dengan FreeRADIUS.</p>
                        </div>
                    </div>
                </div>

                <form method="POST" action="index.php?page=backup" id="syncRadiusForm">
                    <input type="hidden" name="action" value="sync_radius">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                    <button type="submit" class="btn btn-warning btn-lg w-100 text-dark fw-bold shadow-sm" id="btnSyncRadius">
                        <i class="bi bi-arrow-repeat me-2"></i>Mulai Sinkronisasi FreeRADIUS Sekarang
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Loading Overlay -->
<div id="restoreLoadingOverlay" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.75);z-index:99999;backdrop-filter:blur(4px);align-items:center;justify-content:center;">
    <div class="bg-white p-4 rounded-4 shadow-lg text-center" style="max-width:440px;margin:20px;">
        <div class="spinner-border text-primary mb-3" style="width:3.5rem;height:3.5rem;" role="status"></div>
        <h5 class="fw-bold mb-2" id="overlayTitle">Sedang Memproses Restore Database...</h5>
        <p class="text-muted small mb-3" id="overlayDesc">Database sedang dibaca dan dimasukkan ke sistem. Kecepatan tergantung ukuran file.</p>
        <div class="alert alert-warning small py-2 mb-0">
            <i class="bi bi-exclamation-triangle-fill me-1"></i><strong>Penting:</strong> Jangan tutup atau refresh halaman ini sampai proses selesai.
        </div>
    </div>
</div>

<script>
// Handler Restore V1
document.getElementById('restoreForm').addEventListener('submit', function(e) {
    var fileInput = document.getElementById('sqlFileInput');
    if (!fileInput.files || fileInput.files.length === 0) {
        alert('Silakan pilih file SQL terlebih dahulu.');
        e.preventDefault();
        return false;
    }

    var ok = confirm("Yakin ingin restore data dari V1?\n\n- Data voucher, profil, router, dan RADIUS akan diganti.\n- Data PPPoE Rumahan dan konfigurasi V2 lainnya AMAN.");
    if (!ok) {
        e.preventDefault();
        return false;
    }

    var overlay = document.getElementById('restoreLoadingOverlay');
    if (overlay) {
        document.getElementById('overlayTitle').innerText = 'Sedang Memproses Restore Database...';
        document.getElementById('overlayDesc').innerText = 'Database sedang dibaca dan dimasukkan ke sistem. Kecepatan tergantung ukuran file.';
        overlay.style.display = 'flex';
    }
    return true;
});

// Handler Sinkronisasi FreeRADIUS
var syncForm = document.getElementById('syncRadiusForm');
if (syncForm) {
    syncForm.addEventListener('submit', function(e) {
        var ok = confirm("Proses ini akan menyinkronkan seluruh voucher hotspot, akun PPPoE, dan IP Router NAS ke mesin FreeRADIUS.\n\nLanjutkan sinkronisasi sekarang?");
        if (!ok) {
            e.preventDefault();
            return false;
        }

        var overlay = document.getElementById('restoreLoadingOverlay');
        if (overlay) {
            document.getElementById('overlayTitle').innerText = 'Sedang Menyinkronkan ke FreeRADIUS...';
            document.getElementById('overlayDesc').innerText = 'Membangun ulang radcheck, radreply, radusergroup, dan nas... Proses ini hanya memakan waktu 1-2 detik.';
            overlay.style.display = 'flex';
        }
        return true;
    });
}
</script>

<?php include __DIR__ . '/../../include/footer.php'; ?>
