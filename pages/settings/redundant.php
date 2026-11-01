<?php
/**
 * S.NET RADIUS Manager — Server Redundant & Real-Time Sync (Full PHP)
 */

auth_require_superadmin();
require_once __DIR__ . '/../../include/redundant_functions.php';

$page_title = 'Server Redundant & Replikasi';
$cfg = get_redundant_settings();
$isNode = is_redundant_node();

// Ambil data node jika berjalan sebagai Master
$nodes = [];
$logs = [];
$totalVouchers = 0;
$localSales = 0;
$localPPPoE = 0;
try {
    $nodes = db_fetch_all("SELECT * FROM redundant_nodes ORDER BY id DESC");
    $logs  = db_fetch_all("SELECT * FROM redundant_logs ORDER BY id DESC LIMIT 15");
    $totalVouchers = (int)(db_fetch_one("SELECT COUNT(*) AS c FROM vouchers")['c'] ?? 0);
    $localSales    = (int)(db_fetch_one("SELECT COUNT(*) AS c FROM sales_log")['c'] ?? 0);
    $localPPPoE    = (int)(db_fetch_one("SELECT COUNT(*) AS c FROM pppoe_customers")['c'] ?? 0);
} catch (Throwable $e) {}

include __DIR__ . '/../../include/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
        <h1 class="page-title"><i class="bi bi-diagram-3-fill text-primary me-2"></i>Server Redundant &amp; Replikasi (HA)</h1>
        <p class="page-subtitle mb-0">Sistem failover otomatis &amp; replikasi database real-time antar VPS dan Mini PC Cabang (100% PHP)</p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <span class="badge <?= $isNode ? 'bg-warning text-dark' : 'bg-primary' ?> p-2 fs-6">
            <i class="bi <?= $isNode ? 'bi-hdd-network' : 'bi-cloud-check' ?> me-1"></i>
            Peran: <strong><?= $isNode ? 'Node Lokal (Mini PC)' : 'Server Pusat (Master VPS)' ?></strong>
        </span>
    </div>
</div>

<!-- Switch Mode Banner -->
<div class="card mb-4 border-0 shadow-sm" style="background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); color: #fff;">
    <div class="card-body p-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h5 class="fw-bold mb-1"><i class="bi bi-shield-check text-success me-2"></i>Status Redundansi Sistem</h5>
            <p class="text-light opacity-75 mb-0" style="font-size: 0.9rem;">
                <?= $isNode 
                    ? 'Server ini berjalan sebagai <strong>Edge Node (Mini PC Cabang)</strong>. Autentikasi hotspot/PPPoE akan membackup router cabang saat VPS pusat mati.' 
                    : 'Server ini berjalan sebagai <strong>Master Hub (Cloud VPS)</strong>. Mengelola katalog master voucher, paket, dan menerima sesi accounting dari seluruh cabang.' ?>
            </p>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-outline-light btn-sm" data-bs-toggle="modal" data-bs-target="#modalSettings">
                <i class="bi bi-gear-fill me-1"></i> Ubah Peran Server
            </button>
        </div>
    </div>
</div>

<?php if ($isNode): ?>
<!-- ========================================================================= -->
<!-- TAMPILAN UNTUK MODE NODE (MINI PC CABANG)                                  -->
<!-- ========================================================================= -->
<div class="row g-4 mb-4">
    <!-- Status Koneksi & Tombol Sinkron -->
    <div class="col-12 col-lg-7">
        <div class="card h-100 shadow-sm">
            <div class="card-header bg-white py-3">
                <h6 class="card-title mb-0 fw-bold"><i class="bi bi-arrow-repeat text-primary me-2"></i>Sinkronisasi Database dengan VPS Master</h6>
            </div>
            <div class="card-body">
                <div class="p-3 mb-4 rounded" style="background-color: var(--gray-50); border: 1px solid var(--gray-200);">
                    <div class="row g-2 align-items-center">
                        <div class="col-sm-4 text-muted small">URL Master VPS:</div>
                        <div class="col-sm-8 font-mono fw-bold text-primary"><?= htmlspecialchars($cfg['master_url'] ?? '-') ?></div>
                        
                        <div class="col-sm-4 text-muted small">Kode Node Cabang:</div>
                        <div class="col-sm-8 font-mono fw-bold text-dark"><?= htmlspecialchars($cfg['node_code'] ?? '-') ?></div>

                        <div class="col-sm-4 text-muted small">Status Sinkron Terakhir:</div>
                        <div class="col-sm-8" id="nodeLastSync">
                            <?= !empty($cfg['last_sync_at']) ? htmlspecialchars($cfg['last_sync_at']) . ' — <span class="badge bg-success-subtle text-success">' . htmlspecialchars($cfg['last_sync_status']) . '</span>' : '<span class="text-muted">Belum pernah disinkronkan</span>' ?>
                        </div>

                        <div class="col-sm-4 text-muted small">Status Master VPS:</div>
                        <div class="col-sm-8" id="nodeVpsStatus">
                            <?php
                            $vpsSt = $cfg['master_vps_status'] ?? 'online';
                            $isVpsHealthy = ($vpsSt !== 'offline');
                            ?>
                            <?php if ($isVpsHealthy): ?>
                                <span class="badge bg-success"><i class="bi bi-cloud-check-fill me-1"></i> VPS Master Online (Terhubung)</span>
                            <?php else: ?>
                                <span class="badge bg-danger"><i class="bi bi-cloud-slash-fill me-1"></i> VPS Master Terputus / Bermasalah (Mode Ambil Alih Darurat Aktif)</span>
                            <?php endif; ?>
                        </div>

                        <div class="col-sm-4 text-muted small">Data di Database Lokal:</div>
                        <div class="col-sm-8 small">
                            <span class="badge bg-secondary me-1"><i class="bi bi-ticket-perforated me-1"></i><?= number_format($totalVouchers) ?> Voucher</span>
                            <span class="badge bg-success-subtle text-success me-1"><i class="bi bi-cash-stack me-1"></i><?= number_format($localSales) ?> Riwayat Penjualan</span>
                            <span class="badge bg-info-subtle text-info"><i class="bi bi-people me-1"></i><?= number_format($localPPPoE) ?> Pelanggan PPPoE</span>
                        </div>
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2 mb-3">
                    <button type="button" class="btn btn-primary px-4 py-2" id="btnSyncNow" onclick="runNodeSync()">
                        <i class="bi bi-arrow-clockwise me-1"></i> Sinkronkan Data Sekarang
                    </button>
                    <button type="button" class="btn btn-outline-secondary px-3 py-2" id="btnPingMaster" onclick="pingMaster()">
                        <i class="bi bi-activity me-1"></i> Tes Koneksi ke VPS
                    </button>
                </div>

                <div id="syncResultAlert" style="display: none;" class="alert mb-0"></div>
            </div>
        </div>
    </div>

    <!-- Informasi Auto-Sync & Cron -->
    <div class="col-12 col-lg-5">
        <div class="card h-100 shadow-sm">
            <div class="card-header bg-white py-3">
                <h6 class="card-title mb-0 fw-bold"><i class="bi bi-clock-history text-primary me-2"></i>Otomatisasi Latar Belakang (Cron)</h6>
            </div>
            <div class="card-body">
                <p class="small text-muted mb-2">
                    Agar database di Mini PC ini selalu diperbarui otomatis dari VPS setiap menit tanpa klik manual, pasang baris cron berikut di aaPanel Mini PC:
                </p>
                <div class="p-2 bg-dark text-white rounded font-mono mb-3" style="font-size: 0.8rem; word-break: break-all;">
                    * * * * * php <?= BASE_PATH ?>/cron/cron_redundant_sync.php >/dev/null 2>&1
                </div>
                <div class="alert alert-info py-2 px-3 small mb-0">
                    <i class="bi bi-info-circle me-1"></i> <strong>Cara Pasang:</strong> Buka aaPanel Mini PC &rarr; menu <strong>Cron</strong> &rarr; pilih Type: <em>Shell Script</em> &rarr; tempel perintah di atas &rarr; Interval: <em>Every 1 minute</em>.
                </div>
                <a href="/index.php?page=cron_jobs" class="btn btn-sm btn-outline-primary mt-3 w-100">
                    <i class="bi bi-clock-history me-1"></i>Lihat Panduan Lengkap Semua Cron Job &rarr;
                </a>
            </div>
        </div>
    </div>
</div>

<?php else: ?>
<!-- ========================================================================= -->
<!-- TAMPILAN UNTUK MODE MASTER (CLOUD VPS)                                    -->
<!-- ========================================================================= -->
<div class="row g-4 mb-4">
    <!-- Stat Card Master -->
    <div class="col-12 col-md-4">
        <div class="card p-3 shadow-sm border-start border-primary border-4 h-100">
            <div class="text-muted small">Total Node Cabang Terdaftar</div>
            <div class="fs-2 fw-bold text-dark mt-1"><?= count($nodes) ?> <span class="fs-6 text-muted fw-normal">Mini PC</span></div>
            <small class="text-success mt-2 d-block"><i class="bi bi-hdd-network me-1"></i>Siap menerima sinkronisasi</small>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card p-3 shadow-sm border-start border-success border-4 h-100">
            <div class="text-muted small">Katalog Voucher Master</div>
            <div class="fs-2 fw-bold text-dark mt-1"><?= number_format($totalVouchers) ?> <span class="fs-6 text-muted fw-normal">Voucher</span></div>
            <small class="text-muted mt-2 d-block"><i class="bi bi-check2-circle me-1"></i>Tersedia untuk direplikasi</small>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card p-3 shadow-sm border-start border-info border-4 h-100">
            <div class="text-muted small">API Replikasi Endpoint</div>
            <div class="font-mono text-primary mt-1" style="font-size: 0.85rem; word-break: break-all;">
                https://10.77.77.1/api/redundant_sync.php
            </div>
            <small class="text-muted mt-2 d-block"><i class="bi bi-shield-lock me-1"></i>Via tunnel WireGuard (atau domain portal)</small>
        </div>
    </div>
</div>

<!-- Daftar Node Mini PC Cabang -->
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h6 class="card-title mb-0 fw-bold"><i class="bi bi-hdd-network-fill text-primary me-2"></i>Daftar Node Mini PC Cabang</h6>
        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modalAddNode">
            <i class="bi bi-plus-circle me-1"></i> Tambah Node Mini PC Baru
        </button>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Nama Node</th>
                    <th>Kode Node</th>
                    <th>IP / Subnet</th>
                    <th>Status</th>
                    <th>Sinkron Terakhir</th>
                    <th>API Key Token</th>
                    <th class="text-end">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($nodes)): ?>
                <tr>
                    <td colspan="7" class="text-center py-4 text-muted">
                        <i class="bi bi-hdd-network display-6 d-block mb-2 text-muted"></i>
                        Belum ada Node Mini PC cabang yang didaftarkan.
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($nodes as $n): ?>
                <tr>
                    <td>
                        <div class="fw-bold text-dark"><?= htmlspecialchars($n['node_name']) ?></div>
                    </td>
                    <td><span class="badge bg-secondary font-mono"><?= htmlspecialchars($n['node_code']) ?></span></td>
                    <td class="font-mono text-muted small"><?= htmlspecialchars($n['node_ip'] ?: '-') ?></td>
                    <td>
                        <span class="badge <?= $n['status'] === 'active' ? 'bg-success' : 'bg-danger' ?>">
                            <?= strtoupper($n['status']) ?>
                        </span>
                    </td>
                    <td class="small">
                        <?php if ($n['last_sync_at']): ?>
                            <i class="bi bi-check2 text-success me-1"></i><?= date('d/m/y H:i', strtotime($n['last_sync_at'])) ?>
                            <div class="text-muted" style="font-size:0.75rem;"><?= htmlspecialchars($n['last_sync_status']) ?></div>
                        <?php else: ?>
                            <span class="text-muted">Belum pernah</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="input-group input-group-sm" style="max-width: 200px;">
                            <input type="password" class="form-control font-mono" value="<?= htmlspecialchars($n['api_key']) ?>" readonly id="key_<?= $n['id'] ?>">
                            <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText('<?= $n['api_key'] ?>'); alert('API Key disalin!');" title="Salin Key">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </div>
                    </td>
                    <td class="text-end">
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteNode(<?= $n['id'] ?>, '<?= htmlspecialchars($n['node_name']) ?>')">
                            <i class="bi bi-trash"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Log Aktivitas Sinkronisasi -->
<div class="card shadow-sm">
    <div class="card-header bg-white py-3">
        <h6 class="card-title mb-0 fw-bold"><i class="bi bi-journal-text text-primary me-2"></i>Log Aktivitas Replikasi Terakhir</h6>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-striped mb-0 small">
            <thead>
                <tr>
                    <th>Waktu</th>
                    <th>Node</th>
                    <th>Arah</th>
                    <th>Status</th>
                    <th>Ringkasan</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                <tr><td colspan="5" class="text-center text-muted py-3">Belum ada riwayat aktivitas sinkronisasi.</td></tr>
                <?php else: ?>
                <?php foreach ($logs as $l): ?>
                <tr>
                    <td class="text-muted"><?= date('d/m/Y H:i:s', strtotime($l['created_at'])) ?></td>
                    <td><span class="badge bg-secondary font-mono"><?= htmlspecialchars($l['node_code']) ?></span></td>
                    <td>
                        <span class="badge <?= $l['direction'] === 'pull' ? 'bg-primary' : 'bg-info' ?>">
                            <?= strtoupper($l['direction']) ?>
                        </span>
                    </td>
                    <td>
                        <span class="badge <?= $l['status'] === 'success' ? 'bg-success' : 'bg-danger' ?>">
                            <?= strtoupper($l['status']) ?>
                        </span>
                    </td>
                    <td><?= htmlspecialchars($l['summary']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Ubah Pengaturan Role / Node -->
<div class="modal fade" id="modalSettings" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-gear-fill me-2"></i>Pengaturan Peran Server</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="formRedundantSettings" onsubmit="saveSettings(event)">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Peran Server Ini</label>
                        <select class="form-select" name="redundant_mode" id="selMode" onchange="toggleModeFields(this.value)">
                            <option value="master" <?= !$isNode ? 'selected' : '' ?>>Server Pusat (Cloud VPS Master)</option>
                            <option value="node" <?= $isNode ? 'selected' : '' ?>>Node Cabang Lokal (Mini PC)</option>
                        </select>
                        <div class="form-text">Pilih 'Node Cabang' jika ini diinstal di Mini PC desa/cabang.</div>
                    </div>

                    <div id="nodeFields" style="<?= $isNode ? '' : 'display:none;' ?>">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Nama Node Cabang</label>
                            <input type="text" class="form-control" name="node_name" value="<?= htmlspecialchars($cfg['node_name'] ?? 'Mini PC Desa Lusuo') ?>" placeholder="Contoh: Mini PC Desa Lusuo">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Kode Unik Node</label>
                            <input type="text" class="form-control font-mono" name="node_code" value="<?= htmlspecialchars($cfg['node_code'] ?? 'NODE_LUSUO') ?>" placeholder="NODE_LUSUO">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">URL Master VPS</label>
                            <input type="text" class="form-control font-mono" name="master_url" value="<?= htmlspecialchars($cfg['master_url'] ?? 'https://10.77.77.1') ?>" placeholder="https://10.77.77.1">
                            <div class="form-text">Gunakan <code>https://10.77.77.1</code> atau domain <code>https://portal.snetwifi.com</code>.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">API Key Token dari Master</label>
                            <input type="password" class="form-control font-mono" name="api_key" value="<?= htmlspecialchars($cfg['api_key'] ?? '') ?>" placeholder="Tempel token API dari VPS">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary" id="btnSaveSetting">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Tambah Node (Master VPS) -->
<div class="modal fade" id="modalAddNode" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Daftarkan Mini PC Cabang Baru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="formAddNode" onsubmit="addNode(event)">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Nama Node Cabang</label>
                        <input type="text" class="form-control" name="node_name" required placeholder="Contoh: Mini PC Desa Lusuo">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Kode Unik Node</label>
                        <input type="text" class="form-control font-mono" name="node_code" required placeholder="Contoh: NODE_LUSUO">
                        <div class="form-text">Hanya huruf besar, angka, dan garis bawah (_).</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">IP Lokal / IP VPN Mini PC</label>
                        <input type="text" class="form-control font-mono" name="node_ip" placeholder="Contoh: 10.77.77.5 atau 192.168.9.9">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Daftarkan &amp; Buat API Key</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function toggleModeFields(val) {
    document.getElementById('nodeFields').style.display = (val === 'node') ? 'block' : 'none';
}

function saveSettings(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSaveSetting');
    btn.disabled = true;
    const fd = new FormData(document.getElementById('formRedundantSettings'));
    fd.append('action', 'save_settings');

    fetch('/ajax/redundant_sync.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            btn.disabled = false;
            if (res.success) {
                alert('✓ ' + res.message);
                window.location.reload();
            } else {
                alert('Gagal: ' + res.message);
            }
        })
        .catch(err => {
            btn.disabled = false;
            alert('Kesalahan jaringan: ' + err.message);
        });
}

function addNode(e) {
    e.preventDefault();
    const fd = new FormData(document.getElementById('formAddNode'));
    fd.append('action', 'add_node');

    fetch('/ajax/redundant_sync.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                alert('✓ ' + res.message + '\n\nToken API Key:\n' + res.api_key + '\n\nSilakan salin token ini dan masukkan ke Mini PC cabang.');
                window.location.reload();
            } else {
                alert('Gagal: ' + res.message);
            }
        })
        .catch(err => alert('Error: ' + err.message));
}

function deleteNode(id, name) {
    if (!confirm('Hapus pendaftaran node \'' + name + '\'?')) return;
    const fd = new FormData();
    fd.append('action', 'delete_node');
    fd.append('id', id);

    fetch('/ajax/redundant_sync.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.success) window.location.reload();
            else alert(res.message);
        });
}

function pingMaster() {
    const btn = document.getElementById('btnPingMaster');
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Menguji...';

    fetch('/ajax/redundant_sync.php?action=ping_master')
        .then(async r => {
            const text = await r.text();
            try { return JSON.parse(text); } catch (e) {
                const clean = text.replace(/<[^>]*>?/gm, ' ').replace(/\s+/g, ' ').trim();
                return { success: false, message: clean ? clean.substring(0, 300) : ('HTTP ' + r.status) };
            }
        })
        .then(res => {
            btn.disabled = false;
            btn.innerHTML = orig;
            const al = document.getElementById('syncResultAlert');
            al.style.display = 'block';
            al.className = 'alert mt-3 ' + (res.success ? 'alert-success' : 'alert-danger');
            al.innerHTML = (res.success ? '<i class="bi bi-check-circle me-1"></i> ' : '<i class="bi bi-x-circle me-1"></i> ') + res.message;
            if (res.success && document.getElementById('nodeVpsStatus')) {
                document.getElementById('nodeVpsStatus').innerHTML = '<span class="badge bg-success"><i class="bi bi-cloud-check-fill me-1"></i> VPS Master Online (Terhubung)</span>';
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = orig;
            alert('Error: ' + err.message);
        });
}

function runNodeSync() {
    const btn = document.getElementById('btnSyncNow');
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Menyinkronkan...';

    const al = document.getElementById('syncResultAlert');
    al.style.display = 'block';
    al.className = 'alert alert-info mt-3';
    al.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Sedang menarik data dari VPS Master & menyinkronkan database lokal...';

    fetch('/ajax/redundant_sync.php?action=sync_now')
        .then(async r => {
            const text = await r.text();
            try {
                return JSON.parse(text);
            } catch (e) {
                const clean = text.replace(/<[^>]*>?/gm, ' ').replace(/\s+/g, ' ').trim();
                return {
                    success: false,
                    message: clean ? clean.substring(0, 300) : ('Respons server tidak valid (HTTP ' + r.status + ')')
                };
            }
        })
        .then(res => {
            btn.disabled = false;
            btn.innerHTML = orig;
            al.className = 'alert mt-3 ' + (res.success ? 'alert-success' : 'alert-danger');
            al.innerHTML = (res.success ? '<i class="bi bi-check-circle-fill me-1"></i> ' : '<i class="bi bi-exclamation-triangle-fill me-1"></i> ') + res.message;
            if (res.success) {
                setTimeout(() => window.location.reload(), 2000);
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = orig;
            al.className = 'alert alert-danger mt-3';
            al.innerHTML = 'Kesalahan komunikasi: ' + err.message;
        });
}
</script>

<?php include __DIR__ . '/../../include/footer.php'; ?>

