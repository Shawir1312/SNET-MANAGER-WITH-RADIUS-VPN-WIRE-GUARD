<?php
/**
 * PPPoE Settings — Configuration for Isolir, Billing, Midtrans & ONT Provisioning Templates
 */
$page_title = 'Pengaturan PPPoE & Billing';
auth_require_superadmin();

// Load current settings from database
$raw_settings = db_fetch_all("SELECT setting_key, setting_value FROM pppoe_settings");
$settings = [];
foreach ($raw_settings as $s) {
    $settings[$s['setting_key']] = $s['setting_value'];
}

include __DIR__ . '/../../include/header.php';
?>
<div class="page-header">
    <div>
        <h1 class="page-title"><i class="bi bi-gear-wide-connected text-primary me-2"></i>Pengaturan PPPoE &amp; Billing</h1>
        <p class="page-subtitle">Kelola template provisioning ONT, konfigurasi isolir otomatis, dan payment gateway Midtrans</p>
    </div>
</div>

<form method="POST" action="/process/save_pppoe_settings.php">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

    <div class="row g-4">
        <!-- ── CARD 1: TEMPLATE AUTO-PROVISIONING ONT ── -->
        <div class="col-12">
            <div class="card border-0 shadow-sm border-start border-4 border-success">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="card-title text-success mb-0 fw-bold">
                        <i class="bi bi-lightning-charge-fill me-2"></i>⚡ Template &amp; Preset Auto-Provisioning ONT (GenieACS TR-069)
                    </h5>
                    <span class="badge bg-success">Zero-Touch Automation</span>
                </div>
                <div class="card-body">
                    <p class="text-muted mb-3" style="font-size: .88rem;">
                        Konfigurasi format otomatis username PPPoE, nama Wi-Fi, dan pemetaan slot WAN saat menambah pelanggan baru.
                    </p>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Suffix / Akhiran Username PPPoE</label>
                            <input type="text" class="form-control font-mono fw-bold text-primary" name="ont_username_suffix"
                                   value="<?= htmlspecialchars($settings['ont_username_suffix'] ?? '@snet') ?>"
                                   placeholder="@snet">
                            <div class="form-text">Contoh: <code>@snet</code> &rarr; username: <code>nama@snet</code></div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold">Format Nama Wi-Fi 2.4 GHz (SSID 1)</label>
                            <input type="text" class="form-control" name="ont_wifi1_prefix"
                                   value="<?= htmlspecialchars($settings['ont_wifi1_prefix'] ?? 'S.NET - ') ?>"
                                   placeholder="S.NET - ">
                            <div class="form-text">Contoh: <code>S.NET - </code> &rarr; SSID 1: <code>S.NET - Budi</code></div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold">Suffix Nama Wi-Fi 5 GHz (SSID 2)</label>
                            <input type="text" class="form-control" name="ont_wifi2_suffix"
                                   value="<?= htmlspecialchars($settings['ont_wifi2_suffix'] ?? ' 5G') ?>"
                                   placeholder=" 5G">
                            <div class="form-text">Contoh: <code> 5G</code> &rarr; SSID 2: <code>S.NET - Budi 5G</code></div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold">Slot WAN Default (FiberHome)</label>
                            <select class="form-select font-mono" name="ont_default_wan_fh">
                                <option value="2" <?= ($settings['ont_default_wan_fh'] ?? '2') === '2' ? 'selected' : '' ?>>Slot 2 (Standard TR-069 FiberHome)</option>
                                <option value="1" <?= ($settings['ont_default_wan_fh'] ?? '') === '1' ? 'selected' : '' ?>>Slot 1</option>
                                <option value="3" <?= ($settings['ont_default_wan_fh'] ?? '') === '3' ? 'selected' : '' ?>>Slot 3</option>
                            </select>
                            <div class="form-text">Instance WAN PPP default untuk modem merek FiberHome.</div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold">Slot WAN Default (ZTE / Huawei / Lainnya)</label>
                            <select class="form-select font-mono" name="ont_default_wan_other">
                                <option value="1" <?= ($settings['ont_default_wan_other'] ?? '1') === '1' ? 'selected' : '' ?>>Slot 1 (Standard ZTE / Huawei / CData)</option>
                                <option value="2" <?= ($settings['ont_default_wan_other'] ?? '') === '2' ? 'selected' : '' ?>>Slot 2</option>
                                <option value="3" <?= ($settings['ont_default_wan_other'] ?? '') === '3' ? 'selected' : '' ?>>Slot 3</option>
                            </select>
                            <div class="form-text">Instance WAN PPP default untuk modem merek ZTE/Huawei/CData.</div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold">Default VLAN ID PPPoE Global</label>
                            <input type="number" class="form-control font-mono fw-bold" name="ont_default_vlan"
                                   value="<?= htmlspecialchars($settings['ont_default_vlan'] ?? '100') ?>"
                                   placeholder="100">
                            <div class="form-text">VLAN ID default untuk layanan internet PPPoE.</div>
                        </div>
                    </div>

                    <hr class="my-4">

                    <!-- Template WAN 2: Hotspot S.NET -->
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <h6 class="fw-bold text-primary mb-0"><i class="bi bi-wifi me-2"></i>Template WAN 2: Hotspot S.NET (Bridged ke SSID 2 &amp; 6)</h6>
                            <small class="text-muted">Push WAN kedua secara otomatis untuk hotspot voucher publik tanpa mengganggu WAN PPPoE rumahan pelanggan.</small>
                        </div>
                        <div class="form-check form-switch fs-5 mb-0">
                            <input class="form-check-input" type="checkbox" name="ont_enable_hotspot" value="1" id="checkEnableHotspot"
                                   <?= (!empty($settings['ont_enable_hotspot'])) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold text-primary" for="checkEnableHotspot">Aktifkan Auto Dual-WAN</label>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label fw-bold">VLAN ID Hotspot</label>
                            <input type="number" class="form-control font-mono fw-bold text-success" name="ont_hotspot_vlan"
                                   value="<?= htmlspecialchars($settings['ont_hotspot_vlan'] ?? '100') ?>"
                                   placeholder="100">
                            <div class="form-text">VLAN interface Hotspot di MikroTik.</div>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label fw-bold">Nama SSID 2 (2.4 GHz Hotspot)</label>
                            <input type="text" class="form-control fw-bold" name="ont_hotspot_ssid2"
                                   value="<?= htmlspecialchars($settings['ont_hotspot_ssid2'] ?? 'S.NET @Hotspot') ?>"
                                   placeholder="S.NET @Hotspot">
                            <div class="form-text">Wi-Fi Hotspot Open (Tanpa password).</div>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label fw-bold">Nama SSID 6 (5 GHz Hotspot)</label>
                            <input type="text" class="form-control fw-bold" name="ont_hotspot_ssid6"
                                   value="<?= htmlspecialchars($settings['ont_hotspot_ssid6'] ?? 'S.NET @Hotspot 5G') ?>"
                                   placeholder="S.NET @Hotspot 5G">
                            <div class="form-text">Wi-Fi 5G Hotspot Open.</div>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label fw-bold">Slot WAN Hotspot (FH / ZTE)</label>
                            <div class="input-group">
                                <span class="input-group-text font-mono small">FH</span>
                                <input type="number" class="form-control font-mono" name="ont_hotspot_slot_fh" value="<?= htmlspecialchars($settings['ont_hotspot_slot_fh'] ?? '3') ?>" title="FiberHome Slot">
                                <span class="input-group-text font-mono small">ZTE</span>
                                <input type="number" class="form-control font-mono" name="ont_hotspot_slot_other" value="<?= htmlspecialchars($settings['ont_hotspot_slot_other'] ?? '2') ?>" title="ZTE/Lainnya Slot">
                            </div>
                            <div class="form-text">Slot WAN IP Bridged (NAT: false).</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── CARD 2: PENGATURAN ISOLIR ── -->
        <div class="col-12 col-lg-6">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-header bg-white py-3">
                    <h5 class="card-title text-danger mb-0 fw-bold"><i class="bi bi-shield-slash me-2"></i>Konfigurasi Auto-Isolir</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Nama Profil Isolir di MikroTik <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="isolir_profile" required
                               value="<?= htmlspecialchars($settings['isolir_profile'] ?? 'isolir') ?>"
                               placeholder="isolir">
                        <div class="form-text">Nama profile PPP di MikroTik yang digunakan untuk mengarahkan pelanggan menunggak ke web isolir.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Masa Tenggang / Grace Period (Hari) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="number" class="form-control" name="isolir_grace_days" required min="0" max="30"
                                   value="<?= htmlspecialchars($settings['isolir_grace_days'] ?? '3') ?>">
                            <span class="input-group-text">Hari</span>
                        </div>
                        <div class="form-text">Jumlah hari toleransi setelah tanggal jatuh tempo sebelum cron melakukan isolir otomatis.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">URL Halaman Isolir Pelanggan</label>
                        <input type="text" class="form-control" name="isolir_redirect_url"
                               value="<?= htmlspecialchars($settings['isolir_redirect_url'] ?? '/portal/isolir.php') ?>"
                               placeholder="/portal/isolir.php">
                        <div class="form-text">Halaman tujuan di mana pelanggan terisolir dapat melihat rincian tagihan &amp; bayar online.</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── CARD 3: IDENTITAS LAYANAN & KONTAK CS ── -->
        <div class="col-12 col-lg-6">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-header bg-white py-3">
                    <h5 class="card-title text-primary mb-0 fw-bold"><i class="bi bi-building me-2"></i>Kontak &amp; Identitas Layanan</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Nama Layanan / Perusahaan</label>
                        <input type="text" class="form-control" name="company_name"
                               value="<?= htmlspecialchars($settings['company_name'] ?? 'S.NET Internet') ?>"
                               placeholder="S.NET Internet">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Nomor WhatsApp / CS Bantuan</label>
                        <input type="text" class="form-control" name="company_phone"
                               value="<?= htmlspecialchars($settings['company_phone'] ?? '') ?>"
                               placeholder="081234567890">
                        <div class="form-text">Ditampilkan di kwitansi, invoice WhatsApp, dan portal isolir pelanggan.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Alamat Kantor / Keterangan</label>
                        <textarea class="form-control" name="company_address" rows="3"
                                  placeholder="Alamat kantor layanan..."><?= htmlspecialchars($settings['company_address'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── CARD 4: MIDTRANS PAYMENT GATEWAY ── -->
        <div class="col-12">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="card-title text-success mb-0 fw-bold"><i class="bi bi-credit-card me-2"></i>Payment Gateway Midtrans Snap</h5>
                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">Otomatisasi Pembayaran Online</span>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Environment / Mode <span class="text-danger">*</span></label>
                            <select class="form-select" name="midtrans_mode">
                                <option value="sandbox" <?= ($settings['midtrans_mode'] ?? 'sandbox') === 'sandbox' ? 'selected' : '' ?>>Sandbox (Mode Testing)</option>
                                <option value="production" <?= ($settings['midtrans_mode'] ?? '') === 'production' ? 'selected' : '' ?>>Production (Live Transaksi Nyata)</option>
                            </select>
                            <div class="form-text">Gunakan <b>Sandbox</b> untuk uji coba dan <b>Production</b> untuk menerima pembayaran asli.</div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold">Midtrans Client Key</label>
                            <input type="text" class="form-control font-mono" name="midtrans_client_key"
                                   value="<?= htmlspecialchars($settings['midtrans_client_key'] ?? '') ?>"
                                   placeholder="SB-Mid-client-...">
                            <div class="form-text">Client Key dari dashboard Midtrans (Snap JS).</div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold">Midtrans Server Key</label>
                            <input type="password" class="form-control font-mono" name="midtrans_server_key"
                                   value="<?= htmlspecialchars($settings['midtrans_server_key'] ?? '') ?>"
                                   placeholder="SB-Mid-server-...">
                            <div class="form-text">Server Key untuk otentikasi request token &amp; signature webhook.</div>
                        </div>
                    </div>

                    <div class="alert alert-info mt-4 mb-0 d-flex gap-3 align-items-start">
                        <i class="bi bi-info-circle-fill fs-5 mt-1 text-primary"></i>
                        <div style="font-size: .88rem;">
                            <strong>Webhook / Notification URL Midtrans:</strong><br>
                            Salin URL berikut ke menu <em>Settings &gt; Configuration &gt; Payment Notification URL</em> di Dashboard Midtrans Anda:
                            <code class="d-block mt-1 p-2 bg-light border rounded text-dark">
                                https://<?= $_SERVER['HTTP_HOST'] ?? 'dash.snetwifi.com' ?>/portal/payment_webhook.php
                            </code>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── CARD 5: CRON AUTO-ISOLIR ── -->
        <div class="col-12">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3">
                    <h5 class="card-title text-warning mb-0 fw-bold"><i class="bi bi-clock-history me-2"></i>Jadwal Cron Auto-Isolir</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted mb-2" style="font-size:.88rem;">
                        Agar sistem dapat memeriksa jatuh tempo dan mengisolir pelanggan yang menunggak secara otomatis, pasang baris cron berikut di server/aaPanel Anda:
                    </p>
                    <div class="p-3 bg-dark text-light rounded font-mono" style="font-size:.82rem;">
                        0 1 * * * php <?= realpath(__DIR__ . '/../../process/cron_pppoe.php') ?: '/www/wwwroot/dash.snetwifi.com/process/cron_pppoe.php' ?> &gt;&gt; /tmp/cron_pppoe.log 2&gt;&amp;1
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="mt-4 mb-5 d-flex gap-2">
        <button type="submit" class="btn btn-primary btn-lg px-4 shadow">
            <i class="bi bi-save me-1"></i> Simpan Pengaturan
        </button>
        <a href="/index.php?page=pppoe_customers" class="btn btn-outline-secondary btn-lg px-4">Kembali ke Pelanggan</a>
    </div>
</form>

<?php include __DIR__ . '/../../include/footer.php'; ?>
