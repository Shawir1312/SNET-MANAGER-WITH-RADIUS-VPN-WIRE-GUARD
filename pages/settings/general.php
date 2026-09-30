<?php
/**
 * Settings — General (timezone, app name etc.)
 */
$page_title = 'Pengaturan';
auth_require_superadmin();

// Handle Restart FreeRADIUS Service
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'restart_freeradius') {
    $csrf = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
        flash_set('error', 'Token keamanan (CSRF) tidak valid.');
    } else {
        $res = restart_freeradius_service();
        if ($res['success']) {
            audit_log('service_restart', 'freeradius', 0, 'Restart service FreeRADIUS via menu Pengaturan');
            flash_set('success', $res['message']);
        } else {
            flash_set('error', $res['message']);
        }
    }
    header('Location: index.php?page=settings');
    exit;
}

$radiusStatus = get_freeradius_status();

include __DIR__ . '/../../include/header.php';
?>
<div class="page-header">
    <div><h1 class="page-title"><i class="bi bi-gear-wide-connected me-2 text-primary"></i>Pengaturan Aplikasi</h1><p class="page-subtitle">Konfigurasi umum aplikasi &amp; status service server</p></div>
</div>

<div class="row g-4">
    <!-- KARTU STATUS SERVICE FREERADIUS -->
    <div class="col-12">
        <div class="card <?= $radiusStatus['is_active'] ? 'border-success' : 'border-danger' ?> shadow-sm">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2 py-3 <?= $radiusStatus['is_active'] ? 'bg-success bg-opacity-10' : 'bg-danger bg-opacity-10' ?>">
                <div class="d-flex align-items-center gap-2">
                    <h5 class="card-title mb-0 fw-bold">
                        <i class="bi bi-hdd-network-fill me-2 <?= $radiusStatus['is_active'] ? 'text-success' : 'text-danger' ?>"></i>Status Service FreeRADIUS
                    </h5>
                    <span class="badge bg-secondary font-monospace"><?= htmlspecialchars($radiusStatus['service_name']) ?>.service</span>
                </div>
                <div>
                    <?php if ($radiusStatus['is_active']): ?>
                        <span class="badge bg-success fs-6 px-3 py-2 rounded-pill d-inline-flex align-items-center">
                            <span class="spinner-grow spinner-grow-sm me-2" role="status" style="width:0.6rem;height:0.6rem;"></span>
                            <strong>AKTIF (RUNNING)</strong>
                        </span>
                    <?php else: ?>
                        <span class="badge bg-danger fs-6 px-3 py-2 rounded-pill d-inline-flex align-items-center">
                            <i class="bi bi-x-circle-fill me-2"></i>
                            <strong>NONAKTIF / BERHENTI</strong>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body p-4">
                <?php if (!$radiusStatus['is_active']): ?>
                    <div class="alert alert-danger d-flex gap-2 align-items-start mb-4">
                        <i class="bi bi-exclamation-triangle-fill fs-5 mt-1 flex-shrink-0"></i>
                        <div>
                            <strong>Peringatan: Service FreeRADIUS Sedang Mati / Berhenti!</strong><br>
                            Router MikroTik tidak dapat melakukan autentikasi login voucher hotspot maupun PPPoE saat service FreeRADIUS tidak aktif.
                            Klik tombol <strong>"Restart / Start Service"</strong> di bawah atau jalankan perintah SSH berikut di terminal:
                            <code class="d-block mt-1 p-2 bg-dark text-white rounded">sudo systemctl start freeradius &amp;&amp; sudo systemctl enable freeradius</code>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="row g-3 text-center mb-4">
                    <!-- Unit & Status -->
                    <div class="col-6 col-md-3">
                        <div class="p-3 border rounded h-100 bg-light">
                            <div class="text-muted small mb-1">Status Mesin RADIUS</div>
                            <div class="fw-bold fs-5 <?= $radiusStatus['is_active'] ? 'text-success' : 'text-danger' ?>">
                                <?= htmlspecialchars($radiusStatus['status_label']) ?>
                            </div>
                            <div class="text-muted small mt-1 font-monospace">
                                PID: <?= $radiusStatus['pid'] ? ('#' . $radiusStatus['pid']) : '-' ?>
                            </div>
                        </div>
                    </div>

                    <!-- Port 1812 UDP -->
                    <div class="col-6 col-md-3">
                        <div class="p-3 border rounded h-100 bg-light">
                            <div class="text-muted small mb-1">Port Autentikasi (Auth)</div>
                            <div class="fw-bold fs-5 <?= $radiusStatus['port_1812'] ? 'text-success' : 'text-danger' ?>">
                                UDP 1812
                            </div>
                            <div class="mt-1">
                                <?php if ($radiusStatus['port_1812']): ?>
                                    <span class="badge bg-success-subtle text-success border border-success"><i class="bi bi-check2"></i> Listening / Terbuka</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Tidak Terdeteksi</span>
                                <?php endif; ?>
                            </div>
                            <div class="text-muted small mt-1" style="font-size:0.75rem;">Login Hotspot &amp; PPPoE</div>
                        </div>
                    </div>

                    <!-- Port 1813 UDP -->
                    <div class="col-6 col-md-3">
                        <div class="p-3 border rounded h-100 bg-light">
                            <div class="text-muted small mb-1">Port Akuntansi (Acct)</div>
                            <div class="fw-bold fs-5 <?= $radiusStatus['port_1813'] ? 'text-success' : 'text-danger' ?>">
                                UDP 1813
                            </div>
                            <div class="mt-1">
                                <?php if ($radiusStatus['port_1813']): ?>
                                    <span class="badge bg-success-subtle text-success border border-success"><i class="bi bi-check2"></i> Listening / Terbuka</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Tidak Terdeteksi</span>
                                <?php endif; ?>
                            </div>
                            <div class="text-muted small mt-1" style="font-size:0.75rem;">Catatan Kuota &amp; Sesi</div>
                        </div>
                    </div>

                    <!-- Uptime & Versi -->
                    <div class="col-6 col-md-3">
                        <div class="p-3 border rounded h-100 bg-light">
                            <div class="text-muted small mb-1">Versi &amp; Penggunaan RAM</div>
                            <div class="fw-bold fs-6 text-dark text-truncate" title="<?= htmlspecialchars($radiusStatus['version'] ?: 'FreeRADIUS 3.x') ?>">
                                <?= htmlspecialchars($radiusStatus['version'] ?: 'FreeRADIUS 3.x') ?>
                            </div>
                            <div class="text-muted small mt-1">
                                RAM: <strong><?= htmlspecialchars($radiusStatus['memory_human'] ?: '-') ?></strong> | Uptime: <strong><?= htmlspecialchars($radiusStatus['uptime_human'] ?: '-') ?></strong>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tombol Aksi FreeRADIUS -->
                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center pt-2 border-top">
                    <div class="d-flex flex-wrap gap-2">
                        <form method="POST" action="index.php?page=settings" class="d-inline" onsubmit="return confirm('Restart service FreeRADIUS di server?\n\nService akan direfresh dan membaca ulang konfigurasi.');">
                            <input type="hidden" name="action" value="restart_freeradius">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-arrow-clockwise me-1"></i>Restart Service FreeRADIUS
                            </button>
                        </form>
                        <a href="index.php?page=backup#sinkronisasi-radius" class="btn btn-outline-warning text-dark fw-bold">
                            <i class="bi bi-arrow-repeat me-1"></i>Sinkronisasi Database FreeRADIUS
                        </a>
                    </div>
                    <div>
                        <a href="index.php?page=settings" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-arrow-repeat me-1"></i>Refresh Status
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-md-6">
        <div class="card">
            <div class="card-header"><h5 class="card-title"><i class="bi bi-info-circle"></i> Informasi Sistem</h5></div>
            <div class="card-body">
                <table class="table table-sm">
                    <tr><th>Versi Aplikasi</th><td><?= APP_VERSION ?></td></tr>
                    <tr><th>PHP Version</th><td><?= phpversion() ?></td></tr>
                    <tr><th>Database</th><td><?= DB_NAME ?> @ <?= DB_HOST ?></td></tr>
                    <tr><th>Timezone</th><td><?= APP_TIMEZONE ?></td></tr>
                    <tr><th>Waktu Server</th><td><?= date('d M Y H:i:s') ?></td></tr>
                </table>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6">
        <div class="card">
            <div class="card-header"><h5 class="card-title"><i class="bi bi-database"></i> Status Database</h5></div>
            <div class="card-body">
                <?php
                $tables = ['radcheck','radreply','radacct','nas','routers','profiles','vouchers','admins'];
                foreach ($tables as $t):
                    $cnt = (int)(db_fetch_one("SELECT COUNT(*) AS n FROM `{$t}`")['n'] ?? 0);
                ?>
                <div class="d-flex justify-content-between border-bottom py-1" style="font-size:.82rem;">
                    <span class="font-mono"><?= $t ?></span>
                    <span class="badge bg-secondary"><?= number_format($cnt) ?> baris</span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="col-12">
        <div class="card">
            <div class="card-header"><h5 class="card-title"><i class="bi bi-terminal"></i> Konfigurasi FreeRADIUS</h5></div>
            <div class="card-body">
                <p class="text-muted">Tambahkan konfigurasi berikut ke file FreeRADIUS Anda:</p>
                <div class="bg-dark text-light p-3 rounded" style="font-family:monospace;font-size:.8rem;">
<pre class="mb-0 text-light"># /etc/freeradius/3.0/mods-available/sql
sql {
    driver = "rlm_sql_mysql"
    dialect = "mysql"
    server = "127.0.0.1"
    port = 3306
    login = "<?= DB_USER ?>"
    password = "***"
    radius_db = "<?= DB_NAME ?>"
    
    # FreeRADIUS standard table names (sudah dibuat oleh installer)
    acct_table1 = "radacct"
    acct_table2 = "radacct"
    postauth_table = "radpostauth"
    authcheck_table = "radcheck"
    authreply_table = "radreply"
    groupcheck_table = "radgroupcheck"
    groupreply_table = "radgroupreply"
    usergroup_table = "radusergroup"
    nas_table = "nas"
    
    # Nama user untuk query
    sql_user_name = "%{%{Stripped-User-Name}:-%{%{User-Name}:-DEFAULT}}"
    default_user_profile = ""

    # Simultaneous-Use check (cegah 1 voucher dipakai banyak orang)
    simul_count_query = "SELECT COUNT(*) FROM ${acct_table1} WHERE username = '%{SQL-User-Name}' AND acctstoptime IS NULL"
    simul_verify_query = "SELECT radacctid, acctsessionid, username, nasipaddress, nasportid, framedipaddress, callingstationid, framedprotocol FROM ${acct_table1} WHERE username = '%{SQL-User-Name}' AND acctstoptime IS NULL"

    read_clients = yes
    client_table = "nas"
    group_attribute = "SQL-Group"

    # Karakter aman untuk query
    safe_characters = "@abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789.-_: /"

    # Muat query standar FreeRADIUS untuk MySQL
    $INCLUDE ${modconfdir}/${.:name}/main/${dialect}/queries.conf
}

# /etc/freeradius/3.0/clients.conf
# (Akan otomatis dibaca dari tabel 'nas' jika read_clients = yes)</pre>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title"><i class="bi bi-clock-history"></i> Pengaturan Cron Job (Otomatisasi)</h5>
                <span class="badge bg-success">5 Cron Job Aktif</span>
            </div>
            <div class="card-body">
                <p class="text-muted mb-3">Tambahkan semua cron job berikut di server Anda (<strong>cPanel → Cron Jobs</strong> atau <code>crontab -e</code>). Semua cron aman dijalankan bersamaan — masing-masing menggunakan <strong>lock file</strong> untuk mencegah tumpukan proses.</p>

                <!-- Tabel Ringkasan -->
                <div class="table-responsive mb-3">
                    <table class="table table-sm table-bordered align-middle" style="font-size:.82rem;">
                        <thead class="table-dark">
                            <tr>
                                <th>#</th>
                                <th>Nama File</th>
                                <th>Jadwal</th>
                                <th>Fungsi</th>
                                <th>Lock?</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>1</td>
                                <td><code>auto_clear_ghosts.php</code></td>
                                <td><span class="badge bg-primary">Tiap 1 menit</span></td>
                                <td>Bersihkan sesi hantu di radacct yang tidak ada di MikroTik (PPPoE disconnect tidak tercatat)</td>
                                <td><span class="badge bg-success">✓ Aman</span></td>
                            </tr>
                            <tr>
                                <td>2</td>
                                <td><code>expire_vouchers.php</code></td>
                                <td><span class="badge bg-primary">Tiap 5 menit</span></td>
                                <td>Putuskan & hapus voucher hotspot yang sudah lewat masa berlaku</td>
                                <td><span class="badge bg-success">✓ Aman</span></td>
                            </tr>
                            <tr class="table-success">
                                <td>3</td>
                                <td><code>cron_bandwidth_snapshot.php</code></td>
                                <td><span class="badge bg-success">Tiap 5 menit</span></td>
                                <td>🆕 Checkpoint counter bandwidth MikroTik agar pemakaian data tidak hilang saat router restart</td>
                                <td><span class="badge bg-success">✓ Aman</span></td>
                            </tr>
                            <tr>
                                <td>4</td>
                                <td><code>cleanup_ont_remotes.php</code></td>
                                <td><span class="badge bg-primary">Tiap 1 menit</span></td>
                                <td>Tutup akses port-forward remote ONT yang sudah melewati batas 15 menit</td>
                                <td><span class="badge bg-success">✓ Aman</span></td>
                            </tr>
                            <tr>
                                <td>5</td>
                                <td><code>cron_pppoe_reminder.php</code></td>
                                <td><span class="badge bg-warning text-dark">Setiap hari 08:00</span></td>
                                <td>Kirim pesan WhatsApp pengingat tagihan PPPoE (H-3, H-1, H+0)</td>
                                <td><span class="badge bg-success">✓ Aman</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Langkah pasang crontab -->
                <h6 class="fw-bold mb-2"><i class="bi bi-terminal me-1"></i> Cara Pasang di Terminal (crontab -e):</h6>
                <ol class="text-muted small mb-3">
                    <li>SSH ke server / VPS Anda</li>
                    <li>Ketik <code>crontab -e</code></li>
                    <li>Salin semua baris kode di bawah ke bagian paling bawah</li>
                    <li>Simpan (nano: <code>Ctrl+X</code> → <code>Y</code> → <code>Enter</code>)</li>
                </ol>

                <!-- Blok kode cron lengkap -->
                <div class="bg-dark text-light p-3 rounded" style="font-family:monospace;font-size:.78rem;line-height:1.9;">
<pre class="mb-0 text-light"><?php
$phpBin = '/usr/bin/php'; // Ganti sesuai server: /www/server/php/81/bin/php
$base   = realpath(__DIR__ . '/../../');
echo "# ── S.NET Manager — Cron Jobs ──\n";
echo "# [1] Bersihkan sesi hantu radacct vs MikroTik (setiap 1 menit)\n";
echo "* * * * *   {$phpBin} {$base}/cron/auto_clear_ghosts.php &gt;&gt; /var/log/snet_ghosts.log 2&gt;&amp;1\n\n";
echo "# [2] Expire voucher yang habis masa berlaku (setiap 5 menit)\n";
echo "*/5 * * * * {$phpBin} {$base}/cron/expire_vouchers.php &gt;&gt; /var/log/snet_expire.log 2&gt;&amp;1\n\n";
echo "# [3] Snapshot bandwidth bulanan tahan restart MikroTik (setiap 5 menit)\n";
echo "*/5 * * * * {$phpBin} {$base}/cron/cron_bandwidth_snapshot.php &gt;&gt; /var/log/snet_bw_snap.log 2&gt;&amp;1\n\n";
echo "# [4] Bersihkan akses remote ONT yang sudah kedaluwarsa (setiap 1 menit)\n";
echo "* * * * *   {$phpBin} {$base}/cron/cleanup_ont_remotes.php &gt;/dev/null 2&gt;&amp;1\n\n";
echo "# [5] Kirim reminder tagihan via WhatsApp (setiap hari jam 08:00 WIT)\n";
echo "0 8 * * *   {$phpBin} {$base}/cron/cron_pppoe_reminder.php &gt;&gt; /var/log/snet_wa_reminder.log 2&gt;&amp;1";
?></pre>
                </div>

                <div class="alert alert-info mt-3 mb-0" style="font-size:0.82rem;">
                    <i class="bi bi-lightbulb-fill me-2 text-warning"></i>
                    <strong>Tips:</strong> Ganti <code>/usr/bin/php</code> dengan path PHP CLI di server Anda.
                    Cek dengan perintah: <code>which php</code> atau <code>php -v</code> di terminal server.
                    Pengguna <strong>aaPanel</strong> umumnya: <code>/www/server/php/81/bin/php</code>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../include/footer.php'; ?>

