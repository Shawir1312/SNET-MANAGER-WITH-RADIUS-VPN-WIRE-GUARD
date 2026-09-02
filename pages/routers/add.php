<?php
/**
 * Routers — Add/Edit Form
 */
auth_require_superadmin();
$is_edit   = ($page === 'router_edit');
$page_title = $is_edit ? 'Edit Router' : 'Tambah Router';
$router    = null;

if ($is_edit) {
    $id = (int)get('id');
    $router = db_fetch_one("SELECT * FROM routers WHERE id = ?", 'i', [$id]);
    if (!$router) { flash_set('error', 'Router tidak ditemukan.'); header('Location: /index.php?page=router_list'); exit; }
    if (!can_access_router($id)) { flash_set('error', 'Akses ditolak.'); header('Location: /index.php?page=router_list'); exit; }
}

include __DIR__ . '/../../include/header.php';
?>
<div class="page-header">
    <div>
        <h1 class="page-title"><?= $page_title ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="/index.php?page=router_list">Router</a></li>
                <li class="breadcrumb-item active"><?= $page_title ?></li>
            </ol>
        </nav>
    </div>
</div>

<div class="row justify-content-center">
<div class="col-12 col-lg-8">
<div class="card">
    <div class="card-header">
        <h5 class="card-title"><i class="bi bi-router"></i> <?= $page_title ?></h5>
    </div>
    <div class="card-body">
        <form method="POST" action="/process/save_router.php">
            <?php if ($is_edit): ?>
            <input type="hidden" name="id" value="<?= $router['id'] ?>">
            <?php endif; ?>
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Nama Router <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="name" required
                           value="<?= htmlspecialchars($router['name'] ?? '') ?>"
                           placeholder="Contoh: Router Kota A">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Router IP Address (API) <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="ip_address" required
                           value="<?= htmlspecialchars($router['ip_address'] ?? '') ?>"
                           placeholder="10.1.1.18">
                    <div class="form-text">IP untuk test koneksi dan API (Bisa IP lokal router)</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">RADIUS NAS IP <span class="text-muted">(Optional)</span></label>
                    <input type="text" class="form-control" name="nas_ip"
                           value="<?= htmlspecialchars($router['nas_ip'] ?? '0.0.0.0/0') ?>"
                           placeholder="0.0.0.0/0">
                    <div class="form-text">IP yang dideteksi FreeRADIUS (Biarkan 0.0.0.0/0 untuk VPN/NAT)</div>
                </div>
                <div class="col-md-12">
                    <label class="form-label">RADIUS Shared Secret <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <input type="password" class="form-control" name="radius_secret" id="radius_secret" required
                               value="<?= htmlspecialchars($router['radius_secret'] ?? '') ?>"
                               placeholder="Shared secret antara router dan FreeRADIUS">
                        <button type="button" class="btn btn-outline-secondary" onclick="
                            const f = document.getElementById('radius_secret');
                            f.type = f.type === 'password' ? 'text' : 'password';">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                    <div class="form-text">Harus sama dengan secret di konfigurasi clients.conf FreeRADIUS</div>
                </div>

                <div class="col-12"><hr class="my-1"><small class="text-muted fw-600">Koneksi RouterOS API (opsional — untuk test koneksi & provisioning)</small></div>

                <div class="col-md-4">
                    <label class="form-label">API Username</label>
                    <input type="text" class="form-control" name="api_user"
                           value="<?= htmlspecialchars($router['api_user'] ?? 'admin') ?>"
                           placeholder="admin">
                </div>
                <div class="col-md-4">
                    <label class="form-label">API Password</label>
                    <input type="password" class="form-control" name="api_password"
                           value="<?= htmlspecialchars($router['api_password'] ?? '') ?>"
                           placeholder="Password RouterOS">
                </div>
                <div class="col-md-4">
                    <label class="form-label">API Port</label>
                    <input type="number" class="form-control" name="api_port"
                           value="<?= $router['api_port'] ?? 8728 ?>"
                           min="1" max="65535">
                </div>

                <div class="col-12">
                    <label class="form-label">Lokasi / Keterangan</label>
                    <input type="text" class="form-control" name="location"
                           value="<?= htmlspecialchars($router['location'] ?? '') ?>"
                           placeholder="Contoh: Gedung A Lantai 2">
                </div>

                <div class="col-12"><hr class="my-1"><small class="text-success fw-bold"><i class="bi bi-hdd-network me-1"></i>Integrasi Auto-Provisioning ONT (GenieACS TR-069)</small></div>

                <div class="col-md-6">
                    <label class="form-label">Server GenieACS Cabang Ini</label>
                    <select class="form-select" name="genie_server_id">
                        <option value="">-- Gunakan Server Default / Utama --</option>
                        <?php 
                        $genie_servers = db_fetch_all("SELECT * FROM genie_config WHERE is_active = 1");
                        foreach ($genie_servers as $gs): 
                        ?>
                        <option value="<?= $gs['id'] ?>" <?= ($router['genie_server_id'] ?? '') == $gs['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($gs['name']) ?> (<?= htmlspecialchars($gs['url']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Server GenieACS yang mengelola ONT di area / cabang router ini.</div>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Default VLAN ID Pelanggan</label>
                    <input type="number" class="form-control font-mono fw-bold" name="default_vlan"
                           value="<?= htmlspecialchars($router['default_vlan'] ?? 100) ?>"
                           placeholder="100">
                    <div class="form-text">VLAN ID default yang otomatis terisi saat menambah pelanggan di router ini.</div>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status">
                        <option value="active" <?= ($router['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Aktif</option>
                        <option value="inactive" <?= ($router['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Nonaktif</option>
                    </select>
                </div>
            </div>

            <!-- Test API Result -->
            <?php if ($is_edit): ?>
            <div class="mt-3 p-3 bg-light rounded d-flex align-items-center gap-3">
                <button type="button" class="btn btn-outline-info btn-sm"
                        onclick="testRouterApi(<?= $router['id'] ?>, document.getElementById('api-test-result'))">
                    <i class="bi bi-lightning me-1"></i>Test Koneksi API
                </button>
                <span id="api-test-result" class="text-muted" style="font-size:.82rem;"></span>
            </div>
            <?php endif; ?>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save me-1"></i><?= $is_edit ? 'Simpan Perubahan' : 'Tambah Router' ?>
                </button>
                <a href="/index.php?page=router_list" class="btn btn-outline-secondary">Batal</a>
            </div>
        </form>
    </div>
</div>

<!-- Info card -->
<div class="card mt-3">
    <div class="card-body">
        <h6 class="fw-700 text-blue mb-2"><i class="bi bi-info-circle me-1"></i>Cara Konfigurasi MikroTik sebagai NAS</h6>
        <ol class="mb-0" style="font-size:.82rem;">
            <li>Di MikroTik: <strong>IP → Hotspot → Hotspot Setup</strong> (atau Radius client)</li>
            <li>Tambah Radius server: <strong>Radius → Add</strong> — Service: <code>hotspot</code>, Address: <em>IP server FreeRADIUS</em>, Secret: <em>shared secret di atas</em></li>
            <li>Di Hotspot Profile: aktifkan <strong>Use RADIUS</strong>, <strong>Accounting</strong>, <strong>NAS Port Type: Wireless-802.11</strong></li>
            <li>IP ini (<?= htmlspecialchars($router['ip_address'] ?? 'IP router') ?>) otomatis ditambahkan ke tabel <code>nas</code> FreeRADIUS saat Anda menyimpan form ini</li>
        </ol>
    </div>
</div>
</div>
</div>

<?php include __DIR__ . '/../../include/footer.php'; ?>
