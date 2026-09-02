<?php
/**
 * PPPoE Profiles — List (Fetched from Mikrotik API)
 */
$page_title = 'Paket / Profil PPPoE';
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

$profiles = [];
$api_error = '';

if ($selRouter) {
    try {
        require_once __DIR__ . '/../../../lib/routeros_api.class.php';
        $api = new RouterosAPI();
        $api->debug = false;
        $api->timeout = 2;
        $api->attempts = 1;
        $api->delay = 0;
        if ($api->connect($selRouter['ip_address'], $selRouter['api_user'], $selRouter['api_password'], (int)$selRouter['api_port'])) {
            $profs = $api->comm('/ppp/profile/print', [
                ".proplist" => ".id,name,local-address,remote-address,rate-limit,only-one,comment"
            ]);
            foreach ($profs as $p) {
                // Ignore default profiles if you want, but better show all
                $profiles[] = [
                    'id'             => $p['.id'] ?? '',
                    'name'           => $p['name'] ?? '',
                    'local_address'  => $p['local-address'] ?? '',
                    'remote_address' => $p['remote-address'] ?? '',
                    'rate_limit'     => $p['rate-limit'] ?? '',
                    'only_one'       => $p['only-one'] ?? 'default',
                    'comment'        => $p['comment'] ?? ''
                ];
            }
            $api->disconnect();
        } else {
            $api_error = 'Koneksi API ditolak (Cek kredensial router).';
        }
    } catch (Exception $e) {
        $api_error = 'Gagal terhubung ke MikroTik: ' . $e->getMessage();
    }
}

include __DIR__ . '/../../../include/header.php';
?>
<div class="page-header">
    <div>
        <h1 class="page-title"><i class="bi bi-box me-2 text-primary"></i>Paket / Profil PPPoE</h1>
        <p class="page-subtitle">Daftar profil PPPoE yang ada langsung di dalam router MikroTik Anda.</p>
    </div>
    <a href="/index.php?page=pppoe_profile_add&router_id=<?= $selRid ?>" class="btn btn-primary <?= !$selRouter ? 'disabled' : '' ?>">
        <i class="bi bi-plus-circle me-1"></i> Tambah Profil ke MikroTik
    </a>
</div>

<?php if ($api_error): ?>
<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($api_error) ?></div>
<?php endif; ?>

<div class="card table-card">
    <div class="table-toolbar flex-wrap gap-2">
        <div class="d-flex align-items-center gap-3">
            <span class="fw-600"><?= count($profiles) ?> Profil MikroTik</span>
            
            <form method="GET" class="d-flex align-items-center gap-2 m-0">
                <input type="hidden" name="page" value="pppoe_profiles">
                <select name="router_id" class="form-select form-select-sm" style="width:250px" onchange="this.form.submit()">
                    <?php if (empty($routers)): ?>
                        <option value="">Belum ada router</option>
                    <?php endif; ?>
                    <?php foreach ($routers as $rt): ?>
                    <option value="<?= $rt['id'] ?>" <?= $selRid == $rt['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($rt['name']) ?> (<?= htmlspecialchars($rt['ip_address']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
        <input type="text" id="table-search" class="form-control form-control-sm" style="width:220px" placeholder="Cari profil...">
    </div>
    <div class="table-responsive">
        <table class="table" id="data-table">
            <thead>
                <tr>
                    <th>Nama Profil</th>
                    <th>Local Address</th>
                    <th>Remote Address (Pool)</th>
                    <th>Rate Limit</th>
                    <th>Only One</th>
                    <th>Komentar</th>
                    <th width="100">Aksi</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($profiles) && !$api_error): ?>
            <tr><td colspan="7" class="text-center text-muted py-4">Belum ada profil PPPoE di MikroTik ini.</td></tr>
            <?php else: ?>
            <?php foreach ($profiles as $p): ?>
            <tr>
                <td><div class="fw-bold text-primary"><?= htmlspecialchars($p['name']) ?></div></td>
                <td><?= htmlspecialchars($p['local_address'] ?: '-') ?></td>
                <td><span class="badge bg-secondary"><?= htmlspecialchars($p['remote_address'] ?: '-') ?></span></td>
                <td><code style="font-size:14px;color:#d946ef"><?= htmlspecialchars($p['rate_limit'] ?: 'Unlimited') ?></code></td>
                <td><?= htmlspecialchars($p['only_one']) ?></td>
                <td><span class="text-muted" style="font-size:13px"><?= htmlspecialchars($p['comment'] ?: '-') ?></span></td>
                <td>
                    <div class="d-flex gap-1">
                        <a href="/index.php?page=pppoe_profile_edit&router_id=<?= $selRid ?>&id=<?= urlencode($p['id']) ?>"
                           class="btn btn-sm btn-outline-primary btn-icon" title="Edit">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <?php if ($p['name'] !== 'default' && $p['name'] !== 'default-encryption'): ?>
                        <a href="/index.php?page=pppoe_profile_delete&router_id=<?= $selRid ?>&id=<?= urlencode($p['id']) ?>"
                           class="btn btn-sm btn-outline-danger btn-icon"
                           data-confirm="Hapus profil '<?= htmlspecialchars($p['name']) ?>' secara permanen dari MikroTik?"
                           title="Hapus">
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
</div>

<?php include __DIR__ . '/../../../include/footer.php'; ?>
