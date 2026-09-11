<?php
/**
 * Routers — List Page
 */
auth_require_superadmin();
$page_title = 'Daftar Router / NAS';
$all_routers = get_all_routers();
include __DIR__ . '/../../include/header.php';
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Router / NAS</h1>
        <p class="page-subtitle">Kelola router MikroTik yang terdaftar sebagai RADIUS client</p>
    </div>
    <?php if (is_superadmin()): ?>
    <a href="/index.php?page=router_add" class="btn btn-primary">
        <i class="bi bi-plus-circle me-1"></i> Tambah Router
    </a>
    <?php endif; ?>
</div>

<div class="card table-card">
    <div class="table-toolbar">
        <span class="fw-600"><?= count($all_routers) ?> Router Terdaftar</span>
        <input type="text" id="table-search" class="form-control form-control-sm" style="width:220px" placeholder="Cari router...">
    </div>
    <div class="table-responsive">
        <table class="table" id="data-table">
            <thead><tr>
                <th>#</th>
                <th>Nama</th>
                <th>IP / NAS Name</th>
                <th>Lokasi</th>
                <th>Status</th>
                <th>Koneksi API</th>
                <th>Aksi</th>
            </tr></thead>
            <tbody>
            <?php if (empty($all_routers)): ?>
            <tr><td colspan="7" class="text-center text-muted py-4">
                <i class="bi bi-router display-4 d-block mb-2"></i>Belum ada router. <a href="/index.php?page=router_add">Tambah sekarang</a>
            </td></tr>
            <?php else: ?>
            <?php foreach ($all_routers as $i => $r): ?>
            <tr data-router-id="<?= $r['id'] ?>">
                <td class="text-muted"><?= $i+1 ?></td>
                <td>
                    <div class="fw-600"><?= htmlspecialchars($r['name']) ?></div>
                </td>
                <td class="font-mono"><?= htmlspecialchars($r['ip_address']) ?></td>
                <td class="text-muted"><?= htmlspecialchars($r['location'] ?: '-') ?></td>
                <td>
                    <?= $r['status'] === 'active'
                        ? "<span class='badge bg-success'>Aktif</span>"
                        : "<span class='badge bg-secondary'>Nonaktif</span>" ?>
                </td>
                <td>
                    <span class="router-status-badge badge bg-secondary">
                        <span class="status-dot"></span>Cek...
                    </span>
                    <span class="router-users-count text-muted ms-1" style="font-size:.73rem;"></span>
                </td>
                <td>
                    <div class="d-flex gap-1 flex-wrap">
                        <a href="/index.php?page=router_edit&id=<?= $r['id'] ?>"
                           class="btn btn-sm btn-outline-primary btn-icon" title="Edit">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <button class="btn btn-sm btn-outline-info btn-icon" title="Test API"
                                onclick="testRouterApi(<?= $r['id'] ?>, this.closest('tr').querySelector('.router-status-badge'))">
                            <i class="bi bi-lightning"></i>
                        </button>
                        <?php if (is_superadmin()): ?>
                        <a href="/index.php?page=router_delete&id=<?= $r['id'] ?>"
                           class="btn btn-sm btn-outline-danger btn-icon"
                           data-confirm="Hapus router '<?= htmlspecialchars($r['name']) ?>'? Voucher yang dikaitkan ke router ini akan terpengaruh."
                           title="Hapus">
                            <i class="bi bi-trash"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../../include/footer.php'; ?>
