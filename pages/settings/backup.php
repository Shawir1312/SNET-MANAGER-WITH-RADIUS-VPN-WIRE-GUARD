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

// ── HANDLE: Restore Database (Mendukung Restore Penuh V2 / Pindah VPS & Migrasi V1) ──
if (isset($_POST['action']) && in_array($_POST['action'], ['restore_db', 'restore_v1'], true)) {
    @set_time_limit(0);
    @ini_set('memory_limit', '512M');

    $csrf = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
        $msg_error = 'Token keamanan (CSRF) tidak valid atau sesi telah kedaluwarsa. Silakan refresh halaman dan coba lagi.';
    } else {
        $restoreMode = $_POST['restore_mode'] ?? ($_POST['action'] === 'restore_v1' ? 'v1' : 'full');
        $isFullMode  = ($restoreMode === 'full');

        $fileUpload = $_FILES['sql_file'] ?? null;
        $errCode    = $fileUpload['error'] ?? UPLOAD_ERR_NO_FILE;

        if ($errCode === UPLOAD_ERR_INI_SIZE || $errCode === UPLOAD_ERR_FORM_SIZE) {
            $maxUp = ini_get('upload_max_filesize');
            $msg_error = "File melebihi batas upload PHP server ($maxUp). Solusi: Gunakan file versi terkompresi .sql.gz (hanya ~5 MB) atau naikkan 'upload_max_filesize' dan 'post_max_size' di php.ini.";
        } elseif ($errCode === UPLOAD_ERR_NO_FILE || empty($fileUpload['tmp_name'])) {
            $msg_error = 'Silakan pilih file SQL atau SQL.GZ terlebih dahulu.';
        } elseif ($errCode !== UPLOAD_ERR_OK) {
            $msg_error = "Upload gagal dengan kode error PHP: $errCode.";
        } elseif ($fileUpload['size'] > 300 * 1024 * 1024) {
            $msg_error = 'Ukuran file terlalu besar (maksimal 300 MB).';
        } else {
            $tmpFile  = $fileUpload['tmp_name'];
            $origName = $fileUpload['name'];
            $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

            if ($ext !== 'sql' && $ext !== 'gz') {
                $msg_error = 'Format file tidak valid. Hanya file .sql atau .sql.gz yang diperbolehkan.';
            } else {
                // Lepaskan session lock agar browser tidak hanging saat import query banyak
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_write_close();
                }

                $fp = @gzopen($tmpFile, 'r');
                if (!$fp) {
                    $msg_error = 'Gagal membuka file backup untuk dibaca.';
                } else {
                    $db = db();

                    // Optimasi performa bulk import MariaDB / InnoDB
                    $db->query("SET innodb_lock_wait_timeout = 15;");
                    $db->query("SET autocommit = 1;");
                    $db->query("SET unique_checks = 0;");
                    $db->query("SET foreign_key_checks = 0;");
                    $db->query("SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';");

                    $executedCount   = 0;
                    $skippedCount    = 0;
                    $errorCount      = 0;
                    $errorDetails    = [];
                    $tablesProcessed = [];
                    $buf             = '';

                    while (!gzeof($fp)) {
                        $line = gzgets($fp, 65536);
                        if ($line === false) break;

                        $t = trim($line);
                        // Lewati baris kosong atau komentar (-- , /* , #)
                        if ($t === '' || $t[0] === '#' || (isset($t[1]) && (($t[0] === '-' && $t[1] === '-') || ($t[0] === '/' && $t[1] === '*')))) {
                            continue;
                        }

                        $buf .= $line;

                        // Query berakhir jika baris diakhiri titik koma (;)
                        if (substr($t, -1) === ';') {
                            $stmt = trim($buf);
                            $buf  = '';

                            if ($stmt === '') continue;

                            $isAllowed = false;

                            if ($isFullMode) {
                                // MODE FULL: Izinkan seluruh struktur tabel dan data V2
                                if (preg_match('/^(SET|DROP\s+TABLE|CREATE\s+TABLE|INSERT\s+INTO|REPLACE\s+INTO|ALTER\s+TABLE|TRUNCATE\s+TABLE|LOCK\s+TABLES|UNLOCK\s+TABLES)\b/i', $stmt)) {
                                    $isAllowed = true;
                                    if (preg_match('/(?:FROM|TABLE|INTO)\s+[`"]?([a-zA-Z0-9_]+)[`"]?/i', $stmt, $mTab)) {
                                        $tablesProcessed[strtolower($mTab[1])] = true;
                                    }
                                }
                            } else {
                                // MODE V1: Whitelist ketat hanya tabel V1, jangan sentuh tabel V2
                                if (preg_match('/^SET\s+(NAMES|FOREIGN_KEY_CHECKS)/i', $stmt)) {
                                    $isAllowed = true;
                                } elseif (preg_match('/^(INSERT\s+INTO|REPLACE\s+INTO|DELETE\s+FROM)\s+[`"]?([a-zA-Z0-9_]+)[`"]?/i', $stmt, $m)) {
                                    $tbl = strtolower($m[2]);
                                    if (in_array($tbl, $V1_SAFE_TABLES, true) && !in_array($tbl, $V2_ONLY_TABLES, true)) {
                                        $isAllowed = true;
                                        $tablesProcessed[$tbl] = true;
                                    }
                                }

                                // Optimasi cepat V1: TRUNCATE tabel V1
                                if ($isAllowed && preg_match('/^DELETE\s+FROM\s+[`"]?([a-zA-Z0-9_]+)[`"]?\s*$/i', $stmt, $mDel)) {
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
                            }

                            if (!$isAllowed) {
                                $skippedCount++;
                                continue;
                            }

                            try {
                                $db->query($stmt);
                                $executedCount++;
                            } catch (Throwable $e) {
                                $errorCount++;
                                if (count($errorDetails) < 5) {
                                    $errorDetails[] = substr($e->getMessage(), 0, 150);
                                }
                            }
                        }
                    }
                    gzclose($fp);

                    // Kembalikan setting keamanan MariaDB
                    $db->query("SET unique_checks = 1;");
                    $db->query("SET foreign_key_checks = 1;");
                    $db->query("SET innodb_lock_wait_timeout = 50;");

                    // Buka kembali session untuk status flash message
                    if (session_status() !== PHP_SESSION_ACTIVE) {
                        @session_start();
                    }

                    if ($isFullMode) {
                        // Otomatis sinkronkan FreeRADIUS setelah full restore
                        $syncNote = '';
                        try {
                            $resSync = execute_radius_sync();
                            $syncNote = "<br><i class='bi bi-check-circle me-1 text-success'></i><strong>FreeRADIUS Otomatis Terhubung:</strong> {$resSync['voucher_count']} voucher hotspot, {$resSync['pppoe_count']} akun PPPoE, dan {$resSync['nas_count']} router NAS aktif.";
                        } catch (Throwable $eSync) {
                            $syncNote = "<br><i class='bi bi-exclamation-triangle me-1 text-warning'></i>Catatan: Sinkronisasi FreeRADIUS otomatis belum sempurna. Silakan klik tombol 'Sinkronisasi FreeRADIUS' di bawah.";
                        }

                        // Refresh sesi admin saat ini
                        if (!empty($_SESSION['admin_id'])) {
                            try {
                                $adm = db_fetch_one("SELECT * FROM admins WHERE id = ?", "i", [(int)$_SESSION['admin_id']]);
                                if ($adm) {
                                    $_SESSION['admin_role'] = $adm['role'];
                                    $_SESSION['admin_name'] = $adm['name'] ?? $adm['username'];
                                }
                            } catch (Throwable $eAdm) {}
                        }

                        $tblCount = count($tablesProcessed);
                        if ($errorCount > 0) {
                            $msg_error = "Restore selesai dengan $errorCount catatan error ($executedCount query berhasil dijalankan pada $tblCount tabel): " . implode('; ', $errorDetails) . $syncNote;
                        } else {
                            $msg_ok = "✓ <strong>RESTORE DATABASE PENUH BERHASIL!</strong> Sebanyak <strong>{$executedCount} query</strong> berhasil dieksekusi pada <strong>{$tblCount} tabel</strong> (Hotspot, PPPoE, Routers, WireGuard, WhatsApp, dan RADIUS terpulihkan).{$syncNote}";
                        }
                    } else {
                        if ($errorCount > 0) {
                            $msg_error = "Restore selesai dengan $errorCount error ($executedCount query berhasil, $skippedCount query dilewati): " . implode('; ', $errorDetails);
                        } else {
                            $msg_ok = "Restore data dari V1 BERHASIL! $executedCount query berhasil dijalankan ($skippedCount query dilewati/bukan tabel aman). Silakan klik tombol 'Mulai Sinkronisasi FreeRADIUS' di bawah.";
                        }
                    }
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
        $writeSql("DROP TABLE IF EXISTS `" . $safeTable . "`;\n");

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

$maxUpload = ini_get('upload_max_filesize') ?: '2M';
$maxPost   = ini_get('post_max_size') ?: '8M';
$memLimit  = ini_get('memory_limit') ?: '128M';

include __DIR__ . '/../../include/header.php';
?>
<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3">
    <div>
        <h1 class="page-title"><i class="bi bi-cloud-arrow-down me-2 text-primary"></i>Backup &amp; Restore Database</h1>
        <p class="page-subtitle mb-0">Cadangkan seluruh database untuk pindah VPS atau pulihkan data dari file cadangan</p>
    </div>
    <div class="d-flex flex-wrap gap-2 align-items-center">
        <span class="badge bg-light text-secondary border px-2 py-2" title="Batas maksimal ukuran file upload per request"><i class="bi bi-cloud-arrow-up me-1"></i>Upload Max: <?= htmlspecialchars($maxUpload) ?></span>
        <span class="badge bg-light text-secondary border px-2 py-2" title="Batas maksimal payload POST server"><i class="bi bi-arrow-up-circle me-1"></i>Post Max: <?= htmlspecialchars($maxPost) ?></span>
        <span class="badge bg-light text-secondary border px-2 py-2" title="Batas memori eksekusi PHP"><i class="bi bi-cpu me-1"></i>RAM PHP: <?= htmlspecialchars($memLimit) ?></span>
    </div>
</div>

<?php if ($msg_ok): ?>
<div class="alert alert-success d-flex gap-2 align-items-start shadow-sm">
    <i class="bi bi-check-circle-fill fs-5 mt-1 flex-shrink-0"></i>
    <div><?= $msg_ok ?></div>
</div>
<?php endif; ?>
<?php if ($msg_error): ?>
<div class="alert alert-danger d-flex gap-2 align-items-start shadow-sm">
    <i class="bi bi-x-circle-fill fs-5 mt-1 flex-shrink-0"></i>
    <div><?= $msg_error ?></div>
</div>
<?php endif; ?>

<div class="row g-4">

<!-- FORM RESTORE DATABASE -->
<div class="col-12 col-lg-7">
<div class="card border-primary shadow-sm h-100">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center py-3">
        <h5 class="card-title mb-0"><i class="bi bi-box-arrow-in-down me-2"></i>Pulihkan / Restore Database</h5>
        <span class="badge bg-white text-primary fw-bold">Mendukung .sql &amp; .sql.gz</span>
    </div>
    <div class="card-body p-4">
        <form method="POST" action="index.php?page=backup" enctype="multipart/form-data" id="restoreForm">
            <input type="hidden" name="action" value="restore_db">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

            <div class="mb-4">
                <label class="form-label fw-bold d-block mb-2">Pilih Mode Restore: <span class="text-danger">*</span></label>
                
                <!-- Opsi 1: Restore Penuh V2 (Default untuk Pindah VPS) -->
                <div class="card p-3 mb-2 border-primary bg-light" id="cardModeFull" style="cursor:pointer;" onclick="selectMode('full')">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="restore_mode" id="modeFull" value="full" checked onchange="updateRestoreModeUI()">
                        <label class="form-check-label fw-bold text-primary" for="modeFull" style="cursor:pointer;">
                            <i class="bi bi-hdd-stack me-1"></i>Restore Penuh V2 (Rekomendasi untuk Pindah VPS)
                            <span class="badge bg-success ms-1">Disarankan</span>
                        </label>
                        <div class="form-text text-muted small mt-1">
                            Mengembalikan <strong>100% seluruh data</strong>: Hotspot Voucher, Pelanggan PPPoE Rumahan, Paket Profil, Router NAS, WireGuard VPN, WhatsApp Config, Log Transaksi, Akun Admin, dan Database FreeRADIUS.
                        </div>
                    </div>
                </div>

                <!-- Opsi 2: Migrasi Parsial dari V1 -->
                <div class="card p-3 border" id="cardModeV1" style="cursor:pointer;" onclick="selectMode('v1')">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="restore_mode" id="modeV1" value="v1" onchange="updateRestoreModeUI()">
                        <label class="form-check-label fw-bold text-dark" for="modeV1" style="cursor:pointer;">
                            <i class="bi bi-box-arrow-in-down me-1"></i>Migrasi Parsial dari S.NET V1
                        </label>
                        <div class="form-text text-muted small mt-1">
                            Hanya untuk mengimpor file backup dari aplikasi versi 1 lama (hanya Hotspot &amp; RADIUS). Data pelanggan PPPoE dan fitur V2 di server tidak akan tersentuh.
                        </div>
                    </div>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-bold">Pilih File Cadangan (.sql atau .sql.gz) <span class="text-danger">*</span></label>
                <input type="file" class="form-control form-control-lg" name="sql_file" id="sqlFileInput" accept=".sql,.gz" required>
                <div class="form-text text-muted mt-1">
                    <i class="bi bi-info-circle me-1 text-primary"></i>Mendukung file <strong>.sql</strong> atau file terkompresi <strong>.sql.gz</strong> (maksimal <strong>300 MB</strong>).
                </div>
            </div>

            <!-- Petunjuk Mode Full -->
            <div id="fullRestoreAlert" class="alert alert-warning mb-4" style="font-size:.85rem;">
                <div class="d-flex gap-2">
                    <i class="bi bi-exclamation-triangle-fill fs-5 mt-1 text-warning flex-shrink-0"></i>
                    <div>
                        <strong>Peringatan Pindah VPS:</strong> Seluruh tabel dan data di database server saat ini akan diperbarui sesuai file backup yang diupload. Setelah selesai, FreeRADIUS akan langsung otomatis disinkronkan.
                    </div>
                </div>
            </div>

            <!-- Petunjuk Mode V1 -->
            <div id="v1RestoreAlert" class="d-none mb-4">
                <div class="alert alert-success mb-2" style="font-size:.83rem;">
                    <i class="bi bi-shield-check me-2"></i><strong>TIDAK AKAN TERHAPUS:</strong>
                    Pelanggan PPPoE Rumahan, WireGuard VPN, WhatsApp Gateway, Data ONT, Bandwidth Snapshot.
                </div>
                <div class="alert alert-warning mb-0" style="font-size:.83rem;">
                    <i class="bi bi-exclamation-triangle me-2"></i><strong>AKAN DIGANTI dari V1:</strong>
                    Voucher hotspot, Profil paket, Data router/NAS, Riwayat sesi RADIUS, Akun admin.
                </div>
            </div>

            <button type="submit" class="btn btn-primary w-100 btn-lg shadow-sm" id="btnRestore">
                <i class="bi bi-cloud-arrow-down me-2"></i><span id="btnRestoreText">Upload &amp; Restore Penuh Database Sekarang</span>
            </button>
        </form>
    </div>
</div>
</div>

<!-- DOWNLOAD BACKUP DATABASE V2 -->
<div class="col-12 col-lg-5">
    <div class="card shadow-sm mb-3">
        <div class="card-header d-flex justify-content-between align-items-center py-3">
            <h5 class="card-title mb-0"><i class="bi bi-download me-2 text-primary"></i>Backup Database V2</h5>
            <span class="badge bg-secondary"><?= count($allTbls) ?> tabel</span>
        </div>
        <div class="card-body">
            <p class="text-muted small mb-2">Simpan file backup database sebelum mematikan VPS lama atau sebelum melakukan restore di VPS baru.</p>
            
            <div class="bg-light rounded p-2 mb-3 border" style="max-height:220px;overflow-y:auto;">
                <?php foreach ($allTbls as $tn):
                    $r   = bkQuery("SELECT COUNT(*) AS n FROM `" . db()->real_escape_string($tn) . "`");
                    $cnt = $r ? (int)($r->fetch_assoc()['n'] ?? 0) : 0;
                    if ($r) $r->free();
                ?>
                <div class="d-flex justify-content-between py-1 border-bottom" style="font-size:.75rem;">
                    <span class="font-monospace text-dark"><?= htmlspecialchars($tn) ?></span>
                    <span class="badge bg-white text-muted border"><?= number_format($cnt) ?> baris</span>
                </div>
                <?php endforeach; ?>
            </div>

            <form method="POST" action="index.php?page=backup" class="d-flex flex-column gap-2">
                <button type="submit" name="action" value="export_v2_backup_gz" class="btn btn-success btn-lg shadow-sm d-flex justify-content-between align-items-center" title="Format terkompresi, hemat ukuran hingga 90%">
                    <span><i class="bi bi-file-earmark-zip me-2"></i>Download .SQL.GZ (Kecil)</span>
                    <span class="badge bg-white text-success small">Rekomendasi</span>
                </button>
                <button type="submit" name="action" value="export_v2_backup" class="btn btn-outline-secondary">
                    <i class="bi bi-filetype-sql me-1"></i>Download .SQL Biasa (Teks)
                </button>
            </form>
        </div>
    </div>

    <!-- PANDUAN RINGKAS PINDAH VPS -->
    <div class="card shadow-sm border-info">
        <div class="card-header bg-info bg-opacity-10 text-dark py-2">
            <h6 class="card-title mb-0 fw-bold"><i class="bi bi-signpost-2-fill me-1 text-primary"></i>Alur Pindah VPS via Web</h6>
        </div>
        <div class="card-body" style="font-size:.83rem;">
            <ol class="ps-3 mb-2 text-muted">
                <li class="mb-1"><strong>Di VPS Lama:</strong> Klik tombol hijau <strong>Download .SQL.GZ</strong> di atas untuk mengunduh seluruh database.</li>
                <li class="mb-1"><strong>Di VPS Baru:</strong> Jalankan instalasi aplikasi S.NET (wizard instalasi pertama kali).</li>
                <li class="mb-1"><strong>Di VPS Baru:</strong> Buka menu <em>Backup &amp; Restore</em> ini, pilih <strong>Restore Penuh V2</strong> lalu upload file <code>.sql.gz</code> tersebut.</li>
                <li class="mb-1"><strong>Selesai!</strong> Seluruh voucher, akun PPPoE, router, dan FreeRADIUS otomatis aktif kembali.</li>
            </ol>
            <div class="alert alert-light border py-2 px-3 mb-0 small text-dark">
                <i class="bi bi-lightbulb-fill text-warning me-1"></i><strong>Tips File Sistem:</strong> Agar sesi WhatsApp Web tidak logout dan kunci WireGuard VPN di MikroTik tidak perlu diganti, salin juga folder <code>wa-service/auth_info_baileys</code> dan <code>/etc/wireguard</code> dari VPS lama ke VPS baru.
            </div>
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
                            Service FreeRADIUS di server saat ini tidak berjalan. Anda dapat menyinkronkan data database di bawah terlebih dahulu, lalu hidupkan kembali FreeRADIUS melalui menu <a href="index.php?page=settings" class="alert-link text-decoration-underline">Pengaturan &rarr; Status Service FreeRADIUS</a> atau via SSH (<code>sudo systemctl start freeradius</code>).
                        </div>
                    </div>
                <?php endif; ?>
                <div class="alert alert-info d-flex gap-2 align-items-start mb-3" style="font-size:.85rem;">
                    <i class="bi bi-info-circle-fill fs-5 mt-1 flex-shrink-0 text-primary"></i>
                    <div>
                        <strong>Kenapa Router / RADIUS tidak mau konek setelah migrasi?</strong><br>
                        Setelah import data, mesin FreeRADIUS membutuhkan sinkronisasi ulang agar seluruh daftar voucher hotspot, pelanggan PPPoE, dan IP Router (tabel <code>nas</code>) terdaftar resmi di layanan RADIUS server.
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
    <div class="bg-white p-4 rounded-4 shadow-lg text-center" style="max-width:460px;margin:20px;">
        <div class="spinner-border text-primary mb-3" style="width:3.5rem;height:3.5rem;" role="status"></div>
        <h5 class="fw-bold mb-2" id="overlayTitle">Sedang Memproses Restore Database...</h5>
        <p class="text-muted small mb-3" id="overlayDesc">File database sedang diunggah dan dieksekusi ke sistem. Kecepatan tergantung ukuran file.</p>
        <div class="alert alert-warning small py-2 mb-0">
            <i class="bi bi-exclamation-triangle-fill me-1"></i><strong>Penting:</strong> Jangan tutup atau refresh halaman browser ini sampai proses selesai.
        </div>
    </div>
</div>

<script>
function selectMode(mode) {
    if (mode === 'full') {
        document.getElementById('modeFull').checked = true;
    } else {
        document.getElementById('modeV1').checked = true;
    }
    updateRestoreModeUI();
}

function updateRestoreModeUI() {
    var isFull = document.getElementById('modeFull').checked;
    var fullAlert = document.getElementById('fullRestoreAlert');
    var v1Alert   = document.getElementById('v1RestoreAlert');
    var btnText   = document.getElementById('btnRestoreText');
    var cardFull  = document.getElementById('cardModeFull');
    var cardV1    = document.getElementById('cardModeV1');

    if (isFull) {
        fullAlert.classList.remove('d-none');
        v1Alert.classList.add('d-none');
        btnText.innerText = 'Upload & Restore Penuh Database Sekarang';
        cardFull.classList.add('border-primary', 'bg-light');
        cardV1.classList.remove('border-primary', 'bg-light');
    } else {
        fullAlert.classList.add('d-none');
        v1Alert.classList.remove('d-none');
        btnText.innerText = 'Upload & Migrasi Data dari V1';
        cardV1.classList.add('border-primary', 'bg-light');
        cardFull.classList.remove('border-primary', 'bg-light');
    }
}

// Handler Restore Form Submit
document.getElementById('restoreForm').addEventListener('submit', function(e) {
    var fileInput = document.getElementById('sqlFileInput');
    if (!fileInput.files || fileInput.files.length === 0) {
        alert('Silakan pilih file SQL atau SQL.GZ terlebih dahulu.');
        e.preventDefault();
        return false;
    }

    var isFull = document.getElementById('modeFull').checked;
    var confirmMsg = isFull 
        ? "PERINGATAN RESTORE PENUH (PINDAH VPS):\n\nSeluruh data database server saat ini akan ditimpa dengan data file backup (Hotspot, PPPoE Rumahan, Router, VPN, WA, & FreeRADIUS).\n\nApakah Anda yakin ingin memulai proses restore sekarang?"
        : "Yakin ingin restore data migrasi dari V1?\n\n- Data voucher, profil, router, dan RADIUS akan diganti.\n- Data PPPoE Rumahan dan konfigurasi V2 lainnya AMAN.";

    var ok = confirm(confirmMsg);
    if (!ok) {
        e.preventDefault();
        return false;
    }

    var overlay = document.getElementById('restoreLoadingOverlay');
    if (overlay) {
        document.getElementById('overlayTitle').innerText = isFull ? 'Sedang Memulihkan Database Penuh...' : 'Sedang Memproses Migrasi V1...';
        document.getElementById('overlayDesc').innerText = 'File database sedang diunggah dan dieksekusi ke MySQL. Mohon tunggu...';
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
