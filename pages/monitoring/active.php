<?php
/**
 * Monitoring — Active Users (real-time from radacct)
 */
$page_title       = 'User Aktif';
$show_router_filter = true;
$all_routers      = get_all_routers();
$filter_router    = (int)get('router_id');

include __DIR__ . '/../../include/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">User Aktif</h1>
        <p class="page-subtitle">Sesi hotspot yang sedang berlangsung (real-time dari radacct)</p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <span class="badge bg-success fs-6" id="active-count">—</span>
        <span class="text-muted" style="font-size:.8rem;">user aktif</span>
        <button class="btn btn-outline-primary btn-sm ms-2" onclick="refreshActiveUsers()">
            <i class="bi bi-arrow-clockwise me-1"></i>Refresh
        </button>
        <button class="btn btn-outline-danger btn-sm ms-1" onclick="clearGhostSessions()" id="btnClearGhosts" title="Bersihkan sesi menggantung dari router yang sedang offline">
            <i class="bi bi-trash3 me-1"></i>Bersihkan Sesi Router Offline
        </button>
    </div>
</div>

<!-- Filter -->
<div class="card mb-3">
    <div class="card-body py-2">
        <div class="d-flex gap-3 align-items-center flex-wrap">
            <label class="form-label mb-0 fw-600">Filter Router:</label>
            <select class="form-select form-select-sm" style="width:220px;" id="filter-router"
                    onchange="refreshActiveUsers()">
                <option value="">Semua Router</option>
                <?php foreach ($all_routers as $r): ?>
                <option value="<?= $r['id'] ?>" <?= $filter_router == $r['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($r['name']) ?> — <?= htmlspecialchars($r['ip_address']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <div class="text-muted ms-auto" style="font-size:.75rem;" id="last-refresh">
                Auto-refresh setiap 30 detik
            </div>
        </div>
    </div>
</div>

<!-- Active Users Table -->
<div class="card table-card">
    <div class="table-responsive">
        <table class="table" id="active-users-table">
            <thead><tr>
                <th>Username</th>
                <th>Router / NAS</th>
                <th>MAC Address</th>
                <th>IP Address</th>
                <th>Durasi</th>
                <th>Sisa Waktu</th>
                <th>Download</th>
                <th>Upload</th>
                <th>Profil</th>
                <th>Aksi</th>
            </tr></thead>
            <tbody>
                <tr><td colspan="10" class="text-center text-muted py-4">
                    <div class="spinner-border spinner-border-sm me-2"></div>Memuat data...
                </td></tr>
            </tbody>
        </table>
    </div>
</div>

<script>
// Override refreshActiveUsers to use our filter
function refreshActiveUsers() {
    const router = document.getElementById('filter-router')?.value || '';
    const tbody  = document.querySelector('#active-users-table tbody');
    if (!tbody) return;

    fetch(`/ajax/active_users.php?router_id=${encodeURIComponent(router)}&_t=${new Date().getTime()}`)
        .then(r => r.json())
        .then(data => {
            const count = document.getElementById('active-count');
            const last  = document.getElementById('last-refresh');
            if (count) count.textContent = data.count || 0;
            if (last)  last.textContent  = 'Diperbarui: ' + new Date().toLocaleTimeString('id-ID');

            if (!data.users || data.users.length === 0) {
                tbody.innerHTML = `<tr><td colspan="10" class="text-center text-muted py-5">
                    <i class="bi bi-wifi-off display-4 d-block mb-2"></i>Tidak ada user aktif</td></tr>`;
                return;
            }
            tbody.innerHTML = data.users.map(u => `
                <tr>
                    <td class="font-mono fw-600">${escHtml(u.username)}</td>
                    <td>
                        <div style="font-size:.8rem;">${escHtml(u.router_name || '-')}</div>
                        <div class="font-mono text-muted" style="font-size:.7rem;">${escHtml(u.nasipaddress)}</div>
                    </td>
                    <td class="font-mono" style="font-size:.75rem;">${escHtml(u.callingstationid || '-')}</td>
                    <td class="font-mono" style="font-size:.75rem;">${escHtml(u.framedipaddress || '-')}</td>
                    <td>
                        <span class="badge bg-primary">${escHtml(u.duration)}</span>
                    </td>
                    <td>
                        ${u.sisa_waktu || '-'}
                    </td>
                    <td class="text-success">↓ ${escHtml(u.dl)}</td>
                    <td class="text-primary">↑ ${escHtml(u.ul)}</td>
                    <td style="font-size:.78rem;">${escHtml(u.profile || '-')}</td>
                    <td>
                        <button class="btn btn-danger btn-sm btn-icon"
                            title="Disconnect"
                            onclick="disconnectUser('${escHtml(u.username)}', '${escHtml(u.radacctid)}', this)">
                            <i class="bi bi-x-circle"></i>
                        </button>
                    </td>
                </tr>`).join('');
        })
        .catch(() => {
            if (tbody) tbody.innerHTML = `<tr><td colspan="10" class="text-center text-danger py-3">
                <i class="bi bi-exclamation-triangle me-2"></i>Gagal memuat data</td></tr>`;
        });
}

function clearGhostSessions() {
    if (!confirm('Apakah Anda yakin ingin membersihkan seluruh sesi menggantung dari router yang sedang offline?')) return;
    const btn = document.getElementById('btnClearGhosts');
    const origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Membersihkan...';

    fetch('/ajax/active_users.php?action=clear_offline_ghosts')
        .then(r => r.json())
        .then(d => {
            btn.disabled = false;
            btn.innerHTML = origHtml;
            if (d.success) {
                alert('✓ Sukses! ' + (d.closed_count || 0) + ' sesi menggantung dari router offline berhasil ditutup.');
                refreshActiveUsers();
            } else {
                alert('Gagal membersihkan: ' + (d.error || 'Terjadi kesalahan'));
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = origHtml;
            alert('Gagal menghubungi server: ' + err.message);
        });
}

refreshActiveUsers();
setInterval(refreshActiveUsers, 30000);
</script>

<?php include __DIR__ . '/../../include/footer.php'; ?>
