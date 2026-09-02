<?php
/**
 * PPPoE Payments — History, Billing Reports & Receipt Printing
 */
$page_title = 'Riwayat Pembayaran PPPoE';
$routers = get_all_routers();

$selRid       = (int)get('router_id', 0);
$selMonth     = (int)get('month', (int)date('n'));
$selYear      = (int)get('year', (int)date('Y'));
$selMethod    = get('method', '');
$selStatus    = get('status', '');
$search       = trim(get('q', ''));
$page_num     = max(1, (int)get('p', 1));
$limit        = 25;
$offset       = ($page_num - 1) * $limit;

$months = [
    1=>'Januari', 2=>'Februari', 3=>'Maret', 4=>'April',
    5=>'Mei', 6=>'Juni', 7=>'Juli', 8=>'Agustus',
    9=>'September', 10=>'Oktober', 11=>'November', 12=>'Desember'
];

$where_clauses = ["1=1"];
$params = [];
$types = "";

if ($selRid > 0) {
    $where_clauses[] = "pc.router_id = ?";
    $params[] = $selRid;
    $types .= "i";
}

if ($selMonth > 0) {
    $where_clauses[] = "pp.period_month = ?";
    $params[] = $selMonth;
    $types .= "i";
}

if ($selYear > 0) {
    $where_clauses[] = "pp.period_year = ?";
    $params[] = $selYear;
    $types .= "i";
}

if ($selMethod !== '') {
    $where_clauses[] = "pp.payment_method = ?";
    $params[] = $selMethod;
    $types .= "s";
}

if ($selStatus !== '') {
    $where_clauses[] = "pp.midtrans_status = ?";
    $params[] = $selStatus;
    $types .= "s";
}

if ($search !== '') {
    $where_clauses[] = "(pc.full_name LIKE ? OR pc.pppoe_username LIKE ? OR pp.midtrans_order_id LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= "sss";
}

$where_sql = implode(" AND ", $where_clauses);

// Count total rows
$count_query = "SELECT COUNT(*) as total FROM pppoe_payments pp JOIN pppoe_customers pc ON pp.customer_id = pc.id WHERE $where_sql";
$total_rows = (int)(db_fetch_one($count_query, $types, $params)['total'] ?? 0);
$total_pages = max(1, ceil($total_rows / $limit));

// Summary statistics for current filter
$sum_query = "SELECT 
    COALESCE(SUM(CASE WHEN pp.midtrans_status = 'paid' OR pp.midtrans_status = '' OR pp.payment_method = 'cash' THEN pp.amount ELSE 0 END), 0) as total_paid,
    COUNT(CASE WHEN pp.midtrans_status = 'paid' OR pp.midtrans_status = '' OR pp.payment_method = 'cash' THEN 1 END) as count_paid,
    COUNT(CASE WHEN pp.midtrans_status = 'pending' THEN 1 END) as count_pending
    FROM pppoe_payments pp JOIN pppoe_customers pc ON pp.customer_id = pc.id WHERE $where_sql";
$stats = db_fetch_one($sum_query, $types, $params);

// Fetch paginated data
$data_query = "SELECT pp.*, pc.full_name, pc.pppoe_username, pc.phone, r.name as router_name 
               FROM pppoe_payments pp 
               JOIN pppoe_customers pc ON pp.customer_id = pc.id 
               LEFT JOIN routers r ON pc.router_id = r.id 
               WHERE $where_sql 
               ORDER BY pp.paid_at DESC, pp.id DESC 
               LIMIT $limit OFFSET $offset";

$payments = db_fetch_all($data_query, $types, $params);

// Load all customers for modal
$all_customers = db_fetch_all("SELECT id, full_name, pppoe_username, monthly_price, router_id FROM pppoe_customers ORDER BY full_name ASC");

include __DIR__ . '/../../include/header.php';
?>
<div class="page-header">
    <div>
        <h1 class="page-title"><i class="bi bi-wallet2 text-primary me-2"></i>Riwayat Pembayaran PPPoE</h1>
        <p class="page-subtitle">Rekapitulasi transaksi penagihan dan pembayaran broadband pelanggan</p>
    </div>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalPayGlobal">
            <i class="bi bi-cash-stack me-1"></i> Catat Bayar Kasir
        </button>
        <a href="/process/export_pppoe_payments.php?<?= http_build_query($_GET) ?>" class="btn btn-outline-secondary">
            <i class="bi bi-download me-1"></i> Export CSV
        </a>
    </div>
</div>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
    <div class="col-12 col-md-4">
        <div class="stat-card green h-100">
            <div class="stat-icon"><i class="bi bi-cash-stack"></i></div>
            <div class="stat-value"><?= format_price((float)($stats['total_paid'] ?? 0)) ?></div>
            <div class="stat-label">Total Terbayar (Filter)</div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="stat-card blue h-100">
            <div class="stat-icon"><i class="bi bi-check-circle"></i></div>
            <div class="stat-value"><?= number_format((int)($stats['count_paid'] ?? 0)) ?></div>
            <div class="stat-label">Transaksi Lunas</div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="stat-card orange h-100">
            <div class="stat-icon"><i class="bi bi-hourglass-split"></i></div>
            <div class="stat-value"><?= number_format((int)($stats['count_pending'] ?? 0)) ?></div>
            <div class="stat-label">Transaksi Pending</div>
        </div>
    </div>
</div>

<div class="card table-card">
    <!-- Filter Toolbar -->
    <div class="table-toolbar flex-wrap gap-2 p-3 border-bottom">
        <form method="GET" class="row g-2 align-items-center w-100 m-0">
            <input type="hidden" name="page" value="pppoe_payments">

            <div class="col-12 col-sm-6 col-md-2">
                <select name="router_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="0">Semua Router</option>
                    <?php foreach ($routers as $rt): ?>
                    <option value="<?= $rt['id'] ?>" <?= $selRid == $rt['id'] ? 'selected' : '' ?>><?= htmlspecialchars($rt['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-6 col-sm-3 col-md-2">
                <select name="month" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="0">Semua Bulan</option>
                    <?php foreach ($months as $k => $m): ?>
                    <option value="<?= $k ?>" <?= $selMonth === $k ? 'selected' : '' ?>><?= $m ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-6 col-sm-3 col-md-2">
                <select name="year" class="form-select form-select-sm" onchange="this.form.submit()">
                    <?php for ($y = date('Y') + 1; $y >= 2024; $y--): ?>
                    <option value="<?= $y ?>" <?= $selYear === $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>

            <div class="col-6 col-sm-3 col-md-2">
                <select name="method" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">Semua Metode</option>
                    <option value="cash" <?= $selMethod === 'cash' ? 'selected' : '' ?>>💵 Tunai / Cash</option>
                    <option value="midtrans" <?= $selMethod === 'midtrans' ? 'selected' : '' ?>>💳 Midtrans Snap</option>
                    <option value="transfer" <?= $selMethod === 'transfer' ? 'selected' : '' ?>>🏦 Transfer Bank</option>
                    <option value="qris" <?= $selMethod === 'qris' ? 'selected' : '' ?>>📱 QRIS</option>
                </select>
            </div>

            <div class="col-6 col-sm-3 col-md-2">
                <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">Semua Status</option>
                    <option value="paid" <?= $selStatus === 'paid' ? 'selected' : '' ?>>Lunas</option>
                    <option value="pending" <?= $selStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="cancel" <?= $selStatus === 'cancel' ? 'selected' : '' ?>>Batal</option>
                </select>
            </div>

            <div class="col-12 col-md-2">
                <div class="input-group input-group-sm">
                    <input type="text" name="q" class="form-control" placeholder="Cari..." value="<?= htmlspecialchars($search) ?>">
                    <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
                </div>
            </div>
        </form>
    </div>

    <!-- Table -->
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Waktu Pembayaran</th>
                    <th>Pelanggan</th>
                    <th>Router</th>
                    <th>Periode Tagihan</th>
                    <th>Nominal</th>
                    <th>Metode Bayar</th>
                    <th>No. Order / Ref</th>
                    <th>Status</th>
                    <th class="text-center" style="width: 100px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($payments)): ?>
            <tr>
                <td colspan="9" class="text-center text-muted py-5">
                    <i class="bi bi-inbox fs-2 d-block mb-2 text-secondary"></i>
                    Belum ada riwayat pembayaran yang cocok dengan filter yang dipilih.
                </td>
            </tr>
            <?php else: ?>
            <?php foreach ($payments as $p): 
                $isPaid = ($p['midtrans_status'] === 'paid' || $p['midtrans_status'] === '' || $p['payment_method'] === 'cash');
                $methodBadges = [
                    'cash'     => '<span class="badge bg-success-subtle text-success border border-success-subtle"><i class="bi bi-cash me-1"></i>Tunai</span>',
                    'midtrans' => '<span class="badge bg-primary-subtle text-primary border border-primary-subtle"><i class="bi bi-credit-card me-1"></i>Midtrans</span>',
                    'transfer' => '<span class="badge bg-info-subtle text-info border border-info-subtle"><i class="bi bi-bank me-1"></i>Transfer</span>',
                    'qris'     => '<span class="badge bg-purple-subtle text-purple border border-purple-subtle"><i class="bi bi-qr-code me-1"></i>QRIS</span>',
                ];
                $mBadge = $methodBadges[$p['payment_method']] ?? '<span class="badge bg-light text-dark border">' . htmlspecialchars(ucfirst($p['payment_method'])) . '</span>';
            ?>
            <tr>
                <td>
                    <div class="fw-bold"><?= date('d/m/Y', strtotime($p['paid_at'])) ?></div>
                    <small class="text-muted"><?= date('H:i:s', strtotime($p['paid_at'])) ?> WIB</small>
                </td>
                <td>
                    <div class="fw-bold text-dark"><?= htmlspecialchars($p['full_name']) ?></div>
                    <div class="font-mono small text-primary"><?= htmlspecialchars($p['pppoe_username']) ?></div>
                </td>
                <td><span class="badge bg-light text-secondary border"><?= htmlspecialchars($p['router_name'] ?? '-') ?></span></td>
                <td>
                    <span class="fw-semibold"><?= $months[$p['period_month']] ?? $p['period_month'] ?> <?= $p['period_year'] ?></span>
                </td>
                <td>
                    <strong class="text-success fs-6"><?= format_price((float)$p['amount']) ?></strong>
                </td>
                <td><?= $mBadge ?></td>
                <td>
                    <span class="font-mono small text-muted"><?= htmlspecialchars($p['midtrans_order_id'] ?: '-') ?></span>
                    <?php if ($p['notes']): ?>
                    <div class="small text-muted fst-italic" style="font-size: .75rem;"><?= htmlspecialchars($p['notes']) ?></div>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($isPaid): ?>
                        <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Lunas</span>
                    <?php elseif ($p['midtrans_status'] === 'pending'): ?>
                        <span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split me-1"></i>Pending</span>
                    <?php else: ?>
                        <span class="badge bg-danger"><?= htmlspecialchars(ucfirst($p['midtrans_status'])) ?></span>
                    <?php endif; ?>
                </td>
                <td class="text-center">
                    <div class="d-flex gap-1 justify-content-center">
                        <a href="/index.php?page=pppoe_receipt&id=<?= $p['id'] ?>" target="_blank"
                           class="btn btn-sm btn-outline-primary btn-icon" title="Cetak Kwitansi / Struk">
                            <i class="bi bi-printer"></i>
                        </a>

                        <?php if (!empty($p['phone'])): ?>
                        <a href="/index.php?page=pppoe_receipt&id=<?= $p['id'] ?>&action=send_wa" target="_blank"
                           class="btn btn-sm btn-outline-success btn-icon" title="Kirim Kwitansi via WhatsApp"
                           onclick="return confirm('Kirim kwitansi ini ke nomor WhatsApp <?= htmlspecialchars(addslashes($p['full_name'])) ?> (<?= htmlspecialchars($p['phone']) ?>)?')">
                            <i class="bi bi-whatsapp"></i>
                        </a>
                        <?php endif; ?>

                        <?php if ($admin['role'] === 'superadmin'): ?>
                        <a href="/process/delete_pppoe_payment.php?id=<?= $p['id'] ?>&csrf=<?= htmlspecialchars($_SESSION['csrf_token']) ?>"
                           class="btn btn-sm btn-outline-danger btn-icon"
                           data-confirm="Hapus catatan pembayaran ini? (Tindakan ini tidak dapat dibatalkan)"
                           title="Hapus Transaksi">
                            <i class="bi bi-trash"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="card-footer d-flex justify-content-between align-items-center py-3">
        <span class="text-muted small">Menampilkan <?= min($total_rows, $offset + 1) ?> - <?= min($total_rows, $offset + $limit) ?> dari <?= $total_rows ?> transaksi</span>
        <ul class="pagination pagination-sm mb-0">
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
            <li class="page-item <?= $page_num == $i ? 'active' : '' ?>">
                <a class="page-link" href="/index.php?<?= http_build_query(array_merge($_GET, ['p' => $i])) ?>"><?= $i ?></a>
            </li>
            <?php endfor; ?>
        </ul>
    </div>
    <?php endif; ?>
</div>

<!-- Modal Catat Pembayaran Global -->
<div class="modal fade" id="modalPayGlobal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="/process/save_pppoe_payment.php" class="modal-content">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="redirect_page" value="pppoe_payments">

            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-cash-stack text-success me-2"></i>Catat Pembayaran Kasir</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label fw-bold">Pilih Pelanggan <span class="text-danger">*</span></label>
                        <select name="customer_id" class="form-select" required onchange="updatePaymentPrice(this)">
                            <option value="">-- Pilih Pelanggan --</option>
                            <?php foreach ($all_customers as $c): ?>
                            <option value="<?= $c['id'] ?>" data-price="<?= (float)$c['monthly_price'] ?>">
                                <?= htmlspecialchars($c['full_name']) ?> (<?= htmlspecialchars($c['pppoe_username']) ?>) — Rp <?= number_format((float)$c['monthly_price'], 0, ',', '.') ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-6">
                        <label class="form-label fw-bold">Periode Bulan <span class="text-danger">*</span></label>
                        <select name="period_month" class="form-select" required>
                            <?php 
                            $curMonth = (int)date('n');
                            foreach ($months as $k => $m): 
                            ?>
                            <option value="<?= $k ?>" <?= $curMonth === $k ? 'selected' : '' ?>><?= $m ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-6">
                        <label class="form-label fw-bold">Periode Tahun <span class="text-danger">*</span></label>
                        <input type="number" name="period_year" class="form-control" value="<?= date('Y') ?>" required min="2024" max="2099">
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-bold">Nominal Pembayaran (Rp) <span class="text-danger">*</span></label>
                        <input type="number" name="amount" id="modal_pay_amount" class="form-control fs-5 fw-bold text-success" required min="1000" step="1000">
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
                        <div class="form-check form-switch mt-1">
                            <input class="form-check-input" type="checkbox" name="auto_unisolir" value="1" id="checkAutoUnisolirPay" checked>
                            <label class="form-check-label fw-bold text-primary" for="checkAutoUnisolirPay">
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

<script>
function updatePaymentPrice(sel) {
    const opt = sel.options[sel.selectedIndex];
    const price = opt ? opt.getAttribute('data-price') : 0;
    document.getElementById('modal_pay_amount').value = price || '';
}
</script>

<?php include __DIR__ . '/../../include/footer.php'; ?>
