<?php
/**
 * S.NET Public Digital Receipt / Kwitansi Online Pelanggan
 * URL: https://domain/portal/receipt.php?id=123 (or ?inv=INV-xxx)
 * Accessible directly by customers without admin login
 */
define('IN_APP', true);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../include/functions.php';

$id = (int)get('id', 0);
$inv = trim(get('inv', ''));

$payment = null;
if ($id > 0) {
    $payment = db_fetch_one(
        "SELECT pp.*, pc.full_name, pc.pppoe_username, pc.phone, pc.address, pc.profile, r.name as router_name 
         FROM pppoe_payments pp 
         JOIN pppoe_customers pc ON pp.customer_id = pc.id 
         LEFT JOIN routers r ON pc.router_id = r.id 
         WHERE pp.id = ?",
        'i', [$id]
    );
} elseif (!empty($inv)) {
    $payment = db_fetch_one(
        "SELECT pp.*, pc.full_name, pc.pppoe_username, pc.phone, pc.address, pc.profile, r.name as router_name 
         FROM pppoe_payments pp 
         JOIN pppoe_customers pc ON pp.customer_id = pc.id 
         LEFT JOIN routers r ON pc.router_id = r.id 
         WHERE pp.midtrans_order_id = ? OR pp.id = ?",
        'ss', [$inv, $inv]
    );
}

if (!$payment) {
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Kwitansi Tidak Ditemukan</title>
        <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700&display=swap" rel="stylesheet">
        <style>
            body { font-family: 'Plus Jakarta Sans', sans-serif; background: #f8fafc; color: #334155; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px; }
            .card { background: #fff; padding: 36px 28px; border-radius: 16px; text-align: center; max-width: 400px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; }
            .icon { font-size: 3rem; margin-bottom: 12px; }
            h2 { margin: 0 0 8px; color: #0f172a; font-size: 1.25rem; }
            p { margin: 0 0 20px; color: #64748b; font-size: 0.9rem; line-height: 1.5; }
            .btn { display: inline-block; background: #2563eb; color: #fff; text-decoration: none; padding: 10px 20px; border-radius: 8px; font-weight: 600; font-size: 0.9rem; }
        </style>
    </head>
    <body>
        <div class="card">
            <div class="icon">🔍</div>
            <h2>Kwitansi Tidak Ditemukan</h2>
            <p>Data kwitansi atau invoice pembayaran yang Anda cari tidak ditemukan dalam sistem kami.</p>
            <a href="/" class="btn">Kembali ke Beranda</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$settings_raw = db_fetch_all("SELECT setting_key, setting_value FROM pppoe_settings");
$settings = [];
foreach ($settings_raw as $s) {
    $settings[$s['setting_key']] = $s['setting_value'];
}

$company_name = $settings['company_name'] ?? (defined('APP_COMPANY') ? APP_COMPANY : 'S.NET Internet');
$company_phone = $settings['company_phone'] ?? '';
$company_address = $settings['company_address'] ?? '';

$months = [
    1=>'Januari', 2=>'Februari', 3=>'Maret', 4=>'April',
    5=>'Mei', 6=>'Juni', 7=>'Juli', 8=>'Agustus',
    9=>'September', 10=>'Oktober', 11=>'November', 12=>'Desember'
];

$receiptNo = $payment['midtrans_order_id'] ?: ('INV-' . str_pad($payment['id'], 6, '0', STR_PAD_LEFT));
$periodName = ($months[$payment['period_month']] ?? $payment['period_month']) . ' ' . $payment['period_year'];
$logoPath = file_exists(__DIR__ . '/../assets/img/logo.png') ? '/assets/img/logo.png' : '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kwitansi Pembayaran #<?= htmlspecialchars($receiptNo) ?> - <?= htmlspecialchars($company_name) ?></title>
    <meta name="description" content="Kwitansi Pembayaran Tagihan Internet Resmi <?= htmlspecialchars($company_name) ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #f1f5f9;
            color: #1e293b;
            padding: 30px 15px;
            font-size: 14px;
            min-height: 100vh;
        }
        .receipt-card {
            max-width: 480px;
            margin: 0 auto;
            background: #fff;
            border-radius: 20px;
            padding: 30px 24px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.05);
            border: 1px solid #e2e8f0;
            position: relative;
        }
        .receipt-header {
            text-align: center;
            border-bottom: 2px dashed #e2e8f0;
            padding-bottom: 22px;
            margin-bottom: 22px;
        }
        .logo-img {
            max-height: 52px;
            max-width: 180px;
            object-fit: contain;
            margin-bottom: 10px;
        }
        .company-name {
            font-size: 1.25rem;
            font-weight: 800;
            color: #1e3a8a;
            letter-spacing: -0.02em;
        }
        .company-info {
            font-size: 0.78rem;
            color: #64748b;
            margin-top: 3px;
            line-height: 1.4;
        }
        .badge-status {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
            padding: 5px 14px;
            border-radius: 30px;
            font-size: 0.8rem;
            font-weight: 700;
            margin-top: 12px;
            letter-spacing: 0.03em;
        }
        .info-group {
            margin-bottom: 18px;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            padding: 7px 0;
            font-size: 0.88rem;
        }
        .info-label {
            color: #64748b;
        }
        .info-value {
            font-weight: 600;
            color: #0f172a;
            text-align: right;
        }
        .font-mono {
            font-family: 'JetBrains Mono', monospace;
        }
        .divider {
            border-top: 1px solid #f1f5f9;
            margin: 14px 0;
        }
        .amount-box {
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            border: 1px solid #bbf7d0;
            border-radius: 14px;
            padding: 16px 18px;
            margin: 20px 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .amount-label {
            font-size: 0.82rem;
            color: #166534;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .amount-value {
            font-size: 1.4rem;
            font-weight: 800;
            color: #15803d;
            letter-spacing: -0.02em;
        }
        .receipt-footer {
            text-align: center;
            font-size: 0.76rem;
            color: #94a3b8;
            margin-top: 24px;
            border-top: 2px dashed #e2e8f0;
            padding-top: 18px;
            line-height: 1.5;
        }
        .action-btns {
            max-width: 480px;
            margin: 16px auto 0;
            display: flex;
            gap: 10px;
        }
        .btn {
            flex: 1;
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 0.9rem;
            font-weight: 700;
            border: none;
            cursor: pointer;
            text-align: center;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: all 0.2s;
        }
        .btn-print {
            background: #2563eb;
            color: #fff;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);
        }
        .btn-print:hover { background: #1d4ed8; transform: translateY(-1px); }
        .btn-cs {
            background: #16a34a;
            color: #fff;
            box-shadow: 0 4px 12px rgba(22, 163, 74, 0.25);
        }
        .btn-cs:hover { background: #15803d; transform: translateY(-1px); }

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
        <?php if ($logoPath): ?>
            <img src="<?= htmlspecialchars($logoPath) ?>" alt="Logo" class="logo-img">
        <?php endif; ?>
        <div class="company-name"><?= htmlspecialchars($company_name) ?></div>
        <?php if ($company_address || $company_phone): ?>
        <div class="company-info">
            <?= htmlspecialchars($company_address) ?>
            <?= ($company_address && $company_phone) ? '<br>' : '' ?>
            <?= $company_phone ? 'WA / CS: ' . htmlspecialchars($company_phone) : '' ?>
        </div>
        <?php endif; ?>
        <div>
            <div class="badge-status">
                <span>✓</span> LUNAS / PEMBAYARAN BERHASIL
            </div>
        </div>
    </div>

    <div class="info-group">
        <div class="info-row">
            <span class="info-label">No. Invoice</span>
            <span class="info-value font-mono">#<?= htmlspecialchars($receiptNo) ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">Waktu Pembayaran</span>
            <span class="info-value"><?= date('d M Y, H:i', strtotime($payment['paid_at'])) ?> WIB</span>
        </div>
        <div class="info-row">
            <span class="info-label">Metode Pembayaran</span>
            <span class="info-value font-mono"><?= strtoupper(htmlspecialchars($payment['payment_method'] ?: 'CASH')) ?></span>
        </div>
    </div>

    <div class="divider"></div>

    <div class="info-group">
        <div class="info-row">
            <span class="info-label">Nama Pelanggan</span>
            <span class="info-value"><?= htmlspecialchars($payment['full_name']) ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">ID / User PPPoE</span>
            <span class="info-value font-mono"><?= htmlspecialchars($payment['pppoe_username']) ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">Paket Layanan</span>
            <span class="info-value"><?= htmlspecialchars($payment['profile'] ?: 'Reguler') ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">Periode Tagihan</span>
            <span class="info-value"><?= $periodName ?></span>
        </div>
        <?php if ($payment['notes']): ?>
        <div class="info-row">
            <span class="info-label">Keterangan</span>
            <span class="info-value fst-italic"><?= htmlspecialchars($payment['notes']) ?></span>
        </div>
        <?php endif; ?>
    </div>

    <div class="amount-box">
        <span class="amount-label">TOTAL DIBAYAR</span>
        <span class="amount-value"><?= format_price((float)$payment['amount']) ?></span>
    </div>

    <div class="receipt-footer">
        <p>Terima kasih atas pembayaran tagihan internet Anda.</p>
        <p>Simpan dokumen digital ini sebagai bukti transaksi resmi yang sah.</p>
    </div>
</div>

<div class="action-btns">
    <button class="btn btn-print" onclick="window.print()">
        🖨️ Cetak / Simpan PDF
    </button>
    <?php if ($company_phone): ?>
    <a href="https://wa.me/<?= preg_replace('/\D/', '', $company_phone) ?>?text=Halo%20Admin%20<?= urlencode($company_name) ?>%2C%20saya%20ingin%20bertanya%20mengenai%20invoice%20%23<?= urlencode($receiptNo) ?>" class="btn btn-cs" target="_blank">
        💬 Hubungi CS
    </a>
    <?php endif; ?>
</div>

</body>
</html>
