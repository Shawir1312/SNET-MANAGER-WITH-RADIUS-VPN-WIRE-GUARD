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

// Lepaskan lock session PHP agar AJAX request paralel (polling chat, cek grup, status) tidak saling memblokir
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$action = get('action', 'status');

// Microservice Baileys lokal selalu berada di localhost / 127.0.0.1
// Port dideteksi dinamis dari wa_config jika menggunakan URL lokal, default 3000
$waCfg = db_fetch_one("SELECT api_url FROM wa_config LIMIT 1");
$rawApiUrl = $waCfg['api_url'] ?? 'http://127.0.0.1:3000/api/send';
$parsedUrl = parse_url($rawApiUrl);

$nodePort = 3000;
if (!empty($parsedUrl['host']) && in_array($parsedUrl['host'], ['127.0.0.1', 'localhost', '::1'])) {
    $nodePort = $parsedUrl['port'] ?? 3000;
}
$nodeHost = '127.0.0.1';
$nodeScheme = 'http';
$nodeUrl = "http://127.0.0.1:{$nodePort}";

function callNode(string $url, string $method = 'GET', array $data = [], int $timeout = 15): array {
    global $nodePort;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
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
    $force = get('force', '');
    $url = $nodeUrl . '/api/qr' . ($force ? '?force=1' : '');
    $resp = callNode($url, 'GET', [], 15);
    echo json_encode($resp);
    exit;
}

if ($action === 'pairing_code') {
    $phone = trim($_POST['phone'] ?? get('phone', ''));
    if (empty($phone)) {
        echo json_encode(['success' => false, 'message' => 'Nomor WhatsApp wajib diisi']);
        exit;
    }
    $resp = callNode($nodeUrl . '/api/pairing-code', 'POST', ['phone' => $phone], 25);
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

// ── WHATSAPP WEB: AMBIL DAFTAR CHAT (CONVERSATIONS) ──
if ($action === 'chats') {
    // 1. Coba ambil dari Node.js Microservice
    $nodeChats = callNode($nodeUrl . '/api/chats');
    $chatList = [];
    $seenPhones = [];

    if (!empty($nodeChats['success']) && isset($nodeChats['chats']) && is_array($nodeChats['chats'])) {
        foreach ($nodeChats['chats'] as $c) {
            $rawPhone = preg_replace('/\D/', '', $c['phone'] ?? '');
            if (!$rawPhone) continue;
            $seenPhones[$rawPhone] = true;
            $chatList[] = [
                'phone' => $rawPhone,
                'name' => $c['name'] ?? ('+' . $rawPhone),
                'unread_count' => (int)($c['unread_count'] ?? 0),
                'last_message' => $c['last_message'] ?? '',
                'last_time' => $c['last_time'] ?? date('Y-m-d H:i:s'),
                'total_messages' => (int)($c['total_messages'] ?? 0)
            ];
        }
    }

    // 2. Gabungkan dengan data wa_inbox di MySQL (jika ada pesan yang belum ada di Node)
    try {
        $dbChats = db_fetch_all("
            SELECT 
                m.sender_phone, 
                m.sender_name, 
                m.message_text, 
                m.created_at,
                COALESCE(u.db_unread, 0) AS db_unread,
                COALESCE(u.total_db, 0) AS total_db
            FROM wa_inbox m
            INNER JOIN (
                SELECT sender_phone, MAX(id) AS max_id,
                       SUM(CASE WHEN is_read = 0 AND direction = 'in' THEN 1 ELSE 0 END) AS db_unread,
                       COUNT(*) AS total_db
                FROM wa_inbox
                WHERE is_group = 0
                GROUP BY sender_phone
            ) u ON m.id = u.max_id
            ORDER BY m.created_at DESC
        ");

        foreach ($dbChats as $dc) {
            $clean = preg_replace('/\D/', '', $dc['sender_phone']);
            if (isset($seenPhones[$clean])) {
                // Perbarui unread count dari DB jika lebih tinggi
                foreach ($chatList as &$cl) {
                    if ($cl['phone'] === $clean) {
                        if ((int)$dc['db_unread'] > $cl['unread_count']) {
                            $cl['unread_count'] = (int)$dc['db_unread'];
                        }
                        break;
                    }
                }
            } else {
                $chatList[] = [
                    'phone' => $clean,
                    'name' => $dc['sender_name'] ?: ('+' . $clean),
                    'unread_count' => (int)$dc['db_unread'],
                    'last_message' => $dc['message_text'] ?: '',
                    'last_time' => $dc['created_at'],
                    'total_messages' => (int)$dc['total_db']
                ];
            }
        }
    } catch (Exception $e) {
        error_log("wa_qr.php dbChats error: " . $e->getMessage());
    }

    // 3. Cocokkan setiap nomor dengan data pelanggan PPPoE
    foreach ($chatList as &$chat) {
        $ph = $chat['phone'];
        $variants = [$ph];
        if (str_starts_with($ph, '62')) {
            $variants[] = '0' . substr($ph, 2);
        } elseif (str_starts_with($ph, '0')) {
            $variants[] = '62' . substr($ph, 1);
        }

        $customer = null;
        foreach ($variants as $v) {
            $found = db_fetch_one(
                "SELECT id, full_name, pppoe_username, profile, monthly_price, due_day, status 
                 FROM pppoe_customers 
                 WHERE REPLACE(REPLACE(REPLACE(phone, '+', ''), '-', ''), ' ', '') LIKE ? LIMIT 1",
                's',
                ['%' . $v . '%']
            );
            if ($found) {
                $customer = $found;
                break;
            }
        }

        if ($customer) {
            $chat['customer'] = [
                'id' => (int)$customer['id'],
                'name' => $customer['full_name'],
                'username' => $customer['pppoe_username'],
                'profile' => $customer['profile'],
                'price' => (float)$customer['monthly_price'],
                'due_day' => (int)$customer['due_day'],
                'status' => $customer['status']
            ];
            // Gunakan nama pelanggan PPPoE jika ada
            if (!empty($customer['full_name'])) {
                $chat['name'] = $customer['full_name'];
            }
        } else {
            $chat['customer'] = null;
        }
    }

    // Urutkan percakapan dari yang paling baru
    usort($chatList, function($a, $b) {
        return strtotime($b['last_time']) <=> strtotime($a['last_time']);
    });

    $totalUnread = 0;
    foreach ($chatList as $c) {
        $totalUnread += $c['unread_count'];
    }

    $gwStatus = $nodeChats['status'] ?? 'unknown';
    if (!empty($nodeChats['success']) && !empty($nodeChats['status'])) {
        $gwStatus = $nodeChats['status'];
    } elseif (isset($nodeChats['status']) && $nodeChats['status'] === 'offline') {
        $gwStatus = 'offline';
    }

    echo json_encode([
        'success' => true,
        'gateway_status' => $gwStatus,
        'connected_user' => $nodeChats['user'] ?? null,
        'total_unread' => $totalUnread,
        'chats' => $chatList
    ]);
    exit;
}

// ── WHATSAPP WEB: AMBIL RIWAYAT PESAN DENGAN NOMOR TERTENTU ──
if ($action === 'chat_messages') {
    $phone = preg_replace('/\D/', '', get('phone', ''));
    if (empty($phone)) {
        echo json_encode(['success' => false, 'message' => 'Nomor WhatsApp tidak valid']);
        exit;
    }

    $allMessages = [];
    $seenIds = [];

    // 1. Ambil dari Node.js
    $nodeRes = callNode($nodeUrl . '/api/chat/' . $phone);
    if (!empty($nodeRes['success']) && isset($nodeRes['messages']) && is_array($nodeRes['messages'])) {
        foreach ($nodeRes['messages'] as $m) {
            $mId = $m['id'] ?? '';
            if ($mId) $seenIds[$mId] = true;
            $allMessages[] = [
                'id' => $mId,
                'direction' => $m['direction'] ?? 'in',
                'text' => $m['text'] ?? '',
                'type' => $m['type'] ?? 'text',
                'timestamp' => $m['timestamp'] ?? date('Y-m-d H:i:s'),
                'status' => $m['status'] ?? 'delivered'
            ];
        }
    }

    // 2. Ambil dari wa_inbox MySQL
    $variants = [$phone];
    if (str_starts_with($phone, '62')) $variants[] = '0' . substr($phone, 2);
    elseif (str_starts_with($phone, '0')) $variants[] = '62' . substr($phone, 1);

    $inPlaceholders = implode(',', array_fill(0, count($variants), '?'));
    $types = str_repeat('s', count($variants));

    try {
        $dbMsgs = db_fetch_all(
            "SELECT * FROM wa_inbox WHERE is_group = 0 AND sender_phone IN ($inPlaceholders) ORDER BY id ASC",
            $types,
            $variants
        );

        foreach ($dbMsgs as $dm) {
            $mId = $dm['message_id'] ?: ('db_' . $dm['id']);
            if (!isset($seenIds[$mId])) {
                $seenIds[$mId] = true;
                $allMessages[] = [
                    'id' => $mId,
                    'direction' => $dm['direction'] ?? 'in',
                    'text' => $dm['message_text'] ?? '',
                    'type' => $dm['message_type'] ?? 'text',
                    'timestamp' => $dm['created_at'],
                    'status' => 'delivered'
                ];
            }
        }

        // Tandai sudah dibaca di database
        db_execute(
            "UPDATE wa_inbox SET is_read = 1 WHERE sender_phone IN ($inPlaceholders) AND is_read = 0",
            $types,
            $variants
        );
    } catch (Exception $e) {}

    // Tandai sudah dibaca di Node.js
    callNode($nodeUrl . '/api/chat/read', 'POST', ['phone' => $phone]);

    // Urutkan pesan secara kronologis (terlama ke terbaru)
    usort($allMessages, function($a, $b) {
        return strtotime($a['timestamp']) <=> strtotime($b['timestamp']);
    });

    // Ambil info pelanggan PPPoE
    $customer = null;
    foreach ($variants as $v) {
        $found = db_fetch_one(
            "SELECT id, full_name, pppoe_username, profile, monthly_price, due_day, status, ip_address 
             FROM pppoe_customers 
             WHERE REPLACE(REPLACE(REPLACE(phone, '+', ''), '-', ''), ' ', '') LIKE ? LIMIT 1",
            's',
            ['%' . $v . '%']
        );
        if ($found) {
            $customer = $found;
            break;
        }
    }

    echo json_encode([
        'success' => true,
        'phone' => $phone,
        'customer' => $customer,
        'messages' => $allMessages
    ]);
    exit;
}

// ── WHATSAPP WEB: KIRIM PESAN CHAT LANGSUNG ──
if ($action === 'send_chat') {
    $phone = preg_replace('/\D/', '', $_POST['phone'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if (empty($phone) || empty($message)) {
        echo json_encode(['success' => false, 'message' => 'Nomor dan pesan wajib diisi.']);
        exit;
    }

    // Kirim via Node.js microservice
    $resp = callNode($nodeUrl . '/api/chat/send', 'POST', [
        'phone' => $phone,
        'message' => $message
    ]);

    // Simpan ke MySQL wa_inbox sebagai pesan keluar
    if (!empty($resp['success'])) {
        try {
            db_execute(
                "INSERT INTO wa_inbox (message_id, sender_phone, sender_name, message_text, message_type, direction, is_read) 
                 VALUES (?, ?, 'S.NET Admin', ?, 'text', 'out', 1)",
                'sss',
                [$resp['message_id'] ?? ('out_' . time()), $phone, $message]
            );
        } catch (Exception $e) {}

        // Catat ke wa_logs
        try {
            db_execute(
                "INSERT INTO wa_logs (phone, recipient_name, message_type, message_text, status, response_payload) 
                 VALUES (?, 'Pelanggan', 'waweb_chat', ?, 'success', ?)",
                'sss',
                [$phone, $message, json_encode($resp)]
            );
        } catch (Exception $e) {}
    }

    echo json_encode($resp);
    exit;
}

// ── COMPATIBILITY: INBOX ACTION (PAGINATED LIST) ──
if ($action === 'inbox') {
    // Sinkronkan pesan dari Node.js ke MySQL jika ada
    $nodeMsgs = callNode($nodeUrl . '/api/messages?limit=50');
    if (!empty($nodeMsgs['success']) && isset($nodeMsgs['messages']) && is_array($nodeMsgs['messages'])) {
        foreach ($nodeMsgs['messages'] as $nm) {
            $mId = $nm['message_id'] ?? '';
            $sPhone = preg_replace('/\D/', '', $nm['sender_phone'] ?? '');
            if (!$sPhone) continue;

            try {
                $exists = db_fetch_one("SELECT id FROM wa_inbox WHERE message_id = ? LIMIT 1", 's', [$mId]);
                if (!$exists) {
                    db_execute(
                        "INSERT INTO wa_inbox (message_id, sender_phone, sender_name, message_text, message_type, direction, is_read, created_at) 
                         VALUES (?, ?, ?, ?, ?, 'in', 0, ?)",
                        'ssssss',
                        [$mId, $sPhone, $nm['sender_name'] ?? '', $nm['text'] ?? '', $nm['message_type'] ?? 'text', $nm['timestamp'] ?? date('Y-m-d H:i:s')]
                    );
                }
            } catch (Exception $e) {}
        }
    }

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
    
    $unread = (int)(db_fetch_one("SELECT COUNT(*) as c FROM wa_inbox WHERE is_read = 0 AND direction = 'in' AND is_group = 0")['c'] ?? 0);

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

// ── ACTION: TANDAI SEMUA SUDAH DIBACA ──
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

// ── ACTION: HITUNG PESAN BELUM DIBACA ──
if ($action === 'unread_count') {
    $count = (int)(db_fetch_one("SELECT COUNT(*) as c FROM wa_inbox WHERE is_read = 0 AND direction = 'in' AND is_group = 0")['c'] ?? 0);
    echo json_encode(['success' => true, 'unread' => $count]);
    exit;
}

// ── ACTION: REPLY MODAL LAMA ──
if ($action === 'reply') {
    $phone = trim($_POST['phone'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $inboxId = (int)($_POST['inbox_id'] ?? 0);

    if (empty($phone) || empty($message)) {
        echo json_encode(['success' => false, 'message' => 'Nomor dan pesan wajib diisi.']);
        exit;
    }

    $resp = callNode($nodeUrl . '/api/reply', 'POST', ['phone' => $phone, 'message' => $message]);

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

// ── WHATSAPP WEB: PERBAIKI SESI ENKRIPSI KONTAK TERTENTU ──
if ($action === 'repair_session') {
    $phone = preg_replace('/\D/', '', $_POST['phone'] ?? get('phone', ''));
    if (empty($phone)) {
        echo json_encode(['success' => false, 'message' => 'Nomor WhatsApp wajib diisi']);
        exit;
    }
    $resp = callNode($nodeUrl . '/api/chat/repair-session', 'POST', ['phone' => $phone]);
    echo json_encode($resp);
    exit;
}

// ── ACTION: DAFTAR GRUP WA ──
if ($action === 'groups') {
    $resp = callNode($nodeUrl . '/api/groups');
    echo json_encode($resp);
    exit;
}

// ── ACTION: KONFIGURASI GATEWAY ──
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
