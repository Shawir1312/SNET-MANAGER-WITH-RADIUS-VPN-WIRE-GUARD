<?php
/**
 * PPPoE Profiles — Add / Edit via Mikrotik API
 */
$page_title = 'Form Profil PPPoE';
$routers = get_all_routers();
$selRid = (int)get('router_id');
$id = get('id'); // Mikrotik ID (e.g. *1)

$selRouter = null;
foreach ($routers as $r) {
    if ($r['id'] == $selRid) {
        $selRouter = $r;
        break;
    }
}

if (!$selRouter) {
    flash_set('error', 'Router tidak valid.');
    header('Location: /index.php?page=pppoe_profiles');
    exit;
}

$profile = [
    'id' => '',
    'name' => '',
    'local_address' => '',
    'remote_address' => '',
    'rate_limit' => '',
    'only_one' => 'default',
    'comment' => ''
];

$pools = [];
if ($selRouter) {
    try {
        require_once __DIR__ . '/../../../lib/routeros_api.class.php';
        $api = new RouterosAPI();
        $api->debug = false;
        $api->timeout = 2;
        $api->attempts = 1;
        $api->delay = 0;
        if ($api->connect($selRouter['ip_address'], $selRouter['api_user'], $selRouter['api_password'], (int)$selRouter['api_port'])) {
            if ($id) {
                $profs = $api->comm('/ppp/profile/print', ['?.id' => $id]);
                if (!empty($profs)) {
                    $p = $profs[0];
                    $profile['id'] = $p['.id'] ?? '';
                    $profile['name'] = $p['name'] ?? '';
                    $profile['local_address'] = $p['local-address'] ?? '';
                    $profile['remote_address'] = $p['remote-address'] ?? '';
                    $profile['rate_limit'] = $p['rate-limit'] ?? '';
                    $profile['only_one'] = $p['only-one'] ?? 'default';
                    $profile['comment'] = $p['comment'] ?? '';
                }
            }
            $pls = $api->comm('/ip/pool/print');
            foreach ($pls as $pl) {
                $pools[] = $pl['name'];
            }
            $api->disconnect();
        }
    } catch (Exception $e) {
        if ($id) flash_set('error', 'Gagal memuat data dari router: ' . $e->getMessage());
    }
}

include __DIR__ . '/../../../include/header.php';
?>
<div class="page-header">
    <div>
        <h1 class="page-title"><?= $page_title ?></h1>
        <nav aria-label="breadcrumb"><ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="/index.php?page=pppoe_profiles&router_id=<?= $selRid ?>">Profil PPPoE</a></li>
            <li class="breadcrumb-item active"><?= $page_title ?></li>
        </ol></nav>
    </div>
</div>

<div class="row justify-content-center">
<div class="col-12 col-lg-8">
<div class="card">
    <div class="card-header">
        <h5 class="card-title"><i class="bi bi-box"></i> <?= $page_title ?> (MikroTik)</h5>
    </div>
    <div class="card-body">
        <form method="POST" action="/process/save_pppoe_profile.php">
            <input type="hidden" name="router_id" value="<?= $selRid ?>">
            <?php if ($id): ?>
            <input type="hidden" name="id" value="<?= htmlspecialchars($id) ?>">
            <?php endif; ?>
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

            <div class="row g-3">
                <div class="col-md-12">
                    <label class="form-label">Nama Profil <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="name" required
                           value="<?= htmlspecialchars($profile['name']) ?>" <?= ($profile['name'] === 'default' || $profile['name'] === 'default-encryption') ? 'readonly' : '' ?>
                           placeholder="Contoh: Paket 10 Mbps">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Local Address</label>
                    <input type="text" class="form-control" name="local_address"
                           value="<?= htmlspecialchars($profile['local_address']) ?>"
                           placeholder="IP Gateway (cth: 192.168.10.1)">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Remote Address (Pool)</label>
                    <select class="form-select" name="remote_address">
                        <option value="">-- Kosong --</option>
                        <?php foreach ($pools as $pool): ?>
                        <option value="<?= htmlspecialchars($pool) ?>" <?= $profile['remote_address'] === $pool ? 'selected' : '' ?>>
                            <?= htmlspecialchars($pool) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Rate Limit (Rx/Tx)</label>
                    <input type="text" class="form-control" name="rate_limit"
                           value="<?= htmlspecialchars($profile['rate_limit']) ?>"
                           placeholder="cth: 5M/10M">
                    <div class="form-text">Batas kecepatan (Upload/Download). Cth: <code>5M/10M</code></div>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Only One</label>
                    <select class="form-select" name="only_one">
                        <option value="default" <?= $profile['only_one'] === 'default' ? 'selected' : '' ?>>Default</option>
                        <option value="yes" <?= $profile['only_one'] === 'yes' ? 'selected' : '' ?>>Yes (1 Akun 1 Sesi)</option>
                        <option value="no" <?= $profile['only_one'] === 'no' ? 'selected' : '' ?>>No (Bisa multiple sesi)</option>
                    </select>
                </div>

                <div class="col-12">
                    <label class="form-label">Komentar</label>
                    <input type="text" class="form-control" name="comment"
                           value="<?= htmlspecialchars($profile['comment']) ?>">
                </div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save me-1"></i><?= $id ? 'Simpan ke MikroTik' : 'Tambah ke MikroTik' ?>
                </button>
                <a href="/index.php?page=pppoe_profiles&router_id=<?= $selRid ?>" class="btn btn-outline-secondary">Batal</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>

<?php include __DIR__ . '/../../../include/footer.php'; ?>
