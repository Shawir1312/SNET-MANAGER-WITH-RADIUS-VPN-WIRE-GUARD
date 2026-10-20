<?php
/**
 * WhatsApp Inbox Webhook — Receives incoming and outgoing messages from Baileys Node.js engine
 * Called automatically by wa-service/server.js
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Security: Allow from localhost or private network
$remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
$allowedIps = ['127.0.0.1', '::1', 'localhost'];
$isLocal = in_array($remoteIp, $allowedIps) 
    || str_starts_with($remoteIp, '10.') 
    || str_starts_with($remoteIp, '172.') 
    || str_starts_with($remoteIp, '192.168.');

if (!$isLocal) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['sender_phone'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid payload']);
    exit;
}

// Auto-create / migrate wa_inbox table
try {
    db_execute("CREATE TABLE IF NOT EXISTS wa_inbox (
        id INT AUTO_INCREMENT PRIMARY KEY,
        message_id VARCHAR(100) DEFAULT '',
        sender_phone VARCHAR(30) NOT NULL,
        sender_name VARCHAR(150) DEFAULT '',
        message_text TEXT DEFAULT NULL,
        message_type VARCHAR(30) DEFAULT 'text',
        direction ENUM('in', 'out') DEFAULT 'in',
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

    // Add direction column if not exists
    try {
        db_execute("ALTER TABLE wa_inbox ADD COLUMN direction ENUM('in', 'out') DEFAULT 'in' AFTER message_type");
    } catch (Exception $e) {}
} catch (Exception $e) {}

$messageId   = $input['message_id'] ?? '';
$senderPhone = preg_replace('/\D/', '', $input['sender_phone'] ?? '');
$senderName  = substr($input['sender_name'] ?? '', 0, 150);
$text        = $input['text'] ?? '';
$msgType     = $input['message_type'] ?? 'text';
$direction   = ($input['direction'] ?? ($input['from_me'] ? 'out' : 'in')) === 'out' ? 'out' : 'in';
$isGroup     = (int)($input['is_group'] ?? 0);
$groupJid    = $input['group_jid'] ?? '';
$groupName   = $input['group_name'] ?? '';
$isRead      = ($direction === 'out') ? 1 : 0;

// Don't store group messages in inbox (they are forwarded, not stored individually)
if ($isGroup) {
    echo json_encode(['success' => true, 'stored' => false, 'reason' => 'group_message_skipped']);
    exit;
}

// Try to match sender to a pppoe_customer
$customerId = null;
try {
    $phoneVariants = [$senderPhone];
    if (str_starts_with($senderPhone, '62')) {
        $phoneVariants[] = '0' . substr($senderPhone, 2);
    }
    foreach ($phoneVariants as $pv) {
        $customer = db_fetch_one(
            "SELECT id FROM pppoe_customers WHERE REPLACE(REPLACE(REPLACE(phone, '+', ''), '-', ''), ' ', '') LIKE ? LIMIT 1",
            's',
            ['%' . $pv . '%']
        );
        if ($customer) {
            $customerId = (int)$customer['id'];
            break;
        }
    }
} catch (Exception $e) {}

// Prevent duplicate messages
try {
    if ($messageId) {
        $exists = db_fetch_one(
            "SELECT id FROM wa_inbox WHERE message_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND) LIMIT 1",
            's',
            [$messageId]
        );
        if ($exists) {
            echo json_encode(['success' => true, 'stored' => false, 'reason' => 'duplicate']);
            exit;
        }
    }
} catch (Exception $e) {}

// Insert into wa_inbox
try {
    db_execute(
        "INSERT INTO wa_inbox (message_id, sender_phone, sender_name, message_text, message_type, direction, is_group, group_jid, group_name, is_read, customer_id) 
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        'ssssssissii',
        [$messageId, $senderPhone, $senderName, $text, $msgType, $direction, $isGroup, $groupJid, $groupName, $isRead, $customerId]
    );

    echo json_encode(['success' => true, 'stored' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
}
