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
$headers = getallheaders();
$apiKey = $headers['X-Redundant-Key'] ?? ($headers['x-redundant-key'] ?? ($_GET['api_key'] ?? ''));
$nodeCode = $headers['X-Node-Code'] ?? ($headers['x-node-code'] ?? ($_GET['node_code'] ?? 'UNKNOWN'));

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
    echo json_encode(['success' => false, 'message' => 'Autentikasi ditolak: API Key tidak cocok atau Node dinonaktifkan.']);
    exit;
}

$action = $_GET['action'] ?? 'ping';

switch ($action) {
    case 'ping':
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
            // Ambil semua profil
            $profiles = db_fetch_all("SELECT id, name, price, validity_value, validity_unit, duration_value, duration_unit, rate_up, rate_down, quota_mb, router_id FROM profiles");

            // Ambil semua router & nas
            $routers  = db_fetch_all("SELECT id, name, ip_address, nas_ip, radius_secret, status FROM routers WHERE status != 'inactive' OR status IS NULL");
            $nas      = db_fetch_all("SELECT id, nasname, shortname, type, secret, description FROM nas");

            // Ambil seluruh voucher aktif & belum terpakai (atau maksimal 3000 voucher terbaru)
            $vouchers = db_fetch_all("SELECT id, username, password, profile_id, batch_id, status, used_at, expired_at, created_at FROM vouchers WHERE status IN ('unused', 'active') OR created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY id DESC LIMIT 5000");

            // Ambil radcheck & radreply untuk voucher-voucher tersebut
            $radcheck = db_fetch_all("SELECT username, attribute, op, value FROM radcheck");
            $radreply = db_fetch_all("SELECT username, attribute, op, value FROM radreply");

            // Update status sinkronisasi node di tabel master jika terdaftar
            if (!empty($validNode['id'])) {
                db_execute("UPDATE redundant_nodes SET last_sync_at = NOW(), last_sync_status = 'Pull Catalog Sukses', total_vouchers_synced = ? WHERE id = ?", 'ii', [count($vouchers), $validNode['id']]);
            }

            echo json_encode([
                'success' => true,
                'payload' => [
                    'profiles' => $profiles,
                    'routers'  => $routers,
                    'nas'      => $nas,
                    'vouchers' => $vouchers,
                    'radcheck' => $radcheck,
                    'radreply' => $radreply,
                    'count'    => count($vouchers)
                ]
            ]);
        } catch (Throwable $e) {
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

            $db->commit();

            if (!empty($validNode['id'])) {
                db_execute("UPDATE redundant_nodes SET last_sync_at = NOW(), last_sync_status = ? WHERE id = ?",
                    'si', ["Push Sukses ({$updatedCount} voucher terpakai)", $validNode['id']]);
            }

            echo json_encode([
                'success' => true,
                'message' => "Aktivitas berhasil disinkronkan ke Master ({$updatedCount} status voucher diperbarui)",
                'updated_count' => $updatedCount
            ]);

        } catch (Throwable $e) {
            $db->rollback();
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Gagal memproses data push: ' . $e->getMessage()]);
        }
        exit;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Aksi '{$action}' tidak dikenal."]);
        exit;
}
