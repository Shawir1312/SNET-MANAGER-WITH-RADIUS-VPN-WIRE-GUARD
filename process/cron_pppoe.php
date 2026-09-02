<?php
/**
 * Auto-Isolir Cron Job
 * Jalankan setiap hari via cron: 0 1 * * * php /www/wwwroot/rs.snetwork.online/process/cron_pppoe.php
 */
define('IN_APP', true);
define('IS_CRON', true);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../include/functions.php';
require_once __DIR__ . '/../lib/routeros_api.class.php';
require_once __DIR__ . '/../include/GenieACS.php';

// Ambil settings dari database
$settings_raw = db_fetch_all("SELECT setting_key, setting_value FROM pppoe_settings");
$settings = [];
foreach ($settings_raw as $s) {
    $settings[$s['setting_key']] = $s['setting_value'];
}

$grace = (int)($settings['isolir_grace_days'] ?? 3);
$isoProfile = $settings['isolir_profile'] ?? 'isolir';
$todayTs = time();
$todayDate = date('Y-m-d');

echo "[" . date('Y-m-d H:i:s') . "] Memulai pengecekan auto-isolir...\n";
echo "Grace days: $grace | Hari ini: $todayDate | Profil isolir: $isoProfile\n";

function check_payment($customer_id, $month, $year) {
    $res = db_fetch_one(
        "SELECT COUNT(*) as c FROM pppoe_payments WHERE customer_id = ? AND period_month = ? AND period_year = ? AND midtrans_status NOT IN ('pending','cancel','deny','expire')",
        'iii', [$customer_id, $month, $year]
    );
    return $res && (int)$res['c'] > 0;
}

$routers = db_fetch_all("SELECT * FROM routers WHERE status = 'active'");
$router_apis = [];

$genie_server = db_fetch_one("SELECT * FROM genie_config LIMIT 1");
$genieApi = null;
if ($genie_server) {
    $genieApi = new GenieACS($genie_server['url'], $genie_server['username'], $genie_server['password']);
}

$isolated_count = 0;
$skipped_count = 0;
$error_count = 0;

$customers = db_fetch_all("SELECT * FROM pppoe_customers WHERE status = 'active'");
echo "Total pelanggan aktif: " . count($customers) . "\n";

foreach ($customers as $c) {
    // Lewati jika pelanggan gratis / bebas iuran (Jangan pernah diisolir)
    if ((isset($c['is_free']) && (int)$c['is_free'] === 1) || (float)$c['monthly_price'] <= 0) {
        echo "  [SKIP FREE] {$c['pppoe_username']} (Pelanggan Gratis / Bebas Iuran - Tanpa Isolir)\n";
        $skipped_count++;
        continue;
    }

    $dueDay = (int)$c['due_day'];
    $cid = $c['id'];
    $rid = $c['router_id'];
    
    $m1 = (int)date('n');
    $y1 = (int)date('Y');
    $d1 = min($dueDay, date('t')); 
    $dueDate1Ts = strtotime(sprintf('%04d-%02d-%02d', $y1, $m1, $d1));
    
    $m2 = $m1 - 1;
    $y2 = $y1;
    if ($m2 == 0) { $m2 = 12; $y2--; }
    $d2 = min($dueDay, date('t', strtotime(sprintf('%04d-%02d-01', $y2, $m2))));
    $dueDate2Ts = strtotime(sprintf('%04d-%02d-%02d', $y2, $m2, $d2));
    
    $needs_isolation = false;
    $reason = "";
    
    if ($todayTs >= $dueDate1Ts + ($grace * 86400)) {
        if (!check_payment($cid, $m1, $y1)) {
            $needs_isolation = true;
            $late_days = floor(($todayTs - $dueDate1Ts) / 86400);
            $reason = "Auto-isolir: Menunggak tagihan bulan $m1/$y1 (Terlambat $late_days hari)";
        }
    } elseif ($todayTs >= $dueDate2Ts + ($grace * 86400)) {
        if (!check_payment($cid, $m2, $y2)) {
            $needs_isolation = true;
            $late_days = floor(($todayTs - $dueDate2Ts) / 86400);
            $reason = "Auto-isolir: Menunggak tagihan bulan $m2/$y2 (Terlambat $late_days hari)";
        }
    }
    
    if (!$needs_isolation) {
        $skipped_count++;
        continue;
    }
    
    echo "  >> Mengisolir: {$c['pppoe_username']} ({$c['full_name']}) | Alasan: $reason\n";
    
    try {
        if (!isset($router_apis[$rid])) {
            $r_data = null;
            foreach ($routers as $r) { if ($r['id'] == $rid) $r_data = $r; }
            if ($r_data) {
                $api = new RouterosAPI();
                $api->debug = false;
                if ($api->connect($r_data['ip_address'], $r_data['api_user'], $r_data['api_password'], (int)$r_data['api_port'])) {
                    $router_apis[$rid] = $api;
                } else {
                    $router_apis[$rid] = false;
                }
            } else {
                $router_apis[$rid] = false;
            }
        }
        
        $api = $router_apis[$rid];
        if ($api) {
            $api->comm('/ppp/secret/set', ['?name' => $c['pppoe_username'], '=profile' => $isoProfile]);
            $acts = $api->comm('/ppp/active/print', ['?name' => $c['pppoe_username']]);
            foreach ($acts as $act) {
                if (isset($act['.id'])) {
                    $api->comm('/ppp/active/remove', ['=.id' => $act['.id']]);
                }
            }
        } else {
            throw new Exception("Gagal terhubung ke API Router ID $rid.");
        }
        
        db_execute("UPDATE pppoe_customers SET status = 'isolated', isolated_at = NOW(), isolated_reason = ? WHERE id = ?", 'si', [$reason, $cid]);
        
        if (!empty($c['ont_sn']) && $genieApi) {
            $sn = trim($c['ont_sn']);
            $devices = $genieApi->getDevices('{"_deviceId._SerialNumber": "'.$sn.'"}', '_id');
            if (!empty($devices) && isset($devices[0]['_id'])) {
                $dev_id = $devices[0]['_id'];
                $genieApi->reboot($dev_id);
                echo "     - ONT Reboot terkirim ke $sn ($dev_id)\n";
            }
        }
        
        // Kirim WhatsApp pemberitahuan isolir ke pelanggan
        if (!empty($c['phone'])) {
            try {
                require_once __DIR__ . '/../include/WhatsAppGateway.php';
                $waTmpl = WhatsAppGateway::getTemplate('isolir');
                if ($waTmpl) {
                    $wa = WhatsAppGateway::getInstance();
                    $msgBody = WhatsAppGateway::renderTemplate($waTmpl['message'], [
                        'full_name' => $c['full_name'],
                        'pppoe_username' => $c['pppoe_username'],
                        'monthly_price' => $c['monthly_price'],
                        'due_day' => $c['due_day'],
                        'cs_phone' => $settings['company_phone'] ?? '',
                        'company_name' => $settings['company_name'] ?? (defined('APP_COMPANY') ? APP_COMPANY : 'S.NET Internet'),
                        'link_portal' => 'https://' . ($_SERVER['HTTP_HOST'] ?? 'dash.snetwifi.com') . '/portal/isolir.php?user=' . urlencode($c['pppoe_username'])
                    ]);
                    $wa->send($c['phone'], $msgBody, $cid, 'isolir', $c['full_name']);
                    echo "     - WhatsApp isolir terkirim ke {$c['phone']}\n";
                }
            } catch (Exception $e) {
                echo "     - Gagal kirim WA isolir: " . $e->getMessage() . "\n";
            }
        }
        
        $isolated_count++;
    } catch (Exception $e) {
        echo "  [ERROR] {$c['pppoe_username']}: " . $e->getMessage() . "\n";
        $error_count++;
    }
}

foreach ($router_apis as $api) {
    if ($api) $api->disconnect();
}

echo "\n[" . date('Y-m-d H:i:s') . "] Selesai. Diisolir: $isolated_count | Aman/Skip: $skipped_count | Error: $error_count\n";
