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

/**
 * Split SQL dump into individual statements safely.
 * Handles strings, comments, and semicolons correctly without corrupting multi-line INSERTs.
 */
function splitSqlStatements(string $sql): array {
    $queries = [];
    $len = strlen($sql);
    $inString = false;
    $stringChar = '';
    $buffer = '';

    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];

        if ($inString) {
            $buffer .= $c;
            if ($c === '\\') {
                if ($i + 1 < $len) {
                    $i++;
                    $buffer .= $sql[$i];
                }
            } elseif ($c === $stringChar) {
                $inString = false;
            }
            continue;
        }

        // Line comment: -- ...
        if ($c === '-' && $i + 1 < $len && $sql[$i + 1] === '-') {
            $eol = strpos($sql, "\n", $i);
            if ($eol === false) break;
            $i = $eol;
            continue;
        }

        // Block comment: /* ... */
        if ($c === '/' && $i + 1 < $len && $sql[$i + 1] === '*') {
            $endComment = strpos($sql, '*/', $i);
            if ($endComment === false) break;
            $i = $endComment + 1;
            continue;
        }

        if ($c === "'" || $c === '"') {
            $inString = true;
            $stringChar = $c;
            $buffer .= $c;
            continue;
        }

        if ($c === ';') {
            $stmt = trim($buffer);
            if ($stmt !== '') {
                $queries[] = $stmt;
            }
            $buffer = '';
            continue;
        }

        $buffer .= $c;
    }

    $stmt = trim($buffer);
    if ($stmt !== '') {
        $queries[] = $stmt;
    }

    return $queries;
}

// ── HANDLE: Restore file SQL dari V1 ─────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'restore_v1') {
    @set_time_limit(300);
    @ini_set('memory_limit', '256M');

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
            $content = file_get_contents($tmpFile);
            if ($content === false) {
                $msg_error = 'Gagal membaca file SQL.';
            } else {
                // Keamanan: blokir perintah berbahaya
                $dangerous = ['DROP DATABASE', 'DROP TABLE', 'TRUNCATE TABLE', 'ALTER TABLE', 'DROP TRIGGER'];
                $blocked   = false;
                foreach ($dangerous as $kw) {
                    if (stripos($content, $kw) !== false) {
                        $blocked   = true;
                        $msg_error = "File SQL mengandung perintah berbahaya ($kw). Upload ditolak demi keamanan.";
                        break;
                    }
                }

                if (!$blocked) {
                    $statements = splitSqlStatements($content);
                    $db = db();
                    $db->query("SET FOREIGN_KEY_CHECKS = 0");

                    $executedCount = 0;
                    $skippedCount  = 0;
                    $errorCount    = 0;
                    $errorDetails  = [];

                    foreach ($statements as $stmt) {
                        $stmt = trim($stmt);
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

                    $db->query("SET FOREIGN_KEY_CHECKS = 1");

                    if ($errorCount > 0) {
                        $msg_error = "Restore selesai dengan \$errorCount error (\$executedCount query berhasil, \$skippedCount query dilewati): " . implode('; ', $errorDetails);
                    } else {
                        $msg_ok = "Restore data dari V1 BERHASIL! \$executedCount query berhasil dijalankan (\$skippedCount query dilewati/bukan tabel aman).";
                    }
                }
            }
        }
    }
}

// ── HANDLE: Export backup penuh V2 ───────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'export_v2_backup') {
    @set_time_limit(300);
    @ini_set('memory_limit', '256M');

    $res = bkQuery("SHOW TABLES");
    if (!$res) { die('Gagal membaca daftar tabel.'); }

    $tables = [];
    while ($r = $res->fetch_row()) { $tables[] = $r[0]; }

    header('Content-Type: application/sql; charset=UTF-8');
    header('Content-Disposition: attachment; filename="snet_v2_backup_' . date('Ymd_His') . '.sql"');
    header('Cache-Control: no-cache, no-store');

    $db = db();
    echo "-- ============================================================\n";
    echo "-- S.NET V2 Full Backup\n";
    echo "-- Dibuat: " . date('Y-m-d H:i:s') . "\n";
    echo "-- Total tabel: " . count($tables) . "\n";
    echo "-- ============================================================\n\n";
    echo "SET NAMES utf8mb4;\n";
    echo "SET FOREIGN_KEY_CHECKS = 0;\n\n";

    foreach ($tables as $table) {
        $safeTable = $db->real_escape_string($table);
        echo "-- ── Tabel: $table ──\n";

        // SHOW CREATE TABLE
        $crRes = $db->query("SHOW CREATE TABLE `" . $safeTable . "`");
        if ($crRes) {
            $cr = $crRes->fetch_row();
            if (!empty($cr[1])) {
                echo $cr[1] . ";\n";
            }
            $crRes->free();
        }

        // Data
        $dataRes = $db->query("SELECT * FROM `" . $safeTable . "`");
        if (!$dataRes || $dataRes->num_rows === 0) {
            echo "-- (tabel kosong)\n\n";
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
                echo "INSERT INTO `" . $safeTable . "` ($colStr) VALUES\n" . implode(",\n", $batch) . ";\n";
                $batch = [];
            }
        }
        if (!empty($batch)) {
            echo "INSERT INTO `" . $safeTable . "` ($colStr) VALUES\n" . implode(",\n", $batch) . ";\n";
        }
        $dataRes->free();
        echo "\n";
    }

    echo "SET FOREIGN_KEY_CHECKS = 1;\n";
    echo "-- ── Selesai ──\n";
    exit;
}

// ── Ambil daftar tabel untuk tampilan ────────────────────
$allTbls  = [];
$allRes   = bkQuery("SHOW TABLES");
if ($allRes) {
    while ($r = $allRes->fetch_row()) { $allTbls[] = $r[0]; }
}

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
            <strong>Cara dapat file SQL:</strong> Buka <strong>V1 &rarr; Pengaturan &rarr; Backup &amp; Migrasi ke V2</strong>, download file SQL-nya, lalu upload di sini.
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="restore_v1">
            <div class="mb-3">
                <label class="form-label fw-bold">File SQL dari V1 <span class="text-danger">*</span></label>
                <input type="file" class="form-control" name="sql_file" accept=".sql" required>
                <div class="form-text">Hanya file .sql. Maksimal 50 MB.</div>
            </div>
            <div class="alert alert-success mb-2" style="font-size:.83rem;">
                <i class="bi bi-shield-check me-2"></i><strong>TIDAK AKAN TERHAPUS:</strong>
                Pelanggan PPPoE Rumahan, WireGuard VPN, WhatsApp Gateway, Data ONT, Bandwidth Snapshot
            </div>
            <div class="alert alert-warning mb-3" style="font-size:.83rem;">
                <i class="bi bi-exclamation-triangle me-2"></i><strong>AKAN DIGANTI dari V1:</strong>
                Voucher hotspot, Profil paket, Data router/NAS, Riwayat sesi RADIUS, Akun admin
            </div>
            <button type="submit" class="btn btn-primary w-100 btn-lg"
                onclick="return confirm('Yakin restore dari V1?\n\nData voucher, profil, router, dan RADIUS akan diganti.\nData PPPoE Rumahan dan V2 lainnya AMAN.')">
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
            <div class="bg-light rounded p-2 mb-3" style="max-height:260px;overflow-y:auto;">
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
            <form method="POST">
                <input type="hidden" name="action" value="export_v2_backup">
                <button type="submit" class="btn btn-outline-primary w-100">
                    <i class="bi bi-download me-2"></i>Download Backup V2 (.sql)
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
            <div class="text-center text-muted my-1">&#8595; Download .sql</div>
            <div class="d-flex align-items-center gap-2 mb-2">
                <span class="badge bg-primary">V2</span>
                <span>Pengaturan &rarr; Backup &amp; Restore &rarr; Upload .sql</span>
            </div>
            <div class="text-center text-success fw-bold mt-1">&#10003; Selesai!</div>
        </div>
    </div>
</div>

</div>
<?php include __DIR__ . '/../../include/footer.php'; ?>
