<?php
/**
 * S.NET RADIUS & VPN — WireGuard Routers / Peers List
 */
if (!defined('IN_APP')) { define('IN_APP', true); }
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../include/functions.php';
require_once __DIR__ . '/../../include/wireguard_functions.php';

auth_check();

$pageTitle = 'VPN WireGuard — Router Peers';
$activeNav  = 'wireguard';

// Pastikan tabel ada
try {
    db_execute("CREATE TABLE IF NOT EXISTS wg_routers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        location VARCHAR(150) DEFAULT NULL,
        public_key VARCHAR(255) NOT NULL UNIQUE,
        private_key VARCHAR(255) NOT NULL,
        tunnel_ip VARCHAR(20) NOT NULL UNIQUE,
        lan_subnets TEXT DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    db_execute("CREATE TABLE IF NOT EXISTS wg_settings (
        `key` VARCHAR(100) NOT NULL PRIMARY KEY,
        `value` TEXT NOT NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    db_execute("CREATE TABLE IF NOT EXISTS wg_port_forwards (
        id INT AUTO_INCREMENT PRIMARY KEY,
        router_id INT NOT NULL,
        public_port INT NOT NULL,
        target_port INT NOT NULL,
        target_ip VARCHAR(20) DEFAULT NULL,
        protocol VARCHAR(10) NOT NULL DEFAULT 'tcp',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_port_proto (public_port, protocol),
        FOREIGN KEY (router_id) REFERENCES wg_routers(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

$search = trim($_GET['search'] ?? '');
$sql = "SELECT r.*, 
        (SELECT COUNT(*) FROM wg_port_forwards pf WHERE pf.router_id = r.id) as total_forwards
        FROM wg_routers r";
$params = [];
$types = "";

if ($search !== '') {
    $sql .= " WHERE r.name LIKE ? OR r.tunnel_ip LIKE ? OR r.location LIKE ? OR r.lan_subnets LIKE ?";
    $like = '%' . $search . '%';
    $params = [$like, $like, $like, $like];
    $types = "ssss";
}
$sql .= " ORDER BY r.id DESC";

$routers = db_fetch_all($sql, $types, $params);
$peerStatus = wg_get_peer_status();
$wgSettings = get_all_wg_settings();

// Calculate stats
$totalPeers = count($routers);
$onlinePeers = 0;
$totalRx = 0;
$totalTx = 0;

foreach ($routers as $r) {
    $pub = $r['public_key'];
    if (isset($peerStatus[$pub]) && $peerStatus[$pub]['connected']) {
        $onlinePeers++;
    }
    if (isset($peerStatus[$pub])) {
        $totalRx += $peerStatus[$pub]['rx_bytes'] ?? 0;
        $totalTx += $peerStatus[$pub]['tx_bytes'] ?? 0;
    }
}

include __DIR__ . '/../../include/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
        <h1 class="page-title"><i class="bi bi-shield-lock-fill me-2 text-primary"></i>VPN WireGuard Hub</h1>
        <p class="page-subtitle mb-0">Manajemen Interkoneksi Router MikroTik &amp; Remote Access</p>
    </div>
    <div class="d-flex gap-2">
        <a href="/index.php?page=wg_port_forwarding" class="btn btn-outline-primary">
            <i class="bi bi-arrow-left-right me-1"></i> Port Forwarding
        </a>
        <a href="/index.php?page=wg_settings" class="btn btn-outline-secondary">
            <i class="bi bi-gear-fill me-1"></i> Pengaturan Server
        </a>
        <a href="/index.php?page=wg_router_add" class="btn btn-primary">
            <i class="bi bi-plus-circle-fill me-1"></i> Tambah Router VPN
        </a>
    </div>
</div>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card blue h-100">
            <div class="stat-icon"><i class="bi bi-router"></i></div>
            <div class="stat-value"><?= number_format($totalPeers) ?></div>
            <div class="stat-label">Total Router Peer</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card green h-100">
            <div class="stat-icon"><i class="bi bi-broadcast"></i></div>
            <div class="stat-value"><?= number_format($onlinePeers) ?></div>
            <div class="stat-label">Router Terhubung (Online)</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card teal h-100">
            <div class="stat-icon"><i class="bi bi-arrow-down-circle"></i></div>
            <div class="stat-value"><?= format_bytes($totalRx) ?></div>
            <div class="stat-label">Total Transfer RX</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card orange h-100">
            <div class="stat-icon"><i class="bi bi-arrow-up-circle"></i></div>
            <div class="stat-value"><?= format_bytes($totalTx) ?></div>
            <div class="stat-label">Total Transfer TX</div>
        </div>
    </div>
</div>

<!-- Main Table Card -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-transparent py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
            <span class="fw-bold fs-6">Daftar Peer Router MikroTik</span>
            <span class="badge bg-light text-dark border"><?= count($routers) ?> Router</span>
        </div>
        <form method="GET" class="d-flex gap-2" style="max-width: 320px;">
            <input type="hidden" name="page" value="wg_routers">
            <input type="text" name="search" class="form-control form-control-sm" placeholder="Cari router / IP..." value="<?= htmlspecialchars($search) ?>">
            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i></button>
            <?php if ($search !== ''): ?>
            <a href="/index.php?page=wg_routers" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x"></i></a>
            <?php endif; ?>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width: 50px;">Status</th>
                    <th>Nama Router</th>
                    <th>IP Tunnel</th>
                    <th>Subnet LAN</th>
                    <th>Traffic RX / TX</th>
                    <th>Last Handshake</th>
                    <th>Remote NAT</th>
                    <th class="text-end" style="width: 140px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($routers)): ?>
                <tr>
                    <td colspan="8" class="text-center py-5 text-muted">
                        <i class="bi bi-shield-slash fs-1 d-block mb-2 text-secondary"></i>
                        Belum ada router WireGuard yang ditambahkan.
                        <div class="mt-2">
                            <a href="/index.php?page=wg_router_add" class="btn btn-sm btn-primary">
                                <i class="bi bi-plus-circle me-1"></i> Tambah Router Sekarang
                            </a>
                        </div>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($routers as $r): 
                    $pub = $r['public_key'];
                    $st = $peerStatus[$pub] ?? null;
                    $isOnline = $st && $st['connected'];
                ?>
                <tr>
                    <td class="text-center">
                        <?php if ($isOnline): ?>
                        <span class="badge bg-success-subtle text-success p-2 rounded-circle" title="Connected">
                            <i class="bi bi-check-circle-fill"></i>
                        </span>
                        <?php else: ?>
                        <span class="badge bg-secondary-subtle text-secondary p-2 rounded-circle" title="Offline">
                            <i class="bi bi-dash-circle"></i>
                        </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="/index.php?page=wg_router_detail&id=<?= $r['id'] ?>" class="fw-bold text-decoration-none text-dark">
                            <?= htmlspecialchars($r['name']) ?>
                        </a>
                        <?php if (!empty($r['location'])): ?>
                        <div class="small text-muted"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($r['location']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge bg-primary-subtle text-primary font-monospace fs-7"><?= htmlspecialchars($r['tunnel_ip']) ?></span>
                    </td>
                    <td>
                        <?php if (!empty($r['lan_subnets'])): ?>
                            <?php foreach (explode(',', $r['lan_subnets']) as $sub): ?>
                                <span class="badge bg-light text-dark border font-monospace me-1"><?= htmlspecialchars(trim($sub)) ?></span>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span class="text-muted small">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($st): ?>
                        <div class="small font-monospace">
                            <span class="text-success"><i class="bi bi-arrow-down-short"></i><?= format_bytes($st['rx_bytes'] ?? 0) ?></span>
                            <span class="text-primary ms-1"><i class="bi bi-arrow-up-short"></i><?= format_bytes($st['tx_bytes'] ?? 0) ?></span>
                        </div>
                        <?php else: ?>
                        <span class="text-muted small">0 B</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="small <?= $isOnline ? 'text-success fw-semibold' : 'text-muted' ?>">
                            <?= format_relative_time($st['last_handshake'] ?? null) ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($r['total_forwards'] > 0): ?>
                        <a href="/index.php?page=wg_port_forwarding&router_id=<?= $r['id'] ?>" class="badge bg-info-subtle text-info text-decoration-none">
                            <i class="bi bi-arrow-left-right me-1"></i><?= $r['total_forwards'] ?> Port
                        </a>
                        <?php else: ?>
                        <a href="/index.php?page=wg_port_forwarding&router_id=<?= $r['id'] ?>" class="text-muted small text-decoration-none">
                            + Tambah NAT
                        </a>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <div class="btn-group btn-group-sm">
                            <a href="/index.php?page=wg_router_detail&id=<?= $r['id'] ?>" class="btn btn-outline-primary" title="Lihat Skrip &amp; Detail">
                                <i class="bi bi-terminal"></i>
                            </a>
                            <a href="/index.php?page=wg_router_edit&id=<?= $r['id'] ?>" class="btn btn-outline-secondary" title="Edit Router">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <button type="button" class="btn btn-outline-danger" onclick="confirmDelete(<?= $r['id'] ?>, '<?= htmlspecialchars(addslashes($r['name'])) ?>')" title="Hapus Router">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function confirmDelete(id, name) {
    if (confirm("Apakah Anda yakin ingin menghapus router WireGuard '" + name + "'?\n\nKoneksi VPN router ini akan langsung diputus dan dihapus dari server.")) {
        window.location.href = "/process/wireguard/delete_router.php?id=" + id + "&csrf=<?= $_SESSION['csrf_token'] ?? '' ?>";
    }
}
</script>

<?php include __DIR__ . '/../../include/footer.php'; ?>
