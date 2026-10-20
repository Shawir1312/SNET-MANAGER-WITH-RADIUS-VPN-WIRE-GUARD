<?php
/**
 * AJAX Bridge for WhatsApp Web QR, Pairing Code, Status, Port Diagnosis,
 * Inbox Messages, Group List, Config & Reply
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

// ── NEW: INBOX (from database) ──
if ($action === 'inbox') {
    // Auto-create table if needed
    try {
        db_execute("CREATE TABLE IF NOT EXISTS wa_inbox (
            id INT AUTO_INCREMENT PRIMARY KEY,
            message_id VARCHAR(100) DEFAULT '',
            sender_phone VARCHAR(30) NOT NULL,
            sender_name VARCHAR(150) DEFAULT '',
            message_text TEXT DEFAULT NULL,
            message_type VARCHAR(30) DEFAULT 'text',
            is_group TINYINT(1) DEFAULT 0,
            group_jid VARCHAR(100) DEFAULT '',
            group_name VARCHAR(150) DEFAULT '',
            is_read TINYINT(1) DEFAULT 0,
            replied_at DATETIME DEFAULT NULL,
            reply_text TEXT DEFAULT NULL,
            customer_id INT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sender (sender_phone),
            INDEX idx_read (is_read),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}

    $page = max(1, (int)get('p', 1));
    $limit = 30;
    $offset = ($page - 1) * $limit;
    $filter = get('filter', '');

    $where = "WHERE is_group = 0";
    $params = [];
    $types = '';

    if ($filter) {
        $where .= " AND (sender_phone LIKE ? OR sender_name LIKE ? OR message_text LIKE ?)";
        $like = '%' . $filter . '%';
        $params = [$like, $like, $like];
        $types = 'sss';
    }

    $total = (int)(db_fetch_one("SELECT COUNT(*) as c FROM wa_inbox $where", $types, $params)['c'] ?? 0);
    $messages = db_fetch_all("SELECT i.*, c.full_name AS customer_name, c.pppoe_username 
        FROM wa_inbox i 
        LEFT JOIN pppoe_customers c ON c.id = i.customer_id 
        $where 
        ORDER BY i.id DESC 
        LIMIT $limit OFFSET $offset", $types, $params);
    
    $unread = (int)(db_fetch_one("SELECT COUNT(*) as c FROM wa_inbox WHERE is_read = 0 AND is_group = 0")['c'] ?? 0);

    echo json_encode([
        'success' => true,
        'messages' => $messages,
        'total' => $total,
        'unread' => $unread,
        'page' => $page,
        'total_pages' => max(1, ceil($total / $limit))
    ]);
    exit;
}

// ── NEW: MARK READ ──
if ($action === 'mark_read') {
    $ids = $_POST['ids'] ?? get('ids', '');
    if ($ids === 'all') {
        db_execute("UPDATE wa_inbox SET is_read = 1 WHERE is_read = 0");
    } elseif ($ids) {
        $idArr = array_map('intval', explode(',', $ids));
        $placeholders = implode(',', array_fill(0, count($idArr), '?'));
        $types = str_repeat('i', count($idArr));
        db_execute("UPDATE wa_inbox SET is_read = 1 WHERE id IN ($placeholders)", $types, $idArr);
    }
    echo json_encode(['success' => true]);
    exit;
}

// ── NEW: UNREAD COUNT ──
if ($action === 'unread_count') {
    try {
        db_execute("CREATE TABLE IF NOT EXISTS wa_inbox (
            id INT AUTO_INCREMENT PRIMARY KEY,
            message_id VARCHAR(100) DEFAULT '',
            sender_phone VARCHAR(30) NOT NULL,
            sender_name VARCHAR(150) DEFAULT '',
            message_text TEXT DEFAULT NULL,
            message_type VARCHAR(30) DEFAULT 'text',
            is_group TINYINT(1) DEFAULT 0,
            group_jid VARCHAR(100) DEFAULT '',
            group_name VARCHAR(150) DEFAULT '',
            is_read TINYINT(1) DEFAULT 0,
            replied_at DATETIME DEFAULT NULL,
            reply_text TEXT DEFAULT NULL,
            customer_id INT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}
    
    $count = (int)(db_fetch_one("SELECT COUNT(*) as c FROM wa_inbox WHERE is_read = 0 AND is_group = 0")['c'] ?? 0);
    echo json_encode(['success' => true, 'unread' => $count]);
    exit;
}

// ── NEW: REPLY TO CUSTOMER ──
if ($action === 'reply') {
    $phone = trim($_POST['phone'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $inboxId = (int)($_POST['inbox_id'] ?? 0);

    if (empty($phone) || empty($message)) {
        echo json_encode(['success' => false, 'message' => 'Nomor dan pesan wajib diisi.']);
        exit;
    }

    $resp = callNode($nodeUrl . '/api/reply', 'POST', ['phone' => $phone, 'message' => $message]);

    // Mark as replied in database
    if (($resp['success'] ?? false) && $inboxId > 0) {
        try {
            db_execute(
                "UPDATE wa_inbox SET replied_at = NOW(), reply_text = ?, is_read = 1 WHERE id = ?",
                'si',
                [$message, $inboxId]
            );
        } catch (Exception $e) {}
    }

    echo json_encode($resp);
    exit;
}

// ── NEW: GROUP LIST ──
if ($action === 'groups') {
    $resp = callNode($nodeUrl . '/api/groups');
    echo json_encode($resp);
    exit;
}

// ── NEW: GATEWAY CONFIG (GET/SET) ──
if ($action === 'get_config') {
    $resp = callNode($nodeUrl . '/api/config');
    echo json_encode($resp);
    exit;
}

if ($action === 'save_config_gateway') {
    $data = [
        'forward_group_jid' => $_POST['forward_group_jid'] ?? '',
        'forward_enabled' => (bool)($_POST['forward_enabled'] ?? false),
        'webhook_url' => $_POST['webhook_url'] ?? '',
        'webhook_enabled' => (bool)($_POST['webhook_enabled'] ?? true)
    ];
    $resp = callNode($nodeUrl . '/api/config', 'POST', $data);
    echo json_encode($resp);
    exit;
}

echo json_encode(['error' => 'Invalid action']);
