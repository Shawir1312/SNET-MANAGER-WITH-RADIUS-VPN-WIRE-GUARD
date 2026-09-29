<?php
/**
 * Installer: Buat tabel pppoe_bandwidth_snapshots
 * Jalankan SEKALI dari browser: https://domain-anda.com/install_bw_snapshots.php
 * Hapus file ini setelah berhasil!
 */
define('IN_APP', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

$sql = "
CREATE TABLE IF NOT EXISTS `pppoe_bandwidth_snapshots` (
    `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `router_id`       INT             NOT NULL,
    `username`        VARCHAR(128)    NOT NULL,
    `month_year`      CHAR(7)         NOT NULL COMMENT 'Format: YYYY-MM',
    `committed_dl`    BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Akumulasi tx-byte',
    `committed_ul`    BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Akumulasi rx-byte',
    `last_if_tx`      BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `last_if_rx`      BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `updated_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_router_user_month` (`router_id`, `username`, `month_year`),
    KEY `idx_username_month` (`username`, `month_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Akumulasi bandwidth bulanan tahan restart MikroTik';
";

try {
    db()->query($sql);
    echo "<h2 style='color:green'>✅ Berhasil! Tabel pppoe_bandwidth_snapshots sudah dibuat.</h2>";
    echo "<p>Silakan hapus file <strong>install_bw_snapshots.php</strong> dari server.</p>";
    echo "<p>Langkah selanjutnya: tambahkan cron berikut di server (cPanel / crontab):</p>";
    echo "<pre style='background:#f0f0f0;padding:15px;border-radius:8px'>";
    echo "*/5 * * * * php " . dirname(__FILE__) . "/cron/cron_bandwidth_snapshot.php >> /var/log/snet_bw_snap.log 2>&amp;1";
    echo "</pre>";
    echo "<p><strong>Artinya:</strong> Setiap 5 menit, sistem otomatis checkpoint counter MikroTik.<br>
          Data pemakaian pelanggan tidak akan hilang meski MikroTik restart berkali-kali.</p>";
} catch (Throwable $e) {
    echo "<h2 style='color:red'>❌ Gagal: " . htmlspecialchars($e->getMessage()) . "</h2>";
}
?>
