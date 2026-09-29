<?php
/**
 * Pelanggan Rumahan — List
 * Kriteria Pelanggan Rumahan:
 * 1. Sudah di-mapping ONT (ont_sn != '')
 * 2. Sudah di-mapping Secret PPPoE (pppoe_username != '')
 * 3. Sudah di-mapping Profil Paket (profile != '')
 * 4. TIDAK BEBAS IURAN (is_free = 0 dan monthly_price > 0)
 */
$page_title = 'Pelanggan Rumahan';
$routers = get_all_routers();
$selRid = (int)get('router_id', 0); // 0 = Semua Cabang

$selRouter = null;
if ($selRid > 0) {
    foreach ($routers as $r) {
        if ($r['id'] == $selRid) {
            $selRouter = $r;
            break;
        }
    }
}

$search = trim(get('q', ''));
$filter_status = get('status', '');

// Base criteria for Pelanggan Rumahan:
// ONT mapped, Secret PPPoE mapped, Profile mapped, NOT bebas iuran
$where_clauses = [
    "pc.ont_sn IS NOT NULL AND pc.ont_sn != '' AND pc.ont_sn != '0'",
    "pc.pppoe_username IS NOT NULL AND pc.pppoe_username != ''",
    "pc.profile IS NOT NULL AND pc.profile != ''",
    "(pc.is_free = 0 OR pc.is_free IS NULL)",
    "pc.monthly_price > 0"
];
$params = [];
$types = "";

if ($selRid > 0) {
    $where_clauses[] = "pc.router_id = ?";
    $params[] = $selRid;
    $types .= "i";
}

if ($search !== '') {
    $where_clauses[] = "(pc.pppoe_username LIKE ? OR pc.full_name LIKE ? OR pc.ont_sn LIKE ? OR pc.phone LIKE ? OR pc.address LIKE ?)";
    $kw = "%$search%";
    $params[] = $kw;
    $params[] = $kw;
    $params[] = $kw;
    $params[] = $kw;
    $params[] = $kw;
    $types .= "sssss";
}

if ($filter_status === 'active') {
    $where_clauses[] = "pc.status = 'active'";
} elseif ($filter_status === 'isolated') {
    $where_clauses[] = "pc.status = 'isolated'";
} elseif ($filter_status === 'suspended') {
    $where_clauses[] = "pc.status = 'suspended'";
}

$where_sql = "WHERE " . implode(" AND ", $where_clauses);

// Query Pelanggan Rumahan beserta data pembayaran bulan berjalan
$sql = "SELECT pc.*, 
               r.name AS router_name,
               COALESCE(pay.paid_this_month, 0) AS paid_this_month,
               COALESCE(pay.pending_this_month, 0) AS pending_this_month
        FROM pppoe_customers pc 
        JOIN routers r ON pc.router_id = r.id
        LEFT JOIN (
            SELECT customer_id,
                   SUM(CASE WHEN (midtrans_status = 'paid' OR payment_method = 'cash' OR (midtrans_status NOT IN ('pending','cancel','deny','expire') AND midtrans_status IS NOT NULL)) THEN amount ELSE 0 END) AS paid_this_month,
                   SUM(CASE WHEN midtrans_status = 'pending' THEN 1 ELSE 0 END) AS pending_this_month
            FROM pppoe_payments
            WHERE period_year = YEAR(NOW()) AND period_month = MONTH(NOW())
            GROUP BY customer_id
        ) pay ON pay.customer_id = pc.id
        $where_sql 
        ORDER BY pc.status ASC, pc.full_name ASC";

$customers = db_fetch_all($sql, $types, $params);

// Hitung statistik untuk badge & widget atas
$stat_total = count($customers);
$stat_mrr = 0;
$stat_lunas = 0;
$stat_lunas_nominal = 0;
$stat_nunggak = 0;
$stat_nunggak_nominal = 0;
$stat_isolir = 0;

$today = (int)date('j');
foreach ($customers as $c) {
    $price = (float)$c['monthly_price'];
    $stat_mrr += $price;
    $is_paid = (float)$c['paid_this_month'] > 0;
    
    if ($c['status'] === 'isolated') {
        $stat_isolir++;
    }
    
    if ($is_paid) {
        $stat_lunas++;
        $stat_lunas_nominal += (float)$c['paid_this_month'];
    } else {
        if ($c['due_day'] <= $today) {
            $stat_nunggak++;
            $stat_nunggak_nominal += $price;
        }
    }
}

// Cek sesi online MikroTik jika router tertentu dipilih
$active_sessions = [];
$api_error = '';

if ($selRouter) {
    try {
        require_once __DIR__ . '/../../../lib/routeros_api.class.php';
        $api = new RouterosAPI();
        $api->debug = false;
        $api->timeout = 1.5;
        $api->attempts = 1;
        $api->delay = 0;
        if ($api->connect($selRouter['ip_address'], $selRouter['api_user'], $selRouter['api_password'], (int)$selRouter['api_port'])) {
            $acts = $api->comm('/ppp/active/print', [
                '.proplist' => 'name,address,uptime'
            ]);
            foreach ($acts as $a) {
                if (isset($a['name'])) {
                    $active_sessions[$a['name']] = $a;
                }
            }
            $api->disconnect();
        } else {
            $api_error = 'Gagal terhubung ke MikroTik untuk cek status online.';
        }
    } catch (Exception $e) {
        $api_error = $e->getMessage();
    }
}

// Filter tambahan jika user memilih filter koneksi (online / offline)
if ($filter_status === 'online' && !empty($active_sessions)) {
    $customers = array_filter($customers, function($c) use ($active_sessions) {
        return isset($active_sessions[$c['pppoe_username']]);
    });
} elseif ($filter_status === 'offline' && $selRouter) {
    $customers = array_filter($customers, function($c) use ($active_sessions) {
        return !isset($active_sessions[$c['pppoe_username']]);
    });
}

// Ambil template WA
$wa_templates = [];
try {
    $wa_templates = db_fetch_all("SELECT * FROM wa_templates WHERE is_active = 1 ORDER BY id ASC");
} catch (Exception $e) {}

// Ambil setting PPPoE
$settings_raw = [];
try {
    $settings_raw = db_fetch_all("SELECT setting_key, setting_value FROM pppoe_settings");
} catch (Exception $e) {}
$pppoe_settings = [];
foreach ($settings_raw as $s) {
    $pppoe_settings[$s['setting_key']] = $s['setting_value'];
}
$company_name = $pppoe_settings['company_name'] ?? (defined('APP_COMPANY') ? APP_COMPANY : 'S.NET Internet');
$company_phone = $pppoe_settings['company_phone'] ?? '';

include __DIR__ . '/../../../include/header.php';
?>
<style>
.sts-active { background:#DCFCE7; color:#15803D; padding:3px 8px; border-radius:12px; font-size:12px; font-weight:600; display:inline-flex; align-items:center; gap:4px; }
.sts-isolated { background:#FEE2E2; color:#DC2626; padding:3px 8px; border-radius:12px; font-size:12px; font-weight:600; display:inline-flex; align-items:center; gap:4px; }
.sts-suspended { background:#FEF3C7; color:#D97706; padding:3px 8px; border-radius:12px; font-size:12px; font-weight:600; display:inline-flex; align-items:center; gap:4px; }
.online-dot { width:8px; height:8px; border-radius:50%; background:#22C55E; display:inline-block; box-shadow:0 0 0 3px rgba(34,197,94,.2); animation:dp 2s infinite; }
@keyframes dp { 0%{box-shadow:0 0 0 0 rgba(34,197,94,.4)} 70%{box-shadow:0 0 0 6px rgba(34,197,94,0)} 100%{box-shadow:0 0 0 0 rgba(34,197,94,0)} }
.rumahan-card { border-radius:10px; border:none; box-shadow:0 2px 6px rgba(0,0,0,0.06); transition:transform .15s; }
.rumahan-card:hover { transform: translateY(-2px); }
</style>

<div class="page-header">
    <div>
        <h1 class="page-title"><i class="bi bi-house-door text-primary me-2"></i>Pelanggan Rumahan</h1>
        <p class="page-subtitle">Pelanggan broadband rumahan aktif berbayar — telah ter-mapping ONT, PPPoE Secret, dan Paket Profil.</p>
    </div>
    <div class="d-flex gap-2">
        <?php if ($selRouter): ?>
        <form method="POST" action="/process/sync_pppoe_mikrotik.php" class="d-inline"
              onsubmit="return confirm('Tarik dan sinkronkan seluruh PPPoE Secrets dari router <?= htmlspecialchars(addslashes($selRouter['name'])) ?> ke Database?')">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="router_id" value="<?= $selRid ?>">
            <button type="submit" class="btn btn-outline-info" title="Tarik & Sinkronkan Secrets dari MikroTik">
                <i class="bi bi-arrow-repeat me-1"></i> Sync MikroTik
            </button>
        </form>
        <?php endif; ?>
        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalPayGlobal" <?= empty($customers) ? 'disabled' : '' ?>>
            <i class="bi bi-cash-stack me-1"></i> Bayar Kasir
        </button>
        <a href="/index.php?page=pppoe_add<?= $selRid ? '&router_id=' . $selRid : '' ?>" class="btn btn-primary">
            <i class="bi bi-person-plus me-1"></i> Tambah Pelanggan
        </a>
    </div>
</div>

<?php if ($api_error): ?>
<div class="alert alert-warning py-2 mb-3"><i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($api_error) ?></div>
<?php endif; ?>

<!-- Stat Widget Banner -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3">
        <div class="card rumahan-card bg-white p-3 h-100 border-start border-4 border-primary">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small fw-bold text-uppercase">Total Rumahan</div>
                    <div class="fs-4 fw-bold text-primary"><?= number_format($stat_total, 0, ',', '.') ?> <span class="fs-6 text-muted font-normal">Klien</span></div>
                </div>
                <div class="bg-primary-subtle text-primary p-3 rounded-circle"><i class="bi bi-house-door-fill fs-4"></i></div>
            </div>
            <div class="small text-muted mt-2">Potensi MRR: <strong><?= format_price($stat_mrr) ?></strong></div>
        </div>
    </div>
    
    <div class="col-sm-6 col-lg-3">
        <div class="card rumahan-card bg-white p-3 h-100 border-start border-4 border-success">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small fw-bold text-uppercase">Lunas Bulan Ini</div>
                    <div class="fs-4 fw-bold text-success"><?= number_format($stat_lunas, 0, ',', '.') ?> <span class="fs-6 text-muted font-normal">Klien</span></div>
                </div>
                <div class="bg-success-subtle text-success p-3 rounded-circle"><i class="bi bi-check-circle-fill fs-4"></i></div>
            </div>
            <div class="small text-muted mt-2">Terkumpul: <strong><?= format_price($stat_lunas_nominal) ?></strong></div>
        </div>
    </div>

    <div class="col-sm-6 col-lg-3">
        <div class="card rumahan-card bg-white p-3 h-100 border-start border-4 border-danger">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small fw-bold text-uppercase">Nunggak / Lewat JT</div>
                    <div class="fs-4 fw-bold text-danger"><?= number_format($stat_nunggak, 0, ',', '.') ?> <span class="fs-6 text-muted font-normal">Klien</span></div>
                </div>
                <div class="bg-danger-subtle text-danger p-3 rounded-circle"><i class="bi bi-exclamation-triangle-fill fs-4"></i></div>
            </div>
            <div class="small text-muted mt-2">Tunggakan: <strong><?= format_price($stat_nunggak_nominal) ?></strong></div>
        </div>
    </div>

    <div class="col-sm-6 col-lg-3">
        <div class="card rumahan-card bg-white p-3 h-100 border-start border-4 border-warning">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small fw-bold text-uppercase">Status Isolir</div>
                    <div class="fs-4 fw-bold text-warning"><?= number_format($stat_isolir, 0, ',', '.') ?> <span class="fs-6 text-muted font-normal">Terisolir</span></div>
                </div>
                <div class="bg-warning-subtle text-warning p-3 rounded-circle"><i class="bi bi-slash-circle-fill fs-4"></i></div>
            </div>
            <div class="small text-muted mt-2">
                <?= $selRouter ? 'Online: ' . count($active_sessions) . ' klien' : 'Pilih cabang untuk cek online' ?>
            </div>
        </div>
    </div>
</div>

<div class="card table-card">
    <div class="table-toolbar flex-wrap gap-2">
        <div class="d-flex align-items-center gap-3 flex-wrap">
            <span class="fw-600"><span class="badge bg-primary rounded-pill me-1"><?= count($customers) ?></span> Pelanggan Rumahan</span>
            
            <form method="GET" class="d-flex align-items-center gap-2 m-0 flex-wrap">
                <input type="hidden" name="page" value="pelanggan_rumahan">
                
                <select name="router_id" class="form-select form-select-sm" style="width:200px" onchange="this.form.submit()">
                    <option value="0">🌐 Semua Cabang / Router</option>
                    <?php foreach ($routers as $rt): ?>
                    <option value="<?= $rt['id'] ?>" <?= $selRid == $rt['id'] ? 'selected' : '' ?>>
                        📍 <?= htmlspecialchars($rt['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                
                <select name="status" class="form-select form-select-sm" style="width:170px" onchange="this.form.submit()">
                    <option value="">Semua Status</option>
                    <option value="active" <?= $filter_status === 'active' ? 'selected' : '' ?>>🟢 Status Aktif</option>
                    <option value="isolated" <?= $filter_status === 'isolated' ? 'selected' : '' ?>>🔴 Status Isolir</option>
                    <?php if ($selRouter): ?>
                    <option value="online" <?= $filter_status === 'online' ? 'selected' : '' ?>>📶 Sedang Online</option>
                    <option value="offline" <?= $filter_status === 'offline' ? 'selected' : '' ?>>📴 Offline</option>
                    <?php endif; ?>
                </select>
            </form>
        </div>
        
        <form method="GET" class="d-flex m-0">
            <input type="hidden" name="page" value="pelanggan_rumahan">
            <input type="hidden" name="router_id" value="<?= $selRid ?>">
            <input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>">
            <div class="input-group input-group-sm" style="width:260px">
                <input type="text" name="q" class="form-control" placeholder="Cari nama, user, ONT SN..." value="<?= htmlspecialchars($search) ?>">
                <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
                <?php if ($search !== '' || $filter_status !== '' || $selRid > 0): ?>
                <a href="/index.php?page=pelanggan_rumahan" class="btn btn-outline-danger" title="Reset Filter"><i class="bi bi-x-lg"></i></a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>Status</th>
                    <th>Username PPPoE</th>
                    <th>Nama & Kontak</th>
                    <th>Mapping ONT (Modem)</th>
                    <th>Paket Profil</th>
                    <th>Tagihan & JT</th>
                    <th>Sesi Online</th>
                    <th style="min-width: 140px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($customers)): ?>
            <tr>
                <td colspan="8" class="text-center text-muted py-5">
                    <i class="bi bi-house-x display-4 text-muted d-block mb-2"></i>
                    Belum ada data pelanggan rumahan yang cocok dengan filter.<br>
                    <small class="text-muted">Pelanggan rumahan harus memiliki: ONT SN terisi, PPPoE Username terisi, Paket Profil terisi, dan bukan Bebas Iuran.</small>
                </td>
            </tr>
            <?php else: ?>
            <?php foreach ($customers as $c): 
                $is_online = isset($active_sessions[$c['pppoe_username']]);
                $is_late = ($c['status'] === 'active' && $c['due_day'] <= $today && !(float)$c['paid_this_month']);
                $cleanPhone = preg_replace('/[^0-9]/', '', $c['phone']);
                if (str_starts_with($cleanPhone, '0')) {
                    $cleanPhone = '62' . substr($cleanPhone, 1);
                }
            ?>
            <tr <?= $c['status'] === 'isolated' ? 'style="background-color: var(--red-pale);"' : '' ?>>
                <td>
                    <?php if ($c['status'] === 'active' && $is_online): ?>
                        <span class="sts-active">🟢 Online</span>
                    <?php elseif ($c['status'] === 'active' && $is_late): ?>
                        <span class="sts-suspended">⚠️ Jatuh Tempo</span>
                    <?php elseif ($c['status'] === 'active'): ?>
                        <span class="sts-active">✅ Aktif</span>
                    <?php else: ?>
                        <span class="sts-isolated">🔴 Isolir</span>
                    <?php endif; ?>
                </td>
                
                <td>
                    <strong class="font-mono text-primary" style="font-size:13px;"><?= htmlspecialchars($c['pppoe_username']) ?></strong>
                    <div class="text-muted" style="font-size:11px;">
                        <i class="bi bi-router"></i> <?= htmlspecialchars($c['router_name']) ?>
                    </div>
                    <div class="mt-1 d-flex align-items-center gap-1">
                        <span class="badge bg-light text-secondary border font-mono" style="font-size:10px;" title="ID Login Portal Pelanggan">
                            <i class="bi bi-person-lock text-primary me-1"></i><?= htmlspecialchars($c['portal_username'] ?: $c['pppoe_username']) ?>
                        </span>
                        <button type="button" class="btn btn-outline-secondary py-0 px-1 btn-quick-portal"
                                style="font-size: 10px; line-height: 1.3;"
                                data-id="<?= $c['id'] ?>"
                                data-router="<?= $c['router_id'] ?>"
                                data-name="<?= htmlspecialchars($c['full_name']) ?>"
                                data-username="<?= htmlspecialchars($c['pppoe_username']) ?>"
                                data-portal-user="<?= htmlspecialchars($c['portal_username'] ?: $c['pppoe_username']) ?>"
                                data-phone="<?= htmlspecialchars($c['phone'] ?? '') ?>"
                                title="Atur ID & Password Portal">
                            <i class="bi bi-key-fill text-warning"></i>
                        </button>
                    </div>
                </td>
                
                <td>
                    <div class="fw-bold text-dark"><?= htmlspecialchars($c['full_name']) ?></div>
                    <?php if (!empty($c['phone'])): ?>
                    <a href="https://wa.me/<?= $cleanPhone ?>" target="_blank" class="text-success text-decoration-none small" style="font-size:11px;">
                        <i class="bi bi-whatsapp"></i> <?= htmlspecialchars($c['phone']) ?>
                    </a>
                    <?php endif; ?>
                    <?php if (!empty($c['address'])): ?>
                    <div class="text-muted text-truncate" style="max-width:200px; font-size:11px;" title="<?= htmlspecialchars($c['address']) ?>">
                        <i class="bi bi-geo-alt"></i> <?= htmlspecialchars($c['address']) ?>
                    </div>
                    <?php endif; ?>
                </td>
                
                <td>
                    <div class="d-flex align-items-center gap-1">
                        <span class="badge bg-light text-primary border font-mono fw-bold" style="font-size:12px;">
                            <i class="bi bi-hdd-network me-1"></i><?= htmlspecialchars($c['ont_sn']) ?>
                        </span>
                        <a href="/index.php?page=monitor_ont&search=<?= urlencode($c['ont_sn']) ?>" class="btn btn-sm btn-outline-secondary p-0 px-1" title="Lihat di Monitor ONT" target="_blank">
                            <i class="bi bi-box-arrow-up-right" style="font-size:10px;"></i>
                        </a>
                    </div>
                    <div class="mt-1 d-flex align-items-center justify-content-between pt-1 border-top border-light">
                        <div class="text-truncate" style="max-width: 130px;" title="Nama Wi-Fi: <?= htmlspecialchars($c['ont_wifi_ssid'] ?: '-') ?>">
                            <i class="bi bi-wifi text-primary"></i> <span class="fw-bold text-dark" style="font-size:11px;"><?= htmlspecialchars($c['ont_wifi_ssid'] ?: 'Belum diatur') ?></span>
                        </div>
                        <button type="button" class="btn btn-outline-primary py-0 px-1 btn-quick-wifi"
                                style="font-size: 10px; line-height: 1.3;"
                                data-id="<?= $c['id'] ?>"
                                data-router="<?= $c['router_id'] ?>"
                                data-name="<?= htmlspecialchars($c['full_name']) ?>"
                                data-sn="<?= htmlspecialchars($c['ont_sn']) ?>"
                                data-ssid="<?= htmlspecialchars($c['ont_wifi_ssid'] ?: ('S.NET - ' . explode(' ', $c['full_name'])[0])) ?>"
                                data-pass="<?= htmlspecialchars($c['ont_wifi_pass'] ?: '') ?>"
                                title="Ganti Nama & Kode Wi-Fi (Push ONT)">
                            <i class="bi bi-pencil-square"></i> Ubah
                        </button>
                    </div>
                </td>
                
                <td>
                    <?php 
                    $currentIsoProfile = !empty($pppoe_settings['isolir_profile']) ? $pppoe_settings['isolir_profile'] : 'isolir';
                    if ($c['status'] === 'isolated'): 
                    ?>
                        <span class="badge bg-danger text-white px-2 py-1"><i class="bi bi-shield-slash me-1"></i><?= htmlspecialchars($currentIsoProfile) ?></span>
                        <div class="text-muted" style="font-size: 11px; margin-top: 3px;">Paket: <?= htmlspecialchars($c['profile'] ?: '-') ?></div>
                    <?php else: ?>
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle fw-bold"><?= htmlspecialchars($c['profile'] ?: '-') ?></span>
                    <?php endif; ?>
                    <div class="fw-bold text-dark mt-1" style="font-size:12px;">
                        <?= format_price((float)$c['monthly_price']) ?> <span class="text-muted fw-normal" style="font-size:10px;">/bln</span>
                    </div>
                </td>
                
                <td>
                    <strong>Tgl <?= $c['due_day'] ?></strong>
                    <?php if ($c['paid_this_month'] > 0): ?>
                        <br><span class="badge bg-success-subtle text-success border border-success-subtle"><i class="bi bi-check-circle-fill me-1"></i>Lunas</span>
                    <?php elseif (!empty($c['pending_this_month']) && (int)$c['pending_this_month'] > 0): ?>
                        <br><span class="badge bg-warning-subtle text-warning border border-warning-subtle"><i class="bi bi-hourglass-split me-1"></i>Pending</span>
                    <?php elseif ($is_late): ?>
                        <br><span class="badge bg-danger-subtle text-danger border border-danger-subtle"><i class="bi bi-exclamation-circle-fill me-1"></i>Nunggak</span>
                    <?php else: ?>
                        <br><small class="text-muted">Belum bayar</small>
                    <?php endif; ?>
                </td>
                
                <td>
                    <?php if ($is_online): ?>
                        <div class="d-flex align-items-center gap-2">
                            <span class="online-dot"></span>
                            <span class="font-mono" style="font-size:12px"><?= htmlspecialchars($active_sessions[$c['pppoe_username']]['address'] ?? '') ?></span>
                        </div>
                        <div style="font-size:11px; color:var(--bs-success); margin-left:14px; margin-top:2px;">
                            Up: <?= htmlspecialchars($active_sessions[$c['pppoe_username']]['uptime'] ?? '') ?>
                        </div>
                    <?php else: ?>
                        <span class="text-muted" style="font-size:12px"><?= $selRouter ? 'Offline' : '—' ?></span>
                    <?php endif; ?>
                </td>
                
                <td>
                    <div class="d-flex gap-1 flex-nowrap">
                        <!-- Kirim Notifikasi WhatsApp Cepat -->
                        <?php if (!empty($c['phone'])): ?>
                        <button type="button" class="btn btn-sm btn-outline-success btn-icon btn-quick-wa"
                                data-id="<?= $c['id'] ?>"
                                data-name="<?= htmlspecialchars($c['full_name']) ?>"
                                data-username="<?= htmlspecialchars($c['pppoe_username']) ?>"
                                data-phone="<?= htmlspecialchars($c['phone']) ?>"
                                data-price="<?= (float)$c['monthly_price'] ?>"
                                data-due="<?= (int)$c['due_day'] ?>"
                                data-status="<?= $c['status'] ?>"
                                title="Kirim WhatsApp Tagihan / Info">
                            <i class="bi bi-whatsapp"></i>
                        </button>
                        <?php endif; ?>

                        <!-- Ganti Nama & Kode Wi-Fi Cepat (Push ONT) -->
                        <button type="button" class="btn btn-sm btn-outline-primary btn-icon btn-quick-wifi"
                                data-id="<?= $c['id'] ?>"
                                data-router="<?= $c['router_id'] ?>"
                                data-name="<?= htmlspecialchars($c['full_name']) ?>"
                                data-sn="<?= htmlspecialchars($c['ont_sn']) ?>"
                                data-ssid="<?= htmlspecialchars($c['ont_wifi_ssid'] ?: ('S.NET - ' . explode(' ', $c['full_name'])[0])) ?>"
                                data-pass="<?= htmlspecialchars($c['ont_wifi_pass'] ?: '') ?>"
                                title="Ganti Nama & Kode Wi-Fi (Push ONT)">
                            <i class="bi bi-wifi"></i>
                        </button>

                        <!-- Atur ID & Password Portal Pelanggan -->
                        <button type="button" class="btn btn-sm btn-outline-dark btn-icon btn-quick-portal"
                                data-id="<?= $c['id'] ?>"
                                data-router="<?= $c['router_id'] ?>"
                                data-name="<?= htmlspecialchars($c['full_name']) ?>"
                                data-username="<?= htmlspecialchars($c['pppoe_username']) ?>"
                                data-portal-user="<?= htmlspecialchars($c['portal_username'] ?: $c['pppoe_username']) ?>"
                                data-phone="<?= htmlspecialchars($c['phone'] ?? '') ?>"
                                title="Atur ID & Password Portal Pelanggan">
                            <i class="bi bi-person-lock"></i>
                        </button>

                        <!-- Bayar Kasir Cepat -->
                        <button type="button" class="btn btn-sm btn-outline-success btn-icon btn-quick-pay"
                                data-id="<?= $c['id'] ?>"
                                data-router="<?= $c['router_id'] ?>"
                                data-name="<?= htmlspecialchars($c['full_name']) ?>"
                                data-username="<?= htmlspecialchars($c['pppoe_username']) ?>"
                                data-price="<?= (float)$c['monthly_price'] ?>"
                                data-status="<?= $c['status'] ?>"
                                title="Catat Bayar Kasir">
                            <i class="bi bi-cash-coin"></i>
                        </button>

                        <!-- Aksi Cepat Isolir / Buka Isolir -->
                        <?php if ($c['status'] === 'active'): ?>
                        <form method="POST" action="/process/toggle_pppoe_status.php" class="d-inline"
                              onsubmit="return confirm('Apakah Anda yakin ingin MENGISOLIR pelanggan <?= htmlspecialchars(addslashes($c['full_name'])) ?>?')">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="customer_id" value="<?= $c['id'] ?>">
                            <input type="hidden" name="router_id" value="<?= $c['router_id'] ?>">
                            <input type="hidden" name="redirect_page" value="pelanggan_rumahan">
                            <input type="hidden" name="target_status" value="isolated">
                            <button type="submit" class="btn btn-sm btn-outline-warning btn-icon" title="Isolir Sekarang">
                                <i class="bi bi-slash-circle"></i>
                            </button>
                        </form>
                        <?php elseif ($c['status'] === 'isolated'): ?>
                        <form method="POST" action="/process/toggle_pppoe_status.php" class="d-inline"
                              onsubmit="return confirm('Apakah Anda yakin ingin MEMBUKA ISOLIR pelanggan <?= htmlspecialchars(addslashes($c['full_name'])) ?>?')">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="customer_id" value="<?= $c['id'] ?>">
                            <input type="hidden" name="router_id" value="<?= $c['router_id'] ?>">
                            <input type="hidden" name="redirect_page" value="pelanggan_rumahan">
                            <input type="hidden" name="target_status" value="active">
                            <button type="submit" class="btn btn-sm btn-outline-info btn-icon" title="Buka Isolir / Aktifkan Kembali">
                                <i class="bi bi-play-circle-fill"></i>
                            </button>
                        </form>
                        <?php endif; ?>

                        <a href="/index.php?page=pppoe_edit&router_id=<?= $c['router_id'] ?>&id=<?= $c['id'] ?>&from=pelanggan_rumahan"
                           class="btn btn-sm btn-outline-primary btn-icon" title="Edit Data Pelanggan">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <a href="/index.php?page=pppoe_delete&router_id=<?= $c['router_id'] ?>&id=<?= $c['id'] ?>"
                           class="btn btn-sm btn-outline-danger btn-icon"
                           data-confirm="Hapus pelanggan '<?= htmlspecialchars($c['full_name']) ?>' secara permanen dari Database dan MikroTik?"
                           title="Hapus">
                            <i class="bi bi-trash"></i>
                        </a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Catat Pembayaran Kasir (Cepat per Pelanggan) -->
<div class="modal fade" id="modalPayCustomer" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="/process/save_pppoe_payment.php" class="modal-content">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="router_id" id="pay_router_id" value="<?= $selRid ?>">
            <input type="hidden" name="customer_id" id="pay_customer_id" value="">
            <input type="hidden" name="redirect_page" value="pelanggan_rumahan">

            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-cash-stack text-success me-2"></i>Catat Pembayaran Pelanggan Rumahan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-light border mb-3">
                    <div class="fw-bold fs-6" id="pay_customer_name">-</div>
                    <div class="font-mono text-muted small" id="pay_customer_username">-</div>
                </div>

                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label fw-bold">Periode Bulan <span class="text-danger">*</span></label>
                        <select name="period_month" class="form-select" required>
                            <?php 
                            $months = [1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',5=>'Mei',6=>'Juni',7=>'Juli',8=>'Agustus',9=>'September',10=>'Oktober',11=>'November',12=>'Desember'];
                            $curM = (int)date('n');
                            foreach ($months as $k => $m):
                            ?>
                            <option value="<?= $k ?>" <?= $curM === $k ? 'selected' : '' ?>><?= $m ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-6">
                        <label class="form-label fw-bold">Periode Tahun <span class="text-danger">*</span></label>
                        <input type="number" name="period_year" class="form-control" value="<?= date('Y') ?>" required min="2024" max="2099">
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-bold">Nominal Pembayaran (Rp) <span class="text-danger">*</span></label>
                        <input type="number" name="amount" id="pay_amount" class="form-control fs-5 fw-bold text-success" required min="1000" step="1000">
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-bold">Metode Pembayaran</label>
                        <select name="payment_method" class="form-select">
                            <option value="cash">💵 Tunai / Cash (Kasir Kantor)</option>
                            <option value="transfer">🏦 Transfer Bank</option>
                            <option value="qris">📱 QRIS / E-Wallet</option>
                            <option value="other">Lainnya</option>
                        </select>
                    </div>

                    <div class="col-12">
                        <label class="form-label">Catatan / Keterangan (Opsional)</label>
                        <input type="text" name="notes" class="form-control" placeholder="Contoh: Lunas bayar di loket">
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-bold">Petugas / Teknisi Penerima</label>
                        <?php $curAdmin = current_admin(); $defCollector = ($curAdmin['full_name'] ?? '') ?: (($curAdmin['name'] ?? '') ?: ($curAdmin['username'] ?? 'Kasir / Admin')); ?>
                        <input type="text" name="collector_name" class="form-control" value="<?= htmlspecialchars($defCollector) ?>" placeholder="Nama teknisi / admin penerima uang">
                    </div>

                    <div class="col-12">
                        <div class="form-check form-switch mt-1">
                            <input class="form-check-input" type="checkbox" name="send_wa" value="1" id="checkSendWaCustomer" checked>
                            <label class="form-check-label fw-bold text-success" for="checkSendWaCustomer">
                                <i class="bi bi-whatsapp me-1"></i> Kirim WhatsApp Bukti Pembayaran Lunas ke Pelanggan
                            </label>
                        </div>
                    </div>

                    <div class="col-12" id="pay_unisolir_wrap">
                        <div class="form-check form-switch mt-1">
                            <input class="form-check-input" type="checkbox" name="auto_unisolir" value="1" id="checkAutoUnisolir" checked>
                            <label class="form-check-label fw-bold text-primary" for="checkAutoUnisolir">
                                Otomatis Buka Isolir di MikroTik jika pelanggan terisolir
                            </label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-success px-4"><i class="bi bi-check-lg me-1"></i> Simpan Pembayaran</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Catat Pembayaran Global (Pilih Pelanggan Rumahan) -->
<div class="modal fade" id="modalPayGlobal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="/process/save_pppoe_payment.php" class="modal-content">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="redirect_page" value="pelanggan_rumahan">

            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-cash-stack text-success me-2"></i>Catat Bayar Pelanggan Rumahan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label fw-bold">Pilih Pelanggan Rumahan <span class="text-danger">*</span></label>
                        <select name="customer_id" class="form-select select2-customer" required onchange="updateGlobalPrice(this)">
                            <option value="">-- Cari Nama / Username PPPoE --</option>
                            <?php foreach ($customers as $c): ?>
                            <option value="<?= $c['id'] ?>" data-price="<?= (float)$c['monthly_price'] ?>" data-router="<?= $c['router_id'] ?>">
                                <?= htmlspecialchars($c['full_name']) ?> (<?= htmlspecialchars($c['pppoe_username']) ?>) — <?= format_price((float)$c['monthly_price']) ?> [Cabang <?= htmlspecialchars($c['router_name']) ?>]
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-6">
                        <label class="form-label fw-bold">Periode Bulan <span class="text-danger">*</span></label>
                        <select name="period_month" class="form-select" required>
                            <?php foreach ($months as $k => $m): ?>
                            <option value="<?= $k ?>" <?= $curM === $k ? 'selected' : '' ?>><?= $m ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-6">
                        <label class="form-label fw-bold">Periode Tahun <span class="text-danger">*</span></label>
                        <input type="number" name="period_year" class="form-control" value="<?= date('Y') ?>" required min="2024" max="2099">
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-bold">Nominal Pembayaran (Rp) <span class="text-danger">*</span></label>
                        <input type="number" name="amount" id="global_pay_amount" class="form-control fs-5 fw-bold text-success" required min="1000" step="1000">
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-bold">Metode Pembayaran</label>
                        <select name="payment_method" class="form-select">
                            <option value="cash">💵 Tunai / Cash (Kasir Kantor)</option>
                            <option value="transfer">🏦 Transfer Bank</option>
                            <option value="qris">📱 QRIS / E-Wallet</option>
                            <option value="other">Lainnya</option>
                        </select>
                    </div>

                    <div class="col-12">
                        <label class="form-label">Catatan / Keterangan</label>
                        <input type="text" name="notes" class="form-control" placeholder="Contoh: Lunas bayar di kantor">
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-bold">Petugas / Teknisi Penerima</label>
                        <input type="text" name="collector_name" class="form-control" value="<?= htmlspecialchars($defCollector) ?>" placeholder="Nama teknisi / admin penerima uang">
                    </div>

                    <div class="col-12">
                        <div class="form-check form-switch mt-1">
                            <input class="form-check-input" type="checkbox" name="send_wa" value="1" id="checkSendWaGlobal" checked>
                            <label class="form-check-label fw-bold text-success" for="checkSendWaGlobal">
                                <i class="bi bi-whatsapp me-1"></i> Kirim WhatsApp Bukti Pembayaran Lunas ke Pelanggan
                            </label>
                        </div>
                    </div>

                    <div class="col-12">
                        <div class="form-check form-switch mt-1">
                            <input class="form-check-input" type="checkbox" name="auto_unisolir" value="1" id="checkAutoUnisolirGlobal" checked>
                            <label class="form-check-label fw-bold text-primary" for="checkAutoUnisolirGlobal">
                                Otomatis Buka Isolir di MikroTik jika pelanggan terisolir
                            </label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-success px-4"><i class="bi bi-check-lg me-1"></i> Simpan Pembayaran</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Kirim WhatsApp Cepat -->
<div class="modal fade" id="modalQuickWa" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="/process/send_wa_manual.php" class="modal-content">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="customer_id" id="wa_customer_id" value="">
            <input type="hidden" name="redirect" value="/index.php?page=pelanggan_rumahan&router_id=<?= $selRid ?>">

            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-whatsapp text-success me-2"></i>Kirim Notifikasi WhatsApp</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-light border mb-3">
                    <div class="fw-bold fs-6" id="wa_customer_name">-</div>
                    <div class="small font-mono text-muted" id="wa_customer_info">-</div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">Nomor WhatsApp Tujuan <span class="text-danger">*</span></label>
                    <input type="text" name="phone" id="wa_inp_phone" class="form-control font-mono" required>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">Pilih Template Pesan</label>
                    <select id="wa_sel_template" class="form-select" onchange="applyQuickTemplate(this.value)">
                        <option value="">-- Pilih Template Pesan --</option>
                        <?php foreach ($wa_templates as $wt): ?>
                        <option value="<?= htmlspecialchars($wt['code']) ?>"><?= htmlspecialchars($wt['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">Isi Pesan WhatsApp <span class="text-danger">*</span></label>
                    <textarea name="message" id="wa_inp_message" class="form-control font-mono small" rows="7" required placeholder="Tulis isi pesan..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-success px-4"><i class="bi bi-send-fill me-1"></i> Kirim WhatsApp</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Ganti Nama & Password Wi-Fi (Push ONT) -->
<div class="modal fade" id="modalQuickWifi" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="/process/quick_rumahan_action.php" class="modal-content shadow border-0" onsubmit="document.getElementById('btnSubmitWifi').innerHTML = '<span class=\'spinner-border spinner-border-sm me-1\'></span> Mengirim ke ONT...'; document.getElementById('btnSubmitWifi').disabled = true;">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="update_wifi">
            <input type="hidden" name="customer_id" id="wifi_customer_id" value="">
            <input type="hidden" name="router_id" id="wifi_router_id" value="<?= $selRid ?>">

            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fs-6"><i class="bi bi-wifi me-2"></i>Ganti Nama &amp; Password Wi-Fi (Push ONT)</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="alert alert-light border d-flex align-items-center justify-content-between mb-3 py-2">
                    <div>
                        <div class="fw-bold text-dark fs-6" id="wifi_customer_name">-</div>
                        <div class="text-muted small">SN ONT: <strong class="font-mono text-primary" id="wifi_customer_sn">-</strong></div>
                    </div>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1"><i class="bi bi-router me-1"></i>TR-069</span>
                </div>

                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label fw-bold">Nama Wi-Fi / SSID (2.4 GHz &amp; 5 GHz) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-broadcast"></i></span>
                            <input type="text" name="wifi_ssid" id="modal_wifi_ssid" class="form-control form-control-lg fw-bold" required placeholder="Contoh: S.NET - FADIRA">
                        </div>
                        <div class="form-text">Nama SSID untuk sinyal 2.4 GHz dan 5 GHz disamakan otomatis (tanpa embel-embel 5G).</div>
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-bold">Password / Kode Wi-Fi (WPA2) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-key-fill"></i></span>
                            <input type="text" name="wifi_pass" id="modal_wifi_pass" class="form-control form-control-lg font-mono fw-bold text-primary" minlength="8" required placeholder="Minimal 8 karakter">
                            <button type="button" class="btn btn-outline-secondary" onclick="generateRandomWifiPass()" title="Buat Sandi Acak">
                                <i class="bi bi-shuffle me-1"></i>Acak
                            </button>
                            <button type="button" class="btn btn-outline-secondary" onclick="copyWifiPass()" title="Salin Sandi">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </div>
                        <div class="form-text">Minimal 8 karakter. Berlaku sama untuk sinyal Wi-Fi 2.4 GHz &amp; 5 GHz.</div>
                    </div>

                    <div class="col-12">
                        <div class="form-check form-switch p-3 bg-light rounded-3 border">
                            <input class="form-check-input ms-0 me-2" type="checkbox" name="push_ont" value="1" id="checkPushWifiOnt" checked style="float:none;">
                            <label class="form-check-label fw-bold text-success" for="checkPushWifiOnt">
                                <i class="bi bi-lightning-charge-fill me-1"></i> Push langsung ke Modem ONT via GenieACS (TR-069)
                            </label>
                            <div class="text-muted small mt-1 ms-4">Konfigurasi baru akan otomatis dikirim ke modem pelanggan tanpa perlu teknisi datang ke rumah.</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary fw-bold px-4" id="btnSubmitWifi">
                    <i class="bi bi-check2-circle me-1"></i> Simpan &amp; Terapkan Wi-Fi
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Atur ID & Password Portal Pelanggan -->
<div class="modal fade" id="modalQuickPortal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="/process/quick_rumahan_action.php" class="modal-content shadow border-0">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="update_portal">
            <input type="hidden" name="customer_id" id="portal_customer_id" value="">
            <input type="hidden" name="router_id" id="portal_router_id" value="<?= $selRid ?>">

            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title fs-6"><i class="bi bi-person-lock me-2"></i>Atur Akun Portal Pelanggan</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="alert alert-light border mb-3 py-2">
                    <div class="fw-bold text-dark fs-6" id="portal_customer_name">-</div>
                    <div class="text-muted small">Username PPPoE: <strong class="font-mono text-primary" id="portal_customer_pppoe">-</strong></div>
                </div>

                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label fw-bold">ID / Username Login Portal <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-person-fill"></i></span>
                            <input type="text" name="portal_username" id="modal_portal_user" class="form-control form-control-lg font-mono fw-bold" required placeholder="Username portal">
                        </div>
                        <div class="form-text">Digunakan oleh pelanggan saat login ke halaman web portal mandiri.</div>
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-bold">Password Baru Portal</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-shield-lock-fill"></i></span>
                            <input type="text" name="portal_password" id="modal_portal_pass" class="form-control form-control-lg font-mono fw-bold text-dark" placeholder="Kosongkan jika tidak ingin ubah password">
                            <button type="button" class="btn btn-outline-secondary" onclick="generateRandomPortalPass()" title="Buat Sandi Acak">
                                <i class="bi bi-shuffle me-1"></i>Acak
                            </button>
                        </div>
                        <div class="form-text">Isi kolom ini jika ingin mereset password portal pelanggan (minimal 4 karakter).</div>
                    </div>

                    <div class="col-12" id="portal_wa_wrapper">
                        <div class="form-check form-switch p-3 bg-light rounded-3 border">
                            <input class="form-check-input ms-0 me-2" type="checkbox" name="send_wa" value="1" id="checkSendWaPortal" checked style="float:none;">
                            <label class="form-check-label fw-bold text-success" for="checkSendWaPortal">
                                <i class="bi bi-whatsapp me-1"></i> Kirim info login &amp; link portal via WhatsApp ke pelanggan
                            </label>
                            <div class="text-muted small mt-1 ms-4" id="portal_wa_phone_info">No WhatsApp: -</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-dark fw-bold px-4">
                    <i class="bi bi-check2-circle me-1"></i> Simpan Akun Portal
                </button>
            </div>
        </form>
    </div>
</div>

<script>
const waTemplatesList = <?= json_encode($wa_templates) ?>;
let activeWaCustomer = null;

document.addEventListener('DOMContentLoaded', function() {
    const payButtons = document.querySelectorAll('.btn-quick-pay');
    const modalEl = document.getElementById('modalPayCustomer');
    if (modalEl && payButtons.length > 0) {
        const modal = new bootstrap.Modal(modalEl);
        payButtons.forEach(btn => {
            btn.addEventListener('click', function() {
                document.getElementById('pay_customer_id').value = this.dataset.id;
                document.getElementById('pay_router_id').value = this.dataset.router || '<?= $selRid ?>';
                document.getElementById('pay_customer_name').textContent = this.dataset.name;
                document.getElementById('pay_customer_username').textContent = 'Username: ' + this.dataset.username;
                document.getElementById('pay_amount').value = this.dataset.price;
                modal.show();
            });
        });
    }

    const waButtons = document.querySelectorAll('.btn-quick-wa');
    const modalWaEl = document.getElementById('modalQuickWa');
    if (modalWaEl && waButtons.length > 0) {
        const modalWa = new bootstrap.Modal(modalWaEl);
        waButtons.forEach(btn => {
            btn.addEventListener('click', function() {
                activeWaCustomer = {
                    id: this.dataset.id,
                    name: this.dataset.name,
                    username: this.dataset.username,
                    phone: this.dataset.phone,
                    price: this.dataset.price,
                    due: this.dataset.due,
                    status: this.dataset.status
                };
                document.getElementById('wa_customer_id').value = activeWaCustomer.id;
                document.getElementById('wa_customer_name').textContent = activeWaCustomer.name;
                document.getElementById('wa_customer_info').textContent = 'Username: ' + activeWaCustomer.username + ' | Tagihan: Rp ' + Number(activeWaCustomer.price).toLocaleString('id-ID');
                document.getElementById('wa_inp_phone').value = activeWaCustomer.phone;
                
                // Set default template based on status
                let defaultCode = (activeWaCustomer.status === 'isolated') ? 'isolir' : 'reminder_h3';
                document.getElementById('wa_sel_template').value = defaultCode;
                applyQuickTemplate(defaultCode);
                
                modalWa.show();
            });
        });
    }

    // Modal Quick Wi-Fi
    const wifiButtons = document.querySelectorAll('.btn-quick-wifi');
    const modalWifiEl = document.getElementById('modalQuickWifi');
    if (modalWifiEl && wifiButtons.length > 0) {
        const modalWifi = new bootstrap.Modal(modalWifiEl);
        wifiButtons.forEach(btn => {
            btn.addEventListener('click', function() {
                document.getElementById('wifi_customer_id').value = this.dataset.id;
                document.getElementById('wifi_router_id').value = this.dataset.router || '<?= $selRid ?>';
                document.getElementById('wifi_customer_name').textContent = this.dataset.name;
                document.getElementById('wifi_customer_sn').textContent = this.dataset.sn || '-';
                document.getElementById('modal_wifi_ssid').value = this.dataset.ssid || '';
                document.getElementById('modal_wifi_pass').value = this.dataset.pass || '';
                modalWifi.show();
            });
        });
    }

    // Modal Quick Portal
    const portalButtons = document.querySelectorAll('.btn-quick-portal');
    const modalPortalEl = document.getElementById('modalQuickPortal');
    if (modalPortalEl && portalButtons.length > 0) {
        const modalPortal = new bootstrap.Modal(modalPortalEl);
        portalButtons.forEach(btn => {
            btn.addEventListener('click', function() {
                document.getElementById('portal_customer_id').value = this.dataset.id;
                document.getElementById('portal_router_id').value = this.dataset.router || '<?= $selRid ?>';
                document.getElementById('portal_customer_name').textContent = this.dataset.name;
                document.getElementById('portal_customer_pppoe').textContent = this.dataset.username || '-';
                document.getElementById('modal_portal_user').value = this.dataset.portalUser || this.dataset.username || '';
                document.getElementById('modal_portal_pass').value = '';
                
                const phone = this.dataset.phone || '';
                const phoneInfo = document.getElementById('portal_wa_phone_info');
                const chkWa = document.getElementById('checkSendWaPortal');
                if (phone) {
                    phoneInfo.textContent = 'Nomor WhatsApp: ' + phone;
                    chkWa.disabled = false;
                    chkWa.checked = true;
                } else {
                    phoneInfo.textContent = 'Pelanggan tidak memiliki nomor WhatsApp tersimpan.';
                    chkWa.disabled = true;
                    chkWa.checked = false;
                }
                modalPortal.show();
            });
        });
    }
});

function generateRandomWifiPass() {
    const chars = 'abcdefghjkmnpqrstuvwxyz23456789';
    let res = '';
    for (let i = 0; i < 8; i++) {
        res += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    document.getElementById('modal_wifi_pass').value = res;
}

function copyWifiPass() {
    const inp = document.getElementById('modal_wifi_pass');
    if (!inp || !inp.value) return;
    navigator.clipboard.writeText(inp.value).then(() => {
        alert('Password Wi-Fi (' + inp.value + ') berhasil disalin ke clipboard!');
    }).catch(() => {
        inp.select();
        document.execCommand('copy');
        alert('Password Wi-Fi disalin!');
    });
}

function generateRandomPortalPass() {
    const rand = Math.floor(100000 + Math.random() * 900000);
    document.getElementById('modal_portal_pass').value = String(rand);
}

const companyName = <?= json_encode($company_name) ?>;
const companyPhone = <?= json_encode($company_phone) ?>;
const currentMonthYear = '<?= date('F Y') ?>';
const currentDateTime = '<?= date('d M Y, H:i') . ' WIB' ?>';

function applyQuickTemplate(code) {
    if (!code || !activeWaCustomer) return;
    const t = waTemplatesList.find(item => item.code === code);
    if (!t) return;
    
    let msg = t.message;
    const custId = activeWaCustomer.id || '1';
    msg = msg.replace(/{nama}/g, activeWaCustomer.name)
             .replace(/{username}/g, activeWaCustomer.username)
             .replace(/{nama_layanan}/g, companyName)
             .replace(/{tagihan}/g, 'Rp ' + Number(activeWaCustomer.price).toLocaleString('id-ID'))
             .replace(/{jatuh_tempo}/g, 'Tanggal ' + activeWaCustomer.due)
             .replace(/{bulan}/g, currentMonthYear)
             .replace(/{waktu_bayar}/g, currentDateTime)
             .replace(/{no_invoice}/g, 'INV-' + '<?= date('Ymd') ?>-' + String(custId).padStart(3, '0'))
             .replace(/{link_receipt}/g, window.location.origin + '/portal/receipt.php?id=' + custId)
             .replace(/{cs_phone}/g, companyPhone)
             .replace(/{link_portal}/g, window.location.origin + '/portal/isolir.php?user=' + encodeURIComponent(activeWaCustomer.username));
    
    document.getElementById('wa_inp_message').value = msg;
}

function updateGlobalPrice(sel) {
    const opt = sel.options[sel.selectedIndex];
    const price = opt ? opt.getAttribute('data-price') : 0;
    document.getElementById('global_pay_amount').value = price || '';
}
</script>

<?php include __DIR__ . '/../../../include/footer.php'; ?>
