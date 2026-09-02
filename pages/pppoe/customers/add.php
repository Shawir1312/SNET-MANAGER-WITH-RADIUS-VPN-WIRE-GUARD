<?php
/**
 * PPPoE Customers — Add / Edit
 */
$is_edit = ($page === 'pppoe_edit');
$page_title = $is_edit ? 'Edit Pelanggan PPPoE' : 'Tambah Pelanggan PPPoE';

$routers = get_all_routers();
$selRid = (int)get('router_id');
$id = (int)get('id');

if (!$selRid && !empty($routers)) {
    $selRid = $routers[0]['id'];
}

$customer = [
    'id' => '',
    'pppoe_username' => '',
    'full_name' => '',
    'phone' => '',
    'address' => '',
    'profile' => '',
    'monthly_price' => '',
    'due_day' => '1',
    'status' => 'active',
    'portal_username' => '',
    'notes' => ''
];

$mikrotik_password = '';

if ($is_edit) {
    $c = db_fetch_one("SELECT * FROM pppoe_customers WHERE id = ? AND router_id = ?", 'ii', [$id, $selRid]);
    if (!$c) {
        flash_set('error', 'Pelanggan tidak ditemukan.');
        header("Location: /index.php?page=pppoe_customers&router_id=$selRid");
        exit;
    }
    $customer = $c;
}

// Fetch Mikrotik Profiles for Dropdown
$selRouter = null;
foreach ($routers as $r) {
    if ($r['id'] == $selRid) { $selRouter = $r; break; }
}

$profiles = [];
if ($selRouter) {
    try {
        require_once __DIR__ . '/../../../lib/routeros_api.class.php';
        $api = new RouterosAPI();
        $api->debug = false;
        $api->timeout = 2;
        $api->attempts = 1;
        $api->delay = 0;
        if ($api->connect($selRouter['ip_address'], $selRouter['api_user'], $selRouter['api_password'], (int)$selRouter['api_port'])) {
            $profs = $api->comm('/ppp/profile/print');
            foreach ($profs as $p) {
                if (isset($p['name'])) $profiles[] = $p['name'];
            }
            
            if ($is_edit) {
                // Get password from Mikrotik
                $secs = $api->comm('/ppp/secret/print', ['?name' => $customer['pppoe_username']]);
                if (!empty($secs)) {
                    $mikrotik_password = $secs[0]['password'] ?? '';
                }
            }
            $api->disconnect();
        }
    } catch (Exception $e) {}
}

include __DIR__ . '/../../../include/header.php';
?>
<div class="page-header">
    <div>
        <h1 class="page-title"><?= $page_title ?></h1>
        <nav aria-label="breadcrumb"><ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="/index.php?page=pppoe_customers&router_id=<?= $selRid ?>">Pelanggan PPPoE</a></li>
            <li class="breadcrumb-item active"><?= $page_title ?></li>
        </ol></nav>
    </div>
</div>

<div class="row justify-content-center">
<div class="col-12 col-lg-8">
<div class="card">
    <div class="card-header">
        <h5 class="card-title"><i class="bi bi-person"></i> Data Pelanggan PPPoE</h5>
    </div>
    <div class="card-body">
        <form method="POST" action="/process/save_pppoe_customer.php">
            <input type="hidden" name="router_id" value="<?= $selRid ?>">
            <?php if ($is_edit): ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="old_username" value="<?= htmlspecialchars($customer['pppoe_username']) ?>">
            <?php endif; ?>
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Username PPPoE <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="pppoe_username" required
                           value="<?= htmlspecialchars($customer['pppoe_username']) ?>"
                           placeholder="Contoh: user123">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Password PPPoE <?= !$is_edit ? '<span class="text-danger">*</span>' : '' ?></label>
                    <input type="text" class="form-control" name="pppoe_password" <?= !$is_edit ? 'required' : '' ?>
                           value="<?= htmlspecialchars($mikrotik_password) ?>"
                           placeholder="<?= $is_edit ? '(Kosongkan jika tidak ingin diubah)' : 'Password' ?>">
                           <small class="text-muted">Untuk dial-up MikroTik.</small>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Username Portal</label>
                    <input type="text" class="form-control" name="portal_username"
                           value="<?= htmlspecialchars($customer['portal_username'] ?? '') ?>"
                           placeholder="Kosongkan jika tidak butuh akses portal">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Password Portal</label>
                    <input type="text" class="form-control" name="portal_password"
                           placeholder="<?= $is_edit ? '(Kosongkan jika tidak ingin diubah)' : 'Password Portal' ?>">
                           <small class="text-muted">Kredensial pelanggan untuk login ke /portal.</small>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Nama Pelanggan <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="full_name" required
                           value="<?= htmlspecialchars($customer['full_name']) ?>"
                           placeholder="Nama Lengkap">
                </div>

                <div class="col-md-6">
                    <label class="form-label">No. Telepon / WhatsApp</label>
                    <input type="text" class="form-control" name="phone"
                           value="<?= htmlspecialchars($customer['phone']) ?>"
                           placeholder="0812...">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Profil / Paket <span class="text-danger">*</span></label>
                    <select class="form-select" name="profile" required>
                        <option value="">-- Pilih Profil --</option>
                        <?php foreach ($profiles as $p): ?>
                        <option value="<?= htmlspecialchars($p) ?>" <?= $customer['profile'] === $p ? 'selected' : '' ?>>
                            <?= htmlspecialchars($p) ?>
                        </option>
                        <?php endforeach; ?>
                        <!-- Fallback jika api gagal -->
                        <?php if (!empty($customer['profile']) && !in_array($customer['profile'], $profiles)): ?>
                        <option value="<?= htmlspecialchars($customer['profile']) ?>" selected>
                            <?= htmlspecialchars($customer['profile']) ?> (Aktual)
                        </option>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Harga Bulanan (Rp) <span class="text-danger">*</span></label>
                    <input type="number" class="form-control" name="monthly_price" id="inp_monthly_price" required min="0"
                           value="<?= htmlspecialchars($customer['monthly_price']) ?>"
                           placeholder="Contoh: 150000">
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-bold">Skema Pembayaran</label>
                    <div class="form-check form-switch mt-1 p-2 bg-light border rounded">
                        <input class="form-check-input ms-0 me-2" type="checkbox" name="is_free" value="1" id="checkIsFree"
                               <?= (!empty($customer['is_free']) || ($is_edit && (float)$customer['monthly_price'] == 0)) ? 'checked' : '' ?>
                               onchange="toggleFreeCustomer(this.checked)">
                        <label class="form-check-label fw-bold text-success" for="checkIsFree">
                            🎁 Pelanggan Gratis / Bebas Iuran (Tanpa Isolir)
                        </label>
                    </div>
                    <div class="form-text">Jika aktif, pelanggan ini tidak akan pernah diisolir atau ditagih otomatis oleh cronjob.</div>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Tanggal Jatuh Tempo Tiap Bulan</label>
                    <input type="number" class="form-control" name="due_day" id="inp_due_day" required min="1" max="28"
                           value="<?= htmlspecialchars($customer['due_day']) ?>">
                    <div class="form-text">Tanggal pembayaran setiap bulan (1-28)</div>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Status Koneksi</label>
                    <select class="form-select" name="status">
                        <option value="active" <?= $customer['status'] === 'active' ? 'selected' : '' ?>>Aktif (Bisa Konek)</option>
                        <option value="isolated" <?= $customer['status'] === 'isolated' ? 'selected' : '' ?>>Isolir (Profile Isolir)</option>
                        <option value="suspended" <?= $customer['status'] === 'suspended' ? 'selected' : '' ?>>Suspend (Disable di MikroTik)</option>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Serial Number ONT (Opsional)</label>
                    <input type="text" class="form-control" name="ont_sn"
                           value="<?= htmlspecialchars($customer['ont_sn'] ?? '') ?>"
                           placeholder="Contoh: ZTEGC1234567">
                    <div class="form-text">Isi dengan SN modem pelanggan untuk dipetakan ke menu Monitor ONT (GenieACS).</div>
                </div>

                <div class="col-md-12">
                    <label class="form-label">Alamat / Detail Pemasangan</label>
                    <textarea class="form-control" name="address" rows="2"><?= htmlspecialchars($customer['address']) ?></textarea>
                </div>
                
                <div class="col-12">
                    <label class="form-label">Catatan</label>
                    <textarea class="form-control" name="notes" rows="2"><?= htmlspecialchars($customer['notes']) ?></textarea>
                </div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save me-1"></i><?= $is_edit ? 'Simpan Perubahan' : 'Tambah Pelanggan' ?>
                </button>
                <a href="/index.php?page=pppoe_customers&router_id=<?= $selRid ?>" class="btn btn-outline-secondary">Batal</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>
<script>
function toggleFreeCustomer(isFree) {
    const priceInput = document.getElementById('inp_monthly_price');
    if (!priceInput) return;
    if (isFree) {
        if (priceInput.value != 0) {
            priceInput.dataset.oldPrice = priceInput.value;
        }
        priceInput.value = 0;
        priceInput.readOnly = true;
        priceInput.classList.add('bg-light');
    } else {
        priceInput.readOnly = false;
        priceInput.classList.remove('bg-light');
        if (priceInput.value == 0 && priceInput.dataset.oldPrice) {
            priceInput.value = priceInput.dataset.oldPrice;
        }
    }
}
document.addEventListener('DOMContentLoaded', function() {
    const chk = document.getElementById('checkIsFree');
    if (chk && chk.checked) {
        toggleFreeCustomer(true);
    }
});
</script>

<?php include __DIR__ . '/../../../include/footer.php'; ?>
