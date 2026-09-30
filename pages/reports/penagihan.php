<?php
/**
 * Laporan Penagihan
 */
$page_title = 'Laporan Penagihan';
$admin = current_admin();

// Fetch routers for dropdown
$all_routers = get_all_routers();

// Filter date range
$filter_start = get('start_date', date('Y-m-01'));
$filter_end   = get('end_date', date('Y-m-d'));
$router_filter = (int)get('router_id');

// Build query for history
$sql_history = "SELECT p.*, r.name as router_name, pr.name as profile_name, a.full_name as admin_name 
                FROM penagihan p 
                JOIN routers r ON p.router_id = r.id 
                JOIN profiles pr ON p.profile_id = pr.id 
                LEFT JOIN admins a ON p.ditagih_oleh = a.id 
                WHERE p.tanggal BETWEEN ? AND ?";
$types_history = 'ss';
$params_history = [$filter_start, $filter_end];

if ($router_filter > 0) {
    $sql_history .= " AND p.router_id = ?";
    $types_history .= 'i';
    $params_history[] = $router_filter;
}
$sql_history .= " ORDER BY p.created_at DESC";

$history = db_fetch_all($sql_history, $types_history, $params_history);

// Calculate totals
$total_kotor = 0;
$total_bersih = 0;
$total_transaksi = count($history);

$totals_per_router = [];
// Initialize all routers so they show up even if revenue is 0
foreach ($all_routers as $r) {
    $totals_per_router[$r['name']] = ['kotor' => 0, 'bersih' => 0, 'transaksi' => 0];
}

foreach ($history as $h) {
    $total_kotor += (float)$h['total_pendapatan'];
    $total_bersih += (float)$h['pendapatan_bersih'];
    
    $rname = $h['router_name'] ?: 'Tanpa Router';
    if (!isset($totals_per_router[$rname])) {
        $totals_per_router[$rname] = ['kotor' => 0, 'bersih' => 0, 'transaksi' => 0];
    }
    $totals_per_router[$rname]['kotor'] += (float)$h['total_pendapatan'];
    $totals_per_router[$rname]['bersih'] += (float)$h['pendapatan_bersih'];
    $totals_per_router[$rname]['transaksi']++;
}

include __DIR__ . '/../../include/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><i class="bi bi-wallet2 text-primary me-2"></i> Laporan Penagihan</h1>
        <p class="page-subtitle">Input pendapatan tagihan reseller per cabang — sistem hitung otomatis</p>
    </div>
    <?php if (current_admin()['role'] === 'superadmin'): ?>
    <div>
        <a href="/index.php?page=report_sales" class="btn btn-outline-danger btn-sm">
            <i class="bi bi-arrow-repeat me-1"></i>Reset &amp; Hitung Ulang Penjualan
        </a>
    </div>
    <?php endif; ?>
</div>

<!-- Header Statistik -->
<div class="card mb-4">
    <div class="card-body p-3">
        <form method="GET" action="/index.php" class="d-flex align-items-end gap-3 flex-wrap">
            <input type="hidden" name="page" value="penagihan_report">
            <div>
                <label class="form-label" style="font-size:.8rem;font-weight:600">Statistik Per Cabang: Dari</label>
                <input type="date" class="form-control form-control-sm" name="start_date" value="<?= htmlspecialchars($filter_start) ?>">
            </div>
            <div>
                <label class="form-label" style="font-size:.8rem;font-weight:600">s/d</label>
                <input type="date" class="form-control form-control-sm" name="end_date" value="<?= htmlspecialchars($filter_end) ?>">
            </div>
            <div>
                <label class="form-label" style="font-size:.8rem;font-weight:600">Cabang</label>
                <select class="form-select form-select-sm" name="router_id">
                    <option value="">Semua Cabang</option>
                    <?php foreach ($all_routers as $r): ?>
                    <option value="<?= $r['id'] ?>" <?= $router_filter == $r['id'] ? 'selected' : '' ?>><?= htmlspecialchars($r['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search me-1"></i> Tampilkan</button>
            </div>
        </form>
        
        <div class="row g-3 mt-2">
            <!-- Totals Utama -->
            <div class="col-md-6 col-lg-4">
                <div class="p-3 rounded stat-card-kotor border h-100">
                    <div class="text-uppercase fw-bold text-muted mb-1" style="font-size:0.75rem;"><i class="bi bi-wallet2 me-1"></i> TOTAL SEMUA CABANG (KOTOR)</div>
                    <div class="fs-4 fw-bold stat-value-kotor"><?= format_price($total_kotor) ?></div>
                    <div class="text-muted" style="font-size:0.85rem;"><?= $total_transaksi ?> transaksi penagihan</div>
                </div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="p-3 rounded stat-card-bersih border h-100">
                    <div class="text-uppercase fw-bold text-muted mb-1" style="font-size:0.75rem;"><i class="bi bi-piggy-bank me-1"></i> TOTAL BERSIH PERUSAHAAN</div>
                    <div class="fs-4 fw-bold text-success"><?= format_price($total_bersih) ?></div>
                    <div class="text-muted" style="font-size:0.85rem;">Setelah dipotong bagian reseller</div>
                </div>
            </div>
            
            <?php if (empty($router_filter)): ?>
            <div class="col-12 mt-4 mb-2">
                <h6 class="fw-bold text-dark text-uppercase" style="font-size:0.85rem;"><i class="bi bi-building me-1"></i> Rincian Per Cabang</h6>
            </div>
            
            <?php foreach ($totals_per_router as $rname => $rtot): ?>
            <div class="col-md-6 col-lg-3">
                <div class="card h-100 shadow-sm border-0">
                    <div class="card-header bg-light py-2 border-bottom-0">
                        <div class="fw-bold text-dark" style="font-size:0.8rem;"><i class="bi bi-router"></i> <?= htmlspecialchars($rname) ?></div>
                    </div>
                    <div class="card-body p-3">
                        <div class="mb-2">
                            <div class="text-muted" style="font-size:0.7rem; font-weight:600; text-transform:uppercase;">Pendapatan Kotor</div>
                            <div class="fw-bold text-primary" style="font-size:1.1rem;"><?= format_price($rtot['kotor']) ?></div>
                        </div>
                        <div>
                            <div class="text-muted" style="font-size:0.7rem; font-weight:600; text-transform:uppercase;">Bersih Perusahaan</div>
                            <div class="fw-bold text-success" style="font-size:1.1rem;"><?= format_price($rtot['bersih']) ?></div>
                        </div>
                    </div>
                    <div class="card-footer bg-white border-0 pt-0 pb-3 text-muted" style="font-size:0.75rem;">
                        <?= $rtot['transaksi'] ?> transaksi
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Form Penagihan Baru -->
<div class="card mb-4 shadow-sm border-0">
    <div class="card-header bg-white border-bottom-0 pt-4 pb-0">
        <h5 class="fw-bold"><i class="bi bi-pencil-square text-warning me-2"></i> Input Penagihan Baru</h5>
    </div>
    <div class="card-body">
        <form id="formPenagihan" method="POST" action="/process/save_penagihan.php">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            
            <!-- Step 1: Cabang -->
            <div class="p-3 mb-3 rounded step-box-1">
                <h6 class="fw-bold mb-3"><span class="badge bg-success rounded-circle me-2">1</span> Pilih Cabang</h6>
                <div class="mb-2">
                    <label class="form-label" style="font-size:.8rem;font-weight:600">NAMA CABANG *</label>
                    <select class="form-select" name="router_id" id="selectRouter" required>
                        <option value="">— Pilih Cabang —</option>
                        <?php foreach ($all_routers as $r): ?>
                        <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Step 2: Reseller -->
            <div class="p-3 mb-3 rounded step-box-2">
                <h6 class="fw-bold mb-3"><span class="badge bg-primary rounded-circle me-2">2</span> Pilih Reseller</h6>
                <div class="mb-2">
                    <label class="form-label" style="font-size:.8rem;font-weight:600">NAMA RESELLER *</label>
                    <select class="form-select" name="profile_id" id="selectProfile" required disabled>
                        <option value="">— Pilih Cabang Terlebih Dahulu —</option>
                    </select>
                </div>
                <div id="resellerInfo" class="alert alert-success py-2 px-3 mt-2 mb-0 d-none" style="font-size:.85rem;">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div><i class="bi bi-check-circle-fill me-1"></i> <span id="resellerName" class="fw-bold"></span> — Keuntungan: <span id="resellerPercent" class="fw-bold"></span>% · <span id="resellerPrice" class="fw-bold"></span>/voucher</div>
                        <div id="resellerTekorCount" class="badge bg-danger d-none"><i class="bi bi-exclamation-triangle-fill me-1"></i> <span id="tekorCountNum">0</span>x Riwayat Tekor</div>
                    </div>
                    
                    <div class="row g-2 mt-2 pt-2 border-top border-success-subtle text-dark" style="font-size:0.8rem;">
                        <div class="col-sm-6 col-md-3">
                            <span class="text-muted d-block" style="font-size:0.7rem; text-transform:uppercase;">Tagih Terakhir</span>
                            <span class="fw-bold" id="resellerLastBilled">-</span>
                        </div>
                        <div class="col-sm-6 col-md-3">
                            <span class="text-muted d-block" style="font-size:0.7rem; text-transform:uppercase;">Voucher Laku Baru</span>
                            <span class="fw-bold text-primary" id="resellerVcrBaru">0 vcr</span>
                        </div>
                        <div class="col-sm-6 col-md-3">
                            <span class="text-muted d-block" style="font-size:0.7rem; text-transform:uppercase;">Sisa/Tunggakan Lalu</span>
                            <span class="fw-bold" id="resellerSisaPrev">0 vcr</span>
                        </div>
                        <div class="col-sm-6 col-md-3">
                            <span class="text-muted d-block" style="font-size:0.7rem; text-transform:uppercase;">Total Target Tagih</span>
                            <span class="fw-bold text-success" id="resellerTargetTotal">0 vcr</span>
                        </div>
                    </div>

                    <div id="boxIgnorePrevious" class="mt-2 pt-2 border-top border-success-subtle d-none">
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" name="ignore_previous" id="checkIgnorePrevious" value="1">
                            <label class="form-check-label text-dark fw-bold" for="checkIgnorePrevious" style="font-size:0.8rem;">
                                Abaikan sisa tagihan sebelumnya <small class="text-muted fw-normal">(Hitung bersih hanya voucher baru yang laku periode ini)</small>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Step 3: Input & Calc -->
            <div class="p-3 mb-3 rounded step-box-3">
                <h6 class="fw-bold mb-3"><span class="badge bg-purple rounded-circle me-2" style="background-color:#6f42c1">3</span> Isi Total Pendapatan</h6>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" style="font-size:.8rem;font-weight:600">TOTAL PENDAPATAN (RP) *</label>
                        <input type="number" class="form-control fw-bold fs-5" name="total_pendapatan" id="inputTotal" placeholder="Contoh: 500000" min="0" required>
                    </div>
                    <div class="col-md-6">
                        <label id="catatanLabel" class="form-label" style="font-size:.8rem;font-weight:600" for="catatan">CATATAN (opsional)</label>
                        <input type="text" id="catatan" class="form-control" name="catatan" placeholder="Keterangan penagihan...">
                    </div>
                </div>
            </div>

            <!-- Result Box -->
            <div class="p-3 mb-3 rounded text-white shadow" style="background-color:#1e3a8a;">
                <h6 class="fw-bold mb-3 text-uppercase" style="font-size:.75rem; color:#93c5fd;"><i class="bi bi-calculator me-1"></i> Hasil Perhitungan Otomatis</h6>
                <div class="d-flex justify-content-between mb-2 pb-2 border-bottom border-secondary">
                    <span><i class="bi bi-cash me-2"></i>Total Pendapatan</span>
                    <span class="fw-bold" id="resTotal">Rp 0</span>
                </div>
                <div class="d-flex justify-content-between mb-2 pb-2 border-bottom border-secondary">
                    <span><i class="bi bi-arrow-down-right text-warning me-2"></i>Bagian Reseller (<span id="resLabelPercent">0</span>%)</span>
                    <span class="fw-bold text-warning" id="resBagian">Rp 0</span>
                </div>
                <div class="d-flex justify-content-between mb-2 pb-2 border-bottom border-secondary fs-5 text-success">
                    <span><i class="bi bi-check-circle-fill me-2"></i>Pendapatan Bersih</span>
                    <span class="fw-bold" id="resBersih">Rp 0</span>
                </div>
                <div class="d-flex justify-content-between mb-1" style="font-size:.9rem; color:#93c5fd;">
                    <span><i class="bi bi-ticket-detailed me-2"></i>Target Voucher Sistem</span>
                    <span class="fw-bold" id="resTargetVoucher">0 voucher</span>
                </div>
                <div class="d-flex justify-content-between mb-1" style="font-size:.9rem; color:#93c5fd;">
                    <span><i class="bi bi-ticket-perforated me-2"></i>Estimasi Voucher Terjual</span>
                    <span class="fw-bold" id="resVoucher">0 voucher</span>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-lg w-100 fw-bold" id="btnSubmit" disabled>
                <i class="bi bi-send-fill me-2"></i> KIRIM LAPORAN
            </button>
        </form>
    </div>
</div>

<!-- Riwayat Penagihan -->
<div class="card table-card">
    <div class="card-header bg-white pt-3 pb-2 border-bottom-0 d-flex justify-content-between align-items-center">
        <h5 class="card-title m-0"><i class="bi bi-clock-history me-2 text-secondary"></i> Semua Riwayat Penagihan</h5>
        <a href="/process/export_penagihan.php?start_date=<?= $filter_start ?>&end_date=<?= $filter_end ?>&router_id=<?= $router_filter ?>" class="btn btn-sm btn-outline-success">
            <i class="bi bi-file-earmark-excel me-1"></i> Export Excel
        </a>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead class="table-light">
                <tr style="font-size:.75rem; text-transform:uppercase;">
                    <th>Waktu</th>
                    <th>Cabang</th>
                    <th>Reseller</th>
                    <th>Total</th>
                    <th>Bagian Reseller</th>
                    <th>Bersih</th>
                    <th>Voucher</th>
                    <th>Status</th>
                    <th>Ditagih Oleh</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($history)): ?>
                <tr><td colspan="9" class="text-center text-muted py-4">Belum ada data penagihan di periode ini.</td></tr>
                <?php else: ?>
                    <?php foreach($history as $h): ?>
                    <tr>
                        <td>
                            <div class="fw-bold"><?= date('d/m/Y', strtotime($h['tanggal'])) ?></div>
                            <small class="text-muted"><?= date('H:i', strtotime($h['created_at'])) ?></small>
                        </td>
                        <td><span class="badge bg-primary text-white"><?= htmlspecialchars($h['router_name']) ?></span></td>
                        <td>
                            <div class="fw-bold"><?= htmlspecialchars($h['profile_name']) ?></div>
                        </td>
                        <td class="fw-bold"><?= format_price((float)$h['total_pendapatan']) ?></td>
                        <td class="text-danger fw-600"><?= format_price((float)$h['bagian_reseller']) ?></td>
                        <td class="text-success fw-bold"><?= format_price((float)$h['pendapatan_bersih']) ?></td>
                        <td>
                            <div class="fw-bold text-primary">Est: <?= $h['estimasi_voucher'] ?> / Laku: <?= $h['voucher_aktual'] ?></div>
                        </td>
                        <td>
                            <?php if ($h['status_kecocokan'] === 'sesuai'): ?>
                                <span class="badge bg-success"><i class="bi bi-check-circle"></i> Sesuai</span>
                            <?php elseif ($h['status_kecocokan'] === 'tekor'): ?>
                                <?php $selisih = $h['voucher_aktual'] - $h['estimasi_voucher']; ?>
                                <span class="badge bg-danger"><i class="bi bi-exclamation-triangle"></i> Tekor <?= $selisih ?> Voucher</span>
                            <?php else: ?>
                                <?php $selisih = $h['estimasi_voucher'] - $h['voucher_aktual']; ?>
                                <span class="badge bg-info"><i class="bi bi-info-circle"></i> Lebih <?= $selisih ?> Voucher</span>
                            <?php endif; ?>
                            
                            <?php if (!empty($h['catatan'])): ?>
                                <div class="mt-1" style="font-size:0.75rem; color:#6c757d; max-width:150px; overflow:hidden; text-overflow:ellipsis;">
                                    <strong>Catatan:</strong> <?= htmlspecialchars($h['catatan']) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="fw-600"><?= htmlspecialchars($h['admin_name']) ?></div>
                            <?php if ($h['ditagih_oleh'] == $admin['id']): ?>
                                <span class="badge bg-success" style="font-size:0.6rem;">SAYA</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (date('Y-m-d', strtotime($h['created_at'])) === date('Y-m-d') && $h['ditagih_oleh'] == $admin['id']): ?>
                            <a href="/index.php?page=penagihan_delete&id=<?= $h['id'] ?>" class="btn btn-sm btn-outline-danger" data-confirm="Hapus data penagihan ini? Anda bisa menginputnya kembali setelah dihapus." title="Hapus"><i class="bi bi-trash"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
let currentProfiles = [];
let selectedProfile = null;

const selectRouter = document.getElementById('selectRouter');
const selectProfile = document.getElementById('selectProfile');
const inputTotal = document.getElementById('inputTotal');
const btnSubmit = document.getElementById('btnSubmit');

const elResellerInfo = document.getElementById('resellerInfo');
const elResellerName = document.getElementById('resellerName');
const elResellerPercent = document.getElementById('resellerPercent');
const elResellerPrice = document.getElementById('resellerPrice');

const resTotal = document.getElementById('resTotal');
const resBagian = document.getElementById('resBagian');
const resBersih = document.getElementById('resBersih');
const resVoucher = document.getElementById('resVoucher');
const resLabelPercent = document.getElementById('resLabelPercent');

// Format Rupiah function
const formatRp = (num) => {
    return 'Rp ' + parseFloat(num).toLocaleString('id-ID', {minimumFractionDigits:0});
};

selectRouter.addEventListener('change', function() {
    const rid = this.value;
    selectProfile.innerHTML = '<option value="">— Loading... —</option>';
    selectProfile.disabled = true;
    selectedProfile = null;
    calculate();
    
    if(!rid) {
        selectProfile.innerHTML = '<option value="">— Pilih Cabang Terlebih Dahulu —</option>';
        return;
    }
    
    fetch('/process/get_resellers.php?router_id=' + rid)
        .then(r => r.json())
        .then(data => {
            currentProfiles = data;
            let defaultOption = '<option value="">— Pilih Reseller —</option>';
            if(currentProfiles.length === 0) {
                defaultOption = '<option value="">— Tidak ada data reseller —</option>';
            } else {
                selectProfile.disabled = false;
            }
            
            let optionsHtml = defaultOption;
            currentProfiles.forEach(prof => {
                let label = prof.name + ` (${parseFloat(prof.reseller_percent)}%)`;
                if (prof.router_id) {
                    label += ` [Cabang ${prof.router_name}]`;
                } else {
                    label += ' [Global]';
                }
                optionsHtml += `<option value="${prof.id}">${label}</option>`;
            });
            
            selectProfile.innerHTML = optionsHtml;
        })
        .catch(e => {
            selectProfile.innerHTML = '<option value="">— Gagal memuat data —</option>';
        });
});

const checkIgnorePrevious = document.getElementById('checkIgnorePrevious');
if (checkIgnorePrevious) {
    checkIgnorePrevious.addEventListener('change', calculate);
}

selectProfile.addEventListener('change', function() {
    const pid = this.value;
    if(!pid) {
        selectedProfile = null;
        elResellerInfo.classList.add('d-none');
    } else {
        selectedProfile = currentProfiles.find(p => p.id == pid);
        if(selectedProfile) {
            elResellerName.textContent = selectedProfile.name;
            elResellerPercent.textContent = parseFloat(selectedProfile.reseller_percent);
            elResellerPrice.textContent = formatRp(selectedProfile.price);
            
            // Detail periode dan sisa
            const lastDate = selectedProfile.last_billed_date || 'Belum Pernah';
            document.getElementById('resellerLastBilled').textContent = lastDate;
            document.getElementById('resellerVcrBaru').textContent = (selectedProfile.vcr_baru || 0) + ' vcr';
            
            const sisa = parseInt(selectedProfile.sisa_sebelumnya) || 0;
            const elSisa = document.getElementById('resellerSisaPrev');
            const boxIgnore = document.getElementById('boxIgnorePrevious');
            
            if (sisa > 0) {
                elSisa.innerHTML = `<span class="badge bg-danger">+${sisa} vcr (Tekor Lalu)</span>`;
                if (boxIgnore) boxIgnore.classList.remove('d-none');
            } else if (sisa < 0) {
                elSisa.innerHTML = `<span class="badge bg-info">${sisa} vcr (Lebih Lalu)</span>`;
                if (boxIgnore) boxIgnore.classList.remove('d-none');
            } else {
                elSisa.innerHTML = `<span class="badge bg-secondary">0 (Lunas/Pas)</span>`;
                if (boxIgnore) boxIgnore.classList.add('d-none');
            }
            
            if (checkIgnorePrevious) checkIgnorePrevious.checked = false;
            
            const targetTotal = parseInt(selectedProfile.voucher_aktual) || 0;
            document.getElementById('resellerTargetTotal').textContent = targetTotal + ' vcr';

            const tekorCount = parseInt(selectedProfile.tekor_count) || 0;
            if (tekorCount > 0) {
                document.getElementById('tekorCountNum').textContent = tekorCount;
                document.getElementById('resellerTekorCount').classList.remove('d-none');
            } else {
                document.getElementById('resellerTekorCount').classList.add('d-none');
            }
            
            elResellerInfo.classList.remove('d-none');
        }
    }
    calculate();
});

inputTotal.addEventListener('input', calculate);

function calculate() {
    if(!selectedProfile || !inputTotal.value) {
        resTotal.textContent = 'Rp 0';
        resBagian.textContent = 'Rp 0';
        resBersih.textContent = 'Rp 0';
        resVoucher.textContent = '0 voucher';
        const elResTarget = document.getElementById('resTargetVoucher');
        if (elResTarget) elResTarget.textContent = '0 voucher';
        resLabelPercent.textContent = '0';
        btnSubmit.disabled = true;
        return;
    }
    
    const total = parseFloat(inputTotal.value) || 0;
    const percent = parseFloat(selectedProfile.reseller_percent) || 0;
    const price = parseFloat(selectedProfile.price) || 0;
    
    const isIgnore = checkIgnorePrevious && checkIgnorePrevious.checked;
    const vcrBaru = parseInt(selectedProfile.vcr_baru) || 0;
    const sisaPrev = isIgnore ? 0 : (parseInt(selectedProfile.sisa_sebelumnya) || 0);
    const unbilled = Math.max(0, vcrBaru + sisaPrev);
    
    const elTargetTotal = document.getElementById('resellerTargetTotal');
    if (elTargetTotal) {
        elTargetTotal.textContent = unbilled + ' vcr';
    }
    
    const elResTarget = document.getElementById('resTargetVoucher');
    if (elResTarget) {
        const targetRupiah = unbilled * price;
        elResTarget.textContent = `${unbilled} voucher (${formatRp(targetRupiah)})`;
    }
    
    const bagian = total * (percent / 100);
    const bersih = total - bagian;
    const estimasi = price > 0 ? Math.floor(total / price) : 0;
    
    resTotal.textContent = formatRp(total);
    resBagian.textContent = formatRp(bagian);
    resBersih.textContent = formatRp(bersih);
    
    let statusText = '';
    if (estimasi < unbilled) {
        const selisihVoucher = unbilled - estimasi;
        const targetPenjualan = unbilled * price;
        statusText = ` (TEKOR ${selisihVoucher} voucher, Target Tagihan: ${formatRp(targetPenjualan)})`;
        resVoucher.innerHTML = estimasi + ' voucher <span class="text-danger fw-bold d-block mt-1">' + statusText + '</span>';
        document.querySelector('input[name="catatan"]').required = true;
        document.querySelector('input[name="catatan"]').placeholder = 'Wajib diisi karena tekor...';
        document.getElementById('catatanLabel').innerHTML = 'CATATAN (Wajib) <span class="text-danger">*</span>';
    } else {
        if (estimasi > unbilled) {
            const selisihLebih = estimasi - unbilled;
            statusText = ` (LEBIH ${selisihLebih} voucher, Target: ${unbilled} vcr)`;
            resVoucher.innerHTML = estimasi + ' voucher <span class="text-info fw-bold">' + statusText + '</span>';
        } else {
            statusText = ' (SESUAI)';
            resVoucher.innerHTML = estimasi + ' voucher <span class="text-success fw-bold">' + statusText + '</span>';
        }
        document.querySelector('input[name="catatan"]').required = false;
        document.querySelector('input[name="catatan"]').placeholder = 'Keterangan penagihan...';
        document.getElementById('catatanLabel').innerHTML = 'CATATAN (opsional)';
    }
    
    resLabelPercent.textContent = percent;
    
    btnSubmit.disabled = total <= 0;
}
</script>

<?php include __DIR__ . '/../../include/footer.php'; ?>
