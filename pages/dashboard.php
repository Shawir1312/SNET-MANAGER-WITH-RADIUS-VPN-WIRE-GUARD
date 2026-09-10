<?php
/**
 * Dashboard — Summary of all routers & voucher statistics
 */
$page_title       = 'Dashboard';
$show_router_filter = false;
$all_routers      = get_all_routers();
run_auto_expire_vouchers(); // Automatically sync and clean up database (Lazy Evaluation)

// ── Stats ────────────────────────────────────────────────
$total_routers   = count($all_routers);
$total_vouchers  = (int)(db_fetch_one("SELECT COUNT(*) AS n FROM vouchers WHERE status != 'deleted'")['n'] ?? 0);
$unused_vouchers = (int)(db_fetch_one("SELECT COUNT(*) AS n FROM vouchers WHERE status = 'unused'")['n'] ?? 0);
$active_vouchers = (int)(db_fetch_one("SELECT COUNT(*) AS n FROM vouchers WHERE status = 'active'")['n'] ?? 0);
$expired_vouchers= (int)(db_fetch_one("SELECT COUNT(*) AS n FROM vouchers WHERE status = 'expired'")['n'] ?? 0);
$total_profiles  = (int)(db_fetch_one("SELECT COUNT(*) AS n FROM profiles WHERE is_active = 1")['n'] ?? 0);

// Active sessions from radacct
$active_sessions = (int)(db_fetch_one("SELECT COUNT(*) AS n FROM radacct WHERE acctstoptime IS NULL")['n'] ?? 0);

// Today's sales
$today_sales = db_fetch_one(
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(price),0) AS total FROM sales_log WHERE DATE(sold_at) = CURDATE()"
) ?? ['cnt' => 0, 'total' => 0];

// This month's sales
$month_sales = db_fetch_one(
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(price),0) AS total FROM sales_log WHERE MONTH(sold_at) = MONTH(CURDATE()) AND YEAR(sold_at) = YEAR(CURDATE())"
) ?? ['cnt' => 0, 'total' => 0];

// Sales per router (Today and This Month)
$router_sales = db_fetch_all(
    "SELECT r.name,
            COALESCE(SUM(CASE WHEN DATE(sl.sold_at) = CURDATE() THEN sl.price ELSE 0 END), 0) AS today_total,
            COALESCE(SUM(CASE WHEN MONTH(sl.sold_at) = MONTH(CURDATE()) AND YEAR(sl.sold_at) = YEAR(CURDATE()) THEN sl.price ELSE 0 END), 0) AS month_total
     FROM routers r
     LEFT JOIN sales_log sl ON r.id = sl.router_id
     GROUP BY r.id
     ORDER BY r.name ASC"
);

// Recent voucher batches
$recent_batches = db_fetch_all(
    "SELECT v.batch_id, v.created_at, v.router_id, r.name AS router_name, p.name AS profile_name,
            COUNT(*) AS qty, a.username AS generated_by
     FROM vouchers v
     LEFT JOIN routers r ON v.router_id = r.id
     LEFT JOIN profiles p ON v.profile_id = p.id
     LEFT JOIN admins a ON v.generated_by = a.id
     WHERE v.batch_id IS NOT NULL
     GROUP BY v.batch_id
     ORDER BY v.created_at DESC LIMIT 8"
);

// Sales chart — last 7 days
$chart_data = db_fetch_all(
    "SELECT DATE(sold_at) AS day, COUNT(*) AS cnt, COALESCE(SUM(price),0) AS revenue
     FROM sales_log WHERE sold_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
     GROUP BY DATE(sold_at) ORDER BY day ASC"
);

// Broadband PPPoE Stats
$pppoe_total = 0;
$pppoe_active = 0;
$pppoe_isolated = 0;
$pppoe_paid_month = 0;
try {
    $cStats = db_fetch_one("SELECT 
        COUNT(*) as total,
        COALESCE(SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END), 0) as active_count,
        COALESCE(SUM(CASE WHEN status = 'isolated' THEN 1 ELSE 0 END), 0) as isolated_count
        FROM pppoe_customers");
    if ($cStats) {
        $pppoe_total = (int)$cStats['total'];
        $pppoe_active = (int)$cStats['active_count'];
        $pppoe_isolated = (int)$cStats['isolated_count'];
    }
    $pMonth = db_fetch_one("SELECT COALESCE(SUM(amount), 0) as total FROM pppoe_payments WHERE period_month = MONTH(CURDATE()) AND period_year = YEAR(CURDATE()) AND midtrans_status NOT IN ('pending','cancel','deny','expire')");
    if ($pMonth) {
        $pppoe_paid_month = (float)$pMonth['total'];
    }
} catch (Throwable $e) {}

// WireGuard VPN Stats
$wg_total_peers = 0;
$wg_online_peers = 0;
$wg_forwards_count = 0;
try {
    require_once __DIR__ . '/../include/wireguard_functions.php';
    $wgPeers = db_fetch_all("SELECT public_key FROM wg_routers");
    $wg_total_peers = count($wgPeers);
    $peerStatus = wg_get_peer_status();
    foreach ($wgPeers as $wp) {
        $k = $wp['public_key'];
        if (isset($peerStatus[$k]) && $peerStatus[$k]['connected']) {
            $wg_online_peers++;
        }
    }
    $pfRow = db_fetch_one("SELECT COUNT(*) as c FROM wg_port_forwards");
    $wg_forwards_count = (int)($pfRow['c'] ?? 0);
} catch (Throwable $e) {}

include __DIR__ . '/../include/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div>
        <h1 class="page-title">Dashboard</h1>
        <p class="page-subtitle">Ringkasan status Hotspot, Broadband PPPoE, dan VPN WireGuard</p>
    </div>
    <div class="d-flex gap-2">
        <a href="/index.php?page=generate_voucher" class="btn btn-primary">
            <i class="bi bi-plus-circle me-1"></i> Generate Voucher
        </a>
    </div>
</div>

<!-- Broadband & WireGuard Summary Highlights -->
<div class="row g-3 mb-4">
    <div class="col-12 col-md-6 col-xl-4">
        <div class="card shadow-sm border-0 border-start border-4 border-primary h-100">
            <div class="card-body p-3 d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-uppercase fw-bold text-muted small"><i class="bi bi-people me-1 text-primary"></i> Pelanggan Broadband PPPoE</div>
                    <div class="fs-4 fw-bold text-dark mt-1"><?= number_format($pppoe_total) ?> <span class="fs-6 fw-normal text-muted">Pelanggan</span></div>
                    <div class="small mt-1">
                        <span class="badge bg-success me-1">🟢 <?= $pppoe_active ?> Aktif</span>
                        <span class="badge bg-danger">🔴 <?= $pppoe_isolated ?> Isolir</span>
                    </div>
                </div>
                <a href="/index.php?page=pppoe_customers" class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-right"></i></a>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-xl-4">
        <div class="card shadow-sm border-0 border-start border-4 border-success h-100">
            <div class="card-body p-3 d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-uppercase fw-bold text-muted small"><i class="bi bi-wallet2 me-1 text-success"></i> Tagihan PPPoE Bulan Ini</div>
                    <div class="fs-4 fw-bold text-success mt-1"><?= format_price($pppoe_paid_month) ?></div>
                    <div class="small text-muted mt-1">Periode <?= date('F Y') ?></div>
                </div>
                <a href="/index.php?page=pppoe_payments" class="btn btn-outline-success btn-sm"><i class="bi bi-arrow-right"></i></a>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-12 col-xl-4">
        <div class="card shadow-sm border-0 border-start border-4 border-info h-100">
            <div class="card-body p-3 d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-uppercase fw-bold text-muted small"><i class="bi bi-shield-lock me-1 text-info"></i> VPN WireGuard Hub</div>
                    <div class="fs-4 fw-bold text-dark mt-1"><?= $wg_online_peers ?> / <?= $wg_total_peers ?> <span class="fs-6 fw-normal text-muted">Router Online</span></div>
                    <div class="small text-muted mt-1"><i class="bi bi-arrow-left-right me-1"></i> <?= $wg_forwards_count ?> Port Forwarding Aktif</div>
                </div>
                <a href="/index.php?page=wg_routers" class="btn btn-outline-info btn-sm"><i class="bi bi-arrow-right"></i></a>
            </div>
        </div>
    </div>
</div>

<!-- Stat Cards Row 1 -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-lg-2">
        <div class="stat-card blue h-100">
            <div class="stat-icon"><i class="bi bi-router"></i></div>
            <div class="stat-value"><?= $total_routers ?></div>
            <div class="stat-label">Total Router</div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <div class="stat-card green h-100">
            <div class="stat-icon"><i class="bi bi-wifi"></i></div>
            <div class="stat-value"><?= $active_sessions ?></div>
            <div class="stat-label">User Aktif</div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <div class="stat-card teal h-100">
            <div class="stat-icon"><i class="bi bi-ticket-perforated"></i></div>
            <div class="stat-value"><?= $unused_vouchers ?></div>
            <div class="stat-label">Voucher Tersedia</div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <div class="stat-card orange h-100">
            <div class="stat-icon"><i class="bi bi-ticket-detailed"></i></div>
            <div class="stat-value"><?= $active_vouchers ?></div>
            <div class="stat-label">Voucher Aktif</div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <div class="stat-card red h-100">
            <div class="stat-icon"><i class="bi bi-x-circle"></i></div>
            <div class="stat-value"><?= $expired_vouchers ?></div>
            <div class="stat-label">Kadaluarsa</div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <div class="stat-card purple h-100">
            <div class="stat-icon"><i class="bi bi-currency-dollar"></i></div>
            <div class="stat-value"><?= $today_sales['cnt'] ?></div>
            <div class="stat-label">Terjual Hari Ini</div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <!-- Router Status Cards -->
    <div class="col-12 col-lg-8 d-flex flex-column gap-3">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title"><i class="bi bi-router"></i> Status Router</h5>
                <?php if (is_superadmin()): ?>
                <a href="/index.php?page=router_list" class="btn btn-sm btn-outline-primary">Kelola Router</a>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (empty($all_routers)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="bi bi-router display-4 d-block mb-2"></i>
                    <p>Belum ada router terdaftar.</p>
                    <?php if (is_superadmin()): ?>
                    <a href="/index.php?page=router_add" class="btn btn-primary btn-sm">+ Tambah Router</a>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($all_routers as $router): ?>
                    <div class="col-sm-6 col-md-4">
                        <div class="router-card" data-router-id="<?= $router['id'] ?>">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div>
                                    <div class="router-name"><?= htmlspecialchars($router['name']) ?></div>
                                    <div class="router-ip"><?= htmlspecialchars($router['ip_address']) ?></div>
                                </div>
                                <span class="router-status-badge badge bg-secondary">
                                    <span class="status-dot"></span>Cek...
                                </span>
                            </div>
                            <?php if ($router['location']): ?>
                            <div class="text-muted" style="font-size:.72rem;"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($router['location']) ?></div>
                            <?php endif; ?>
                            <div class="router-users mt-2">
                                <i class="bi bi-wifi me-1"></i>
                                <span class="router-users-count">-</span> user aktif
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Realtime Traffic Compact -->
        <div class="row g-3" id="traffic-charts-container">
            <?php foreach ($all_routers as $router): ?>
            <div class="col-12 col-md-6">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center p-2">
                        <h5 class="card-title m-0 text-truncate pe-2" style="font-size:0.85rem;"><i class="bi bi-activity text-primary"></i> <?= htmlspecialchars($router['name']) ?></h5>
                        <select class="form-select form-select-sm w-auto interface-select py-0 px-2" data-router-id="<?= $router['id'] ?>" style="font-size:0.75rem; height: 24px;">
                            <option value="">Memuat...</option>
                        </select>
                    </div>
                    <div class="card-body p-2">
                        <canvas id="trafficChart_<?= $router['id'] ?>" height="110"></canvas>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Sales Chart -->
        <div class="card mt-3">
            <div class="card-header">
                <h5 class="card-title"><i class="bi bi-graph-up"></i> Penjualan 7 Hari Terakhir</h5>
            </div>
            <div class="card-body" style="height: 300px; position: relative;">
                <canvas id="salesChart"></canvas>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-4 d-flex flex-column gap-3">
        <!-- Revenue -->
        <div class="card">
            <div class="card-header">
                <h5 class="card-title"><i class="bi bi-cash-stack"></i> Ringkasan Penjualan</h5>
            </div>
            <div class="card-body p-0">
                <div class="row g-0">
                    <!-- Hari Ini -->
                    <div class="col-6 border-end d-flex flex-column align-items-center justify-content-center text-center p-3">
                        <div class="text-muted mb-2" style="font-size:0.8rem;text-transform:uppercase;letter-spacing:0.5px;font-weight:600;">Hari Ini</div>
                        <div class="mb-1" style="font-size:2rem;font-weight:800;color:var(--blue);line-height:1;">
                            <?= $today_sales['cnt'] ?> <span style="font-size:0.8rem;font-weight:normal;color:#6c757d">pcs</span>
                        </div>
                        <div style="font-size:1.15rem;font-weight:700;color:var(--red);">
                            <?= format_price((float)$today_sales['total']) ?>
                        </div>
                    </div>
                    <!-- Bulan Ini -->
                    <div class="col-6 d-flex flex-column align-items-center justify-content-center text-center p-3">
                        <div class="text-muted mb-2" style="font-size:0.8rem;text-transform:uppercase;letter-spacing:0.5px;font-weight:600;">Bulan Ini</div>
                        <div class="mb-1" style="font-size:2rem;font-weight:800;color:var(--blue);line-height:1;">
                            <?= $month_sales['cnt'] ?> <span style="font-size:0.8rem;font-weight:normal;color:#6c757d">pcs</span>
                        </div>
                        <div style="font-size:1.15rem;font-weight:700;color:var(--red);">
                            <?= format_price((float)$month_sales['total']) ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-footer bg-white border-top-0 pt-0 pb-3">
                <a href="/index.php?page=report_sales" class="btn btn-outline-primary btn-sm w-100">
                    <i class="bi bi-bar-chart me-1"></i>Lihat Laporan Lengkap
                </a>
            </div>
        </div>

        <!-- Pendapatan per Cabang -->
        <div class="card">
            <div class="card-header">
                <h5 class="card-title"><i class="bi bi-shop"></i> Pendapatan per Cabang</h5>
            </div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush">
                    <?php if (empty($router_sales)): ?>
                    <div class="list-group-item text-center text-muted py-4">Belum ada cabang/router</div>
                    <?php else: ?>
                    <?php foreach ($router_sales as $rs): ?>
                    <div class="list-group-item d-flex justify-content-between align-items-center p-3">
                        <div>
                            <h6 class="mb-1" style="font-size:0.9rem; font-weight:700; color:var(--blue);"><?= htmlspecialchars($rs['name']) ?></h6>
                            <div class="text-muted" style="font-size:0.75rem;">
                                Hari ini: <strong class="text-dark"><?= format_price((float)$rs['today_total']) ?></strong>
                            </div>
                        </div>
                        <div class="text-end">
                            <div style="font-size:0.7rem; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d; margin-bottom:2px;">Bulan Ini</div>
                            <div style="font-size:1.05rem; font-weight:800; color:var(--red); line-height:1;">
                                <?= format_price((float)$rs['month_total']) ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Recent Batches -->
        <div class="card">
            <div class="card-header">
                <h5 class="card-title"><i class="bi bi-clock-history"></i> Generate Terakhir</h5>
                <a href="/index.php?page=voucher_list" class="btn btn-sm btn-outline-primary">Lihat Semua</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr>
                            <th>Batch</th><th>Profil</th><th>Qty</th><th>Router</th><th>Waktu</th>
                        </tr></thead>
                        <tbody>
                        <?php if (empty($recent_batches)): ?>
                        <tr><td colspan="5" class="text-center text-muted py-3">Belum ada data</td></tr>
                        <?php else: ?>
                        <?php foreach ($recent_batches as $b): ?>
                        <tr>
                            <td>
                                <a href="/index.php?page=voucher_list&batch_id=<?= urlencode($b['batch_id']) ?>"
                                   class="font-mono fw-600 text-blue" style="font-size:.73rem;">
                                    <?= htmlspecialchars($b['batch_id']) ?>
                                </a>
                            </td>
                            <td style="font-size:.78rem;"><?= htmlspecialchars($b['profile_name'] ?? '-') ?></td>
                            <td><span class="badge bg-primary"><?= $b['qty'] ?></span></td>
                            <td style="font-size:.75rem;"><?= htmlspecialchars($b['router_name'] ?? 'Semua') ?></td>
                            <td style="font-size:.72rem;color:var(--gray-500);">
                                <?= date('d/m H:i', strtotime($b['created_at'])) ?>
                            </td>
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

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
(function() {
// Sales chart — Dashboard (7 Hari Terakhir)
const chartData = <?= json_encode($chart_data) ?>;
const labels    = chartData.map(d => {
    const dt = new Date(d.day);
    return dt.toLocaleDateString('id-ID', { weekday:'short', day:'numeric', month:'short' });
});
const cnts = chartData.map(d => parseInt(d.cnt));
const revs = chartData.map(d => parseFloat(d.revenue));

const ctx = document.getElementById('salesChart');
if (!ctx) return;
const ctxg = ctx.getContext('2d');

// Gradient for revenue line
const revGrad = ctxg.createLinearGradient(0, 0, 0, 300);
revGrad.addColorStop(0,   'rgba(198,40,40,0.25)');
revGrad.addColorStop(1,   'rgba(198,40,40,0)');

// Gradient for bars
const barGrad = ctxg.createLinearGradient(0, 0, 0, 300);
barGrad.addColorStop(0,  'rgba(21,101,192,0.95)');
barGrad.addColorStop(1,  'rgba(21,101,192,0.35)');

// Detect dark mode
const isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
const gridColor  = isDark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.05)';
const tickColor  = isDark ? '#adb5bd' : '#6c757d';
const labelColor = isDark ? '#dee2e6' : '#343a40';

new Chart(ctxg, {
    type: 'bar',
    data: {
        labels,
        datasets: [
            {
                label: 'Voucher Terjual',
                data: cnts,
                backgroundColor: barGrad,
                borderColor: 'rgba(21,101,192,0)',
                borderRadius: { topLeft: 8, topRight: 8 },
                borderSkipped: false,
                yAxisID: 'y',
                order: 2,
            },
            {
                label: 'Pendapatan (Rp)',
                data: revs,
                type: 'line',
                borderColor: '#C62828',
                backgroundColor: revGrad,
                borderWidth: 2.5,
                pointBackgroundColor: '#fff',
                pointBorderColor: '#C62828',
                pointBorderWidth: 2,
                pointRadius: 5,
                pointHoverRadius: 7,
                tension: 0.45,
                fill: true,
                yAxisID: 'y2',
                order: 1,
            }
        ],
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        animation: { duration: 800, easing: 'easeInOutQuart' },
        plugins: {
            legend: {
                position: 'top',
                labels: {
                    font: { family: 'Inter, sans-serif', size: 12, weight: '600' },
                    color: labelColor,
                    usePointStyle: true,
                    pointStyleWidth: 10,
                    padding: 20,
                }
            },
            tooltip: {
                backgroundColor: isDark ? '#1e2330' : '#fff',
                titleColor:  isDark ? '#dee2e6' : '#212529',
                bodyColor:   isDark ? '#adb5bd' : '#495057',
                borderColor: isDark ? 'rgba(255,255,255,.1)' : 'rgba(0,0,0,.08)',
                borderWidth: 1,
                padding: 12,
                cornerRadius: 10,
                boxPadding: 4,
                titleFont: { size: 12, weight: '700' },
                bodyFont:  { size: 12 },
                callbacks: {
                    label: ctx => {
                        if (ctx.dataset.label.includes('Pendapatan')) {
                            return ' ' + ctx.dataset.label + ': Rp ' + parseInt(ctx.parsed.y).toLocaleString('id-ID');
                        }
                        return ' ' + ctx.dataset.label + ': ' + ctx.parsed.y + ' pcs';
                    }
                }
            }
        },
        scales: {
            x: {
                grid: { display: false },
                ticks: { color: tickColor, font: { size: 11 } },
                border: { display: false },
            },
            y: {
                beginAtZero: true,
                ticks: { precision: 0, color: tickColor, font: { size: 11 } },
                grid: { color: gridColor },
                border: { display: false },
            },
            y2: {
                beginAtZero: true,
                position: 'right',
                grid: { display: false },
                border: { display: false },
                ticks: {
                    color: '#C62828',
                    font: { size: 11 },
                    callback: v => 'Rp ' + (v >= 1000000 ? (v/1000000).toFixed(1)+'jt' : (v >= 1000 ? (v/1000).toFixed(0)+'rb' : v))
                },
            },
        },
    },
});
// --- Realtime Traffic Charts ---
const routers = <?= json_encode(array_column($all_routers, 'id')) ?>;
const trafficCharts = {};

function formatBps(bits) {
    if (bits >= 1000000000) return (bits / 1000000000).toFixed(2) + ' Gbps';
    if (bits >= 1000000) return (bits / 1000000).toFixed(2) + ' Mbps';
    if (bits >= 1000) return (bits / 1000).toFixed(2) + ' Kbps';
    return bits + ' bps';
}

routers.forEach(async (rid) => {
    const ctxT = document.getElementById('trafficChart_' + rid);
    if (!ctxT) return;
    
    trafficCharts[rid] = new Chart(ctxT.getContext('2d'), {
        type: 'line',
        data: {
            labels: Array(20).fill(''),
            datasets: [
                {
                    label: 'RX (Download)',
                    data: Array(20).fill(0),
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16,185,129,0.1)',
                    fill: true,
                    tension: 0.4,
                    pointRadius: 0
                },
                {
                    label: 'TX (Upload)',
                    data: Array(20).fill(0),
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59,130,246,0.1)',
                    fill: true,
                    tension: 0.4,
                    pointRadius: 0
                }
            ]
        },
        options: {
            responsive: true,
            animation: false,
            scales: {
                x: { display: false },
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(v) { return formatBps(v); },
                        font: { size: 10 }
                    }
                }
            },
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function(ctx) { return ctx.dataset.label + ': ' + formatBps(ctx.raw); }
                    }
                },
                legend: { position: 'top', labels: { boxWidth: 12, font: { size: 11 } } }
            }
        }
    });

    const sel = document.querySelector(`.interface-select[data-router-id="${rid}"]`);
    try {
        const r = await fetch(`/ajax/api_traffic.php?action=interfaces&router_id=${rid}`);
        const d = await r.json();
        if (d.success && d.data) {
            sel.innerHTML = '';
            d.data.forEach(iface => {
                const opt = document.createElement('option');
                opt.value = iface.name;
                opt.textContent = iface.name;
                sel.appendChild(opt);
            });
            const saved = localStorage.getItem('traffic_iface_' + rid);
            if (saved && d.data.find(i => i.name === saved)) {
                sel.value = saved;
            }
        }
    } catch(e) {}

    sel.addEventListener('change', function() {
        localStorage.setItem('traffic_iface_' + rid, this.value);
        trafficCharts[rid].data.datasets[0].data.fill(0);
        trafficCharts[rid].data.datasets[1].data.fill(0);
        trafficCharts[rid].update();
    });
});

setInterval(async () => {
    for (const rid of routers) {
        const sel = document.querySelector(`.interface-select[data-router-id="${rid}"]`);
        if (!sel || !sel.value) continue;
        
        try {
            const fd = new FormData();
            fd.append('action', 'traffic');
            fd.append('router_id', rid);
            fd.append('interface', sel.value);
            
            const r = await fetch('/ajax/api_traffic.php', { method: 'POST', body: fd });
            const d = await r.json();
            
            if (d.success) {
                const chart = trafficCharts[rid];
                chart.data.labels.push('');
                chart.data.labels.shift();
                
                chart.data.datasets[0].data.push(d.rx || 0);
                chart.data.datasets[0].data.shift();
                
                chart.data.datasets[1].data.push(d.tx || 0);
                chart.data.datasets[1].data.shift();
                
                chart.update();
            }
        } catch(e) {}
    }
}, 3000);

})();
</script>

<?php include __DIR__ . '/../include/footer.php'; ?>
