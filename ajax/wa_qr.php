<?php
/**
 * AJAX Bridge for WhatsApp Web QR & Status
 * Proxies requests between web panel and local Baileys microservice (Port 3000)
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../include/functions.php';

auth_check();

// Allow superadmin only
$admin = current_admin();
if (!$admin || $admin['role'] !== 'superadmin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action = get('action', 'status');
$nodeUrl = 'http://127.0.0.1:3000';

function callNode(string $url, string $method = 'GET', array $data = []): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $res = curl_exec($ch);
    $err = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err || $httpCode === 0) {
        return [
            'success' => false,
            'status' => 'offline',
            'message' => 'Layanan background WhatsApp (Port 3000) belum berjalan. Pastikan sudah menjalankan: sudo bash setup_wa_service.sh di VPS Anda.'
        ];
    }

    $json = json_decode($res, true);
    return is_array($json) ? $json : ['raw' => $res, 'status' => 'unknown'];
}

if ($action === 'status') {
    $resp = callNode($nodeUrl . '/api/status');
    echo json_encode($resp);
    exit;
}

if ($action === 'qr') {
    $resp = callNode($nodeUrl . '/api/qr');
    echo json_encode($resp);
    exit;
}

if ($action === 'logout') {
    $resp = callNode($nodeUrl . '/api/logout', 'POST');
    echo json_encode($resp);
    exit;
}

if ($action === 'restart') {
    $resp = callNode($nodeUrl . '/api/restart', 'POST');
    echo json_encode($resp);
    exit;
}

echo json_encode(['error' => 'Invalid action']);
