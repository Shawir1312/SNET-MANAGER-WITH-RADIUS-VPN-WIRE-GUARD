<?php
/**
 * Settings — General (timezone, app name etc.)
 */
$page_title = 'Pengaturan';
auth_require_superadmin();
include __DIR__ . '/../../include/header.php';
?>
<div class="page-header">
    <div><h1 class="page-title">Pengaturan Aplikasi</h1><p class="page-subtitle">Konfigurasi umum aplikasi</p></div>
</div>

<div class="row g-4">
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

