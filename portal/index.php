<?php
session_start();
define('IN_APP',true);
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../include/functions.php';
require_once __DIR__.'/../include/GenieACS.php';
if(empty($_SESSION['csrf_token'])){ $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

if (!isset($_SESSION['portal_customer_id'])) { header('Location: login.php'); exit; }
$cid = $_SESSION['portal_customer_id'];
$msg='';$mtype='ok';

$custRow = db_fetch_one("SELECT * FROM pppoe_customers WHERE id=?", 'i', [$cid]);
if(!$custRow){session_destroy();header('Location: login.php');exit;}
if(!in_array($custRow['status'], ['active', 'isolated'])){session_destroy();header('Location: login.php?err=disabled');exit;}

$genie_server = db_fetch_one("SELECT * FROM genie_config LIMIT 1");
$genie = null;
if ($genie_server) {
    $genie = new GenieACS($genie_server['url'], $genie_server['username'], $genie_server['password']);
}
$devId = null;
$dev=null;$info=[];$wifi=[];$clients=[];$wanList=[];

if($genie && !empty($custRow['ont_sn'])){
    $cleanSn = strtoupper(trim($custRow['ont_sn']));
    $devices = $genie->getDevices(json_encode([
        '$or' => [
            ['_deviceId._SerialNumber' => $cleanSn],
            ['_id' => ['$regex' => $cleanSn, '$options' => 'i']],
            ['InternetGatewayDevice.DeviceInfo.SerialNumber._value' => $cleanSn],
            ['InternetGatewayDevice.DeviceInfo.X_HW_SerialNumber._value' => $cleanSn]
        ]
    ]));
    if (!empty($devices) && isset($devices[0])) {
        $dev = $devices[0];
        $devId = $dev['_id'];
        $info=$genie->getInfo($dev);$wifi=$genie->getWifi($dev);
        $clients=$genie->getClients($dev);$wanList=$genie->getWanList($dev);
    }
}
$online=$info['online']??false;

$curWifiSsid = trim(($wifi['ssid_24'] ?? '') ?: (($wifi['ssid_5g'] ?? '') ?: ($custRow['ont_wifi_ssid'] ?? '')));
$curWifiPass = trim(($wifi['pass_24'] ?? '') ?: (($wifi['pass_5g'] ?? '') ?: ($custRow['ont_wifi_pass'] ?? '')));

if (!empty($curWifiPass) && empty($custRow['ont_wifi_pass'])) {
    db_execute("UPDATE pppoe_customers SET ont_wifi_pass = ? WHERE id = ?", 'si', [$curWifiPass, $cid]);
    $custRow['ont_wifi_pass'] = $curWifiPass;
}
if (!empty($curWifiSsid) && empty($custRow['ont_wifi_ssid'])) {
    db_execute("UPDATE pppoe_customers SET ont_wifi_ssid = ? WHERE id = ?", 'si', [$curWifiSsid, $cid]);
    $custRow['ont_wifi_ssid'] = $curWifiSsid;
}

// Load pengaturan aplikasi & Midtrans
$settings_raw = db_fetch_all("SELECT setting_key, setting_value FROM pppoe_settings");
$pppoe_settings = [];
foreach ($settings_raw as $s) {
    $pppoe_settings[$s['setting_key']] = $s['setting_value'];
}
$midServerKey = $pppoe_settings['midtrans_server_key'] ?? '';
$midClientKey = $pppoe_settings['midtrans_client_key'] ?? '';
$midMode      = $pppoe_settings['midtrans_mode'] ?? 'sandbox';
$companyName  = $pppoe_settings['company_name'] ?? (defined('APP_COMPANY') ? APP_COMPANY : 'S.NET Internet');
$companyPhone = $pppoe_settings['company_phone'] ?? '';
$snapJsUrl    = ($midMode === 'production') ? 'https://app.midtrans.com/snap/snap.js' : 'https://app.sandbox.midtrans.com/snap/snap.js';

if($_SERVER['REQUEST_METHOD']==='POST'){
    $act=$_POST['action']??'';

    // ── PEMBAYARAN TAGIHAN ONLINE VIA MIDTRANS SNAP ──
    if($act==='pay_bill'){
        header('Content-Type: application/json');
        if(!$midServerKey || !$midClientKey){
            echo json_encode(['error'=>'Payment gateway Midtrans belum dikonfigurasi. Silakan hubungi admin.']);
            exit;
        }
        $amount = (int)($_POST['amount'] ?? $custRow['monthly_price']);
        if($amount <= 0){
            echo json_encode(['error'=>'Nominal tagihan tidak valid.']);
            exit;
        }
        $month = (int)($_POST['period_month'] ?? date('n'));
        $year  = (int)($_POST['period_year'] ?? date('Y'));
        $mNames = [1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',5=>'Mei',6=>'Juni',7=>'Juli',8=>'Agustus',9=>'September',10=>'Oktober',11=>'November',12=>'Desember'];
        $mLabel = ($mNames[$month] ?? $month) . " $year";

        $cleanU = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $custRow['pppoe_username']));
        $orderId = 'INV-' . date('Ymd') . '-' . substr($cleanU, 0, 6) . '-' . rand(1000, 9999);

        db_execute(
            "INSERT INTO pppoe_payments (customer_id, amount, payment_method, midtrans_order_id, midtrans_status, period_month, period_year, notes) 
             VALUES (?, ?, 'midtrans', ?, 'pending', ?, ?, ?)",
            'iisiis',
            [$cid, $amount, $orderId, $month, $year, "Bayar Tagihan Portal ($mLabel)"]
        );

        $payload = [
            'transaction_details' => ['order_id' => $orderId, 'gross_amount' => $amount],
            'customer_details' => [
                'first_name' => $custRow['full_name'],
                'phone'      => $custRow['phone'] ?? '',
                'notes'      => 'Tagihan Internet ' . $mLabel
            ],
            'item_details' => [[
                'id'       => 'INET-' . $month . $year,
                'price'    => $amount,
                'quantity' => 1,
                'name'     => 'Tagihan Internet ' . $mLabel . ' (' . $custRow['pppoe_username'] . ')'
            ]],
            'callbacks' => [
                'finish' => 'https://' . ($_SERVER['HTTP_HOST'] ?? 'dash.snetwifi.com') . '/portal/index.php?paid=1&tab=billing'
            ]
        ];

        $snapUrl = ($midMode === 'production') ? 'https://app.midtrans.com/snap/v1/transactions' : 'https://app.sandbox.midtrans.com/snap/v1/transactions';
        $ch = curl_init($snapUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Basic ' . base64_encode($midServerKey . ':')]
        ]);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if($err){
            echo json_encode(['error'=>'Koneksi payment gateway gagal: ' . $err]);
            exit;
        }
        $d = json_decode($res, true);
        if(isset($d['token'])){
            echo json_encode(['token' => $d['token'], 'order_id' => $orderId, 'client_key' => $midClientKey, 'mode' => $midMode]);
        } else {
            echo json_encode(['error' => $d['error_messages'][0] ?? 'Gagal membuat transaksi pembayaran.']);
        }
        exit;
    }

    // ── UBAH WIFI — 1 form: 1 SSID & 1 PASSWORD (berlaku untuk 2.4G & 5G sama) ──
    if($act==='change_wifi'){
        if(!$genie||!$dev){$msg='ONT tidak terhubung.';$mtype='err';}
        else{
            $ssid=trim($_POST['wifi_ssid'] ?? $_POST['ssid_24'] ?? '');
            $pw  =trim($_POST['wifi_pass']??'');
            $errs=[];
            if($ssid&&strlen($ssid)<2)  $errs[]='Nama WiFi terlalu pendek (min. 2 karakter)';
            if($pw&&strlen($pw)<8)      $errs[]='Password minimal 8 karakter';
            if(!$ssid&&!$pw)           $errs[]='Isi minimal satu field (Nama WiFi atau Password)';
            if($errs){$msg=implode('<br>',$errs);$mtype='err';}
            else{
                // Kirim: SSID sama dan Password sama ke 2.4G dan 5G (wifi 2.4 = 5)
                $ok=$genie->setWifi($devId,$dev,
                    $ssid?:null, $pw?:null,   // 2.4G SSID & pass
                    $ssid?:null, $pw?:null,   // 5G SSID & pass (sama persis!)
                    true  // samePass flag
                );
                if($ok){
                    // Simpan juga ke database pppoe_customers
                    if ($ssid && $pw) {
                        db_execute("UPDATE pppoe_customers SET ont_wifi_ssid = ?, ont_wifi_pass = ? WHERE id = ?", 'ssi', [$ssid, $pw, $cid]);
                    } elseif ($ssid) {
                        db_execute("UPDATE pppoe_customers SET ont_wifi_ssid = ? WHERE id = ?", 'si', [$ssid, $cid]);
                    } elseif ($pw) {
                        db_execute("UPDATE pppoe_customers SET ont_wifi_pass = ? WHERE id = ?", 'si', [$pw, $cid]);
                    }
                    $msg='✅ WiFi berhasil diperbarui! Nama dan Password diterapkan untuk 2.4 GHz dan 5 GHz.';$mtype='ok';
                    sleep(1);$genie->refresh($devId);
                    $dev=$genie->getDevice($devId);
                    if($dev){$wifi=$genie->getWifi($dev);$clients=$genie->getClients($dev);}
                }else{$msg='❌ Gagal: '.$genie->error;$mtype='err';}
            }
        }
    }

    if($act==='block_client'){
        $mac=strtoupper(trim($_POST['mac']??''));
        if($genie&&$dev&&$mac){
            // Refresh data MACFilter dari ONT agar count akurat
            $genie->refreshMACFilter($devId);
            sleep(1);
            $dev=$genie->getDevice($devId);
            $ok=$genie->blockClient($devId,$dev,$mac);
            $msg=$ok?"✅ Perangkat $mac diblokir!":'❌ Gagal: '.$genie->error;
            $mtype=$ok?'ok':'err';
            // Refresh tampilan clients
            if($ok){sleep(1);$dev=$genie->getDevice($devId);if($dev)$clients=$genie->getClients($dev);}
        }
    }
    if($act==='unblock_client'){
        $mac=strtoupper(trim($_POST['mac']??''));
        if($genie&&$dev&&$mac){
            // Refresh data MACFilter dari ONT agar data slot akurat
            $genie->refreshMACFilter($devId);
            sleep(1);
            $dev=$genie->getDevice($devId);
            $ok=$genie->unblockClient($devId,$dev,$mac);
            $msg=$ok?"✅ Perangkat $mac berhasil diunblokir!":'❌ Gagal: '.$genie->error;
            $mtype=$ok?'ok':'err';
        }
    }
    if($act==='reboot'){
        if($genie&&$devId){$ok=$genie->reboot($devId);$msg=$ok?'✅ Perintah reboot dikirim. ONT akan restart dalam 1-2 menit.':'❌ Gagal reboot.';$mtype=$ok?'ok':'err';}
    }
    if($act==='refresh'){
        if($genie&&$devId){$genie->refresh($devId);sleep(2);$dev=$genie->getDevice($devId);if($dev){$info=$genie->getInfo($dev);$wifi=$genie->getWifi($dev);$clients=$genie->getClients($dev);}$msg='✅ Data ONT diperbarui.';$mtype='ok';}
    }
    if($act==='change_pass'){
        $oldPassValid = (!empty($custRow['portal_password']) && password_verify($old, $custRow['portal_password']))
                     || (!empty($custRow['portal_password_plain']) && $old === $custRow['portal_password_plain'])
                     || (!empty($custRow['portal_password']) && $old === $custRow['portal_password']);
        if(!$oldPassValid){$msg='Password lama salah!';$mtype='err';}
        elseif(strlen($new)<4){$msg='Password baru minimal 4 karakter!';$mtype='err';}
        elseif($new!==$cf){$msg='Konfirmasi tidak cocok!';$mtype='err';}
        else{
            db_execute("UPDATE pppoe_customers SET portal_password=?, portal_password_plain=? WHERE id=?", 'ssi', [password_hash($new,PASSWORD_DEFAULT), $new, $cid]);
            $msg='✅ Password portal berhasil diubah!';$mtype='ok';
            $custRow['portal_password'] = password_hash($new,PASSWORD_DEFAULT);
            $custRow['portal_password_plain'] = $new;
        }
    }
}

// ── PENGECEKAN CALLBACK PEMBAYARAN DARI MIDTRANS SNAP ──
if (isset($_GET['paid']) && $_GET['paid'] === '1') {
    $latestPayment = db_fetch_one("SELECT * FROM pppoe_payments WHERE customer_id = ? ORDER BY id DESC LIMIT 1", 'i', [$cid]);
    if ($latestPayment) {
        if ($latestPayment['midtrans_status'] === 'pending' && !empty($latestPayment['midtrans_order_id'])) {
            $st = check_midtrans_order_status($latestPayment['midtrans_order_id']);
            if ($st && in_array($st['transaction_status'] ?? '', ['settlement', 'capture']) && in_array($st['fraud_status'] ?? '', ['accept', ''])) {
                db_execute("UPDATE pppoe_payments SET midtrans_status='paid', midtrans_tx_id=? WHERE id=?", 'si', [$st['transaction_id'] ?? '', $latestPayment['id']]);
                $latestPayment['midtrans_status'] = 'paid';
            }
        }
        if ($latestPayment['midtrans_status'] === 'paid' || $latestPayment['payment_method'] === 'cash') {
            if ($custRow['status'] === 'isolated') {
                unisolir_pppoe_customer($cid);
            }
            send_pppoe_payment_notification((int)$latestPayment['id'], 'Sistem Online (Midtrans)');
            $msg = '✅ Pembayaran Berhasil! Tagihan Anda telah lunas dan bukti pembayaran telah dikirim ke WhatsApp.';
            $mtype = 'ok';
        } else {
            $msg = 'Status pembayaran Anda saat ini: ' . htmlspecialchars(ucfirst($latestPayment['midtrans_status']));
            $mtype = 'wrn';
        }
    } else {
        if ($custRow['status'] === 'isolated') {
            unisolir_pppoe_customer($cid);
        }
        $msg = '✅ Pembayaran Berhasil! Layanan internet Anda telah aktif.';
        $mtype = 'ok';
    }
    // Refresh custRow
    $custRow = db_fetch_one("SELECT * FROM pppoe_customers WHERE id=?", 'i', [$cid]);
}

// ── PERHITUNGAN STATUS TAGIHAN & TUNGGAKAN ──
$mNow = (int)date('n');
$yNow = (int)date('Y');
$dNow = (int)date('j');
$dueDay = (int)($custRow['due_day'] ?? 1);
$monthlyPrice = (float)($custRow['monthly_price'] ?? 0);
$isFree = (!empty($custRow['is_free']) || $monthlyPrice <= 0);

$mNames = [1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',5=>'Mei',6=>'Juni',7=>'Juli',8=>'Agustus',9=>'September',10=>'Oktober',11=>'November',12=>'Desember'];

// Pembayaran lunas bulan ini
$payThisMonth = db_fetch_one(
    "SELECT * FROM pppoe_payments 
     WHERE customer_id = ? 
       AND period_month = ? 
       AND period_year = ? 
       AND (midtrans_status = 'paid' OR payment_method = 'cash' OR (midtrans_status NOT IN ('pending','cancel','deny','expire') AND midtrans_status IS NOT NULL))
     ORDER BY id DESC LIMIT 1",
    'iii', [$cid, $mNow, $yNow]
);
$isPaidThisMonth = ($payThisMonth !== null);

// Pembayaran lunas bulan lalu
$mPrev = $mNow - 1;
$yPrev = $yNow;
if ($mPrev === 0) { $mPrev = 12; $yPrev--; }
$payPrevMonth = db_fetch_one(
    "SELECT * FROM pppoe_payments 
     WHERE customer_id = ? 
       AND period_month = ? 
       AND period_year = ? 
       AND (midtrans_status = 'paid' OR payment_method = 'cash' OR (midtrans_status NOT IN ('pending','cancel','deny','expire') AND midtrans_status IS NOT NULL))
     ORDER BY id DESC LIMIT 1",
    'iii', [$cid, $mPrev, $yPrev]
);
$isPaidPrevMonth = ($payPrevMonth !== null);

// Susun daftar tagihan yang belum lunas
$unpaidBills = [];
if (!$isFree) {
    if (!$isPaidPrevMonth) {
        $unpaidBills[] = [
            'month'       => $mPrev,
            'year'        => $yPrev,
            'label'       => ($mNames[$mPrev] ?? $mPrev) . " $yPrev",
            'amount'      => $monthlyPrice,
            'is_arrear'   => true,
            'status_text' => 'Menunggak (Bulan Lalu)'
        ];
    }
    if (!$isPaidThisMonth) {
        $isLate = ($dNow >= $dueDay);
        $unpaidBills[] = [
            'month'       => $mNow,
            'year'        => $yNow,
            'label'       => ($mNames[$mNow] ?? $mNow) . " $yNow",
            'amount'      => $monthlyPrice,
            'is_arrear'   => $isLate,
            'status_text' => $isLate ? 'Lewat Jatuh Tempo' : 'Tagihan Berjalan'
        ];
    }
}

$totalUnpaid = 0;
foreach ($unpaidBills as $b) {
    $totalUnpaid += $b['amount'];
}
$hasUnpaid = ($totalUnpaid > 0);

// Riwayat pembayaran pelanggan
$paymentHistory = db_fetch_all(
    "SELECT * FROM pppoe_payments WHERE customer_id = ? ORDER BY paid_at DESC, id DESC LIMIT 10",
    'i', [$cid]
);

// ── PEMAKAIAN DATA PELANGGAN DARI RADIUS (radacct) ──
$portalUsageCurr = [
    'dl'       => 0,
    'ul'       => 0,
    'total'    => 0,
    'secs'     => 0,
    'sessions' => 0
];
$portalUsageHistory = [];
$portalLiveSession = null;

if (!empty($custRow['pppoe_username'])) {
    $uPpp = trim($custRow['pppoe_username']);
    $uClean = preg_replace('/@.*$/', '', $uPpp);
    $uPattern = $uClean . '@%';
    
    // Pemakaian Bulan Berjalan dari FreeRADIUS (radacct)
    try {
        $rowCurr = db_fetch_one(
            "SELECT COALESCE(SUM(CASE WHEN (acctstoptime IS NOT NULL AND acctstoptime != '0000-00-00 00:00:00' AND acctstoptime != '') THEN acctoutputoctets ELSE 0 END), 0) AS closed_dl,
                    COALESCE(SUM(CASE WHEN (acctstoptime IS NOT NULL AND acctstoptime != '0000-00-00 00:00:00' AND acctstoptime != '') THEN acctinputoctets ELSE 0 END), 0) AS closed_ul,
                    COALESCE(SUM(CASE WHEN (acctstoptime IS NULL OR acctstoptime = '0000-00-00 00:00:00' OR acctstoptime = '') THEN acctoutputoctets ELSE 0 END), 0) AS active_dl,
                    COALESCE(SUM(CASE WHEN (acctstoptime IS NULL OR acctstoptime = '0000-00-00 00:00:00' OR acctstoptime = '') THEN acctinputoctets ELSE 0 END), 0) AS active_ul,
                    COALESCE(SUM(acctoutputoctets), 0) AS dl_bytes,
                    COALESCE(SUM(acctinputoctets), 0) AS ul_bytes,
                    COALESCE(SUM(acctoutputoctets + acctinputoctets), 0) AS total_bytes,
                    COALESCE(SUM(acctsessiontime), 0) AS total_secs,
                    COUNT(*) AS session_count
             FROM radacct
             WHERE (username = ? OR username = ? OR username LIKE ?)
               AND ((YEAR(acctstarttime) = YEAR(CURDATE()) AND MONTH(acctstarttime) = MONTH(CURDATE()))
                    OR (acctstoptime IS NULL OR acctstoptime = '0000-00-00 00:00:00' OR acctstoptime = ''))",
            'sss', [$uPpp, $uClean, $uPattern]
        );
        if ($rowCurr) {
            $portalUsageCurr = [
                'closed_dl' => (float)$rowCurr['closed_dl'],
                'closed_ul' => (float)$rowCurr['closed_ul'],
                'active_dl' => (float)$rowCurr['active_dl'],
                'active_ul' => (float)$rowCurr['active_ul'],
                'dl'        => (float)$rowCurr['dl_bytes'],
                'ul'        => (float)$rowCurr['ul_bytes'],
                'total'     => (float)$rowCurr['total_bytes'],
                'secs'      => (int)$rowCurr['total_secs'],
                'sessions'  => (int)$rowCurr['session_count']
            ];
        }
    } catch (Throwable $e) {}
    
    // Riwayat Pemakaian 6 Bulan Terakhir dari FreeRADIUS
    try {
        $historyRows = db_fetch_all(
            "SELECT DATE_FORMAT(acctstarttime, '%Y-%m') AS ym,
                    YEAR(acctstarttime) AS yr,
                    MONTH(acctstarttime) AS mo,
                    COALESCE(SUM(acctoutputoctets), 0) AS dl_bytes,
                    COALESCE(SUM(acctinputoctets), 0) AS ul_bytes,
                    COALESCE(SUM(acctoutputoctets + acctinputoctets), 0) AS total_bytes,
                    COALESCE(SUM(acctsessiontime), 0) AS total_secs,
                    COUNT(*) AS session_count
             FROM radacct
             WHERE (username = ? OR username = ? OR username LIKE ?)
               AND acctstarttime >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
             GROUP BY DATE_FORMAT(acctstarttime, '%Y-%m'), YEAR(acctstarttime), MONTH(acctstarttime)
             ORDER BY ym DESC",
            'sss', [$uPpp, $uClean, $uPattern]
        );
        if (is_array($historyRows)) {
            $portalUsageHistory = $historyRows;
        }
    } catch (Throwable $e) {}
    
    // Cek Sesi Aktif Saat Ini di FreeRADIUS
    try {
        $portalLiveSession = db_fetch_one(
            "SELECT framedipaddress, acctstarttime, acctsessiontime, acctoutputoctets, acctinputoctets
             FROM radacct
             WHERE (username = ? OR username = ? OR username LIKE ?)
               AND (acctstoptime IS NULL OR acctstoptime = '0000-00-00 00:00:00' OR acctstoptime = '')
             ORDER BY radacctid DESC LIMIT 1",
            'sss', [$uPpp, $uClean, $uPattern]
        );
    } catch (Throwable $e) {}

    // ── Cek Real-Time Tx & Rx Byte Langsung dari Interface MikroTik (<pppoe-username>) ──
    $portalMikrotikTraffic = null;
    $portalMikrotikActiveSession = null;
    $cRouter = null;

    // 1. Dapatkan router pelanggan (dengan fallback aman jika router_id kosong)
    if (!empty($custRow['router_id'])) {
        try {
            $cRouter = db_fetch_one("SELECT * FROM routers WHERE id = ? AND (status = 'active' OR status IS NULL OR status = '') LIMIT 1", 'i', [$custRow['router_id']]);
            if (!$cRouter) {
                $cRouter = db_fetch_one("SELECT * FROM routers WHERE id = ? LIMIT 1", 'i', [$custRow['router_id']]);
            }
        } catch (Throwable $e) {}
    }
    if (!$cRouter) {
        try {
            $cRouter = db_fetch_one("SELECT * FROM routers WHERE status = 'active' ORDER BY id ASC LIMIT 1");
            if (!$cRouter) {
                $cRouter = db_fetch_one("SELECT * FROM routers ORDER BY id ASC LIMIT 1");
            }
        } catch (Throwable $e) {}
    }

    if ($cRouter) {
        try {
            require_once __DIR__ . '/../lib/routeros_api.class.php';
            $mtApi = new RouterosAPI();
            $mtApi->debug = false;
            $mtApi->timeout = 3.0; // 3 detik agar stabil via VPN / WireGuard
            $mtApi->attempts = 1;
            $mtApi->delay = 0;
            
            $apiPort = !empty($cRouter['api_port']) ? (int)$cRouter['api_port'] : 8728;
            if ($mtApi->connect($cRouter['ip_address'], $cRouter['api_user'], $cRouter['api_password'], $apiPort)) {
                $uTarget = strtolower(trim($uPpp));
                $uTargetClean = strtolower(trim($uClean));
                
                // A. Ambil Interface Traffic Real-time dari MikroTik
                // Ambil daftar interface tanpa filter query '?name' karena karakter '<' merusak parser RouterOS API
                $ifaces = $mtApi->comm('/interface/print', [
                    '.proplist' => 'name,type,tx-byte,rx-byte,bytes,running'
                ]);
                
                if (is_array($ifaces)) {
                    foreach ($ifaces as $if) {
                        $ifName = trim($if['name'] ?? '');
                        $ifLower = strtolower($ifName);
                        
                        // Periksa kecocokan nama interface: <pppoe-username>, <pppoe-username@...>, pppoe-username, dll
                        $isMatch = false;
                        if ($ifLower === "<pppoe-{$uTarget}>" || $ifLower === "<pppoe-{$uTargetClean}>" ||
                            $ifLower === "pppoe-{$uTarget}"   || $ifLower === "pppoe-{$uTargetClean}") {
                            $isMatch = true;
                        } elseif (preg_match('/^<?pppoe-' . preg_quote($uTargetClean, '/') . '(@.*)?>?$/i', $ifName)) {
                            $isMatch = true;
                        } elseif (strpos($ifLower, 'pppoe-') !== false && strpos($ifLower, $uTargetClean) !== false) {
                            $isMatch = true;
                        }
                        
                        if ($isMatch) {
                            $liveTx = (float)($if['tx-byte'] ?? $if['tx_byte'] ?? 0);
                            $liveRx = (float)($if['rx-byte'] ?? $if['rx_byte'] ?? 0);
                            if ($liveTx === 0.0 && $liveRx === 0.0 && !empty($if['bytes'])) {
                                $bParts = explode('/', (string)$if['bytes']);
                                if (count($bParts) === 2) {
                                    $liveRx = (float)trim($bParts[0]);
                                    $liveTx = (float)trim($bParts[1]);
                                }
                            }
                            $liveTot = $liveTx + $liveRx; // Total Pemakaian = Tx Byte + Rx Byte
                            $portalMikrotikTraffic = [
                                'tx'        => $liveTx,
                                'rx'        => $liveRx,
                                'total'     => $liveTot,
                                'tx_fmt'    => format_bytes($liveTx),
                                'rx_fmt'    => format_bytes($liveRx),
                                'total_fmt' => format_bytes($liveTot),
                                'ifname'    => $ifName,
                            ];
                            break;
                        }
                    }
                }
                
                // B. Cek Sesi Aktif di /ppp/active (IP address & Uptime) langsung dari MikroTik
                $pppActs = $mtApi->comm('/ppp/active/print');
                if (is_array($pppActs)) {
                    foreach ($pppActs as $pa) {
                        $paName = strtolower(trim($pa['name'] ?? ''));
                        if ($paName === $uTarget || $paName === $uTargetClean || strpos($paName, $uTargetClean) === 0) {
                            $portalMikrotikActiveSession = [
                                'address'   => $pa['address'] ?? '',
                                'uptime'    => $pa['uptime'] ?? '',
                                'caller_id' => $pa['caller-id'] ?? '',
                                'service'   => $pa['service'] ?? 'pppoe',
                            ];
                            break;
                        }
                    }
                }
                
                $mtApi->disconnect();
            }
        } catch (Throwable $e) {}
    }

    // ── SNAPSHOT: Ambil akumulasi committed dari tabel snapshot (tahan restart) ──
    // Snapshot di-update oleh cron setiap 5 menit.
    // committed_dl/ul = total bytes yang sudah dikonfirmasi (termasuk sesi sebelum restart)
    // last_if_tx/rx   = counter interface saat snapshot terakhir
    $portalSnap = null;
    $snapCommittedDl = 0.0;
    $snapCommittedUl = 0.0;
    $snapLastIfTx    = 0.0;
    $snapLastIfRx    = 0.0;
    try {
        if ($cRouter) {
            $portalSnap = db_fetch_one(
                "SELECT committed_dl, committed_ul, last_if_tx, last_if_rx
                 FROM pppoe_bandwidth_snapshots
                 WHERE router_id = ? AND username = ? AND month_year = ?",
                'iss', [(int)$cRouter['id'], $uPpp, date('Y-m')]
            );
            if (!$portalSnap) {
                // Coba dengan username bersih
                $portalSnap = db_fetch_one(
                    "SELECT committed_dl, committed_ul, last_if_tx, last_if_rx
                     FROM pppoe_bandwidth_snapshots
                     WHERE router_id = ? AND username = ? AND month_year = ?",
                    'iss', [(int)$cRouter['id'], $uClean, date('Y-m')]
                );
            }
        }
    } catch (Throwable $e) {}

    if ($portalSnap) {
        $snapCommittedDl = (float)$portalSnap['committed_dl'];
        $snapCommittedUl = (float)$portalSnap['committed_ul'];
        $snapLastIfTx    = (float)$portalSnap['last_if_tx'];
        $snapLastIfRx    = (float)$portalSnap['last_if_rx'];
    }

    // ── Sinkronisasi data real-time MikroTik ke Pemakaian Bulan Berjalan ──
    // Formula tahan restart:
    //   total = committed_snapshot + delta_live
    //   delta_live = live_counter - last_if (jika live >= last_if)
    //              = live_counter           (jika reset: live < last_if)
    if ($portalMikrotikTraffic) {
        $liveTxNow = $portalMikrotikTraffic['tx'];
        $liveRxNow = $portalMikrotikTraffic['rx'];

        if ($portalSnap) {
            // Hitung delta sejak checkpoint terakhir
            $deltaTx = ($liveTxNow >= $snapLastIfTx) ? ($liveTxNow - $snapLastIfTx) : $liveTxNow;
            $deltaRx = ($liveRxNow >= $snapLastIfRx) ? ($liveRxNow - $snapLastIfRx) : $liveRxNow;

            $finalDl = $snapCommittedDl + $deltaTx;
            $finalUl = $snapCommittedUl + $deltaRx;
        } else {
            // Belum ada snapshot — gunakan logika lama (radacct closed + live aktif)
            $actDl = max((float)($portalUsageCurr['active_dl'] ?? 0), $liveTxNow);
            $actUl = max((float)($portalUsageCurr['active_ul'] ?? 0), $liveRxNow);
            $finalDl = (float)($portalUsageCurr['closed_dl'] ?? 0) + $actDl;
            $finalUl = (float)($portalUsageCurr['closed_ul'] ?? 0) + $actUl;
        }

        $portalUsageCurr['dl']    = $finalDl;
        $portalUsageCurr['ul']    = $finalUl;
        $portalUsageCurr['total'] = $finalDl + $finalUl;

        $portalUsageCurr['sessions'] = max(1, (int)$portalUsageCurr['sessions']);

        if ($portalUsageCurr['secs'] <= 0 && !empty($portalMikrotikActiveSession['uptime'])) {
            $portalUsageCurr['secs'] = parse_mikrotik_uptime_to_seconds($portalMikrotikActiveSession['uptime']);
        }
    } elseif ($portalSnap) {
        // Tidak ada live MikroTik (offline?) tapi ada snapshot — tampilkan snapshot committed
        // delta = 0 karena interface tidak aktif
        $portalUsageCurr['dl']    = max($snapCommittedDl, (float)($portalUsageCurr['closed_dl'] ?? 0));
        $portalUsageCurr['ul']    = max($snapCommittedUl, (float)($portalUsageCurr['closed_ul'] ?? 0));
        $portalUsageCurr['total'] = $portalUsageCurr['dl'] + $portalUsageCurr['ul'];
    } else {
        // Fallback: radacct saja (closed + active)
        $portalUsageCurr['dl']    = (float)($portalUsageCurr['closed_dl'] ?? 0) + (float)($portalUsageCurr['active_dl'] ?? 0);
        $portalUsageCurr['ul']    = (float)($portalUsageCurr['closed_ul'] ?? 0) + (float)($portalUsageCurr['active_ul'] ?? 0);
        $portalUsageCurr['total'] = $portalUsageCurr['dl'] + $portalUsageCurr['ul'];
    }

    // Jika radacct tidak ada sesi aktif, tapi ada sesi aktif di MikroTik, buat portalLiveSession virtual
    if (!$portalLiveSession && ($portalMikrotikActiveSession || $portalMikrotikTraffic)) {
        $upSecs = !empty($portalMikrotikActiveSession['uptime']) ? parse_mikrotik_uptime_to_seconds($portalMikrotikActiveSession['uptime']) : 0;
        $portalLiveSession = [
            'framedipaddress'  => $portalMikrotikActiveSession['address'] ?? '',
            'acctstarttime'    => '',
            'acctsessiontime'  => $upSecs,
            'uptime_raw'       => $portalMikrotikActiveSession['uptime'] ?? '',
            'acctoutputoctets' => $portalMikrotikTraffic ? $portalMikrotikTraffic['tx'] : 0,
            'acctinputoctets'  => $portalMikrotikTraffic ? $portalMikrotikTraffic['rx'] : 0,
            'source'           => 'mikrotik'
        ];
    }

    // Jika riwayat pemakaian kosong tapi bulan ini ada pemakaian, sediakan entri bulan ini
    if (empty($portalUsageHistory) && $portalUsageCurr['total'] > 0) {
        $portalUsageHistory[] = [
            'ym'            => date('Y-m'),
            'yr'            => (int)date('Y'),
            'mo'            => (int)date('n'),
            'dl_bytes'      => $portalUsageCurr['dl'],
            'ul_bytes'      => $portalUsageCurr['ul'],
            'total_bytes'   => $portalUsageCurr['total'],
            'total_secs'    => $portalUsageCurr['secs'],
            'session_count' => $portalUsageCurr['sessions']
        ];
    }
}

$logo=logoB64();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Portal — <?=h($custRow['full_name'])?></title>
<link href="https://fonts.googleapis.com/css2?family=Exo+2:wght@400;600;700;800;900&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet">
<script>
(function() {
    const t = localStorage.getItem('snet-portal-theme') || 'light';
    if (t === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
})();
</script>
<?php if($midClientKey):?>
<script src="<?=$snapJsUrl?>" data-client-key="<?=h($midClientKey)?>"></script>
<?php endif;?>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--red:#D42B2B;--red-d:#A51C1C;--blue:#1B3FA6;--blue-d:#122B7A;--green:#16A34A;--green-d:#15803D;--orange:#D97706;--purple:#7C3AED;--g50:#F8FAFF;--g100:#F0F3FA;--g200:#E0E6F5;--g400:#8A95B8;--g600:#5A6490;--g700:#3A4468;--g900:#1A2040}
html,body{font-family:'Exo 2',sans-serif;min-height:100vh;background:var(--g50);color:var(--g700);width:100%;max-width:100vw;overflow-x:hidden;-webkit-text-size-adjust:100%}
.hdr{background:linear-gradient(135deg,var(--blue-d),var(--blue) 65%,#5B0000);padding:0 14px;height:54px;display:flex;align-items:center;gap:10px;position:sticky;top:0;z-index:100;box-shadow:0 2px 12px rgba(18,43,122,.4);width:100%;max-width:100vw;box-sizing:border-box}
.hdr::after{content:'';position:absolute;bottom:0;left:0;right:0;height:3px;background:linear-gradient(90deg,var(--red),#F23535,var(--blue))}
.h-logo{height:34px;object-fit:contain;background:#fff;padding:2px 8px;border-radius:6px;box-shadow:0 2px 8px rgba(0,0,0,.15);flex-shrink:0}
.h-n{color:#fff;font-weight:700;font-size:.86rem;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.h-id{color:rgba(255,255,255,.6);font-size:.68rem;font-family:'JetBrains Mono',monospace;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.h-lo{background:rgba(212,43,43,.2);border:1px solid rgba(212,43,43,.4);color:#FFB3B3;padding:5px 10px;border-radius:7px;font-size:.73rem;font-weight:600;text-decoration:none;transition:.2s;white-space:nowrap;flex-shrink:0}
.h-lo:hover{background:var(--red);color:#fff}
.wrap{max-width:820px;width:100%;margin:0 auto;padding:14px 12px 30px;box-sizing:border-box}
.sbar{background:#fff;border-radius:11px;padding:12px 14px;margin-bottom:14px;border:1px solid var(--g200);box-shadow:0 1px 6px rgba(27,63,166,.07);display:flex;align-items:center;gap:10px;flex-wrap:wrap;box-sizing:border-box}
.sdot{width:11px;height:11px;border-radius:50%;flex-shrink:0}
.sdot.on{background:#22C55E;box-shadow:0 0 0 4px rgba(34,197,94,.2);animation:dp 2s infinite}
.sdot.off{background:#EF4444}
@keyframes dp{0%,100%{opacity:1}50%{opacity:.5}}
.card{background:#fff;border-radius:12px;border:1px solid var(--g200);box-shadow:0 1px 6px rgba(27,63,166,.06);overflow:hidden;margin-bottom:14px;width:100%;box-sizing:border-box}
.ch{padding:11px 15px;border-bottom:1px solid var(--g200);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:6px}
.ct{font-size:.87rem;font-weight:700;color:var(--g900)}
.cb{padding:15px}
.fl{display:block;font-size:.69rem;font-weight:700;color:var(--g700);text-transform:uppercase;letter-spacing:.9px;margin-bottom:4px}
.fc{width:100%;padding:9px 12px;border:2px solid var(--g200);border-radius:9px;font-family:'Exo 2',sans-serif;font-size:.88rem;color:var(--g700);background:var(--g50);outline:none;transition:.2s;box-sizing:border-box}
.fc:focus{border-color:var(--blue);background:#fff;box-shadow:0 0 0 3px rgba(27,63,166,.08)}
.fg{margin-bottom:11px}
.fhint{font-size:.68rem;color:var(--g400);margin-top:3px}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:5px;padding:7px 14px;border-radius:9px;font-family:'Exo 2',sans-serif;font-size:.81rem;font-weight:600;cursor:pointer;border:none;transition:.2s;text-decoration:none;white-space:nowrap}
.btn-p{background:linear-gradient(135deg,var(--blue),var(--blue-d));color:#fff;box-shadow:0 3px 8px rgba(27,63,166,.25)}
.btn-p:hover{transform:translateY(-1px);box-shadow:0 5px 12px rgba(27,63,166,.35)}
.btn-d{background:linear-gradient(135deg,var(--red),var(--red-d));color:#fff}
.btn-d:hover{transform:translateY(-1px)}
.btn-o{background:transparent;border:2px solid var(--g200);color:var(--g600)}
.btn-o:hover{border-color:var(--blue);color:var(--blue)}
.btn-sm{padding:4px 10px;font-size:.72rem;border-radius:6px}
.btn-full{width:100%;justify-content:center;padding:11px}
.alert{padding:10px 14px;border-radius:9px;font-size:.82rem;font-weight:500;margin-bottom:13px;line-height:1.5;box-sizing:border-box}
.aok{background:#DCFCE7;border-left:4px solid #16A34A;color:#15803D}
.aerr{background:#FEE2E2;border-left:4px solid var(--red);color:var(--red-d)}
.ainf{background:#DBEAFE;border-left:4px solid var(--blue);color:var(--blue-d)}
.awrn{background:#FEF3C7;border-left:4px solid var(--orange);color:#92400E}

/* Navigation Tabs */
.tabnav{display:flex;gap:4px;border-bottom:2px solid var(--g200);margin-bottom:14px}
.tab{padding:9px 14px;border:none;background:none;border-radius:8px 8px 0 0;font-family:'Exo 2',sans-serif;font-size:.82rem;font-weight:600;cursor:pointer;color:var(--g400);white-space:nowrap;border-bottom:3px solid transparent;margin-bottom:-2px;transition:.2s;display:inline-flex;align-items:center;justify-content:center;gap:6px;position:relative}
.tab:hover{color:var(--blue)}
.tab.on{color:var(--blue);border-bottom-color:var(--blue);background:var(--g50);font-weight:700}
.tab-badge-dot{position:absolute;top:4px;right:6px;width:8px;height:8px;border-radius:50%;background:var(--red);box-shadow:0 0 0 2px #fff;animation:dp 2s infinite}

/* Responsive Mobile Navigation (< 680px) */
@media(max-width:680px){
    .tabnav{display:grid;grid-template-columns:repeat(auto-fit,minmax(48px,1fr));gap:3px;background:#EEF2FA;padding:3px;border-radius:12px;border-bottom:none;margin-bottom:14px}
    .tab{padding:7px 2px;border-radius:8px;border-bottom:none;margin-bottom:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;color:var(--g600);white-space:normal;line-height:1.15;text-align:center;min-height:48px}
    .tab.on{background:#fff;color:var(--blue-d);box-shadow:0 2px 6px rgba(27,63,166,.14);border-bottom:none}
    .tab .t-icon{font-size:1.15rem;line-height:1;display:block}
    .tab .t-txt{font-size:.65rem;font-weight:700;display:block;white-space:nowrap;letter-spacing:-0.2px}
    .tab-badge-dot{top:3px;right:calc(50% - 13px)}
}

/* Pemakaian Data & Kuota Styles */
.usage-hero {
    background: linear-gradient(135deg, #1E3A8A 0%, #2563EB 50%, #4F46E5 100%);
    color: #fff;
    border-radius: 14px;
    padding: 18px 20px;
    margin-bottom: 14px;
    box-shadow: 0 4px 16px rgba(37,99,235,.22);
    position: relative;
    overflow: hidden;
}
.usage-hero::after {
    content: '';
    position: absolute;
    right: -20px;
    bottom: -20px;
    width: 140px;
    height: 140px;
    background: radial-gradient(circle, rgba(255,255,255,.15) 0%, transparent 70%);
    border-radius: 50%;
    pointer-events: none;
}
.usage-hero-title { font-size: .75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; opacity: .88; margin-bottom: 4px; }
.usage-hero-amount { font-size: 2.2rem; font-weight: 900; font-family: 'JetBrains Mono', monospace; line-height: 1.1; }
.usage-hero-badge { display: inline-flex; align-items: center; gap: 6px; background: rgba(255,255,255,.2); backdrop-filter: blur(4px); padding: 4px 10px; border-radius: 20px; font-size: .72rem; font-weight: 700; margin-top: 8px; flex-wrap: wrap; }
.usage-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 10px; margin-top: 14px; }
.u-box { background: rgba(255,255,255,.13); backdrop-filter: blur(6px); border-radius: 10px; padding: 10px 12px; border: 1px solid rgba(255,255,255,.2); }
.u-box-l { font-size: .67rem; text-transform: uppercase; opacity: .85; font-weight: 700; letter-spacing: .5px; }
.u-box-v { font-size: 1.12rem; font-weight: 800; font-family: 'JetBrains Mono', monospace; margin-top: 3px; }
.u-box-sub { font-size: .67rem; opacity: .75; margin-top: 2px; }

.usage-quick-bar {
    background: #EFF6FF;
    border: 1px solid #BFDBFE;
    border-radius: 11px;
    padding: 10px 14px;
    margin-bottom: 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 8px;
    cursor: pointer;
    transition: .15s;
}
.usage-quick-bar:hover { background: #DBEAFE; transform: translateY(-1px); }

.live-session-card {
    background: #F0FDF4;
    border: 1px solid #BBF7D0;
    border-radius: 11px;
    padding: 11px 14px;
    margin-bottom: 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 8px;
}

.tp{display:none}.tp.on{display:block}
.pass-grid{display:grid;grid-template-columns:1fr 1fr;gap:11px}
@media(max-width:560px){.pass-grid{grid-template-columns:1fr;gap:0}}
.mo{display:none;position:fixed;inset:0;background:rgba(18,43,122,.5);z-index:2000;align-items:center;justify-content:center;backdrop-filter:blur(3px);padding:12px}
.mo.show{display:flex}
.md{background:#fff;border-radius:14px;width:100%;max-width:400px;box-shadow:0 20px 60px rgba(18,43,122,.3);animation:mIn .2s ease}
@keyframes mIn{from{opacity:0;transform:scale(.94) translateY(-12px)}to{opacity:1;transform:none}}
.mh{padding:13px 16px;border-bottom:1px solid var(--g200);display:flex;align-items:center;justify-content:space-between}
.mt{font-size:.88rem;font-weight:800;color:var(--g900)}
.mx{width:24px;height:24px;border-radius:6px;border:none;background:var(--g100);color:var(--g600);cursor:pointer;font-size:.8rem;display:flex;align-items:center;justify-content:center}
.mx:hover{color:var(--red)}
.mb{padding:16px}.mf{padding:11px 16px;border-top:1px solid var(--g200);display:flex;gap:8px;justify-content:flex-end}

/* Unified WiFi card */
.wfc-cur{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px}
.wfb{background:#fff;border-radius:11px;padding:13px 15px;border:1px solid var(--g200);box-shadow:0 1px 5px rgba(27,63,166,.06)}
.wfb-band{font-size:.63rem;font-weight:800;text-transform:uppercase;letter-spacing:1.2px;margin-bottom:4px}
.wfb-ssid{font-size:1rem;font-weight:800;color:var(--g900);word-break:break-all;margin-bottom:4px}
.wfb-pass{display:flex;align-items:center;gap:5px;font-family:'JetBrains Mono',monospace;font-size:.77rem;color:var(--g600)}
.pw-val{letter-spacing:1px}
.cbtn{background:none;border:1px solid var(--g200);border-radius:4px;padding:2px 6px;font-size:.64rem;cursor:pointer;color:var(--g400);transition:.2s;font-family:'Exo 2',sans-serif}
.cbtn:hover{background:var(--blue);color:#fff;border-color:var(--blue)}
.bdg{display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:20px;font-size:.66rem;font-weight:700}
.bon{background:#DCFCE7;color:#15803D}.boff{background:#FEE2E2;color:var(--red)}
.ipm{font-family:'JetBrains Mono',monospace;font-size:.79rem}
.code{font-family:'JetBrains Mono',monospace;font-size:.77rem;background:var(--g100);padding:1px 5px;border-radius:4px;color:var(--blue-d)}

/* Client row */
.cl-row{display:flex;align-items:center;gap:8px;padding:9px 14px;border-bottom:1px solid var(--g100);flex-wrap:wrap}
.cl-row:last-child{border-bottom:none}
.cl-mac{font-family:'JetBrains Mono',monospace;font-size:.79rem;font-weight:600;color:var(--blue-d);min-width:130px}
.bl24{background:#DBEAFE;color:#1D4ED8;padding:2px 8px;border-radius:10px;font-size:.66rem;font-weight:700}
.bl5g{background:#F3E8FF;color:var(--purple);padding:2px 8px;border-radius:10px;font-size:.66rem;font-weight:700}
.wan-row{display:flex;align-items:center;gap:10px;padding:9px 14px;border-bottom:1px solid var(--g100);flex-wrap:wrap;font-size:.8rem}
.wan-row:last-child{border-bottom:none}

/* Big password display with eye toggle */
.pw-display-box{background:var(--g50);border:2px solid var(--g200);border-radius:10px;padding:10px 14px;font-family:'JetBrains Mono',monospace;font-size:1rem;font-weight:600;color:var(--g900);letter-spacing:2px;display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:6px}

/* Billing & Payment Styles */
.bill-hero { background: linear-gradient(135deg, #1E3A8A, #1E40AF); color: #fff; border-radius: 14px; padding: 18px 20px; margin-bottom: 14px; box-shadow: 0 4px 14px rgba(30, 58, 138, .2); }
.bill-hero.unpaid { background: linear-gradient(135deg, #991B1B, #B91C1C); box-shadow: 0 4px 14px rgba(185, 28, 28, .25); }
.bill-hero-title { font-size: .75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; opacity: .85; margin-bottom: 4px; }
.bill-hero-amount { font-size: 1.9rem; font-weight: 900; font-family: 'JetBrains Mono', monospace; }
.bill-hero-subtitle { font-size: .82rem; margin-top: 6px; opacity: .9; }

.info-table { width: 100%; border-collapse: collapse; font-size: .84rem; }
.info-table td { padding: 9px 0; border-bottom: 1px solid var(--g100); }
.info-table td:last-child { text-align: right; font-weight: 600; }
.info-table tr:last-child td { border-bottom: none; }

.bill-table { width: 100%; border-collapse: collapse; font-size: .82rem; }
.bill-table th { background: var(--g50); padding: 9px 12px; font-weight: 700; text-align: left; color: var(--g700); border-bottom: 2px solid var(--g200); }
.bill-table td { padding: 10px 12px; border-bottom: 1px solid var(--g100); }
.bill-table tr:last-child td { border-bottom: none; }

.btn-pay-now { background: linear-gradient(135deg, #16A34A, #15803D); color: #fff; font-weight: 700; padding: 12px 20px; font-size: .95rem; border-radius: 10px; border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; box-shadow: 0 4px 12px rgba(22, 163, 74, .3); transition: .2s; }
.btn-pay-now:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(22, 163, 74, .4); }
.btn-pay-now:disabled { opacity: .6; cursor: not-allowed; transform: none; }

.loading-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.6); display: none; align-items: center; justify-content: center; z-index: 9999; backdrop-filter: blur(4px); }
.spinner { width: 44px; height: 44px; border: 4px solid #fff; border-top-color: transparent; border-radius: 50%; animation: spin .7s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

@media(max-width:560px){.wfc-cur{grid-template-columns:1fr}.h-n{font-size:.78rem}}

/* ── Theme Button ────────────────────────────── */
.h-theme-btn {
    background: rgba(255,255,255,.16);
    border: 1px solid rgba(255,255,255,.28);
    color: #fff;
    padding: 5px 8px;
    border-radius: 7px;
    font-size: .85rem;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: all .2s;
    line-height: 1;
}
.h-theme-btn:hover { background: rgba(255,255,255,.28); }

/* ── Dark Mode Overrides for Customer Portal ──── */
[data-theme="dark"] body {
    background: #0F172A;
    color: #E2E8F0;
}
[data-theme="dark"] .sbar,
[data-theme="dark"] .card,
[data-theme="dark"] .md,
[data-theme="dark"] .wfb {
    background: #1E293B !important;
    border-color: #334155 !important;
    box-shadow: 0 4px 20px rgba(0,0,0,.35) !important;
}
[data-theme="dark"] .ch,
[data-theme="dark"] .mh,
[data-theme="dark"] .mf {
    border-bottom-color: #334155 !important;
    border-top-color: #334155 !important;
}
[data-theme="dark"] .ct,
[data-theme="dark"] .mt,
[data-theme="dark"] .wfb-ssid {
    color: #F8FAFC !important;
}
[data-theme="dark"] .fl {
    color: #94A3B8 !important;
}
[data-theme="dark"] .fc {
    background: #0F172A !important;
    border-color: #334155 !important;
    color: #F8FAFC !important;
}
[data-theme="dark"] .fc:focus {
    border-color: #3B82F6 !important;
    box-shadow: 0 0 0 3px rgba(59,130,246,.2) !important;
}
[data-theme="dark"] .fhint {
    color: #94A3B8 !important;
}
[data-theme="dark"] .btn-o {
    border-color: #334155 !important;
    color: #CBD5E1 !important;
}
[data-theme="dark"] .btn-o:hover {
    border-color: #3B82F6 !important;
    color: #60A5FA !important;
}
[data-theme="dark"] .wfc-cur {
    background: transparent !important;
}
[data-theme="dark"] .wfb-band {
    color: #60A5FA !important;
}
[data-theme="dark"] .wfb-pass {
    color: #CBD5E1 !important;
}
[data-theme="dark"] .cbtn {
    border-color: #334155 !important;
    color: #94A3B8 !important;
}
[data-theme="dark"] .cbtn:hover {
    background: #3B82F6 !important;
    color: #fff !important;
}
[data-theme="dark"] .pw-display-box {
    background: #0F172A !important;
    border-color: #334155 !important;
    color: #F8FAFC !important;
}
[data-theme="dark"] .code {
    background: #0F172A !important;
    color: #93C5FD !important;
}
[data-theme="dark"] .usage-quick-bar {
    background: #162032 !important;
    border-color: #1E3A8A !important;
}
[data-theme="dark"] .usage-quick-bar:hover {
    background: #1E2E4A !important;
}
[data-theme="dark"] .usage-quick-bar div div div {
    color: #93C5FD !important;
}
[data-theme="dark"] .live-session-card {
    background: #0E291C !important;
    border-color: #065F46 !important;
}
[data-theme="dark"] .cl-row,
[data-theme="dark"] .wan-row,
[data-theme="dark"] .info-table td,
[data-theme="dark"] .bill-table td {
    border-bottom-color: #334155 !important;
    color: #E2E8F0 !important;
}
[data-theme="dark"] .bill-table th {
    background: #162032 !important;
    color: #F8FAFC !important;
    border-bottom-color: #334155 !important;
}
[data-theme="dark"] .cl-mac {
    color: #93C5FD !important;
}
[data-theme="dark"] .mx {
    background: #334155 !important;
    color: #CBD5E1 !important;
}
[data-theme="dark"] .tabnav {
    border-bottom-color: #334155 !important;
}
[data-theme="dark"] .tab {
    color: #94A3B8 !important;
}
[data-theme="dark"] .tab.on {
    color: #60A5FA !important;
    border-bottom-color: #3B82F6 !important;
    background: #162032 !important;
}
@media(max-width:680px){
    [data-theme="dark"] .tabnav {
        background: #162032 !important;
    }
    [data-theme="dark"] .tab {
        color: #94A3B8 !important;
    }
    [data-theme="dark"] .tab.on {
        background: #1E293B !important;
        color: #60A5FA !important;
        box-shadow: 0 2px 8px rgba(0,0,0,.4) !important;
    }
}
[data-theme="dark"] .aok  { background: rgba(22,163,74,.18) !important; border-left-color: #22C55E !important; color: #86EFAC !important; }
[data-theme="dark"] .aerr { background: rgba(220,38,38,.18) !important; border-left-color: #EF4444 !important; color: #FCA5A5 !important; }
[data-theme="dark"] .ainf { background: rgba(37,99,235,.18) !important; border-left-color: #3B82F6 !important; color: #93C5FD !important; }
[data-theme="dark"] .awrn { background: rgba(217,119,6,.18) !important; border-left-color: #F59E0B !important; color: #FDE68A !important; }
[data-theme="dark"] .badge-pay.paid { background: rgba(22,163,74,.22) !important; color: #86EFAC !important; }
[data-theme="dark"] .badge-pay.unpaid { background: rgba(220,38,38,.22) !important; color: #FCA5A5 !important; }
[data-theme="dark"] .badge-pay.pending { background: rgba(217,119,6,.22) !important; color: #FDE68A !important; }

/* ── Live Traffic Component Styles ── */
.live-speed-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 14px;
}
@media(max-width:580px) {
    .live-speed-grid {
        grid-template-columns: 1fr;
        gap: 10px;
    }
}
.speed-card {
    border-radius: 14px;
    padding: 16px 18px;
    position: relative;
    overflow: hidden;
    box-shadow: 0 4px 15px rgba(0,0,0,0.06);
    transition: transform .2s ease;
}
.speed-card-dl {
    background: linear-gradient(135deg, #065F46 0%, #059669 45%, #10B981 100%);
    color: #fff;
    border: 1px solid rgba(16,185,129,0.3);
}
.speed-card-ul {
    background: linear-gradient(135deg, #1E3A8A 0%, #2563EB 45%, #0EA5E9 100%);
    color: #fff;
    border: 1px solid rgba(14,165,233,0.3);
}
.speed-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: .72rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    opacity: .95;
    margin-bottom: 8px;
}
.speed-val-box {
    display: flex;
    align-items: baseline;
    gap: 8px;
}
.speed-num {
    font-size: 2.3rem;
    font-weight: 900;
    font-family: 'JetBrains Mono', monospace;
    line-height: 1;
    letter-spacing: -0.5px;
}
.speed-unit {
    font-size: 1rem;
    font-weight: 800;
    background: rgba(255,255,255,0.22);
    padding: 2px 8px;
    border-radius: 6px;
    text-transform: uppercase;
    font-family: 'Exo 2', sans-serif;
}
.speed-sub-box {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: .72rem;
    opacity: .88;
    margin-top: 10px;
    font-family: 'JetBrains Mono', monospace;
}
.live-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 3px 9px;
    border-radius: 20px;
    font-size: .68rem;
    font-weight: 800;
    font-family: 'JetBrains Mono', monospace;
}
.live-pill.on {
    background: #DCFCE7;
    color: #15803D;
    border: 1px solid #86EFAC;
}
.live-pill.off {
    background: #FEE2E2;
    color: #B91C1C;
    border: 1px solid #FCA5A5;
}
.live-pill.paused {
    background: #F1F5F9;
    color: #475569;
    border: 1px solid #CBD5E1;
}
.live-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: currentColor;
}
.live-pill.on .live-dot {
    animation: dp 1.5s infinite;
}
.traffic-chart-card {
    background: #fff;
    border-radius: 14px;
    border: 1px solid var(--g200);
    padding: 14px 16px;
    margin-bottom: 14px;
    box-shadow: 0 2px 10px rgba(27,63,166,0.06);
}
.traffic-meta-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 10px;
    margin-top: 10px;
}
.traffic-meta-box {
    background: var(--g50);
    border: 1px solid var(--g200);
    border-radius: 10px;
    padding: 10px 12px;
}
.traffic-meta-label {
    font-size: .67rem;
    color: var(--g400);
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .5px;
}
.traffic-meta-val {
    font-size: .88rem;
    font-weight: 800;
    color: var(--g900);
    margin-top: 3px;
    font-family: 'JetBrains Mono', monospace;
    word-break: break-all;
}

/* Dark mode overrides for live traffic */
[data-theme="dark"] .traffic-chart-card {
    background: #111827;
    border-color: #374151;
    box-shadow: 0 4px 16px rgba(0,0,0,0.3);
}
[data-theme="dark"] .traffic-meta-box {
    background: #1F2937;
    border-color: #374151;
}
[data-theme="dark"] .traffic-meta-val {
    color: #F3F4F6;
}
[data-theme="dark"] .live-pill.on {
    background: rgba(22,163,74,0.22);
    color: #86EFAC;
    border-color: rgba(34,197,94,0.4);
}
[data-theme="dark"] .live-pill.off {
    background: rgba(220,38,38,0.22);
    color: #FCA5A5;
    border-color: rgba(239,68,68,0.4);
}
[data-theme="dark"] .live-pill.paused {
    background: rgba(100,116,139,0.22);
    color: #94A3B8;
    border-color: rgba(100,116,139,0.4);
}
</style>
</head>
<body>
<header class="hdr">
    <?php if($logo):?><img src="<?=$logo?>" alt="S.NET" class="h-logo"><?php endif;?>
    <div style="flex:1;min-width:0">
        <div class="h-n"><?=h($custRow['full_name'])?></div>
        <div class="h-id"><?=h($custRow['pppoe_username'])?></div>
    </div>
    <div style="display:flex;align-items:center;gap:6px">
        <button type="button" class="h-theme-btn" id="portal-theme-btn" title="Ganti Mode Gelap/Terang" onclick="togglePortalTheme()">
            <span class="p-moon-icon">🌙</span>
            <span class="p-sun-icon" style="display:none">☀️</span>
        </button>
        <a href="/portal/logout.php" class="h-lo">⏻ Keluar</a>
    </div>
</header>

<div class="wrap">

<!-- Status Bar -->
<div class="sbar">
    <div class="sdot <?=$online?'on':'off'?>"></div>
    <div style="flex:1;min-width:0">
        <div style="font-weight:700;font-size:.88rem"><?=h($info['model']??'Modem WiFi')?></div>
        <div style="font-size:.72rem;color:var(--g400)">SN: <span class="ipm"><?=h($custRow['ont_sn']?:'—')?></span><?php if(!empty($info)):?> &bull; <?=h($info['last_seen']??'')?><?php endif;?></div>
    </div>
    <span style="font-weight:700;font-size:.84rem;color:<?=$online?'#16A34A':'var(--red)'?>"><?=$online?'● Online':'● Offline'?></span>
    <?php if($devId):?>
    <form method="POST" style="display:inline"><?=csrfField()?><input type="hidden" name="action" value="refresh"><button type="submit" class="btn btn-o btn-sm">🔄</button></form>
    <?php endif;?>
</div>

<?php if($msg):?><div class="alert a<?=$mtype?>"><?=$msg?></div><?php endif;?>

<?php if(!$devId):?>
<div class="alert ainf">ℹ️ ONT belum terdaftar. Hubungi admin S.NET untuk aktivasi.</div>
<?php endif;?>

<!-- Current WiFi display -->
<?php if(!empty($curWifiSsid)):?>
<div class="wfc-cur">
    <div class="wfb" style="flex:1">
        <div class="wfb-band" style="color:var(--blue-d)">📡 Nama WiFi Aktif (2.4G &amp; 5G)</div>
        <div class="wfb-ssid"><?=h($curWifiSsid)?></div>
        <div class="wfb-pass">
            <span class="pw-val" id="pw24" data-val="<?=h($curWifiPass)?>" data-show="0"><?=!empty($curWifiPass)?'••••••••':'(Belum diatur)'?></span>
            <?php if(!empty($curWifiPass)):?>
            <button class="cbtn" onclick="tpw('pw24',this)" title="Lihat/Sembunyikan Sandi">👁</button>
            <button class="cbtn" onclick="cpTxt('<?=h($curWifiPass)?>', this)" title="Salin Sandi">📋</button>
            <?php endif;?>
        </div>
    </div>
</div>
<?php endif;?>

<!-- Quick Usage Banner -->
<div class="usage-quick-bar" onclick="sw('usage')">
    <div style="display:flex;align-items:center;gap:10px">
        <div style="width:34px;height:34px;border-radius:8px;background:#DBEAFE;color:#1E40AF;display:flex;align-items:center;justify-content:center;font-size:1.15rem;flex-shrink:0">📊</div>
        <div>
            <div style="font-size:.82rem;font-weight:800;color:#1E40AF;display:flex;align-items:center;gap:6px;flex-wrap:wrap">
                <span>Pemakaian (Tx + Rx): <strong style="font-family:'JetBrains Mono',monospace"><?= format_bytes($portalUsageCurr['total']) ?></strong></span>
                <?php if (!empty($portalMikrotikTraffic)): ?>
                <span style="font-size:.65rem;background:#16A34A;color:#fff;padding:1px 6px;border-radius:4px;font-family:'JetBrains Mono',monospace;font-weight:700">● Live WinBox</span>
                <?php endif; ?>
            </div>
            <div style="font-size:.7rem;color:#3B82F6;margin-top:1px">
                Tx: <strong><?= format_bytes($portalUsageCurr['dl']) ?></strong> · Rx: <strong><?= format_bytes($portalUsageCurr['ul']) ?></strong> &bull; Kuota Unlimited (FUP Bebas)
            </div>
        </div>
    </div>
    <div style="display:flex;align-items:center;gap:4px;font-size:.74rem;font-weight:700;color:#1E40AF">
        <span>Cek Detail</span>
        <span>&rsaquo;</span>
    </div>
</div>

<!-- TABS -->
<div class="tabnav">
    <button class="tab on" data-tab="wifi" onclick="sw('wifi')">
        <span class="t-icon">✏️</span>
        <span class="t-txt">WiFi</span>
    </button>
    <button class="tab" data-tab="usage" onclick="sw('usage')">
        <span class="t-icon">📊</span>
        <span class="t-txt">Pemakaian</span>
    </button>
    <button class="tab" data-tab="traffic" onclick="sw('traffic')">
        <span class="t-icon">📈</span>
        <span class="t-txt">Live Traffic</span>
    </button>
    <button class="tab" data-tab="clients" onclick="sw('clients')">
        <span class="t-icon">📱</span>
        <span class="t-txt">Client (<?=count($clients)?>)</span>
    </button>
    <?php if(!empty($wanList)):?>
    <button class="tab" data-tab="wan" onclick="sw('wan')">
        <span class="t-icon">🌐</span>
        <span class="t-txt">WAN</span>
    </button>
    <?php endif;?>
    <button class="tab" data-tab="billing" onclick="sw('billing')">
        <span class="t-icon">💳</span>
        <span class="t-txt">Tagihan</span>
        <?php if($hasUnpaid):?><span class="tab-badge-dot" title="Ada tagihan belum diselesaikan"></span><?php endif;?>
    </button>
    <button class="tab" data-tab="settings" onclick="sw('settings')">
        <span class="t-icon">⚙️</span>
        <span class="t-txt">Setting</span>
    </button>
</div>

<!-- ══════════════════════════════════════════
     TAB WIFI — 1 FORM: 1 NAMA WIFI (SSID) & 1 PASSWORD (2.4G = 5G)
     ══════════════════════════════════════════ -->
<div class="tp on" id="tp-wifi">
<?php if(!$dev && empty($curWifiSsid)):?>
<div class="alert ainf">Perangkat tidak terdeteksi — hubungi admin jika ingin mengubah WiFi.</div>
<?php else:?>
<div class="card">
    <div class="ch"><div class="ct">✏️ Ubah Nama &amp; Sandi WiFi (2.4G &amp; 5G)</div></div>
    <div class="cb">
        <!-- Kotak Info WiFi Saat Ini -->
        <div style="background:#F0FDF4; border:1px solid #BBF7D0; border-radius:10px; padding:14px; margin-bottom:18px;">
            <div style="font-size:0.75rem; font-weight:700; color:#166534; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:10px; display:flex; align-items:center; gap:6px;">
                <span>📡 Data Wi-Fi Aktif Saat Ini</span>
            </div>
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                <div style="background:#ffffff; padding:10px 12px; border-radius:8px; border:1px solid #DCFCE7;">
                    <div style="font-size:0.7rem; color:#6B7280; font-weight:600; text-transform:uppercase;">SSID Saat Ini</div>
                    <div style="font-size:0.95rem; font-weight:700; color:#1F2937; margin-top:2px; word-break:break-all;">
                        <?= h($curWifiSsid ?: '(Belum diatur)') ?>
                    </div>
                </div>
                <div style="background:#ffffff; padding:10px 12px; border-radius:8px; border:1px solid #DCFCE7;">
                    <div style="font-size:0.7rem; color:#6B7280; font-weight:600; text-transform:uppercase;">Password Saat Ini</div>
                    <div style="display:flex; align-items:center; gap:6px; margin-top:2px;">
                        <span class="pw-val" id="portalCurWifiPass" data-val="<?= h($curWifiPass) ?>" data-show="0" style="font-family:'JetBrains Mono',monospace; font-size:0.95rem; font-weight:700; color:#15803D;">
                            <?= !empty($curWifiPass) ? '••••••••' : '(Belum tersimpan)' ?>
                        </span>
                        <?php if (!empty($curWifiPass)): ?>
                        <button type="button" class="cbtn" onclick="tpw('portalCurWifiPass', this)" title="Lihat/Sembunyikan Sandi">👁</button>
                        <button type="button" class="cbtn" onclick="cpTxt('<?= h($curWifiPass) ?>', this)" title="Salin Sandi">📋</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <form method="POST">
            <?=csrfField()?>
            <input type="hidden" name="action" value="change_wifi">

            <div class="alert ainf" style="margin-bottom:14px">
                📋 <strong>Info:</strong> Nama WiFi (SSID) dan Password baru akan otomatis diterapkan serentak pada frekuensi <strong>2.4 GHz dan 5 GHz</strong> tanpa perlu dipisah.
            </div>

            <!-- NAMA WIFI (SSID) — 1 INPUT UNTUK 2.4G & 5G -->
            <div class="fg">
                <label class="fl">📡 Nama WiFi Baru (SSID) <span style="font-weight:500;color:var(--blue-d)">(Otomatis untuk 2.4G &amp; 5G)</span></label>
                <input type="text" name="wifi_ssid" class="fc" maxlength="32"
                    placeholder="Contoh: <?=h($curWifiSsid ?: 'Nama WiFi Baru')?>"
                    value="<?=h($curWifiSsid)?>" required>
                <div class="fhint">Nama WiFi ini berlaku untuk seluruh perangkat di frekuensi 2.4 GHz dan 5 GHz.</div>
            </div>

            <!-- PASSWORD — 1 input untuk keduanya -->
            <div class="fg">
                <label class="fl">🔑 Password WiFi Baru <span style="font-weight:500;color:var(--blue-d)">(berlaku untuk 2.4G &amp; 5G)</span></label>
                <div style="position:relative">
                    <input type="password" name="wifi_pass" id="wPwInp" class="fc"
                        placeholder="Minimal 8 karakter"
                        value="<?=h($curWifiPass)?>"
                        maxlength="63" style="padding-right:90px" required minlength="8">
                    <button type="button" onclick="togglePw()" id="wPwBtn"
                        style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:var(--g100);border:1px solid var(--g200);border-radius:6px;padding:3px 10px;font-size:.7rem;cursor:pointer;color:var(--g600);font-family:'Exo 2',sans-serif;font-weight:600">
                        Lihat
                    </button>
                </div>
                <div class="fhint">⚠️ Password yang sama akan dikirim ke 2.4 GHz dan 5 GHz. Semua perangkat harus pakai password ini.</div>
            </div>

            <button type="submit" class="btn btn-p btn-full" onclick="return confirm('Peringatan: Jika Anda mengubah pengaturan ini, HP/Perangkat akan terputus dari WiFi dan harus disambungkan dengan password baru. Lanjutkan?')">📡 Simpan &amp; Terapkan ke Modem</button>
        </form>
    </div>
</div>
<?php endif;?>
</div>

<!-- ══════════════════════════════════════════
     TAB PEMAKAIAN DATA (KUOTA & HISTORI)
     ══════════════════════════════════════════ -->
<div class="tp" id="tp-usage">
    <!-- Hero Pemakaian Bulan Ini -->
    <div class="usage-hero">
        <div class="usage-hero-title">📊 Total Pemakaian Bandwidth (Bulan <?= $mNames[$mNow] ?? $mNow ?> <?= $yNow ?>)</div>
        <div class="usage-hero-amount"><?= format_bytes($portalUsageCurr['total']) ?></div>
        <div class="usage-hero-badge" style="display:flex;gap:6px;justify-content:center;flex-wrap:wrap;align-items:center">
            <span style="background:rgba(255,255,255,0.22);padding:2px 10px;border-radius:12px;font-weight:700">Rumus: Total = Tx Byte + Rx Byte</span>
            <span>&bull;</span>
            <span>🚀 Paket: <strong><?= h($custRow['profile'] ?: 'Unlimited') ?></strong></span>
            <?php if (!empty($portalMikrotikTraffic)): ?>
            <span>&bull;</span>
            <span style="background:#16A34A;color:#fff;padding:2px 8px;border-radius:12px;font-weight:700">● Live WinBox Real-Time</span>
            <?php endif; ?>
        </div>

        <div class="usage-grid">
            <div class="u-box">
                <div class="u-box-l">⬇️ Tx Byte (Unduh / Download)</div>
                <div class="u-box-v"><?= format_bytes($portalUsageCurr['dl']) ?></div>
                <div class="u-box-sub"><?= $portalUsageCurr['total'] > 0 ? round(($portalUsageCurr['dl'] / $portalUsageCurr['total']) * 100) : 0 ?>% dari total trafik</div>
            </div>
            <div class="u-box">
                <div class="u-box-l">⬆️ Rx Byte (Unggah / Upload)</div>
                <div class="u-box-v"><?= format_bytes($portalUsageCurr['ul']) ?></div>
                <div class="u-box-sub"><?= $portalUsageCurr['total'] > 0 ? round(($portalUsageCurr['ul'] / $portalUsageCurr['total']) * 100) : 0 ?>% dari total trafik</div>
            </div>
            <div class="u-box">
                <div class="u-box-l">⏱️ Total Jam Online</div>
                <div class="u-box-v" style="font-size: .95rem;"><?= format_uptime_seconds($portalUsageCurr['secs']) ?></div>
                <div class="u-box-sub"><?= $portalUsageCurr['sessions'] ?> sesi dial koneksi</div>
            </div>
        </div>
    </div>

    <!-- Live Traffic Quick Link Card -->
    <div class="card" style="background:linear-gradient(135deg,#0F172A 0%,#1E293B 100%);color:#fff;border:1px solid #334155;margin-bottom:14px;cursor:pointer;transition:.15s" onclick="sw('traffic')">
        <div class="cb" style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 16px;flex-wrap:wrap">
            <div style="display:flex;align-items:center;gap:12px">
                <div style="width:38px;height:38px;border-radius:10px;background:rgba(34,197,94,0.15);border:1px solid rgba(34,197,94,0.3);display:flex;align-items:center;justify-content:center;font-size:1.3rem;color:#22C55E;flex-shrink:0">
                    📈
                </div>
                <div>
                    <div style="font-size:.85rem;font-weight:800;display:flex;align-items:center;gap:7px">
                        <span>Pantau Live Traffic Real-Time</span>
                        <span class="sdot on" style="width:7px;height:7px;background:#22C55E"></span>
                    </div>
                    <div style="font-size:.71rem;color:#94A3B8;margin-top:2px">
                        Pantau kecepatan unduh &amp; unggah detik demi detik dengan grafik live
                    </div>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:5px;font-size:.78rem;font-weight:700;color:#38BDF8">
                <span>Buka Grafik Live</span>
                <span>&rsaquo;</span>
            </div>
        </div>
    </div>

    <!-- Sesi Dial Aktif Saat Ini di MikroTik / FreeRADIUS -->
    <?php if(!empty($portalMikrotikTraffic)): ?>
    <div class="live-session-card" style="background:#F0FDF4;border:1px solid #BBF7D0;">
        <div style="display:flex;align-items:center;gap:10px">
            <div class="sdot on" style="background:#16A34A"></div>
            <div>
                <div style="font-weight:700;font-size:.84rem;color:#15803D;display:flex;align-items:center;gap:6px">
                    <span>Sesi Online Aktif di Router MikroTik</span>
                    <span style="font-size:.65rem;background:#16A34A;color:#fff;padding:1px 6px;border-radius:4px;font-family:'JetBrains Mono',monospace">WinBox Live</span>
                </div>
                <div style="font-size:.72rem;color:var(--g600);margin-top:2px">
                    Interface: <span class="ipm" style="font-weight:700;color:#15803D"><?= h($portalMikrotikTraffic['ifname']) ?></span>
                    <?php if($portalLiveSession && !empty($portalLiveSession['framedipaddress'])): ?>
                    &bull; IP: <span class="ipm" style="font-weight:700;color:var(--blue-d)"><?= h($portalLiveSession['framedipaddress']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div style="text-align:right;font-size:.76rem;color:#15803D;font-family:'JetBrains Mono',monospace">
            <div>Total Sesi: <strong><?= $portalMikrotikTraffic['total_fmt'] ?></strong></div>
            <div style="font-size:.7rem;color:var(--g600)">Tx: <strong><?= $portalMikrotikTraffic['tx_fmt'] ?></strong> · Rx: <strong><?= $portalMikrotikTraffic['rx_fmt'] ?></strong></div>
            <div style="margin-top:4px"><button type="button" class="btn btn-sm btn-p" onclick="sw('traffic')" style="font-size:.68rem;padding:2px 8px">📈 Lihat Grafik Real-Time</button></div>
        </div>
    </div>
    <?php elseif($portalLiveSession): ?>
    <div class="live-session-card">
        <div style="display:flex;align-items:center;gap:10px">
            <div class="sdot on"></div>
            <div>
                <div style="font-weight:700;font-size:.84rem;color:#15803D">Sesi Online Berlangsung</div>
                <div style="font-size:.72rem;color:var(--g600);margin-top:2px">
                    IP: <span class="ipm" style="font-weight:700;color:var(--blue-d)"><?= h($portalLiveSession['framedipaddress'] ?: 'Aktif') ?></span> &bull;
                    Tersambung: <?= format_uptime_seconds($portalLiveSession['acctsessiontime']) ?>
                </div>
            </div>
        </div>
        <div style="text-align:right;font-size:.76rem;color:#15803D;font-family:'JetBrains Mono',monospace">
            <div>Total: <strong><?= format_bytes((float)$portalLiveSession['acctoutputoctets'] + (float)$portalLiveSession['acctinputoctets']) ?></strong></div>
            <div style="font-size:.7rem;color:var(--g600)">Tx: <strong><?= format_bytes((float)$portalLiveSession['acctoutputoctets']) ?></strong> · Rx: <strong><?= format_bytes((float)$portalLiveSession['acctinputoctets']) ?></strong></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Riwayat Pemakaian Data Tiap Bulan -->
    <div class="card">
        <div class="ch">
            <div class="ct">📜 Riwayat Pemakaian Data Tiap Bulan</div>
            <span style="font-size:.73rem;color:var(--g400)">Maksimal 6 Bulan Terakhir</span>
        </div>
        <div class="cb" style="padding:0">
            <?php if(empty($portalUsageHistory)): ?>
                <div style="text-align:center;padding:26px;color:var(--g400)">
                    <div style="font-size:1.8rem;margin-bottom:6px">📊</div>
                    Belum ada riwayat pemakaian data yang tercatat.
                </div>
            <?php else: ?>
                <div style="overflow-x:auto">
                    <table class="bill-table">
                        <thead>
                            <tr>
                                <th>Periode Bulan</th>
                                <th>Tx Byte (Unduh)</th>
                                <th>Rx Byte (Unggah)</th>
                                <th style="text-align:right">Total (Tx + Rx)</th>
                                <th style="text-align:right">Durasi Online</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($portalUsageHistory as $puh): 
                                $puhM = (int)$puh['mo'];
                                $puhY = (int)$puh['yr'];
                                $puhLabel = ($mNames[$puhM] ?? $puhM) . " $puhY";
                                $isThisM = ($puh['ym'] === date('Y-m'));
                            ?>
                            <tr <?= $isThisM ? 'style="background: #F8FAFC;"' : '' ?>>
                                <td>
                                    <strong><?= $puhLabel ?></strong>
                                    <?php if($isThisM): ?>
                                        <span class="bdg bon" style="font-size:.62rem;margin-left:4px">Bulan Ini</span>
                                    <?php endif; ?>
                                    <div style="font-size:.7rem;color:var(--g400)"><?= (int)$puh['session_count'] ?> Sesi Dial</div>
                                </td>
                                <td style="font-family:'JetBrains Mono',monospace;color:#16A34A;font-weight:600">
                                    ↓ <?= format_bytes((float)$puh['dl_bytes']) ?>
                                </td>
                                <td style="font-family:'JetBrains Mono',monospace;color:#2563EB;font-weight:600">
                                    ↑ <?= format_bytes((float)$puh['ul_bytes']) ?>
                                </td>
                                <td style="text-align:right;font-family:'JetBrains Mono',monospace;font-weight:800;color:var(--g900)">
                                    <?= format_bytes((float)$puh['total_bytes']) ?>
                                </td>
                                <td style="text-align:right;font-size:.76rem;color:var(--g600)">
                                    <?= format_uptime_seconds($puh['total_secs']) ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div style="padding:10px 16px;background:#F8FAFC;border-top:1px solid var(--g200);font-size:.73rem;color:var(--g500);display:flex;align-items:center;gap:6px">
                    <span>ℹ️</span>
                    <span>Total pemakaian dihitung dari akumulasi <strong>Tx Byte (Unduh) + Rx Byte (Unggah)</strong> sesuai pembacaan traffic interface MikroTik &amp; RADIUS.</span>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Informasi Kuota & Layanan -->
    <div class="card">
        <div class="ch"><div class="ct">ℹ️ Kebijakan Layanan &amp; Pemakaian</div></div>
        <div class="cb">
            <div style="display:grid;gap:8px;font-size:.82rem">
                <div style="display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid var(--g100)">
                    <span style="color:var(--g400)">Paket Berlangganan</span>
                    <span class="bdg" style="background:#DBEAFE;color:#1D4ED8;font-weight:700"><?= h($custRow['profile'] ?: 'Standar') ?></span>
                </div>
                <div style="display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid var(--g100)">
                    <span style="color:var(--g400)">Batas Kuota (FUP)</span>
                    <span style="font-weight:700;color:#16A34A">Unlimited (Tanpa Batas Kuota)</span>
                </div>
                <div style="display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid var(--g100)">
                    <span style="color:var(--g400)">Akses Kecepatan</span>
                    <span style="font-weight:700;color:var(--blue-d)">Simetris Cepat &amp; Stabil</span>
                </div>
                <div style="display:flex;justify-content:space-between;padding:5px 0">
                    <span style="color:var(--g400)">Layanan Pelanggan</span>
                    <span style="font-weight:700"><?= h($companyPhone ?: 'Hubungi Teknisi S.NET') ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════
     TAB LIVE TRAFFIC (MONITOR KECEPATAN REAL-TIME)
     ══════════════════════════════════════════ -->
<div class="tp" id="tp-traffic">
    <!-- Top Action & Status Bar -->
    <div class="card" style="margin-bottom:12px">
        <div class="cb" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;padding:12px 16px">
            <div style="display:flex;align-items:center;gap:10px">
                <div style="width:36px;height:36px;border-radius:9px;background:#DCFCE7;color:#15803D;display:flex;align-items:center;justify-content:center;font-size:1.25rem;flex-shrink:0">
                    📈
                </div>
                <div>
                    <div style="font-size:.88rem;font-weight:800;color:var(--g900);display:flex;align-items:center;gap:8px">
                        <span>Live Traffic MikroTik</span>
                        <span id="lt-status-pill" class="live-pill on"><span class="live-dot"></span> LIVE REAL-TIME</span>
                    </div>
                    <div style="font-size:.71rem;color:var(--g400);margin-top:2px" id="lt-status-text">
                        Memantau interface router secara langsung setiap 2.5 detik
                    </div>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:8px">
                <button type="button" class="btn btn-o btn-sm" id="lt-toggle-btn" onclick="toggleTrafficPolling()" title="Jeda atau Lanjutkan Monitoring">
                    ⏸️ Jeda
                </button>
                <a href="https://fast.com" target="_blank" rel="noopener noreferrer" class="btn btn-p btn-sm" title="Uji Kecepatan Maksimal di tab baru">
                    🚀 Tes Speedtest
                </a>
            </div>
        </div>
    </div>

    <!-- Real-Time Speed Cards (Download & Upload) -->
    <div class="live-speed-grid">
        <!-- Download Card -->
        <div class="speed-card speed-card-dl">
            <div class="speed-card-header">
                <span>⬇️ Kecepatan Unduh (Download)</span>
                <span style="font-size:.65rem;background:rgba(255,255,255,0.2);padding:1px 6px;border-radius:4px">RX Router / TX Pelanggan</span>
            </div>
            <div class="speed-val-box">
                <span class="speed-num" id="lt-dl-num">0.00</span>
                <span class="speed-unit" id="lt-dl-unit">Mbps</span>
            </div>
            <div class="speed-sub-box">
                <span>Puncak: <strong id="lt-dl-peak">0 bps</strong></span>
                <span>&bull;</span>
                <span id="lt-dl-pps">0 pps</span>
            </div>
        </div>

        <!-- Upload Card -->
        <div class="speed-card speed-card-ul">
            <div class="speed-card-header">
                <span>⬆️ Kecepatan Unggah (Upload)</span>
                <span style="font-size:.65rem;background:rgba(255,255,255,0.2);padding:1px 6px;border-radius:4px">TX Router / RX Pelanggan</span>
            </div>
            <div class="speed-val-box">
                <span class="speed-num" id="lt-ul-num">0.00</span>
                <span class="speed-unit" id="lt-ul-unit">Mbps</span>
            </div>
            <div class="speed-sub-box">
                <span>Puncak: <strong id="lt-ul-peak">0 bps</strong></span>
                <span>&bull;</span>
                <span id="lt-ul-pps">0 pps</span>
            </div>
        </div>
    </div>

    <!-- Live Real-Time Line Chart -->
    <div class="traffic-chart-card">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px">
            <div>
                <div style="font-size:.85rem;font-weight:800;color:var(--g900)">📊 Grafik Throughput Real-Time</div>
                <div style="font-size:.71rem;color:var(--g400);margin-top:1px">Aktivitas transfer data dalam 60 detik terakhir</div>
            </div>
            <div style="display:flex;align-items:center;gap:12px;font-size:.73rem;font-weight:700">
                <div style="display:flex;align-items:center;gap:5px;color:#10B981">
                    <span style="width:10px;height:10px;background:#10B981;border-radius:2px;display:inline-block"></span>
                    <span>Download (Unduh)</span>
                </div>
                <div style="display:flex;align-items:center;gap:5px;color:#3B82F6">
                    <span style="width:10px;height:10px;background:#3B82F6;border-radius:2px;display:inline-block"></span>
                    <span>Upload (Unggah)</span>
                </div>
            </div>
        </div>
        <div style="position:relative;height:220px;width:100%">
            <canvas id="portalLiveTrafficChart"></canvas>
        </div>
    </div>

    <!-- Network & Session Details Card -->
    <div class="card">
        <div class="ch">
            <div class="ct">ℹ️ Informasi Interface &amp; Sesi Koneksi</div>
            <span id="lt-live-badge" class="bdg bon" style="font-size:.65rem">WinBox Interface Online</span>
        </div>
        <div class="cb" style="padding-top:10px">
            <div class="traffic-meta-grid">
                <div class="traffic-meta-box">
                    <div class="traffic-meta-label">Interface Router</div>
                    <div class="traffic-meta-val" id="lt-iface-name"><?= !empty($portalMikrotikTraffic['ifname']) ? h($portalMikrotikTraffic['ifname']) : 'Mendeteksi...' ?></div>
                </div>
                <div class="traffic-meta-box">
                    <div class="traffic-meta-label">IP Address Dial</div>
                    <div class="traffic-meta-val" id="lt-ip-addr"><?= !empty($portalLiveSession['framedipaddress']) ? h($portalLiveSession['framedipaddress']) : (!empty($portalMikrotikActiveSession['address']) ? h($portalMikrotikActiveSession['address']) : '—') ?></div>
                </div>
                <div class="traffic-meta-box">
                    <div class="traffic-meta-label">Uptime Sesi</div>
                    <div class="traffic-meta-val" id="lt-uptime"><?= !empty($portalMikrotikActiveSession['uptime']) ? h($portalMikrotikActiveSession['uptime']) : '—' ?></div>
                </div>
                <div class="traffic-meta-box">
                    <div class="traffic-meta-label">Paket Langganan</div>
                    <div class="traffic-meta-val" style="color:var(--blue-d)"><?= h($custRow['profile'] ?: 'Unlimited') ?></div>
                </div>
            </div>
            <div style="margin-top:12px;padding:9px 12px;background:var(--g100);border-radius:8px;font-size:.73rem;color:var(--g600);display:flex;align-items:center;gap:6px">
                <span>💡</span>
                <span><strong>Tips:</strong> Untuk melihat grafik naik signifikan ke batas maksimal paket Anda, silakan coba putar video YouTube kualitas 4K atau jalankan <strong>Tes Speedtest</strong>.</span>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════
     TAB CLIENT
     ══════════════════════════════════════════ -->
<div class="tp" id="tp-clients">
<div class="card">
    <div class="ch">
        <div class="ct">📱 Perangkat Terhubung (<?=count($clients)?>)</div>
        <form method="POST" style="display:inline"><?=csrfField()?><input type="hidden" name="action" value="refresh"><button type="submit" class="btn btn-o btn-sm">🔄</button></form>
    </div>
    <?php if(empty($clients)):?>
    <div style="text-align:center;padding:28px;color:var(--g400)"><div style="font-size:2rem;margin-bottom:8px">📱</div>Tidak ada perangkat terhubung</div>
    <?php else:?>
    <?php foreach($clients as $cl):?>
    <div class="cl-row">
        <div style="flex:1;min-width:0">
            <div class="cl-mac"><?=h($cl['mac'])?></div>
            <div style="font-size:.71rem;color:var(--g400);margin-top:1px">
                IP: <span class="ipm"><?=h($cl['ip'])?></span>
                <?php if($cl['hostname']!=='-'):?> &bull; <?=h($cl['hostname'])?><?php endif;?>
                <?php if($cl['rssi']!=='-'):?> &bull; <?=h($cl['rssi'])?> dBm<?php endif;?>
            </div>
        </div>
        <span class="<?=$cl['band']==='5G'?'bl5g':'bl24'?>"><?=$cl['band']?></span>
        <button onclick="blokir('<?=h($cl['mac'])?>')" class="btn btn-d btn-sm" title="Blokir perangkat dari WiFi">🚫 Blokir</button>
        <button onclick="unblokir('<?=h($cl['mac'])?>')" class="btn btn-sm" style="background:#6B7280;color:#fff" title="Hapus blokir">✅ Unblokir</button>
    </div>
    <?php endforeach;?>
    <?php endif;?>
</div>
</div>

<!-- ══════════════════════════════════════════
     TAB WAN
     ══════════════════════════════════════════ -->
<?php if(!empty($wanList)):?>
<div class="tp" id="tp-wan">
<div class="card">
    <div class="ch"><div class="ct">🌐 Info WAN</div></div>
    <?php foreach($wanList as $w):?>
    <div class="wan-row">
        <div style="flex:1">
            <div style="font-weight:700;font-size:.84rem"><?=h($w['name']??$w['label'])?></div>
            <div style="font-size:.71rem;color:var(--g400);margin-top:2px">
                <?=h($w['conn_type']??'-')?> &bull; IP: <span class="ipm"><?=h($w['ip']??'-')?></span>
                <?php if(($w['vlan']??'-')!=='-'):?> &bull; VLAN: <?=h($w['vlan'])?><?php endif;?>
            </div>
        </div>
        <span class="bdg <?=str_contains($w['status']??'','Connect')?'bon':'boff'?>"><?=h($w['status']??'-')?></span>
    </div>
    <?php endforeach;?>
</div>
</div>
<?php endif;?>

<!-- ══════════════════════════════════════════
     TAB BILLING & PEMBAYARAN
     ══════════════════════════════════════════ -->
<div class="tp" id="tp-billing">

    <!-- Hero Status Tagihan -->
    <?php if($hasUnpaid):?>
    <div class="bill-hero unpaid">
        <div class="bill-hero-title">Total Tagihan & Tunggakan</div>
        <div class="bill-hero-amount">Rp <?=number_format($totalUnpaid,0,',','.')?></div>
        <div class="bill-hero-subtitle">⚠️ Anda memiliki tagihan yang belum diselesaikan. Segera bayar untuk menghindari isolir atau pemutusan layanan.</div>
    </div>
    <?php else:?>
    <div class="bill-hero">
        <div class="bill-hero-title">Status Tagihan Bulan Ini</div>
        <div class="bill-hero-amount" style="font-size:1.45rem">✅ Lunas / Tidak Ada Tagihan</div>
        <div class="bill-hero-subtitle">Terima kasih, pembayaran Anda sudah tertib dan layanan internet dalam kondisi aktif normal.</div>
    </div>
    <?php endif;?>

    <!-- Ringkasan Layanan & Paket -->
    <div class="card">
        <div class="ch"><div class="ct">📋 Informasi Layanan & Langganan</div></div>
        <div class="cb">
            <table class="info-table">
                <tr>
                    <td style="color:var(--g400)">Nama Pelanggan</td>
                    <td><?=h($custRow['full_name'])?></td>
                </tr>
                <tr>
                    <td style="color:var(--g400)">Username PPPoE</td>
                    <td><span class="code"><?=h($custRow['pppoe_username'])?></span></td>
                </tr>
                <tr>
                    <td style="color:var(--g400)">Paket Internet</td>
                    <td><span class="bdg" style="background:#DBEAFE;color:#1D4ED8"><?=h($custRow['profile']?:'Standar')?></span></td>
                </tr>
                <tr>
                    <td style="color:var(--g400)">Biaya Berlangganan</td>
                    <td><?= $isFree ? '<span class="bdg bon">Gratis</span>' : 'Rp '.number_format($monthlyPrice,0,',','.').' / bulan' ?></td>
                </tr>
                <tr>
                    <td style="color:var(--g400)">Jatuh Tempo Tiap Bulan</td>
                    <td>Tanggal <strong><?=h($dueDay)?></strong></td>
                </tr>
                <tr>
                    <td style="color:var(--g400)">Status Layanan</td>
                    <td>
                        <?php if($custRow['status']==='active'):?>
                            <span class="bdg bon">● Aktif Normal</span>
                        <?php elseif($custRow['status']==='isolated'):?>
                            <span class="bdg boff">● Terisolir (Tunggakan)</span>
                        <?php else:?>
                            <span class="bdg boff"><?=h(ucfirst($custRow['status']))?></span>
                        <?php endif;?>
                    </td>
                </tr>
            </table>
        </div>
    </div>

    <!-- Rincian Tagihan & Tunggakan -->
    <?php if($hasUnpaid):?>
    <div class="card">
        <div class="ch"><div class="ct">⚠️ Rincian Tagihan Perlu Dibayar</div></div>
        <div class="cb" style="padding:0">
            <div style="overflow-x:auto">
                <table class="bill-table">
                    <thead>
                        <tr>
                            <th>Periode Tagihan</th>
                            <th>Keterangan</th>
                            <th style="text-align:right">Jumlah</th>
                            <th style="text-align:center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($unpaidBills as $bill):?>
                        <tr>
                            <td><strong>Periode <?=$bill['label']?></strong></td>
                            <td>
                                <span class="bdg <?=$bill['is_arrear']?'boff':'awrn'?>">
                                    <?=$bill['status_text']?>
                                </span>
                            </td>
                            <td style="text-align:right;font-family:'JetBrains Mono',monospace;font-weight:700">
                                Rp <?=number_format($bill['amount'],0,',','.')?>
                            </td>
                            <td style="text-align:center">
                                <?php if(!empty($midClientKey)):?>
                                    <button type="button" class="btn btn-p btn-sm" onclick="payBillOnline(<?=$bill['amount']?>, <?=$bill['month']?>, <?=$bill['year']?>)">
                                        💳 Bayar
                                    </button>
                                <?php else:?>
                                    <span style="font-size:.75rem;color:var(--g400)">Hubungi Admin</span>
                                <?php endif;?>
                            </td>
                        </tr>
                        <?php endforeach;?>
                    </tbody>
                </table>
            </div>

            <?php if(!empty($midClientKey) && count($unpaidBills) > 0):?>
            <div style="padding:14px">
                <button type="button" class="btn-pay-now" onclick="payBillOnline(<?=$unpaidBills[0]['amount']?>, <?=$unpaidBills[0]['month']?>, <?=$unpaidBills[0]['year']?>)">
                    💳 Bayar Sekarang via Online (QRIS / VA Bank / E-Wallet)
                </button>
                <div style="font-size:.72rem;color:var(--g400);text-align:center;margin-top:8px">
                    🔒 Pembayaran aman otomatis diverifikasi oleh Midtrans. Jika layanan terisolir, isolir akan langsung dibuka otomatis setelah sukses bayar.
                </div>
            </div>
            <?php endif;?>
        </div>
    </div>
    <?php endif;?>

    <!-- Riwayat Pembayaran -->
    <div class="card">
        <div class="ch"><div class="ct">📜 Riwayat Pembayaran</div></div>
        <div class="cb" style="padding:0">
            <?php if(empty($paymentHistory)):?>
                <div style="text-align:center;padding:24px;color:var(--g400)">Belum ada riwayat pembayaran yang tercatat.</div>
            <?php else:?>
                <div style="overflow-x:auto">
                    <table class="bill-table">
                        <thead>
                            <tr>
                                <th>Tanggal</th>
                                <th>Periode</th>
                                <th>Metode</th>
                                <th>Status</th>
                                <th style="text-align:right">Nominal</th>
                                <th style="text-align:center">Nota</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($paymentHistory as $ph):?>
                            <tr>
                                <td style="font-size:.76rem;color:var(--g600)"><?=substr($ph['paid_at']??$ph['created_at']??'', 0, 16)?></td>
                                <td>
                                    <?= !empty($ph['period_month']) ? ($mNames[(int)$ph['period_month']] ?? $ph['period_month']).' '.$ph['period_year'] : ($ph['notes'] ?: '-') ?>
                                </td>
                                <td><span class="code"><?=strtoupper($ph['payment_method']??'CASH')?></span></td>
                                <td>
                                    <?php
                                    $pStatus = $ph['midtrans_status'] ?? 'paid';
                                    if($pStatus === 'paid' || $ph['payment_method'] === 'cash') {
                                        echo '<span class="bdg bon">Lunas</span>';
                                    } elseif($pStatus === 'pending') {
                                        echo '<span class="bdg awrn" style="background:#FEF3C7;color:#92400E">Menunggu Bayar</span>';
                                    } else {
                                        echo '<span class="bdg boff">'.h(ucfirst($pStatus)).'</span>';
                                    }
                                    ?>
                                </td>
                                <td style="text-align:right;font-family:'JetBrains Mono',monospace;font-weight:700">
                                    Rp <?=number_format($ph['amount'],0,',','.')?>
                                </td>
                                <td style="text-align:center">
                                    <a href="/portal/receipt.php?id=<?=$ph['id']?>" target="_blank" class="btn btn-o btn-sm" title="Lihat Kwitansi Digital">
                                        📄 Kwitansi
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach;?>
                        </tbody>
                    </table>
                </div>
            <?php endif;?>
        </div>
    </div>

</div>

<!-- ══════════════════════════════════════════
     TAB SETTINGS
     ══════════════════════════════════════════ -->
<div class="tp" id="tp-settings">

<!-- Ganti password portal -->
<div class="card">
    <div class="ch"><div class="ct">🔑 Ubah Password Portal</div></div>
    <div class="cb">
        <form method="POST">
            <?=csrfField()?>
            <input type="hidden" name="action" value="change_pass">
            <div class="fg"><label class="fl">Password Lama</label><input type="password" name="old_pass" class="fc" required></div>
            <div class="pass-grid">
                <div class="fg"><label class="fl">Password Baru</label><input type="password" name="new_pass" class="fc" required minlength="4"></div>
                <div class="fg"><label class="fl">Konfirmasi</label><input type="password" name="confirm_pass" class="fc" required minlength="4"></div>
            </div>
            <button type="submit" class="btn btn-p">🔑 Ubah Password</button>
        </form>
    </div>
</div>

<!-- Info ONT -->
<div class="card">
    <div class="ch"><div class="ct">📡 Info Perangkat</div></div>
    <div class="cb">
        <div style="display:grid;gap:7px;font-size:.82rem">
            <?php foreach(['Username PPPoE'=>h($custRow['pppoe_username']),'Nama'=>h($custRow['full_name']),'Modem'=>h($info['model']??'—'),'Serial'=>'<span class="code">'.h($custRow['ont_sn']?:'—').'</span>','Status'=>'<span class="bdg '.($online?'bon':'boff').'">'.($online?'Online':'Offline').'</span>'] as $l=>$v):?>
            <div style="display:flex;gap:10px;padding:4px 0;border-bottom:1px solid var(--g100)">
                <div style="font-size:.67rem;font-weight:700;color:var(--g400);text-transform:uppercase;width:90px;flex-shrink:0;padding-top:2px"><?=$l?></div>
                <div><?=$v?></div>
            </div>
            <?php endforeach;?>
        </div>
    </div>
</div>

<!-- Reboot -->
<?php if($dev):?>
<div class="card">
    <div class="ch"><div class="ct">⚡ Aksi</div></div>
    <div class="cb">
        <form method="POST" onsubmit="return confirm('Reboot ONT? Koneksi internet terputus 1-2 menit.')">
            <?=csrfField()?>
            <input type="hidden" name="action" value="reboot">
            <button type="submit" class="btn btn-d">🔄 Reboot ONT</button>
        </form>
        <div style="font-size:.75rem;color:var(--g400);margin-top:7px">Reboot memutus semua koneksi sementara.</div>
    </div>
</div>
<?php endif;?>
</div>

</div><!-- /wrap -->

<!-- MODAL: Blokir Client -->
<div class="mo" id="mBlokir">
    <div class="md">
        <div class="mh"><div class="mt">🚫 Blokir Perangkat</div><button class="mx" onclick="cM()">✕</button></div>
        <form method="POST">
            <?=csrfField()?>
            <input type="hidden" name="action" value="block_client">
            <input type="hidden" name="mac" id="bMac">
            <div class="mb">
                <div class="alert awrn">⚠️ Perangkat <strong id="bMacShow"></strong> akan diblokir dari WiFi Anda. Perangkat tidak bisa tersambung sampai Anda unblokir.</div>
            </div>
            <div class="mf"><button type="button" class="btn btn-o" onclick="cM()">Batal</button><button type="submit" class="btn btn-d">🚫 Blokir</button></div>
        </form>
    </div>
</div>

<!-- MODAL: Loading Snap -->
<div class="loading-overlay" id="snapLoading">
    <div style="text-align:center;color:#fff">
        <div class="spinner" style="margin:0 auto 12px"></div>
        <div style="font-weight:700;font-size:1.05rem">Menyiapkan Pembayaran...</div>
        <div style="font-size:.8rem;opacity:.85;margin-top:4px">Mohon tunggu sebentar</div>
    </div>
</div>

<script>
function sw(id){
    document.querySelectorAll('.tab').forEach(t=>t.classList.remove('on'));
    document.querySelectorAll('.tp').forEach(p=>p.classList.remove('on'));
    document.querySelector(`.tab[data-tab="${id}"]`)?.classList.add('on');
    document.getElementById('tp-'+id)?.classList.add('on');
    if (id === 'traffic') {
        startTrafficPolling();
    } else {
        stopTrafficPolling();
    }
}
function tpw(elId,btn){const el=document.getElementById(elId);const shown=el.dataset.show==='1';el.textContent=shown?'••••••••':el.dataset.val;el.dataset.show=shown?'0':'1';btn.textContent=shown?'👁':'🙈';}
function cpTxt(t,btn){navigator.clipboard.writeText(t).then(()=>{const o=btn.textContent;btn.textContent='✓';setTimeout(()=>btn.textContent=o,1600);}).catch(()=>{const ta=document.createElement('textarea');ta.value=t;document.body.appendChild(ta);ta.select();document.execCommand('copy');ta.remove();});}
function blokir(mac){document.getElementById('bMac').value=mac;document.getElementById('bMacShow').textContent=mac;document.getElementById('mBlokir').classList.add('show');}
function unblokir(mac){
    if(!confirm('Unblokir perangkat '+mac+' dari WiFi?'))return;
    const f=document.createElement('form');f.method='POST';f.style.display='none';
    const a=document.createElement('input');a.name='action';a.value='unblock_client';
    const m=document.createElement('input');m.name='mac';m.value=mac;
    f.appendChild(a);f.appendChild(m);document.body.appendChild(f);f.submit();
}
function cM(){document.getElementById('mBlokir').classList.remove('show');}
document.getElementById('mBlokir').addEventListener('click',e=>{if(e.target===e.currentTarget)cM();});

// Show/hide password input
function togglePw(){
    const inp=document.getElementById('wPwInp');const btn=document.getElementById('wPwBtn');
    const isPass=inp.type==='password';inp.type=isPass?'text':'password';
    btn.textContent=isPass?'Sembunyikan':'Lihat';
    btn.style.color=isPass?'var(--blue-d)':'var(--g600)';
}

// Payment via Midtrans Snap
function payBillOnline(amount, month, year) {
    const load = document.getElementById('snapLoading');
    if (load) load.style.display = 'flex';
    const fd = new FormData();
    fd.append('action', 'pay_bill');
    fd.append('amount', amount);
    fd.append('period_month', month);
    fd.append('period_year', year);

    fetch('index.php', {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(res => {
        if (load) load.style.display = 'none';
        if (res.error) {
            alert(res.error);
            return;
        }
        if (res.token && window.snap) {
            window.snap.pay(res.token, {
                onSuccess: function(result) {
                    window.location.href = 'index.php?paid=1&tab=billing';
                },
                onPending: function(result) {
                    alert('Menunggu pembayaran diselesaikan. Silakan ikuti instruksi pembayaran di layar.');
                    window.location.href = 'index.php?tab=billing';
                },
                onError: function(result) {
                    alert('Pembayaran gagal atau terjadi kesalahan.');
                },
                onClose: function() {
                    // Popup ditutup oleh pengguna
                }
            });
        } else {
            alert('Gagal memuat snap popup Midtrans. Pastikan client key telah terkonfigurasi.');
        }
    })
    .catch(err => {
        if (load) load.style.display = 'none';
        alert('Terjadi kesalahan koneksi saat memproses pembayaran: ' + err);
    });
}

// Auto-switch tab jika ada parameter tab di URL atau pembayaran sukses
(function(){
    const urlParams = new URLSearchParams(window.location.search);
    const reqTab = urlParams.get('tab');
    if (reqTab && document.querySelector(`.tab[data-tab="${reqTab}"]`)) {
        sw(reqTab);
    }
})();

// Portal Theme Controller
function togglePortalTheme() {
    const cur = document.documentElement.getAttribute('data-theme') || 'light';
    const next = cur === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    localStorage.setItem('snet-portal-theme', next);
    syncPortalThemeUI(next);
}
function syncPortalThemeUI(theme) {
    const moon = document.querySelector('.p-moon-icon');
    const sun = document.querySelector('.p-sun-icon');
    const btn = document.getElementById('portal-theme-btn');
    if (moon && sun) {
        if (theme === 'dark') {
            moon.style.display = 'none';
            sun.style.display = 'inline';
            if (btn) btn.setAttribute('title', 'Beralih ke Mode Terang');
        } else {
            moon.style.display = 'inline';
            sun.style.display = 'none';
            if (btn) btn.setAttribute('title', 'Beralih ke Mode Gelap');
        }
    }
    if (typeof drawLiveTrafficCanvas === 'function') {
        drawLiveTrafficCanvas();
    }
}
syncPortalThemeUI(document.documentElement.getAttribute('data-theme') || 'light');

// ── LIVE TRAFFIC MONITORING ENGINE (PURE NATIVE CANVAS - 100% OFFLINE CAPABLE) ──
var trafficPollingTimer = null;
var isTrafficFetching = false;
var isTrafficPaused = false;
var trafficPeakDl = 0;
var trafficPeakUl = 0;
var detectedIface = <?= json_encode($portalMikrotikTraffic['ifname'] ?? '') ?>;
var trafficPointsCount = 25;
var trafficDlHistory = Array(trafficPointsCount).fill(0);
var trafficUlHistory = Array(trafficPointsCount).fill(0);

function formatBpsJs(bps) {
    bps = Number(bps) || 0;
    if (bps >= 1000000000) return (bps / 1000000000).toFixed(2) + ' Gbps';
    if (bps >= 1000000) return (bps / 1000000).toFixed(2) + ' Mbps';
    if (bps >= 1000) return (bps / 1000).toFixed(1) + ' Kbps';
    return bps.toFixed(0) + ' bps';
}

function splitBpsJs(bps) {
    bps = Number(bps) || 0;
    if (bps >= 1000000000) return { num: (bps / 1000000000).toFixed(2), unit: 'Gbps' };
    if (bps >= 1000000) return { num: (bps / 1000000).toFixed(2), unit: 'Mbps' };
    if (bps >= 1000) return { num: (bps / 1000).toFixed(1), unit: 'Kbps' };
    return { num: bps.toFixed(0), unit: 'bps' };
}

function drawLiveTrafficCanvas() {
    var canvas = document.getElementById('portalLiveTrafficChart');
    if (!canvas) return;

    var container = canvas.parentElement;
    if (!container) return;

    var w = container.clientWidth || 320;
    var h = container.clientHeight || 220;

    var dpr = window.devicePixelRatio || 1;
    if (canvas.width !== Math.floor(w * dpr) || canvas.height !== Math.floor(h * dpr)) {
        canvas.width = Math.floor(w * dpr);
        canvas.height = Math.floor(h * dpr);
    }

    var ctx = canvas.getContext('2d');
    if (!ctx) return;

    ctx.save();
    ctx.scale(dpr, dpr);
    ctx.clearRect(0, 0, w, h);

    var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    var gridColor = isDark ? 'rgba(255, 255, 255, 0.08)' : 'rgba(0, 0, 0, 0.06)';
    var textColor = isDark ? '#94A3B8' : '#64748B';

    var padLeft = 68;
    var padRight = 16;
    var padTop = 18;
    var padBottom = 22;

    var chartW = w - padLeft - padRight;
    var chartH = h - padTop - padBottom;

    if (chartW <= 20 || chartH <= 20) {
        ctx.restore();
        return;
    }

    // Determine scale (min 1 Mbps headroom)
    var maxVal = 1000000;
    for (var i = 0; i < trafficDlHistory.length; i++) {
        if (trafficDlHistory[i] > maxVal) maxVal = trafficDlHistory[i];
        if (trafficUlHistory[i] > maxVal) maxVal = trafficUlHistory[i];
    }
    maxVal = maxVal * 1.15; // 15% headroom

    // Horizontal grid lines & Y labels (4 horizontal lines)
    ctx.font = "10px 'JetBrains Mono', monospace";
    ctx.fillStyle = textColor;
    ctx.textAlign = 'right';
    ctx.textBaseline = 'middle';

    var steps = 3;
    for (var s = 0; s <= steps; s++) {
        var yVal = maxVal * (1 - s / steps);
        var yPos = Math.round(padTop + (chartH * s / steps));

        ctx.strokeStyle = gridColor;
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(padLeft, yPos);
        ctx.lineTo(w - padRight, yPos);
        ctx.stroke();

        ctx.fillText(formatBpsJs(yVal), padLeft - 8, yPos);
    }

    function getPoint(idx, val, total) {
        var x = padLeft + (idx / (total - 1)) * chartW;
        var y = padTop + chartH - (val / maxVal) * chartH;
        return { x: x, y: y };
    }

    function drawDataset(data, strokeColor, topFill, botFill) {
        if (!data || data.length < 2) return;
        var pts = [];
        for (var k = 0; k < data.length; k++) {
            pts.push(getPoint(k, data[k], data.length));
        }

        // Fill area under curve
        var grad = ctx.createLinearGradient(0, padTop, 0, padTop + chartH);
        grad.addColorStop(0, topFill);
        grad.addColorStop(1, botFill);

        ctx.beginPath();
        ctx.moveTo(pts[0].x, padTop + chartH);
        ctx.lineTo(pts[0].x, pts[0].y);
        for (var p = 0; p < pts.length - 1; p++) {
            var cpX = (pts[p].x + pts[p + 1].x) / 2;
            ctx.quadraticCurveTo(pts[p].x, pts[p].y, cpX, (pts[p].y + pts[p + 1].y) / 2);
        }
        ctx.lineTo(pts[pts.length - 1].x, pts[pts.length - 1].y);
        ctx.lineTo(pts[pts.length - 1].x, padTop + chartH);
        ctx.closePath();
        ctx.fillStyle = grad;
        ctx.fill();

        // Stroke line
        ctx.beginPath();
        ctx.moveTo(pts[0].x, pts[0].y);
        for (var p2 = 0; p2 < pts.length - 1; p2++) {
            var cpX2 = (pts[p2].x + pts[p2 + 1].x) / 2;
            ctx.quadraticCurveTo(pts[p2].x, pts[p2].y, cpX2, (pts[p2].y + pts[p2 + 1].y) / 2);
        }
        ctx.lineTo(pts[pts.length - 1].x, pts[pts.length - 1].y);
        ctx.strokeStyle = strokeColor;
        ctx.lineWidth = 2.2;
        ctx.stroke();

        // Head glowing dot
        var lastPt = pts[pts.length - 1];
        ctx.beginPath();
        ctx.arc(lastPt.x, lastPt.y, 4, 0, Math.PI * 2);
        ctx.fillStyle = strokeColor;
        ctx.fill();
        ctx.strokeStyle = '#FFFFFF';
        ctx.lineWidth = 1.5;
        ctx.stroke();
    }

    drawDataset(trafficDlHistory, '#10B981', 'rgba(16, 185, 129, 0.28)', 'rgba(16, 185, 129, 0.01)');
    drawDataset(trafficUlHistory, '#3B82F6', 'rgba(59, 130, 246, 0.28)', 'rgba(59, 130, 246, 0.01)');

    ctx.restore();
}

async function pollLiveTraffic() {
    if (isTrafficPaused || isTrafficFetching) return;
    isTrafficFetching = true;

    try {
        var url = 'api_traffic.php' + (detectedIface ? ('?interface=' + encodeURIComponent(detectedIface)) : '');
        var res = await fetch(url, { cache: 'no-store' });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        var data = await res.json();

        if (data.success) {
            if (data.interface) detectedIface = data.interface;

            var pill = document.getElementById('lt-status-pill');
            var statusText = document.getElementById('lt-status-text');
            var liveBadge = document.getElementById('lt-live-badge');

            if (data.online) {
                if (pill) {
                    pill.className = 'live-pill on';
                    pill.innerHTML = '<span class="live-dot"></span> LIVE REAL-TIME';
                }
                if (statusText) statusText.textContent = 'Terhubung aktif ke router MikroTik • ' + (data.interface || 'PPPoE');
                if (liveBadge) {
                    liveBadge.className = 'bdg bon';
                    liveBadge.textContent = '● Online ' + (data.interface || '');
                }
            } else {
                if (pill) {
                    pill.className = 'live-pill off';
                    pill.innerHTML = '<span class="live-dot"></span> OFFLINE';
                }
                if (statusText) statusText.textContent = data.message || 'Sesi dial PPPoE sedang offline';
                if (liveBadge) {
                    liveBadge.className = 'bdg boff';
                    liveBadge.textContent = '● Offline';
                }
            }

            var dlBps = Number(data.download_bps) || 0;
            var ulBps = Number(data.upload_bps) || 0;

            var dlSplit = splitBpsJs(dlBps);
            var ulSplit = splitBpsJs(ulBps);

            var elDlNum = document.getElementById('lt-dl-num');
            var elDlUnit = document.getElementById('lt-dl-unit');
            var elUlNum = document.getElementById('lt-ul-num');
            var elUlUnit = document.getElementById('lt-ul-unit');

            if (elDlNum) elDlNum.textContent = dlSplit.num;
            if (elDlUnit) elDlUnit.textContent = dlSplit.unit;
            if (elUlNum) elUlNum.textContent = ulSplit.num;
            if (elUlUnit) elUlUnit.textContent = ulSplit.unit;

            if (dlBps > trafficPeakDl) {
                trafficPeakDl = dlBps;
                var elPeakDl = document.getElementById('lt-dl-peak');
                if (elPeakDl) elPeakDl.textContent = formatBpsJs(trafficPeakDl);
            }
            if (ulBps > trafficPeakUl) {
                trafficPeakUl = ulBps;
                var elPeakUl = document.getElementById('lt-ul-peak');
                if (elPeakUl) elPeakUl.textContent = formatBpsJs(trafficPeakUl);
            }

            var elDlPps = document.getElementById('lt-dl-pps');
            var elUlPps = document.getElementById('lt-ul-pps');
            if (elDlPps) elDlPps.textContent = Number(data.download_pps || 0).toLocaleString() + ' pps';
            if (elUlPps) elUlPps.textContent = Number(data.upload_pps || 0).toLocaleString() + ' pps';

            if (data.interface) {
                var elIface = document.getElementById('lt-iface-name');
                if (elIface) elIface.textContent = data.interface;
            }
            if (data.ip) {
                var elIp = document.getElementById('lt-ip-addr');
                if (elIp) elIp.textContent = data.ip;
            }
            if (data.uptime) {
                var elUp = document.getElementById('lt-uptime');
                if (elUp) elUp.textContent = data.uptime;
            }

            trafficDlHistory.push(dlBps);
            trafficDlHistory.shift();
            trafficUlHistory.push(ulBps);
            trafficUlHistory.shift();

            drawLiveTrafficCanvas();
        } else {
            var stFail = document.getElementById('lt-status-text');
            if (stFail && data.message) stFail.textContent = data.message;
        }
    } catch (err) {
        console.warn('Traffic poll warning:', err);
    } finally {
        isTrafficFetching = false;
    }
}

function startTrafficPolling() {
    drawLiveTrafficCanvas();
    if (trafficPollingTimer) clearInterval(trafficPollingTimer);
    isTrafficPaused = false;
    var btn = document.getElementById('lt-toggle-btn');
    if (btn) btn.innerHTML = '⏸️ Jeda';
    pollLiveTraffic();
    trafficPollingTimer = setInterval(pollLiveTraffic, 2500);
}

function stopTrafficPolling() {
    if (trafficPollingTimer) {
        clearInterval(trafficPollingTimer);
        trafficPollingTimer = null;
    }
}

function toggleTrafficPolling() {
    isTrafficPaused = !isTrafficPaused;
    var btn = document.getElementById('lt-toggle-btn');
    var pill = document.getElementById('lt-status-pill');

    if (isTrafficPaused) {
        stopTrafficPolling();
        if (btn) btn.innerHTML = '▶️ Lanjutkan';
        if (pill) {
            pill.className = 'live-pill paused';
            pill.innerHTML = '⏸️ DIJEDA';
        }
    } else {
        if (btn) btn.innerHTML = '⏸️ Jeda';
        startTrafficPolling();
    }
}

// Window resize & Tab visibility
window.addEventListener('resize', function() {
    drawLiveTrafficCanvas();
});

document.addEventListener('visibilitychange', function() {
    var isTrafficTabActive = document.querySelector('.tab[data-tab="traffic"]')?.classList.contains('on');
    if (document.hidden) {
        stopTrafficPolling();
    } else if (isTrafficTabActive && !isTrafficPaused) {
        startTrafficPolling();
    }
});

// Auto-switch tab & start polling if active
(function(){
    var urlParams = new URLSearchParams(window.location.search);
    var reqTab = urlParams.get('tab');
    if (reqTab && document.querySelector('.tab[data-tab="' + reqTab + '"]')) {
        sw(reqTab);
    } else if (document.querySelector('.tab[data-tab="traffic"]')?.classList.contains('on')) {
        startTrafficPolling();
    } else {
        drawLiveTrafficCanvas();
    }
})();
</script>
</body>
</html>
