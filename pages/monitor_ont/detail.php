<?php
/**
 * S.NET RADIUS & VPN — Detail Perangkat ONT & Konfigurasi Lengkap GenieACS
 */
if (!defined('IN_APP')) { define('IN_APP', true); }
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../include/functions.php';
require_once __DIR__ . '/../../include/GenieACS.php';

auth_check();

$devId    = trim($_GET['id'] ?? '');
$serverId = (int)($_GET['genie_id'] ?? ($_GET['server_id'] ?? 0));

if (!$devId) {
    flash_set('error', 'ID Perangkat ONT tidak ditemukan.');
    header('Location: /index.php?page=monitor_ont');
    exit;
}

$servers = db_fetch_all("SELECT * FROM genie_config WHERE is_active = 1 ORDER BY name ASC");
$server  = null;

if ($serverId > 0) {
    foreach ($servers as $s) {
        if ((int)$s['id'] === $serverId) {
            $server = $s;
            break;
        }
    }
}
if (!$server && !empty($servers)) {
    $server = $servers[0];
    $serverId = (int)$server['id'];
}

if (!$server) {
    flash_set('error', 'Server GenieACS belum dikonfigurasi.');
    header('Location: /index.php?page=monitor_ont');
    exit;
}

$api = new GenieACS($server['url'], $server['username'], $server['password']);
$dev = $api->getDevice($devId);

if (!$dev) {
    flash_set('error', 'Perangkat ONT tidak ditemukan di GenieACS.');
    header('Location: /index.php?page=monitor_ont&server_id=' . $serverId);
    exit;
}

$info    = $api->getInfo($dev);
$opt     = $api->getOptical($dev);
$wifi    = $api->getWifi($dev);
$wanList = $api->getWanList($dev);
$clients = $api->getClients($dev);
$brand   = $api->detectBrandName($dev);

$pageTitle = 'Detail ONT — ' . ($brand . ' ' . $info['product']);
$activeNav  = 'monitor_ont';

// Hitung Redaman RX
$rxVal = (float)($opt['rx'] ?? 0);
$rxStatusText = 'Normal';
$rxBadgeClass = 'badge bg-success';
if ($rxVal !== 0.0 && $rxVal < -27) {
    $rxStatusText = 'Redaman Buruk';
    $rxBadgeClass = 'badge bg-danger';
} elseif ($rxVal !== 0.0 && $rxVal < -25) {
    $rxStatusText = 'Redaman Waspada';
    $rxBadgeClass = 'badge bg-warning text-dark';
}

include __DIR__ . '/../../include/header.php';
?>

<!-- Breadcrumb Navigation -->
<div class="mb-3">
    <a href="/index.php?page=monitor_ont&server_id=<?= $serverId ?>" class="text-decoration-none small text-muted">
        <i class="bi bi-arrow-left me-1"></i> Semua ONT
    </a>
</div>

<!-- Header Detail ONT -->
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
        <h2 class="page-title d-flex align-items-center gap-2 mb-1">
            <i class="bi bi-reception-4 text-primary"></i> <?= htmlspecialchars($brand . ' ' . $info['product']) ?>
        </h2>
        <p class="page-subtitle mb-0">
            Serial: <span class="font-monospace fw-bold text-dark"><?= htmlspecialchars($info['serial']) ?></span> &middot;
            <?php if ($info['online']): ?>
            <span class="text-success fw-bold"><i class="bi bi-circle-fill text-success" style="font-size: 8px;"></i> Online</span>
            <?php else: ?>
            <span class="text-secondary"><i class="bi bi-circle-fill text-secondary" style="font-size: 8px;"></i> Offline</span>
            <?php endif; ?>
            &middot; <span class="text-muted"><?= htmlspecialchars($info['last_seen']) ?></span>
        </p>
    </div>

    <!-- Quick Action Buttons: Refresh & Reboot -->
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="triggerTask('refresh')">
            <i class="bi bi-arrow-clockwise me-1"></i> Refresh
        </button>
        <button type="button" class="btn btn-warning text-white btn-sm fw-bold" style="background:#f05023; border-color:#f05023;" onclick="triggerTask('reboot')">
            <i class="bi bi-power me-1"></i> Reboot
        </button>
    </div>
</div>

<!-- Row 1: Info Perangkat & Edit WiFi -->
<div class="row g-4 mb-4">
    
    <!-- Box Kiri: Info Perangkat -->
    <div class="col-12 col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent py-3 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0 text-dark">
                    <i class="bi bi-info-circle-fill text-primary me-2"></i>Info Perangkat
                </h6>
                <small class="text-success fw-semibold">
                    <i class="bi bi-circle-fill text-success" style="font-size: 7px;"></i> update <?= date('H:i') ?>
                </small>
            </div>
            <div class="card-body p-0">
                <table class="table table-borderless table-striped align-middle mb-0" style="font-size: .88rem;">
                    <tbody>
                        <tr>
                            <td class="text-muted text-uppercase small fw-semibold ps-3" style="width: 35%;">BRAND/MODEL</td>
                            <td class="fw-bold text-dark pe-3"><?= htmlspecialchars($brand . ' ' . $info['product']) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted text-uppercase small fw-semibold ps-3">SERIAL</td>
                            <td class="pe-3">
                                <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 font-monospace px-2 py-1">
                                    <?= htmlspecialchars($info['serial']) ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted text-uppercase small fw-semibold ps-3">IP WAN</td>
                            <td class="font-monospace fw-bold text-primary pe-3"><?= htmlspecialchars($info['ip_wan']) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted text-uppercase small fw-semibold ps-3">FIRMWARE</td>
                            <td class="font-monospace small text-dark pe-3"><?= htmlspecialchars($info['sw_version']) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted text-uppercase small fw-semibold ps-3">UPTIME</td>
                            <td class="font-monospace text-dark pe-3"><?= htmlspecialchars($info['uptime']) ?> detik</td>
                        </tr>
                        <tr>
                            <td class="text-muted text-uppercase small fw-semibold ps-3">LAST INFORM</td>
                            <td class="pe-3"><?= htmlspecialchars($info['last_seen']) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted text-uppercase small fw-semibold ps-3">TAGS</td>
                            <td class="pe-3">
                                <?php if (!empty($info['tags'])): ?>
                                    <?php foreach ($info['tags'] as $t): ?>
                                    <span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25 font-monospace px-2 py-1">
                                        <?= htmlspecialchars($t) ?>
                                    </span>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted text-uppercase small fw-semibold ps-3">REDAMAN</td>
                            <td class="pe-3">
                                <?php if (!empty($opt['rx']) && $opt['rx'] !== '-'): ?>
                                <span class="<?= $rxBadgeClass ?> font-monospace px-2 py-1">
                                    <i class="bi bi-check-circle me-1"></i><?= htmlspecialchars($opt['rx']) ?> dBm
                                </span>
                                <span class="small text-muted ms-1"><?= $rxStatusText ?></span>
                                <?php else: ?>
                                <button type="button" class="btn btn-outline-primary btn-sm py-0 px-2" style="font-size: .78rem;" onclick="readOpticalLive()">
                                    <i class="bi bi-broadcast me-1"></i> Baca Redaman Sinyal
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Box Kanan: Edit WiFi (2.4G + 5G) -->
    <div class="col-12 col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent py-3">
                <h6 class="fw-bold mb-0 text-dark">
                    <i class="bi bi-pencil-square text-primary me-2"></i>Edit WiFi (2.4G + 5G)
                </h6>
            </div>
            <div class="card-body p-3">
                <form id="wifiDetailForm" onsubmit="submitWifiDetail(event)">
                    <input type="hidden" name="server_id" value="<?= $serverId ?>">
                    <input type="hidden" name="dev_id" value="<?= htmlspecialchars($devId) ?>">
                    <input type="hidden" name="action" value="set_wifi">
                    <input type="hidden" name="csrf" value="<?= $_SESSION['csrf_token'] ?? '' ?>">

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-uppercase" style="letter-spacing: .5px;">
                            <i class="bi bi-wifi text-primary me-1"></i> SSID 2.4 GHZ
                        </label>
                        <input type="text" name="ssid_24" class="form-control" value="<?= htmlspecialchars($wifi['ssid_24'] ?? '') ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-uppercase" style="letter-spacing: .5px;">
                            <i class="bi bi-wifi text-success me-1"></i> SSID 5 GHZ
                        </label>
                        <input type="text" name="ssid_5g" class="form-control" value="<?= htmlspecialchars($wifi['ssid_5g'] ?? '') ?>" placeholder="Kosongkan jika ONT tidak memiliki 5 GHz">
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-uppercase" style="letter-spacing: .5px;">
                            <i class="bi bi-key-fill text-warning me-1"></i> PASSWORD WIFI <span class="text-muted fw-normal">(BERLAKU 2.4G + 5G)</span>
                        </label>
                        <input type="text" name="pass_24" class="form-control font-monospace" value="<?= htmlspecialchars($wifi['pass_24'] ?? '') ?>" placeholder="Min 8 karakter" minlength="8" required>
                        <small class="text-muted d-block mt-1">Password sama akan dikirim ke 2.4 GHz dan 5 GHz sekaligus.</small>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold" id="submitWifiBtn">
                        <i class="bi bi-send-fill me-1"></i> Kirim WiFi ke ONT
                    </button>
                </form>
            </div>
        </div>
    </div>

</div>

<!-- Row 2: Tabs Management (WAN, Binding, Sinyal Optik, Clients) -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-transparent py-2 border-bottom">
        <ul class="nav nav-tabs card-header-tabs" id="ontDetailTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active fw-semibold" id="tab-wan-btn" data-bs-toggle="tab" data-bs-target="#tab-wan" type="button" role="tab">
                    <i class="bi bi-globe me-1"></i> WAN (<?= count($wanList) ?>)
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link fw-semibold" id="tab-opt-btn" data-bs-toggle="tab" data-bs-target="#tab-opt" type="button" role="tab">
                    <i class="bi bi-reception-4 me-1"></i> Sinyal Optik <span class="badge bg-success bg-opacity-10 text-success ms-1"><?= htmlspecialchars($opt['rx'] ?? '-') ?> dBm</span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link fw-semibold" id="tab-cli-btn" data-bs-toggle="tab" data-bs-target="#tab-cli" type="button" role="tab">
                    <i class="bi bi-phone me-1"></i> Clients (<?= count($clients) ?>)
                </button>
            </li>
        </ul>
    </div>
    
    <div class="card-body p-3">
        <div class="tab-content" id="ontDetailTabsContent">
            
            <!-- TAB 1: WAN CONNECTIONS -->
            <div class="tab-pane fade show active" id="tab-wan" role="tabpanel">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold mb-0"><i class="bi bi-globe text-primary me-2"></i>WAN Connections</h6>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.location.reload()">
                        <i class="bi bi-arrow-clockwise me-1"></i> Refresh
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size: .85rem;">
                        <thead class="table-light">
                            <tr>
                                <th>LABEL</th>
                                <th>NAMA</th>
                                <th>CONN TYPE</th>
                                <th>STATUS</th>
                                <th>IP WAN</th>
                                <th>GATEWAY</th>
                                <th>VLAN</th>
                                <th>NAT</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($wanList)): ?>
                            <tr><td colspan="8" class="text-center text-muted py-4">Belum ada koneksi WAN yang terdeteksi di ONT ini.</td></tr>
                            <?php else: ?>
                            <?php foreach ($wanList as $key => $wan): ?>
                            <tr>
                                <td>
                                    <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 font-monospace">
                                        <?= htmlspecialchars(strtoupper($wan['label'] ?? $key)) ?>
                                    </span>
                                </td>
                                <td class="font-monospace text-dark"><?= htmlspecialchars($wan['name'] ?? '-') ?></td>
                                <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($wan['conn_type'] ?? '-') ?></span></td>
                                <td>
                                    <?php if (stripos($wan['status'] ?? '', 'connect') !== false): ?>
                                    <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-pill px-2">
                                        CONNECTED
                                    </span>
                                    <?php else: ?>
                                    <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25 rounded-pill px-2">
                                        <?= htmlspecialchars($wan['status'] ?? 'DISCONNECTED') ?>
                                    </span>
                                    <?php endif; ?>
                                </td>
                                <td class="font-monospace fw-bold text-primary"><?= htmlspecialchars($wan['ip'] ?? '-') ?></td>
                                <td class="font-monospace text-muted"><?= htmlspecialchars($wan['gw'] ?? '-') ?></td>
                                <td>
                                    <?php if (!empty($wan['vlan']) && $wan['vlan'] !== '-'): ?>
                                    <span class="badge bg-warning bg-opacity-25 text-dark border border-warning">
                                        VLAN <?= htmlspecialchars($wan['vlan']) ?>
                                    </span>
                                    <?php else: ?>
                                    <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-light text-muted border"><?= htmlspecialchars($wan['nat'] ?? 'OFF') ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- TAB 2: SINYAL OPTIK -->
            <div class="tab-pane fade" id="tab-opt" role="tabpanel">
                <div class="row g-3">
                    <div class="col-md-3">
                        <div class="p-3 bg-light rounded text-center">
                            <small class="text-muted d-block mb-1">Redaman RX (Sinyal Masuk)</small>
                            <span class="fs-4 fw-bold font-monospace text-primary"><?= htmlspecialchars($opt['rx'] ?? '-') ?> dBm</span>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="p-3 bg-light rounded text-center">
                            <small class="text-muted d-block mb-1">Pancaran TX (Sinyal Keluar)</small>
                            <span class="fs-4 fw-bold font-monospace text-dark"><?= htmlspecialchars($opt['tx'] ?? '-') ?> dBm</span>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="p-3 bg-light rounded text-center">
                            <small class="text-muted d-block mb-1">Tegangan (Voltage)</small>
                            <span class="fs-4 fw-bold font-monospace text-dark"><?= htmlspecialchars($opt['voltage'] ?? '-') ?> V</span>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="p-3 bg-light rounded text-center">
                            <small class="text-muted d-block mb-1">Suhu Perangkat</small>
                            <span class="fs-4 fw-bold font-monospace text-dark"><?= htmlspecialchars($opt['temperature'] ?? '-') ?> °C</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TAB 3: CONNECTED CLIENTS -->
            <div class="tab-pane fade" id="tab-cli" role="tabpanel">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size: .85rem;">
                        <thead class="table-light">
                            <tr>
                                <th>MAC ADDRESS</th>
                                <th>IP ADDRESS</th>
                                <th>BAND</th>
                                <th>NAMA PERANGKAT (HOSTNAME)</th>
                                <th>SINYAL (RSSI)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($clients)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4">Belum ada perangkat client Wi-Fi yang terhubung saat ini.</td></tr>
                            <?php else: ?>
                            <?php foreach ($clients as $cli): ?>
                            <tr>
                                <td class="font-monospace fw-bold text-dark"><?= htmlspecialchars($cli['mac'] ?? '-') ?></td>
                                <td class="font-monospace text-primary"><?= htmlspecialchars($cli['ip'] ?? '-') ?></td>
                                <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($cli['band'] ?? '2.4G') ?></span></td>
                                <td><?= htmlspecialchars($cli['hostname'] ?? '-') ?></td>
                                <td class="font-monospace text-muted"><?= htmlspecialchars($cli['rssi'] ?? '-') ?> dBm</td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
async function submitWifiDetail(e) {
    e.preventDefault();
    const btn = document.getElementById('submitWifiBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Mengirim ke ONT via GenieACS...';

    try {
        const fd = new FormData(document.getElementById('wifiDetailForm'));
        const req = await fetch('/ajax/ont_detail.php', { method: 'POST', body: fd });
        const res = await req.json();

        if (res.success) {
            alert(res.message);
        } else {
            alert('Gagal: ' + res.error);
        }
    } catch (err) {
        alert('Gagal: ' + err.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-send-fill me-1"></i> Kirim WiFi ke ONT';
    }
}

async function triggerTask(action) {
    if (!confirm('Kirim perintah ' + action.toUpperCase() + ' ke perangkat ONT ini?')) return;

    try {
        const fd = new URLSearchParams();
        fd.append('server_id', '<?= $serverId ?>');
        fd.append('dev_id', '<?= htmlspecialchars(addslashes($devId)) ?>');
        fd.append('action', action);
        fd.append('csrf', '<?= $_SESSION['csrf_token'] ?? '' ?>');

        const req = await fetch('/ajax/genieacs_task.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: fd.toString()
        });
        const res = await req.json();
        if (res.success) {
            alert(res.message);
        } else {
            alert('Error: ' + res.error);
        }
    } catch (e) {
        alert('Gagal mengirim perintah: ' + e.message);
    }
}

async function readOpticalLive() {
    try {
        const fd = new URLSearchParams();
        fd.append('server_id', '<?= $serverId ?>');
        fd.append('dev_id', '<?= htmlspecialchars(addslashes($devId)) ?>');
        fd.append('action', 'refresh');
        fd.append('csrf', '<?= $_SESSION['csrf_token'] ?? '' ?>');

        const req = await fetch('/ajax/genieacs_task.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: fd.toString()
        });
        const res = await req.json();
        if (res.success) {
            alert('Perintah pembacaan parameter sinyal terkirim ke ONT via GenieACS! Halaman akan dimuat ulang...');
            setTimeout(() => { window.location.reload(); }, 2000);
        } else {
            alert('Error: ' + res.error);
        }
    } catch (e) {
        alert('Gagal: ' + e.message);
    }
}
</script>

<?php include __DIR__ . '/../../include/footer.php'; ?>
