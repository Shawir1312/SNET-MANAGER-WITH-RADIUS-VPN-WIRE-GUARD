<?php
/**
 * Reports — Sales Report
 */
$page_title       = 'Laporan Penjualan';
$show_router_filter = true;
$all_routers      = get_all_routers();

$filter_router  = (int)get('router_id');
$filter_from    = get('from', date('Y-m-01'));
$filter_to      = get('to',   date('Y-m-d'));
$filter_profile = (int)get('profile_id');

$profiles = db_fetch_all("SELECT id, name FROM profiles ORDER BY name ASC");

// WHERE clause
$where  = ['DATE(sold_at) BETWEEN ? AND ?'];
$params = [$filter_from, $filter_to];
$types  = 'ss';

if ($filter_router) {
    $where[] = 'router_id = ?'; $params[] = $filter_router; $types .= 'i';
}
if ($filter_profile) {
    $where[] = 'profile_id = ?'; $params[] = $filter_profile; $types .= 'i';
}

// Restrict by access
$access = accessible_router_ids();
if ($access !== null && !empty($access)) {
    $pls = implode(',', array_fill(0, count($access), '?'));
    $where[] = "router_id IN ({$pls})";
    foreach ($access as $rid) { $params[] = $rid; $types .= 'i'; }
}

$where_sql = 'WHERE ' . implode(' AND ', $where);

// Summary
$summary = db_fetch_one(
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(price),0) AS total FROM sales_log {$where_sql}",
    $types, $params
) ?? ['cnt' => 0, 'total' => 0];

// By day chart
$by_day = db_fetch_all(
    "SELECT DATE(sold_at) AS day, COUNT(*) AS cnt, SUM(price) AS revenue
     FROM sales_log {$where_sql} GROUP BY DATE(sold_at) ORDER BY day ASC",
    $types, $params
);

$by_profile = db_fetch_all(
    "SELECT profile_id, profile_name, router_id, 
            (SELECT name FROM routers WHERE id = sales_log.router_id) AS router_name,
            COUNT(*) AS cnt, COALESCE(SUM(price),0) AS revenue
     FROM sales_log {$where_sql} 
     GROUP BY router_id, profile_id, profile_name 
     ORDER BY router_name ASC, revenue DESC",
    $types, $params
);

// Detail rows
$details = db_fetch_all(
    "SELECT sl.*, r.name AS router_name, a.username AS sold_by_name
     FROM sales_log sl
     LEFT JOIN routers r ON sl.router_id = r.id
     LEFT JOIN admins a ON sl.sold_by = a.id
     {$where_sql}
     ORDER BY sl.sold_at DESC LIMIT 500",
    $types, $params
);

include __DIR__ . '/../../include/header.php';
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Laporan Penjualan</h1>
        <p class="page-subtitle">Histori penjualan voucher per periode dan router</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if (current_admin()['role'] === 'superadmin'): ?>
        <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rebuildSalesModal">
            <i class="bi bi-arrow-repeat me-1"></i>Reset &amp; Hitung Ulang
        </button>
        <?php endif; ?>
        <a href="/index.php?page=report_export&type=sales&from=<?= urlencode($filter_from) ?>&to=<?= urlencode($filter_to) ?>&router_id=<?= $filter_router ?>"
           class="btn btn-outline-primary">
            <i class="bi bi-download me-1"></i>Export CSV
        </a>
    </div>
</div>

<!-- Filter -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <input type="hidden" name="page" value="report_sales">
            <div class="col-sm-auto">
                <label class="form-label">Dari</label>
                <input type="date" class="form-control form-control-sm" name="from" value="<?= $filter_from ?>">
            </div>
            <div class="col-sm-auto">
                <label class="form-label">Sampai</label>
                <input type="date" class="form-control form-control-sm" name="to" value="<?= $filter_to ?>">
            </div>
            <div class="col-sm-auto">
                <label class="form-label">Router</label>
                <select class="form-select form-select-sm" name="router_id">
                    <option value="">Semua Router</option>
                    <?php foreach ($all_routers as $r): ?>
                    <option value="<?= $r['id'] ?>" <?= $filter_router==$r['id']?'selected':'' ?>><?= htmlspecialchars($r['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-auto">
                <label class="form-label">Profil</label>
                <select class="form-select form-select-sm" name="profile_id">
                    <option value="">Semua Profil</option>
                    <?php foreach ($profiles as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= $filter_profile==$p['id']?'selected':'' ?>><?= htmlspecialchars($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-auto">
                <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                <a href="/index.php?page=report_sales" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card blue h-100">
            <div class="stat-icon"><i class="bi bi-ticket-perforated"></i></div>
            <div class="stat-value"><?= number_format($summary['cnt']) ?></div>
            <div class="stat-label">Voucher Terjual</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card red h-100">
            <div class="stat-icon"><i class="bi bi-cash-stack"></i></div>
            <div class="stat-value" style="font-size:1.3rem;"><?= format_price((float)$summary['total']) ?></div>
            <div class="stat-label">Total Pendapatan</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card green h-100">
            <div class="stat-icon"><i class="bi bi-calendar-range"></i></div>
            <div class="stat-value"><?= count($by_day) ?></div>
            <div class="stat-label">Hari Aktif</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card orange h-100">
            <div class="stat-icon"><i class="bi bi-graph-up"></i></div>
            <div class="stat-value" style="font-size:1.3rem;">
                <?= $summary['cnt'] > 0 ? format_price((float)$summary['total'] / $summary['cnt']) : 'Rp 0' ?>
            </div>
            <div class="stat-label">Rata-rata per Voucher</div>
        </div>
    </div>
</div>

<!-- Chart -->
<div class="card mb-4">
    <div class="card-header"><h5 class="card-title"><i class="bi bi-bar-chart"></i> Penjualan per Hari</h5></div>
    <div class="card-body"><canvas id="salesChart" height="120"></canvas></div>
</div>

<!-- Profile Breakdown & Detail -->
<div class="row g-4 mb-4">
    <div class="col-12 col-lg-5 d-flex flex-column gap-3">
        <h5 class="fw-bold mb-0 text-dark" style="font-size: 1.1rem;"><i class="bi bi-pie-chart text-primary me-1"></i> Penjualan per Profil</h5>
        
        <?php if (empty($by_profile)): ?>
        <div class="card"><div class="card-body text-center text-muted py-4">Belum ada data penjualan</div></div>
        <?php else: ?>
        <?php 
        // Group by router
        $profiles_by_router = [];
        foreach ($by_profile as $bp) {
            $rname = $bp['router_name'] ?: 'Tanpa Router';
            $profiles_by_router[$rname][] = $bp;
        }

        foreach ($profiles_by_router as $router_name => $profiles): 
            $max_rev = 0;
            foreach ($profiles as $p) { if ($p['revenue'] > $max_rev) $max_rev = $p['revenue']; }
        ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-bottom py-2">
                <h6 class="card-title mb-0" style="font-size:0.85rem; font-weight:700; color:var(--blue);">
                    <i class="bi bi-router"></i> <?= htmlspecialchars($router_name) ?>
                </h6>
            </div>
            <div class="card-body p-3">
                <?php 
                $count = count($profiles);
                foreach ($profiles as $i => $p): 
                    $pct = $max_rev > 0 ? ($p['revenue'] / $max_rev) * 100 : 0;
                    $is_last = ($i === $count - 1);
                ?>
                <div class="<?= $is_last ? '' : 'mb-3' ?>">
                    <div class="d-flex justify-content-between mb-1" style="font-size:0.82rem;">
                        <a href="?page=report_sales&profile_id=<?= $p['profile_id'] ?>&router_id=<?= $filter_router ?>&from=<?= urlencode($filter_from) ?>&to=<?= urlencode($filter_to) ?>" class="fw-bold text-decoration-none">
                            <?= htmlspecialchars($p['profile_name'] ?: 'Tanpa Profil') ?>
                        </a>
                        <div class="fw-bold" style="color:var(--orange);">
                            <?= format_price((float)$p['revenue']) ?> 
                            <span class="text-muted fw-normal ms-1">(<?= number_format($p['cnt']) ?> vcr)</span>
                        </div>
                    </div>
                    <div class="progress" style="height: 10px; border-radius: 5px; background-color: var(--bs-gray-200);">
                        <div class="progress-bar bg-primary" role="progressbar" style="width: <?= $pct ?>%; border-radius: 5px;"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <div class="col-12 col-lg-7">
        <div class="card table-card h-100">
    <div class="table-toolbar">
        <span class="fw-600">Detail Transaksi</span>
        <small class="text-muted">(maks. 500 baris)</small>
    </div>
    <div class="table-responsive">
        <table class="table" id="data-table">
            <thead><tr>
                <th>Waktu</th><th>Username Voucher</th><th>Profil</th><th>Router</th><th>Harga</th><th>Oleh</th><th class="text-end">Aksi</th>
            </tr></thead>
            <tbody>
            <?php if (empty($details)): ?>
            <tr><td colspan="7" class="text-center text-muted py-4">Tidak ada data</td></tr>
            <?php else: ?>
            <?php foreach ($details as $d): ?>
            <tr>
                <td style="font-size:.78rem;"><?= date('d/m/Y H:i', strtotime($d['sold_at'])) ?></td>
                <td class="font-mono fw-600"><?= htmlspecialchars($d['voucher_username']) ?></td>
                <td><?= htmlspecialchars($d['profile_name'] ?? '-') ?></td>
                <td><?= htmlspecialchars($d['router_name'] ?? 'Semua') ?></td>
                <td class="fw-600 text-red"><?= format_price((float)$d['price']) ?></td>
                <td class="text-muted"><?= htmlspecialchars($d['sold_by_name'] ?? '-') ?></td>
                <td class="text-end">
                    <a href="/index.php?page=report_sales_delete&id=<?= $d['id'] ?>" class="btn btn-sm btn-outline-danger" data-confirm="Hapus data penjualan ini secara permanen?" title="Hapus"><i class="bi bi-trash"></i></a>
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

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
(function() {
const bd = <?= json_encode($by_day) ?>;
const labels = bd.map(d => {
    const dt = new Date(d.day);
    return dt.toLocaleDateString('id-ID', { day:'numeric', month:'short' });
});
const cnts = bd.map(d => parseInt(d.cnt));
const revs = bd.map(d => parseFloat(d.revenue));

const canvas = document.getElementById('salesChart');
if (!canvas) return;
const ctxg = canvas.getContext('2d');

// Gradients
const revGrad = ctxg.createLinearGradient(0, 0, 0, 400);
revGrad.addColorStop(0, 'rgba(198,40,40,0.3)');
revGrad.addColorStop(1, 'rgba(198,40,40,0)');

const barGrad = ctxg.createLinearGradient(0, 0, 0, 400);
barGrad.addColorStop(0, 'rgba(21,101,192,0.95)');
barGrad.addColorStop(1, 'rgba(21,101,192,0.3)');

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
                borderColor: 'rgba(0,0,0,0)',
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
                pointHoverRadius: 8,
                tension: 0.45,
                fill: true,
                yAxisID: 'y2',
                order: 1,
            }
        ],
    },
    options: {
        responsive: true,
        interaction: { mode: 'index', intersect: false },
        animation: { duration: 900, easing: 'easeInOutQuart' },
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
                borderColor: isDark ? 'rgba(255,255,255,.1)' : 'rgba(0,0,0,.1)',
                borderWidth: 1,
                padding: 12,
                cornerRadius: 10,
                boxPadding: 4,
                titleFont: { size: 12, weight: '700' },
                bodyFont:  { size: 12 },
                callbacks: {
                    label: function(ctx) {
                        if (ctx.dataset.label.includes('Pendapatan')) {
                            return ' Pendapatan: Rp ' + parseInt(ctx.parsed.y).toLocaleString('id-ID');
                        }
                        return ' Terjual: ' + ctx.parsed.y + ' voucher';
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
})();
</script>

<?php if (current_admin()['role'] === 'superadmin'): ?>
<!-- Modal Reset & Hitung Ulang Pendapatan -->
<div class="modal fade" id="rebuildSalesModal" tabindex="-1" aria-labelledby="rebuildSalesModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="rebuildSalesModalLabel"><i class="bi bi-exclamation-triangle-fill me-2"></i>Reset &amp; Hitung Ulang Pendapatan</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="/index.php?page=rebuild_sales">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <div class="modal-body p-4">
                    <div class="alert alert-warning mb-3">
                        <h6 class="fw-bold mb-1"><i class="bi bi-info-circle-fill me-1"></i> Apa yang akan diproses?</h6>
                        <ul class="mb-0 ps-3 mt-2" style="font-size: 0.88rem;">
                            <li><strong>Kosongkan Data Lama:</strong> Menghapus riwayat penjualan voucher (<code>sales_log</code>) dan riwayat tagihan reseller (<code>penagihan</code>) yang amburadul / duplikat.</li>
                            <li><strong>Cek Sesi FreeRADIUS:</strong> Menyelaraskan status voucher dengan riwayat login asli di <code>radacct</code>.</li>
                            <li><strong>Hitung Ulang Murni:</strong> Membangun kembali 1 transaksi penjualan bersih untuk setiap voucher yang benar-benar aktif/terpakai.</li>
                            <li><strong>Dashboard &amp; Laporan:</strong> Otomatis menampilkan omset bersih dan valid tanpa duplikasi.</li>
                        </ul>
                    </div>

                    <div class="form-check p-3 border rounded bg-light">
                        <input class="form-check-input ms-0 me-2" type="checkbox" id="confirmRebuildCheck" required>
                        <label class="form-check-label text-dark fw-semibold" for="confirmRebuildCheck" style="font-size: 0.9rem;">
                            Saya memahami dan setuju untuk mereset riwayat lama dan menghitung ulang pendapatan dari awal.
                        </label>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-danger"><i class="bi bi-arrow-repeat me-1"></i>Ya, Hitung Ulang Sekarang</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../include/footer.php'; ?>
