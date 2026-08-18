<?php
/**
 * S.NET RADIUS & VPN — Monitor ONT (GenieACS Card Grid & Remote Access)
 */
if (!defined('IN_APP')) { define('IN_APP', true); }
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../include/functions.php';
require_once __DIR__ . '/../../include/GenieACS.php';
require_once __DIR__ . '/../../include/wireguard_functions.php';

auth_check();

$pageTitle = 'Monitor ONT (GenieACS)';
$activeNav  = 'monitor_ont';

// Pastikan tabel ont_remotes tersedia
try {
    db_execute("CREATE TABLE IF NOT EXISTS ont_remotes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ont_sn VARCHAR(100) NOT NULL,
        ont_name VARCHAR(150) DEFAULT NULL,
        ont_ip VARCHAR(50) NOT NULL,
        target_port INT NOT NULL DEFAULT 80,
        public_port INT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NOT NULL,
        is_active TINYINT(1) DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

// Ambil semua server GenieACS aktif
$servers = db_fetch_all("SELECT * FROM genie_config WHERE is_active = 1 ORDER BY name ASC");

$selServerId = (int)($_GET['server_id'] ?? 0);
if (!$selServerId && !empty($servers)) {
    $selServerId = (int)$servers[0]['id'];
}

$selServer = null;
foreach ($servers as $s) {
    if ((int)$s['id'] === $selServerId) {
        $selServer = $s;
        break;
    }
}

$devices = [];
$api_error = '';

if ($selServer) {
    try {
        $api = new GenieACS($selServer['url'], $selServer['username'], $selServer['password']);
        
        $projection = '_id,_lastInform,_deviceId,_tags,VirtualParameters,InternetGatewayDevice.DeviceInfo,InternetGatewayDevice.WANDevice,InternetGatewayDevice.LANDevice.1.WLANConfiguration,InternetGatewayDevice.X_ALU_OntOpticalParam,InternetGatewayDevice.X_ZTE-COM_WANPONInterfaceConfig,InternetGatewayDevice.X_ZTE-COM_PONInterfaceConfig,InternetGatewayDevice.X_FH_GponInterfaceConfig,InternetGatewayDevice.X_GponInterafceConfig,InternetGatewayDevice.X_GponInterfaceConfig,InternetGatewayDevice.X_HW_OpticalParameter';
        $raw_devices = $api->getDevices('{}', $projection);
        
        if ($api->error) {
            throw new Exception($api->error);
        }
        
        foreach ($raw_devices as $dev) {
            $info = $api->getInfo($dev);
            $opt  = $api->getOptical($dev);
            $wifi = $api->getWifi($dev);
            
            $last_inform = strtotime($dev['_lastInform'] ?? '0');
            $diffMins = $last_inform ? (time() - $last_inform) / 60 : 9999;
            $is_online = $diffMins < 20; // Margin online 20 menit
            
            // Format waktu relatif
            if ($diffMins < 1) {
                $lastSeenText = 'Baru saja';
            } elseif ($diffMins < 60) {
                $lastSeenText = round($diffMins) . 'm lalu';
            } elseif ($diffMins < 1440) {
                $lastSeenText = round($diffMins / 60) . 'j lalu';
            } else {
                $lastSeenText = date('d/m/Y', $last_inform);
            }

            // Kumpulkan SSID yang aktif
            $ssids = [];
            if (!empty($wifi['ssid_24'])) $ssids[] = $wifi['ssid_24'];
            if (!empty($wifi['ssid_5g']) && $wifi['ssid_5g'] !== $wifi['ssid_24']) $ssids[] = $wifi['ssid_5g'];

            $devices[] = [
                '_id'         => $dev['_id'],
                'sn'          => $info['serial'] ?: ($dev['_deviceId']['_SerialNumber'] ?? $dev['_id']),
                'model'       => $info['product'] ?: ($dev['_deviceId']['_ProductClass'] ?? 'ONT Device'),
                'brand'       => $api->detectBrandName($dev),
                'manufacturer'=> $info['manufacturer'] ?: ($dev['_deviceId']['_Manufacturer'] ?? ''),
                'ip'          => $info['ip_wan'] ?? '-',
                'rx'          => $opt['rx'] ?? '-',
                'tx'          => $opt['tx'] ?? '-',
                'last_inform' => $dev['_lastInform'] ?? null,
                'last_seen'   => $lastSeenText,
                'is_online'   => $is_online,
                'tags'        => $dev['_tags'] ?? [],
                'ssids'       => $ssids,
                'raw'         => $dev
            ];
        }
    } catch (Throwable $e) {
        $api_error = 'Koneksi ke GenieACS (' . htmlspecialchars($selServer['name']) . ') gagal: ' . $e->getMessage();
    }
}

// Ambil data pelanggan PPPoE untuk pencocokan nama
$customers = db_fetch_all("SELECT pppoe_username, full_name, ont_sn, phone, address FROM pppoe_customers WHERE ont_sn != ''");
$cust_map = [];
foreach ($customers as $c) {
    $sn_upper = strtoupper(trim($c['ont_sn']));
    if ($sn_upper) {
        $cust_map[$sn_upper] = [
            'username' => $c['pppoe_username'],
            'name'     => $c['full_name'],
            'phone'    => $c['phone'],
            'address'  => $c['address']
        ];
    }
}

// Ambil sesi remote aktif saat ini
$activeRemotes = [];
try {
    $vpsHost = get_remote_public_host();

    $remRows = db_fetch_all("SELECT *, (UNIX_TIMESTAMP(expires_at) - UNIX_TIMESTAMP(NOW())) as rem_sec FROM ont_remotes WHERE is_active = 1 AND expires_at > NOW()");
    foreach ($remRows as $rr) {
        $activeRemotes[$rr['ont_sn']] = [
            'port'       => (int)$rr['public_port'],
            'url'        => "http://{$vpsHost}:{$rr['public_port']}",
            'rem_sec'    => max(0, (int)$rr['rem_sec']),
            'expires_at' => date('H:i', strtotime($rr['expires_at']))
        ];
    }
} catch (Throwable $e) {}

include __DIR__ . '/../../include/header.php';
?>

<!-- Search Bar & Header -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-3">
        <label class="form-label small fw-bold text-muted mb-1 text-uppercase" style="letter-spacing: .5px;">
            <i class="bi bi-search me-1 text-primary"></i> Cari Serial / SSID / Tag
        </label>
        <div class="input-group">
            <input type="text" id="ontSearchInput" class="form-control form-control-lg font-monospace" placeholder="Ketik Serial Number, Nama Pelanggan, SSID, atau IP ONT..." autofocus>
            <button class="btn btn-primary px-4 fw-bold" type="button" id="ontSearchBtn">
                <i class="bi bi-search me-1"></i> Cari
            </button>
        </div>
    </div>
</div>

<!-- Server Selector & Total Badge -->
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div class="d-flex align-items-center gap-2">
        <h5 class="fw-bold mb-0 text-dark d-flex align-items-center">
            <i class="bi bi-router-fill text-primary me-2"></i>
            Server: <span class="text-primary ms-1"><?= htmlspecialchars($selServer['name'] ?? 'SERVER ACS') ?></span>
        </h5>
        <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-2 py-1 fs-7">
            <?= count($devices) ?> ONT
        </span>
    </div>

    <div class="d-flex align-items-center gap-2">
        <?php if (count($servers) > 1): ?>
        <form method="GET" class="m-0">
            <input type="hidden" name="page" value="monitor_ont">
            <select name="server_id" class="form-select form-select-sm" onchange="this.form.submit()">
                <?php foreach ($servers as $srv): ?>
                <option value="<?= $srv['id'] ?>" <?= $selServerId == $srv['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($srv['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php endif; ?>

        <a href="/index.php?page=genieacs_servers" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-gear me-1"></i> Config ACS
        </a>
    </div>
</div>

<?php if (empty($servers)): ?>
    <div class="alert alert-warning py-3">
        <h5 class="alert-heading"><i class="bi bi-exclamation-triangle me-2"></i>Belum Ada Server GenieACS!</h5>
        <p class="mb-0">Anda perlu mengkonfigurasi server GenieACS terlebih dahulu di menu <strong>Administrasi > Config GenieACS</strong>.</p>
    </div>
<?php else: ?>

    <?php if ($api_error): ?>
        <div class="alert alert-danger py-2 mb-3"><i class="bi bi-exclamation-octagon me-2"></i><?= htmlspecialchars($api_error) ?></div>
    <?php endif; ?>

    <!-- Active Remote Sessions Alert Banner -->
    <div id="activeRemotesBanner" class="alert alert-warning border-warning d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4" style="<?= empty($activeRemotes) ? 'display:none;' : '' ?>">
        <div class="d-flex align-items-center gap-2">
            <span class="spinner-grow spinner-grow-sm text-danger" role="status"></span>
            <div>
                <strong>Akses Remote ONT Sedang Aktif:</strong>
                <span id="activeRemotesText" class="ms-1 small">
                    <?php foreach ($activeRemotes as $sn => $ar): ?>
                    <span class="badge bg-dark me-1">SN: <?= htmlspecialchars($sn) ?> &rarr; <a href="<?= $ar['url'] ?>" target="_blank" class="text-warning text-decoration-none font-monospace"><?= $ar['url'] ?></a> (Sisa <?= floor($ar['rem_sec']/60) ?>m)</span>
                    <?php endforeach; ?>
                </span>
            </div>
        </div>
        <small class="text-muted fst-italic">Akses remote akan otomatis terhapus dalam 15 menit.</small>
    </div>

    <!-- ONT Grid Cards Container (3 Columns Desktop) -->
    <div class="row g-3" id="ontGridContainer">
        <?php if (empty($devices)): ?>
        <div class="col-12 text-center text-muted py-5">
            <i class="bi bi-modem text-secondary" style="font-size: 3rem;"></i>
            <h6 class="mt-3">Tidak ada perangkat ONT yang terdeteksi pada server GenieACS ini.</h6>
        </div>
        <?php else: ?>
        <?php foreach ($devices as $d): 
            $sn_upper = strtoupper(trim($d['sn']));
            $cust = $cust_map[$sn_upper] ?? null;
            $displayName = $cust ? $cust['name'] : ($d['ssids'][0] ?? ($d['tags'][0] ?? $d['sn']));
            $subTitle    = $cust ? ($cust['username'] ? $cust['username'] : '-') : ($d['tags'][0] ?? '-');
            $activeRemote = $activeRemotes[$d['sn']] ?? null;

            // Redaman Optical RX Color
            $rxVal = (float)$d['rx'];
            $rxBadgeClass = 'text-success';
            if ($rxVal !== 0.0 && $rxVal < -27) {
                $rxBadgeClass = 'text-danger fw-bold';
            } elseif ($rxVal !== 0.0 && $rxVal < -25) {
                $rxBadgeClass = 'text-warning fw-bold';
            }
        ?>
        <div class="col-12 col-md-6 col-lg-4 ont-card-wrapper" 
             data-sn="<?= strtolower($d['sn']) ?>" 
             data-name="<?= strtolower($displayName) ?>" 
             data-sub="<?= strtolower($subTitle) ?>" 
             data-ip="<?= strtolower($d['ip']) ?>" 
             data-ssids="<?= strtolower(implode(' ', $d['ssids'])) ?>">
            
            <div class="card border-0 shadow-sm h-100 rounded-3" style="transition: transform .15s ease, box-shadow .15s ease;">
                <div class="card-body p-3 d-flex flex-column justify-content-between">
                    
                    <!-- Header Card: Status Dot, Name & Online/Offline -->
                    <div>
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div class="d-flex align-items-center gap-2">
                                <!-- Status Icon Dot -->
                                <div style="width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: <?= $d['is_online'] ? '#e8f5e9' : '#fff3e0' ?>; border: 2px solid <?= $d['is_online'] ? '#4caf50' : '#ff9800' ?>;">
                                    <div style="width: 14px; height: 14px; border-radius: 50%; background: <?= $d['is_online'] ? '#4caf50' : '#ff9800' ?>;"></div>
                                </div>
                                <div>
                                    <h6 class="fw-bold mb-0 text-dark text-truncate" style="max-width: 170px;" title="<?= htmlspecialchars($displayName) ?>">
                                        <?= htmlspecialchars($displayName) ?>
                                    </h6>
                                    <small class="text-muted text-truncate d-block" style="font-size: .75rem; max-width: 170px;" title="<?= htmlspecialchars($subTitle) ?>">
                                        <?= htmlspecialchars($subTitle) ?>
                                    </small>
                                </div>
                            </div>

                            <div>
                                <?php if ($d['is_online']): ?>
                                <span class="text-success small fw-bold d-flex align-items-center gap-1" style="font-size: .75rem;">
                                    <span style="width:7px; height:7px; border-radius:50%; background:#28a745; display:inline-block;"></span> Online
                                </span>
                                <?php else: ?>
                                <span class="text-secondary small d-flex align-items-center gap-1" style="font-size: .75rem;">
                                    <span style="width:7px; height:7px; border-radius:50%; background:#adb5bd; display:inline-block;"></span> Offline
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Brand & Serial Number -->
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-1 mb-2 pt-1 border-top">
                            <span class="small text-muted text-truncate" style="max-width: 140px; font-size: .75rem;" title="<?= htmlspecialchars($d['manufacturer'] . ' ' . $d['model']) ?>">
                                <i class="bi bi-hdd-network me-1"></i><?= htmlspecialchars($d['brand'] . ' ' . $d['model']) ?>
                            </span>
                            <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 font-monospace px-2 py-1" style="font-size: .72rem;">
                                <?= htmlspecialchars($d['sn']) ?>
                            </span>
                            <span class="small text-muted" style="font-size: .72rem;">
                                <i class="bi bi-clock me-1"></i><?= $d['last_seen'] ?>
                            </span>
                        </div>

                        <!-- Tags / SSIDs Pills -->
                        <?php if (!empty($d['ssids']) || !empty($d['tags'])): ?>
                        <div class="d-flex flex-wrap gap-1 mb-2">
                            <?php foreach ($d['ssids'] as $idx => $sName): ?>
                            <span class="badge bg-light text-dark border px-2 py-1" style="font-size: .7rem; font-weight: 500;">
                                <i class="bi bi-wifi me-1 text-primary"></i><?= htmlspecialchars($sName) ?>
                            </span>
                            <?php endforeach; ?>
                            <?php foreach ($d['tags'] as $t): ?>
                            <span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25 px-2 py-1" style="font-size: .7rem;">
                                #<?= htmlspecialchars($t) ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <!-- Redaman Optik & IP -->
                        <div class="d-flex justify-content-between align-items-center small text-muted mb-3 bg-light p-2 rounded font-monospace" style="font-size: .75rem;">
                            <div>
                                <i class="bi bi-globe me-1"></i>IP: <span class="fw-bold text-dark"><?= htmlspecialchars($d['ip']) ?></span>
                            </div>
                            <div>
                                RX: <span class="<?= $rxBadgeClass ?>"><?= htmlspecialchars($d['rx']) ?> dBm</span>
                            </div>
                        </div>
                    </div>

                    <!-- Card Actions: Detail & Config + 15-Minute Remote -->
                    <div class="d-flex gap-2 pt-2 border-top">
                        <!-- Detail & Config Button (Ke Halaman Detail Lengkap) -->
                        <a href="/index.php?page=ont_detail&id=<?= urlencode($d['_id']) ?>&genie_id=<?= $selServerId ?>" class="btn btn-outline-primary btn-sm flex-fill fw-semibold text-decoration-none text-center" style="font-size: .78rem;">
                            <i class="bi bi-search me-1"></i> Detail &amp; Config
                        </a>

                        <!-- Remote Access 15 Min Button (Orange jika baru / Hijau jika aktif) -->
                        <?php if ($activeRemote): ?>
                        <button type="button" id="remoteBtn_<?= htmlspecialchars($d['sn']) ?>" class="btn btn-success btn-sm flex-fill fw-bold font-monospace remote-active-btn" style="font-size: .78rem;" data-sn="<?= htmlspecialchars($d['sn']) ?>" data-rem="<?= $activeRemote['rem_sec'] ?>" data-url="<?= htmlspecialchars($activeRemote['url']) ?>" onclick="handleActiveRemoteClick('<?= htmlspecialchars($d['sn']) ?>', '<?= htmlspecialchars($activeRemote['url']) ?>')">
                            <span class="spinner-grow spinner-grow-sm me-1" style="width: 8px; height: 8px;"></span> <span class="rem-btn-timer"><?= sprintf('%02d:%02d', floor($activeRemote['rem_sec']/60), $activeRemote['rem_sec']%60) ?></span>
                        </button>
                        <?php else: ?>
                        <button type="button" id="remoteBtn_<?= htmlspecialchars($d['sn']) ?>" class="btn btn-warning text-white btn-sm flex-fill fw-bold" style="background:#f05023; border-color:#f05023; font-size: .78rem;" onclick="startOntRemote('<?= htmlspecialchars($d['sn']) ?>', '<?= htmlspecialchars($d['ip']) ?>', '<?= htmlspecialchars(addslashes($displayName)) ?>')">
                            <i class="bi bi-plug-fill me-1"></i> Remote
                        </button>
                        <?php endif; ?>
                    </div>

                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

<?php endif; ?>

<!-- ====================================================================== -->
<!-- MODAL: DETAIL & CONFIG ONT (Wi-Fi, Redaman, Reboot)                    -->
<!-- ====================================================================== -->
<div class="modal fade" id="ontDetailModal" tabindex="-1" aria-labelledby="ontDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header py-3">
                <h5 class="modal-title fw-bold" id="ontDetailModalLabel">
                    <i class="bi bi-modem text-primary me-2"></i><span id="modalOntTitle">Detail ONT</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                
                <!-- Loading State -->
                <div id="ontModalLoading" class="text-center py-5">
                    <div class="spinner-border text-primary me-2"></div>
                    <div class="small text-muted mt-2">Mengambil data dari GenieACS...</div>
                </div>

                <!-- Content Body -->
                <div id="ontModalBody" style="display:none;">
                    
                    <!-- Nav Tabs -->
                    <ul class="nav nav-pills mb-3" id="ontTab" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="tab-info-btn" data-bs-toggle="pill" data-bs-target="#tab-info" type="button" role="tab">
                                <i class="bi bi-info-circle me-1"></i> Info &amp; Redaman
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="tab-wifi-btn" data-bs-toggle="pill" data-bs-target="#tab-wifi" type="button" role="tab">
                                <i class="bi bi-wifi me-1"></i> Konfigurasi Wi-Fi
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="tab-action-btn" data-bs-toggle="pill" data-bs-target="#tab-action" type="button" role="tab">
                                <i class="bi bi-gear-wide-connected me-1"></i> Perintah ONT
                            </button>
                        </li>
                    </ul>

                    <div class="tab-content" id="ontTabContent">
                        <!-- TAB 1: INFO & REDAMAN -->
                        <div class="tab-pane fade show active" id="tab-info" role="tabpanel">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="p-3 bg-light rounded">
                                        <h6 class="fw-bold mb-3"><i class="bi bi-broadcast text-danger me-2"></i>Kekuatan Sinyal Optik</h6>
                                        <div class="d-flex justify-content-between mb-2">
                                            <span class="text-muted">Redaman RX:</span>
                                            <strong class="font-monospace text-primary fs-6" id="m_rx">-</strong>
                                        </div>
                                        <div class="d-flex justify-content-between mb-2">
                                            <span class="text-muted">Pancaran TX:</span>
                                            <strong class="font-monospace text-dark" id="m_tx">-</strong>
                                        </div>
                                        <div class="d-flex justify-content-between mb-2">
                                            <span class="text-muted">Tegangan (Volt):</span>
                                            <span class="font-monospace" id="m_volt">-</span>
                                        </div>
                                        <div class="d-flex justify-content-between">
                                            <span class="text-muted">Suhu Perangkat:</span>
                                            <span class="font-monospace" id="m_temp">-</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="p-3 bg-light rounded">
                                        <h6 class="fw-bold mb-3"><i class="bi bi-card-heading text-primary me-2"></i>Informasi Hardware</h6>
                                        <div class="d-flex justify-content-between mb-2">
                                            <span class="text-muted">Serial Number:</span>
                                            <strong class="font-monospace" id="m_sn">-</strong>
                                        </div>
                                        <div class="d-flex justify-content-between mb-2">
                                            <span class="text-muted">Merek &amp; Model:</span>
                                            <span id="m_model">-</span>
                                        </div>
                                        <div class="d-flex justify-content-between mb-2">
                                            <span class="text-muted">IP WAN:</span>
                                            <span class="font-monospace fw-bold" id="m_ip">-</span>
                                        </div>
                                        <div class="d-flex justify-content-between">
                                            <span class="text-muted">Versi Software:</span>
                                            <span class="font-monospace small" id="m_sw">-</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- TAB 2: KONFIGURASI WI-FI -->
                        <div class="tab-pane fade" id="tab-wifi" role="tabpanel">
                            <form id="wifiConfigForm" onsubmit="saveWifiConfig(event)">
                                <input type="hidden" name="server_id" value="<?= $selServerId ?>">
                                <input type="hidden" name="dev_id" id="wifi_dev_id" value="">
                                <input type="hidden" name="action" value="set_wifi">
                                <input type="hidden" name="csrf" value="<?= $_SESSION['csrf_token'] ?? '' ?>">

                                <div class="row g-3 mb-3">
                                    <div class="col-md-6">
                                        <div class="p-3 border rounded">
                                            <h6 class="fw-bold mb-3 text-primary"><i class="bi bi-wifi me-1"></i> Wi-Fi 2.4 GHz</h6>
                                            <div class="mb-3">
                                                <label class="form-label small fw-semibold">Nama SSID 2.4G</label>
                                                <input type="text" name="ssid_24" id="wifi_ssid_24" class="form-control form-control-sm" required>
                                            </div>
                                            <div class="mb-2">
                                                <label class="form-label small fw-semibold">Password Wi-Fi 2.4G</label>
                                                <input type="text" name="pass_24" id="wifi_pass_24" class="form-control form-control-sm font-monospace" minlength="8" placeholder="Minimal 8 karakter" required>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <div class="p-3 border rounded">
                                            <h6 class="fw-bold mb-3 text-success"><i class="bi bi-wifi me-1"></i> Wi-Fi 5 GHz (Jika Didukung)</h6>
                                            <div class="mb-3">
                                                <label class="form-label small fw-semibold">Nama SSID 5G</label>
                                                <input type="text" name="ssid_5g" id="wifi_ssid_5g" class="form-control form-control-sm">
                                            </div>
                                            <div class="mb-2">
                                                <label class="form-label small fw-semibold">Password Wi-Fi 5G</label>
                                                <input type="text" name="pass_5g" id="wifi_pass_5g" class="form-control form-control-sm font-monospace" minlength="8" placeholder="Minimal 8 karakter">
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="d-flex justify-content-end">
                                    <button type="submit" class="btn btn-primary btn-sm px-4" id="wifiSaveBtn">
                                        <i class="bi bi-save me-1"></i> Simpan &amp; Terapkan ke ONT
                                    </button>
                                </div>
                            </form>
                        </div>

                        <!-- TAB 3: PERINTAH ONT -->
                        <div class="tab-pane fade" id="tab-action" role="tabpanel">
                            <div class="p-3 bg-light rounded text-center">
                                <h6 class="fw-bold mb-3 text-dark">Perintah Remote Jarak Jauh (TR-069)</h6>
                                <div class="d-flex justify-content-center gap-3">
                                    <button type="button" class="btn btn-outline-primary btn-sm px-3" onclick="triggerTask('refresh')">
                                        <i class="bi bi-arrow-clockwise me-1"></i> Refresh Parameter ONT
                                    </button>
                                    <button type="button" class="btn btn-outline-danger btn-sm px-3" onclick="triggerTask('reboot')">
                                        <i class="bi bi-power me-1"></i> Reboot Perangkat ONT
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>

            </div>
        </div>
    </div>
</div>

<!-- ====================================================================== -->
<!-- MODAL: AKSES REMOTE 15 MENIT COUNTDOWN                                 -->
<!-- ====================================================================== -->
<div class="modal fade" id="remoteModal" tabindex="-1" aria-labelledby="remoteModalLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content text-center p-4">
            <div class="modal-body p-0">
                <div class="mb-3">
                    <span class="badge bg-warning bg-opacity-25 text-warning p-3 rounded-circle" style="font-size: 2rem;">
                        🔌
                    </span>
                </div>
                <h5 class="fw-bold mb-1" id="remoteOntName">Akses Remote ONT Aktif</h5>
                <p class="small text-muted mb-3" id="remoteOntIp">IP: 10.55.55.X &middot; Port: 8081</p>

                <!-- Live Countdown Clock Box -->
                <div class="p-3 bg-light rounded border mb-3">
                    <small class="text-muted d-block mb-1">SISA WAKTU AKSES OTOMATIS:</small>
                    <div class="fs-1 fw-bold font-monospace text-danger" id="remoteTimerText">15:00</div>
                    <small class="text-muted">Akses remote akan tertutup dan dihapus dari firewall setelah waktu habis.</small>
                </div>

                <div class="d-grid gap-2 mb-2">
                    <a href="#" id="openRemoteTabBtn" target="_blank" class="btn btn-primary fw-bold py-2">
                        <i class="bi bi-box-arrow-up-right me-1"></i> Buka Web GUI ONT (Tab Baru)
                    </a>
                    <button type="button" class="btn btn-outline-danger py-2" onclick="closeRemoteManual()">
                        <i class="bi bi-x-circle me-1"></i> Tutup Akses Remote Sekarang
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// ── LIVE FILTER SEARCH ───────────────────────────────────────────────
const searchInput = document.getElementById('ontSearchInput');
if (searchInput) {
    searchInput.addEventListener('input', function(e) {
        let q = e.target.value.toLowerCase().trim();
        document.querySelectorAll('.ont-card-wrapper').forEach(card => {
            let sn = card.dataset.sn || '';
            let name = card.dataset.name || '';
            let sub = card.dataset.sub || '';
            let ip = card.dataset.ip || '';
            let ssids = card.dataset.ssids || '';

            if (sn.includes(q) || name.includes(q) || sub.includes(q) || ip.includes(q) || ssids.includes(q)) {
                card.style.display = '';
            } else {
                card.style.display = 'none';
            }
        });
    });
}

// ── REMOTE 15 MENIT HANDLER ──────────────────────────────────────────
let activeRemoteSN = '';
let cardTimerInterval = null;

// Jalankan timer untuk seluruh tombol remote yang sedang aktif saat halaman dibuka
function initActiveCardTimers() {
    document.querySelectorAll('.remote-active-btn').forEach(btn => {
        let rem = parseInt(btn.dataset.rem || '0');
        let timerSpan = btn.querySelector('.rem-btn-timer');
        if (rem > 0 && timerSpan) {
            setInterval(() => {
                rem--;
                if (rem <= 0) {
                    btn.classList.remove('btn-success');
                    btn.classList.add('btn-secondary');
                    btn.innerHTML = 'Kadaluarsa';
                    btn.disabled = true;
                } else {
                    let m = Math.floor(rem / 60);
                    let s = rem % 60;
                    timerSpan.innerText = (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
                }
            }, 1000);
        }
    });
}
initActiveCardTimers();

async function startOntRemote(sn, ip, name) {
    activeRemoteSN = sn;
    const btn = document.getElementById('remoteBtn_' + sn);
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    }

    try {
        const fd = new URLSearchParams();
        fd.append('action', 'start');
        fd.append('sn', sn);
        fd.append('ip', ip);
        fd.append('name', name);

        const req = await fetch('/ajax/ont_remote.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: fd.toString()
        });
        const res = await req.json();

        if (res.success) {
            // 1. Langsung buka tab baru ke Web GUI ONT!
            window.open(res.remote_url, '_blank');

            // 2. Ubah tombol pada kartu ONT menjadi hijau dengan countdown live
            if (btn) {
                btn.disabled = false;
                btn.className = 'btn btn-success btn-sm flex-fill fw-bold font-monospace remote-active-btn';
                btn.style.background = '#28a745';
                btn.style.borderColor = '#28a745';
                btn.innerHTML = '<span class="spinner-grow spinner-grow-sm me-1" style="width: 8px; height: 8px;"></span> <span class="rem-btn-timer">15:00</span>';
                btn.onclick = () => handleActiveRemoteClick(sn, res.remote_url);

                // Jalankan hitung mundur tombol
                let rem = res.remaining_seconds;
                let tSpan = btn.querySelector('.rem-btn-timer');
                let itv = setInterval(() => {
                    rem--;
                    if (rem <= 0) {
                        clearInterval(itv);
                        btn.className = 'btn btn-secondary btn-sm flex-fill';
                        btn.innerHTML = 'Kadaluarsa';
                    } else if (tSpan) {
                        let m = Math.floor(rem / 60);
                        let s = rem % 60;
                        tSpan.innerText = (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
                    }
                }, 1000);
            }

            // Tampilkan alert banner aktif di atas jika ada
            const banner = document.getElementById('activeRemotesBanner');
            if (banner) banner.style.display = 'flex';

        } else {
            alert('Gagal membuka akses remote: ' + res.error);
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-plug-fill me-1"></i> Remote';
            }
        }
    } catch (e) {
        alert('Terjadi kesalahan: ' + e.message);
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-plug-fill me-1"></i> Remote';
        }
    }
}

function handleActiveRemoteClick(sn, url) {
    if (confirm("Sesi Remote ONT sedang AKTIF!\n\n• Klik OK untuk MEMBUKA ULANG Web GUI ONT di Tab Baru.\n• Klik BATAL/CANCEL jika ingin MENUTUP akses remote sekarang.")) {
        window.open(url, '_blank');
    } else {
        closeRemoteManual(sn);
    }
}

async function closeRemoteManual(sn) {
    const targetSn = sn || activeRemoteSN;
    if (!targetSn) return;
    if (!confirm('Tutup dan hapus akses remote port forward ONT ini sekarang?')) return;

    try {
        const fd = new URLSearchParams();
        fd.append('action', 'close');
        fd.append('sn', targetSn);

        await fetch('/ajax/ont_remote.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: fd.toString()
        });

        window.location.reload();
    } catch (e) {
        alert('Gagal menutup remote: ' + e.message);
    }
}

// ── DETAIL & CONFIG MODAL HANDLER ────────────────────────────────────
let currentDevId = '';

async function openOntDetail(devId, name, sn) {
    currentDevId = devId;
    document.getElementById('modalOntTitle').innerText = name + ' (' + sn + ')';
    document.getElementById('wifi_dev_id').value = devId;

    document.getElementById('ontModalLoading').style.display = 'block';
    document.getElementById('ontModalBody').style.display = 'none';

    const modal = new bootstrap.Modal(document.getElementById('ontDetailModal'));
    modal.show();

    try {
        const req = await fetch('/ajax/ont_detail.php?action=get_detail&server_id=<?= $selServerId ?>&dev_id=' + encodeURIComponent(devId));
        const res = await req.json();

        if (res.success) {
            document.getElementById('ontModalLoading').style.display = 'none';
            document.getElementById('ontModalBody').style.display = 'block';

            // Optical info
            document.getElementById('m_rx').innerText = (res.optical.rx || '-') + ' dBm';
            document.getElementById('m_tx').innerText = (res.optical.tx || '-') + ' dBm';
            document.getElementById('m_volt').innerText = (res.optical.voltage || '-') + ' V';
            document.getElementById('m_temp').innerText = (res.optical.temperature || '-') + ' °C';

            // Hardware info
            document.getElementById('m_sn').innerText = res.info.serial || sn;
            document.getElementById('m_model').innerText = (res.brand || '') + ' ' + (res.info.product || '');
            document.getElementById('m_ip').innerText = res.info.ip_wan || '-';
            document.getElementById('m_sw').innerText = res.info.sw_version || '-';

            // Wi-Fi inputs
            document.getElementById('wifi_ssid_24').value = res.wifi.ssid_24 || '';
            document.getElementById('wifi_pass_24').value = res.wifi.pass_24 || '';
            document.getElementById('wifi_ssid_5g').value = res.wifi.ssid_5g || '';
            document.getElementById('wifi_pass_5g').value = res.wifi.pass_5g || '';
        } else {
            alert('Gagal mengambil data ONT: ' + res.error);
        }
    } catch (e) {
        alert('Terjadi kesalahan: ' + e.message);
    }
}

async function saveWifiConfig(e) {
    e.preventDefault();
    const btn = document.getElementById('wifiSaveBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Menyimpan ke ONT...';

    try {
        const fd = new FormData(document.getElementById('wifiConfigForm'));
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
        btn.innerHTML = '<i class="bi bi-save me-1"></i> Simpan & Terapkan ke ONT';
    }
}

async function triggerTask(action) {
    if (!currentDevId) return;
    if (!confirm('Kirim perintah ' + action.toUpperCase() + ' ke perangkat ONT ini?')) return;

    try {
        const fd = new URLSearchParams();
        fd.append('server_id', '<?= $selServerId ?>');
        fd.append('dev_id', currentDevId);
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
</script>

<?php include __DIR__ . '/../../include/footer.php'; ?>
