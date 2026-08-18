<?php
/**
 * S.NET RADIUS & VPN — Edit Router WireGuard
 */
if (!defined('IN_APP')) { define('IN_APP', true); }
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../include/functions.php';
require_once __DIR__ . '/../../include/wireguard_functions.php';

auth_check();

$id = (int)($_GET['id'] ?? 0);
$router = db_fetch_one("SELECT * FROM wg_routers WHERE id = ?", 'i', [$id]);

if (!$router) {
    flash_set('error', 'Router tidak ditemukan.');
    header('Location: /index.php?page=wg_routers');
    exit;
}

$pageTitle = 'Edit Router WireGuard — ' . $router['name'];
$activeNav  = 'wireguard';
$settings = get_all_wg_settings();

include __DIR__ . '/../../include/header.php';
?>

<div class="page-header mb-4">
    <a href="/index.php?page=wg_router_detail&id=<?= $router['id'] ?>" class="text-decoration-none small text-muted">
        <i class="bi bi-arrow-left me-1"></i> Kembali ke Detail Router
    </a>
    <h1 class="page-title mt-1"><i class="bi bi-pencil-square me-2 text-primary"></i>Edit Router: <?= htmlspecialchars($router['name']) ?></h1>
    <p class="page-subtitle mb-0">Ubah konfigurasi IP, subnet LAN, dan kunci WireGuard</p>
</div>

<div class="row g-4">
    <div class="col-12 col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <form method="POST" action="/process/wireguard/save_router.php">
                    <input type="hidden" name="csrf" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" value="<?= $router['id'] ?>">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Nama Router / Lokasi <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($router['name']) ?>" required>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Lokasi / Cabang</label>
                            <input type="text" name="location" class="form-control" value="<?= htmlspecialchars($router['location'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">IP Tunnel VPN <span class="text-danger">*</span></label>
                            <input type="text" name="tunnel_ip" class="form-control font-monospace" value="<?= htmlspecialchars($router['tunnel_ip']) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Listen Port MikroTik</label>
                            <input type="number" name="listen_port" class="form-control font-monospace" value="<?= htmlspecialchars($router['listen_port'] ?? 13231) ?>" placeholder="13231" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Subnet LAN Router (Opsional — untuk Interkoneksi Site-to-Site)</label>
                        <input type="text" name="lan_subnets" class="form-control font-monospace" value="<?= htmlspecialchars($router['lan_subnets'] ?? '') ?>">
                        <div class="form-text">Pisahkan dengan koma jika ada beberapa subnet (contoh: 192.168.10.0/24, 192.168.20.0/24).</div>
                    </div>

                    <hr class="my-4">

                    <h6 class="fw-bold mb-3"><i class="bi bi-key-fill text-warning me-2"></i>Kunci Kriptografi WireGuard</h6>
                    <div class="row g-3 mb-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold">Public Key Client <span class="text-danger">*</span></label>
                            <input type="text" name="public_key" class="form-control font-monospace" value="<?= htmlspecialchars($router['public_key']) ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Private Key Client <span class="text-danger">*</span></label>
                            <input type="text" name="private_key" class="form-control font-monospace" value="<?= htmlspecialchars($router['private_key']) ?>" required>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold">Catatan Tambahan</label>
                        <textarea name="notes" class="form-control" rows="2"><?= htmlspecialchars($router['notes'] ?? '') ?></textarea>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <a href="/index.php?page=wg_router_detail&id=<?= $router['id'] ?>" class="btn btn-light px-4">Batal</a>
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="bi bi-check-circle-fill me-1"></i> Perbarui Router
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../include/footer.php'; ?>
