-- ============================================================
--  TABEL: pppoe_bandwidth_snapshots
--  Fungsi: Menyimpan akumulasi pemakaian bandwidth per pelanggan
--          per bulan agar data tidak hilang saat MikroTik restart.
--
--  Cara kerja:
--    - Cron (setiap 5 menit) baca counter interface MikroTik
--    - Hitung selisih (delta) vs counter terakhir yang disimpan
--    - Jika counter LEBIH KECIL dari terakhir → terjadi RESET
--      → committed_dl/ul bertambah sesuai counter baru
--    - Jika counter LEBIH BESAR → tambah selisih ke committed
--    - Total tampil = committed_dl + active_dl_live (counter saat ini)
-- ============================================================

CREATE TABLE IF NOT EXISTS `pppoe_bandwidth_snapshots` (
    `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `router_id`       INT             NOT NULL,
    `username`        VARCHAR(128)    NOT NULL,
    `month_year`      CHAR(7)         NOT NULL COMMENT 'Format: YYYY-MM',

    -- Byte yang sudah dikumpulkan dari sesi-sesi yang selesai / sesi yang mati restart
    `committed_dl`    BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Akumulasi tx-byte (download pelanggan / upload router)',
    `committed_ul`    BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Akumulasi rx-byte (upload pelanggan / download router)',

    -- Counter interface MikroTik saat checkpoint terakhir (untuk hitung delta)
    `last_if_tx`      BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Nilai tx-byte interface saat cron terakhir jalan',
    `last_if_rx`      BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Nilai rx-byte interface saat cron terakhir jalan',

    `updated_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_router_user_month` (`router_id`, `username`, `month_year`),
    KEY `idx_username_month` (`username`, `month_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Akumulasi bandwidth bulanan tahan restart MikroTik';
