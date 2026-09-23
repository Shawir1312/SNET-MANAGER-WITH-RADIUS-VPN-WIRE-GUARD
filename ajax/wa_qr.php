<?php
/**
 * AJAX Bridge for WhatsApp Web QR, Pairing Code, Status & Port Diagnosis
 * Proxies requests between web panel and local Baileys microservice
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

// Deteksi dinamis URL & Port Microservice dari wa_config (Mencegah bentrok port statis)
$waCfg = db_fetch_one("SELECT api_url FROM wa_config LIMIT 1");
$rawApiUrl = $waCfg['api_url'] ?? 'http://127.0.0.1:3000/api/send';
$parsedUrl = parse_url($rawApiUrl);
$nodePort = $parsedUrl['port'] ?? 3000;
$nodeHost = $parsedUrl['host'] ?? '127.0.0.1';
$nodeScheme = $parsedUrl['scheme'] ?? 'http';
$nodeUrl = "{$nodeScheme}://{$nodeHost}:{$nodePort}";

function callNode(string $url, string $method = 'GET', array $data = []): array {
    global $nodePort;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 12);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);

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
            'http_code' => $httpCode,
            'curl_error' => $err,
            'port' => $nodePort,
            'message' => "Layanan background WhatsApp (Port $nodePort) belum merespons. Pastikan service aktif: sudo systemctl status snet-wa"
        ];
    }

    $json = json_decode($res, true);
    return is_array($json) ? $json : ['raw' => $res, 'status' => 'unknown', 'http_code' => $httpCode];
}

// ── ACTION: DIAGNOSE PORT & HEALTH ──
if ($action === 'diagnose') {
    $fp = @fsockopen($nodeHost, $nodePort, $errno, $errstr, 2);
    $portOpen = is_resource($fp);
    if ($portOpen) {
        fclose($fp);
    }

    $statusResp = callNode($nodeUrl . '/api/status');
    echo json_encode([
        'success' => true,
        'host' => $nodeHost,
        'port' => $nodePort,
        'port_open' => $portOpen,
        'node_url' => $nodeUrl,
        'status_response' => $statusResp,
        'server_time' => date('Y-m-d H:i:s')
    ]);
    exit;
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

if ($action === 'pairing_code') {
    $phone = trim($_POST['phone'] ?? get('phone', ''));
    if (empty($phone)) {
        echo json_encode(['success' => false, 'message' => 'Nomor WhatsApp wajib diisi']);
        exit;
    }
    $resp = callNode($nodeUrl . '/api/pairing-code', 'POST', ['phone' => $phone]);
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

if ($action === 'reset') {
    $resp = callNode($nodeUrl . '/api/reset', 'POST');
    echo json_encode($resp);
    exit;
}

echo json_encode(['error' => 'Invalid action']);
