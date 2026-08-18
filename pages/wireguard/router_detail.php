<?php
/**
 * S.NET RADIUS & VPN — Router WireGuard Detail & MikroTik Script Generator
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

$pageTitle = 'Detail Router WireGuard — ' . $router['name'];
$activeNav  = 'wireguard';

$peerStatus = wg_get_peer_status();
$st = $peerStatus[$router['public_key']] ?? null;
$isOnline = $st && $st['connected'];

$mikrotikScript = wg_generate_mikrotik_script($router);
$clientConf = wg_generate_client_conf($router);
$forwards = db_fetch_all("SELECT * FROM wg_port_forwards WHERE router_id = ? ORDER BY public_port ASC", 'i', [$router['id']]);
$settings = get_all_wg_settings();

include __DIR__ . '/../../include/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
        <a href="/index.php?page=wg_routers" class="text-decoration-none small text-muted">
            <i class="bi bi-arrow-left me-1"></i> Kembali ke Daftar Router
        </a>
        <h1 class="page-title mt-1 d-flex align-items-center gap-2">
            <?= htmlspecialchars($router['name']) ?>
            <?php if ($isOnline): ?>
            <span class="badge bg-success fs-7">🟢 Connected (Online)</span>
            <?php else: ?>
            <span class="badge bg-secondary fs-7">⚪ Offline</span>
            <?php endif; ?>
        </h1>
        <p class="page-subtitle mb-0">
            <span class="font-monospace text-primary fw-bold"><?= htmlspecialchars($router['tunnel_ip']) ?></span>
            <?php if ($router['location']): ?> &middot; <i class="bi bi-geo-alt"></i> <?= htmlspecialchars($router['location']) ?><?php endif; ?>
        </p>
    </div>
    <div class="d-flex gap-2">
        <a href="/index.php?page=wg_router_edit&id=<?= $router['id'] ?>" class="btn btn-outline-secondary">
            <i class="bi bi-pencil me-1"></i> Edit Router
        </a>
        <button type="button" class="btn btn-outline-danger" onclick="confirmDelete(<?= $router['id'] ?>, '<?= htmlspecialchars(addslashes($router['name'])) ?>')">
            <i class="bi bi-trash me-1"></i> Hapus
        </button>
    </div>
</div>

<div class="row g-4">
    <!-- Left Column: Router Info & Scripts -->
    <div class="col-12 col-lg-8">
        
        <!-- Live Traffic & Status Info -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-transparent py-3">
                <h6 class="fw-bold mb-0"><i class="bi bi-activity text-primary me-2"></i>Status Koneksi Real-Time</h6>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-6 col-md-3">
                        <div class="p-3 bg-light rounded text-center">
                            <small class="text-muted d-block mb-1">Status VPN</small>
                            <span class="fw-bold <?= $isOnline ? 'text-success' : 'text-secondary' ?>">
                                <?= $isOnline ? 'Terhubung' : 'Offline' ?>
                            </span>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 bg-light rounded text-center">
                            <small class="text-muted d-block mb-1">Last Handshake</small>
                            <span class="fw-semibold small">
                                <?= format_relative_time($st['last_handshake'] ?? null) ?>
                            </span>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 bg-light rounded text-center">
                            <small class="text-muted d-block mb-1">Total Download (RX)</small>
                            <span class="fw-bold text-success font-monospace">
                                <?= format_bytes($st['rx_bytes'] ?? 0) ?>
                            </span>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 bg-light rounded text-center">
                            <small class="text-muted d-block mb-1">Total Upload (TX)</small>
                            <span class="fw-bold text-primary font-monospace">
                                <?= format_bytes($st['tx_bytes'] ?? 0) ?>
                            </span>
                        </div>
                    </div>
                </div>

                <?php if ($st && !empty($st['endpoint'])): ?>
                <div class="mt-3 small text-muted">
                    <i class="bi bi-globe me-1"></i> Terhubung dari IP Publik Router: <code class="text-dark fw-bold"><?= htmlspecialchars($st['endpoint']) ?></code>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- MikroTik Script Generator Card -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-transparent py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h6 class="fw-bold mb-0 text-dark">
                        <i class="bi bi-terminal-fill text-danger me-2"></i>Skrip Konfigurasi MikroTik RouterOS v7
                    </h6>
                    <small class="text-muted">Salin dan jalankan langsung di Terminal Winbox MikroTik</small>
                </div>
                <button type="button" class="btn btn-sm btn-primary" onclick="copyScript()">
                    <i class="bi bi-clipboard-check me-1"></i> <span id="copyBtnText">Salin Skrip MikroTik</span>
                </button>
            </div>
            <div class="card-body p-0">
                <pre class="m-0 p-3 bg-dark text-light rounded-bottom font-monospace" style="font-size: .82rem; max-height: 380px; overflow-y: auto;" id="scriptArea"><?= htmlspecialchars($mikrotikScript) ?></pre>
            </div>
        </div>

        <!-- Port Forwarding for this router -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-transparent py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h6 class="fw-bold mb-0"><i class="bi bi-arrow-left-right text-info me-2"></i>Remote Access &amp; Port Forwarding</h6>
                    <small class="text-muted">Akses Winbox, Webfig, atau API router ini melalui IP Publik VPS</small>
                </div>
                <a href="/index.php?page=wg_port_forwarding&router_id=<?= $router['id'] ?>" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-plus-circle me-1"></i> Tambah NAT Port
                </a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Port Publik VPS</th>
                            <th>Target Router</th>
                            <th>Protokol</th>
                            <th>Akses Cepat (Winbox / Web)</th>
                            <th class="text-end">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($forwards)): ?>
                        <tr>
                            <td colspan="5" class="text-center py-4 text-muted small">
                                Belum ada port forwarding untuk router ini.
                                <a href="/index.php?page=wg_port_forwarding&router_id=<?= $router['id'] ?>" class="d-block mt-1">
                                    + Buka Remote Winbox Port 8291
                                </a>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($forwards as $pf): 
                            $vpsHost = get_remote_public_host();
                        ?>
                        <tr>
                            <td>
                                <span class="badge bg-primary fs-7 font-monospace"><?= $vpsHost ?>:<?= $pf['public_port'] ?></span>
                            </td>
                            <td>
                                <code class="text-dark"><?= htmlspecialchars($pf['target_ip'] ?: $router['tunnel_ip']) ?>:<?= $pf['target_port'] ?></code>
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
                                <span class="text-success small fw-semibold font-monospace">Winbox: <?= $vpsHost ?>:<?= $pf['public_port'] ?></span>
                                <?php else: ?>
                                <span class="text-muted small">Port <?= $pf['target_port'] ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <a href="/process/wireguard/delete_port_forward.php?id=<?= $pf['id'] ?>&csrf=<?= $_SESSION['csrf_token'] ?? '' ?>&redirect=router_detail&router_id=<?= $router['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Hapus port forward ini?')">
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

    </div>

    <!-- Right Column: Diagnostic & Keys Info -->
    <div class="col-12 col-lg-4">
        
        <!-- Live Diagnostic Card -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-transparent py-3">
                <h6 class="fw-bold mb-0"><i class="bi bi-tools text-primary me-2"></i>Alat Diagnostik Router</h6>
            </div>
            <div class="card-body p-3">
                <div class="d-grid gap-2 mb-3">
                    <button type="button" class="btn btn-outline-primary btn-sm text-start" onclick="runPing('<?= $router['tunnel_ip'] ?>')">
                        <i class="bi bi-broadcast-pin me-2"></i> Test Ping Tunnel (<?= $router['tunnel_ip'] ?>)
                    </button>
                    <button type="button" class="btn btn-outline-success btn-sm text-start" onclick="runPortCheck('<?= $router['tunnel_ip'] ?>', 8728)">
                        <i class="bi bi-cpu me-2"></i> Test MikroTik API Port 8728
                    </button>
                    <button type="button" class="btn btn-outline-info btn-sm text-start" onclick="runPortCheck('<?= $router['tunnel_ip'] ?>', 8291)">
                        <i class="bi bi-window-desktop me-2"></i> Test Winbox Port 8291
                    </button>
                </div>

                <div id="diagLoading" style="display:none;" class="text-center py-2 text-muted small">
                    <div class="spinner-border spinner-border-sm text-primary me-2"></div> Menjalankan tes...
                </div>

                <div id="diagResultBox" style="display:none;" class="p-2 bg-light border rounded small font-monospace">
                    <pre class="m-0" style="white-space: pre-wrap;" id="diagResultText"></pre>
                </div>
            </div>
        </div>

        <!-- WireGuard Client Config & Download -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-transparent py-3">
                <h6 class="fw-bold mb-0"><i class="bi bi-phone text-primary me-2"></i>Koneksi HP (iPhone / Android) &amp; PC</h6>
            </div>
            <div class="card-body p-3">
                <p class="small text-muted mb-3">
                    Gunakan <strong>Scan QR Code</strong> untuk iPhone/Android, atau unduh file <code>.conf</code> untuk PC Windows/Mac.
                </p>
                <div class="d-grid gap-2">
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#qrModal">
                        <i class="bi bi-qr-code me-1"></i> Scan QR Code (iPhone / Android)
                    </button>
                    <a href="/process/wireguard/download_conf.php?id=<?= $router['id'] ?>" class="btn btn-outline-dark btn-sm">
                        <i class="bi bi-download me-1"></i> Unduh File <?= htmlspecialchars($router['name']) ?>.conf
                    </a>
                </div>
            </div>
        </div>

        <!-- Detailed Info -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-transparent py-3">
                <h6 class="fw-bold mb-0"><i class="bi bi-info-circle me-2"></i>Detail Kriptografi</h6>
            </div>
            <div class="card-body p-3 small">
                <div class="mb-2">
                    <span class="text-muted d-block">Public Key Client:</span>
                    <code class="d-block text-truncate text-dark font-monospace"><?= htmlspecialchars($router['public_key']) ?></code>
                </div>
                <div class="mb-2">
                    <span class="text-muted d-block">IP Tunnel:</span>
                    <code class="fw-bold text-primary font-monospace"><?= htmlspecialchars($router['tunnel_ip']) ?>/24</code>
                </div>
                <?php if (!empty($router['lan_subnets'])): ?>
                <div class="mb-2">
                    <span class="text-muted d-block">Subnet LAN:</span>
                    <code><?= htmlspecialchars($router['lan_subnets']) ?></code>
                </div>
                <?php endif; ?>
                <div>
                    <span class="text-muted d-block">Dibuat Pada:</span>
                    <span><?= date('d M Y, H:i', strtotime($router['created_at'])) ?></span>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- Modal QR Code -->
<div class="modal fade" id="qrModal" tabindex="-1" aria-labelledby="qrModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="qrModalLabel"><i class="bi bi-qr-code me-2 text-primary"></i>Scan QR Code WireGuard</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center p-4">
                <div class="p-3 bg-white border rounded shadow-sm d-inline-block mb-3">
                    <div id="qrcodeCanvas"></div>
                </div>
                <h6 class="fw-bold mb-1"><?= htmlspecialchars($router['name']) ?> (<?= htmlspecialchars($router['tunnel_ip']) ?>)</h6>
                <p class="small text-muted mb-0">
                    1. Buka aplikasi <strong>WireGuard</strong> di iPhone / Android.<br>
                    2. Tekan tanda <strong>+</strong> &rarr; Pilih <strong>Create from QR code</strong>.<br>
                    3. Arahkan kamera ke QR Code di atas.
                </p>
            </div>
            <div class="modal-footer justify-content-between">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#rawConfigCollapse">
                    <i class="bi bi-code-square me-1"></i> Teks Config
                </button>
                <a href="/process/wireguard/download_conf.php?id=<?= $router['id'] ?>" class="btn btn-sm btn-primary">
                    <i class="bi bi-download me-1"></i> Unduh .conf
                </a>
            </div>
            <div class="collapse p-3 bg-light border-top" id="rawConfigCollapse">
                <pre class="m-0 small font-monospace bg-dark text-light p-2 rounded" style="max-height: 160px; overflow-y: auto;"><?= htmlspecialchars($clientConf) ?></pre>
            </div>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
let qrGenerated = false;
document.getElementById('qrModal').addEventListener('shown.bs.modal', function () {
    if (!qrGenerated) {
        const confText = <?= json_encode($clientConf) ?>;
        new QRCode(document.getElementById("qrcodeCanvas"), {
            text: confText,
            width: 220,
            height: 220,
            colorDark : "#000000",
            colorLight : "#ffffff",
            correctLevel : QRCode.CorrectLevel.M
        });
        qrGenerated = true;
    }
});

function copyScript() {
    const text = document.getElementById('scriptArea').innerText;
    navigator.clipboard.writeText(text).then(() => {
        const btn = document.getElementById('copyBtnText');
        btn.innerText = '✓ Berhasil Disalin!';
        setTimeout(() => { btn.innerText = 'Salin Skrip MikroTik'; }, 2500);
    });
}

function runPing(ip) {
    const box = document.getElementById('diagResultBox');
    const txt = document.getElementById('diagResultText');
    const ldr = document.getElementById('diagLoading');
    ldr.style.display = 'block';
    box.style.display = 'none';

    fetch('/process/wireguard/ajax_tools.php?action=ping&ip=' + encodeURIComponent(ip))
        .then(r => r.json())
        .then(d => {
            ldr.style.display = 'none';
            box.style.display = 'block';
            txt.innerText = d.output;
        })
        .catch(e => {
            ldr.style.display = 'none';
            box.style.display = 'block';
            txt.innerText = 'Error: ' + e;
        });
}

function runPortCheck(ip, port) {
    const box = document.getElementById('diagResultBox');
    const txt = document.getElementById('diagResultText');
    const ldr = document.getElementById('diagLoading');
    ldr.style.display = 'block';
    box.style.display = 'none';

    fetch('/process/wireguard/ajax_tools.php?action=port_check&ip=' + encodeURIComponent(ip) + '&port=' + port)
        .then(r => r.json())
        .then(d => {
            ldr.style.display = 'none';
            box.style.display = 'block';
            txt.innerText = d.message;
        })
        .catch(e => {
            ldr.style.display = 'none';
            box.style.display = 'block';
            txt.innerText = 'Error: ' + e;
        });
}

function confirmDelete(id, name) {
    if (confirm("Apakah Anda yakin ingin menghapus router WireGuard '" + name + "'?\n\nKoneksi VPN router ini akan langsung diputus dan dihapus dari server.")) {
        window.location.href = "/process/wireguard/delete_router.php?id=" + id + "&csrf=<?= $_SESSION['csrf_token'] ?? '' ?>";
    }
}
</script>

<?php include __DIR__ . '/../../include/footer.php'; ?>
