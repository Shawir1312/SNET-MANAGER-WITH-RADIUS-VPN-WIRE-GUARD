<?php
/**
 * S.NET RADIUS & VPN — Tambah Router WireGuard
 */
if (!defined('IN_APP')) { define('IN_APP', true); }
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../include/functions.php';
require_once __DIR__ . '/../../include/wireguard_functions.php';

auth_check();

$pageTitle = 'Tambah Router WireGuard';
$activeNav  = 'wireguard';

$keys = wg_generate_keypair();
$suggestedIp = wg_get_next_tunnel_ip();
$settings = get_all_wg_settings();

include __DIR__ . '/../../include/header.php';
?>

<div class="page-header mb-4">
    <a href="/index.php?page=wg_routers" class="text-decoration-none small text-muted">
        <i class="bi bi-arrow-left me-1"></i> Kembali ke Daftar Router
    </a>
    <h1 class="page-title mt-1"><i class="bi bi-plus-circle-fill me-2 text-primary"></i>Tambah Router WireGuard Baru</h1>
    <p class="page-subtitle mb-0">Hubungkan router MikroTik cabang atau client ke Server VPN S.NET</p>
</div>

<div class="row g-4">
    <div class="col-12 col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <form method="POST" action="/process/wireguard/save_router.php">
                    <input type="hidden" name="csrf" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                    <input type="hidden" name="action" value="create">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Nama Router / Lokasi <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" placeholder="Contoh: Router-Cabang-Sentani / MikroTik-RB450Gx4" required autofocus>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Lokasi / Cabang</label>
                            <input type="text" name="location" class="form-control" placeholder="Contoh: Kantor Cabang Jayapura">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">IP Tunnel VPN <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="text" name="tunnel_ip" id="tunnel_ip" class="form-control font-monospace" value="<?= htmlspecialchars($suggestedIp) ?>" required>
                                <button type="button" class="btn btn-outline-secondary" onclick="generateNewIp()" title="Cari IP Baru">
                                    <i class="bi bi-arrow-clockwise"></i>
                                </button>
                            </div>
                            <div class="form-text">Subnet server: <code><?= htmlspecialchars($settings['wg_subnet_prefix']) ?>0/24</code></div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Subnet LAN Router (Opsional — untuk Interkoneksi Site-to-Site)</label>
                        <input type="text" name="lan_subnets" class="form-control font-monospace" placeholder="Contoh: 192.168.10.0/24, 192.168.20.0/24">
                        <div class="form-text">Pisahkan dengan koma jika router memiliki lebih dari satu subnet lokal.</div>
                    </div>

                    <hr class="my-4">

                    <h6 class="fw-bold mb-3"><i class="bi bi-key-fill text-warning me-2"></i>Kunci Kriptografi WireGuard</h6>
                    <div class="row g-3 mb-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold">Public Key Client <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="text" name="public_key" id="public_key" class="form-control font-monospace" value="<?= htmlspecialchars($keys['public_key']) ?>" required>
                                <button type="button" class="btn btn-outline-primary" onclick="generateNewKeys()">
                                    <i class="bi bi-shuffle me-1"></i> Generate Kunci Baru
                                </button>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Private Key Client <span class="text-danger">*</span></label>
                            <input type="text" name="private_key" id="private_key" class="form-control font-monospace" value="<?= htmlspecialchars($keys['private_key']) ?>" required>
                            <div class="form-text">Private key ini digunakan pada router client untuk membuka koneksi ke server.</div>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold">Catatan Tambahan</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Catatan teknis, kontak PIC cabang, dll."></textarea>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <a href="/index.php?page=wg_routers" class="btn btn-light px-4">Batal</a>
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="bi bi-check-circle-fill me-1"></i> Simpan &amp; Buat Skrip MikroTik
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-4">
        <div class="card border-0 shadow-sm bg-primary-subtle border-primary mb-4">
            <div class="card-body p-4">
                <h6 class="fw-bold text-primary mb-2"><i class="bi bi-info-circle-fill me-2"></i>Panduan Singkat</h6>
                <p class="small text-muted mb-2">
                    Setelah menyimpan form ini:
                </p>
                <ol class="small text-muted ps-3 mb-0" style="line-height: 1.6;">
                    <li>Sistem otomatis mendaftarkan peer ke service WireGuard Linux.</li>
                    <li>Sistem akan men-generate skrip RouterOS v7 MikroTik 1-klik siap pakai.</li>
                    <li>Anda cukup copy skrip tersebut dan paste di Terminal Winbox router target.</li>
                    <li>Router langsung terhubung ke VPS secara instan.</li>
                </ol>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <h6 class="fw-bold mb-3"><i class="bi bi-server me-2"></i>Info Server WireGuard</h6>
                <div class="small mb-2">
                    <span class="text-muted d-block">Endpoint Server:</span>
                    <code class="fw-bold text-dark"><?= htmlspecialchars($settings['wg_server_endpoint']) ?></code>
                </div>
                <div class="small mb-2">
                    <span class="text-muted d-block">Server Public Key:</span>
                    <code class="d-block text-truncate text-dark" style="max-width: 250px;"><?= htmlspecialchars($settings['wg_server_pubkey'] ?: '(Belum diatur)') ?></code>
                </div>
                <div class="small">
                    <span class="text-muted d-block">Subnet Tunnel:</span>
                    <code><?= htmlspecialchars($settings['wg_subnet_prefix']) ?>0/24</code>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function generateNewKeys() {
    fetch('/process/wireguard/ajax_tools.php?action=generate_keys')
        .then(r => r.json())
        .then(d => {
            if (d.public_key && d.private_key) {
                document.getElementById('public_key').value = d.public_key;
                document.getElementById('private_key').value = d.private_key;
            }
        })
        .catch(e => alert('Gagal generate key: ' + e));
}

function generateNewIp() {
    fetch('/process/wireguard/ajax_tools.php?action=get_next_ip')
        .then(r => r.json())
        .then(d => {
            if (d.ip) {
                document.getElementById('tunnel_ip').value = d.ip;
            }
        })
        .catch(e => alert('Gagal dapat IP: ' + e));
}
</script>

<?php include __DIR__ . '/../../include/footer.php'; ?>
