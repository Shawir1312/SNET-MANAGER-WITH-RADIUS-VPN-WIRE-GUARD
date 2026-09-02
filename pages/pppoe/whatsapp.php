<?php
/**
 * WhatsApp Gateway & Notification Settings
 */
$page_title = 'WhatsApp Gateway & Notifikasi';
auth_require_superadmin();

$wa_config = db_fetch_one("SELECT * FROM wa_config LIMIT 1");
if (!$wa_config) {
    $wa_config = [
        'provider' => 'fonnte',
        'api_url' => 'https://api.fonnte.com/send',
        'api_token' => '',
        'device_id' => '',
        'is_active' => 1
    ];
}

$templates = db_fetch_all("SELECT * FROM wa_templates ORDER BY id ASC");
$customers = db_fetch_all("SELECT id, full_name, pppoe_username, phone, monthly_price, due_day FROM pppoe_customers WHERE phone != '' ORDER BY full_name ASC");

// Pagination for logs
$page_num = max(1, (int)get('p', 1));
$limit = 20;
$offset = ($page_num - 1) * $limit;
$total_logs = (int)(db_fetch_one("SELECT COUNT(*) as c FROM wa_logs")['c'] ?? 0);
$total_pages = max(1, ceil($total_logs / $limit));
$logs = db_fetch_all("SELECT * FROM wa_logs ORDER BY id DESC LIMIT $limit OFFSET $offset");

$active_tab = get('tab', 'config');

include __DIR__ . '/../../include/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><i class="bi bi-whatsapp text-success me-2"></i>WhatsApp Gateway &amp; Notifikasi</h1>
        <p class="page-subtitle">Kelola koneksi provider WhatsApp, template pengingat jatuh tempo, dan riwayat pengiriman</p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header border-bottom-0 pb-0">
        <ul class="nav nav-tabs card-header-tabs" role="tablist">
            <li class="nav-item">
                <a class="nav-link <?= $active_tab === 'config' ? 'active' : '' ?>" href="/index.php?page=pppoe_whatsapp&tab=config">
                    <i class="bi bi-gear-wide me-1 text-primary"></i> Pengaturan Gateway
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $active_tab === 'templates' ? 'active' : '' ?>" href="/index.php?page=pppoe_whatsapp&tab=templates">
                    <i class="bi bi-file-earmark-text me-1 text-info"></i> Template Pesan
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $active_tab === 'send' ? 'active' : '' ?>" href="/index.php?page=pppoe_whatsapp&tab=send">
                    <i class="bi bi-send me-1 text-success"></i> Kirim Pesan Manual
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $active_tab === 'logs' ? 'active' : '' ?>" href="/index.php?page=pppoe_whatsapp&tab=logs">
                    <i class="bi bi-journal-text me-1 text-secondary"></i> Riwayat Log (<?= $total_logs ?>)
                </a>
            </li>
        </ul>
    </div>
    
    <div class="card-body">
        <?php if ($active_tab === 'config'): ?>
        <!-- TAB 1: PENGATURAN GATEWAY -->
        <div class="row g-4">
            <div class="col-12 col-lg-7">
                <form method="POST" action="/process/save_wa_config.php">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="save_config">

                    <div class="mb-3">
                        <label class="form-label fw-bold">Provider WhatsApp Gateway <span class="text-danger">*</span></label>
                        <select name="provider" id="wa_provider" class="form-select" onchange="updateProviderFields(this.value)">
                            <option value="fonnte" <?= ($wa_config['provider'] ?? '') === 'fonnte' ? 'selected' : '' ?>>Fonnte (Direkomendasikan — Multi-device)</option>
                            <option value="ultramsg" <?= ($wa_config['provider'] ?? '') === 'ultramsg' ? 'selected' : '' ?>>Ultramsg API</option>
                            <option value="greenapi" <?= ($wa_config['provider'] ?? '') === 'greenapi' ? 'selected' : '' ?>>Green API</option>
                            <option value="generic" <?= ($wa_config['provider'] ?? '') === 'generic' ? 'selected' : '' ?>>Generic REST API (Custom)</option>
                        </select>
                        <div class="form-text">Pilih layanan API gateway yang Anda gunakan.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">API Token / API Key <span class="text-danger">*</span></label>
                        <input type="password" name="api_token" class="form-control font-mono" required
                               value="<?= htmlspecialchars($wa_config['api_token'] ?? '') ?>"
                               placeholder="Contoh: v#xxxxxxx... atau token Anda">
                        <div class="form-text">Token otentikasi dari dashboard provider WhatsApp Anda.</div>
                    </div>

                    <div class="mb-3" id="field_device_id" style="<?= in_array($wa_config['provider'] ?? '', ['ultramsg','greenapi']) ? '' : 'display:none;' ?>">
                        <label class="form-label fw-bold">Instance ID / Device ID</label>
                        <input type="text" name="device_id" class="form-control font-mono"
                               value="<?= htmlspecialchars($wa_config['device_id'] ?? '') ?>"
                               placeholder="Contoh: instance12345 atau 110182...">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">API Endpoint URL</label>
                        <input type="text" name="api_url" id="wa_api_url" class="form-control font-mono"
                               value="<?= htmlspecialchars($wa_config['api_url'] ?? 'https://api.fonnte.com/send') ?>">
                        <div class="form-text">Biarkan default jika menggunakan provider standar Fonnte.</div>
                    </div>

                    <div class="mb-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="waActive"
                                   <?= ($wa_config['is_active'] ?? 1) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold" for="waActive">Aktifkan Notifikasi WhatsApp Otomatis</label>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary px-4">
                        <i class="bi bi-save me-1"></i> Simpan Konfigurasi
                    </button>
                </form>
            </div>

            <div class="col-12 col-lg-5">
                <!-- Test Gateway Box -->
                <div class="card bg-light border">
                    <div class="card-body">
                        <h6 class="card-title fw-bold"><i class="bi bi-send-check text-success me-2"></i>Uji Coba Kirim Pesan (Test)</h6>
                        <p class="text-muted small">Kirim pesan WhatsApp percobaan ke nomor Anda untuk memastikan koneksi gateway berjalan lancar.</p>

                        <form method="POST" action="/process/send_wa_manual.php">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="is_test" value="1">

                            <div class="mb-3">
                                <label class="form-label small fw-bold">Nomor WhatsApp Tujuan</label>
                                <input type="text" name="phone" class="form-control" placeholder="081234567890" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-bold">Isi Pesan Uji Coba</label>
                                <textarea name="message" class="form-control font-mono small" rows="3" required>Halo! Ini adalah pesan uji coba dari sistem S.NET Manager. Koneksi WhatsApp Gateway berhasil terhubung dengan sempurna! 🚀</textarea>
                            </div>

                            <button type="submit" class="btn btn-success w-100">
                                <i class="bi bi-whatsapp me-1"></i> Kirim Pesan Uji Coba
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Info Cronjob -->
                <div class="card bg-light border mt-3">
                    <div class="card-body">
                        <h6 class="card-title fw-bold"><i class="bi bi-alarm text-warning me-2"></i>Cronjob Pengingat Tagihan Otomatis</h6>
                        <p class="text-muted small mb-2">Pasang baris cron berikut di server/aaPanel Anda untuk mengirim reminder tagihan otomatis setiap jam 08:00 pagi:</p>
                        <div class="p-2 bg-dark text-light rounded font-mono" style="font-size:.78rem;">
                            0 8 * * * php <?= realpath(__DIR__ . '/../../cron/cron_pppoe_reminder.php') ?: '/www/wwwroot/dash.snetwifi.com/cron/cron_pppoe_reminder.php' ?> &gt;&gt; /tmp/cron_wa_reminder.log 2&gt;&amp;1
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php elseif ($active_tab === 'templates'): ?>
        <!-- TAB 2: TEMPLATE PESAN -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h5 class="mb-0 fw-bold">Template Pesan Notifikasi</h5>
                <small class="text-muted">Gunakan placeholder seperti <code>{nama}</code>, <code>{username}</code>, <code>{tagihan}</code>, <code>{jatuh_tempo}</code>, <code>{link_portal}</code></small>
            </div>
        </div>

        <div class="row g-3">
            <?php foreach ($templates as $tmpl): ?>
            <div class="col-12 col-md-6">
                <div class="card h-100 border">
                    <div class="card-header d-flex justify-content-between align-items-center bg-light">
                        <span class="fw-bold"><i class="bi bi-chat-left-dots text-primary me-2"></i><?= htmlspecialchars($tmpl['name']) ?></span>
                        <span class="badge bg-secondary font-mono"><?= htmlspecialchars($tmpl['code']) ?></span>
                    </div>
                    <div class="card-body">
                        <pre class="bg-light p-3 rounded text-dark small" style="white-space: pre-wrap; font-family:'Exo 2', sans-serif; font-size:.84rem;"><?= htmlspecialchars($tmpl['message']) ?></pre>
                    </div>
                    <div class="card-footer bg-transparent border-top-0 pt-0 text-end">
                        <button type="button" class="btn btn-sm btn-outline-primary"
                                onclick="editTemplate(<?= htmlspecialchars(json_encode($tmpl)) ?>)">
                            <i class="bi bi-pencil me-1"></i> Edit Template
                        </button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php elseif ($active_tab === 'send'): ?>
        <!-- TAB 3: KIRIM PESAN MANUAL -->
        <div class="row justify-content-center">
            <div class="col-12 col-lg-8">
                <div class="card border">
                    <div class="card-header">
                        <h5 class="card-title mb-0"><i class="bi bi-send text-success me-2"></i>Kirim Notifikasi ke Pelanggan</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="/process/send_wa_manual.php">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                            <div class="mb-3">
                                <label class="form-label fw-bold">Pilih Pelanggan PPPoE</label>
                                <select name="customer_id" id="sel_customer" class="form-select" onchange="fillCustomerInfo(this)">
                                    <option value="">-- Pilih Pelanggan (Atau Ketik Nomor di Bawah) --</option>
                                    <?php foreach ($customers as $c): ?>
                                    <option value="<?= $c['id'] ?>"
                                            data-phone="<?= htmlspecialchars($c['phone']) ?>"
                                            data-name="<?= htmlspecialchars($c['full_name']) ?>"
                                            data-username="<?= htmlspecialchars($c['pppoe_username']) ?>"
                                            data-price="<?= (float)$c['monthly_price'] ?>"
                                            data-due="<?= (int)$c['due_day'] ?>">
                                        <?= htmlspecialchars($c['full_name']) ?> (<?= htmlspecialchars($c['pppoe_username']) ?>) — <?= htmlspecialchars($c['phone']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold">Nomor WhatsApp Tujuan <span class="text-danger">*</span></label>
                                <input type="text" name="phone" id="inp_phone" class="form-control" placeholder="081234567890" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold">Gunakan Template Pesan</label>
                                <select id="sel_template" class="form-select" onchange="applyTemplateToText(this.value)">
                                    <option value="">-- Tulis Pesan Kustom / Pilih Template --</option>
                                    <?php foreach ($templates as $t): ?>
                                    <option value="<?= htmlspecialchars($t['code']) ?>"><?= htmlspecialchars($t['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold">Isi Pesan WhatsApp <span class="text-danger">*</span></label>
                                <textarea name="message" id="inp_message" class="form-control font-mono" rows="8" required placeholder="Tulis pesan WhatsApp..."></textarea>
                            </div>

                            <button type="submit" class="btn btn-success px-4">
                                <i class="bi bi-send-fill me-1"></i> Kirim WhatsApp Sekarang
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <?php elseif ($active_tab === 'logs'): ?>
        <!-- TAB 4: RIWAYAT LOG -->
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Waktu</th>
                        <th>Penerima</th>
                        <th>Tipe</th>
                        <th>Status</th>
                        <th>Isi Pesan</th>
                        <th>Detail Response</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">Belum ada riwayat pengiriman WhatsApp.</td></tr>
                    <?php else: ?>
                    <?php foreach ($logs as $l): ?>
                    <tr>
                        <td class="small text-muted" style="white-space:nowrap;"><?= date('d/m/Y H:i', strtotime($l['created_at'])) ?></td>
                        <td>
                            <div class="fw-bold"><?= htmlspecialchars($l['recipient_name'] ?: 'Pelanggan') ?></div>
                            <small class="font-mono text-muted"><?= htmlspecialchars($l['phone']) ?></small>
                        </td>
                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($l['message_type']) ?></span></td>
                        <td>
                            <?php if ($l['status'] === 'success'): ?>
                                <span class="badge bg-success">Terkirim</span>
                            <?php elseif ($l['status'] === 'failed'): ?>
                                <span class="badge bg-danger">Gagal</span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark">Pending</span>
                            <?php endif; ?>
                        </td>
                        <td style="max-width: 320px;">
                            <div class="small text-truncate" title="<?= htmlspecialchars($l['message_text']) ?>">
                                <?= htmlspecialchars($l['message_text']) ?>
                            </div>
                        </td>
                        <td class="small font-mono text-muted" style="max-width: 200px;">
                            <div class="text-truncate" title="<?= htmlspecialchars($l['response_payload'] ?? '') ?>">
                                <?= htmlspecialchars($l['response_payload'] ?? '-') ?>
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
        <nav class="d-flex justify-content-center mt-3">
            <ul class="pagination pagination-sm">
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <li class="page-item <?= $i == $page_num ? 'active' : '' ?>">
                    <a class="page-link" href="/index.php?page=pppoe_whatsapp&tab=logs&p=<?= $i ?>"><?= $i ?></a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
        <?php endif; ?>

        <?php endif; ?>
    </div>
</div>

<!-- Modal Edit Template -->
<div class="modal fade" id="modalEditTemplate" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form method="POST" action="/process/save_wa_config.php" class="modal-content">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="save_template">
            <input type="hidden" name="code" id="tmpl_code" value="">

            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil-square text-primary me-2"></i>Edit Template Pesan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-bold">Nama Template</label>
                    <input type="text" name="name" id="tmpl_name" class="form-control" required>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">Isi Pesan WhatsApp</label>
                    <textarea name="message" id="tmpl_message" class="form-control font-mono" rows="10" required></textarea>
                </div>

                <div class="alert alert-info py-2" style="font-size:.82rem;">
                    <strong>Variabel yang Tersedia:</strong><br>
                    <code>{nama}</code> = Nama Pelanggan, <code>{username}</code> = Username PPPoE, <code>{tagihan}</code> = Jumlah Rp Tagihan, <code>{jatuh_tempo}</code> = Tgl Jatuh Tempo, <code>{bulan}</code> = Bulan Tagihan, <code>{link_portal}</code> = Link Bayar Online, <code>{link_receipt}</code> = Link Kwitansi Struk, <code>{cs_phone}</code> = No CS, <code>{nama_layanan}</code> = Nama Layanan.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary px-4"><i class="bi bi-save me-1"></i> Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<script>
const rawTemplates = <?= json_encode($templates) ?>;

function updateProviderFields(val) {
    const devBox = document.getElementById('field_device_id');
    const urlInput = document.getElementById('wa_api_url');
    if (val === 'fonnte') {
        devBox.style.display = 'none';
        urlInput.value = 'https://api.fonnte.com/send';
    } else if (val === 'ultramsg') {
        devBox.style.display = 'block';
        urlInput.value = 'https://api.ultramsg.com';
    } else if (val === 'greenapi') {
        devBox.style.display = 'block';
        urlInput.value = 'https://api.green-api.com';
    } else {
        devBox.style.display = 'block';
    }
}

function editTemplate(tmpl) {
    document.getElementById('tmpl_code').value = tmpl.code;
    document.getElementById('tmpl_name').value = tmpl.name;
    document.getElementById('tmpl_message').value = tmpl.message;
    new bootstrap.Modal(document.getElementById('modalEditTemplate')).show();
}

let selectedCustomerData = null;

function fillCustomerInfo(sel) {
    const opt = sel.options[sel.selectedIndex];
    if (opt && opt.value) {
        document.getElementById('inp_phone').value = opt.getAttribute('data-phone') || '';
        selectedCustomerData = {
            full_name: opt.getAttribute('data-name'),
            pppoe_username: opt.getAttribute('data-username'),
            monthly_price: opt.getAttribute('data-price'),
            due_day: opt.getAttribute('data-due')
        };
        const currentTmplCode = document.getElementById('sel_template').value;
        if (currentTmplCode) {
            applyTemplateToText(currentTmplCode);
        }
    } else {
        selectedCustomerData = null;
    }
}

function applyTemplateToText(code) {
    if (!code) return;
    const t = rawTemplates.find(item => item.code === code);
    if (!t) return;
    
    let msg = t.message;
    if (selectedCustomerData) {
        msg = msg.replace(/{nama}/g, selectedCustomerData.full_name)
                 .replace(/{username}/g, selectedCustomerData.pppoe_username)
                 .replace(/{tagihan}/g, 'Rp ' + Number(selectedCustomerData.monthly_price).toLocaleString('id-ID'))
                 .replace(/{jatuh_tempo}/g, 'Tanggal ' + selectedCustomerData.due_day)
                 .replace(/{bulan}/g, '<?= date('F Y') ?>')
                 .replace(/{link_portal}/g, window.location.origin + '/portal/isolir.php?user=' + encodeURIComponent(selectedCustomerData.pppoe_username));
    }
    document.getElementById('inp_message').value = msg;
}
</script>

<?php include __DIR__ . '/../../include/footer.php'; ?>
