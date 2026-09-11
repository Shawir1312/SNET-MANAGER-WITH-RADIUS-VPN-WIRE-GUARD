<?php
/**
 * Profiles — List & Add/Edit
 */
$is_edit    = ($page === 'profile_edit');
$is_list    = ($page === 'profile_list');
$page_title = $is_list ? 'Profil / Paket' : ($is_edit ? 'Edit Profil' : 'Tambah Profil');

ensure_profile_columns();

if ($is_list) {
    $filter_router = (int)get('router_id');
    $all_routers   = get_all_routers();
    
    $where_sql = "";
    $params = [];
    $types = "";
    if ($filter_router) {
        $where_sql = "WHERE p.router_id = ?";
        $params[] = $filter_router;
        $types = "i";
    }

    // ── LIST ──
    $profiles = db_fetch_all(
        "SELECT p.*, r.name AS router_name,
                (SELECT COUNT(*) FROM vouchers v WHERE v.profile_id = p.id AND v.status IN ('unused', 'active')) AS voucher_count
         FROM profiles p
         LEFT JOIN routers r ON p.router_id = r.id
         $where_sql
         ORDER BY r.name ASC, p.name ASC",
        $types, $params
    );
    include __DIR__ . '/../../include/header.php';
    ?>
<div class="page-header">
    <div>
        <h1 class="page-title">Profil / Paket</h1>
        <p class="page-subtitle">Kelola paket internet (mapping ke atribut RADIUS)</p>
    </div>
    <a href="/index.php?page=profile_add" class="btn btn-primary">
        <i class="bi bi-plus-circle me-1"></i> Tambah Profil
    </a>
</div>

<div class="card table-card">
    <div class="table-toolbar flex-wrap gap-2">
        <div class="d-flex align-items-center gap-3">
            <span class="fw-600"><?= count($profiles) ?> Profil</span>
            
            <form method="GET" class="d-flex align-items-center gap-2 m-0">
                <input type="hidden" name="page" value="profile_list">
                <select name="router_id" class="form-select form-select-sm" style="width:200px" onchange="this.form.submit()">
                    <option value="">Semua Cabang / Router</option>
                    <?php foreach ($all_routers as $rt): ?>
                    <option value="<?= $rt['id'] ?>" <?= $filter_router == $rt['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($rt['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
        <input type="text" id="table-search" class="form-control form-control-sm" style="width:220px" placeholder="Cari profil...">
    </div>
    <div class="table-responsive">
        <table class="table" id="data-table">
            <thead>
                <tr>
                    <th>Nama Profil</th>
                    <th>Masa Aktif</th>
                    <th>Durasi Pakai</th>
                    <th>Kuota</th>
                    <th>Rate Limit</th>
                    <th>Harga</th>
                    <th>Router</th>
                    <th>Status</th>
                    <th>Voucher</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($profiles)): ?>
            <tr><td colspan="10" class="text-center text-muted py-4">Belum ada profil.</td></tr>
            <?php else: ?>
            <?php foreach ($profiles as $p): ?>
            <tr>
                <td>
                    <div class="fw-bold"><?= htmlspecialchars($p['name']) ?></div>
                    <?php if ($p['display_name']): ?>
                    <small class="text-muted"><?= htmlspecialchars($p['display_name']) ?></small>
                    <?php endif; ?>
                </td>
                <td><?= $p['validity_value'] ?> <?= trans_unit($p['validity_unit'] ?? 'days') ?></td>
                <td>
                    <?php if ($p['duration_value'] == 0): ?>
                        <span class="badge bg-secondary">Unlimited</span>
                    <?php else: ?>
                        <?= $p['duration_value'] ?> <?= trans_unit($p['duration_unit'] ?? 'hours') ?>
                    <?php endif; ?>
                </td>
                <td><?= $p['quota_mb'] > 0 ? format_bytes($p['quota_mb'] * 1048576) : '<span class="text-muted">Unlimited</span>' ?></td>
                <td class="font-mono">
                    <span class="text-primary">↑<?= htmlspecialchars($p['rate_up'] ?: '0') ?></span> /
                    <span class="text-success">↓<?= htmlspecialchars($p['rate_down'] ?: '0') ?></span>
                </td>
                <td>
                    <div class="fw-bold"><?= format_price((float)$p['price']) ?></div>
                    <?php if (isset($p['include_in_sales']) && (int)$p['include_in_sales'] === 0): ?>
                    <span class="badge bg-secondary-subtle text-secondary border mt-1" style="font-size:.68rem;" title="Tidak dicatat ke data/laporan penjualan"><i class="bi bi-cart-x me-1"></i>Non-Penjualan</span>
                    <?php else: ?>
                    <span class="badge bg-success-subtle text-success border border-success-subtle mt-1" style="font-size:.68rem;" title="Dicatat ke data & laporan penjualan"><i class="bi bi-cart-check me-1"></i>Masuk Penjualan</span>
                    <?php endif; ?>
                    <?php if ($p['reseller_percent'] > 0): ?>
                    <br><small class="text-success"><i class="bi bi-tag"></i> Reseller: <?= (float)$p['reseller_percent'] ?>%</small>
                    <?php endif; ?>
                </td>
                <td><?= $p['router_id'] ? htmlspecialchars($p['router_name'] ?? '') : '<span class="text-muted">Semua Router</span>' ?></td>
                <td><?= $p['is_active'] ? '<span class="badge bg-success">Aktif</span>' : '<span class="badge bg-secondary">Nonaktif</span>' ?></td>
                <td><span class="badge bg-primary"><?= $p['voucher_count'] ?></span></td>
                <td>
                    <div class="d-flex gap-1">
                        <a href="/index.php?page=profile_edit&id=<?= $p['id'] ?>"
                           class="btn btn-sm btn-outline-primary btn-icon" title="Edit">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <a href="/index.php?page=generate_voucher&profile_id=<?= $p['id'] ?>"
                           class="btn btn-sm btn-outline-success btn-icon" title="Generate Voucher">
                            <i class="bi bi-plus-circle"></i>
                        </a>
                        <a href="/index.php?page=profile_delete&id=<?= $p['id'] ?>"
                           class="btn btn-sm btn-outline-danger btn-icon"
                           data-confirm="Hapus profil '<?= htmlspecialchars($p['name']) ?>'?"
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
</div>

<?php
    include __DIR__ . '/../../include/footer.php';
    return;
}

// ── ADD / EDIT FORM ──────────────────────────────────────
$profile = null;
if ($is_edit) {
    $id = (int)get('id');
    $profile = db_fetch_one("SELECT * FROM profiles WHERE id = ?", 'i', [$id]);
    if (!$profile) { flash_set('error', 'Profil tidak ditemukan.'); header('Location: /index.php?page=profile_list'); exit; }
}
$all_routers = get_all_routers();
include __DIR__ . '/../../include/header.php';
?>
<div class="page-header">
    <div>
        <h1 class="page-title"><?= $page_title ?></h1>
        <nav aria-label="breadcrumb"><ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="/index.php?page=profile_list">Profil</a></li>
            <li class="breadcrumb-item active"><?= $page_title ?></li>
        </ol></nav>
    </div>
</div>

<div class="row justify-content-center">
<div class="col-12 col-lg-8">
<div class="card">
    <div class="card-header">
        <h5 class="card-title"><i class="bi bi-collection"></i> <?= $page_title ?></h5>
    </div>
    <div class="card-body">
        <form method="POST" action="/process/save_profile.php">
            <?php if ($is_edit): ?>
            <input type="hidden" name="id" value="<?= $profile['id'] ?>">
            <?php endif; ?>
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

            <div class="row g-3">
                <?php if (!$is_edit): ?>
                <div class="col-12 mb-2">
                    <label class="form-label text-primary fw-bold"><i class="bi bi-magic me-1"></i>Isi Otomatis dari Template</label>
                    <select class="form-select border-primary bg-primary bg-opacity-10" id="profileTemplate" onchange="applyTemplate(this.value)">
                        <option value="">-- Pilih Template Profil (Opsional) --</option>
                        <option value="1jam">Paket 1 Jam (Rp 2.000)</option>
                        <option value="3jam">Paket 3 Jam (Rp 3.000)</option>
                        <option value="5jam">Paket 5 Jam (Rp 5.000)</option>
                        <option value="12jam">Paket 12 Jam (Rp 10.000)</option>
                        <option value="1hari">Paket 1 Hari / 24 Jam (Rp 15.000)</option>
                        <option value="1minggu">Paket 1 Minggu (Rp 30.000)</option>
                        <option value="1bulan">Paket 1 Bulan (Rp 100.000)</option>
                    </select>
                </div>
                <script>
                function applyTemplate(val) {
                    if(!val) return;
                    const f = document.querySelector('form[action="/process/save_profile.php"]');
                    const t = {
                        '1jam':   {n:'1 Jam', d:'1 JAM', vv:1, vu:'days', dv:1, du:'hours', p:2000, q:0, ru:'2M', rd:'3M'},
                        '3jam':   {n:'3 Jam', d:'3 JAM', vv:1, vu:'days', dv:3, du:'hours', p:3000, q:0, ru:'2M', rd:'3M'},
                        '5jam':   {n:'5 Jam', d:'5 JAM', vv:1, vu:'days', dv:5, du:'hours', p:5000, q:0, ru:'2M', rd:'3M'},
                        '12jam':  {n:'12 Jam', d:'12 JAM', vv:1, vu:'days', dv:12, du:'hours', p:10000, q:0, ru:'2M', rd:'3M'},
                        '1hari':  {n:'1 Hari', d:'1 HARI', vv:1, vu:'days', dv:24, du:'hours', p:15000, q:0, ru:'2M', rd:'3M'},
                        '1minggu':{n:'1 Minggu', d:'1 MINGGU', vv:7, vu:'days', dv:7, du:'days', p:30000, q:0, ru:'2M', rd:'3M'},
                        '1bulan': {n:'1 Bulan', d:'1 BULAN', vv:30, vu:'days', dv:30, du:'days', p:100000, q:0, ru:'2M', rd:'3M'}
                    }[val];
                    
                    if(t) {
                        f.querySelector('[name="name"]').value = t.n;
                        f.querySelector('[name="display_name"]').value = t.d;
                        f.querySelector('[name="validity_value"]').value = t.vv;
                        f.querySelector('[name="validity_unit"]').value = t.vu;
                        f.querySelector('[name="duration_value"]').value = t.dv;
                        f.querySelector('[name="duration_unit"]').value = t.du;
                        f.querySelector('[name="price"]').value = t.p;
                        f.querySelector('[name="quota_mb"]').value = t.q;
                        f.querySelector('[name="rate_up"]').value = t.ru;
                        f.querySelector('[name="rate_down"]').value = t.rd;
                        f.querySelector('[name="reseller_percent"]').value = 20;
                        if (f.querySelector('[name="include_in_sales"]')) {
                            f.querySelector('[name="include_in_sales"]').value = '1';
                        }
                    }
                }
                </script>
                <?php endif; ?>

                <div class="col-md-6">
                    <label class="form-label">Nama Profil <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="name" required
                           value="<?= htmlspecialchars($profile['name'] ?? '') ?>"
                           placeholder="Contoh: Paket 1 Jam">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Nama Tampilan (pada voucher)</label>
                    <input type="text" class="form-control" name="display_name"
                           value="<?= htmlspecialchars($profile['display_name'] ?? '') ?>"
                           placeholder="Contoh: 1 JAM - 5MB">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Masa Aktif <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <input type="number" class="form-control" name="validity_value" min="1" required
                               value="<?= $profile['validity_value'] ?? 30 ?>">
                        <select class="form-select" style="max-width: 120px;" name="validity_unit">
                            <option value="minutes" <?= ($profile['validity_unit'] ?? '') === 'minutes' ? 'selected' : '' ?>>Menit</option>
                            <option value="hours"   <?= ($profile['validity_unit'] ?? '') === 'hours' ? 'selected' : '' ?>>Jam</option>
                            <option value="days"    <?= ($profile['validity_unit'] ?? 'days') === 'days' ? 'selected' : '' ?>>Hari</option>
                        </select>
                    </div>
                    <div class="form-text">→ Batas absolut sejak pertama login (Expired_at)</div>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Durasi Pakai (0 = Unlimited)</label>
                    <div class="input-group">
                        <input type="number" class="form-control" name="duration_value" min="0" required
                               value="<?= $profile['duration_value'] ?? 30 ?>">
                        <select class="form-select" style="max-width: 120px;" name="duration_unit">
                            <option value="minutes" <?= ($profile['duration_unit'] ?? '') === 'minutes' ? 'selected' : '' ?>>Menit</option>
                            <option value="hours"   <?= ($profile['duration_unit'] ?? 'hours') === 'hours' ? 'selected' : '' ?>>Jam</option>
                            <option value="days"    <?= ($profile['duration_unit'] ?? '') === 'days' ? 'selected' : '' ?>>Hari</option>
                        </select>
                    </div>
                    <div class="form-text">→ Total kuota waktu online (Session-Timeout)</div>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Harga Jual (Rp)</label>
                    <input type="number" class="form-control" name="price" min="0" step="500"
                           value="<?= $profile['price'] ?? 0 ?>">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Pencatatan Penjualan <span class="text-danger">*</span></label>
                    <select class="form-select" name="include_in_sales">
                        <option value="1" <?= (!isset($profile['include_in_sales']) || (int)$profile['include_in_sales'] === 1) ? 'selected' : '' ?>>
                            Ya — Masuk ke Data Penjualan (Komersial)
                        </option>
                        <option value="0" <?= (isset($profile['include_in_sales']) && (int)$profile['include_in_sales'] === 0) ? 'selected' : '' ?>>
                            Tidak — Jangan Masukkan ke Data Penjualan (Non-Komersial / Free / Internal)
                        </option>
                    </select>
                    <div class="form-text">Jika "Tidak", voucher aktif dari profil ini tidak akan dihitung di Laporan Penjualan/Omset.</div>
                </div>
                
                <div class="col-md-6">
                    <label class="form-label">Keuntungan Reseller (%)</label>
                    <div class="input-group">
                        <input type="number" class="form-control" name="reseller_percent" min="0" max="100" step="0.01"
                               value="<?= $profile['reseller_percent'] ?? 0.00 ?>">
                        <span class="input-group-text">%</span>
                    </div>
                    <div class="form-text">Isi jika profil ini khusus untuk reseller tertentu.</div>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Kuota Data (MB) — 0 = Unlimited</label>
                    <div class="input-group">
                        <input type="number" class="form-control" name="quota_mb" min="0"
                               value="<?= $profile['quota_mb'] ?? 0 ?>">
                        <span class="input-group-text">MB</span>
                    </div>
                    <div class="form-text">→ Atribut RADIUS: <code>Mikrotik-Total-Limit</code></div>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Rate Upload</label>
                    <input type="text" class="form-control" name="rate_up"
                           value="<?= htmlspecialchars($profile['rate_up'] ?? '10M') ?>"
                           placeholder="10M">
                    <div class="form-text">Contoh: <code>512k</code>, <code>2M</code>, <code>10M</code></div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Rate Download</label>
                    <input type="text" class="form-control" name="rate_down"
                           value="<?= htmlspecialchars($profile['rate_down'] ?? '10M') ?>"
                           placeholder="10M">
                    <div class="form-text">→ <code>Mikrotik-Rate-Limit</code></div>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Cabang / Lokasi <span class="text-danger">*</span></label>
                    <select class="form-select" name="router_id">
                        <option value="">Semua Cabang (Global)</option>
                        <?php foreach ($all_routers as $r): ?>
                        <option value="<?= $r['id'] ?>" <?= ($profile['router_id'] ?? '') == $r['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($r['name']) ?> (<?= htmlspecialchars($r['ip_address']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Profil ini akan masuk ke cabang mana saat penagihan?</div>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="is_active">
                        <option value="1" <?= ($profile['is_active'] ?? 1) ? 'selected' : '' ?>>Aktif</option>
                        <option value="0" <?= ($profile['is_active'] ?? 1) ? '' : 'selected' ?>>Nonaktif</option>
                    </select>
                </div>

                <div class="col-12">
                    <label class="form-label">Keterangan</label>
                    <textarea class="form-control" name="description" rows="2"><?= htmlspecialchars($profile['description'] ?? '') ?></textarea>
                </div>
            </div>

            <?php if ($is_edit): ?>
            <!-- Sinkronisasi ke Voucher yang Ada -->
            <div class="mt-3 p-3 rounded border" style="background: rgba(13, 110, 253, 0.05); border-color: rgba(13, 110, 253, 0.25) !important;">
                <div class="fw-bold text-primary mb-2 d-flex align-items-center gap-2" style="font-size: 0.9rem;">
                    <i class="bi bi-arrow-repeat"></i> Sinkronisasi ke Voucher yang Ada
                </div>
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" name="sync_existing_vouchers" id="syncExistingVouchers" value="1" checked>
                    <label class="form-check-label fw-semibold" for="syncExistingVouchers" style="font-size: 0.85rem;">
                        Terapkan perubahan limit & masa aktif ke semua voucher lama (Aktif & Belum Dipakai)
                    </label>
                    <div class="form-text text-muted" style="font-size: 0.75rem;">
                        Memperbarui batas kecepatan (<code>Mikrotik-Rate-Limit</code>) dan durasi/kuota di RADIUS, serta menghitung ulang batas masa aktif (<code>expired_at</code>) pada voucher yang sedang aktif.
                    </div>
                </div>
                <div class="form-check form-switch ms-3" id="kickSessionsWrapper">
                    <input class="form-check-input" type="checkbox" name="disconnect_active" id="disconnectActive" value="1" checked>
                    <label class="form-check-label fw-semibold" for="disconnectActive" style="font-size: 0.85rem;">
                        Putus (Kick) sesi user yang sedang online saat ini
                    </label>
                    <div class="form-text text-muted" style="font-size: 0.75rem;">
                        User aktif di MikroTik akan diputus otomatis agar saat login ulang langsung mendapatkan limit kecepatan yang baru.
                    </div>
                </div>
            </div>
            <script>
            document.getElementById('syncExistingVouchers')?.addEventListener('change', function() {
                const kickWrapper = document.getElementById('kickSessionsWrapper');
                const kickInput = document.getElementById('disconnectActive');
                if (kickWrapper && kickInput) {
                    kickWrapper.style.display = this.checked ? 'block' : 'none';
                    kickInput.disabled = !this.checked;
                }
            });
            </script>
            <?php endif; ?>

            <!-- RADIUS Preview -->
            <div class="mt-3 p-3 rounded" style="background:var(--blue-pale); border-left:3px solid var(--blue);">
                <div class="fw-600 mb-2" style="font-size:.8rem;"><i class="bi bi-code me-1"></i>Preview Atribut RADIUS yang akan dikirim:</div>
                <code style="font-size:.78rem;">
                    Session-Timeout = <em>[durasi dalam detik]</em><br>
                    Mikrotik-Rate-Limit = "<em>[upload]</em>/<em>[download]</em>"<br>
                    <?= 'Mikrotik-Total-Limit = <em>[quota_mb × 1048576 bytes]</em>' ?>
                </code>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save me-1"></i><?= $is_edit ? 'Simpan Perubahan' : 'Tambah Profil' ?>
                </button>
                <a href="/index.php?page=profile_list" class="btn btn-outline-secondary">Batal</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>

<?php include __DIR__ . '/../../include/footer.php'; ?>
