<?php
/**
 * S.NET RADIUS Manager — AJAX Process Controller for Redundant Server
 */

define('IN_APP', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../include/functions.php';
require_once __DIR__ . '/../../include/redundant_functions.php';

auth_check();

while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

switch ($action) {
    case 'sync_now':
        $res = redundant_run_full_sync();
        echo json_encode($res);
        exit;

    case 'ping_master':
        $cfg = get_redundant_settings();
        $masterUrl = rtrim($cfg['master_url'] ?? 'https://10.77.77.1', '/');
        $apiKey    = trim($cfg['api_key'] ?? '');
        $nodeCode  = trim($cfg['node_code'] ?? 'NODE_LUSUO');

        $req = redundant_http_request($masterUrl . '/api/redundant_sync.php?action=ping', [
            'X-Redundant-Key: ' . $apiKey,
            'X-Node-Code: ' . $nodeCode,
            'Accept: application/json'
        ], null, 15);

        $out  = $req['response'];
        $code = $req['http_code'];
        $err  = $req['error'];

        if ($err) {
            echo json_encode(['success' => false, 'message' => "Gagal terhubung ke {$masterUrl}: {$err}"]);
            exit;
        }

        $res = json_decode((string)$out, true);
        if ($res && !empty($res['success'])) {
            echo json_encode([
                'success' => true,
                'message' => "Koneksi ke Master VPS Berhasil! (Status HTTP {$code})",
                'data'    => $res
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => $res['message'] ?? "Master VPS merespons HTTP {$code}: " . substr(strip_tags((string)$out), 0, 150)
            ]);
        }
        exit;

    case 'save_settings':
        $mode      = trim(post('redundant_mode', 'master'));
        $node_code = strtoupper(preg_replace('/[^a-zA-Z0-9_-]/', '', trim(post('node_code', 'NODE_LUSUO'))));
        $node_name = trim(post('node_name', 'Mini PC Desa Lusuo'));
        $master_url= trim(post('master_url', 'http://10.77.77.1'));
        $api_key   = trim(post('api_key', ''));

        save_redundant_settings([
            'redundant_mode' => $mode,
            'node_code'      => $node_code,
            'node_name'      => $node_name,
            'master_url'     => $master_url,
            'api_key'        => $api_key
        ]);

        echo json_encode(['success' => true, 'message' => 'Pengaturan redundansi berhasil disimpan!']);
        exit;

    case 'add_node':
        // Hanya bisa dilakukan di Master VPS
        $name = trim(post('node_name'));
        $code = strtoupper(preg_replace('/[^a-zA-Z0-9_-]/', '', trim(post('node_code'))));
        $ip   = trim(post('node_ip', ''));
        $rId  = (int)post('router_id', 0);

        if (!$name || !$code) {
            echo json_encode(['success' => false, 'message' => 'Nama dan Kode Node wajib diisi.']);
            exit;
        }

        $exist = db_fetch_one("SELECT id FROM redundant_nodes WHERE node_code = ?", 's', [$code]);
        if ($exist) {
            echo json_encode(['success' => false, 'message' => "Kode Node '{$code}' sudah terdaftar."]);
            exit;
        }

        $apiKey = generate_redundant_key();
        db_execute("INSERT INTO redundant_nodes (node_name, node_code, router_id, node_ip, api_key, status)
                    VALUES (?, ?, ?, ?, ?, 'active')",
                    'ssiss', [$name, $code, $rId, $ip, $apiKey]);

        echo json_encode([
            'success' => true,
            'message' => "Node '{$name}' berhasil didaftarkan!",
            'api_key' => $apiKey
        ]);
        exit;

    case 'delete_node':
        $id = (int)post('id', 0);
        if ($id > 0) {
            db_execute("DELETE FROM redundant_nodes WHERE id = ?", 'i', [$id]);
        }
        echo json_encode(['success' => true, 'message' => 'Node berhasil dihapus.']);
        exit;

    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        exit;
}

