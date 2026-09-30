<?php
/**
 * Daily WhatsApp Reminder Cron Job
 * Checks active customers due in H-3, H-1, and today (H-0)
 * Run daily at 08:00 AM via crontab:
 * 0 8 * * * php /www/wwwroot/s.shawir.id/cron/cron_pppoe_reminder.php >> /var/log/snet_wa_reminder.log 2>&1
 */
define('IN_APP', true);
define('IS_CRON', true);

require_once __DIR__ . '/cron_logger.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../include/functions.php';
require_once __DIR__ . '/../include/WhatsAppGateway.php';

$log       = cron_logger('cron_pppoe_reminder');
$startTime = microtime(true);

// Hindari proses ganda / tumpukan cron (Process Lock)
$lockFp = fopen(sys_get_temp_dir() . '/snet_cron_wa_reminder.lock', 'c+');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    $log('Instance sebelumnya masih berjalan. Dilewati.', 'SKIP');
    exit(0);
}
cron_start_banner($log, 'cron_pppoe_reminder');

$wa = WhatsAppGateway::getInstance();
if (!$wa->isConfigured()) {
    $log('WhatsApp Gateway belum dikonfigurasi / tidak aktif. Cron dihentikan.', 'WARN');
    exit(0);
}

// Load company settings
$settings_raw = db_fetch_all("SELECT setting_key, setting_value FROM pppoe_settings");
$settings = [];
foreach ($settings_raw as $s) {
    $settings[$s['setting_key']] = $s['setting_value'];
}
$companyName = $settings['company_name'] ?? (defined('APP_COMPANY') ? APP_COMPANY : 'S.NET Internet');
$csPhone = $settings['company_phone'] ?? '';
$appDomain = WhatsAppGateway::getAppDomain();

$monthNames = [
    1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',5=>'Mei',6=>'Juni',
    7=>'Juli',8=>'Agustus',9=>'September',10=>'Oktober',11=>'November',12=>'Desember'
];

// Ambil pelanggan aktif berbayar yang memiliki nomor telepon
$customers = db_fetch_all(
    "SELECT * FROM pppoe_customers WHERE status = 'active' AND phone != '' AND monthly_price > 0 AND (is_free = 0 OR is_free IS NULL)"
);

$log('Total pelanggan berbayar dengan nomor WhatsApp: ' . count($customers));

$sent_h3 = 0;
$sent_h1 = 0;
$sent_h0 = 0;
$skipped = 0;

$tmplH3 = WhatsAppGateway::getTemplate('reminder_h3');
$tmplH1 = WhatsAppGateway::getTemplate('reminder_h1');
$tmplH0 = WhatsAppGateway::getTemplate('reminder_h0');

$todayTs = strtotime(date('Y-m-d'));
$curMonth = (int)date('n');
$curYear  = (int)date('Y');

foreach ($customers as $c) {
    $cid = (int)$c['id'];
    $cName = !empty($c['full_name']) ? $c['full_name'] : $c['pppoe_username'];

    if ((isset($c['is_free']) && (int)$c['is_free'] === 1) || (float)$c['monthly_price'] <= 0) {
        $log("[SKIP] {$cName} ({$c['pppoe_username']}): Pelanggan gratis / tagihan Rp 0");
        $skipped++;
        continue;
    }

    $rawDueDay = max(1, min(28, (int)$c['due_day']));

    // Hitung tanggal jatuh tempo terdekat
    // 1) Opsi bulan ini
    $dThis = min($rawDueDay, (int)date('t'));
    $dateThis = sprintf('%04d-%02d-%02d', $curYear, $curMonth, $dThis);
    $diffThis = (int)round((strtotime($dateThis) - $todayTs) / 86400);

    // 2) Opsi bulan depan (jika bulan ini sudah lewat tanggalnya)
    $nextMonthTs = strtotime('+1 month', strtotime(date('Y-m-01')));
    $nextM = (int)date('n', $nextMonthTs);
    $nextY = (int)date('Y', $nextMonthTs);
    $dNext = min($rawDueDay, (int)date('t', $nextMonthTs));
    $dateNext = sprintf('%04d-%02d-%02d', $nextY, $nextM, $dNext);
    $diffNext = (int)round((strtotime($dateNext) - $todayTs) / 86400);

    // Pilih siklus terdekat
    if ($diffThis >= 0) {
        $diff = $diffThis;
        $targetMonth = $curMonth;
        $targetYear = $curYear;
        $dueDay = $dThis;
    } else {
        $diff = $diffNext;
        $targetMonth = $nextM;
        $targetYear = $nextY;
        $dueDay = $dNext;
    }

    $targetMonthName = ($monthNames[$targetMonth] ?? '') . ' ' . $targetYear;
    $dueDateFormatted = sprintf('%02d %s %04d', $dueDay, ($monthNames[$targetMonth] ?? ''), $targetYear);

    // Cek apakah hari ini jadwal reminder (H-3, H-1, atau H-0)
    $targetTmpl = null;
    $typeLabel = '';

    if ($diff === 3) {
        $targetTmpl = $tmplH3;
        $typeLabel = 'reminder_h3';
    } elseif ($diff === 1) {
        $targetTmpl = $tmplH1;
        $typeLabel = 'reminder_h1';
    } elseif ($diff === 0) {
        $targetTmpl = $tmplH0;
        $typeLabel = 'reminder_h0';
    }

    if (!$typeLabel) {
        $log("[SKIP] {$cName} ({$c['pppoe_username']}): Belum jadwal reminder (Jatuh tempo {$dueDateFormatted}, {$diff} hari lagi)");
        $skipped++;
        continue;
    }

    if (!$targetTmpl) {
        $log("[SKIP] {$cName} ({$c['pppoe_username']}): Template WhatsApp '{$typeLabel}' belum aktif / tidak tersedia", 'WARN');
        $skipped++;
        continue;
    }

    // Cek apakah sudah lunas untuk periode jatuh tempo tersebut
    $paid = db_fetch_one(
        "SELECT COUNT(*) as c FROM pppoe_payments WHERE customer_id = ? AND period_month = ? AND period_year = ? AND midtrans_status NOT IN ('pending','cancel','deny','expire')",
        'iii', [$cid, $targetMonth, $targetYear]
    );
    if ($paid && (int)$paid['c'] > 0) {
        $log("[SKIP] {$cName} ({$c['pppoe_username']}): Tagihan periode {$targetMonthName} sudah lunas");
        $skipped++;
        continue;
    }

    // Hindari duplikasi pengiriman di hari yang sama untuk tipe yang sama
    $alreadySent = db_fetch_one(
        "SELECT id FROM wa_logs WHERE customer_id = ? AND message_type = ? AND DATE(created_at) = CURDATE() AND status = 'success' LIMIT 1",
        'is', [$cid, $typeLabel]
    );
    if ($alreadySent) {
        $log("[SKIP] {$cName} ({$c['pppoe_username']}): Reminder {$typeLabel} sudah pernah dikirim hari ini");
        $skipped++;
        continue;
    }

    $portalLink = 'https://' . $appDomain . '/portal/isolir.php?user=' . urlencode($c['pppoe_username']);

    $msgBody = WhatsAppGateway::renderTemplate($targetTmpl['message'], [
        'full_name' => $cName,
        'pppoe_username' => $c['pppoe_username'],
        'monthly_price' => $c['monthly_price'],
        'due_day' => $dueDay,
        'due_date' => $dueDateFormatted,
        'month_name' => $targetMonthName,
        'link_portal' => $portalLink,
        'cs_phone' => $csPhone,
        'company_name' => $companyName
    ]);

    $res = $wa->send($c['phone'], $msgBody, $cid, $typeLabel, $cName);

    if ($res['success']) {
        $log("[OK] ($typeLabel) Terkirim ke {$cName} ({$c['phone']})");
        if ($typeLabel === 'reminder_h3') $sent_h3++;
        if ($typeLabel === 'reminder_h1') $sent_h1++;
        if ($typeLabel === 'reminder_h0') $sent_h0++;
    } else {
        $log("[FAIL] ($typeLabel) {$cName}: {$res['message']}", 'ERROR');
    }

    // Beri jeda 1.5 detik antar pengiriman agar microservice & WhatsApp tidak flood/ban
    usleep(1500000);
}

cron_end_banner($log, 'cron_pppoe_reminder', $startTime, [
    'H-3 terkirim' => $sent_h3,
    'H-1 terkirim' => $sent_h1,
    'Hari H terkirim' => $sent_h0,
    'Dilewati' => $skipped,
]);
