/**
 * S.NET WhatsApp Web Microservice (Baileys Engine)
 * Self-Hosted QR Code & Pairing Code WhatsApp Gateway for S.NET Manager
 * Production-Grade Stability Edition v2 — Inbox + Forward to Group
 */

const express = require('express');
const cors = require('cors');
const qrcode = require('qrcode');
const pino = require('pino');
const fs = require('fs');
const path = require('path');
const {
    default: makeWASocket,
    useMultiFileAuthState,
    DisconnectReason,
    fetchLatestBaileysVersion,
    makeCacheableSignalKeyStore,
    Browsers,
    getAggregateVotesInPollMessage,
    proto
} = require('@whiskeysockets/baileys');

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

// Cache & Message Store untuk menangani permintaan retry WhatsApp (mencegah "Menunggu pesan ini...")
const messageStore = new Map();
const retryCountMap = new Map();
const retryCache = {
    get: (key) => retryCountMap.get(key),
    set: (key, val) => { retryCountMap.set(key, val); },
    del: (key) => { retryCountMap.delete(key); }
};

// Incoming messages store (circular buffer — max 500 messages)
const inboxMessages = [];
const MAX_INBOX = 500;

const logger = pino({ level: 'silent' });

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
        forward_group_jid: '',     // e.g. '120363012345678901@g.us'
        forward_enabled: false,
        webhook_url: '',           // e.g. 'http://127.0.0.1/ajax/wa_inbox_webhook.php'
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
 * Mencegah pemblokiran WhatsApp dan socket drop saat Cron mengirim ratusan tagihan sekaligus
 */
class MessageQueue {
    constructor(delayMs = 1500) {
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
                // Jeda aman antar pengiriman pesan: 1.5 - 2.5 detik
                const jitter = Math.floor(Math.random() * 1000);
                await new Promise((r) => setTimeout(r, this.delayMs + jitter));
            }
        }

        this.processing = false;
    }

    size() {
        return this.queue.length;
    }
}

const sendQueue = new MessageQueue(1500);

/**
 * Validasi dan perbaikan folder autentikasi (Self-Healing)
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
                    console.error('>>> [WA-GATEWAY] creds.json korup / invalid JSON. Mencadangkan file...', pe.message);
                    fs.renameSync(credsPath, path.join(AUTH_DIR, 'creds.json.corrupt_' + Date.now()));
                }
            }
        }

        // Repair pre-key files yang rusak (penyebab utama "Menunggu pesan ini...")
        const files = fs.readdirSync(AUTH_DIR);
        for (const file of files) {
            if (file.startsWith('pre-key-') || file.startsWith('session-') || file.startsWith('sender-key-')) {
                const fp = path.join(AUTH_DIR, file);
                try {
                    const raw = fs.readFileSync(fp, 'utf8');
                    if (!raw || raw.trim().length === 0) {
                        console.warn(`>>> [WA-GATEWAY] Menghapus file kosong: ${file}`);
                        fs.unlinkSync(fp);
                    } else {
                        JSON.parse(raw);
                    }
                } catch (e) {
                    console.warn(`>>> [WA-GATEWAY] Menghapus file korup: ${file}`);
                    try { fs.unlinkSync(fp); } catch (_) {}
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
        // Group message — get participant
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

        // Jangan forward pesan dari grup (hanya pesan pribadi)
        if (isFromGroup) return;
        // Jangan forward pesan sendiri
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

        // Check for media
        const m = msg.message;
        if (m?.imageMessage) {
            forwardText += `📷 [Mengirim Gambar]\n`;
        } else if (m?.videoMessage) {
            forwardText += `🎥 [Mengirim Video]\n`;
        } else if (m?.audioMessage) {
            forwardText += `🎵 [Mengirim Audio/Voice Note]\n`;
        } else if (m?.documentMessage) {
            forwardText += `📄 [Mengirim Dokumen: ${m.documentMessage.fileName || 'file'}]\n`;
        } else if (m?.stickerMessage) {
            forwardText += `🎭 [Mengirim Sticker]\n`;
        } else if (m?.locationMessage) {
            forwardText += `📍 [Mengirim Lokasi]\n`;
        } else if (m?.contactMessage || m?.contactsArrayMessage) {
            forwardText += `👥 [Mengirim Kontak]\n`;
        }

        if (!text && !m?.imageMessage && !m?.videoMessage && !m?.audioMessage && !m?.documentMessage) {
            forwardText += `💬 [Pesan tidak dapat ditampilkan]\n`;
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
    if (!gatewayConfig.webhook_enabled || !gatewayConfig.webhook_url) return;

    try {
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 5000);

        await fetch(gatewayConfig.webhook_url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(msgData),
            signal: controller.signal
        });

        clearTimeout(timeout);
    } catch (err) {
        // Silent fail — webhook is best-effort
        console.warn('>>> [WA-GATEWAY] Webhook error (non-fatal):', err?.message);
    }
}

/**
 * Inisialisasi WhatsApp Socket (Baileys)
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
                if (key?.id && messageStore.has(key.id)) {
                    return messageStore.get(key.id);
                }
                return undefined;
            },
            browser: Browsers.macOS('Desktop'),
            connectTimeoutMs: 60000,
            defaultQueryTimeoutMs: 60000,
            keepAliveIntervalMs: 25000,
            retryRequestDelayMs: 250,
            emitOwnEvents: false,
            syncFullHistory: false,
            markOnlineOnConnect: false,
            generateHighQualityLinkPreview: false,
            patchMessageBeforeSending: (message) => {
                // Tambahkan device info agar pesan tidak di-flag bot oleh Meta
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

        sock.ev.on('creds.update', saveCreds);

        // ── INCOMING MESSAGE HANDLER ──
        sock.ev.on('messages.upsert', async (m) => {
            if (!m.messages || !Array.isArray(m.messages)) return;

            for (const msg of m.messages) {
                // Store for retry mechanism (anti "Menunggu pesan ini...")
                if (msg.key?.id && msg.message) {
                    messageStore.set(msg.key.id, msg.message);
                    if (messageStore.size > 3000) {
                        const oldest = messageStore.keys().next().value;
                        messageStore.delete(oldest);
                    }
                }

                // Skip own outgoing messages and status broadcasts
                if (msg.key?.fromMe) continue;
                if (msg.key?.remoteJid === 'status@broadcast') continue;

                // Process incoming message
                const senderPhone = getSenderPhone(msg);
                const senderName = getSenderName(msg);
                const text = extractMessageText(msg);
                const isGroup = (msg.key?.remoteJid || '').endsWith('@g.us');
                const timestamp = msg.messageTimestamp
                    ? new Date(Number(msg.messageTimestamp) * 1000).toISOString()
                    : new Date().toISOString();

                // Determine message type
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
                    group_name: '', // Will be populated if available
                    timestamp: timestamp,
                    raw_jid: msg.key?.remoteJid || ''
                };

                // Push to inbox buffer
                inboxMessages.unshift(msgData);
                if (inboxMessages.length > MAX_INBOX) {
                    inboxMessages.pop();
                }

                totalMessagesReceived++;

                console.log(`>>> [WA-GATEWAY] 📩 Pesan masuk dari +${senderPhone} (${senderName}): ${text.substring(0, 80) || '[media]'}`);

                // Forward to technician group (only private messages, not group)
                if (!isGroup) {
                    forwardToGroup(msg).catch(() => {});
                }

                // Notify PHP webhook
                notifyWebhook(msgData).catch(() => {});
            }
        });

        // Pantau lifecycle pengiriman pesan di WhatsApp
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
                console.log('>>> [WA-GATEWAY] QR Code siap di-scan di web admin.');
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
                    // Exponential backoff dengan cap 30 detik
                    const delayMs = Math.min(reconnectAttempts * 2000, 30000);
                    console.log(`>>> [WA-GATEWAY] Mencoba menghubungkan kembali dalam ${delayMs / 1000} detik (Percobaan #${reconnectAttempts})...`);
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

                // Fetch group list for config
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

// Cached group list
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
        fs.mkdirSync(AUTH_DIR, { recursive: true });
    } catch (e) {
        console.error('Error removing auth directory:', e);
    }
}

function formatJid(phone) {
    let clean = String(phone).replace(/\D/g, '');
    if (clean.startsWith('08')) {
        clean = '62' + clean.slice(1);
    } else if (clean.startsWith('8')) {
        clean = '62' + clean;
    }
    return clean + '@s.whatsapp.net';
}

function cleanPhoneNumber(phone) {
    let clean = String(phone).replace(/\D/g, '');
    if (clean.startsWith('08')) {
        clean = '62' + clean.slice(1);
    } else if (clean.startsWith('8')) {
        clean = '62' + clean;
    }
    return clean;
}

// ── REST API ENDPOINTS ──

/**
 * GET /api/status - Cek status koneksi WhatsApp (diperluas)
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
        forward_enabled: gatewayConfig.forward_enabled,
        forward_group_jid: gatewayConfig.forward_group_jid,
        timestamp: new Date().toISOString()
    });
});

/**
 * GET /api/qr - Ambil gambar QR Code (Data URL Base64)
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
        // Jika QR belum tersedia, pemicu inisialisasi jika idle
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
 * POST /api/pairing-code - Tautkan WhatsApp via Nomor HP (Tanpa Scan Kamera)
 * Body: { phone: '08123456789' }
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
        
        // Format kode menjadi 8 karakter mudah dibaca (misal: 1234-ABCD)
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
 * POST /api/send - Kirim pesan teks atau gambar dengan caption (Melalui Antrean Aman)
 * Body: { phone: '08123...', message: '...', image_url: 'http...', image_base64: '...' }
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
        // Enqueue tugas pengiriman agar berurutan & aman dari spam-limit
        const result = await sendQueue.enqueue(async () => {
            if (connectionStatus !== 'connected' || !sock) {
                throw new Error('Koneksi WhatsApp terputus saat giliran pengiriman antrean.');
            }

            const rawPhone = cleanPhoneNumber(phone);
            let targetJid = formatJid(phone);

            // Validasi apakah nomor tujuan terdaftar di WhatsApp
            try {
                const onWaResults = await sock.onWhatsApp(rawPhone);
                if (Array.isArray(onWaResults) && onWaResults.length > 0 && onWaResults[0].exists) {
                    targetJid = onWaResults[0].jid;
                    console.log(`>>> [WA-GATEWAY] Nomor WhatsApp terverifikasi: ${rawPhone} -> ${targetJid}`);
                } else if (Array.isArray(onWaResults) && onWaResults.length > 0 && !onWaResults[0].exists) {
                    console.warn(`>>> [WA-GATEWAY] Nomor ${phone} (${rawPhone}) TIDAK TERDAFTAR di WhatsApp.`);
                    throw new Error(`Nomor ${phone} (${rawPhone}) tidak terdaftar di WhatsApp.`);
                }
            } catch (checkErr) {
                if (checkErr.message.includes('tidak terdaftar')) {
                    throw checkErr;
                }
                console.warn(`>>> [WA-GATEWAY] onWhatsApp skip/fallback:`, checkErr.message);
            }

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

            console.log(`>>> [WA-GATEWAY] Sukses dikirim ke server WhatsApp! Msg ID: ${sentMessage?.key?.id}`);

            if (sentMessage?.key?.id && sentMessage?.message) {
                messageStore.set(sentMessage.key.id, sentMessage.message);
                if (messageStore.size > 3000) {
                    const oldest = messageStore.keys().next().value;
                    messageStore.delete(oldest);
                }
            }

            totalMessagesSent++;
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
 * GET /api/messages - Ambil pesan masuk (inbox)
 * Query: ?limit=50&offset=0&phone=628xxx
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

/**
 * GET /api/groups - Daftar Grup WhatsApp yang bisa dipilih sebagai tujuan forward
 */
app.get('/api/groups', async (req, res) => {
    if (connectionStatus !== 'connected' || !sock) {
        return res.json({ success: false, groups: [], message: 'WhatsApp belum terhubung.' });
    }

    // Refresh group list
    await fetchGroupList().catch(() => {});

    res.json({
        success: true,
        groups: cachedGroups,
        current_forward_jid: gatewayConfig.forward_group_jid
    });
});

/**
 * POST /api/config - Simpan konfigurasi gateway (forward group, webhook, dll)
 * Body: { forward_group_jid, forward_enabled, webhook_url, webhook_enabled }
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
 * GET /api/config - Ambil konfigurasi gateway saat ini
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
 * POST /api/restart - Restart engine socket tanpa menghapus sesi yang ada
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
 * POST /api/reset - Reset bersih folder autentikasi (Jika terjadi error korupsi sesi)
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
        res.json({ success: true, message: 'Folder sesi berhasil direset bersih. Silakan tautkan ulang.' });
    } catch (err) {
        res.status(500).json({ success: false, message: err?.message || String(err) });
    }
});

/**
 * POST /api/reply - Balas pesan ke nomor tertentu (dari panel admin)
 * Body: { phone: '628xxx', message: '...' }
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
        const result = await sendQueue.enqueue(async () => {
            const targetJid = formatJid(phone);
            const sentMessage = await sock.sendMessage(targetJid, { text: message });

            if (sentMessage?.key?.id && sentMessage?.message) {
                messageStore.set(sentMessage.key.id, sentMessage.message);
            }

            totalMessagesSent++;
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

// Start server
app.listen(PORT, '127.0.0.1', () => {
    console.log(`====================================================`);
    console.log(`  S.NET WHATSAPP WEB ENGINE (BAILEYS) v2 RUNNING`);
    console.log(`  Listening on: http://127.0.0.1:${PORT}`);
    console.log(`  Features: Send + Inbox + Forward to Group`);
    console.log(`====================================================`);
    initWhatsApp();
});
