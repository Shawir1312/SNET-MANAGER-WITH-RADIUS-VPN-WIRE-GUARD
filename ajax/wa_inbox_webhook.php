<?php
/**
 * WhatsApp Inbox Webhook — Receives incoming messages from Baileys Node.js engine
 * Called automatically by wa-service/server.js when a message is received
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

// Only accept POST from localhost
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Security: Only allow from localhost
$remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
$allowedIps = ['127.0.0.1', '::1', 'localhost'];
if (!in_array($remoteIp, $allowedIps)) {
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

// Auto-create wa_inbox table if not exists
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
} catch (Exception $e) {
    // Table probably exists already
}

$messageId   = $input['message_id'] ?? '';
$senderPhone = preg_replace('/\D/', '', $input['sender_phone'] ?? '');
$senderName  = substr($input['sender_name'] ?? '', 0, 150);
$text        = $input['text'] ?? '';
$msgType     = $input['message_type'] ?? 'text';
$isGroup     = (int)($input['is_group'] ?? 0);
$groupJid    = $input['group_jid'] ?? '';
$groupName   = $input['group_name'] ?? '';

// Don't store group messages in inbox (they are forwarded, not stored individually)
if ($isGroup) {
    echo json_encode(['success' => true, 'stored' => false, 'reason' => 'group_message_skipped']);
    exit;
}

// Try to match sender to a pppoe_customer
$customerId = null;
try {
    // Try matching phone number — strip leading 62 for 0-prefixed search too
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
} catch (Exception $e) {
    // Ignore — matching is best-effort
}

// Prevent duplicate messages (same message_id within 60 seconds)
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
        "INSERT INTO wa_inbox (message_id, sender_phone, sender_name, message_text, message_type, is_group, group_jid, group_name, customer_id) 
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
        'sssssissi',
        [$messageId, $senderPhone, $senderName, $text, $msgType, $isGroup, $groupJid, $groupName, $customerId]
    );

    echo json_encode(['success' => true, 'stored' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
}
