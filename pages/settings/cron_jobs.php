<?php
/**
 * S.NET RADIUS Manager — Panduan & Manajemen Cron Job (Master & Node)
 */

if (!defined('IN_APP')) { define('IN_APP', true); }
require_once __DIR__ . '/../../include/redundant_functions.php';

$page_title = 'Panduan & Jadwal Cron Job';
$isNode = is_redundant_node();
$cfg = get_redundant_settings();

// Deteksi otomatis direktori aplikasi dan PHP binary
$basePath = realpath(__DIR__ . '/../../');
$phpBin = PHP_BINARY;
if (!file_exists($phpBin) || strpos($phpBin, 'php-fpm') !== false) {
    $phpBin = 'php';
}

include __DIR__ . '/../../include/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
        <h1 class="page-title"><i class="bi bi-clock-history text-primary me-2"></i>Panduan &amp; Jadwal Cron Job Otomatisasi</h1>
        <p class="page-subtitle mb-0">Daftar perintah cron job dan panduan pemasangan di aaPanel untuk Cloud VPS dan Mini PC Cabang</p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <span class="badge <?= $isNode ? 'bg-warning text-dark' : 'bg-primary' ?> p-2 fs-6">
            <i class="bi <?= $isNode ? 'bi-hdd-network' : 'bi-cloud-check' ?> me-1"></i>
            Peran Server Ini: <strong><?= $isNode ? 'Node Cabang Lokal (Mini PC)' : 'Server Pusat (Master VPS)' ?></strong>
        </span>
    </div>
</div>

<!-- Petunjuk Cepat aaPanel -->
<div class="card shadow-sm border-0 mb-4" style="background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); color: #fff;">
    <div class="card-body p-4">
        <div class="d-flex align-items-start gap-3">
            <div class="fs-1 text-warning"><i class="bi bi-lightbulb-fill"></i></div>
            <div>
                <h5 class="fw-bold mb-1 text-white">Cara Memasang Cron di aaPanel GUI (Mudah &amp; Cepat)</h5>
                <p class="text-light opacity-75 mb-3" style="font-size: 0.9rem;">
                    Buka panel aaPanel di browser Anda &rarr; pilih menu <strong>Cron</strong> di bilah menu kiri &rarr; klik <strong>Add task</strong>:
                </p>
                <div class="row g-3">
                    <div class="col-12 col-md-3">
                        <div class="bg-white bg-opacity-10 p-2 rounded small">
                            <span class="text-warning fw-bold">1. Type of Task:</span><br>Pilih <code>Shell Script</code>
                        </div>
                    </div>
                    <div class="col-12 col-md-3">
                        <div class="bg-white bg-opacity-10 p-2 rounded small">
                            <span class="text-warning fw-bold">2. Name of Task:</span><br>Isi nama tugas (bebas)
                        </div>
                    </div>
                    <div class="col-12 col-md-3">
                        <div class="bg-white bg-opacity-10 p-2 rounded small">
                            <span class="text-warning fw-bold">3. Execution cycle:</span><br>Pilih interval waktu (menit/jam)
                        </div>
                    </div>
                    <div class="col-12 col-md-3">
                        <div class="bg-white bg-opacity-10 p-2 rounded small">
                            <span class="text-warning fw-bold">4. Script content:</span><br>Klik tombol <strong>Salin</strong> di bawah &amp; paste
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Navigasi Tab Peran -->
<ul class="nav nav-pills mb-4" id="cronTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $isNode ? 'active' : '' ?> fw-bold d-flex align-items-center gap-2" id="node-tab" data-bs-toggle="pill" data-bs-target="#tab-node" type="button" role="tab">
            <i class="bi bi-hdd-network"></i>
            1. Cron Mini PC Cabang (Node Lokal)
            <?php if ($isNode): ?><span class="badge bg-light text-dark ms-1">Server Ini</span><?php endif; ?>
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= !$isNode ? 'active' : '' ?> fw-bold d-flex align-items-center gap-2" id="vps-tab" data-bs-toggle="pill" data-bs-target="#tab-vps" type="button" role="tab">
            <i class="bi bi-cloud-check"></i>
            2. Cron Cloud VPS (Server Pusat)
            <?php if (!$isNode): ?><span class="badge bg-light text-dark ms-1">Server Ini</span><?php endif; ?>
        </button>
    </li>
</ul>

<div class="tab-content" id="cronTabsContent">

    <!-- ==================== TAB 1: MINI PC CABANG ==================== -->
    <div class="tab-pane fade <?= $isNode ? 'show active' : '' ?>" id="tab-node" role="tabpanel">
        
        <div class="alert alert-info d-flex align-items-center gap-3 mb-4">
            <i class="bi bi-info-circle-fill fs-3 text-info"></i>
            <div>
                <strong>Aturan Emas Mini PC Cabang:</strong><br>
                Mini PC bertindak sebagai <em>Edge Survivor Node</em>. Tugas utamanya adalah sinkronisasi data dengan VPS. Cron pembersihan sesi di bawah dilengkapi <strong>Sensor Otomatis (Standby Pasif)</strong> sehingga hanya akan aktif membersihkan router saat VPS Master terdeteksi mati.
            </div>
        </div>

        <div class="row g-4">
            <!-- 1. Cron Sync Replikasi -->
            <div class="col-12">
                <div class="card shadow-sm border-start border-primary border-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                            <div>
                                <span class="badge bg-danger mb-1">WAJIB DI NODE</span>
                                <h5 class="card-title fw-bold mb-1">1. Sinkronisasi Real-Time Database (PULL &amp; PUSH)</h5>
                                <p class="text-muted small mb-0">Menarik seluruh voucher &amp; paket baru dari Cloud VPS, serta menyetor data pemakaian voucher lokal ke VPS.</p>
                            </div>
                            <span class="badge bg-dark font-mono p-2"><i class="bi bi-stopwatch me-1"></i>Setiap 1 Menit</span>
                        </div>
                        <div class="bg-dark p-3 rounded d-flex justify-content-between align-items-center mt-3">
                            <code class="text-warning font-mono" id="cmd_sync_node" style="font-size: 0.95rem;">
                                <?= $phpBin ?> <?= $basePath ?>/cron/cron_redundant_sync.php >/dev/null 2>&1
                            </code>
                            <button class="btn btn-sm btn-outline-light ms-2 flex-shrink-0" onclick="copyCode('cmd_sync_node')">
                                <i class="bi bi-clipboard me-1"></i>Salin Perintah
                            </button>
                        </div>
                        <div class="mt-2 text-muted small">
                            <i class="bi bi-check2-circle text-success me-1"></i>Siklus di aaPanel: Pilih <strong>N Minutes</strong> &rarr; isi angka <code>1</code>.
                        </div>
                    </div>
                </div>
            </div>

            <!-- 2. Cron Sesi Hantu Standby -->
            <div class="col-12">
                <div class="card shadow-sm border-start border-warning border-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                            <div>
                                <span class="badge bg-warning text-dark mb-1">STANDBY OTOMATIS</span>
                                <h5 class="card-title fw-bold mb-1">2. Pembersihan Sesi Hantu MikroTik Lokal (Darurat Saat VPS Mati)</h5>
                                <p class="text-muted small mb-0">Otomatis tidur saat VPS normal. Otomatis bangun mengambil alih pembersihan sesi hantu router cabang saat Cloud VPS mati/putus.</p>
                            </div>
                            <span class="badge bg-dark font-mono p-2"><i class="bi bi-stopwatch me-1"></i>Setiap 1 Menit</span>
                        </div>
                        <div class="bg-dark p-3 rounded d-flex justify-content-between align-items-center mt-3">
                            <code class="text-warning font-mono" id="cmd_ghost_node" style="font-size: 0.95rem;">
                                <?= $phpBin ?> <?= $basePath ?>/cron/auto_clear_ghosts.php >/dev/null 2>&1
                            </code>
                            <button class="btn btn-sm btn-outline-light ms-2 flex-shrink-0" onclick="copyCode('cmd_ghost_node')">
                                <i class="bi bi-clipboard me-1"></i>Salin Perintah
                            </button>
                        </div>
                        <div class="mt-2 text-muted small">
                            <i class="bi bi-check2-circle text-success me-1"></i>Siklus di aaPanel: Pilih <strong>N Minutes</strong> &rarr; isi angka <code>1</code>.
                        </div>
                    </div>
                </div>
            </div>

            <!-- 3. Cron Expire Voucher Standby -->
            <div class="col-12">
                <div class="card shadow-sm border-start border-secondary border-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                            <div>
                                <span class="badge bg-secondary mb-1">STANDBY OTOMATIS</span>
                                <h5 class="card-title fw-bold mb-1">3. Auto-Expire Voucher Hotspot Lokal (Darurat Saat VPS Mati)</h5>
                                <p class="text-muted small mb-0">Otomatis tidur saat VPS normal. Otomatis bangun memutus voucher yang masa aktifnya habis di router lokal jika VPS mati.</p>
                            </div>
                            <span class="badge bg-dark font-mono p-2"><i class="bi bi-stopwatch me-1"></i>Setiap 5 Menit</span>
                        </div>
                        <div class="bg-dark p-3 rounded d-flex justify-content-between align-items-center mt-3">
                            <code class="text-warning font-mono" id="cmd_expire_node" style="font-size: 0.95rem;">
                                <?= $phpBin ?> <?= $basePath ?>/cron/expire_vouchers.php >/dev/null 2>&1
                            </code>
                            <button class="btn btn-sm btn-outline-light ms-2 flex-shrink-0" onclick="copyCode('cmd_expire_node')">
                                <i class="bi bi-clipboard me-1"></i>Salin Perintah
                            </button>
                        </div>
                        <div class="mt-2 text-muted small">
                            <i class="bi bi-check2-circle text-success me-1"></i>Siklus di aaPanel: Pilih <strong>N Minutes</strong> &rarr; isi angka <code>5</code>.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ==================== TAB 2: CLOUD VPS MASTER ==================== -->
    <div class="tab-pane fade <?= !$isNode ? 'show active' : '' ?>" id="tab-vps" role="tabpanel">

        <div class="alert alert-primary d-flex align-items-center gap-3 mb-4">
            <i class="bi bi-cloud-check-fill fs-3 text-primary"></i>
            <div>
                <strong>Pusat Pengendali Cloud VPS Master:</strong><br>
                Seluruh operasional terpusat dijalankan di Cloud VPS. VPS memonitor router seluruh cabang, mengirim pengingat tagihan WhatsApp otomatis, dan memelihara snapshot data.
            </div>
        </div>

        <div class="row g-4">
            <!-- 1. Hapus Sesi Hantu Master -->
            <div class="col-12">
                <div class="card shadow-sm border-start border-primary border-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                            <div>
                                <span class="badge bg-primary mb-1">OPERASIONAL UTAMA</span>
                                <h5 class="card-title fw-bold mb-1">1. Pembersihan Sesi Hantu MikroTik Real-Time</h5>
                                <p class="text-muted small mb-0">Menyelaraskan daftar user aktif di Winbox seluruh router cabang dengan database RADIUS pusat &amp; menutup sesi zombie.</p>
                            </div>
                            <span class="badge bg-dark font-mono p-2"><i class="bi bi-stopwatch me-1"></i>Setiap 1 Menit</span>
                        </div>
                        <div class="bg-dark p-3 rounded d-flex justify-content-between align-items-center mt-3">
                            <code class="text-warning font-mono" id="cmd_ghost_vps" style="font-size: 0.95rem;">
                                <?= $phpBin ?> <?= $basePath ?>/cron/auto_clear_ghosts.php >/dev/null 2>&1
                            </code>
                            <button class="btn btn-sm btn-outline-light ms-2 flex-shrink-0" onclick="copyCode('cmd_ghost_vps')">
                                <i class="bi bi-clipboard me-1"></i>Salin Perintah
                            </button>
                        </div>
                        <div class="mt-2 text-muted small"><i class="bi bi-check2-circle text-success me-1"></i>aaPanel: N Minutes &rarr; <code>1</code>.</div>
                    </div>
                </div>
            </div>

            <!-- 2. Auto-Expire Voucher Master -->
            <div class="col-12">
                <div class="card shadow-sm border-start border-info border-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                            <div>
                                <span class="badge bg-info text-dark mb-1">OPERASIONAL UTAMA</span>
                                <h5 class="card-title fw-bold mb-1">2. Auto-Expire Voucher Hotspot Pusat</h5>
                                <p class="text-muted small mb-0">Mengecek sesi yang melewati Session-Timeout, mengkadaluarsakan voucher, dan membersihkan radcheck pusat.</p>
                            </div>
                            <span class="badge bg-dark font-mono p-2"><i class="bi bi-stopwatch me-1"></i>Setiap 5 Menit</span>
                        </div>
                        <div class="bg-dark p-3 rounded d-flex justify-content-between align-items-center mt-3">
                            <code class="text-warning font-mono" id="cmd_expire_vps" style="font-size: 0.95rem;">
                                <?= $phpBin ?> <?= $basePath ?>/cron/expire_vouchers.php >/dev/null 2>&1
                            </code>
                            <button class="btn btn-sm btn-outline-light ms-2 flex-shrink-0" onclick="copyCode('cmd_expire_vps')">
                                <i class="bi bi-clipboard me-1"></i>Salin Perintah
                            </button>
                        </div>
                        <div class="mt-2 text-muted small"><i class="bi bi-check2-circle text-success me-1"></i>aaPanel: N Minutes &rarr; <code>5</code>.</div>
                    </div>
                </div>
            </div>

            <!-- 3. WhatsApp PPPoE Reminder -->
            <div class="col-12">
                <div class="card shadow-sm border-start border-success border-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                            <div>
                                <span class="badge bg-success mb-1">BILLING WHATSAPP</span>
                                <h5 class="card-title fw-bold mb-1">3. Pengingat Tagihan WhatsApp PPPoE Otomatis</h5>
                                <p class="text-muted small mb-0">Mengirimkan pesan penagihan otomatis ke pelanggan PPPoE yang jatuh tempo pada H-3, H-1, dan hari H.</p>
                            </div>
                            <span class="badge bg-dark font-mono p-2"><i class="bi bi-clock me-1"></i>Setiap Hari Jam 08:00 Pagi</span>
                        </div>
                        <div class="bg-dark p-3 rounded d-flex justify-content-between align-items-center mt-3">
                            <code class="text-warning font-mono" id="cmd_wa_vps" style="font-size: 0.95rem;">
                                <?= $phpBin ?> <?= $basePath ?>/cron/cron_pppoe_reminder.php >/dev/null 2>&1
                            </code>
                            <button class="btn btn-sm btn-outline-light ms-2 flex-shrink-0" onclick="copyCode('cmd_wa_vps')">
                                <i class="bi bi-clipboard me-1"></i>Salin Perintah
                            </button>
                        </div>
                        <div class="mt-2 text-muted small"><i class="bi bi-check2-circle text-success me-1"></i>aaPanel: Pilih <strong>Day</strong> &rarr; Hour: <code>8</code>, Minute: <code>0</code>.</div>
                    </div>
                </div>
            </div>

            <!-- 4. Bandwidth Snapshot -->
            <div class="col-12">
                <div class="card shadow-sm border-start border-secondary border-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                            <div>
                                <span class="badge bg-secondary mb-1">AKUMULASI KUOTA</span>
                                <h5 class="card-title fw-bold mb-1">4. Snapshot Bandwidth PPPoE (Tahan Restart MikroTik)</h5>
                                <p class="text-muted small mb-0">Merekam data counter byte traffic pelanggan PPPoE agar hitungan kuota bulanan tidak pernah kembali ke 0 saat router reboot.</p>
                            </div>
                            <span class="badge bg-dark font-mono p-2"><i class="bi bi-stopwatch me-1"></i>Setiap 5 Menit</span>
                        </div>
                        <div class="bg-dark p-3 rounded d-flex justify-content-between align-items-center mt-3">
                            <code class="text-warning font-mono" id="cmd_bw_vps" style="font-size: 0.95rem;">
                                <?= $phpBin ?> <?= $basePath ?>/cron/cron_bandwidth_snapshot.php >/dev/null 2>&1
                            </code>
                            <button class="btn btn-sm btn-outline-light ms-2 flex-shrink-0" onclick="copyCode('cmd_bw_vps')">
                                <i class="bi bi-clipboard me-1"></i>Salin Perintah
                            </button>
                        </div>
                        <div class="mt-2 text-muted small"><i class="bi bi-check2-circle text-success me-1"></i>aaPanel: N Minutes &rarr; <code>5</code>.</div>
                    </div>
                </div>
            </div>

            <!-- 5. WireGuard Watchdog -->
            <div class="col-12">
                <div class="card shadow-sm border-start border-dark border-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                            <div>
                                <span class="badge bg-dark mb-1">INFRASTRUKTUR VPN</span>
                                <h5 class="card-title fw-bold mb-1">5. Watchdog Auto-Recovery WireGuard VPN</h5>
                                <p class="text-muted small mb-0">Memantau kesehatan interface kernel wg0 dan me-restart koneksi jika terjadi deadlock jaringan.</p>
                            </div>
                            <span class="badge bg-dark font-mono p-2"><i class="bi bi-stopwatch me-1"></i>Setiap 2 Menit</span>
                        </div>
                        <div class="bg-dark p-3 rounded d-flex justify-content-between align-items-center mt-3">
                            <code class="text-warning font-mono" id="cmd_wg_vps" style="font-size: 0.95rem;">
                                <?= $phpBin ?> <?= $basePath ?>/cron/cron_wireguard_watchdog.php >/dev/null 2>&1
                            </code>
                            <button class="btn btn-sm btn-outline-light ms-2 flex-shrink-0" onclick="copyCode('cmd_wg_vps')">
                                <i class="bi bi-clipboard me-1"></i>Salin Perintah
                            </button>
                        </div>
                        <div class="mt-2 text-muted small"><i class="bi bi-check2-circle text-success me-1"></i>aaPanel: N Minutes &rarr; <code>2</code>.</div>
                    </div>
                </div>
            </div>

            <!-- 6. Cleanup Remote ONT -->
            <div class="col-12">
                <div class="card shadow-sm border-start border-warning border-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                            <div>
                                <span class="badge bg-warning text-dark mb-1">KEAMANAN REMOTE</span>
                                <h5 class="card-title fw-bold mb-1">6. Cleanup Akses Remote Sementara ONT (15 Menit)</h5>
                                <p class="text-muted small mb-0">Menutup port forwarding NAT publik untuk akses remote web GUI modem ONT pelanggan yang telah melewati batas 15 menit.</p>
                            </div>
                            <span class="badge bg-dark font-mono p-2"><i class="bi bi-stopwatch me-1"></i>Setiap 2 Menit</span>
                        </div>
                        <div class="bg-dark p-3 rounded d-flex justify-content-between align-items-center mt-3">
                            <code class="text-warning font-mono" id="cmd_ont_vps" style="font-size: 0.95rem;">
                                <?= $phpBin ?> <?= $basePath ?>/cron/cleanup_ont_remotes.php >/dev/null 2>&1
                            </code>
                            <button class="btn btn-sm btn-outline-light ms-2 flex-shrink-0" onclick="copyCode('cmd_ont_vps')">
                                <i class="bi bi-clipboard me-1"></i>Salin Perintah
                            </button>
                        </div>
                        <div class="mt-2 text-muted small"><i class="bi bi-check2-circle text-success me-1"></i>aaPanel: N Minutes &rarr; <code>2</code>.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
function copyCode(elementId) {
    const text = document.getElementById(elementId).innerText.trim();
    navigator.clipboard.writeText(text).then(() => {
        alert('✓ Perintah cron berhasil disalin!\n\n' + text + '\n\nSilakan paste ke kolom Script content di aaPanel.');
    }).catch(err => {
        prompt('Salin perintah cron berikut:', text);
    });
}
</script>

<?php include __DIR__ . '/../../include/footer.php'; ?>
