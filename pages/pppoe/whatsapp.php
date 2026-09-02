<?php
/**
 * WhatsApp Gateway & Notification Settings
 */
$page_title = 'WhatsApp Gateway & Notifikasi';
auth_require_superadmin();

// Auto-create WhatsApp tables if not exists (Self-Healing)
try {
    db_execute("CREATE TABLE IF NOT EXISTS wa_config (
        id INT AUTO_INCREMENT PRIMARY KEY,
        provider ENUM('fonnte','ultramsg','greenapi','generic') DEFAULT 'fonnte',
        api_url VARCHAR(255) DEFAULT 'https://api.fonnte.com/send',
        api_token VARCHAR(255) DEFAULT '',
        device_id VARCHAR(100) DEFAULT '',
        is_active TINYINT(1) DEFAULT 1,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    db_execute("CREATE TABLE IF NOT EXISTS wa_templates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(50) NOT NULL UNIQUE,
        name VARCHAR(100) NOT NULL,
        message TEXT NOT NULL,
        is_active TINYINT(1) DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    db_execute("CREATE TABLE IF NOT EXISTS wa_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id INT DEFAULT NULL,
        phone VARCHAR(30) NOT NULL,
        recipient_name VARCHAR(150) DEFAULT '',
        message_type VARCHAR(50) DEFAULT 'general',
        message_text TEXT NOT NULL,
        status ENUM('success','failed','pending') DEFAULT 'pending',
        response_payload TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Seed default templates if empty
    $cnt = db_fetch_one("SELECT COUNT(*) as c FROM wa_templates");
    if (!$cnt || (int)$cnt['c'] === 0) {
        $defaultTemplates = [
            ['reminder_h3', 'Pengingat Tagihan (H-3 Jatuh Tempo)', "Halo Kak {nama} ({username}),\n\nKami informasikan bahwa tagihan internet {nama_layanan} untuk bulan {bulan} sebesar *{tagihan}* akan jatuh tempo pada *{jatuh_tempo}*.\n\nMohon lakukan pembayaran tepat waktu agar kenyamanan berinternet tetap terjaga.\n\nPortal & Bayar Online: {link_portal}\nTerima kasih atas kerja samanya. 🙏"],
            ['reminder_h1', 'Pengingat Tagihan (H-1 Jatuh Tempo)', "Halo Kak {nama},\n\nTagihan internet {nama_layanan} Anda sebesar *{tagihan}* akan jatuh tempo *BESOK ({jatuh_tempo})*.\n\nUntuk menghindari gangguan / isolir otomatis oleh sistem, silakan melakukan pembayaran melalui transfer atau portal online:\n{link_portal}\n\nTerima kasih! 🙏"],
            ['reminder_h0', 'Pemberitahuan Hari Jatuh Tempo (Hari H)', "Yth. Pelanggan {nama_layanan},\nKak {nama} ({username})\n\nHari ini adalah batas tanggal jatuh tempo pembayaran tagihan internet Anda sebesar *{tagihan}*.\n\nSilakan segera selesaikan pembayaran hari ini. Bayar mudah via QRIS/VA melalui portal:\n{link_portal}\n\nTerima kasih atas perhatiannya. 😊"],
            ['isolir', 'Pemberitahuan Layanan Terisolir', "Pemberitahuan: Layanan Internet Terisolir ⚠️\n\nYth. Kak {nama} ({username}),\nLayanan internet {nama_layanan} Anda saat ini telah dinonaktifkan sementara karena melewati batas waktu jatuh tempo.\n\nTotal Tunggakan: *{tagihan}*\n\nAgar koneksi aktif kembali secara otomatis dalam hitungan detik, silakan bayar sekarang melalui tautan berikut:\n{link_portal}\n\nButuh bantuan? Hubungi WhatsApp CS kami: {cs_phone}"],
            ['payment_success', 'Konfirmasi Pembayaran Lunas', "Terima Kasih! Pembayaran Berhasil ✅\n\nYth. Kak {nama},\nPembayaran tagihan internet {nama_layanan} bulan {bulan} sebesar *{tagihan}* telah kami terima pada {waktu_bayar}.\n\nNo. Kwitansi: #{no_invoice}\nStatus: *LUNAS*\nKoneksi internet Anda aktif dan siap digunakan.\n\nLihat Kwitansi Digital: {link_receipt}\nTerima kasih telah setia bersama {nama_layanan}! ✨"]
        ];
        foreach ($defaultTemplates as $t) {
            db_execute("INSERT IGNORE INTO wa_templates (code, name, message, is_active) VALUES (?, ?, ?, 1)", 'sss', [$t[0], $t[1], $t[2]]);
        }
    }
} catch (Exception $e) {}

$wa_config = null;
try {
    $wa_config = db_fetch_one("SELECT * FROM wa_config LIMIT 1");
} catch (Exception $e) {}

if (!$wa_config) {
    $wa_config = [
        'provider' => 'fonnte',
        'api_url' => 'https://api.fonnte.com/send',
        'api_token' => '',
        'device_id' => '',
        'is_active' => 1
    ];
}

$templates = [];
try {
    $templates = db_fetch_all("SELECT * FROM wa_templates ORDER BY id ASC");
} catch (Exception $e) {}

$customers = [];
try {
    $customers = db_fetch_all("SELECT id, full_name, pppoe_username, phone, monthly_price, due_day FROM pppoe_customers WHERE phone != '' ORDER BY full_name ASC");
} catch (Exception $e) {}

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
                
                <!-- WA Web Scan QR Box -->
                <div id="waweb_control_card" class="card border-success mb-4" style="background: linear-gradient(135deg, #f0fdf4 0%, #ffffff 100%);">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <h6 class="fw-bold mb-1 text-success"><i class="bi bi-qr-code-scan me-2"></i>Koneksi WhatsApp Web Mandiri (Scan Barcode)</h6>
                                <span class="text-muted small">Scan langsung dari WhatsApp HP Anda — 100% Gratis &amp; Tanpa API Berbayar</span>
                            </div>
                            <span id="waweb_badge" class="badge bg-secondary fs-6 py-2 px-3"><i class="bi bi-hourglass-split me-1"></i> Memeriksa...</span>
                        </div>
                        
                        <div class="p-3 bg-white rounded border mb-3" id="waweb_info_box">
                            <div class="d-flex align-items-center gap-3">
                                <div class="rounded-circle p-3 bg-light text-success fs-3"><i class="bi bi-phone"></i></div>
                                <div>
                                    <div class="fw-bold fs-6" id="waweb_user_phone">Memeriksa status...</div>
                                    <div class="text-muted small" id="waweb_user_status">Menghubungi engine WhatsApp lokal (Port 3000)...</div>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex gap-2 flex-wrap">
                            <button type="button" class="btn btn-success" onclick="openScanQrModal()">
                                <i class="bi bi-qr-code me-1"></i> Scan Barcode / QR Code
                            </button>
                            <button type="button" class="btn btn-outline-secondary" onclick="checkWaWebStatus(true)">
                                <i class="bi bi-arrow-clockwise me-1"></i> Refresh Status
                            </button>
                            <button type="button" class="btn btn-outline-danger" id="btn_waweb_logout" onclick="disconnectWaWeb()" style="display:none;">
                                <i class="bi bi-power me-1"></i> Putus Tautan / Ganti Nomor
                            </button>
                        </div>
                    </div>
                </div>

                <form method="POST" action="/process/save_wa_config.php">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="save_config">

                    <div class="mb-3">
                        <label class="form-label fw-bold">Pilihan Provider WhatsApp <span class="text-danger">*</span></label>
                        <select name="provider" id="wa_provider" class="form-select" onchange="updateProviderFields(this.value)">
                            <option value="waweb" <?= ($wa_config['provider'] ?? 'waweb') === 'waweb' ? 'selected' : '' ?>>📱 S.NET WA Web (Scan Barcode Mandiri — Gratis &amp; Langsung)</option>
                            <option value="fonnte" <?= ($wa_config['provider'] ?? '') === 'fonnte' ? 'selected' : '' ?>>Fonnte API (Cloud Gateway)</option>
                            <option value="ultramsg" <?= ($wa_config['provider'] ?? '') === 'ultramsg' ? 'selected' : '' ?>>Ultramsg API</option>
                            <option value="greenapi" <?= ($wa_config['provider'] ?? '') === 'greenapi' ? 'selected' : '' ?>>Green API</option>
                            <option value="generic" <?= ($wa_config['provider'] ?? '') === 'generic' ? 'selected' : '' ?>>Generic REST API (Custom)</option>
                        </select>
                        <div class="form-text">Pilih metode koneksi yang Anda inginkan. Disarankan menggunakan <b>S.NET WA Web (Scan Barcode)</b>.</div>
                    </div>

                    <div id="cloud_api_fields" style="<?= ($wa_config['provider'] ?? 'waweb') === 'waweb' ? 'display:none;' : '' ?>">
                        <div class="mb-3">
                            <label class="form-label fw-bold">API Token / API Key <span class="text-danger">*</span></label>
                            <input type="password" name="api_token" id="inp_api_token" class="form-control font-mono"
                                   value="<?= htmlspecialchars($wa_config['api_token'] ?? '') ?>"
                                   placeholder="Contoh: v#xxxxxxx... atau token Anda">
                            <div class="form-text">Token otentikasi dari dashboard provider WhatsApp pihak ketiga.</div>
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
                        </div>
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
                        <p class="text-muted small">Kirim pesan WhatsApp percobaan ke nomor Anda untuk memastikan pesan terkirim dengan baik.</p>

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
                        <p class="text-muted small mb-2">Jadwalkan pengiriman reminder tagihan ramah H-3, H-1, dan Hari H secara otomatis di VPS Anda:</p>
                        <div class="p-2 bg-dark text-light rounded font-mono" style="font-size:.78rem;">
                            0 8 * * * php <?= realpath(__DIR__ . '/../../cron/cron_pppoe_reminder.php') ?: '/www/wwwroot/s.shawir.id/cron/cron_pppoe_reminder.php' ?> &gt;&gt; /var/log/snet_wa_reminder.log 2&gt;&amp;1
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal Live Scan QR Code -->
        <div class="modal fade" id="modalScanQr" tabindex="-1" data-bs-backdrop="static">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-qr-code text-success me-2"></i>Scan Barcode WhatsApp Web</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" onclick="stopQrPolling()"></button>
                    </div>
                    <div class="modal-body text-center p-4">
                        <div id="qr_loading_spinner" class="py-5">
                            <div class="spinner-border text-success mb-3" style="width: 3rem; height: 3rem;" role="status"></div>
                            <div class="text-muted fw-bold">Membuat QR Code WhatsApp Web...</div>
                            <div class="small text-muted mt-1">Pastikan background service sudah berjalan di VPS</div>
                        </div>
                        
                        <div id="qr_image_container" style="display:none;">
                            <div class="p-2 border rounded bg-white shadow-sm d-inline-block mb-3">
                                <img id="qr_image_img" src="" alt="Scan QR Code" style="width:260px;height:260px;display:block;">
                            </div>
                            <div class="alert alert-info py-2 small text-start mb-0">
                                <strong>Cara Menghubungkan:</strong>
                                <ol class="ps-3 mb-0 mt-1">
                                    <li>Buka <strong>WhatsApp</strong> di HP Anda</li>
                                    <li>Ketuk menu <strong>Perangkat Tertaut</strong> &rarr; <strong>Tautkan Perangkat</strong></li>
                                    <li>Arahkan kamera HP ke barcode di atas</li>
                                </ol>
                            </div>
                        </div>

                        <div id="qr_success_container" style="display:none;" class="py-4">
                            <i class="bi bi-check-circle-fill text-success" style="font-size: 4.5rem;"></i>
                            <h5 class="fw-bold text-success mt-3">WhatsApp Berhasil Terhubung! 🎉</h5>
                            <p class="text-muted mb-0" id="qr_success_user">-</p>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" onclick="stopQrPolling()">Tutup</button>
                        <button type="button" class="btn btn-success" onclick="loadQrCode()"><i class="bi bi-arrow-clockwise me-1"></i> Muat Ulang QR</button>
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
let qrPollTimer = null;
let statusPollTimer = null;

function updateProviderFields(val) {
    const devBox = document.getElementById('field_device_id');
    const cloudBox = document.getElementById('cloud_api_fields');
    const urlInput = document.getElementById('wa_api_url');
    const wawebCard = document.getElementById('waweb_control_card');

    if (val === 'waweb') {
        if (cloudBox) cloudBox.style.display = 'none';
        if (wawebCard) wawebCard.style.display = 'block';
        if (urlInput) urlInput.value = 'http://127.0.0.1:3000/api/send';
        checkWaWebStatus();
    } else {
        if (cloudBox) cloudBox.style.display = 'block';
        if (wawebCard) wawebCard.style.display = 'none';
        if (val === 'fonnte') {
            if (devBox) devBox.style.display = 'none';
            if (urlInput) urlInput.value = 'https://api.fonnte.com/send';
        } else if (val === 'ultramsg') {
            if (devBox) devBox.style.display = 'block';
            if (urlInput) urlInput.value = 'https://api.ultramsg.com';
        } else if (val === 'greenapi') {
            if (devBox) devBox.style.display = 'block';
            if (urlInput) urlInput.value = 'https://api.green-api.com';
        } else {
            if (devBox) devBox.style.display = 'block';
        }
    }
}

// ── WA WEB STATUS & SCAN LOGIC ──

async function checkWaWebStatus(showToast = false) {
    const badge = document.getElementById('waweb_badge');
    const userPhone = document.getElementById('waweb_user_phone');
    const userStatus = document.getElementById('waweb_user_status');
    const btnLogout = document.getElementById('btn_waweb_logout');

    if (!badge) return;

    try {
        const res = await fetch('/ajax/wa_qr.php?action=status');
        const data = await res.json();

        if (data.status === 'connected' && data.user) {
            badge.className = 'badge bg-success fs-6 py-2 px-3';
            badge.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i> Terhubung Online';
            userPhone.textContent = '+' + data.user.id + ' (' + (data.user.name || 'S.NET Admin') + ')';
            userPhone.className = 'fw-bold fs-6 text-success';
            userStatus.textContent = 'WhatsApp Web aktif dan siap mengirim pesan otomatis.';
            if (btnLogout) btnLogout.style.display = 'inline-block';
        } else if (data.status === 'scan_qr') {
            badge.className = 'badge bg-warning text-dark fs-6 py-2 px-3';
            badge.innerHTML = '<i class="bi bi-qr-code me-1"></i> Perlu Scan Barcode';
            userPhone.textContent = 'Belum Terhubung';
            userPhone.className = 'fw-bold fs-6 text-warning';
            userStatus.textContent = 'Silakan klik tombol [Scan Barcode] untuk menghubungkan WhatsApp.';
            if (btnLogout) btnLogout.style.display = 'none';
        } else if (data.status === 'connecting') {
            badge.className = 'badge bg-info fs-6 py-2 px-3';
            badge.innerHTML = '<i class="bi bi-arrow-repeat spin me-1"></i> Sedang Menghubungkan...';
            userPhone.textContent = 'Menghubungkan...';
            userPhone.className = 'fw-bold fs-6 text-info';
            userStatus.textContent = 'Memverifikasi sesi dengan server WhatsApp...';
            if (btnLogout) btnLogout.style.display = 'none';
        } else {
            badge.className = 'badge bg-secondary fs-6 py-2 px-3';
            badge.innerHTML = '<i class="bi bi-exclamation-circle me-1"></i> Belum Terhubung';
            userPhone.textContent = 'Tidak Ada Perangkat Tertaut';
            userPhone.className = 'fw-bold fs-6 text-muted';
            userStatus.textContent = data.message || 'Klik [Scan Barcode] untuk mulai menghubungkan.';
            if (btnLogout) btnLogout.style.display = 'none';
        }

        if (showToast) {
            alert('Status WhatsApp: ' + (data.status === 'connected' ? 'TERHUBUNG (+' + data.user.id + ')' : (data.message || data.status)));
        }
    } catch (e) {
        badge.className = 'badge bg-danger fs-6 py-2 px-3';
        badge.innerHTML = '<i class="bi bi-x-circle me-1"></i> Engine Offline';
        userPhone.textContent = 'Service Port 3000 Tidak Aktif';
        userPhone.className = 'fw-bold fs-6 text-danger';
        userStatus.textContent = 'Pastikan sudah menjalankan: sudo bash setup_wa_service.sh di VPS Anda.';
        if (btnLogout) btnLogout.style.display = 'none';
    }
}

let modalScanQrInstance = null;

function openScanQrModal() {
    const modalEl = document.getElementById('modalScanQr');
    if (!modalEl) return;

    if (!modalScanQrInstance) {
        modalScanQrInstance = new bootstrap.Modal(modalEl);
    }

    document.getElementById('qr_loading_spinner').style.display = 'block';
    document.getElementById('qr_image_container').style.display = 'none';
    document.getElementById('qr_success_container').style.display = 'none';

    modalScanQrInstance.show();
    loadQrCode();

    // Start polling status every 2 seconds
    stopQrPolling();
    qrPollTimer = setInterval(async () => {
        try {
            const res = await fetch('/ajax/wa_qr.php?action=status');
            const data = await res.json();

            if (data.status === 'connected' && data.user) {
                stopQrPolling();
                document.getElementById('qr_loading_spinner').style.display = 'none';
                document.getElementById('qr_image_container').style.display = 'none';
                document.getElementById('qr_success_container').style.display = 'block';
                document.getElementById('qr_success_user').textContent = 'Tersambung: +' + data.user.id + ' (' + (data.user.name || 'S.NET') + ')';
                
                checkWaWebStatus();

                setTimeout(() => {
                    if (modalScanQrInstance) modalScanQrInstance.hide();
                }, 2500);
            } else if (data.status === 'scan_qr' && !document.getElementById('qr_image_img').src.startsWith('data:')) {
                loadQrCode();
            }
        } catch (e) {}
    }, 2000);
}

async function loadQrCode() {
    const spinner = document.getElementById('qr_loading_spinner');
    const container = document.getElementById('qr_image_container');
    const img = document.getElementById('qr_image_img');

    spinner.style.display = 'block';
    container.style.display = 'none';

    try {
        const res = await fetch('/ajax/wa_qr.php?action=qr');
        const data = await res.json();

        if (data.status === 'connected') {
            stopQrPolling();
            spinner.style.display = 'none';
            document.getElementById('qr_success_container').style.display = 'block';
            document.getElementById('qr_success_user').textContent = 'WhatsApp sudah terhubung!';
            checkWaWebStatus();
        } else if (data.qr) {
            img.src = data.qr;
            spinner.style.display = 'none';
            container.style.display = 'block';
        } else {
            spinner.innerHTML = '<div class="text-warning fw-bold mb-2">Sedang membuat QR Code...</div><div class="small text-muted">' + (data.message || 'Harap tunggu 3-5 detik') + '</div>';
            setTimeout(loadQrCode, 2500);
        }
    } catch (e) {
        spinner.innerHTML = '<div class="text-danger fw-bold mb-2">Gagal memuat QR Code</div><div class="small text-muted">Pastikan service snet-wa sudah berjalan di VPS (Port 3000).</div>';
    }
}

function stopQrPolling() {
    if (qrPollTimer) {
        clearInterval(qrPollTimer);
        qrPollTimer = null;
    }
}

async function disconnectWaWeb() {
    if (!confirm('Apakah Anda yakin ingin MEMUTUS TAUTAN WhatsApp ini dan ganti nomor lain?')) return;

    try {
        const res = await fetch('/ajax/wa_qr.php?action=logout', { method: 'POST' });
        const data = await res.json();
        alert(data.message || 'Koneksi WhatsApp diputus.');
        checkWaWebStatus();
    } catch (e) {
        alert('Gagal memutus koneksi: ' + e.message);
    }
}

// ── TEMPLATE & SEND LOGIC ──

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

document.addEventListener('DOMContentLoaded', function() {
    const currentProvider = document.getElementById('wa_provider');
    if (currentProvider && currentProvider.value === 'waweb') {
        checkWaWebStatus();
    }
});
</script>

<?php include __DIR__ . '/../../include/footer.php'; ?>
