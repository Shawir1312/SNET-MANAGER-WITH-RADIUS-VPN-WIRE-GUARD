/**
 * S.NET WhatsApp Web Microservice (Baileys Engine)
 * Self-Hosted QR Code & Pairing Code WhatsApp Gateway for S.NET Manager
 * Production-Grade Stability Edition v3 — Full WhatsApp Web Client + Store + Auto-Repair
 */

const express = require('express');
const cors = require('cors');
const qrcode = require('qrcode');
const pino = require('pino');
const fs = require('fs');
const path = require('path');
const Baileys = require('@whiskeysockets/baileys');
const {
    default: makeWASocket,
    useMultiFileAuthState,
    DisconnectReason,
    fetchLatestBaileysVersion,
    makeCacheableSignalKeyStore,
    makeInMemoryStore,
    Browsers,
    proto
} = Baileys;

// ── Global Process Exception Handlers (Anti-Crash) ──
process.on('uncaughtException', (err) => {
    console.error('>>> [WA-GATEWAY] [UNCAUGHT EXCEPTION]:', err?.message || err);
    if (err?.stack) console.error(err.stack);
});

process.on('unhandledRejection', (reason, promise) => {
    console.error('>>> [WA-GATEWAY] [UNHANDLED REJECTION]:', reason?.message || reason);
});

const app = express();
const PORT = process.env.PORT || 3000;
const AUTH_DIR = path.join(__dirname, 'auth_info_baileys');
const CONFIG_FILE = path.join(__dirname, 'gateway_config.json');
const STORE_FILE = path.join(__dirname, 'baileys_store.json');
const CHAT_FILE = path.join(__dirname, 'chat_history.json');
const PERSISTENT_MSG_FILE = path.join(__dirname, 'messages_cache.json');

app.use(cors());
app.use(express.json({ limit: '25mb' }));
app.use(express.urlencoded({ extended: true, limit: '25mb' }));

// Global State
let sock = null;
let currentQR = null;
let qrDataUrl = null;
let connectionStatus = 'disconnected'; // 'disconnected' | 'connecting' | 'scan_qr' | 'connected'
let connectedUser = null;
let reconnectAttempts = 0;
let isInitializing = false;
let lastConnectedAt = null;
let totalMessagesSent = 0;
let totalMessagesReceived = 0;

const logger = pino({ level: 'silent' });

// ── Baileys In-Memory Store with Disk Persistence (Solusi Utama "Menunggu pesan ini...") ──
let store = null;
try {
    if (typeof makeInMemoryStore === 'function') {
        store = makeInMemoryStore({ logger });
        if (fs.existsSync(STORE_FILE)) {
            try {
                store.readFromFile(STORE_FILE);
                console.log('>>> [WA-GATEWAY] Baileys store dimuat dari:', STORE_FILE);
            } catch (e) {
                console.warn('>>> [WA-GATEWAY] Gagal load STORE_FILE:', e.message);
            }
        }
        // Auto-save store ke disk setiap 10 detik
        setInterval(() => {
            try {
                if (store) store.writeToFile(STORE_FILE);
            } catch (e) {}
        }, 10000);
    }
} catch (e) {
    console.warn('>>> [WA-GATEWAY] makeInMemoryStore init fallback:', e.message);
}

// ── Persistent Message & Retry Cache ──
const messageStore = loadPersistentMessages();
const retryCountMap = new Map();
const retryCache = {
    get: (key) => retryCountMap.get(key),
    set: (key, val) => { retryCountMap.set(key, val); },
    del: (key) => { retryCountMap.delete(key); }
};

function loadPersistentMessages() {
    try {
        if (fs.existsSync(PERSISTENT_MSG_FILE)) {
            const raw = fs.readFileSync(PERSISTENT_MSG_FILE, 'utf8');
            const arr = JSON.parse(raw);
            return new Map(arr);
        }
    } catch (e) {}
    return new Map();
}

function savePersistentMessages() {
    try {
        const arr = Array.from(messageStore.entries()).slice(-1000);
        fs.writeFileSync(PERSISTENT_MSG_FILE, JSON.stringify(arr));
    } catch (e) {}
}

// ── Persistent WhatsApp Web Chat Conversations ──
let conversations = loadChatHistory();

function loadChatHistory() {
    try {
        if (fs.existsSync(CHAT_FILE)) {
            const raw = fs.readFileSync(CHAT_FILE, 'utf8');
            return JSON.parse(raw);
        }
    } catch (e) {
        console.warn('>>> [WA-GATEWAY] Gagal load chat_history:', e.message);
    }
    return {};
}

function saveChatHistory() {
    try {
        fs.writeFileSync(CHAT_FILE, JSON.stringify(conversations, null, 2));
    } catch (e) {
        console.warn('>>> [WA-GATEWAY] Gagal simpan chat_history:', e.message);
    }
}

function recordIncomingMessage(msgData) {
    const phone = cleanPhoneNumber(msgData.sender_phone);
    if (!phone) return;

    if (!conversations[phone]) {
        conversations[phone] = {
            phone: phone,
            name: msgData.sender_name || ('+' + phone),
            unread_count: 0,
            last_message: msgData.text || `[${msgData.message_type}]`,
            last_time: msgData.timestamp,
            messages: []
        };
    }
    const conv = conversations[phone];
    if (msgData.sender_name && msgData.sender_name !== ('+' + phone)) {
        conv.name = msgData.sender_name;
    }
    conv.unread_count = (conv.unread_count || 0) + 1;
    conv.last_message = msgData.text || `[${msgData.message_type}]`;
    conv.last_time = msgData.timestamp;

    if (!conv.messages.some(m => m.id === msgData.message_id)) {
        conv.messages.push({
            id: msgData.message_id || ('in_' + Date.now()),
            direction: 'in',
            text: msgData.text,
            type: msgData.message_type,
            timestamp: msgData.timestamp,
            status: 'delivered'
        });
        if (conv.messages.length > 250) {
            conv.messages = conv.messages.slice(-250);
        }
    }
    saveChatHistory();
}

function recordOutgoingMessage(phone, text, messageId, type = 'text') {
    const rawPhone = cleanPhoneNumber(phone);
    if (!rawPhone) return;

    if (!conversations[rawPhone]) {
        conversations[rawPhone] = {
            phone: rawPhone,
            name: '+' + rawPhone,
            unread_count: 0,
            last_message: text,
            last_time: new Date().toISOString(),
            messages: []
        };
    }
    const conv = conversations[rawPhone];
    conv.last_message = text;
    conv.last_time = new Date().toISOString();
    conv.messages.push({
        id: messageId || ('out_' + Date.now()),
        direction: 'out',
        text: text,
        type: type,
        timestamp: new Date().toISOString(),
        status: 'sent'
    });
    if (conv.messages.length > 250) {
        conv.messages = conv.messages.slice(-250);
    }
    saveChatHistory();
}

// Incoming messages store (circular buffer — max 500 messages)
const inboxMessages = [];
const MAX_INBOX = 500;

// Gateway config (forward group, webhook URL, etc.)
let gatewayConfig = loadGatewayConfig();

function loadGatewayConfig() {
    try {
        if (fs.existsSync(CONFIG_FILE)) {
            const raw = fs.readFileSync(CONFIG_FILE, 'utf8');
            return JSON.parse(raw);
        }
    } catch (e) {
        console.warn('>>> [WA-GATEWAY] Gagal membaca config file:', e.message);
    }
    return {
        forward_group_jid: '',
        forward_enabled: false,
        webhook_url: 'http://127.0.0.1/ajax/wa_inbox_webhook.php',
        webhook_enabled: true
    };
}

function saveGatewayConfig(cfg) {
    try {
        gatewayConfig = { ...gatewayConfig, ...cfg };
        fs.writeFileSync(CONFIG_FILE, JSON.stringify(gatewayConfig, null, 2));
    } catch (e) {
        console.error('>>> [WA-GATEWAY] Gagal menyimpan config:', e.message);
    }
}

/**
 * Sequential Message Queue with Anti-Spam Jitter
 */
class MessageQueue {
    constructor(delayMs = 1200) {
        this.queue = [];
        this.processing = false;
        this.delayMs = delayMs;
    }

    enqueue(task) {
        return new Promise((resolve, reject) => {
            this.queue.push({ task, resolve, reject });
            this.process();
        });
    }

    async process() {
        if (this.processing) return;
        this.processing = true;

        while (this.queue.length > 0) {
            const item = this.queue.shift();
            try {
                const res = await item.task();
                item.resolve(res);
            } catch (err) {
                item.reject(err);
            }

            if (this.queue.length > 0) {
                const jitter = Math.floor(Math.random() * 800);
                await new Promise((r) => setTimeout(r, this.delayMs + jitter));
            }
        }

        this.processing = false;
    }

    size() {
        return this.queue.length;
    }
}

const sendQueue = new MessageQueue(1200);

/**
 * Validasi folder autentikasi (HANYA validasi creds.json, JANGAN hapus pre-keys acak)
 */
function verifyAndRepairAuthDir() {
    try {
        if (!fs.existsSync(AUTH_DIR)) {
            fs.mkdirSync(AUTH_DIR, { recursive: true });
            return;
        }

        const credsPath = path.join(AUTH_DIR, 'creds.json');
        if (fs.existsSync(credsPath)) {
            const raw = fs.readFileSync(credsPath, 'utf8');
            if (!raw || raw.trim().length === 0) {
                console.warn('>>> [WA-GATEWAY] creds.json kosong, mereset file...');
                fs.unlinkSync(credsPath);
            } else {
                try {
                    JSON.parse(raw);
                } catch (pe) {
                    console.error('>>> [WA-GATEWAY] creds.json invalid. Mencadangkan file...', pe.message);
                    fs.renameSync(credsPath, path.join(AUTH_DIR, 'creds.json.corrupt_' + Date.now()));
                }
            }
        }
    } catch (e) {
        console.error('>>> [WA-GATEWAY] Error verifyAndRepairAuthDir:', e);
    }
}

/**
 * Hentikan socket lama dan lepaskan semua event listener secara bersih
 */
function destroySocket() {
    if (sock) {
        try {
            sock.ev.removeAllListeners();
            if (sock.ws && typeof sock.ws.close === 'function') {
                sock.ws.close();
            }
            if (typeof sock.end === 'function') {
                sock.end(undefined);
            }
        } catch (e) {
            // Ignore socket cleanup error
        }
        sock = null;
    }
}

/**
 * Extract text content from any message type
 */
function extractMessageText(msg) {
    if (!msg?.message) return '';
    const m = msg.message;
    return m.conversation
        || m.extendedTextMessage?.text
        || m.imageMessage?.caption
        || m.videoMessage?.caption
        || m.documentMessage?.caption
        || m.buttonsResponseMessage?.selectedDisplayText
        || m.listResponseMessage?.title
        || m.templateButtonReplyMessage?.selectedDisplayText
        || '';
}

/**
 * Get sender display name from message
 */
function getSenderName(msg) {
    return msg.pushName || msg.verifiedBizName || '';
}

/**
 * Get sender phone number from message
 */
function getSenderPhone(msg) {
    const jid = msg.key?.remoteJid || '';
    if (jid.endsWith('@g.us')) {
        return (msg.key?.participant || '').split('@')[0];
    }
    return jid.split('@')[0];
}

/**
 * Forward message to technician group
 */
async function forwardToGroup(msg) {
    if (!gatewayConfig.forward_enabled || !gatewayConfig.forward_group_jid) return;
    if (!sock || connectionStatus !== 'connected') return;

    try {
        const senderPhone = getSenderPhone(msg);
        const senderName = getSenderName(msg);
        const text = extractMessageText(msg);
        const isFromGroup = (msg.key?.remoteJid || '').endsWith('@g.us');

        if (isFromGroup) return;
        if (msg.key?.fromMe) return;

        const timestamp = new Date().toLocaleString('id-ID', { timeZone: 'Asia/Jayapura' });

        let forwardText = `📩 *Pesan Masuk dari Pelanggan*\n`;
        forwardText += `━━━━━━━━━━━━━━━━━━\n`;
        forwardText += `👤 *Nama:* ${senderName || 'Tidak Diketahui'}\n`;
        forwardText += `📱 *Nomor:* +${senderPhone}\n`;
        forwardText += `🕐 *Waktu:* ${timestamp}\n`;
        forwardText += `━━━━━━━━━━━━━━━━━━\n`;

        if (text) {
            forwardText += `💬 *Pesan:*\n${text}\n`;
        }

        const m = msg.message;
        if (m?.imageMessage) forwardText += `📷 [Mengirim Gambar]\n`;
        else if (m?.videoMessage) forwardText += `🎥 [Mengirim Video]\n`;
        else if (m?.audioMessage) forwardText += `🎵 [Mengirim Audio/Voice Note]\n`;
        else if (m?.documentMessage) forwardText += `📄 [Mengirim Dokumen: ${m.documentMessage.fileName || 'file'}]\n`;
        else if (m?.stickerMessage) forwardText += `🎭 [Mengirim Sticker]\n`;
        else if (m?.locationMessage) forwardText += `📍 [Mengirim Lokasi]\n`;
        else if (m?.contactMessage || m?.contactsArrayMessage) forwardText += `👥 [Mengirim Kontak]\n`;

        if (!text && !m?.imageMessage && !m?.videoMessage && !m?.audioMessage && !m?.documentMessage) {
            forwardText += `💬 [Pesan media / sticker]\n`;
        }

        forwardText += `━━━━━━━━━━━━━━━━━━\n`;
        forwardText += `_Balas langsung ke nomor pelanggan._`;

        await sock.sendMessage(gatewayConfig.forward_group_jid, { text: forwardText });
        console.log(`>>> [WA-GATEWAY] ✅ Pesan dari +${senderPhone} diteruskan ke grup teknisi.`);
    } catch (err) {
        console.error('>>> [WA-GATEWAY] Gagal forward ke grup:', err?.message || err);
    }
}

/**
 * Send incoming message to PHP webhook
 */
async function notifyWebhook(msgData) {
    if (!gatewayConfig.webhook_enabled) return;
    const webhookUrl = gatewayConfig.webhook_url || 'http://127.0.0.1/ajax/wa_inbox_webhook.php';

    try {
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 6000);

        await fetch(webhookUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Host': 'dash.snetwifi.com'
            },
            body: JSON.stringify(msgData),
            signal: controller.signal
        });

        clearTimeout(timeout);
    } catch (err) {
        console.warn('>>> [WA-GATEWAY] Webhook error (non-fatal):', err?.message);
    }
}

/**
 * Inisialisasi WhatsApp Socket (Baileys Engine)
 */
async function initWhatsApp() {
    if (isInitializing) {
        console.log('>>> [WA-GATEWAY] Inisialisasi sedang berlangsung, lewati panggilan ganda.');
        return;
    }
    isInitializing = true;

    try {
        destroySocket();
        verifyAndRepairAuthDir();

        const { state, saveCreds } = await useMultiFileAuthState(AUTH_DIR);
        
        let waVersion = undefined;
        try {
            const vRes = await fetchLatestBaileysVersion();
            if (vRes?.version) {
                waVersion = vRes.version;
                console.log('>>> [WA-GATEWAY] Menggunakan Baileys Version (Live):', waVersion.join('.'));
            }
        } catch (ve) {
            console.log('>>> [WA-GATEWAY] Menggunakan versi internal Baileys bawaan library.');
        }

        const socketConfig = {
            logger,
            printQRInTerminal: false,
            auth: {
                creds: state.creds,
                keys: makeCacheableSignalKeyStore(state.keys, logger),
            },
            msgRetryCounterCache: retryCache,
            getMessage: async (key) => {
                // 1. Cek dari makeInMemoryStore resmi Baileys
                try {
                    if (store) {
                        const msg = await store.loadMessage(key.remoteJid, key.id);
                        if (msg?.message) return msg.message;
                    }
                } catch (e) {}

                // 2. Cek dari cache internal Map
                if (key?.id && messageStore.has(key.id)) {
                    return messageStore.get(key.id);
                }
                const cacheKey = `${key?.remoteJid}:${key?.id}`;
                if (messageStore.has(cacheKey)) {
                    return messageStore.get(cacheKey);
                }

                // 3. Cek dari percakapan WhatsApp Web
                if (key?.remoteJid && key?.id) {
                    const phone = cleanPhoneNumber(key.remoteJid.split('@')[0]);
                    if (conversations[phone]?.messages) {
                        const found = conversations[phone].messages.find(m => m.id === key.id);
                        if (found?.text) {
                            return { conversation: found.text };
                        }
                    }
                }

                // 4. Fallback proto.Message kosong (Mencegah loop 'Menunggu pesan ini' pada WhatsApp Client)
                return proto.Message.fromObject({});
            },
            browser: Browsers.macOS('Desktop'),
            connectTimeoutMs: 60000,
            defaultQueryTimeoutMs: 60000,
            keepAliveIntervalMs: 25000,
            retryRequestDelayMs: 250,
            emitOwnEvents: false,
            syncFullHistory: true,
            markOnlineOnConnect: true,
            generateHighQualityLinkPreview: false,
            patchMessageBeforeSending: (message) => {
                const requiresPatch = !!(
                    message.buttonsMessage ||
                    message.templateMessage ||
                    message.listMessage
                );
                if (requiresPatch) {
                    message = {
                        viewOnceMessage: {
                            message: {
                                messageContextInfo: {
                                    deviceListMetadataVersion: 2,
                                    deviceListMetadata: {},
                                },
                                ...message,
                            },
                        },
                    };
                }
                return message;
            }
        };

        if (waVersion) {
            socketConfig.version = waVersion;
        }

        sock = makeWASocket(socketConfig);

        // Bind Store ke event socket Baileys
        if (store) {
            try {
                store.bind(sock.ev);
            } catch (e) {
                console.warn('>>> [WA-GATEWAY] store.bind warning:', e.message);
            }
        }

        sock.ev.on('creds.update', saveCreds);

        // ── INCOMING MESSAGE HANDLER ──
        sock.ev.on('messages.upsert', async (m) => {
            if (!m.messages || !Array.isArray(m.messages)) return;

            for (const msg of m.messages) {
                // Simpan ke messageStore untuk mekanisme retry WhatsApp (anti "Menunggu pesan ini...")
                if (msg.key?.id && msg.message) {
                    messageStore.set(msg.key.id, msg.message);
                    if (msg.key.remoteJid) {
                        messageStore.set(`${msg.key.remoteJid}:${msg.key.id}`, msg.message);
                    }
                    if (messageStore.size > 2000) {
                        const oldest = messageStore.keys().next().value;
                        messageStore.delete(oldest);
                    }
                    savePersistentMessages();
                }

                // Abaikan broadcast status
                if (msg.key?.remoteJid === 'status@broadcast') continue;

                // Process pesan masuk
                const senderPhone = getSenderPhone(msg);
                const senderName = getSenderName(msg);
                const text = extractMessageText(msg);
                const isGroup = (msg.key?.remoteJid || '').endsWith('@g.us');
                const timestamp = msg.messageTimestamp
                    ? new Date(Number(msg.messageTimestamp) * 1000).toISOString()
                    : new Date().toISOString();

                let msgType = 'text';
                const mContent = msg.message;
                if (mContent?.imageMessage) msgType = 'image';
                else if (mContent?.videoMessage) msgType = 'video';
                else if (mContent?.audioMessage) msgType = 'audio';
                else if (mContent?.documentMessage) msgType = 'document';
                else if (mContent?.stickerMessage) msgType = 'sticker';
                else if (mContent?.locationMessage) msgType = 'location';
                else if (mContent?.contactMessage || mContent?.contactsArrayMessage) msgType = 'contact';

                const msgData = {
                    message_id: msg.key?.id || '',
                    sender_phone: senderPhone,
                    sender_name: senderName,
                    text: text,
                    message_type: msgType,
                    is_group: isGroup,
                    group_jid: isGroup ? msg.key?.remoteJid : '',
                    group_name: '',
                    timestamp: timestamp,
                    raw_jid: msg.key?.remoteJid || '',
                    from_me: !!msg.key?.fromMe
                };

                // Jika pesan dari kita sendiri yang dikirim dari HP lain, rekam sebagai pesan keluar
                if (msg.key?.fromMe) {
                    const recipientPhone = cleanPhoneNumber(msg.key?.remoteJid || '');
                    if (recipientPhone && !isGroup) {
                        recordOutgoingMessage(recipientPhone, text || `[${msgType}]`, msg.key?.id, msgType);
                    }
                    continue;
                }

                // Push ke inbox buffer
                inboxMessages.unshift(msgData);
                if (inboxMessages.length > MAX_INBOX) {
                    inboxMessages.pop();
                }

                totalMessagesReceived++;

                console.log(`>>> [WA-GATEWAY] 📩 Pesan masuk dari +${senderPhone} (${senderName}): ${text.substring(0, 80) || '[media]'}`);

                // Rekam ke percakapan WhatsApp Web
                if (!isGroup) {
                    recordIncomingMessage(msgData);
                }

                // Forward ke grup teknisi
                if (!isGroup) {
                    forwardToGroup(msg).catch(() => {});
                }

                // Kirim ke PHP Webhook
                notifyWebhook(msgData).catch(() => {});
            }
        });

        sock.ev.on('messages.update', (updates) => {
            for (const u of updates) {
                const s = u.update?.status;
                const sMap = { 0: 'ERROR', 1: 'PENDING', 2: 'SERVER_ACK (Terkirim)', 3: 'DELIVERED (Diterima)', 4: 'READ (Dibaca)', 5: 'PLAYED' };
                if (s !== undefined) {
                    console.log(`>>> [WA-GATEWAY] Status Pesan ID [${u.key?.id}]: ${sMap[s] || s}`);
                }
            }
        });

        sock.ev.on('connection.update', async (update) => {
            const { connection, lastDisconnect, qr } = update;

            if (qr) {
                currentQR = qr;
                connectionStatus = 'scan_qr';
                try {
                    qrDataUrl = await qrcode.toDataURL(qr, { margin: 2, scale: 7 });
                } catch (e) {
                    console.error('Error generating QR data URL:', e);
                }
                console.log('>>> [WA-GATEWAY] QR Code siap di-scan di panel web.');
            }

            if (connection === 'close') {
                const statusCode = lastDisconnect?.error?.output?.statusCode;
                console.log(`>>> [WA-GATEWAY] Koneksi terputus (Status Code: ${statusCode}).`);

                connectionStatus = 'disconnected';
                connectedUser = null;
                currentQR = null;
                qrDataUrl = null;

                destroySocket();

                const isLoggedOut = statusCode === DisconnectReason.loggedOut;
                const isBadSession = statusCode === 401 || statusCode === 403 || statusCode === 411 || statusCode === 440;

                if (isLoggedOut || isBadSession) {
                    console.log(`>>> [WA-GATEWAY] Sesi bermasalah (${statusCode}). Membersihkan auth folder...`);
                    cleanAuthDir();
                    reconnectAttempts = 0;
                    setTimeout(() => {
                        initWhatsApp();
                    }, 2500);
                } else {
                    reconnectAttempts++;
                    const delayMs = Math.min(reconnectAttempts * 2000, 30000);
                    console.log(`>>> [WA-GATEWAY] Reconnecting dalam ${delayMs / 1000}s (Percobaan #${reconnectAttempts})...`);
                    setTimeout(() => {
                        initWhatsApp();
                    }, delayMs);
                }
            } else if (connection === 'open') {
                reconnectAttempts = 0;
                connectionStatus = 'connected';
                currentQR = null;
                qrDataUrl = null;
                lastConnectedAt = new Date().toISOString();

                const user = sock.user;
                connectedUser = {
                    id: user?.id?.split(':')[0] || user?.id || '',
                    name: user?.name || user?.notify || 'S.NET Admin'
                };
                console.log(`>>> [WA-GATEWAY] BERHASIL TERHUBUNG! Nomor: +${connectedUser.id} (${connectedUser.name})`);

                fetchGroupList().catch(() => {});
            } else if (connection === 'connecting') {
                connectionStatus = 'connecting';
            }
        });

    } catch (err) {
        console.error('>>> [WA-GATEWAY] Error inisialisasi Baileys:', err?.message || err);
        connectionStatus = 'disconnected';
        setTimeout(() => {
            initWhatsApp();
        }, 5000);
    } finally {
        isInitializing = false;
    }
}

let cachedGroups = [];

async function fetchGroupList() {
    try {
        if (!sock || connectionStatus !== 'connected') return;
        const groups = await sock.groupFetchAllParticipating();
        cachedGroups = Object.values(groups).map(g => ({
            jid: g.id,
            subject: g.subject || '(Tanpa Nama)',
            participants: g.participants?.length || 0,
            creation: g.creation || 0
        }));
        console.log(`>>> [WA-GATEWAY] Ditemukan ${cachedGroups.length} grup WhatsApp.`);
    } catch (e) {
        console.warn('>>> [WA-GATEWAY] Gagal fetch daftar grup:', e?.message);
    }
}

function cleanAuthDir() {
    try {
        if (fs.existsSync(AUTH_DIR)) {
            fs.rmSync(AUTH_DIR, { recursive: true, force: true });
        }
        if (fs.existsSync(STORE_FILE)) {
            try { fs.unlinkSync(STORE_FILE); } catch (_) {}
        }
        if (fs.existsSync(PERSISTENT_MSG_FILE)) {
            try { fs.unlinkSync(PERSISTENT_MSG_FILE); } catch (_) {}
        }
        fs.mkdirSync(AUTH_DIR, { recursive: true });
    } catch (e) {
        console.error('Error removing auth directory:', e);
    }
}

function formatJid(phone) {
    let clean = cleanPhoneNumber(phone);
    return clean + '@s.whatsapp.net';
}

function cleanPhoneNumber(phone) {
    let clean = String(phone || '').replace(/\D/g, '');
    if (clean.startsWith('08')) {
        clean = '62' + clean.slice(1);
    } else if (clean.startsWith('8')) {
        clean = '62' + clean;
    }
    return clean;
}

// ── REST API ENDPOINTS ──

/**
 * GET /api/status - Cek status koneksi WhatsApp
 */
app.get('/api/status', (req, res) => {
    res.json({
        status: connectionStatus,
        user: connectedUser,
        has_qr: !!qrDataUrl,
        queue_size: sendQueue.size(),
        reconnect_attempts: reconnectAttempts,
        last_connected_at: lastConnectedAt,
        total_sent: totalMessagesSent,
        total_received: totalMessagesReceived,
        inbox_count: inboxMessages.length,
        chats_count: Object.keys(conversations).length,
        forward_enabled: gatewayConfig.forward_enabled,
        forward_group_jid: gatewayConfig.forward_group_jid,
        timestamp: new Date().toISOString()
    });
});

/**
 * GET /api/qr - Ambil gambar QR Code
 */
app.get('/api/qr', (req, res) => {
    if (connectionStatus === 'connected') {
        return res.json({
            status: 'connected',
            message: 'WhatsApp sudah terhubung',
            user: connectedUser
        });
    }

    if (!qrDataUrl) {
        if (connectionStatus === 'disconnected' && !isInitializing) {
            initWhatsApp();
        }
        return res.json({
            status: connectionStatus,
            message: 'QR Code sedang dibuat, silakan muat ulang dalam 2-3 detik...',
            qr: null
        });
    }

    res.json({
        status: 'scan_qr',
        qr: qrDataUrl,
        raw_qr: currentQR
    });
});

/**
 * POST /api/pairing-code - Tautkan WhatsApp via Nomor HP
 */
app.post('/api/pairing-code', async (req, res) => {
    try {
        const { phone } = req.body;
        if (!phone) {
            return res.status(400).json({ success: false, message: 'Nomor WhatsApp wajib diisi.' });
        }

        if (connectionStatus === 'connected') {
            return res.json({
                success: true,
                status: 'connected',
                message: 'WhatsApp sudah terhubung dengan nomor: +' + (connectedUser?.id || '')
            });
        }

        if (!sock) {
            await initWhatsApp();
            await new Promise(r => setTimeout(r, 1500));
        }

        const rawPhone = cleanPhoneNumber(phone);
        if (rawPhone.length < 10) {
            return res.status(400).json({ success: false, message: 'Format nomor telepon tidak valid.' });
        }

        console.log(`>>> [WA-GATEWAY] Meminta Pairing Code untuk nomor: ${rawPhone}...`);
        const code = await sock.requestPairingCode(rawPhone);
        const formattedCode = code ? (code.length === 8 ? `${code.slice(0, 4)}-${code.slice(4)}` : code) : code;

        res.json({
            success: true,
            code: formattedCode,
            raw_code: code,
            phone: rawPhone,
            message: 'Kode pairing berhasil dibuat. Masukkan kode ini di WhatsApp HP Anda.'
        });
    } catch (err) {
        console.error('Error requesting pairing code:', err);
        res.status(500).json({
            success: false,
            message: 'Gagal membuat kode pairing: ' + (err.message || String(err))
        });
    }
});

/**
 * POST /api/send - Kirim pesan teks atau gambar
 */
app.post('/api/send', async (req, res) => {
    const { phone, message, image_url, image_base64 } = req.body;

    if (!phone || (!message && !image_url && !image_base64)) {
        return res.status(400).json({
            success: false,
            message: 'Nomor telepon dan pesan/gambar wajib diisi.'
        });
    }

    if (connectionStatus !== 'connected' || !sock) {
        return res.status(503).json({
            success: false,
            message: 'WhatsApp belum terhubung atau sedang menghubungkan ulang. Silakan periksa status gateway.'
        });
    }

    try {
        const result = await sendQueue.enqueue(async () => {
            if (connectionStatus !== 'connected' || !sock) {
                throw new Error('Koneksi WhatsApp terputus saat giliran pengiriman antrean.');
            }

            const rawPhone = cleanPhoneNumber(phone);
            let targetJid = formatJid(phone);

            try {
                const onWaResults = await sock.onWhatsApp(rawPhone);
                if (Array.isArray(onWaResults) && onWaResults.length > 0 && onWaResults[0].exists) {
                    targetJid = onWaResults[0].jid;
                }
            } catch (checkErr) {}

            console.log(`>>> [WA-GATEWAY] Mengirim pesan ke ${targetJid}...`);
            let sentMessage = null;

            if (image_url) {
                sentMessage = await sock.sendMessage(targetJid, {
                    image: { url: image_url },
                    caption: message || ''
                });
            } else if (image_base64) {
                const cleanBase64 = image_base64.replace(/^data:image\/\w+;base64,/, '');
                const buffer = Buffer.from(cleanBase64, 'base64');
                sentMessage = await sock.sendMessage(targetJid, {
                    image: buffer,
                    caption: message || ''
                });
            } else {
                sentMessage = await sock.sendMessage(targetJid, { text: message });
            }

            console.log(`>>> [WA-GATEWAY] Sukses terkirim! Msg ID: ${sentMessage?.key?.id}`);

            if (sentMessage?.key?.id && sentMessage?.message) {
                messageStore.set(sentMessage.key.id, sentMessage.message);
                messageStore.set(`${targetJid}:${sentMessage.key.id}`, sentMessage.message);
                savePersistentMessages();
            }

            totalMessagesSent++;
            recordOutgoingMessage(rawPhone, message || '[Gambar]', sentMessage?.key?.id, image_url ? 'image' : 'text');
            return sentMessage;
        });

        res.json({
            success: true,
            message: 'Pesan berhasil dikirim ke WhatsApp',
            message_id: result?.key?.id || null,
            remaining_queue: sendQueue.size()
        });
    } catch (err) {
        console.error('Error sending message:', err?.message || err);
        res.status(500).json({
            success: false,
            message: 'Gagal mengirim pesan: ' + (err?.message || String(err))
        });
    }
});

/**
 * GET /api/messages - Pesan masuk raw buffer
 */
app.get('/api/messages', (req, res) => {
    const limit = Math.min(parseInt(req.query.limit) || 50, 200);
    const offset = parseInt(req.query.offset) || 0;
    const filterPhone = req.query.phone || '';

    let filtered = inboxMessages;
    if (filterPhone) {
        filtered = inboxMessages.filter(m => m.sender_phone.includes(filterPhone));
    }

    const sliced = filtered.slice(offset, offset + limit);

    res.json({
        success: true,
        total: filtered.length,
        offset: offset,
        limit: limit,
        messages: sliced
    });
});

// ── WHATSAPP WEB CONVERSATION API (LIVE CHAT) ──

/**
 * GET /api/chats - Ambil daftar percakapan aktif (WhatsApp Web style)
 */
app.get('/api/chats', (req, res) => {
    const list = Object.values(conversations).map(c => ({
        phone: c.phone,
        name: c.name || ('+' + c.phone),
        unread_count: c.unread_count || 0,
        last_message: c.last_message || '',
        last_time: c.last_time || new Date().toISOString(),
        total_messages: c.messages ? c.messages.length : 0
    })).sort((a, b) => new Date(b.last_time || 0) - new Date(a.last_time || 0));

    res.json({
        success: true,
        status: connectionStatus,
        user: connectedUser,
        chats: list
    });
});

/**
 * GET /api/chat/:phone - Ambil riwayat percakapan dengan nomor tertentu
 */
app.get('/api/chat/:phone', (req, res) => {
    const rawPhone = cleanPhoneNumber(req.params.phone);
    const conv = conversations[rawPhone] || {
        phone: rawPhone,
        name: '+' + rawPhone,
        unread_count: 0,
        last_message: '',
        last_time: new Date().toISOString(),
        messages: []
    };

    res.json({
        success: true,
        phone: rawPhone,
        name: conv.name,
        unread_count: conv.unread_count || 0,
        messages: conv.messages || []
    });
});

/**
 * POST /api/chat/read - Tandai percakapan sebagai telah dibaca
 */
app.post('/api/chat/read', (req, res) => {
    const rawPhone = cleanPhoneNumber(req.body.phone || '');
    if (conversations[rawPhone]) {
        conversations[rawPhone].unread_count = 0;
        saveChatHistory();
    }
    res.json({ success: true });
});

/**
 * POST /api/chat/send - Kirim pesan langsung dari antarmuka WhatsApp Web
 */
app.post('/api/chat/send', async (req, res) => {
    const { phone, message } = req.body;
    if (!phone || !message) {
        return res.status(400).json({ success: false, message: 'Nomor dan pesan wajib diisi.' });
    }

    if (connectionStatus !== 'connected' || !sock) {
        return res.status(503).json({ success: false, message: 'WhatsApp belum terhubung.' });
    }

    try {
        const rawPhone = cleanPhoneNumber(phone);
        const result = await sendQueue.enqueue(async () => {
            const targetJid = formatJid(phone);
            const sentMessage = await sock.sendMessage(targetJid, { text: message });

            if (sentMessage?.key?.id && sentMessage?.message) {
                messageStore.set(sentMessage.key.id, sentMessage.message);
                messageStore.set(`${targetJid}:${sentMessage.key.id}`, sentMessage.message);
                savePersistentMessages();
            }

            totalMessagesSent++;
            recordOutgoingMessage(rawPhone, message, sentMessage?.key?.id, 'text');
            return sentMessage;
        });

        res.json({
            success: true,
            message: 'Pesan berhasil dikirim.',
            message_id: result?.key?.id || null,
            data: {
                id: result?.key?.id || ('out_' + Date.now()),
                direction: 'out',
                text: message,
                type: 'text',
                timestamp: new Date().toISOString(),
                status: 'sent'
            }
        });
    } catch (err) {
        res.status(500).json({ success: false, message: 'Gagal mengirim: ' + (err?.message || String(err)) });
    }
});

/**
 * POST /api/reply - Balas pesan (kompatibilitas modal lama)
 */
app.post('/api/reply', async (req, res) => {
    const { phone, message } = req.body;

    if (!phone || !message) {
        return res.status(400).json({ success: false, message: 'Nomor dan pesan wajib diisi.' });
    }

    if (connectionStatus !== 'connected' || !sock) {
        return res.status(503).json({ success: false, message: 'WhatsApp belum terhubung.' });
    }

    try {
        const rawPhone = cleanPhoneNumber(phone);
        const result = await sendQueue.enqueue(async () => {
            const targetJid = formatJid(phone);
            const sentMessage = await sock.sendMessage(targetJid, { text: message });

            if (sentMessage?.key?.id && sentMessage?.message) {
                messageStore.set(sentMessage.key.id, sentMessage.message);
                messageStore.set(`${targetJid}:${sentMessage.key.id}`, sentMessage.message);
                savePersistentMessages();
            }

            totalMessagesSent++;
            recordOutgoingMessage(rawPhone, message, sentMessage?.key?.id, 'text');
            return sentMessage;
        });

        res.json({
            success: true,
            message: 'Balasan berhasil dikirim.',
            message_id: result?.key?.id || null
        });
    } catch (err) {
        res.status(500).json({
            success: false,
            message: 'Gagal mengirim balasan: ' + (err?.message || String(err))
        });
    }
});

/**
 * GET /api/groups - Daftar Grup WhatsApp
 */
app.get('/api/groups', async (req, res) => {
    if (connectionStatus !== 'connected' || !sock) {
        return res.json({ success: false, groups: [], message: 'WhatsApp belum terhubung.' });
    }

    await fetchGroupList().catch(() => {});

    res.json({
        success: true,
        groups: cachedGroups,
        current_forward_jid: gatewayConfig.forward_group_jid
    });
});

/**
 * POST /api/config - Simpan konfigurasi gateway
 */
app.post('/api/config', (req, res) => {
    const { forward_group_jid, forward_enabled, webhook_url, webhook_enabled } = req.body;

    const updates = {};
    if (forward_group_jid !== undefined) updates.forward_group_jid = String(forward_group_jid);
    if (forward_enabled !== undefined) updates.forward_enabled = !!forward_enabled;
    if (webhook_url !== undefined) updates.webhook_url = String(webhook_url);
    if (webhook_enabled !== undefined) updates.webhook_enabled = !!webhook_enabled;

    saveGatewayConfig(updates);

    res.json({
        success: true,
        message: 'Konfigurasi gateway berhasil disimpan.',
        config: gatewayConfig
    });
});

/**
 * GET /api/config - Ambil konfigurasi gateway
 */
app.get('/api/config', (req, res) => {
    res.json({
        success: true,
        config: gatewayConfig
    });
});

/**
 * POST /api/logout - Putus koneksi dan bersihkan sesi untuk scan ulang
 */
app.post('/api/logout', async (req, res) => {
    try {
        if (sock) {
            await sock.logout().catch(() => {});
        }
        destroySocket();
        cleanAuthDir();
        connectionStatus = 'disconnected';
        connectedUser = null;
        currentQR = null;
        qrDataUrl = null;

        setTimeout(initWhatsApp, 1500);

        res.json({
            success: true,
            message: 'Koneksi WhatsApp berhasil diputus dan sesi dibersihkan. Silakan tautkan ulang.'
        });
    } catch (err) {
        res.status(500).json({
            success: false,
            message: 'Gagal logout: ' + (err?.message || String(err))
        });
    }
});

/**
 * POST /api/restart - Restart engine socket tanpa menghapus sesi
 */
app.post('/api/restart', (req, res) => {
    try {
        destroySocket();
        connectionStatus = 'connecting';
        setTimeout(initWhatsApp, 1000);
        res.json({ success: true, message: 'Engine WhatsApp sedang direstart...' });
    } catch (err) {
        res.status(500).json({ success: false, message: err?.message || String(err) });
    }
});

/**
 * POST /api/reset - Reset bersih sesi dan cache (Solusi tuntas desync "Menunggu pesan ini...")
 */
app.post('/api/reset', (req, res) => {
    try {
        destroySocket();
        cleanAuthDir();
        connectionStatus = 'disconnected';
        connectedUser = null;
        currentQR = null;
        qrDataUrl = null;
        setTimeout(initWhatsApp, 1000);
        res.json({ success: true, message: 'Sesi WhatsApp berhasil direset bersih. Silakan tautkan ulang dengan scan QR baru.' });
    } catch (err) {
        res.status(500).json({ success: false, message: err?.message || String(err) });
    }
});

// Start server
app.listen(PORT, '127.0.0.1', () => {
    console.log(`====================================================`);
    console.log(`  S.NET WHATSAPP WEB ENGINE (BAILEYS) v3 RUNNING`);
    console.log(`  Listening on: http://127.0.0.1:${PORT}`);
    console.log(`  Features: Send + Full WhatsApp Web + Group Forward`);
    console.log(`====================================================`);
    initWhatsApp();
});
