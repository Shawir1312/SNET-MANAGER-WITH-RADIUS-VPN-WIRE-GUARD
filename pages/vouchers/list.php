<?php
/**
 * Voucher — List Page (with filters)
 */
$page_title       = 'Daftar Voucher';
$show_router_filter = true;
$all_routers      = get_all_routers();
run_auto_expire_vouchers(); // Automatically sync and clean up database

// ── Filters ──────────────────────────────────────────────
$filter_status   = get('status', '');
$filter_router   = (int)get('router_id');
$filter_profile  = (int)get('profile_id');
$filter_batch    = get('batch_id', '');
$filter_search   = get('q', '');
$page_num        = max(1, (int)get('p', 1));

// Jika memfilter berdasarkan batch dan status tidak dispesifikasikan (atau default kosong),
// otomatis tampilkan voucher yang masih sisa (status = 'unused')
if ($filter_batch !== '' && ($filter_status === '' || !isset($_GET['status']))) {
    $filter_status = 'unused';
}

// Build WHERE
$where  = ["v.status != 'deleted'"];
$params = [];
$types  = '';

if ($filter_status && $filter_status !== 'all') {
    $where[] = "v.status = ?"; $params[] = $filter_status; $types .= 's';
}
if ($filter_router) {
    $where[] = "v.router_id = ?"; $params[] = $filter_router; $types .= 'i';
}
if ($filter_profile) {
    $where[] = "v.profile_id = ?"; $params[] = $filter_profile; $types .= 'i';
}
if ($filter_batch) {
    $where[] = "v.batch_id = ?"; $params[] = $filter_batch; $types .= 's';
}
if ($filter_search) {
    $where[] = "v.username LIKE ?"; $params[] = '%' . $filter_search . '%'; $types .= 's';
}

// Restrict by router access for operators
$access = accessible_router_ids();
if ($access !== null) {
    if (empty($access)) {
        $where[] = "1=0";
    } else {
        $pls = implode(',', array_fill(0, count($access), '?'));
        $where[] = "(v.router_id IN ({$pls}) OR v.router_id IS NULL)";
        foreach ($access as $rid) { $params[] = $rid; $types .= 'i'; }
    }
}

$where_sql = 'WHERE ' . implode(' AND ', $where);

// Count total
$total_count = (int)(db_fetch_one(
    "SELECT COUNT(*) AS n FROM vouchers v {$where_sql}", $types, $params
)['n'] ?? 0);

$pager = paginate($total_count, PER_PAGE, $page_num,
    "/index.php?page=voucher_list&status={$filter_status}&router_id={$filter_router}&profile_id={$filter_profile}&batch_id=" . urlencode($filter_batch) . "&q=" . urlencode($filter_search));

// Fetch vouchers
$vouchers = db_fetch_all(
    "SELECT v.*, p.name AS profile_name, r.name AS router_name, a.username AS gen_by,
            (SELECT rr.value FROM radreply rr WHERE rr.username = v.username AND rr.attribute = 'Session-Timeout' LIMIT 1) as session_timeout
     FROM vouchers v
     LEFT JOIN profiles p ON v.profile_id = p.id
     LEFT JOIN routers r ON v.router_id = r.id
     LEFT JOIN admins a ON v.generated_by = a.id
     {$where_sql}
     ORDER BY v.created_at DESC
     LIMIT ? OFFSET ?",
    $types . 'ii', array_merge($params, [PER_PAGE, $pager['offset']])
);

$profiles = db_fetch_all("SELECT id, name FROM profiles ORDER BY name ASC");

include __DIR__ . '/../../include/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Daftar Voucher</h1>
        <p class="page-subtitle">
            <?= number_format($total_count) ?> voucher <?= ($filter_status === 'unused') ? 'sisa ' : '' ?>ditemukan
            <?= $filter_batch ? ' — Batch: <span class="font-mono">' . htmlspecialchars($filter_batch) . '</span>' : '' ?>
        </p>
    </div>
    <div class="d-flex gap-2">
        <?php if ($filter_batch): ?>
        <a href="/index.php?page=voucher_print&batch_id=<?= urlencode($filter_batch) ?><?= $filter_profile ? '&profile_id='.urlencode($filter_profile) : '' ?>" target="_blank"
           class="btn btn-primary">
            <i class="bi bi-printer me-1"></i>Cetak Batch Ini
        </a>
        <?php endif; ?>
        <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#bulkDeleteModal">
            <i class="bi bi-trash-fill me-1"></i>Hapus Massal
        </button>
        <a href="/index.php?page=generate_voucher" class="btn btn-outline-primary">
            <i class="bi bi-plus-circle me-1"></i>Generate Baru
        </a>
    </div>
</div>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" action="/index.php" class="row g-2 align-items-end">
            <input type="hidden" name="page" value="voucher_list">
            <div class="col-sm-auto">
                <label class="form-label">Status</label>
                <select class="form-select form-select-sm" name="status" id="statusFilter">
                    <option value="" <?= ($filter_status==='' && !$filter_batch) ? 'selected':'' ?>>Semua</option>
                    <option value="all" <?= $filter_status==='all' ? 'selected':'' ?>>Semua Status</option>
                    <option value="unused"  <?= $filter_status==='unused'  ? 'selected':'' ?>>Belum Dipakai (Sisa)</option>
                    <option value="active"  <?= $filter_status==='active'  ? 'selected':'' ?>>Aktif</option>
                    <option value="expired" <?= $filter_status==='expired' ? 'selected':'' ?>>Kadaluarsa</option>
                </select>
            </div>
            <div class="col-sm-auto">
                <label class="form-label">Router</label>
                <select class="form-select form-select-sm" name="router_id">
                    <option value="">Semua Router</option>
                    <?php foreach ($all_routers as $r): ?>
                    <option value="<?= $r['id'] ?>" <?= $filter_router==$r['id'] ? 'selected':'' ?>>
                        <?= htmlspecialchars($r['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-auto">
                <label class="form-label">Profil</label>
                <select class="form-select form-select-sm" name="profile_id">
                    <option value="">Semua Profil</option>
                    <?php foreach ($profiles as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= $filter_profile==$p['id'] ? 'selected':'' ?>>
                        <?= htmlspecialchars($p['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php
            // Fetch batches with remaining (unused) voucher count
            $batch_list = db_fetch_all(
                "SELECT v.batch_id, DATE(MAX(v.created_at)) as created_date,
                        COUNT(*) as vcr_count,
                        SUM(CASE WHEN v.status = 'unused' THEN 1 ELSE 0 END) as sisa_count,
                        p.name AS profile_name
                 FROM vouchers v
                 LEFT JOIN profiles p ON v.profile_id = p.id
                 WHERE v.batch_id IS NOT NULL AND v.batch_id != '' AND v.status != 'deleted'
                 GROUP BY v.batch_id, p.name
                 ORDER BY MAX(v.created_at) DESC
                 LIMIT 100"
            );
            ?>
            <div class="col-sm-auto">
                <label class="form-label">Pilih Batch / Comment</label>
                <select class="form-select form-select-sm" name="batch_id" id="batchSelect" style="max-width: 320px;">
                    <option value="">— Semua Batch —</option>
                    <?php foreach ($batch_list as $b): 
                        $date_fmt = date('d M Y', strtotime($b['created_date']));
                        $prof = $b['profile_name'] ?: 'Tanpa Profil';
                        $sisa = (int)($b['sisa_count'] ?? 0);
                        $label = "{$date_fmt} — {$prof} [{$sisa} sisa] · {$b['batch_id']}";
                    ?>
                    <option value="<?= htmlspecialchars($b['batch_id']) ?>" <?= $filter_batch === $b['batch_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($label) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-auto">
                <label class="form-label">Cari Username</label>
                <input type="text" class="form-control form-control-sm" name="q"
                       value="<?= htmlspecialchars($filter_search) ?>" placeholder="Username...">
            </div>
            <div class="col-sm-auto">
                <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                <a href="/index.php?page=voucher_list" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Bulk Action Bar -->
<div id="bulk-bar" class="alert alert-info d-none d-flex align-items-center gap-3 mb-3">
    <span><strong id="selected-count">0</strong> voucher dipilih</span>
    <form method="POST" action="/process/delete_voucher.php" class="d-flex gap-2">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="ids" id="bulk-ids-input">
        <button type="submit" class="btn btn-danger btn-sm"
                data-confirm="Hapus voucher yang dipilih? Ini akan menghapus dari radcheck/radreply juga.">
            <i class="bi bi-trash me-1"></i>Hapus Dipilih
        </button>
    </form>
</div>

<!-- Table -->
<div class="card table-card">
    <div class="table-responsive">
        <table class="table" id="data-table">
            <thead><tr>
                <th><input type="checkbox" id="select-all" class="form-check-input"></th>
                <th>Username</th>
                <th>Password</th>
                <th>Profil</th>
                <th>Router</th>
                <th>Batch</th>
                <th>Status</th>
                <th>Dibuat</th>
                <th>Aksi</th>
            </tr></thead>
            <tbody>
            <?php if (empty($vouchers)): ?>
            <tr><td colspan="9" class="text-center text-muted py-5">
                <i class="bi bi-ticket-perforated display-4 d-block mb-2"></i>Tidak ada voucher ditemukan.
            </td></tr>
            <?php else: ?>
            <?php foreach ($vouchers as $v): ?>
            <tr>
                <td><input type="checkbox" class="form-check-input row-check" value="<?= $v['id'] ?>"></td>
                <td class="font-mono fw-600" style="font-size:.82rem;"><?= htmlspecialchars($v['username']) ?></td>
                <td class="font-mono" style="font-size:.82rem;"><?= htmlspecialchars($v['password']) ?></td>
                <td><?= htmlspecialchars($v['profile_name'] ?? '-') ?></td>
                <td><span class="text-muted" style="font-size:.78rem;"><?= htmlspecialchars($v['router_name'] ?? 'Semua') ?></span></td>
                <td>
                    <a href="/index.php?page=voucher_list&batch_id=<?= urlencode($v['batch_id'] ?? '') ?>&status=unused"
                       class="font-mono text-blue" style="font-size:.72rem;" title="Klik untuk lihat voucher sisa dari batch ini">
                        <?= htmlspecialchars($v['batch_id'] ?? '-') ?>
                    </a>
                </td>
                <td>
                    <?= voucher_status_badge($v['status']) ?>
                    <?php if ($v['used_at']): ?>
                    <div style="font-size:0.7rem; color:var(--gray-600); margin-top:2px;">
                        <i class="bi bi-box-arrow-in-right"></i> <?= date('d/m/y H:i', strtotime($v['used_at'])) ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($v['expired_at']): ?>
                    <div style="font-size:0.7rem; color:var(--red); margin-top:2px;">
                        <i class="bi bi-clock-history"></i> <?= date('d/m/y H:i', strtotime($v['expired_at'])) ?>
                        <?php 
                        if ($v['status'] === 'active') {
                            $rem = strtotime($v['expired_at']) - time();
                            if ($rem > 0) {
                                echo '<br><span class="badge bg-info text-dark mt-1" style="font-size:0.65rem;" title="Sisa Masa Aktif (Validity)"><i class="bi bi-calendar-x"></i> ' . seconds_to_human($rem) . '</span>';
                            } else {
                                echo '<br><span class="badge bg-danger mt-1" style="font-size:0.65rem;"><i class="bi bi-calendar-x"></i> Habis</span>';
                            }
                        }
                        ?>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($v['status'] === 'active' && isset($v['session_timeout'])): ?>
                    <div style="font-size:0.7rem; margin-top:2px;">
                        <?php 
                        $rem_dur = (int)$v['session_timeout'];
                        if ($rem_dur > 0) {
                            echo '<span class="badge bg-primary mt-1" style="font-size:0.65rem;" title="Sisa Kuota Waktu (Durasi)"><i class="bi bi-hourglass-split"></i> ' . seconds_to_human($rem_dur) . '</span>';
                        } else {
                            echo '<span class="badge bg-danger mt-1" style="font-size:0.65rem;"><i class="bi bi-hourglass-split"></i> Habis</span>';
                        }
                        ?>
                    </div>
                    <?php endif; ?>
                </td>
                <td style="font-size:.75rem;color:var(--gray-500);">
                    <?= date('d/m/Y H:i', strtotime($v['created_at'])) ?>
                </td>
                <td>
                    <div class="d-flex gap-1">
                        <a href="/index.php?page=voucher_print&voucher_id=<?= $v['id'] ?>" target="_blank"
                           class="btn btn-sm btn-outline-primary btn-icon" title="Cetak">
                            <i class="bi bi-printer"></i>
                        </a>
                        <a href="/index.php?page=voucher_delete&id=<?= $v['id'] ?>"
                           class="btn btn-sm btn-outline-danger btn-icon"
                           data-confirm="Hapus voucher '<?= htmlspecialchars($v['username']) ?>'? Ini juga akan menghapus dari radcheck/radreply."
                           title="Hapus">
                            <i class="bi bi-trash"></i>
                        </a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="card-body py-2 d-flex justify-content-between align-items-center">
        <small class="text-muted">
            Menampilkan <?= count($vouchers) ?> dari <?= number_format($total_count) ?> voucher
        </small>
        <?= pagination_html($pager) ?>
    </div>
</div>

<script>
// Bulk select (local scope to avoid redeclaration with app.js)
(function() {
    const _selectAll = document.getElementById('select-all');
    const _bulkBar   = document.getElementById('bulk-bar');
    const _countEl   = document.getElementById('selected-count');
    const _idsInput  = document.getElementById('bulk-ids-input');

    function updateBulk() {
        const checked = [...document.querySelectorAll('.row-check:checked')];
        const cnt = checked.length;
        if (_bulkBar) _bulkBar.classList.toggle('d-none', cnt === 0);
        if (_bulkBar) _bulkBar.classList.toggle('d-flex', cnt > 0);
        if (_countEl) _countEl.textContent = cnt;
        if (_idsInput) _idsInput.value = checked.map(cb => cb.value).join(',');
    }
    _selectAll?.addEventListener('change', function() {
        document.querySelectorAll('.row-check').forEach(cb => cb.checked = this.checked);
        updateBulk();
    });
    document.querySelectorAll('.row-check').forEach(cb => cb.addEventListener('change', updateBulk));

    // Auto-update status to 'unused' (sisa) when selecting a batch if status is currently empty or 'all'
    const _batchSel = document.getElementById('batchSelect');
    const _statusSel = document.getElementById('statusFilter');
    if (_batchSel && _statusSel) {
        _batchSel.addEventListener('change', function() {
            if (this.value && (_statusSel.value === '' || _statusSel.value === 'all')) {
                _statusSel.value = 'unused';
            }
        });
    }
})();
</script>

<!-- Bulk Delete Modal -->
<div class="modal fade" id="bulkDeleteModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="/process/bulk_delete_vouchers.php" class="modal-content">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-trash-fill text-danger me-2"></i>Hapus Massal Voucher</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i> <strong>Perhatian!</strong> Fitur ini akan menghapus voucher secara permanen dari database aplikasi dan dari Mikrotik (radcheck & radreply).
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Hapus Berdasarkan Profil</label>
                    <select class="form-select" name="profile_id">
                        <option value="">— Pilih Profil (Opsional) —</option>
                        <?php foreach ($profiles as $p): ?>
                        <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label">Hapus Berdasarkan Batch ID</label>
                    <select class="form-select" name="batch_id">
                        <option value="">— Kosongkan jika tidak memfilter Batch —</option>
                        <?php foreach ($batch_list as $b): 
                            $date_fmt = date('d M Y', strtotime($b['created_date']));
                            $prof = $b['profile_name'] ?: 'Tanpa Profil';
                            $sisa = (int)($b['sisa_count'] ?? 0);
                            $label = "{$date_fmt} — {$prof} [{$sisa} sisa] · {$b['batch_id']}";
                        ?>
                        <option value="<?= htmlspecialchars($b['batch_id']) ?>">
                            <?= htmlspecialchars($label) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label">Hapus Berdasarkan Status</label>
                    <select class="form-select" name="status">
                        <option value="">— Semua Status —</option>
                        <option value="unused">Belum Dipakai</option>
                        <option value="active">Aktif</option>
                        <option value="expired">Kadaluarsa</option>
                    </select>
                </div>

                <div class="form-check text-danger mt-4">
                    <input class="form-check-input" type="checkbox" name="confirm_all" id="confirmAll" required>
                    <label class="form-check-label fw-bold" for="confirmAll">
                        Saya yakin ingin menghapus voucher sesuai kriteria di atas!
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-danger"><i class="bi bi-trash"></i> Eksekusi Hapus</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../include/footer.php'; ?>
