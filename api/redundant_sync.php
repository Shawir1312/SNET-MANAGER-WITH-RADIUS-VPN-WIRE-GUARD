<?php
/**
 * S.NET RADIUS Manager — API Endpoint Replikasi Redundant Server (Master VPS)
 * 
 * Endpoint ini menerima koneksi dari Node Lokal (Mini PC Cabang):
 * - Memverifikasi X-Redundant-Key
 * - Melayani aksi 'ping', 'pull_catalog', dan 'push_activity'
 */

define('IN_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../include/functions.php';
require_once __DIR__ . '/../include/redundant_functions.php';

while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

// 1. Ambil Header Autentikasi
$headers = function_exists('getallheaders') ? getallheaders() : [];
$apiKey = $_SERVER['HTTP_X_REDUNDANT_KEY'] ?? ($headers['X-Redundant-Key'] ?? ($headers['x-redundant-key'] ?? ($_GET['api_key'] ?? ($_POST['api_key'] ?? ''))));
$nodeCode = $_SERVER['HTTP_X_NODE_CODE'] ?? ($headers['X-Node-Code'] ?? ($headers['x-node-code'] ?? ($_GET['node_code'] ?? ($_POST['node_code'] ?? 'UNKNOWN'))));

if (empty($apiKey)) {
    http_response_code(401);
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
    http_response_code(403);
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

            // Ambil SELURUH voucher (tersedia, aktif, dan riwayat lengkap hingga 100.000 voucher)
            $vouchers = db_fetch_all("SELECT id, username, password, profile_id, batch_id, status, used_at, expired_at, created_at FROM vouchers ORDER BY id DESC LIMIT 100000");

            // Ambil radcheck & radreply untuk voucher-voucher tersebut
            $radcheck = db_fetch_all("SELECT username, attribute, op, value FROM radcheck");
            $radreply = db_fetch_all("SELECT username, attribute, op, value FROM radreply");

            // Ambil admin superadmin agar bisa login di node lokal
            $admins = db_fetch_all("SELECT id, username, password, full_name, role, is_active FROM admins WHERE is_active = 1");

            // Update status sinkronisasi node di tabel master jika terdaftar
            if (!empty($validNode['id'])) {
                db_execute("UPDATE redundant_nodes SET last_sync_at = NOW(), last_sync_status = 'Pull Catalog Sukses', total_vouchers_synced = ? WHERE id = ?", 'ii', [count($vouchers), $validNode['id']]);
            }

            log_redundant_sync($nodeCode, 'pull', 'success', "Node {$nodeCode} berhasil mendownload katalog (" . count($vouchers) . " voucher, " . count($profiles) . " profil)");

            echo json_encode([
                'success' => true,
                'payload' => [
                    'profiles' => $profiles,
                    'routers'  => $routers,
                    'nas'      => $nas,
                    'vouchers' => $vouchers,
                    'radcheck' => $radcheck,
                    'radreply' => $radreply,
                    'admins'   => $admins,
                    'count'    => count($vouchers)
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
                foreach ($newVouchers as $nv) {
                    $nu = trim($nv['username'] ?? '');
                    if (!$nu) continue;
                    db_execute("INSERT INTO vouchers (username, password, profile_id, router_id, batch_id, status, created_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE status=VALUES(status)",
                                'ssiisss', [
                                    $nu, (string)($nv['password'] ?? ''), (int)($nv['profile_id'] ?? 1),
                                    (int)($nv['router_id'] ?? 0), (string)($nv['batch_id'] ?? ''),
                                    (string)($nv['status'] ?? 'unused'), (string)($nv['created_at'] ?? date('Y-m-d H:i:s'))
                                ]);
                    $newCreatedCount++;
                }
            }

            if (!empty($newRadcheck)) {
                foreach ($newRadcheck as $rc) {
                    $rcu = trim($rc['username'] ?? '');
                    if (!$rcu) continue;
                    db_execute("INSERT INTO radcheck (username, attribute, op, value)
                                VALUES (?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE value=VALUES(value)",
                                'ssss', [$rcu, (string)$rc['attribute'], (string)$rc['op'], (string)$rc['value']]);
                }
            }

            if (!empty($newRadreply)) {
                foreach ($newRadreply as $rr) {
                    $rru = trim($rr['username'] ?? '');
                    if (!$rru) continue;
                    db_execute("INSERT INTO radreply (username, attribute, op, value)
                                VALUES (?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE value=VALUES(value)",
                                'ssss', [$rru, (string)$rr['attribute'], (string)$rr['op'], (string)$rr['value']]);
                }
            }

            $db->commit();

            if (!empty($validNode['id'])) {
                db_execute("UPDATE redundant_nodes SET last_sync_at = NOW(), last_sync_status = ? WHERE id = ?",
                    'si', ["Push Sukses ({$updatedCount} voucher terpakai, {$newCreatedCount} voucher baru)", $validNode['id']]);
            }

            log_redundant_sync($nodeCode, 'push', 'success', "Node {$nodeCode} menyetor aktivitas ({$updatedCount} status terpakai, {$newCreatedCount} voucher baru, " . count($radacct) . " sesi radacct)");

            echo json_encode([
                'success' => true,
                'message' => "Aktivitas berhasil disinkronkan ke Master ({$updatedCount} status voucher diperbarui)",
                'updated_count' => $updatedCount
            ]);

        } catch (Throwable $e) {
            $db->rollback();
            log_redundant_sync($nodeCode, 'push', 'failed', "Gagal proses push: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Gagal memproses data push: ' . $e->getMessage()]);
        }
        exit;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Aksi '{$action}' tidak dikenal."]);
        exit;
}

