<?php
/**
 * PPPoE Customers — Add / Edit with Zero-Touch Auto-Provisioning GenieACS
 */
$is_edit = ($page === 'pppoe_edit');
$page_title = $is_edit ? 'Edit Pelanggan PPPoE' : 'Tambah Pelanggan PPPoE';

$routers = get_all_routers();
$selRid = (int)get('router_id');
$id = (int)get('id');

if (!$selRid && !empty($routers)) {
    $selRid = $routers[0]['id'];
}

// Load active GenieACS servers
$genie_servers = [];
try {
    $genie_servers = db_fetch_all("SELECT * FROM genie_config WHERE is_active = 1 ORDER BY id ASC");
} catch (Exception $e) {}

// Load dynamic provisioning template settings
$raw_settings = db_fetch_all("SELECT setting_key, setting_value FROM pppoe_settings");
$pppoe_settings = [];
foreach ($raw_settings as $s) {
    $pppoe_settings[$s['setting_key']] = $s['setting_value'];
}
$tplUserSuffix   = $pppoe_settings['ont_username_suffix'] ?? '@snet';
$tplWifiPrefix   = $pppoe_settings['ont_wifi1_prefix'] ?? 'S.NET - ';
$tplWifiSuffix   = $pppoe_settings['ont_wifi2_suffix'] ?? ' 5G';
$tplWanSlotFh    = (int)($pppoe_settings['ont_default_wan_fh'] ?? 2);
$tplWanSlotOther = (int)($pppoe_settings['ont_default_wan_other'] ?? 1);
$tplDefaultVlan  = (int)($pppoe_settings['ont_default_vlan'] ?? 100);
$tplEnableHotspot= !empty($pppoe_settings['ont_enable_hotspot']);
$tplHotspotVlan  = (int)($pppoe_settings['ont_hotspot_vlan'] ?? 100);
$tplHotspotSsid2 = $pppoe_settings['ont_hotspot_ssid2'] ?? 'S.NET @Hotspot';
$tplHotspotSsid6 = $pppoe_settings['ont_hotspot_ssid6'] ?? 'S.NET @Hotspot 5G';

$customer = [
    'id' => '',
    'pppoe_username' => '',
    'full_name' => '',
    'phone' => '',
    'address' => '',
    'profile' => '',
    'monthly_price' => '',
    'due_day' => '1',
    'status' => 'active',
    'portal_username' => '',
    'notes' => '',
    'ont_sn' => '',
    'ont_vlan' => $tplDefaultVlan,
    'ont_wifi_ssid' => '',
    'ont_wifi_pass' => '',
    'is_free' => 0
];

$mikrotik_password = '';
if (!$is_edit) {
    // Generate random 5-digit password for new customer
    $mikrotik_password = (string)rand(10000, 99999);
}

if ($is_edit) {
    $c = db_fetch_one("SELECT * FROM pppoe_customers WHERE id = ? AND router_id = ?", 'ii', [$id, $selRid]);
    if (!$c) {
        flash_set('error', 'Pelanggan tidak ditemukan.');
        header("Location: /index.php?page=pppoe_customers&router_id=$selRid");
        exit;
    }
    $customer = $c;
}

// Router info
$selRouter = null;
$router_map = [];
foreach ($routers as $r) {
    $router_map[$r['id']] = [
        'id' => $r['id'],
        'name' => $r['name'],
        'genie_server_id' => $r['genie_server_id'] ?? ($genie_servers[0]['id'] ?? 0),
        'default_vlan' => $r['default_vlan'] ?? $tplDefaultVlan
    ];
    if ($r['id'] == $selRid) { $selRouter = $r; }
}

$profiles = [];
// Ambil profil yang ada di database terlebih dahulu (instan)
try {
    $dbProfs = db_fetch_all("SELECT DISTINCT profile FROM pppoe_customers WHERE profile != '' ORDER BY profile ASC");
    foreach ($dbProfs as $dp) {
        if (!empty($dp['profile']) && !in_array($dp['profile'], $profiles)) {
            $profiles[] = $dp['profile'];
        }
    }
} catch (Exception $e) {}

if ($selRouter) {
    try {
        require_once __DIR__ . '/../../../lib/routeros_api.class.php';
        $api = new RouterosAPI();
        $api->debug = false;
        $api->timeout = 1.5;
        $api->attempts = 1;
        $api->delay = 0;
        if ($api->connect($selRouter['ip_address'], $selRouter['api_user'], $selRouter['api_password'], (int)$selRouter['api_port'])) {
            $profs = $api->comm('/ppp/profile/print', ['.proplist' => 'name']);
            foreach ($profs as $p) {
                if (isset($p['name']) && !in_array($p['name'], $profiles)) {
                    $profiles[] = $p['name'];
                }
            }
            
            if ($is_edit && !empty($customer['pppoe_username'])) {
                $secs = $api->comm('/ppp/secret/print', [
                    '?name' => $customer['pppoe_username'],
                    '.proplist' => 'name,password'
                ]);
                if (!empty($secs)) {
                    $mikrotik_password = $secs[0]['password'] ?? '';
                }
            }
            $api->disconnect();
        }
    } catch (Exception $e) {}
}

$defaultAcsId = $selRouter['genie_server_id'] ?? ($genie_servers[0]['id'] ?? 0);
$defaultVlan  = $selRouter['default_vlan'] ?? $tplDefaultVlan;

include __DIR__ . '/../../../include/header.php';
?>
<div class="page-header">
    <div>
        <h1 class="page-title"><?= $page_title ?></h1>
        <nav aria-label="breadcrumb"><ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="/index.php?page=pppoe_customers&router_id=<?= $selRid ?>">Pelanggan PPPoE</a></li>
            <li class="breadcrumb-item active"><?= $page_title ?></li>
        </ol></nav>
    </div>
</div>

<div class="row justify-content-center">
<div class="col-12 col-xl-10">
    <form method="POST" action="/process/save_pppoe_customer.php" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <!-- Anti autofill fake inputs -->
        <input type="text" style="display:none">
        <input type="password" style="display:none">
        
        <?php if ($is_edit): ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="old_username" value="<?= htmlspecialchars($customer['pppoe_username']) ?>">
        <?php endif; ?>

        <div class="row g-4">
            <!-- ── CARD 1: DATA UTAMA PELANGGAN ── -->
            <div class="col-12 col-lg-7">
                <div class="card h-100 shadow-sm border-0">
                    <div class="card-header bg-primary text-white py-3">
                        <h5 class="card-title mb-0 text-white"><i class="bi bi-person-fill me-2"></i>1. Data Pelanggan</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Cabang / Router MikroTik <span class="text-danger">*</span></label>
                            <select class="form-select" name="router_id" id="sel_router" required onchange="onRouterChange(this.value)">
                                <?php foreach ($routers as $r): ?>
                                <option value="<?= $r['id'] ?>" <?= $selRid == $r['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($r['name']) ?> (<?= htmlspecialchars($r['ip_address']) ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Nama Lengkap Pelanggan <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-lg fw-bold" name="full_name" id="inp_full_name" required
                                   value="<?= htmlspecialchars($customer['full_name']) ?>"
                                   placeholder="Contoh: Mushawir Odegoa"
                                   autocomplete="off"
                                   oninput="autoGenerateCredentials(this.value)">
                            <div class="form-text">Ketik nama pelanggan &rarr; sistem otomatis membuat username, password, dan nama Wi-Fi.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">No. Telepon / WhatsApp <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-whatsapp text-success"></i></span>
                                <input type="text" class="form-control" name="phone" id="inp_phone" required
                                       value="<?= htmlspecialchars($customer['phone']) ?>"
                                       placeholder="08123456789"
                                       autocomplete="off">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Alamat / Detail Pemasangan</label>
                            <textarea class="form-control" name="address" id="inp_address" rows="2" placeholder="Jl. Mawar No. 12, RT 01/RW 02"><?= htmlspecialchars($customer['address']) ?></textarea>
                        </div>

                        <div class="mb-0">
                            <label class="form-label fw-bold">Catatan Pemasangan / ODP</label>
                            <textarea class="form-control" name="notes" rows="2" placeholder="Port ODP, teknisi pemasangan, dll."><?= htmlspecialchars($customer['notes']) ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── CARD 2: PAKET & STATUS ── -->
            <div class="col-12 col-lg-5">
                <div class="card h-100 shadow-sm border-0">
                    <div class="card-header bg-dark text-white py-3">
                        <h5 class="card-title mb-0 text-white"><i class="bi bi-box-seam-fill me-2"></i>2. Paket Layanan</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Profil / Paket PPPoE <span class="text-danger">*</span></label>
                            <select class="form-select form-select-lg fw-bold text-primary" name="profile" id="sel_profile" required>
                                <option value="">-- Pilih Profil Paket --</option>
                                <?php foreach ($profiles as $p): ?>
                                <option value="<?= htmlspecialchars($p) ?>" <?= $customer['profile'] === $p ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($p) ?>
                                </option>
                                <?php endforeach; ?>
                                <?php if (!empty($customer['profile']) && !in_array($customer['profile'], $profiles)): ?>
                                <option value="<?= htmlspecialchars($customer['profile']) ?>" selected>
                                    <?= htmlspecialchars($customer['profile']) ?> (Aktual)
                                </option>
                                <?php endif; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Harga Bulanan (Rp) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control form-control-lg fw-bold text-success" name="monthly_price" id="inp_monthly_price" required min="0"
                                   value="<?= htmlspecialchars($customer['monthly_price']) ?>"
                                   placeholder="150000">
                        </div>

                        <div class="mb-3">
                            <div class="form-check form-switch p-2 bg-light border rounded">
                                <input class="form-check-input ms-0 me-2" type="checkbox" name="is_free" value="1" id="checkIsFree"
                                       <?= (!empty($customer['is_free']) || ($is_edit && (float)$customer['monthly_price'] == 0)) ? 'checked' : '' ?>
                                       onchange="toggleFreeCustomer(this.checked)">
                                <label class="form-check-label fw-bold text-success" for="checkIsFree">
                                    🎁 Pelanggan Gratis / Bebas Iuran
                                </label>
                            </div>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label fw-bold">Tgl Jatuh Tempo</label>
                                <input type="number" class="form-control" name="due_day" id="inp_due_day" required min="1" max="28"
                                       value="<?= htmlspecialchars($customer['due_day']) ?>">
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-bold">Status</label>
                                <select class="form-select" name="status">
                                    <option value="active" <?= $customer['status'] === 'active' ? 'selected' : '' ?>>🟢 Aktif</option>
                                    <option value="isolated" <?= $customer['status'] === 'isolated' ? 'selected' : '' ?>>🔴 Isolir</option>
                                    <option value="suspended" <?= $customer['status'] === 'suspended' ? 'selected' : '' ?>>⚪ Suspend</option>
                                </select>
                            </div>
                        </div>

                        <!-- Info Preview Otomatis -->
                        <div class="p-3 bg-light border rounded">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="text-muted small">Username Otomatis:</span>
                                <strong class="font-mono text-primary" id="preview_pppoe_user"><?= htmlspecialchars($customer['pppoe_username'] ?: 'nama' . $tplUserSuffix) ?></strong>
                            </div>
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-muted small">Password PPPoE:</span>
                                <strong class="font-mono text-dark" id="preview_pppoe_pass"><?= htmlspecialchars($mikrotik_password) ?></strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── CARD 3: ⚡ PERANGKAT ONT & WI-FI ── -->
            <div class="col-12">
                <div class="card shadow-sm border-0 border-top border-4 border-success">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2 py-3">
                        <div>
                            <h5 class="card-title text-success mb-0 fw-bold">
                                <i class="bi bi-router-fill me-2"></i>3. Pengaturan Modem ONT &amp; Wi-Fi Pelanggan
                            </h5>
                            <small class="text-muted">Pilih / Scan SN modem ONT untuk dikonfigurasi secara otomatis ke GenieACS</small>
                        </div>
                        <div class="form-check form-switch fs-6 mb-0">
                            <input class="form-check-input" type="checkbox" name="push_ont" value="1" id="checkPushOnt" checked>
                            <label class="form-check-label fw-bold text-success" for="checkPushOnt">
                                Push Setting Otomatis ke ONT
                            </label>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Serial Number (SN) ONT <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="text" class="form-control form-control-lg font-mono fw-bold text-success" name="ont_sn" id="inp_ont_sn"
                                           value="<?= htmlspecialchars($customer['ont_sn'] ?? '') ?>"
                                           placeholder="Contoh: FHTTC0FD080A atau ZTEGC1234567"
                                           autocomplete="off"
                                           oninput="detectBrandFromSn(this.value)">
                                    <button type="button" class="btn btn-success px-3" onclick="openOntPickerModal()" title="Pilih dari daftar ONT Online">
                                        <i class="bi bi-search me-1"></i> Pilih ONT Online
                                    </button>
                                </div>
                                <div class="form-text d-flex justify-content-between mt-1">
                                    <span>Brand terdeteksi: <strong id="lbl_detected_brand" class="text-primary">-</strong></span>
                                    <span id="lbl_ont_model" class="text-muted small"></span>
                                </div>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label fw-bold">Nama Wi-Fi 2.4 GHz (SSID 1)</label>
                                <input type="text" class="form-control" name="ont_wifi_ssid1" id="inp_wifi_ssid1"
                                       value="<?= htmlspecialchars($customer['ont_wifi_ssid'] ?? '') ?>"
                                       placeholder="S.NET - [Nama]"
                                       autocomplete="off">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label fw-bold">Password Wi-Fi (WPA2)</label>
                                <input type="text" class="form-control font-mono fw-bold" name="ont_wifi_pass" id="inp_wifi_pass"
                                       value="<?= htmlspecialchars($customer['ont_wifi_pass'] ?? $mikrotik_password) ?>"
                                       placeholder="Password Wi-Fi"
                                       autocomplete="off">
                                <div class="form-text">Otomatis sama dengan password PPPoE</div>
                            </div>

                            <!-- Dual-WAN Hotspot S.NET Option -->
                            <div class="col-12 mt-3">
                                <div class="p-3 bg-light border border-info rounded">
                                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                        <div>
                                            <strong class="text-primary"><i class="bi bi-wifi me-1"></i> Dual-WAN: Aktifkan Hotspot S.NET (Bridged ke SSID 2 &amp; 6)</strong>
                                            <div class="small text-muted">Membuat WAN ke-2 mode Bridge (NAT: false, Open Wi-Fi) untuk voucher hotspot publik di ONT pelanggan.</div>
                                        </div>
                                        <div class="form-check form-switch fs-5 mb-0">
                                            <input class="form-check-input" type="checkbox" name="enable_hotspot" value="1" id="checkEnableHotspot"
                                                   <?= $tplEnableHotspot ? 'checked' : '' ?> onchange="document.getElementById('hotspot_fields_box').classList.toggle('d-none', !this.checked)">
                                            <label class="form-check-label fw-bold text-primary" for="checkEnableHotspot">Aktifkan</label>
                                        </div>
                                    </div>
                                    <div id="hotspot_fields_box" class="row g-2 mt-2 <?= $tplEnableHotspot ? '' : 'd-none' ?>">
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold small">SSID 2 (Hotspot 2.4G)</label>
                                            <input type="text" class="form-control form-control-sm fw-bold" name="hotspot_ssid2" value="<?= htmlspecialchars($tplHotspotSsid2) ?>">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold small">SSID 6 (Hotspot 5G)</label>
                                            <input type="text" class="form-control form-control-sm fw-bold" name="hotspot_ssid6" value="<?= htmlspecialchars($tplHotspotSsid6) ?>">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold small">VLAN ID Hotspot</label>
                                            <input type="number" class="form-control form-control-sm font-mono text-success fw-bold" name="hotspot_vlan" value="<?= htmlspecialchars($tplHotspotVlan) ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Opsi Lanjutan / Hidden by default -->
                            <div class="col-12 mt-2">
                                <a class="text-decoration-none small text-muted d-inline-flex align-items-center" data-bs-toggle="collapse" href="#collapseAdvanced" role="button" aria-expanded="false">
                                    <i class="bi bi-sliders me-1"></i> ⚙️ Opsi Kredensial &amp; Parameter Lanjutan (Opsional)
                                </a>
                                <div class="collapse mt-3" id="collapseAdvanced">
                                    <div class="p-3 bg-light border rounded">
                                        <div class="row g-3">
                                            <div class="col-md-3">
                                                <label class="form-label fw-bold small">Username PPPoE Custom</label>
                                                <input type="text" class="form-control form-control-sm font-mono" name="pppoe_username" id="inp_pppoe_user"
                                                       value="<?= htmlspecialchars($customer['pppoe_username']) ?>"
                                                       autocomplete="new-password">
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label fw-bold small">Password PPPoE Custom</label>
                                                <div class="input-group input-group-sm">
                                                    <input type="text" class="form-control font-mono" name="pppoe_password" id="inp_pppoe_pass"
                                                           value="<?= htmlspecialchars($mikrotik_password) ?>"
                                                           autocomplete="new-password">
                                                    <button type="button" class="btn btn-outline-secondary" onclick="generateRandomPass()" title="Acak Password">🎲</button>
                                                </div>
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label fw-bold small">Slot WAN</label>
                                                <select class="form-select form-select-sm font-mono" name="ont_wan_slot" id="sel_wan_slot">
                                                    <option value="2" <?= $tplWanSlotFh == 2 ? 'selected' : '' ?>>Slot 2 (FiberHome)</option>
                                                    <option value="1" <?= $tplWanSlotOther == 1 ? 'selected' : '' ?>>Slot 1 (ZTE/Huawei)</option>
                                                    <option value="3">Slot 3</option>
                                                </select>
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label fw-bold small">VLAN ID</label>
                                                <input type="number" class="form-control form-control-sm font-mono" name="ont_vlan" id="inp_ont_vlan"
                                                       value="<?= htmlspecialchars($customer['ont_vlan'] ?? $defaultVlan) ?>">
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label fw-bold small">Server GenieACS</label>
                                                <select class="form-select form-select-sm" name="genie_server_id" id="sel_genie_server">
                                                    <?php foreach ($genie_servers as $gs): ?>
                                                    <option value="<?= $gs['id'] ?>" <?= $defaultAcsId == $gs['id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($gs['name']) ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label fw-bold small">Nama Wi-Fi 5 GHz (SSID 2)</label>
                                                <input type="text" class="form-control form-control-sm" name="ont_wifi_ssid2" id="inp_wifi_ssid2"
                                                       placeholder="S.NET - [Nama] 5G">
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label fw-bold small">Username Portal</label>
                                                <input type="text" class="form-control form-control-sm font-mono" name="portal_username" id="inp_portal_user"
                                                       value="<?= htmlspecialchars($customer['portal_username'] ?? '') ?>">
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label fw-bold small">Password Portal</label>
                                                <input type="text" class="form-control form-control-sm font-mono" name="portal_password" id="inp_portal_pass"
                                                       placeholder="(Opsional)">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-4 mb-5 d-flex gap-3">
            <button type="submit" class="btn btn-primary btn-lg px-5 py-3 shadow fs-5 fw-bold">
                <i class="bi bi-check-circle-fill me-2"></i><?= $is_edit ? 'Simpan Perubahan' : 'Tambah &amp; Push ke ONT' ?>
            </button>
            <a href="/index.php?page=pppoe_customers&router_id=<?= $selRid ?>" class="btn btn-outline-secondary btn-lg px-4 py-3">
                Batal
            </a>
        </div>
    </form>
</div>
</div>

<!-- Modal Pilih ONT dari GenieACS -->
<div class="modal fade" id="modalPickOnt" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-light">
                <h5 class="modal-title"><i class="bi bi-hdd-network text-success me-2"></i>Pilih ONT Online dari Server GenieACS</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="input-group mb-3">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" id="filter_ont_search" class="form-control" placeholder="Ketik Serial Number, Brand, atau Model untuk memfilter..." onkeyup="filterOntList(this.value)">
                </div>
                <div id="ont_loading_spinner" class="text-center py-4">
                    <div class="spinner-border text-primary" role="status"></div>
                    <div class="small text-muted mt-2">Menghubungi GenieACS untuk mengambil daftar ONT...</div>
                </div>
                <div id="ont_list_container" class="table-responsive d-none">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Serial Number (SN)</th>
                                <th>Brand / Model</th>
                                <th>Status Terpetakan</th>
                                <th class="text-end">Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="ont_table_body"></tbody>
                    </table>
                </div>
                <div id="ont_empty_msg" class="alert alert-warning d-none text-center py-3">
                    Tidak ada perangkat ONT yang terdeteksi di server GenieACS ini.
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const routerMap = <?= json_encode($router_map) ?>;
const tplSettings = {
    userSuffix: <?= json_encode($tplUserSuffix) ?>,
    wifiPrefix: <?= json_encode($tplWifiPrefix) ?>,
    wifiSuffix: <?= json_encode($tplWifiSuffix) ?>,
    wanSlotFh: <?= $tplWanSlotFh ?>,
    wanSlotOther: <?= $tplWanSlotOther ?>
};
let availableOntsData = [];

function cleanUsername(name) {
    return name.toLowerCase()
               .replace(/[^a-z0-9]/g, '')
               .slice(0, 20);
}

function generateRandomPass() {
    const pass = Math.floor(10000 + Math.random() * 90000); // 5 digit angka
    document.getElementById('inp_pppoe_pass').value = pass;
    document.getElementById('inp_portal_pass').value = pass;
    document.getElementById('inp_wifi_pass').value = pass;
    document.getElementById('preview_pppoe_pass').textContent = pass;
}

function autoGenerateCredentials(name) {
    const isEdit = <?= $is_edit ? 'true' : 'false' ?>;
    const clean = cleanUsername(name);
    
    if (!isEdit && clean) {
        const fullUser = clean + tplSettings.userSuffix;
        document.getElementById('inp_pppoe_user').value = fullUser;
        document.getElementById('inp_portal_user').value = clean;
        document.getElementById('preview_pppoe_user').textContent = fullUser;
    }
    
    if (name.trim()) {
        const firstName = name.trim().split(' ')[0];
        document.getElementById('inp_wifi_ssid1').value = tplSettings.wifiPrefix + firstName;
        document.getElementById('inp_wifi_ssid2').value = tplSettings.wifiPrefix + firstName + tplSettings.wifiSuffix;
    }
}

function onRouterChange(rid) {
    if (routerMap[rid]) {
        const r = routerMap[rid];
        if (r.genie_server_id) {
            document.getElementById('sel_genie_server').value = r.genie_server_id;
        }
        if (r.default_vlan) {
            document.getElementById('inp_ont_vlan').value = r.default_vlan;
        }
    }
}

function detectBrandFromSn(sn) {
    const snUpper = sn.toUpperCase();
    const brandLbl = document.getElementById('lbl_detected_brand');
    const wanSlotSel = document.getElementById('sel_wan_slot');

    if (snUpper.startsWith('FHTT') || snUpper.startsWith('FH') || snUpper.includes('FIBERHOME')) {
        brandLbl.textContent = 'FiberHome (Auto Slot ' + tplSettings.wanSlotFh + ')';
        brandLbl.className = 'badge bg-primary';
        wanSlotSel.value = String(tplSettings.wanSlotFh);
    } else if (snUpper.startsWith('ZTE') || snUpper.startsWith('ZTEG')) {
        brandLbl.textContent = 'ZTE (Auto Slot ' + tplSettings.wanSlotOther + ')';
        brandLbl.className = 'badge bg-info text-dark';
        wanSlotSel.value = String(tplSettings.wanSlotOther);
    } else if (snUpper.startsWith('HWTC') || snUpper.startsWith('HUAWEI') || snUpper.startsWith('485754')) {
        brandLbl.textContent = 'Huawei (Auto Slot ' + tplSettings.wanSlotOther + ')';
        brandLbl.className = 'badge bg-danger';
        wanSlotSel.value = String(tplSettings.wanSlotOther);
    } else if (snUpper.startsWith('CDTC') || snUpper.startsWith('CDATA')) {
        brandLbl.textContent = 'CData (Auto Slot ' + tplSettings.wanSlotOther + ')';
        brandLbl.className = 'badge bg-warning text-dark';
        wanSlotSel.value = String(tplSettings.wanSlotOther);
    } else if (snUpper) {
        brandLbl.textContent = 'Generic / Lainnya';
        brandLbl.className = 'badge bg-secondary';
    } else {
        brandLbl.textContent = '-';
        brandLbl.className = 'text-muted';
    }
}

function toggleFreeCustomer(isFree) {
    const priceInput = document.getElementById('inp_monthly_price');
    if (!priceInput) return;
    if (isFree) {
        if (priceInput.value != 0) {
            priceInput.dataset.oldPrice = priceInput.value;
        }
        priceInput.value = 0;
        priceInput.readOnly = true;
        priceInput.classList.add('bg-light');
    } else {
        priceInput.readOnly = false;
        priceInput.classList.remove('bg-light');
        if (priceInput.value == 0 && priceInput.dataset.oldPrice) {
            priceInput.value = priceInput.dataset.oldPrice;
        }
    }
}

// ── ONT PICKER MODAL LOGIC ──
let ontModalInstance = null;

function openOntPickerModal() {
    if (!ontModalInstance) {
        ontModalInstance = new bootstrap.Modal(document.getElementById('modalPickOnt'));
    }
    ontModalInstance.show();
    const serverId = document.getElementById('sel_genie_server').value;
    loadAvailableOnts(serverId);
}

async function loadAvailableOnts(serverId) {
    const spinner = document.getElementById('ont_loading_spinner');
    const container = document.getElementById('ont_list_container');
    const emptyMsg = document.getElementById('ont_empty_msg');
    const tbody = document.getElementById('ont_table_body');

    spinner.classList.remove('d-none');
    container.classList.add('d-none');
    emptyMsg.classList.add('d-none');
    tbody.innerHTML = '';

    try {
        const res = await fetch('/ajax/get_unassigned_onts.php?server_id=' + encodeURIComponent(serverId));
        const json = await res.json();
        spinner.classList.add('d-none');

        if (json.success && json.data && json.data.length > 0) {
            availableOntsData = json.data;
            renderOntTable(json.data);
            container.classList.remove('d-none');
        } else {
            emptyMsg.classList.remove('d-none');
        }
    } catch (e) {
        spinner.classList.add('d-none');
        emptyMsg.textContent = 'Gagal memuat daftar ONT dari GenieACS: ' + e.message;
        emptyMsg.classList.remove('d-none');
    }
}

function renderOntTable(list) {
    const tbody = document.getElementById('ont_table_body');
    tbody.innerHTML = '';

    list.forEach(ont => {
        const tr = document.createElement('tr');
        const badgeAssigned = ont.is_assigned 
            ? '<span class="badge bg-secondary">Sudah Terpetakan</span>' 
            : '<span class="badge bg-success">Tersedia (Ready)</span>';
        
        tr.innerHTML = `
            <td><strong class="font-mono text-primary fs-6">${ont.sn}</strong></td>
            <td>
                <span class="badge bg-light text-dark border">${ont.brand}</span>
                <small class="text-muted d-block">${ont.model}</small>
            </td>
            <td>${badgeAssigned}</td>
            <td class="text-end">
                <button type="button" class="btn btn-sm btn-primary" onclick="selectOntFromModal('${ont.sn}', '${ont.brand}', '${ont.model}', ${ont.suggested_slot})">
                    <i class="bi bi-check2-circle me-1"></i> Gunakan ONT Ini
                </button>
            </td>
        `;
        tbody.appendChild(tr);
    });
}

function filterOntList(keyword) {
    const kw = keyword.toLowerCase().trim();
    if (!kw) {
        renderOntTable(availableOntsData);
        return;
    }
    const filtered = availableOntsData.filter(o => 
        o.sn.toLowerCase().includes(kw) || 
        o.brand.toLowerCase().includes(kw) || 
        o.model.toLowerCase().includes(kw)
    );
    renderOntTable(filtered);
}

function selectOntFromModal(sn, brand, model, suggestedSlot) {
    document.getElementById('inp_ont_sn').value = sn;
    document.getElementById('lbl_detected_brand').textContent = brand;
    document.getElementById('lbl_detected_brand').className = (brand === 'FiberHome') ? 'badge bg-primary' : 'badge bg-info text-dark';
    document.getElementById('lbl_ont_model').textContent = model;
    document.getElementById('sel_wan_slot').value = String(suggestedSlot);

    if (ontModalInstance) {
        ontModalInstance.hide();
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const chk = document.getElementById('checkIsFree');
    if (chk && chk.checked) {
        toggleFreeCustomer(true);
    }
    const snInput = document.getElementById('inp_ont_sn');
    if (snInput && snInput.value) {
        detectBrandFromSn(snInput.value);
    }
    const nameInput = document.getElementById('inp_full_name');
    if (nameInput && nameInput.value) {
        autoGenerateCredentials(nameInput.value);
    }
});
</script>

<?php include __DIR__ . '/../../../include/footer.php'; ?>
