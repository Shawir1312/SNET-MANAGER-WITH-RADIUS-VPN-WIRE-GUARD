<?php
/**
 * S.NET RADIUS Manager — API Endpoint Replikasi Redundant Server (Master VPS)
 * 
 * Endpoint ini menerima koneksi dari Node Lokal (Mini PC Cabang):
 * - Memverifikasi X-Redundant-Key
 * - Melayani aksi 'ping', 'pull_catalog', dan 'push_activity'
 */

define('IN_APP', true);
@ini_set('display_errors', '0');
@ini_set('memory_limit', '512M');
@set_time_limit(300);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../include/functions.php';
require_once __DIR__ . '/../include/redundant_functions.php';

ensure_redundant_tables();

while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

// 1. Ambil Header Autentikasi
$headers = function_exists('getallheaders') ? getallheaders() : [];
$apiKey = $_SERVER['HTTP_X_REDUNDANT_KEY'] ?? ($headers['X-Redundant-Key'] ?? ($headers['x-redundant-key'] ?? ($_GET['api_key'] ?? ($_POST['api_key'] ?? ''))));
$nodeCode = $_SERVER['HTTP_X_NODE_CODE'] ?? ($headers['X-Node-Code'] ?? ($headers['x-node-code'] ?? ($_GET['node_code'] ?? ($_POST['node_code'] ?? 'UNKNOWN'))));

if (empty($apiKey)) {
    echo json_encode(['success' => false, 'message' => 'Autentikasi ditolak: Header X-Redundant-Key wajib diisi.']);
    exit;
}

// 2. Verifikasi Kunci API di database (tabel redundant_nodes atau redundant_settings)
$settings = get_redundant_settings();
$masterKey = $settings['api_key'] ?? '';

$validNode = null;
if (!empty($masterKey) && hash_equals($masterKey, $apiKey)) {
    // Kunci master universal
    $validNode = ['node_code' => $nodeCode, 'node_name' => 'Authorized Master Node'];
} else {
    // Kunci node terdaftar
    $validNode = db_fetch_one("SELECT * FROM redundant_nodes WHERE api_key = ? AND status = 'active'", 's', [$apiKey]);
}

if (!$validNode) {
    log_redundant_sync($nodeCode, 'ping', 'failed', "Autentikasi ditolak: API Key tidak cocok");
    echo json_encode(['success' => false, 'message' => 'Autentikasi ditolak: API Key tidak cocok atau Node dinonaktifkan.']);
    exit;
}

$action = $_GET['action'] ?? 'ping';

switch ($action) {
    case 'ping':
        log_redundant_sync($nodeCode, 'ping', 'success', "Ping tes koneksi dari Node {$nodeCode}");
        echo json_encode([
            'success'     => true,
            'role'        => 'master',
            'server_time' => date('Y-m-d H:i:s'),
            'node_code'   => $nodeCode,
            'message'     => 'Koneksi ke VPS Master berhasil!'
        ]);
        exit;

    case 'pull_catalog':
        try {
            @ini_set('memory_limit', '512M');
            @set_time_limit(300);

            // Ambil semua profil
            $profiles = db_fetch_all("SELECT id, name, price, validity_value, validity_unit, duration_value, duration_unit, rate_up, rate_down, quota_mb, router_id FROM profiles");

            // Ambil semua router & nas
            $routers  = db_fetch_all("SELECT id, name, ip_address, nas_ip, radius_secret, status FROM routers WHERE status != 'inactive' OR status IS NULL");
            $nas      = db_fetch_all("SELECT id, nasname, shortname, type, secret, description FROM nas");

            // Periksa ketersediaan kolom router_id dan generated_by pada tabel vouchers
            $hasRid = false;
            $hasGenBy = false;
            try {
                if (db_fetch_one("SHOW COLUMNS FROM vouchers LIKE 'router_id'")) $hasRid = true;
                if (db_fetch_one("SHOW COLUMNS FROM vouchers LIKE 'generated_by'")) $hasGenBy = true;
            } catch (Throwable $e) {}

            $ridSelect = $hasRid ? "router_id" : "NULL AS router_id";
            $genBySelect = $hasGenBy ? "generated_by" : "NULL AS generated_by";

            // Ambil SELURUH voucher (tersedia, aktif, dan riwayat lengkap hingga 100.000 voucher)
            $vouchers = db_fetch_all("SELECT id, username, password, profile_id, {$ridSelect}, batch_id, status, used_at, expired_at, {$genBySelect}, created_at FROM vouchers ORDER BY id DESC LIMIT 100000");

            // Ambil radcheck & radreply untuk voucher aktif/tersedia & pelanggan PPPoE (lewati voucher kedaluwarsa agar transmisi ringan)
            $radcheck = db_fetch_all("SELECT username, attribute, op, value FROM radcheck WHERE username NOT IN (SELECT username FROM vouchers WHERE status = 'expired')");
            $radreply = db_fetch_all("SELECT username, attribute, op, value FROM radreply WHERE username NOT IN (SELECT username FROM vouchers WHERE status = 'expired')");

            // Ambil admin superadmin agar bisa login di node lokal
            $admins = db_fetch_all("SELECT id, username, password, full_name, role, is_active FROM admins WHERE is_active = 1");

            // Ambil Riwayat Penjualan Hotspot (sales_log) untuk laporan omzet dan pendapatan cabang
            $sales_log = [];
            try {
                $hasSoldBy = (bool)db_fetch_one("SHOW COLUMNS FROM sales_log LIKE 'sold_by'");
                $soldBySelect = $hasSoldBy ? "sold_by" : "NULL AS sold_by";
                $sales_log = db_fetch_all("SELECT id, voucher_id, voucher_username, profile_id, profile_name, router_id, {$soldBySelect}, price, sold_at FROM sales_log ORDER BY id DESC LIMIT 5000");
            } catch (Throwable $e) {}

            // Ambil Laporan Penagihan Reseller (penagihan)
            $penagihan = [];
            try {
                $penagihan = db_fetch_all("SELECT id, router_id, profile_id, total_pendapatan, bagian_reseller, pendapatan_bersih, estimasi_voucher, voucher_aktual, status_kecocokan, catatan, ditagih_oleh, tanggal, created_at FROM penagihan ORDER BY id DESC LIMIT 2000");
            } catch (Throwable $e) {}

            // Ambil Data Pelanggan Broadband PPPoE & Riwayat Pembayaran PPPoE
            $pppoe_customers = [];
            $pppoe_payments  = [];
            $pppoe_settings  = [];
            try {
                $pppoe_customers = db_fetch_all("SELECT id, router_id, pppoe_username, portal_password, portal_password_plain, portal_username, full_name, phone, address, profile, monthly_price, is_free, due_day, status, ont_sn, ont_vlan, ont_wifi_ssid, ont_wifi_pass, isolated_at, isolated_reason, last_paid_at, last_paid_amount, notes FROM pppoe_customers");
                $pppoe_payments  = db_fetch_all("SELECT id, customer_id, amount, payment_method, payment_gateway, midtrans_order_id, midtrans_tx_id, midtrans_status, checkout_url, pay_code, qr_url, period_month, period_year, paid_at, notes FROM pppoe_payments ORDER BY id DESC LIMIT 2000");
                $pppoe_settings  = db_fetch_all("SELECT setting_key, setting_value FROM pppoe_settings");
            } catch (Throwable $e) {}

            // Update status sinkronisasi node di tabel master jika terdaftar
            if (!empty($validNode['id'])) {
                $syncStatusDesc = "Pull Sukses (" . count($vouchers) . " voucher, " . count($sales_log) . " penjualan, " . count($pppoe_customers) . " PPPoE)";
                db_execute("UPDATE redundant_nodes SET last_sync_at = NOW(), last_sync_status = ?, total_vouchers_synced = ? WHERE id = ?", 'sii', [$syncStatusDesc, count($vouchers), $validNode['id']]);
            }

            log_redundant_sync($nodeCode, 'pull', 'success', "Node {$nodeCode} berhasil mendownload katalog (" . count($vouchers) . " voucher, " . count($profiles) . " profil, " . count($sales_log) . " penjualan, " . count($pppoe_customers) . " PPPoE)");

            echo json_encode([
                'success' => true,
                'payload' => [
                    'profiles'        => $profiles,
                    'routers'         => $routers,
                    'nas'             => $nas,
                    'vouchers'        => $vouchers,
                    'radcheck'        => $radcheck,
                    'radreply'        => $radreply,
                    'admins'          => $admins,
                    'sales_log'       => $sales_log,
                    'penagihan'       => $penagihan,
                    'pppoe_customers' => $pppoe_customers,
                    'pppoe_payments'  => $pppoe_payments,
                    'pppoe_settings'  => $pppoe_settings,
                    'count'           => count($vouchers)
                ]
            ]);
        } catch (Throwable $e) {
            log_redundant_sync($nodeCode, 'pull', 'failed', "Gagal ambil katalog: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Gagal mengambil data katalog: ' . $e->getMessage()]);
        }
        exit;

    case 'push_activity':
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true);

        if (!$input) {
            echo json_encode(['success' => false, 'message' => 'Payload JSON kosong']);
            exit;
        }

        $vouchers = $input['vouchers'] ?? [];
        $radacct  = $input['radacct'] ?? [];
        $updatedCount = 0;

        $db = db();
        @$db->query("SET FOREIGN_KEY_CHECKS = 0;");
        $db->begin_transaction();
        try {
            // Update status voucher yang dipakai di node lokal
            if (!empty($vouchers)) {
                foreach ($vouchers as $v) {
                    $u = trim($v['username']);
                    $st = $v['status'];
                    $used = $v['used_at'];
                    $exp = $v['expired_at'];

                    if ($u && in_array($st, ['active', 'expired'])) {
                        db_execute("UPDATE vouchers SET status = ?, used_at = COALESCE(used_at, ?), expired_at = COALESCE(?, expired_at) WHERE username = ? AND status = 'unused'",
                            'ssss', [$st, $used, $exp, $u]);
                        $updatedCount++;
                    }
                }
            }

            // Simpan sesi radacct yang terjadi di node lokal
            if (!empty($radacct)) {
                foreach ($radacct as $ra) {
                    $u = trim($ra['username'] ?? '');
                    $sessId = trim($ra['acctsessionid'] ?? '');
                    if (!$u || !$sessId) continue;

                    // Masukkan ke radacct Master jika belum ada
                    $exist = db_fetch_one("SELECT radacctid FROM radacct WHERE acctsessionid = ? AND username = ? LIMIT 1", 'ss', [$sessId, $u]);
                    if (!$exist) {
                        db_execute("INSERT INTO radacct (acctsessionid, acctuniqueid, username, realm, nasipaddress, nasportid, nasporttype, acctstarttime, acctupdatetime, acctstoptime, acctsessiontime, acctauthentic, connectinfo_start, connectinfo_stop, acctinputoctets, acctoutputoctets, calledstationid, callingstationid, acctterminatecause, servicetype, framedprotocol, framedipaddress)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                            'ssssssssssisssiissssss', [
                                $ra['acctsessionid'] ?? '',
                                $ra['acctuniqueid'] ?? '',
                                $ra['username'] ?? '',
                                $ra['realm'] ?? '',
                                $ra['nasipaddress'] ?? '',
                                $ra['nasportid'] ?? '',
                                $ra['nasporttype'] ?? '',
                                $ra['acctstarttime'] ?? null,
                                $ra['acctupdatetime'] ?? null,
                                $ra['acctstoptime'] ?? null,
                                (int)($ra['acctsessiontime'] ?? 0),
                                $ra['acctauthentic'] ?? '',
                                $ra['connectinfo_start'] ?? '',
                                $ra['connectinfo_stop'] ?? '',
                                (int)($ra['acctinputoctets'] ?? 0),
                                (int)($ra['acctoutputoctets'] ?? 0),
                                $ra['calledstationid'] ?? '',
                                $ra['callingstationid'] ?? '',
                                $ra['acctterminatecause'] ?? '',
                                $ra['servicetype'] ?? '',
                                $ra['framedprotocol'] ?? '',
                                $ra['framedipaddress'] ?? ''
                            ]
                        );
                    }
                }
            }

            // Simpan voucher baru yang dibuat di cabang lokal (jika ada)
            $newVouchers = $input['new_vouchers'] ?? [];
            $newRadcheck = $input['radcheck'] ?? [];
            $newRadreply = $input['radreply'] ?? [];
            $newCreatedCount = 0;

            if (!empty($newVouchers)) {
                $hasRid = (bool)db_fetch_one("SHOW COLUMNS FROM vouchers LIKE 'router_id'");

                foreach ($newVouchers as $nv) {
                    $nu = trim($nv['username'] ?? '');
                    if (!$nu) continue;
                    $pwd = (string)($nv['password'] ?? '');
                    $pId = !empty($nv['profile_id']) ? (int)$nv['profile_id'] : 1;
                    $rId = !empty($nv['router_id']) ? (int)$nv['router_id'] : null;
                    $bId = (string)($nv['batch_id'] ?? '');
                    $st  = (string)($nv['status'] ?? 'unused');
                    $ca  = !empty($nv['created_at']) ? (string)$nv['created_at'] : date('Y-m-d H:i:s');

                    if ($hasRid) {
                        db_execute("INSERT INTO vouchers (username, password, profile_id, router_id, batch_id, status, created_at)
                                    VALUES (?, ?, ?, ?, ?, ?, ?)
                                    ON DUPLICATE KEY UPDATE status=VALUES(status), password=VALUES(password), profile_id=VALUES(profile_id)",
                                    'ssiisss', [$nu, $pwd, $pId, $rId, $bId, $st, $ca]);
                    } else {
                        db_execute("INSERT INTO vouchers (username, password, profile_id, batch_id, status, created_at)
                                    VALUES (?, ?, ?, ?, ?, ?)
                                    ON DUPLICATE KEY UPDATE status=VALUES(status), password=VALUES(password), profile_id=VALUES(profile_id)",
                                    'ssisss', [$nu, $pwd, $pId, $bId, $st, $ca]);
                    }
                    $newCreatedCount++;
                }
            }

            if (!empty($newRadcheck)) {
                foreach ($newRadcheck as $rc) {
                    $rcu = trim($rc['username'] ?? '');
                    $attr = trim($rc['attribute'] ?? '');
                    if (!$rcu || !$attr) continue;
                    db_execute("DELETE FROM radcheck WHERE username = ? AND attribute = ?", 'ss', [$rcu, $attr]);
                    db_execute("INSERT INTO radcheck (username, attribute, op, value)
                                VALUES (?, ?, ?, ?)",
                                'ssss', [$rcu, $attr, (string)$rc['op'], (string)$rc['value']]);
                }
            }

            if (!empty($newRadreply)) {
                foreach ($newRadreply as $rr) {
                    $rru = trim($rr['username'] ?? '');
                    $rattr = trim($rr['attribute'] ?? '');
                    if (!$rru || !$rattr) continue;
                    db_execute("DELETE FROM radreply WHERE username = ? AND attribute = ?", 'ss', [$rru, $rattr]);
                    db_execute("INSERT INTO radreply (username, attribute, op, value)
                                VALUES (?, ?, ?, ?)",
                                'ssss', [$rru, $rattr, (string)$rr['op'], (string)$rr['value']]);
                }
            }

            // Simpan riwayat penjualan (sales_log) yang terjadi di cabang lokal saat offline
            $newSales = $input['sales_log'] ?? [];
            $newSalesCount = 0;
            if (!empty($newSales)) {
                foreach ($newSales as $sl) {
                    $vu = trim($sl['voucher_username'] ?? '');
                    if (!$vu) continue;
                    $exist = db_fetch_one("SELECT id FROM sales_log WHERE voucher_username = ? LIMIT 1", 's', [$vu]);
                    if (!$exist) {
                        db_execute("INSERT INTO sales_log (voucher_id, voucher_username, profile_id, profile_name, router_id, sold_by, price, sold_at)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                                    'isisisds', [
                                        !empty($sl['voucher_id']) ? (int)$sl['voucher_id'] : null,
                                        $vu,
                                        !empty($sl['profile_id']) ? (int)$sl['profile_id'] : null,
                                        (string)($sl['profile_name'] ?? ''),
                                        !empty($sl['router_id']) ? (int)$sl['router_id'] : null,
                                        !empty($sl['sold_by']) ? (int)$sl['sold_by'] : null,
                                        (float)($sl['price'] ?? 0),
                                        (string)($sl['sold_at'] ?? date('Y-m-d H:i:s'))
                                    ]);
                        $newSalesCount++;
                    }
                }
            }

            // Simpan riwayat pembayaran PPPoE dari cabang (jika ada)
            $newPayments = $input['pppoe_payments'] ?? [];
            if (!empty($newPayments)) {
                foreach ($newPayments as $pay) {
                    $cid = (int)($pay['customer_id'] ?? 0);
                    $pm  = (int)($pay['period_month'] ?? 0);
                    $py  = (int)($pay['period_year'] ?? 0);
                    if (!$cid || !$pm || !$py) continue;
                    $pExist = db_fetch_one("SELECT id FROM pppoe_payments WHERE customer_id = ? AND period_month = ? AND period_year = ? LIMIT 1", 'iii', [$cid, $pm, $py]);
                    if (!$pExist) {
                        db_execute("INSERT INTO pppoe_payments (customer_id, amount, payment_method, payment_gateway, midtrans_status, period_month, period_year, paid_at, notes)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                                    'iisssiiss', [
                                        $cid, (int)($pay['amount'] ?? 0), (string)($pay['payment_method'] ?? 'cash'),
                                        'manual', 'settlement', $pm, $py, (string)($pay['paid_at'] ?? date('Y-m-d H:i:s')),
                                        (string)($pay['notes'] ?? '')
                                    ]);
                    }
                }
            }

            $db->commit();
            @$db->query("SET FOREIGN_KEY_CHECKS = 1;");

            if (!empty($validNode['id'])) {
                $pushStatusDesc = "Push Sukses ({$updatedCount} aktif, {$newCreatedCount} baru, {$newSalesCount} penjualan)";
                db_execute("UPDATE redundant_nodes SET last_sync_at = NOW(), last_sync_status = ? WHERE id = ?",
                    'si', [$pushStatusDesc, $validNode['id']]);
            }

            log_redundant_sync($nodeCode, 'push', 'success', "Node {$nodeCode} menyetor aktivitas ({$updatedCount} status terpakai, {$newCreatedCount} voucher baru, {$newSalesCount} penjualan, " . count($radacct) . " sesi radacct)");

            echo json_encode([
                'success' => true,
                'message' => "Aktivitas berhasil disinkronkan ke Master ({$newCreatedCount} voucher baru dibuat, {$updatedCount} status voucher diperbarui, {$newSalesCount} penjualan dicatat)",
                'created_count' => $newCreatedCount,
                'updated_count' => $updatedCount
            ]);

        } catch (Throwable $e) {
            $db->rollback();
            @$db->query("SET FOREIGN_KEY_CHECKS = 1;");
            log_redundant_sync($nodeCode, 'push', 'failed', "Gagal proses push: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Gagal memproses data push: ' . $e->getMessage()]);
        }
        exit;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Aksi '{$action}' tidak dikenal."]);
        exit;
}

