<?php
/**
 * S.NET RADIUS & VPN — Pengaturan Server WireGuard
 */
if (!defined('IN_APP')) { define('IN_APP', true); }
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../include/functions.php';
require_once __DIR__ . '/../../include/wireguard_functions.php';

auth_check();

$pageTitle = 'Pengaturan Server VPN WireGuard';
$activeNav  = 'wireguard';

$settings = get_all_wg_settings();
$wgShowOutput = '';
$isWgRunning = false;

if (function_exists('shell_exec')) {
    $wgShowOutput = @shell_exec('sudo wg show 2>/dev/null') ?: @shell_exec('wg show 2>/dev/null');
    $res = @shell_exec('systemctl is-active wg-quick@wg0 2>/dev/null');
    if (trim((string)$res) === 'active') {
        $isWgRunning = true;
    }
}

include __DIR__ . '/../../include/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
        <a href="/index.php?page=wg_routers" class="text-decoration-none small text-muted">
            <i class="bi bi-arrow-left me-1"></i> Kembali ke Daftar Router
        </a>
        <h1 class="page-title mt-1"><i class="bi bi-gear-fill me-2 text-primary"></i>Pengaturan Server WireGuard VPN</h1>
        <p class="page-subtitle mb-0">Konfigurasi Endpoint Publik, Subnet Tunnel, dan Kunci Server</p>
    </div>
    <div>
        <?php if ($isWgRunning): ?>
        <span class="badge bg-success p-2"><i class="bi bi-shield-check me-1"></i> Service wg-quick@wg0 Active</span>
        <?php else: ?>
        <span class="badge bg-warning text-dark p-2"><i class="bi bi-exclamation-circle me-1"></i> Service Stopped / Not Installed</span>
        <?php endif; ?>
    </div>
</div>

<div class="row g-4">
    <div class="col-12 col-lg-8">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-transparent py-3">
                <h6 class="fw-bold mb-0"><i class="bi bi-sliders text-primary me-2"></i>Konfigurasi Server WireGuard</h6>
            </div>
            <div class="card-body p-4">
                <form method="POST" action="/process/wireguard/save_settings.php">
                    <input type="hidden" name="csrf" value="<?= $_SESSION['csrf_token'] ?? '' ?>">

                    <div class="row g-3 mb-3">
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Server Endpoint WireGuard (IP Publik / Domain:Port) <span class="text-danger">*</span></label>
                            <input type="text" name="wg_server_endpoint" class="form-control font-monospace" value="<?= htmlspecialchars($settings['wg_server_endpoint']) ?>" placeholder="Contoh: vpn.domain.com:51820 atau 203.0.113.1:51820" required>
                            <div class="form-text">Alamat yang digunakan router client MikroTik untuk terhubung ke VPS ini.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Listen Port UDP</label>
                            <input type="number" name="wg_listen_port" class="form-control font-monospace" value="<?= htmlspecialchars($settings['wg_listen_port']) ?>" placeholder="51820" required>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-7">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-globe2 text-primary me-1"></i> Host / IP Publik / Domain Khusus Remote
                            </label>
                            <input type="text" name="wg_remote_public_host" class="form-control font-monospace" value="<?= htmlspecialchars($settings['wg_remote_public_host'] ?? '') ?>" placeholder="Contoh: 203.175.10.133 atau direct.shawir.id">
                            <div class="form-text text-muted">
                                IP Publik VPS atau Domain Tanpa Proxy jika web menggunakan Cloudflare Tunnel.
                            </div>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-shuffle text-primary me-1"></i> Rentang Port Acak Remote ONT
                            </label>
                            <input type="text" name="wg_remote_port_range" class="form-control font-monospace" value="<?= htmlspecialchars($settings['wg_remote_port_range'] ?? '20000-58000') ?>" placeholder="20000-58000">
                            <div class="form-text text-muted">
                                Buka rentang port ini di firewall aaPanel / Cloud VPS.
                            </div>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-7">
                            <label class="form-label fw-semibold">Subnet Tunnel VPN (CIDR) <span class="text-danger">*</span></label>
                            <input type="text" name="wg_subnet_prefix" id="wg_subnet_prefix" class="form-control font-monospace" value="<?= htmlspecialchars(rtrim($settings['wg_subnet_prefix'], '.') . '.0/24') ?>" placeholder="10.66.66.0/24" required>
                            <div class="d-flex flex-wrap gap-1 mt-2">
                                <span class="small text-muted me-1">Preset:</span>
                                <button type="button" class="btn btn-xs btn-outline-secondary py-0" onclick="setSubnet('10.66.66.0/24')">10.66.66.0/24</button>
                                <button type="button" class="btn btn-xs btn-outline-secondary py-0" onclick="setSubnet('10.10.10.0/24')">10.10.10.0/24</button>
                                <button type="button" class="btn btn-xs btn-outline-secondary py-0" onclick="setSubnet('172.16.0.0/24')">172.16.0.0/24</button>
                                <button type="button" class="btn btn-xs btn-outline-secondary py-0" onclick="setSubnet('192.168.99.0/24')">192.168.99.0/24</button>
                            </div>
                            <div class="form-text mt-1">
                                IP Server: <code id="server_ip_preview"><?= rtrim($settings['wg_subnet_prefix'], '.') . '.1/24' ?></code> &middot; Client: <code id="client_ip_preview"><?= rtrim($settings['wg_subnet_prefix'], '.') . '.2' ?> s/d .254</code>
                            </div>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label fw-semibold">Nama Interface WireGuard</label>
                            <input type="text" name="wg_interface" class="form-control font-monospace" value="<?= htmlspecialchars($settings['wg_interface']) ?>" placeholder="wg0" required>
                            <div class="form-text">Default: <code>wg0</code></div>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">DNS Client</label>
                            <input type="text" name="wg_dns" class="form-control font-monospace" value="<?= htmlspecialchars($settings['wg_dns']) ?>" placeholder="1.1.1.1, 8.8.8.8">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">MTU Client</label>
                            <input type="number" name="wg_mtu" class="form-control font-monospace" value="<?= htmlspecialchars($settings['wg_mtu']) ?>" placeholder="1420">
                        </div>
                    </div>

                    <hr class="my-4">

                    <h6 class="fw-bold mb-3"><i class="bi bi-key-fill text-warning me-2"></i>Kunci Kriptografi Server (VPS)</h6>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Server Public Key</label>
                        <input type="text" name="wg_server_pubkey" id="server_pubkey" class="form-control font-monospace" value="<?= htmlspecialchars($settings['wg_server_pubkey']) ?>" placeholder="Public Key dari /etc/wireguard/server_public.key">
                        <div class="form-text">Kunci publik ini akan otomatis dimasukkan ke dalam skrip konfigurasi MikroTik.</div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold">Server Private Key</label>
                        <input type="password" name="wg_server_privkey" class="form-control font-monospace" value="<?= htmlspecialchars($settings['wg_server_privkey']) ?>" placeholder="Private Key server">
                        <div class="form-text">Disimpan secara aman di server untuk keperluan setup interface.</div>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <a href="/index.php?page=wg_routers" class="btn btn-light px-4">Batal</a>
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="bi bi-check-circle-fill me-1"></i> Simpan Pengaturan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Right Column: Status & Instructions -->
    <div class="col-12 col-lg-4">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-transparent py-3">
                <h6 class="fw-bold mb-0"><i class="bi bi-terminal text-dark me-2"></i>Output Live 'wg show'</h6>
            </div>
            <div class="card-body p-0">
                <pre class="m-0 p-3 bg-dark text-light small font-monospace rounded-bottom" style="max-height: 280px; overflow-y: auto;"><?= htmlspecialchars($wgShowOutput ?: 'Tidak ada peer yang terhubung / WireGuard belum aktif.') ?></pre>
            </div>
        </div>

        <div class="card border-0 shadow-sm bg-light">
            <div class="card-body p-4">
                <h6 class="fw-bold mb-2"><i class="bi bi-question-circle text-primary me-2"></i>Instalasi Server WireGuard</h6>
                <p class="small text-muted mb-2">
                    Jika server VPS belum memiliki WireGuard atau ingin setup otomatis dari nol, jalankan di terminal VPS:
                </p>
                <code class="d-block p-2 bg-white border rounded font-monospace small mb-3 text-dark">
                    sudo bash /www/wwwroot/s.shawir.id/setup_wireguard.sh
                </code>
                <p class="small text-muted mb-0">
                    Skrip akan otomatis menginstal paket WireGuard, mengatur iptables NAT forwarding, membuat keypair server, dan mengaktifkan service.
                </p>
</div>

<script>
function setSubnet(cidr) {
    document.getElementById('wg_subnet_prefix').value = cidr;
    updateSubnetPreview();
}

function updateSubnetPreview() {
    let val = document.getElementById('wg_subnet_prefix').value.trim();
    val = val.replace(/\/\d+$/, '').replace(/\.0$/, '').replace(/\.*$/, '');
    if (val) {
        document.getElementById('server_ip_preview').innerText = val + '.1/24';
        document.getElementById('client_ip_preview').innerText = val + '.2 s/d .254';
    }
}

document.getElementById('wg_subnet_prefix').addEventListener('input', updateSubnetPreview);
</script>

<?php include __DIR__ . '/../../include/footer.php'; ?>
