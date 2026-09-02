<?php
/**
 * Daily WhatsApp Reminder Cron Job
 * Checks active customers due in H-3, H-1, and today (H-0)
 * Run daily at 08:00 AM via crontab:
 * 0 8 * * * php /www/wwwroot/s.shawir.id/cron/cron_pppoe_reminder.php >> /var/log/snet_wa_reminder.log 2>&1
 */
define('IN_APP', true);
define('IS_CRON', true);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../include/functions.php';
require_once __DIR__ . '/../include/WhatsAppGateway.php';

echo "[" . date('Y-m-d H:i:s') . "] Memulai pengecekan pengingat tagihan WhatsApp...\n";

$wa = WhatsAppGateway::getInstance();
if (!$wa->isConfigured()) {
    echo "WhatsApp Gateway belum dikonfigurasi / tidak aktif. Cron dihentikan.\n";
    exit;
}

// Load company settings
$settings_raw = db_fetch_all("SELECT setting_key, setting_value FROM pppoe_settings");
$settings = [];
foreach ($settings_raw as $s) {
    $settings[$s['setting_key']] = $s['setting_value'];
}
$companyName = $settings['company_name'] ?? (defined('APP_COMPANY') ? APP_COMPANY : 'S.NET Internet');
$csPhone = $settings['company_phone'] ?? '';

$curMonth = (int)date('n');
$curYear  = (int)date('Y');
$todayDay = (int)date('j');
$daysInMonth = (int)date('t');

$monthNames = [
    1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',5=>'Mei',6=>'Juni',
    7=>'Juli',8=>'Agustus',9=>'September',10=>'Oktober',11=>'November',12=>'Desember'
];
$curMonthName = $monthNames[$curMonth] . ' ' . $curYear;

// Ambil pelanggan aktif yang memiliki nomor telepon
$customers = db_fetch_all(
    "SELECT * FROM pppoe_customers WHERE status = 'active' AND phone != '' AND monthly_price > 0"
);

echo "Total pelanggan aktif dengan nomor WhatsApp: " . count($customers) . "\n";

$sent_h3 = 0;
$sent_h1 = 0;
$sent_h0 = 0;
$skipped = 0;

$tmplH3 = WhatsAppGateway::getTemplate('reminder_h3');
$tmplH1 = WhatsAppGateway::getTemplate('reminder_h1');
$tmplH0 = WhatsAppGateway::getTemplate('reminder_h0');

foreach ($customers as $c) {
    $cid = (int)$c['id'];
    $dueDay = min((int)$c['due_day'], $daysInMonth);

    // Cek apakah sudah bayar bulan ini
    $paid = db_fetch_one(
        "SELECT COUNT(*) as c FROM pppoe_payments WHERE customer_id = ? AND period_month = ? AND period_year = ? AND midtrans_status NOT IN ('pending','cancel','deny','expire')",
        'iii', [$cid, $curMonth, $curYear]
    );
    if ($paid && (int)$paid['c'] > 0) {
        $skipped++;
        continue;
    }

    $diff = $dueDay - $todayDay;
    $targetTmpl = null;
    $typeLabel = '';

    if ($diff === 3 && $tmplH3) {
        $targetTmpl = $tmplH3;
        $typeLabel = 'reminder_h3';
    } elseif ($diff === 1 && $tmplH1) {
        $targetTmpl = $tmplH1;
        $typeLabel = 'reminder_h1';
    } elseif ($diff === 0 && $tmplH0) {
        $targetTmpl = $tmplH0;
        $typeLabel = 'reminder_h0';
    }

    if (!$targetTmpl) {
        $skipped++;
        continue;
    }

    // Hindari duplikasi pengiriman di hari yang sama untuk tipe yang sama
    $alreadySent = db_fetch_one(
        "SELECT id FROM wa_logs WHERE customer_id = ? AND message_type = ? AND DATE(created_at) = CURDATE() AND status = 'success' LIMIT 1",
        'is', [$cid, $typeLabel]
    );
    if ($alreadySent) {
        $skipped++;
        continue;
    }

    $dueDateFormatted = sprintf('%02d %s %04d', $dueDay, $monthNames[$curMonth], $curYear);
    $portalLink = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'dash.snetwifi.com') . '/portal/isolir.php?user=' . urlencode($c['pppoe_username']);

    $msgBody = WhatsAppGateway::renderTemplate($targetTmpl['message'], [
        'full_name' => $c['full_name'],
        'pppoe_username' => $c['pppoe_username'],
        'monthly_price' => $c['monthly_price'],
        'due_day' => $c['due_day'],
        'due_date' => $dueDateFormatted,
        'month_name' => $curMonthName,
        'link_portal' => $portalLink,
        'cs_phone' => $csPhone,
        'company_name' => $companyName
    ]);

    $res = $wa->send($c['phone'], $msgBody, $cid, $typeLabel, $c['full_name']);

    if ($res['success']) {
        echo "  [OK] ($typeLabel) Terkirim ke {$c['full_name']} ({$c['phone']})\n";
        if ($typeLabel === 'reminder_h3') $sent_h3++;
        if ($typeLabel === 'reminder_h1') $sent_h1++;
        if ($typeLabel === 'reminder_h0') $sent_h0++;
    } else {
        echo "  [FAIL] ($typeLabel) {$c['full_name']}: {$res['message']}\n";
    }
}

echo "\n[" . date('Y-m-d H:i:s') . "] Selesai. H-3: $sent_h3 | H-1: $sent_h1 | Hari H: $sent_h0 | Skip: $skipped\n";
