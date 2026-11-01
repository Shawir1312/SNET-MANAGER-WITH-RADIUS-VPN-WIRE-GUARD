<?php
/**
 * S.NET RADIUS Manager — Perbaiki & Batasi Voucher Aktif Hanya untuk 31 Oktober & 1 November 2026
 * 
 * Mengembalikan voucher lama (sebelum 31 Oktober 2026) yang sempat ikut terpulihkan
 * kembali ke status 'expired' dan membersihkan autentikasinya dari radcheck & radreply.
 * Menyisakan HANYA voucher yang digunakan sejak 31 Oktober 2026 (~30-50 voucher).
 */
define('CLI_MODE', php_sapi_name() === 'cli');

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/include/functions.php';

$cutoff_date = '2026-10-31 00:00:00';

echo "====================================================================\n";
echo "  S.NET RADIUS Manager — Penyesuaian Pemulihan Voucher Migrasi\n";
echo "====================================================================\n";
echo "Filter Cutoff: Digunakan sejak {$cutoff_date} (31 Okt - 1 Nov 2026)\n\n";

$db = db();

// 1. Hitung voucher yang digunakan pada/setelah 31 Oktober 2026 (yang HARUS TETAP AKTIF)
$keep_active = db_fetch_all("
    SELECT id, username, used_at, expired_at, profile_id 
    FROM vouchers 
    WHERE status = 'active' 
      AND used_at >= ?
    ORDER BY used_at DESC
", 's', [$cutoff_date]);

echo "[1/3] Voucher aktif sah (digunakan 31 Okt - 1 Nov): " . count($keep_active) . " voucher.\n";
if (count($keep_active) > 0) {
    echo "      Contoh voucher yang dipertahankan:\n";
    $sample = array_slice($keep_active, 0, 5);
    foreach ($sample as $s) {
        echo "      - {$s['username']} (Login: {$s['used_at']}, Expired: {$s['expired_at']})\n";
    }
}

// 2. Cari voucher yang sebelum 31 Oktober 2026 tapi sempat aktif
$to_expire = db_fetch_all("
    SELECT id, username, used_at 
    FROM vouchers 
    WHERE status = 'active' 
      AND (used_at < ? OR used_at IS NULL)
", 's', [$cutoff_date]);

$to_expire_count = count($to_expire);
echo "\n[2/3] Voucher lama (sebelum 31 Okt) yang akan dikembalikan ke 'expired': {$to_expire_count} voucher.\n";

if ($to_expire_count > 0) {
    db_begin();
    try {
        $expire_ids = array_column($to_expire, 'id');
        $chunks = array_chunk($expire_ids, 200);

        foreach ($chunks as $chunk) {
            $inList = implode(',', array_map('intval', $chunk));
            
            // Ambil usernames untuk hapus dari RADIUS
            $userRows = db_fetch_all("SELECT username FROM vouchers WHERE id IN ($inList)");
            $usernames = array_column($userRows, 'username');
            
            if (!empty($usernames)) {
                $u_pls = implode(',', array_fill(0, count($usernames), '?'));
                $u_types = str_repeat('s', count($usernames));
                
                db_execute("DELETE FROM radcheck WHERE username IN ($u_pls)", $u_types, $usernames);
                db_execute("DELETE FROM radreply WHERE username IN ($u_pls)", $u_types, $usernames);
            }

            // Kembalikan status voucher ke expired
            db_execute("UPDATE vouchers SET status = 'expired' WHERE id IN ($inList)");
        }

        db_commit();
        echo "      ✓ Berhasil mengembalikan {$to_expire_count} voucher lama ke status 'expired'.\n";
    } catch (Throwable $e) {
        db_rollback();
        echo "      [!] GAGAL memproses: " . $e->getMessage() . "\n";
        exit(1);
    }
} else {
    echo "      ✓ Tidak ada voucher lama yang perlu dikembalikan.\n";
}

// 3. Pastikan voucher yang sah (31 Okt - 1 Nov) terdaftar lengkap di radcheck & radreply
echo "\n[3/3] Memastikan autentikasi RADIUS untuk " . count($keep_active) . " voucher aktif sah...\n";
$restored_valid = 0;
foreach ($keep_active as $ka) {
    $res = reactivate_voucher((int)$ka['id'], false, 0);
    if ($res['success']) {
        $restored_valid++;
    }
}
echo "      ✓ Selesai! Sebanyak {$restored_valid} voucher aktif 31 Okt - 1 Nov siap digunakan.\n";

echo "\n====================================================================\n";
echo " ✓ SELESAI: Hanya voucher 31 Okt - 1 Nov yang aktif di sistem!\n";
echo "====================================================================\n";
