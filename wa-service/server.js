/**
 * S.NET WhatsApp Web Microservice (Baileys Engine)
 * Self-Hosted QR Code WhatsApp Gateway for S.NET Manager
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
    makeCacheableSignalKeyStore
} = require('@whiskeysockets/baileys');

const app = express();
const PORT = process.env.PORT || 3000;
const AUTH_DIR = path.join(__dirname, 'auth_info_baileys');

app.use(cors());
app.use(express.json({ limit: '15mb' }));
app.use(express.urlencoded({ extended: true, limit: '15mb' }));

// Global State
let sock = null;
let currentQR = null;
let qrDataUrl = null;
let connectionStatus = 'disconnected'; // 'disconnected' | 'connecting' | 'scan_qr' | 'connected'
let connectedUser = null;
let reconnectAttempts = 0;

const logger = pino({ level: 'silent' });

async function initWhatsApp() {
    try {
        if (!fs.existsSync(AUTH_DIR)) {
            fs.mkdirSync(AUTH_DIR, { recursive: true });
        }

        const { state, saveCreds } = await useMultiFileAuthState(AUTH_DIR);
        const { version } = await fetchLatestBaileysVersion();

        sock = makeWASocket({
            version,
            logger,
            printQRInTerminal: true,
            auth: {
                creds: state.creds,
                keys: makeCacheableSignalKeyStore(state.keys, logger),
            },
            browser: ['S.NET Manager', 'Chrome', '120.0.0.0'],
            connectTimeoutMs: 60000,
            defaultQueryTimeoutMs: 60000,
            keepAliveIntervalMs: 10000,
            emitOwnEvents: false,
            syncFullHistory: false
        });

        sock.ev.on('creds.update', saveCreds);

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
                const shouldReconnect = statusCode !== DisconnectReason.loggedOut;
                console.log(`>>> [WA-GATEWAY] Koneksi terputus (Code: ${statusCode}). Reconnect: ${shouldReconnect}`);

                connectionStatus = 'disconnected';
                connectedUser = null;
                currentQR = null;
                qrDataUrl = null;

                if (shouldReconnect) {
                    reconnectAttempts++;
                    const delay = Math.min(reconnectAttempts * 2000, 10000);
                    setTimeout(() => {
                        console.log('>>> [WA-GATEWAY] Mencoba menghubungkan kembali...');
                        initWhatsApp();
                    }, delay);
                } else {
                    console.log('>>> [WA-GATEWAY] Sesi logout. Menghapus auth folder...');
                    cleanAuthDir();
                    setTimeout(initWhatsApp, 2000);
                }
            } else if (connection === 'open') {
                reconnectAttempts = 0;
                connectionStatus = 'connected';
                currentQR = null;
                qrDataUrl = null;

                const user = sock.user;
                connectedUser = {
                    id: user?.id?.split(':')[0] || user?.id || '',
                    name: user?.name || user?.notify || 'S.NET Admin'
                };
                console.log(`>>> [WA-GATEWAY] BERHASIL TERHUBUNG! Nomor: ${connectedUser.id} (${connectedUser.name})`);
            } else if (connection === 'connecting') {
                connectionStatus = 'connecting';
            }
        });
    } catch (err) {
        console.error('>>> [WA-GATEWAY] Error inisialisasi Baileys:', err);
        connectionStatus = 'disconnected';
    }
}

function cleanAuthDir() {
    try {
        if (fs.existsSync(AUTH_DIR)) {
            fs.rmSync(AUTH_DIR, { recursive: true, force: true });
        }
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

// ── REST API ENDPOINTS ──

/**
 * GET /api/status - Cek status koneksi WhatsApp
 */
app.get('/api/status', (req, res) => {
    res.json({
        status: connectionStatus,
        user: connectedUser,
        has_qr: !!qrDataUrl,
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
        return res.json({
            status: connectionStatus,
            message: 'QR Code sedang dibuat, silakan muat ulang dalam beberapa detik...',
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
 * POST /api/send - Kirim pesan teks atau gambar dengan caption
 * Body: { phone: '08123...', message: '...', image_url: 'http...', image_base64: '...' }
 */
app.post('/api/send', async (req, res) => {
    if (connectionStatus !== 'connected' || !sock) {
        return res.status(503).json({
            success: false,
            message: 'WhatsApp belum terhubung. Silakan scan QR Code terlebih dahulu di web panel.'
        });
    }

    const { phone, message, image_url, image_base64 } = req.body;

    if (!phone || (!message && !image_url && !image_base64)) {
        return res.status(400).json({
            success: false,
            message: 'Nomor telepon dan pesan/gambar wajib diisi.'
        });
    }

    const jid = formatJid(phone);

    try {
        let sentMessage = null;

        if (image_url) {
            // Kirim gambar dari URL
            sentMessage = await sock.sendMessage(jid, {
                image: { url: image_url },
                caption: message || ''
            });
        } else if (image_base64) {
            // Kirim gambar dari Base64
            const cleanBase64 = image_base64.replace(/^data:image\/\w+;base64,/, '');
            const buffer = Buffer.from(cleanBase64, 'base64');
            sentMessage = await sock.sendMessage(jid, {
                image: buffer,
                caption: message || ''
            });
        } else {
            // Kirim pesan teks biasa
            sentMessage = await sock.sendMessage(jid, { text: message });
        }

        res.json({
            success: true,
            message: 'Pesan berhasil dikirim',
            message_id: sentMessage?.key?.id || null
        });
    } catch (err) {
        console.error('Error sending message:', err);
        res.status(500).json({
            success: false,
            message: 'Gagal mengirim pesan: ' + (err.message || String(err))
        });
    }
});

/**
 * POST /api/logout - Putus koneksi dan hapus sesi untuk scan ulang
 */
app.post('/api/logout', async (req, res) => {
    try {
        if (sock) {
            await sock.logout().catch(() => {});
        }
        cleanAuthDir();
        connectionStatus = 'disconnected';
        connectedUser = null;
        currentQR = null;
        qrDataUrl = null;

        setTimeout(initWhatsApp, 1500);

        res.json({
            success: true,
            message: 'Koneksi WhatsApp berhasil diputus. Silakan scan QR Code baru.'
        });
    } catch (err) {
        res.status(500).json({
            success: false,
            message: 'Gagal logout: ' + err.message
        });
    }
});

/**
 * POST /api/restart - Restart engine socket
 */
app.post('/api/restart', (req, res) => {
    try {
        if (sock) {
            sock.end(undefined);
        }
        setTimeout(initWhatsApp, 1000);
        res.json({ success: true, message: 'Engine WhatsApp sedang direstart...' });
    } catch (err) {
        res.status(500).json({ success: false, message: err.message });
    }
});

// Start server
app.listen(PORT, '127.0.0.1', () => {
    console.log(`====================================================`);
    console.log(`  S.NET WHATSAPP WEB ENGINE (BAILEYS) RUNNING`);
    console.log(`  Listening on: http://127.0.0.1:${PORT}`);
    console.log(`====================================================`);
    initWhatsApp();
});
