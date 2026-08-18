<?php
/**
 * S.NET RADIUS & VPN — Port Forwarding (Remote Winbox, Webfig, API NAT)
 */
if (!defined('IN_APP')) { define('IN_APP', true); }
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../include/functions.php';
require_once __DIR__ . '/../../include/wireguard_functions.php';

auth_check();

$pageTitle = 'Port Forwarding — Remote Access MikroTik';
$activeNav  = 'wireguard';

$selRouterId = (int)($_GET['router_id'] ?? 0);
$settings = get_all_wg_settings();
$vpsHost = explode(':', $settings['wg_server_endpoint'])[0];

$routers = db_fetch_all("SELECT id, name, tunnel_ip FROM wg_routers ORDER BY name ASC");

$sql = "SELECT pf.*, r.name as router_name, r.tunnel_ip as router_tunnel_ip 
        FROM wg_port_forwards pf 
        JOIN wg_routers r ON pf.router_id = r.id";
$params = [];
$types = "";

if ($selRouterId > 0) {
    $sql .= " WHERE pf.router_id = ?";
    $params = [$selRouterId];
    $types = "i";
}
$sql .= " ORDER BY pf.public_port ASC";

$forwards = db_fetch_all($sql, $types, $params);

include __DIR__ . '/../../include/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
        <a href="/index.php?page=wg_routers" class="text-decoration-none small text-muted">
            <i class="bi bi-arrow-left me-1"></i> Kembali ke Daftar Router
        </a>
        <h1 class="page-title mt-1"><i class="bi bi-arrow-left-right me-2 text-primary"></i>Port Forwarding (Remote Access)</h1>
        <p class="page-subtitle mb-0">Buka akses Winbox, Webfig, dan API router MikroTik di balik NAT/CGNAT via IP Publik VPS</p>
    </div>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-outline-secondary" onclick="syncFirewall()">
            <i class="bi bi-arrow-repeat me-1"></i> Sinkronkan Firewall
        </button>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addForwardModal">
            <i class="bi bi-plus-circle-fill me-1"></i> Tambah Port Forwarding
        </button>
    </div>
</div>

<!-- Info Alert -->
<div class="alert alert-info border-0 shadow-sm mb-4 d-flex align-items-center gap-3">
    <i class="bi bi-info-circle-fill fs-3 text-info"></i>
    <div class="small">
        <strong>Cara Kerja Remote Access:</strong> Port publik pada VPS Anda (misal: <code><?= $vpsHost ?>:8292</code>) akan otomatis diteruskan secara transparan melalui tunnel WireGuard ke port lokal MikroTik (misal: <code>10.66.66.2:8291</code>). Anda bisa membuka Winbox dari mana saja!
    </div>
</div>

<!-- Main Table Card -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-transparent py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
            <span class="fw-bold fs-6">Daftar Port Forwarding Aktif</span>
            <span class="badge bg-light text-dark border"><?= count($forwards) ?> Aturan</span>
        </div>

        <form method="GET" class="d-flex align-items-center gap-2">
            <input type="hidden" name="page" value="wg_port_forwarding">
            <label class="small text-muted text-nowrap">Filter Router:</label>
            <select name="router_id" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="0">Semua Router</option>
                <?php foreach ($routers as $r): ?>
                <option value="<?= $r['id'] ?>" <?= $selRouterId === (int)$r['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($r['name']) ?> (<?= $r['tunnel_ip'] ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Router Target</th>
                    <th>Port Publik VPS</th>
                    <th>Target IP:Port</th>
                    <th>Protokol</th>
                    <th>Akses Remote Cepat</th>
                    <th class="text-end" style="width: 100px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($forwards)): ?>
                <tr>
                    <td colspan="6" class="text-center py-5 text-muted">
                        <i class="bi bi-arrow-left-right fs-1 d-block mb-2 text-secondary"></i>
                        Belum ada aturan Port Forwarding yang dibuat.
                        <div class="mt-2">
                            <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addForwardModal">
                                <i class="bi bi-plus-circle me-1"></i> Tambah Port Forwarding Sekarang
                            </button>
                        </div>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($forwards as $pf): 
                    $targetIp = !empty($pf['target_ip']) ? $pf['target_ip'] : $pf['router_tunnel_ip'];
                ?>
                <tr>
                    <td>
                        <a href="/index.php?page=wg_router_detail&id=<?= $pf['router_id'] ?>" class="fw-bold text-decoration-none text-dark">
                            <?= htmlspecialchars($pf['router_name']) ?>
                        </a>
                    </td>
                    <td>
                        <span class="badge bg-primary fs-7 font-monospace"><?= $vpsHost ?>:<?= $pf['public_port'] ?></span>
                    </td>
                    <td>
                        <code class="text-dark"><?= htmlspecialchars($targetIp) ?>:<?= $pf['target_port'] ?></code>
                    </td>
                    <td>
                        <span class="badge bg-secondary text-uppercase"><?= htmlspecialchars($pf['protocol']) ?></span>
                    </td>
                    <td>
                        <?php if ($pf['target_port'] == 80 || $pf['target_port'] == 8080): ?>
                        <a href="http://<?= $vpsHost ?>:<?= $pf['public_port'] ?>" target="_blank" class="btn btn-xs btn-outline-info">
                            <i class="bi bi-box-arrow-up-right me-1"></i> Buka Webfig
                        </a>
                        <?php elseif ($pf['target_port'] == 8291): ?>
                        <span class="text-success small fw-bold font-monospace">Winbox: <?= $vpsHost ?>:<?= $pf['public_port'] ?></span>
                        <?php elseif ($pf['target_port'] == 8728): ?>
                        <span class="text-primary small fw-semibold">MikroTik API</span>
                        <?php elseif ($pf['target_port'] == 22): ?>
                        <span class="text-dark small font-monospace">ssh -p <?= $pf['public_port'] ?> admin@<?= $vpsHost ?></span>
                        <?php else: ?>
                        <span class="text-muted small">Port <?= $pf['target_port'] ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <a href="/process/wireguard/delete_port_forward.php?id=<?= $pf['id'] ?>&csrf=<?= $_SESSION['csrf_token'] ?? '' ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Apakah Anda yakin ingin menghapus port forwarding port <?= $pf['public_port'] ?>?')">
                            <i class="bi bi-trash"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Tambah Port Forwarding -->
<div class="modal fade" id="addForwardModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="/process/wireguard/save_port_forward.php">
                <input type="hidden" name="csrf" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                
                <div class="modal-header">
                    <h5 class="modal-title fw-bold"><i class="bi bi-plus-circle text-primary me-2"></i>Tambah Port Forwarding</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Pilih Router MikroTik <span class="text-danger">*</span></label>
                        <select name="router_id" id="modal_router_id" class="form-select" required onchange="updateDefaultTargetIp()">
                            <option value="">-- Pilih Router --</option>
                            <?php foreach ($routers as $r): ?>
                            <option value="<?= $r['id'] ?>" data-ip="<?= $r['tunnel_ip'] ?>" <?= $selRouterId === (int)$r['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($r['name']) ?> (<?= $r['tunnel_ip'] ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Preset Port Layanan</label>
                        <div class="d-flex flex-wrap gap-2 mb-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="applyPreset(8291, 'tcp')">Winbox (8291)</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="applyPreset(80, 'tcp')">Webfig (80)</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="applyPreset(8728, 'tcp')">MikroTik API (8728)</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="applyPreset(22, 'tcp')">SSH (22)</button>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold">Port Publik VPS <span class="text-danger">*</span></label>
                            <input type="number" name="public_port" id="modal_pub_port" class="form-control font-monospace" placeholder="Misal: 8292" min="1" max="65535" required>
                            <div class="form-text">Port yang dibuka di VPS</div>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">Target Port Router <span class="text-danger">*</span></label>
                            <input type="number" name="target_port" id="modal_target_port" class="form-control font-monospace" placeholder="Misal: 8291" min="1" max="65535" required>
                            <div class="form-text">Port lokal di MikroTik</div>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-8">
                            <label class="form-label fw-semibold">Target IP Router</label>
                            <input type="text" name="target_ip" id="modal_target_ip" class="form-control font-monospace" placeholder="Otomatis IP Tunnel">
                            <div class="form-text">Kosongkan untuk pakai IP tunnel router</div>
                        </div>
                        <div class="col-4">
                            <label class="form-label fw-semibold">Protokol</label>
                            <select name="protocol" id="modal_protocol" class="form-select">
                                <option value="tcp">TCP</option>
                                <option value="udp">UDP</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle-fill me-1"></i> Simpan &amp; Terapkan NAT
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function applyPreset(port, proto) {
    document.getElementById('modal_target_port').value = port;
    document.getElementById('modal_protocol').value = proto;
    if (!document.getElementById('modal_pub_port').value) {
        // Sarankan port publik (misal target 8291 -> pub 8292)
        document.getElementById('modal_pub_port').value = port === 8291 ? 8292 : (port === 80 ? 8080 : port + 1000);
    }
}

function updateDefaultTargetIp() {
    const sel = document.getElementById('modal_router_id');
    const opt = sel.options[sel.selectedIndex];
    if (opt && opt.dataset.ip) {
        document.getElementById('modal_target_ip').placeholder = opt.dataset.ip;
    }
}

function syncFirewall() {
    fetch('/process/wireguard/ajax_tools.php?action=sync_firewall')
        .then(r => r.json())
        .then(d => {
            alert('✓ Aturan firewall iptables berhasil disinkronkan!');
            window.location.reload();
        })
        .catch(e => alert('Error sync firewall: ' + e));
}
</script>

<?php include __DIR__ . '/../../include/footer.php'; ?>
