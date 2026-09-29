<?php
/**
 * S.NET V2 — Backup & Restore
 * Halaman backup database V2 dan restore dari V1
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

// ── HANDLE: Restore file SQL dari V1 ─────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'restore_v1') {
    if (empty($_FILES['sql_file']['tmp_name'])) {
        $msg_error = 'Pilih file SQL terlebih dahulu.';
    } elseif ($_FILES['sql_file']['size'] > 50 * 1024 * 1024) {
        $msg_error = 'Ukuran file terlalu besar (maksimal 50 MB).';
    } else {
        $tmpFile  = $_FILES['sql_file']['tmp_name'];
        $origName = $_FILES['sql_file']['name'];
        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if ($ext !== 'sql') {
            $msg_error = 'Hanya file .sql yang diperbolehkan.';
        } else {
            $content  = file_get_contents($tmpFile);
            if ($content === false) {
                $msg_error = 'Gagal membaca file.';
            } else {
                // Keamanan: blokir DROP TABLE, TRUNCATE, DROP DATABASE, dll
                $dangerous = ['DROP DATABASE','DROP TABLE','TRUNCATE TABLE','ALTER TABLE','DROP TRIGGER'];
                $contentUpper = strtoupper($content);
                $blocked = false;
                foreach ($dangerous as $kw) {
                    if (strpos($contentUpper, $kw) !== false) {
                        $blocked = true;
                        $msg_error = "File SQL mengandung perintah berbahaya: $kw. Upload ditolak.";
                        break;
                    }
                }

                if (!$blocked) {
                    // Validasi: hanya izinkan INSERT/DELETE ke tabel whitelist
                    $lines = explode("\n", $content);
                    $filteredLines = [];
                    $skippedLines  = 0;
                    $allowedTables = implode('|', $V1_SAFE_TABLES);

                    foreach ($lines as $line) {
                        $lineTrim = trim($line);
                        if (empty($lineTrim) || str_starts_with($lineTrim, '--') || str_starts_with($lineTrim, 'SET ') || str_starts_with($lineTrim, '/*')) {
                            $filteredLines[] = $line;
                            continue;
                        }
                        $lineUp = strtoupper($lineTrim);
                        if (str_starts_with($lineUp, 'INSERT INTO') || str_starts_with($lineUp, 'DELETE FROM')) {
                            // Cek apakah ke tabel yang diizinkan
                            if (preg_match('/(?:INSERT INTO|DELETE FROM)\s+`?(' . $allowedTables . ')`?/i', $lineTrim)) {
                                // Cek tidak ada tabel V2-only
                                $isV2Only = false;
                                foreach ($V2_ONLY_TABLES as $v2t) {
                                    if (stripos($lineTrim, $v2t) !== false) { $isV2Only = true; break; }
                                }
                                if (!$isV2Only) {
                                    $filteredLines[] = $line;
                                    continue;
                                }
                            }
                            $skippedLines++;
                        } else {
                            // Baris non-INSERT/DELETE yang aman (empty, comment, SET NAMES, dll)
                            if (preg_match('/^(SET\s+NAMES|SET\s+FOREIGN_KEY|--|\/\*)/i', $lineTrim)) {
                                $filteredLines[] = $line;
                            } else {
                                $skippedLines++;
                            }
                        }
                    }

                    // Jalankan SQL yang sudah difilter
                    $filteredSQL = implode("\n", $filteredLines);
                    $db = db();
                    $db->query("SET FOREIGN_KEY_CHECKS = 0");
                    $db->multi_query($filteredSQL);
                    // Kosongkan result buffer
                    $importedOk = 0;
                    do {
                        if ($res = $db->store_result()) { $res->free(); $importedOk++; }
                    } while ($db->more_results() && $db->next_result());
                    $db->query("SET FOREIGN_KEY_CHECKS = 1");

                    if ($db->errno && $db->errno !== 0) {
                        $msg_error = 'SQL Error: ' . $db->error;
                    } else {
                        $msg_ok = "Restore berhasil! File V1 telah di-import. $skippedLines baris dilewati (bukan bagian dari tabel yang aman).";
                    }
                }
            }
        }
    }
}

// ── HANDLE: Export backup V2 (opsional) ──────────────────
if (isset($_POST['action']) && $_POST['action'] === 'export_v2_backup') {
    $allTables = db_fetch_all("SHOW TABLES");
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="snet_v2_backup_' . date('Ymd_His') . '.sql"');
    header('Cache-Control: no-cache');
    echo "-- S.NET V2 Full Backup — " . date('Y-m-d H:i:s') . "\n";
    echo "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";
    $db = db();
    foreach ($allTables as $row) {
        $table = reset($row);
        echo "-- Tabel: $table\n";
        $showCreate = $db->query("SHOW CREATE TABLE \`$table\`");
        if ($showCreate) {
            $cr = $showCreate->fetch_row();
            echo $cr[1] . ";\n";
        }
        $result = $db->query("SELECT * FROM \`$table\`");
        if (!$result || $result->num_rows === 0) { echo "-- (kosong)\n\n"; continue; }
        $fields = [];
        while ($fi = $result->fetch_field()) { $fields[] = '`' . $fi->name . '`'; }
        $fStr = implode(', ', $fields);
        $rows = [];
        while ($row = $result->fetch_row()) {
            $vals = array_map(fn($v) => $v === null ? 'NULL' : "'" . $db->real_escape_string($v) . "'", $row);
            $rows[] = '(' . implode(', ', $vals) . ')';
            if (count($rows) >= 200) { echo "INSERT INTO \`$table\` ($fStr) VALUES\n" . implode(",\n", $rows) . ";\n"; $rows = []; }
        }
        if (!empty($rows)) echo "INSERT INTO \`$table\` ($fStr) VALUES\n" . implode(",\n", $rows) . ";\n";
        echo "\n";
    }
    echo "SET FOREIGN_KEY_CHECKS = 1;\n-- Selesai\n";
    exit;
}

include __DIR__ . '/../../include/header.php';
?>
<div class="page-header">
    <div>
        <h1 class="page-title"><i class="bi bi-cloud-arrow-down me-2 text-primary"></i>Backup & Restore</h1>
        <p class="page-subtitle">Backup database V2 dan restore data dari V1</p>
    </div>
</div>

<?php if ($msg_ok): ?>
<div class="alert alert-success d-flex gap-2"><i class="bi bi-check-circle-fill fs-5"></i><div><?= htmlspecialchars($msg_ok) ?></div></div>
<?php endif; ?>
<?php if ($msg_error): ?>
<div class="alert alert-danger d-flex gap-2"><i class="bi bi-x-circle-fill fs-5"></i><div><?= htmlspecialchars($msg_error) ?></div></div>
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
            <strong>Cara dapat file SQL:</strong> Buka <strong>V1 &rarr; Pengaturan &rarr; Backup &amp; Migrasi ke V2</strong>, lalu download file SQL-nya. Upload file tersebut di sini.
        </div>

        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="restore_v1">
            <div class="mb-3">
                <label class="form-label fw-bold">File SQL dari V1 <span class="text-danger">*</span></label>
                <input type="file" class="form-control" name="sql_file" accept=".sql" required>
                <div class="form-text">Hanya file .sql. Maksimal 50 MB. File yang dibuat dari menu Backup V1.</div>
            </div>

            <div class="alert alert-success mb-3" style="font-size:.83rem;">
                <i class="bi bi-shield-check me-2"></i>
                <strong>Data berikut TIDAK AKAN TERHAPUS:</strong><br>
                <span class="text-muted">Pelanggan PPPoE Rumahan, Konfigurasi WireGuard, WhatsApp Gateway, Data ONT, Bandwidth Snapshot</span>
            </div>

            <div class="alert alert-warning mb-3" style="font-size:.83rem;">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <strong>Data berikut AKAN DIGANTI dari V1:</strong><br>
                <span class="text-muted">Voucher hotspot, Profil paket, Data router/NAS, Riwayat sesi RADIUS, Akun admin</span>
            </div>

            <button type="submit" class="btn btn-primary w-100 btn-lg"
                    onclick="return confirm('Yakin ingin restore data dari V1?\n\nData voucher, profil, router, dan RADIUS akan diganti.\nData PPPoE Rumahan dan V2 lainnya AMAN.')">
                <i class="bi bi-cloud-arrow-down me-2"></i>Upload & Restore Sekarang
            </button>
        </form>
    </div>
</div>
</div>

<!-- BACKUP V2 -->
<div class="col-12 col-lg-5">
    <div class="card">
        <div class="card-header"><h5 class="card-title mb-0"><i class="bi bi-download me-2"></i>Backup Penuh V2</h5></div>
        <div class="card-body">
            <p class="text-muted small">Download backup seluruh database V2 (semua tabel). Simpan sebagai cadangan sebelum melakukan restore.</p>
            <?php
            $allTbls = db_fetch_all("SHOW TABLES");
            $tblCount = count($allTbls);
            ?>
            <div class="bg-light rounded p-2 mb-3" style="font-size:.82rem;">
                <div class="d-flex justify-content-between"><span>Total tabel</span><strong><?= $tblCount ?></strong></div>
                <?php foreach ($allTbls as $tRow):
                    $tn = reset($tRow);
                    $cnt = (int)(db_fetch_one("SELECT COUNT(*) AS n FROM \`$tn\`")['n'] ?? 0);
                ?>
                <div class="d-flex justify-content-between border-top py-1" style="font-size:.75rem;">
                    <span class="font-monospace"><?= htmlspecialchars($tn) ?></span>
                    <span class="text-muted"><?= number_format($cnt) ?> baris</span>
                </div>
                <?php endforeach; ?>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="export_v2_backup">
                <button type="submit" class="btn btn-outline-primary w-100">
                    <i class="bi bi-download me-2"></i>Download Backup V2 (.sql)
                </button>
            </form>
        </div>
    </div>

    <div class="card mt-3">
        <div class="card-header"><h6 class="card-title mb-0"><i class="bi bi-diagram-2 me-1"></i>Alur Migrasi V1 → V2</h6></div>
        <div class="card-body" style="font-size:.82rem;">
            <div class="d-flex align-items-center gap-2 mb-2">
                <span class="badge bg-secondary rounded-circle" style="width:24px;height:24px;line-height:24px;">V1</span>
                <span>Pengaturan → Backup & Migrasi ke V2</span>
            </div>
            <div class="text-center text-muted my-1">↓ Download .sql</div>
            <div class="d-flex align-items-center gap-2 mb-2">
                <span class="badge bg-primary rounded-circle" style="width:24px;height:24px;line-height:24px;">V2</span>
                <span>Pengaturan → Backup & Restore → Upload .sql</span>
            </div>
            <div class="text-center text-success my-1">✅ Selesai!</div>
        </div>
    </div>
</div>

</div>

<?php include __DIR__ . '/../../include/footer.php'; ?>
