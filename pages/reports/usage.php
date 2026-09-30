<?php
/**
 * Reports — Monthly Data Usage Report (from radacct)
 */
$page_title = 'Laporan Pemakaian Data';
$all_routers = get_all_routers();
$filter_router = (int)get('router_id');

// Mode: 'month' (default bulanan) atau 'range' (rentang tanggal)
$mode = get('mode', (isset($_GET['from']) && !isset($_GET['month'])) ? 'range' : 'month');

// Filter Bulan (Default Bulan Ini)
$filter_month = get('month', date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $filter_month)) {
    $filter_month = date('Y-m');
}
list($filter_year_str, $filter_m_str) = explode('-', $filter_month);
$filter_year = (int)$filter_year_str;
$filter_m    = (int)$filter_m_str;
$is_curr_m   = ($filter_month === date('Y-m'));

$mIndo = [1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',5=>'Mei',6=>'Juni',7=>'Juli',8=>'Agustus',9=>'September',10=>'Oktober',11=>'November',12=>'Desember'];

// Generate 12 opsi pilihan bulan
$month_options = [];
for ($i = 0; $i < 12; $i++) {
    $time = strtotime("-$i months");
    $val = date('Y-m', $time);
    $moNum = (int)date('n', $time);
    $yrNum = date('Y', $time);
    $lbl = ($i === 0 ? 'Bulan Ini (' : '') . ($mIndo[$moNum] ?? '') . ' ' . $yrNum . ($i === 0 ? ')' : '');
    $month_options[$val] = $lbl;
}

// Navigasi bulan sebelumnya dan berikutnya
$prev_month = date('Y-m', strtotime("$filter_month-01 -1 month"));
$next_month = date('Y-m', strtotime("$filter_month-01 +1 month"));
$selected_month_label = $month_options[$filter_month] ?? (($mIndo[$filter_m] ?? '') . ' ' . $filter_year);

if ($mode === 'month') {
    $filter_from = sprintf('%04d-%02d-01', $filter_year, $filter_m);
    $last_day_of_month = (int)date('t', strtotime($filter_from));
    $filter_to   = sprintf('%04d-%02d-%02d', $filter_year, $filter_m, $last_day_of_month);
} else {
    $filter_from = get('from', date('Y-m-01'));
    $filter_to   = get('to', date('Y-m-t'));
    $last_day_of_month = (int)date('t', strtotime($filter_from));
}

// Konstruksi WHERE SQL untuk radacct
$where  = [];
$params = [];
$types  = '';

if ($mode === 'month') {
    $start_dt = "$filter_from 00:00:00";
    $end_dt   = "$filter_to 23:59:59";
    if ($is_curr_m) {
        $where[] = "((ra.acctstarttime >= ? AND ra.acctstarttime <= ?) OR (ra.acctstoptime >= ? AND ra.acctstoptime <= ?) OR (ra.acctstoptime IS NULL))";
        $params[] = $start_dt;
        $params[] = $end_dt;
        $params[] = $start_dt;
        $params[] = $end_dt;
        $types   .= 'ssss';
    } else {
        $where[] = "((ra.acctstarttime >= ? AND ra.acctstarttime <= ?) OR (ra.acctstoptime >= ? AND ra.acctstoptime <= ?))";
        $params[] = $start_dt;
        $params[] = $end_dt;
        $params[] = $start_dt;
        $params[] = $end_dt;
        $types   .= 'ssss';
    }
} else {
    $where[] = "DATE(ra.acctstarttime) BETWEEN ? AND ?";
    $params[] = $filter_from;
    $params[] = $filter_to;
    $types   .= 'ss';
}

if ($filter_router > 0) {
    $r = db_fetch_one("SELECT ip_address, nas_ip FROM routers WHERE id = ?", 'i', [$filter_router]);
    if ($r) {
        $ips = array_unique(array_filter([$r['ip_address'], $r['nas_ip']]));
        if (count($ips) === 2) {
            $where[] = "(ra.nasipaddress = ? OR ra.nasipaddress = ?)";
            $params[] = $ips[0];
            $params[] = $ips[1];
            $types .= "ss";
        } elseif (count($ips) === 1) {
            $where[] = "ra.nasipaddress = ?";
            $params[] = reset($ips);
            $types .= "s";
        }
    }
}

$where_sql = 'WHERE ' . implode(' AND ', $where);

// Ringkasan Akumulasi Pemakaian Bulan Ini
$summary = db_fetch_one(
    "SELECT COUNT(*) AS sessions,
            COUNT(DISTINCT ra.username) AS total_users,
            COALESCE(SUM(ra.acctsessiontime), 0) AS total_secs,
            COALESCE(SUM(ra.acctoutputoctets), 0) AS total_dl,
            COALESCE(SUM(ra.acctinputoctets), 0) AS total_ul,
            COALESCE(SUM(ra.acctoutputoctets + ra.acctinputoctets), 0) AS total_bytes
     FROM radacct ra {$where_sql}",
    $types, $params
) ?? ['sessions'=>0, 'total_users'=>0, 'total_secs'=>0, 'total_dl'=>0, 'total_ul'=>0, 'total_bytes'=>0];

// Top Pengguna Terbanyak di Bulan Terpilih
$top_users = db_fetch_all(
    "SELECT ra.username, COUNT(*) AS sessions,
            COALESCE(SUM(ra.acctsessiontime), 0) AS total_secs,
            COALESCE(SUM(ra.acctoutputoctets), 0) AS dl,
            COALESCE(SUM(ra.acctinputoctets), 0) AS ul,
            COALESCE(SUM(ra.acctoutputoctets + ra.acctinputoctets), 0) AS total,
            ra.nasipaddress,
            COALESCE(r.name, ra.nasipaddress) AS router_name,
            pc.full_name AS customer_name,
            pc.profile AS customer_profile,
            pc.status AS customer_status
     FROM radacct ra
     LEFT JOIN routers r ON (r.ip_address = ra.nasipaddress OR r.nas_ip = ra.nasipaddress)
     LEFT JOIN pppoe_customers pc ON pc.pppoe_username = ra.username
     {$where_sql}
     GROUP BY ra.username, ra.nasipaddress
     ORDER BY total DESC
     LIMIT 250",
    $types, $params
);

$max_user_bytes = (!empty($top_users) && (float)$top_users[0]['total'] > 0) ? (float)$top_users[0]['total'] : 1;

// Grafik Harian Pemakaian Data (Chart.js)
$chart_labels = [];
$chart_dl = [];
$chart_ul = [];
$chart_total = [];

if ($mode === 'month') {
    $daily_stats = db_fetch_all(
        "SELECT DATE(ra.acctstarttime) AS usage_date,
                COALESCE(SUM(ra.acctoutputoctets), 0) AS dl,
                COALESCE(SUM(ra.acctinputoctets), 0) AS ul,
                COALESCE(SUM(ra.acctoutputoctets + ra.acctinputoctets), 0) AS total
         FROM radacct ra
         {$where_sql}
         GROUP BY usage_date
         ORDER BY usage_date ASC",
        $types, $params
    );
    $daily_map = [];
    foreach ($daily_stats as $ds) {
        $daily_map[$ds['usage_date']] = $ds;
    }
    for ($d = 1; $d <= $last_day_of_month; $d++) {
        $dtKey = sprintf('%04d-%02d-%02d', $filter_year, $filter_m, $d);
        $chart_labels[] = sprintf('%02d %s', $d, substr($mIndo[$filter_m] ?? '', 0, 3));
        $dl_gb = round(($daily_map[$dtKey]['dl'] ?? 0) / (1024 * 1024 * 1024), 2);
        $ul_gb = round(($daily_map[$dtKey]['ul'] ?? 0) / (1024 * 1024 * 1024), 2);
        $tot_gb = round(($daily_map[$dtKey]['total'] ?? 0) / (1024 * 1024 * 1024), 2);
        $chart_dl[] = $dl_gb;
        $chart_ul[] = $ul_gb;
        $chart_total[] = $tot_gb;
    }
}

function format_durasi_clean(int $secs): string {
    if ($secs <= 0) return '0 Menit';
    $d = intdiv($secs, 86400);
    $h = intdiv($secs % 86400, 3600);
    $m = intdiv($secs % 3600, 60);
    $parts = [];
    if ($d) $parts[] = "{$d} Hari";
    if ($h) $parts[] = "{$h} Jam";
    if ($m) $parts[] = "{$m} Menit";
    return implode(' ', $parts) ?: '< 1 Menit';
}

include __DIR__ . '/../../include/header.php';
?>

<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="page-title"><i class="bi bi-bar-chart-line text-primary me-2"></i>Laporan Pemakaian Data</h1>
        <p class="page-subtitle mb-0">Akumulasi statistik pemakaian data bulanan dari FreeRADIUS (radacct)</p>
    </div>
    <div class="d-flex gap-2">
        <a href="/index.php?page=report_export&type=radacct&month=<?= urlencode($filter_month) ?>&from=<?= urlencode($filter_from) ?>&to=<?= urlencode($filter_to) ?>&router_id=<?= $filter_router ?>"
           class="btn btn-outline-primary btn-sm d-flex align-items-center gap-1 shadow-sm">
            <i class="bi bi-download"></i> Export CSV
        </a>
    </div>
</div>

<!-- FILTER CARD (MODE BULANAN & RENTANG TANGGAL) -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-3">
        <form method="GET" class="row g-2 align-items-center">
            <input type="hidden" name="page" value="report_usage">
            <input type="hidden" name="mode" id="filter_mode_input" value="<?= htmlspecialchars($mode) ?>">

            <?php if ($mode === 'month'): ?>
            <!-- Mode Pilihan Bulan -->
            <div class="col-12 col-md-auto d-flex align-items-center gap-1">
                <a href="/index.php?page=report_usage&mode=month&month=<?= $prev_month ?>&router_id=<?= $filter_router ?>" class="btn btn-outline-secondary btn-sm" title="Bulan Sebelumnya">
                    <i class="bi bi-chevron-left"></i>
                </a>
                <select name="month" class="form-select form-select-sm fw-bold border-primary shadow-none" style="min-width: 190px;" onchange="this.form.submit()">
                    <?php foreach ($month_options as $mVal => $mLbl): ?>
                    <option value="<?= $mVal ?>" <?= $filter_month === $mVal ? 'selected' : '' ?>>
                        📅 <?= htmlspecialchars($mLbl) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($filter_month !== date('Y-m')): ?>
                <a href="/index.php?page=report_usage&mode=month&month=<?= $next_month ?>&router_id=<?= $filter_router ?>" class="btn btn-outline-secondary btn-sm" title="Bulan Berikutnya">
                    <i class="bi bi-chevron-right"></i>
                </a>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <!-- Mode Rentang Tanggal Manual -->
            <div class="col-6 col-md-auto">
                <label class="form-label small text-muted mb-0">Dari</label>
                <input type="date" class="form-control form-control-sm" name="from" value="<?= htmlspecialchars($filter_from) ?>">
            </div>
            <div class="col-6 col-md-auto">
                <label class="form-label small text-muted mb-0">Sampai</label>
                <input type="date" class="form-control form-control-sm" name="to" value="<?= htmlspecialchars($filter_to) ?>">
            </div>
            <?php endif; ?>

            <!-- Filter Router -->
            <div class="col-12 col-md-auto">
                <select class="form-select form-select-sm shadow-none" name="router_id" onchange="this.form.submit()">
                    <option value="">🌐 Semua Router</option>
                    <?php foreach ($all_routers as $r): ?>
                    <option value="<?= $r['id'] ?>" <?= $filter_router == $r['id'] ? 'selected' : '' ?>><?= htmlspecialchars($r['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Tombol Aksi & Toggle Mode -->
            <div class="col-auto ms-auto d-flex align-items-center gap-2">
                <?php if ($mode === 'range'): ?>
                <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                <a href="/index.php?page=report_usage&mode=month&month=<?= date('Y-m') ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-calendar-month me-1"></i>Pilih Mode Bulanan
                </a>
                <?php else: ?>
                <a href="/index.php?page=report_usage&mode=range&from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-d') ?>&router_id=<?= $filter_router ?>" class="btn btn-outline-secondary btn-sm" title="Ganti ke input rentang tanggal bebas">
                    <i class="bi bi-calendar3 me-1"></i>Rentang Bebas
                </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- KARTU STATISTIK PEMAKAIAN (PERIODE BULAN TERPILIH) -->
<div class="row g-3 mb-4">
    <!-- Total Pemakaian (Download + Upload) -->
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100 position-relative overflow-hidden" style="background: linear-gradient(135deg, #1E3A8A 0%, #3B82F6 100%); color: white; border-radius: 12px;">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="text-uppercase fw-semibold" style="font-size: 0.72rem; opacity: 0.85; letter-spacing: 0.5px;">Total Pemakaian (Tx+Rx)</div>
                        <div class="h2 fw-bold mb-1 mt-1 font-mono"><?= format_bytes((float)$summary['total_bytes']) ?></div>
                        <div class="small opacity-75" style="font-size: 0.75rem;">
                            Periode: <strong><?= htmlspecialchars($mode === 'month' ? $selected_month_label : date('d M', strtotime($filter_from)) . ' - ' . date('d M Y', strtotime($filter_to))) ?></strong>
                        </div>
                    </div>
                    <div style="font-size: 2rem; opacity: 0.35;"><i class="bi bi-activity"></i></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Total Download (Tx) -->
    <div class="col-6 col-sm-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background: #F0FDF4; border-left: 4px solid #16A34A !important;">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-uppercase fw-bold text-success" style="font-size: 0.72rem;">Total Download (Tx)</span>
                    <i class="bi bi-arrow-down-circle-fill text-success fs-5"></i>
                </div>
                <div class="h3 fw-bold text-dark font-mono mb-1"><?= format_bytes((float)$summary['total_dl']) ?></div>
                <div class="text-muted" style="font-size: 0.75rem;">Bandwidth keluar ke pelanggan</div>
            </div>
        </div>
    </div>

    <!-- Total Upload (Rx) -->
    <div class="col-6 col-sm-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background: #EFF6FF; border-left: 4px solid #2563EB !important;">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-uppercase fw-bold text-primary" style="font-size: 0.72rem;">Total Upload (Rx)</span>
                    <i class="bi bi-arrow-up-circle-fill text-primary fs-5"></i>
                </div>
                <div class="h3 fw-bold text-dark font-mono mb-1"><?= format_bytes((float)$summary['total_ul']) ?></div>
                <div class="text-muted" style="font-size: 0.75rem;">Bandwidth masuk dari pelanggan</div>
            </div>
        </div>
    </div>

    <!-- Waktu Online & Sesi -->
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background: #FFFBEB; border-left: 4px solid #D97706 !important;">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-uppercase fw-bold text-warning" style="font-size: 0.72rem;">Sesi &amp; Waktu Online</span>
                    <i class="bi bi-clock-history text-warning fs-5"></i>
                </div>
                <div class="h4 fw-bold text-dark mb-1"><?= format_durasi_clean((int)$summary['total_secs']) ?></div>
                <div class="text-muted" style="font-size: 0.75rem;">
                    <strong><?= number_format($summary['sessions']) ?></strong> Sesi &bull; <strong><?= number_format($summary['total_users']) ?></strong> Klien Terdata
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($mode === 'month'): ?>
<!-- GRAFIK TREN PEMAKAIAN HARIAN DALAM BULAN -->
<div class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
    <div class="card-header bg-white border-light-subtle py-3 d-flex justify-content-between align-items-center">
        <h6 class="card-title mb-0 fw-bold text-dark">
            <i class="bi bi-graph-up text-primary me-2"></i>Tren Pemakaian Data Harian (Bulan: <?= htmlspecialchars($selected_month_label) ?>)
        </h6>
        <span class="badge bg-primary-subtle text-primary border border-primary-subtle">Satuan: Gigabyte (GB)</span>
    </div>
    <div class="card-body p-3">
        <div style="position: relative; height: 260px; width: 100%;">
            <canvas id="chartDailyUsage"></canvas>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- TABEL TOP PENGGUNA PEMAKAIAN DATA -->
<div class="card border-0 shadow-sm" style="border-radius: 12px;">
    <div class="card-header bg-white border-light-subtle py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h6 class="card-title mb-0 fw-bold text-dark">
                <i class="bi bi-people-fill text-primary me-2"></i>Peringkat Pengguna Terbanyak (Bulan: <?= htmlspecialchars($selected_month_label) ?>)
            </h6>
            <span class="text-muted small">Menampilkan data teratas diurutkan berdasarkan Total Pemakaian (Download + Upload)</span>
        </div>
        <div class="d-flex align-items-center gap-2">
            <input type="text" id="filterUserTableInput" class="form-control form-control-sm" placeholder="Cari nama / username..." style="max-width: 220px;" onkeyup="filterUserTable()">
            <span class="badge bg-light text-dark border"><?= count($top_users) ?> Pengguna</span>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="userUsageTable">
            <thead class="table-light">
                <tr style="font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.5px;">
                    <th style="width: 45px;" class="text-center">#</th>
                    <th>Username / Pelanggan</th>
                    <th>Router &amp; IP</th>
                    <th class="text-center">Sesi</th>
                    <th>Waktu Online</th>
                    <th class="text-end">Download (Tx)</th>
                    <th class="text-end">Upload (Rx)</th>
                    <th class="text-end" style="min-width: 170px;">Total Pemakaian</th>
                    <th class="text-center" style="width: 50px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($top_users)): ?>
                <tr>
                    <td colspan="9" class="text-center py-5 text-muted">
                        <i class="bi bi-inbox fs-1 d-block mb-2 text-secondary"></i>
                        Tidak ada data pemakaian tercatat pada periode <strong><?= htmlspecialchars($selected_month_label) ?></strong>.
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($top_users as $idx => $u): 
                    $uTotal = (float)$u['total'];
                    $uDl = (float)$u['dl'];
                    $uUl = (float)$u['ul'];
                    $pct = min(100, max(2, round(($uTotal / $max_user_bytes) * 100)));
                ?>
                <tr class="user-row">
                    <td class="text-center fw-bold text-muted" style="font-size: 0.85rem;"><?= $idx + 1 ?></td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div style="width: 32px; height: 32px; border-radius: 8px; background: #e0e7ff; color: #4338ca; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 0.85rem;">
                                <?= strtoupper(substr($u['username'], 0, 1)) ?>
                            </div>
                            <div>
                                <?php if (!empty($u['customer_name'])): ?>
                                <div class="fw-bold text-dark user-search-field" style="font-size: 0.88rem;">
                                    <?= htmlspecialchars($u['customer_name']) ?>
                                </div>
                                <div class="text-muted font-mono user-search-field" style="font-size: 0.75rem;">
                                    <?= htmlspecialchars($u['username']) ?> 
                                    <?php if (!empty($u['customer_profile'])): ?>
                                    &bull; <span class="badge bg-light text-secondary border py-0" style="font-size:10px;"><?= htmlspecialchars($u['customer_profile']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php else: ?>
                                <div class="fw-bold font-mono text-dark user-search-field" style="font-size: 0.88rem;">
                                    <?= htmlspecialchars($u['username']) ?>
                                </div>
                                <div class="text-muted" style="font-size: 0.72rem;">
                                    <span class="badge bg-secondary-subtle text-secondary py-0" style="font-size:10px;">Hotspot / Voucher</span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div class="fw-semibold text-dark" style="font-size: 0.82rem;"><?= htmlspecialchars($u['router_name']) ?></div>
                        <div class="text-muted font-mono" style="font-size: 0.72rem;"><?= htmlspecialchars($u['nasipaddress']) ?></div>
                    </td>
                    <td class="text-center">
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2"><?= $u['sessions'] ?></span>
                    </td>
                    <td style="font-size: 0.82rem;">
                        <i class="bi bi-clock text-muted me-1"></i><?= format_durasi_clean((int)$u['total_secs']) ?>
                    </td>
                    <td class="text-end font-mono fw-semibold text-success" style="font-size: 0.85rem;">
                        ↓ <?= format_bytes($uDl) ?>
                    </td>
                    <td class="text-end font-mono fw-semibold text-primary" style="font-size: 0.85rem;">
                        ↑ <?= format_bytes($uUl) ?>
                    </td>
                    <td class="text-end">
                        <div class="fw-bold text-dark font-mono" style="font-size: 0.9rem;"><?= format_bytes($uTotal) ?></div>
                        <div class="progress mt-1" style="height: 4px; background: #e2e8f0;" title="<?= $pct ?>% dari pemakaian tertinggi">
                            <div class="progress-bar bg-primary" role="progressbar" style="width: <?= $pct ?>%;"></div>
                        </div>
                    </td>
                    <td class="text-center">
                        <?php if (current_admin()['role'] === 'superadmin'): ?>
                        <a href="/index.php?page=report_usage_delete&username=<?= urlencode($u['username']) ?>&nasipaddress=<?= urlencode($u['nasipaddress']) ?>&month=<?= urlencode($filter_month) ?>&from=<?= urlencode($filter_from) ?>&to=<?= urlencode($filter_to) ?>"
                           class="btn btn-sm btn-link text-danger p-0"
                           onclick="return confirm('Hapus seluruh riwayat pemakaian sesi untuk user \'<?= htmlspecialchars($u['username']) ?>\' di periode ini?')"
                           title="Hapus Data Sesi User">
                            <i class="bi bi-trash"></i>
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($mode === 'month'): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('chartDailyUsage');
    if (!ctx) return;

    const labels = <?= json_encode($chart_labels) ?>;
    const dataDl = <?= json_encode($chart_dl) ?>;
    const dataUl = <?= json_encode($chart_ul) ?>;

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Download (GB)',
                    data: dataDl,
                    backgroundColor: 'rgba(34, 197, 94, 0.75)',
                    borderColor: 'rgb(34, 197, 94)',
                    borderWidth: 1,
                    borderRadius: 4,
                },
                {
                    label: 'Upload (GB)',
                    data: dataUl,
                    backgroundColor: 'rgba(59, 130, 246, 0.75)',
                    borderColor: 'rgb(59, 130, 246)',
                    borderWidth: 1,
                    borderRadius: 4,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false
            },
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        boxWidth: 12,
                        font: { size: 12, weight: 'bold' }
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.dataset.label + ': ' + context.parsed.y + ' GB';
                        },
                        footer: function(tooltipItems) {
                            let sum = 0;
                            tooltipItems.forEach(function(tooltipItem) {
                                sum += tooltipItem.parsed.y;
                            });
                            return 'Total: ' + sum.toFixed(2) + ' GB';
                        }
                    }
                }
            },
            scales: {
                x: {
                    stacked: true,
                    grid: { display: false },
                    ticks: { font: { size: 11 } }
                },
                y: {
                    stacked: true,
                    beginAtZero: true,
                    ticks: {
                        callback: function(val) { return val + ' GB'; },
                        font: { size: 11 }
                    },
                    grid: { color: 'rgba(0, 0, 0, 0.05)' }
                }
            }
        }
    });
});
</script>
<?php endif; ?>

<script>
function filterUserTable() {
    const filter = document.getElementById('filterUserTableInput').value.toLowerCase();
    const rows = document.querySelectorAll('#userUsageTable tbody tr.user-row');
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(filter) ? '' : 'none';
    });
}
</script>

<?php include __DIR__ . '/../../include/footer.php'; ?>
