<?php
/**
 * PPPoE Payment Receipt / Kwitansi Cetak Bukti Bayar
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../include/functions.php';
require_once __DIR__ . '/../../include/WhatsAppGateway.php';

auth_check();

$id = (int)get('id');
if ($id <= 0) {
    die("ID Pembayaran tidak valid.");
}

$payment = db_fetch_one(
    "SELECT pp.*, pc.full_name, pc.pppoe_username, pc.phone, pc.address, pc.profile, r.name as router_name 
     FROM pppoe_payments pp 
     JOIN pppoe_customers pc ON pp.customer_id = pc.id 
     LEFT JOIN routers r ON pc.router_id = r.id 
     WHERE pp.id = ?",
    'i', [$id]
);

if (!$payment) {
    die("Data transaksi pembayaran tidak ditemukan.");
}

$settings_raw = db_fetch_all("SELECT setting_key, setting_value FROM pppoe_settings");
$settings = [];
foreach ($settings_raw as $s) {
    $settings[$s['setting_key']] = $s['setting_value'];
}

$company_name = $settings['company_name'] ?? APP_COMPANY;
$company_phone = $settings['company_phone'] ?? '';
$company_address = $settings['company_address'] ?? '';

$months = [
    1=>'Januari', 2=>'Februari', 3=>'Maret', 4=>'April',
    5=>'Mei', 6=>'Juni', 7=>'Juli', 8=>'Agustus',
    9=>'September', 10=>'Oktober', 11=>'November', 12=>'Desember'
];

$wa_status = '';
// Handler Kirim Kwitansi via WhatsApp
if (get('action') === 'send_wa') {
    if (empty($payment['phone'])) {
        $wa_status = 'error: Nomor WhatsApp pelanggan tidak terdaftar.';
    } else {
        $receiptNo = $payment['midtrans_order_id'] ?: ('INV-' . str_pad($payment['id'], 6, '0', STR_PAD_LEFT));
        $periodName = ($months[$payment['period_month']] ?? $payment['period_month']) . ' ' . $payment['period_year'];
        $receiptUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 's.shawir.id') . '/index.php?page=pppoe_receipt&id=' . $payment['id'];
        $logoUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 's.shawir.id') . '/assets/img/logo.png';

        $msg = "🧾 *KWITANSI PEMBAYARAN INTERNET*\n";
        $msg .= "--------------------------------------\n";
        $msg .= "🏢 *" . strtoupper($company_name) . "*\n";
        if ($company_address) $msg .= "📍 " . $company_address . "\n";
        $msg .= "--------------------------------------\n\n";
        $msg .= "Yth. *" . $payment['full_name'] . "* (" . $payment['pppoe_username'] . "),\n";
        $msg .= "Terima kasih, pembayaran tagihan internet Anda telah kami terima:\n\n";
        $msg .= "📄 *No. Invoice:* #" . $receiptNo . "\n";
        $msg .= "📅 *Periode:* " . $periodName . "\n";
        $msg .= "📦 *Paket Layanan:* " . ($payment['profile'] ?: 'Reguler') . "\n";
        $msg .= "💳 *Metode:* " . strtoupper($payment['payment_method']) . "\n";
        $msg .= "⏰ *Waktu Bayar:* " . date('d M Y, H:i', strtotime($payment['paid_at'])) . " WIB\n";
        $msg .= "💰 *TOTAL DIBAYAR:* *" . format_price((float)$payment['amount']) . "*\n";
        $msg .= "✅ *STATUS: LUNAS*\n\n";
        $msg .= "🔗 *Lihat & Unduh Kwitansi Digital (Berlogo):*\n" . $receiptUrl . "\n\n";
        if ($company_phone) $msg .= "📞 Layanan Pelanggan / CS: " . $company_phone . "\n";
        $msg .= "Simpan pesan ini sebagai bukti pembayaran yang sah. 🙏";

        $wa = WhatsAppGateway::getInstance();
        $res = $wa->send($payment['phone'], $msg, $payment['customer_id'], 'receipt', $payment['full_name'], $logoUrl);
        if ($res['success']) {
            $wa_status = 'success: Kwitansi pembayaran berhasil dikirim ke WhatsApp ' . htmlspecialchars($payment['phone']);
        } else {
            $wa_status = 'error: Gagal mengirim WhatsApp: ' . htmlspecialchars($res['message']);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kwitansi Pembayaran #<?= htmlspecialchars($payment['midtrans_order_id'] ?: $payment['id']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Exo+2:wght@400;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Exo 2', sans-serif;
            background: #f1f4f9;
            color: #2b3040;
            padding: 30px 15px;
            font-size: 14px;
        }
        .receipt-card {
            max-width: 480px;
            margin: 0 auto;
            background: #fff;
            border-radius: 16px;
            padding: 28px 24px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.06);
            border: 1px solid #e2e8f0;
        }
        .receipt-header {
            text-align: center;
            border-bottom: 2px dashed #e2e8f0;
            padding-bottom: 20px;
            margin-bottom: 20px;
        }
        .receipt-header img {
            max-height: 48px;
            margin-bottom: 8px;
        }
        .company-name {
            font-size: 1.2rem;
            font-weight: 800;
            color: #1e3a8a;
        }
        .company-info {
            font-size: 0.78rem;
            color: #64748b;
            margin-top: 2px;
        }
        .receipt-title {
            display: inline-block;
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.78rem;
            font-weight: 700;
            margin-top: 10px;
        }
        .info-group {
            margin-bottom: 18px;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            font-size: 0.86rem;
        }
        .info-label {
            color: #64748b;
        }
        .info-value {
            font-weight: 600;
            color: #1e293b;
            text-align: right;
        }
        .font-mono {
            font-family: 'JetBrains Mono', monospace;
        }
        .divider {
            border-top: 1px solid #e2e8f0;
            margin: 12px 0;
        }
        .amount-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 14px 16px;
            margin: 16px 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .amount-label {
            font-size: 0.85rem;
            color: #475569;
            font-weight: 600;
        }
        .amount-value {
            font-size: 1.3rem;
            font-weight: 800;
            color: #15803d;
        }
        .receipt-footer {
            text-align: center;
            font-size: 0.76rem;
            color: #94a3b8;
            margin-top: 24px;
            border-top: 2px dashed #e2e8f0;
            padding-top: 16px;
        }
        .action-btns {
            max-width: 480px;
            margin: 16px auto 0;
            display: flex;
            gap: 10px;
        }
        .btn {
            flex: 1;
            padding: 10px 16px;
            border-radius: 10px;
            font-size: 0.9rem;
            font-weight: 700;
            border: none;
            cursor: pointer;
            text-align: center;
            text-decoration: none;
        }
        .btn-print {
            background: #2563eb;
            color: #fff;
        }
        .btn-print:hover { background: #1d4ed8; }
        .btn-close {
            background: #e2e8f0;
            color: #475569;
        }

        @media print {
            body { background: #fff; padding: 0; }
            .receipt-card { box-shadow: none; border: 1px solid #000; border-radius: 0; }
            .action-btns { display: none; }
        }
    </style>
</head>
<body>

<div class="receipt-card">
    <div class="receipt-header">
        <img src="/assets/img/logo.png?v=<?= filemtime(__DIR__ . '/../../assets/img/logo.png') ?>" alt="Logo">
        <div class="company-name"><?= htmlspecialchars($company_name) ?></div>
        <?php if ($company_address || $company_phone): ?>
        <div class="company-info">
            <?= htmlspecialchars($company_address) ?>
            <?= $company_phone ? ' | WA/Telp: ' . htmlspecialchars($company_phone) : '' ?>
        </div>
        <?php endif; ?>
        <div class="receipt-title">BUKTI PEMBAYARAN TAGIHAN</div>
    </div>

    <div class="info-group">
        <div class="info-row">
            <span class="info-label">No. Transaksi</span>
            <span class="info-value font-mono"><?= htmlspecialchars($payment['midtrans_order_id'] ?: ('INV-' . str_pad($payment['id'], 6, '0', STR_PAD_LEFT))) ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">Waktu Bayar</span>
            <span class="info-value"><?= date('d M Y, H:i', strtotime($payment['paid_at'])) ?> WIB</span>
        </div>
        <div class="info-row">
            <span class="info-label">Metode Pembayaran</span>
            <span class="info-value"><?= strtoupper(htmlspecialchars($payment['payment_method'])) ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">Status</span>
            <span class="info-value" style="color:#16a34a">LUNAS / BERHASIL</span>
        </div>
    </div>

    <div class="divider"></div>

    <div class="info-group">
        <div class="info-row">
            <span class="info-label">Nama Pelanggan</span>
            <span class="info-value"><?= htmlspecialchars($payment['full_name']) ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">Username PPPoE</span>
            <span class="info-value font-mono"><?= htmlspecialchars($payment['pppoe_username']) ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">Paket Layanan</span>
            <span class="info-value"><?= htmlspecialchars($payment['profile'] ?: '-') ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">Periode Tagihan</span>
            <span class="info-value"><?= $months[$payment['period_month']] ?? $payment['period_month'] ?> <?= $payment['period_year'] ?></span>
        </div>
        <?php if ($payment['notes']): ?>
        <div class="info-row">
            <span class="info-label">Keterangan</span>
            <span class="info-value"><?= htmlspecialchars($payment['notes']) ?></span>
        </div>
        <?php endif; ?>
    </div>

    <div class="amount-box">
        <span class="amount-label">TOTAL PEMBAYARAN</span>
        <span class="amount-value"><?= format_price((float)$payment['amount']) ?></span>
    </div>

    <div class="receipt-footer">
        <p>Terima kasih atas pembayaran Anda.</p>
        <p>Simpan struk ini sebagai bukti pembayaran yang sah.</p>
    </div>
</div>

<?php if ($wa_status): ?>
<div style="max-width:480px;margin:12px auto 0;">
    <?php if (str_starts_with($wa_status, 'success:')): ?>
        <div style="background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;padding:10px 14px;border-radius:10px;font-size:0.85rem;text-align:center;">
            ✅ <?= htmlspecialchars(substr($wa_status, 9)) ?>
        </div>
    <?php else: ?>
        <div style="background:#fef2f2;color:#991b1b;border:1px solid #fecaca;padding:10px 14px;border-radius:10px;font-size:0.85rem;text-align:center;">
            ⚠️ <?= htmlspecialchars(substr($wa_status, 7)) ?>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="action-btns">
    <button class="btn btn-print" onclick="window.print()">🖨️ Cetak</button>
    <a href="/index.php?page=pppoe_receipt&id=<?= $payment['id'] ?>&action=send_wa" class="btn" style="background:#16a34a;color:#fff;" onclick="return confirm('Kirim kwitansi ini ke nomor WhatsApp <?= htmlspecialchars(addslashes($payment['phone'])) ?>?')">
        📱 Kirim WhatsApp
    </a>
    <button class="btn btn-close" onclick="window.close()">Tutup</button>
</div>

</body>
</html>
