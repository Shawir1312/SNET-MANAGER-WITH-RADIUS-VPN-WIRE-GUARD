<?php
/**
 * S.NET RADIUS & VPN — Log Aktivitas WireGuard
 */
if (!defined('IN_APP')) { define('IN_APP', true); }
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../include/functions.php';
require_once __DIR__ . '/../../include/wireguard_functions.php';

auth_check();

$pageTitle = 'Log Aktivitas VPN WireGuard';
$activeNav  = 'wireguard';

// Pastikan tabel wg_logs ada
try {
    db_execute("CREATE TABLE IF NOT EXISTS wg_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        event VARCHAR(100) NOT NULL,
        router_id INT DEFAULT NULL,
        router_name VARCHAR(100) DEFAULT NULL,
        details TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

$logs = db_fetch_all("SELECT * FROM wg_logs ORDER BY id DESC LIMIT 100");

include __DIR__ . '/../../include/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
        <a href="/index.php?page=wg_routers" class="text-decoration-none small text-muted">
            <i class="bi bi-arrow-left me-1"></i> Kembali ke Daftar Router
        </a>
        <h1 class="page-title mt-1"><i class="bi bi-journal-text me-2 text-primary"></i>Log Aktivitas WireGuard VPN</h1>
        <p class="page-subtitle mb-0">Riwayat penambahan peer, perubahan konfigurasi, dan aktivitas VPN</p>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-transparent py-3">
        <span class="fw-bold fs-6">100 Log Aktivitas Terakhir</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width: 170px;">Waktu</th>
                    <th>Aktivitas (Event)</th>
                    <th>Router Terkait</th>
                    <th>Rincian Detail</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                <tr>
                    <td colspan="4" class="text-center py-5 text-muted">
                        <i class="bi bi-journal-x fs-1 d-block mb-2 text-secondary"></i>
                        Belum ada catatan log aktivitas WireGuard.
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($logs as $l): ?>
                <tr>
                    <td class="small text-muted font-monospace">
                        <?= date('d/m/Y H:i:s', strtotime($l['created_at'])) ?>
                    </td>
                    <td>
                        <span class="badge bg-primary-subtle text-primary fw-semibold">
                            <?= htmlspecialchars($l['event']) ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($l['router_name']): ?>
                            <?php if ($l['router_id']): ?>
                            <a href="/index.php?page=wg_router_detail&id=<?= $l['router_id'] ?>" class="fw-bold text-decoration-none text-dark">
                                <?= htmlspecialchars($l['router_name']) ?>
                            </a>
                            <?php else: ?>
                            <span class="fw-semibold"><?= htmlspecialchars($l['router_name']) ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="small text-muted font-monospace">
                        <?= htmlspecialchars($l['details'] ?? '') ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../../include/footer.php'; ?>
