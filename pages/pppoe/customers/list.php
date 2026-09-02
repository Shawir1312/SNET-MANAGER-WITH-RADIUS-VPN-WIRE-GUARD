<?php
/**
 * PPPoE Customers — List
 */
$page_title = 'Pelanggan PPPoE';
$routers = get_all_routers();
$selRid = (int)get('router_id');
if (!$selRid && !empty($routers)) {
    $selRid = $routers[0]['id'];
}

$selRouter = null;
foreach ($routers as $r) {
    if ($r['id'] == $selRid) {
        $selRouter = $r;
        break;
    }
}

$search = get('q', '');
$filter_status = get('status', '');

$where_sql = "WHERE pc.router_id = ?";
$params = [$selRid];
$types = "i";

if ($search !== '') {
    $where_sql .= " AND (pc.pppoe_username LIKE ? OR pc.full_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= "ss";
}

if ($filter_status !== '') {
    $where_sql .= " AND pc.status = ?";
    $params[] = $filter_status;
    $types .= "s";
}

$customers = db_fetch_all(
    "SELECT pc.*, 
            (SELECT COALESCE(SUM(amount),0) FROM pppoe_payments WHERE customer_id=pc.id AND period_year=YEAR(NOW()) AND period_month=MONTH(NOW())) as paid_this_month
     FROM pppoe_customers pc 
     $where_sql 
     ORDER BY pc.status ASC, pc.full_name ASC",
    $types, $params
);

$wa_templates = [];
try {
    $wa_templates = db_fetch_all("SELECT * FROM wa_templates WHERE is_active = 1 ORDER BY id ASC");
} catch (Exception $e) {}

$active_sessions = [];
$api_error = '';

if ($selRouter) {
    try {
        require_once __DIR__ . '/../../../lib/routeros_api.class.php';
        $api = new RouterosAPI();
        $api->debug = false;
        if ($api->connect($selRouter['ip_address'], $selRouter['api_user'], $selRouter['api_password'], (int)$selRouter['api_port'])) {
            $acts = $api->comm('/ppp/active/print');
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

include __DIR__ . '/../../../include/header.php';
?>
<style>
.sts-active { background:#DCFCE7; color:#15803D; padding:3px 8px; border-radius:12px; font-size:12px; font-weight:600; }
.sts-isolated { background:#FEE2E2; color:#DC2626; padding:3px 8px; border-radius:12px; font-size:12px; font-weight:600; }
.sts-suspended { background:#FEF3C7; color:#D97706; padding:3px 8px; border-radius:12px; font-size:12px; font-weight:600; }
.online-dot { width:8px; height:8px; border-radius:50%; background:#22C55E; display:inline-block; box-shadow:0 0 0 3px rgba(34,197,94,.2); animation:dp 2s infinite; }
@keyframes dp { 0%{box-shadow:0 0 0 0 rgba(34,197,94,.4)} 70%{box-shadow:0 0 0 6px rgba(34,197,94,0)} 100%{box-shadow:0 0 0 0 rgba(34,197,94,0)} }
</style>

<div class="page-header">
    <div>
        <h1 class="page-title"><i class="bi bi-people me-2 text-primary"></i>Pelanggan PPPoE</h1>
        <p class="page-subtitle">Kelola data pelanggan broadband (PPPoE).</p>
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
        <a href="/index.php?page=pppoe_add&router_id=<?= $selRid ?>" class="btn btn-primary <?= !$selRouter ? 'disabled' : '' ?>">
            <i class="bi bi-person-plus me-1"></i> Tambah Pelanggan
        </a>
    </div>
</div>

<?php if ($api_error): ?>
<div class="alert alert-warning py-2 mb-3"><i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($api_error) ?></div>
<?php endif; ?>

<div class="card table-card">
    <div class="table-toolbar flex-wrap gap-2">
        <div class="d-flex align-items-center gap-3">
            <span class="fw-600"><?= count($customers) ?> Pelanggan</span>
            
            <form method="GET" class="d-flex align-items-center gap-2 m-0">
                <input type="hidden" name="page" value="pppoe_customers">
                <select name="router_id" class="form-select form-select-sm" style="width:200px" onchange="this.form.submit()">
                    <?php if (empty($routers)): ?>
                        <option value="">Belum ada router</option>
                    <?php endif; ?>
                    <?php foreach ($routers as $rt): ?>
                    <option value="<?= $rt['id'] ?>" <?= $selRid == $rt['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($rt['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                
                <select name="status" class="form-select form-select-sm" style="width:140px" onchange="this.form.submit()">
                    <option value="">Semua Status</option>
                    <option value="active" <?= $filter_status === 'active' ? 'selected' : '' ?>>Aktif</option>
                    <option value="isolated" <?= $filter_status === 'isolated' ? 'selected' : '' ?>>Isolir</option>
                </select>
            </form>
        </div>
        
        <form method="GET" class="d-flex m-0">
            <input type="hidden" name="page" value="pppoe_customers">
            <input type="hidden" name="router_id" value="<?= $selRid ?>">
            <input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>">
            <div class="input-group input-group-sm" style="width:220px">
                <input type="text" name="q" class="form-control" placeholder="Cari nama/username..." value="<?= htmlspecialchars($search) ?>">
                <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>Status</th>
                    <th>Username PPPoE</th>
                    <th>Nama Pelanggan</th>
                    <th>ONT SN</th>
                    <th>Profil</th>
                    <th>Jatuh Tempo</th>
                    <th>Harga / Bln</th>
                    <th>Sesi Online</th>
                    <th style="min-width: 140px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($customers)): ?>
            <tr><td colspan="9" class="text-center text-muted py-4">Belum ada pelanggan PPPoE di router ini.</td></tr>
            <?php else: ?>
            <?php foreach ($customers as $c): 
                $is_online = isset($active_sessions[$c['pppoe_username']]);
                $today = (int)date('j');
                $is_late = ($c['status'] === 'active' && $c['due_day'] <= $today && !$c['paid_this_month']);
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
                <td><strong class="font-mono text-primary"><?= htmlspecialchars($c['pppoe_username']) ?></strong></td>
                <td>
                    <div class="fw-bold"><?= htmlspecialchars($c['full_name']) ?></div>
                    <?php if ($c['phone']): ?>
                    <small class="text-muted"><i class="bi bi-telephone"></i> <?= htmlspecialchars($c['phone']) ?></small>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (!empty($c['ont_sn'])): ?>
                        <span class="font-mono" style="font-size:12px; color:var(--bs-primary)"><?= htmlspecialchars($c['ont_sn']) ?></span>
                    <?php else: ?>
                        <span class="text-muted" style="font-size:12px">-</span>
                    <?php endif; ?>
                </td>
                <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($c['profile'] ?: '-') ?></span></td>
                <td>
                    <strong>Tgl <?= $c['due_day'] ?></strong>
                    <?php if ($c['paid_this_month'] > 0): ?>
                        <br><small class="text-success fw-bold"><i class="bi bi-check-circle-fill"></i> Lunas</small>
                    <?php elseif ($is_late): ?>
                        <br><small class="text-danger fw-bold"><i class="bi bi-exclamation-circle-fill"></i> Nunggak</small>
                    <?php else: ?>
                        <br><small class="text-muted">Belum bayar</small>
                    <?php endif; ?>
                </td>
                <td><?= format_price((float)$c['monthly_price']) ?></td>
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
                        <span class="text-muted" style="font-size:12px">Offline</span>
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
                                title="Kirim Notifikasi WhatsApp">
                            <i class="bi bi-whatsapp"></i>
                        </button>
                        <?php endif; ?>

                        <!-- Bayar Kasir Cepat -->
                        <button type="button" class="btn btn-sm btn-outline-success btn-icon btn-quick-pay"
                                data-id="<?= $c['id'] ?>"
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
                            <input type="hidden" name="router_id" value="<?= $selRid ?>">
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
                            <input type="hidden" name="router_id" value="<?= $selRid ?>">
                            <input type="hidden" name="target_status" value="active">
                            <button type="submit" class="btn btn-sm btn-outline-info btn-icon" title="Buka Isolir / Aktifkan Kembali">
                                <i class="bi bi-play-circle-fill"></i>
                            </button>
                        </form>
                        <?php endif; ?>

                        <a href="/index.php?page=pppoe_edit&router_id=<?= $selRid ?>&id=<?= $c['id'] ?>"
                           class="btn btn-sm btn-outline-primary btn-icon" title="Edit">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <a href="/index.php?page=pppoe_delete&router_id=<?= $selRid ?>&id=<?= $c['id'] ?>"
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

<!-- Modal Catat Pembayaran Kasir -->
<div class="modal fade" id="modalPayCustomer" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="/process/save_pppoe_payment.php" class="modal-content">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="router_id" value="<?= $selRid ?>">
            <input type="hidden" name="customer_id" id="pay_customer_id" value="">
            <input type="hidden" name="redirect_page" value="pppoe_customers">

            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-cash-stack text-success me-2"></i>Catat Pembayaran Tagihan</h5>
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

<!-- Modal Catat Pembayaran Global (Pilih Pelanggan) -->
<div class="modal fade" id="modalPayGlobal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="/process/save_pppoe_payment.php" class="modal-content">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="router_id" value="<?= $selRid ?>">
            <input type="hidden" name="redirect_page" value="pppoe_customers">

            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-cash-stack text-success me-2"></i>Catat Pembayaran Kasir</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label fw-bold">Pilih Pelanggan <span class="text-danger">*</span></label>
                        <select name="customer_id" id="global_customer_id" class="form-select" required onchange="updateGlobalPrice(this)">
                            <option value="">-- Pilih Pelanggan --</option>
                            <?php foreach ($customers as $c): ?>
                            <option value="<?= $c['id'] ?>" data-price="<?= (float)$c['monthly_price'] ?>">
                                <?= htmlspecialchars($c['full_name']) ?> (<?= htmlspecialchars($c['pppoe_username']) ?>) — Rp <?= number_format((float)$c['monthly_price'], 0, ',', '.') ?>
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
            <input type="hidden" name="redirect" value="/index.php?page=pppoe_customers&router_id=<?= $selRid ?>">

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
});

function applyQuickTemplate(code) {
    if (!code || !activeWaCustomer) return;
    const t = waTemplatesList.find(item => item.code === code);
    if (!t) return;
    
    let msg = t.message;
    msg = msg.replace(/{nama}/g, activeWaCustomer.name)
             .replace(/{username}/g, activeWaCustomer.username)
             .replace(/{tagihan}/g, 'Rp ' + Number(activeWaCustomer.price).toLocaleString('id-ID'))
             .replace(/{jatuh_tempo}/g, 'Tanggal ' + activeWaCustomer.due)
             .replace(/{bulan}/g, '<?= date('F Y') ?>')
             .replace(/{cs_phone}/g, '<?= htmlspecialchars($company_phone ?? '') ?>')
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
