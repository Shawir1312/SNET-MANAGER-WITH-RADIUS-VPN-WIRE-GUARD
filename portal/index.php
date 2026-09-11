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
    $devices = $genie->getDevices('{"_deviceId._SerialNumber": "'.$custRow['ont_sn'].'"}');
    if (!empty($devices) && isset($devices[0])) {
        $dev = $devices[0];
        $devId = $dev['_id'];
        $info=$genie->getInfo($dev);$wifi=$genie->getWifi($dev);
        $clients=$genie->getClients($dev);$wanList=$genie->getWanList($dev);
    }
}
$online=$info['online']??false;

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
                'finish' => 'https://' . ($_SERVER['HTTP_HOST'] ?? 's.shawir.id') . '/portal/index.php?paid=1&tab=billing'
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

    // ── UBAH WIFI — 1 form: SSID 2.4G, SSID 5G, dan 1 PASSWORD berlaku KEDUANYA ──
    if($act==='change_wifi'){
        if(!$genie||!$dev){$msg='ONT tidak terhubung.';$mtype='err';}
        else{
            $s24=trim($_POST['ssid_24']??'');
            $s5g=trim($_POST['ssid_5g']??'');
            $pw =trim($_POST['wifi_pass']??'');  // 1 password untuk 2.4G DAN 5G
            $errs=[];
            if($s24&&strlen($s24)<2)  $errs[]='SSID 2.4G terlalu pendek';
            if($s5g&&strlen($s5g)<2)  $errs[]='SSID 5G terlalu pendek';
            if($pw&&strlen($pw)<8)    $errs[]='Password minimal 8 karakter';
            if(!$s24&&!$s5g&&!$pw)   $errs[]='Isi minimal satu field';
            if($errs){$msg=implode('<br>',$errs);$mtype='err';}
            else{
                // Kirim: password sama ke 2.4G dan 5G (same_pass=true)
                $ok=$genie->setWifi($devId,$dev,
                    $s24?:null, $pw?:null,   // 2.4G SSID & pass
                    $s5g?:null, $pw?:null,   // 5G SSID & pass (password SAMA)
                    true  // samePass flag
                );
                if($ok){
                    // auditLog removed
                    // Simpan ke ont_configs untuk push ulang (Dihilangkan untuk V2)
                    
                    $msg='✅ WiFi berhasil diperbarui! Tunggu 10-30 detik lalu sambungkan ulang perangkat.';$mtype='ok';
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
        $old=$_POST['old_pass']??'';$new=trim($_POST['new_pass']??'');$cf=trim($_POST['confirm_pass']??'');
        if(!password_verify($old,$custRow['portal_password'])){$msg='Password lama salah!';$mtype='err';}
        elseif(strlen($new)<4){$msg='Password baru minimal 4 karakter!';$mtype='err';}
        elseif($new!==$cf){$msg='Konfirmasi tidak cocok!';$mtype='err';}
        else{
            db_execute("UPDATE pppoe_customers SET portal_password=? WHERE id=?", 'si', [password_hash($new,PASSWORD_DEFAULT), $cid]);
            $msg='✅ Password portal berhasil diubah!';$mtype='ok';
            $custRow['portal_password'] = password_hash($new,PASSWORD_DEFAULT);
        }
    }
}

// ── PENGECEKAN CALLBACK PEMBAYARAN DARI MIDTRANS SNAP ──
if (isset($_GET['paid']) && $_GET['paid'] === '1') {
    $latestPending = db_fetch_one("SELECT * FROM pppoe_payments WHERE customer_id = ? AND midtrans_status = 'pending' ORDER BY id DESC LIMIT 1", 'i', [$cid]);
    if ($latestPending && !empty($latestPending['midtrans_order_id'])) {
        $st = check_midtrans_order_status($latestPending['midtrans_order_id']);
        if ($st && in_array($st['transaction_status'] ?? '', ['settlement', 'capture']) && in_array($st['fraud_status'] ?? '', ['accept', ''])) {
            db_execute("UPDATE pppoe_payments SET midtrans_status='paid', midtrans_tx_id=? WHERE id=?", 'si', [$st['transaction_id'] ?? '', $latestPending['id']]);
            if ($custRow['status'] === 'isolated') {
                unisolir_pppoe_customer($cid);
            }
            send_pppoe_payment_notification((int)$latestPending['id'], 'Sistem Online (Midtrans)');
            $msg = '✅ Pembayaran Berhasil! Tagihan Anda telah lunas dan bukti pembayaran telah dikirim ke WhatsApp.';
            $mtype = 'ok';
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

$logo=logoB64();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Portal — <?=h($custRow['full_name'])?></title>
<link href="https://fonts.googleapis.com/css2?family=Exo+2:wght@400;600;700;800;900&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet">
<?php if($midClientKey):?>
<script src="<?=$snapJsUrl?>" data-client-key="<?=h($midClientKey)?>"></script>
<?php endif;?>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--red:#D42B2B;--red-d:#A51C1C;--blue:#1B3FA6;--blue-d:#122B7A;--green:#16A34A;--green-d:#15803D;--orange:#D97706;--purple:#7C3AED;--g50:#F8FAFF;--g100:#F0F3FA;--g200:#E0E6F5;--g400:#8A95B8;--g600:#5A6490;--g700:#3A4468;--g900:#1A2040}
body{font-family:'Exo 2',sans-serif;min-height:100vh;background:var(--g50);color:var(--g700)}
.hdr{background:linear-gradient(135deg,var(--blue-d),var(--blue) 65%,#5B0000);padding:0 16px;height:54px;display:flex;align-items:center;gap:10px;position:sticky;top:0;z-index:100;box-shadow:0 2px 12px rgba(18,43,122,.4)}
.hdr::after{content:'';position:absolute;bottom:0;left:0;right:0;height:3px;background:linear-gradient(90deg,var(--red),#F23535,var(--blue))}
.h-logo{height:34px;object-fit:contain;background:#fff;padding:2px 8px;border-radius:6px;box-shadow:0 2px 8px rgba(0,0,0,.15)}
.h-n{color:#fff;font-weight:700;font-size:.86rem;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.h-id{color:rgba(255,255,255,.6);font-size:.68rem;font-family:'JetBrains Mono',monospace}
.h-lo{background:rgba(212,43,43,.2);border:1px solid rgba(212,43,43,.4);color:#FFB3B3;padding:5px 10px;border-radius:7px;font-size:.73rem;font-weight:600;text-decoration:none;transition:.2s;white-space:nowrap}
.h-lo:hover{background:var(--red);color:#fff}
.wrap{max-width:820px;margin:0 auto;padding:14px 12px 30px}
.sbar{background:#fff;border-radius:11px;padding:12px 14px;margin-bottom:14px;border:1px solid var(--g200);box-shadow:0 1px 6px rgba(27,63,166,.07);display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.sdot{width:11px;height:11px;border-radius:50%;flex-shrink:0}
.sdot.on{background:#22C55E;box-shadow:0 0 0 4px rgba(34,197,94,.2);animation:dp 2s infinite}
.sdot.off{background:#EF4444}
@keyframes dp{0%,100%{opacity:1}50%{opacity:.5}}
.card{background:#fff;border-radius:12px;border:1px solid var(--g200);box-shadow:0 1px 6px rgba(27,63,166,.06);overflow:hidden;margin-bottom:14px}
.ch{padding:11px 15px;border-bottom:1px solid var(--g200);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:6px}
.ct{font-size:.87rem;font-weight:700;color:var(--g900)}
.cb{padding:15px}
.fl{display:block;font-size:.69rem;font-weight:700;color:var(--g700);text-transform:uppercase;letter-spacing:.9px;margin-bottom:4px}
.fc{width:100%;padding:9px 12px;border:2px solid var(--g200);border-radius:9px;font-family:'Exo 2',sans-serif;font-size:.88rem;color:var(--g700);background:var(--g50);outline:none;transition:.2s}
.fc:focus{border-color:var(--blue);background:#fff;box-shadow:0 0 0 3px rgba(27,63,166,.08)}
.fg{margin-bottom:11px}
.fhint{font-size:.68rem;color:var(--g400);margin-top:3px}
.btn{display:inline-flex;align-items:center;gap:5px;padding:7px 14px;border-radius:9px;font-family:'Exo 2',sans-serif;font-size:.81rem;font-weight:600;cursor:pointer;border:none;transition:.2s;text-decoration:none;white-space:nowrap}
.btn-p{background:linear-gradient(135deg,var(--blue),var(--blue-d));color:#fff;box-shadow:0 3px 8px rgba(27,63,166,.25)}
.btn-p:hover{transform:translateY(-1px);box-shadow:0 5px 12px rgba(27,63,166,.35)}
.btn-d{background:linear-gradient(135deg,var(--red),var(--red-d));color:#fff}
.btn-d:hover{transform:translateY(-1px)}
.btn-o{background:transparent;border:2px solid var(--g200);color:var(--g600)}
.btn-o:hover{border-color:var(--blue);color:var(--blue)}
.btn-sm{padding:4px 10px;font-size:.72rem;border-radius:6px}
.btn-full{width:100%;justify-content:center;padding:11px}
.alert{padding:10px 14px;border-radius:9px;font-size:.82rem;font-weight:500;margin-bottom:13px;line-height:1.5}
.aok{background:#DCFCE7;border-left:4px solid #16A34A;color:#15803D}
.aerr{background:#FEE2E2;border-left:4px solid var(--red);color:var(--red-d)}
.ainf{background:#DBEAFE;border-left:4px solid var(--blue);color:var(--blue-d)}
.awrn{background:#FEF3C7;border-left:4px solid var(--orange);color:#92400E}
.tabnav{display:flex;gap:1px;border-bottom:2px solid var(--g200);margin-bottom:14px;overflow-x:auto}
.tab{padding:8px 14px;border:none;background:none;border-radius:7px 7px 0 0;font-family:'Exo 2',sans-serif;font-size:.8rem;font-weight:600;cursor:pointer;color:var(--g400);white-space:nowrap;border-bottom:3px solid transparent;margin-bottom:-2px;transition:.2s}
.tab.on{color:var(--blue);border-bottom-color:var(--blue);background:var(--g50)}
.tp{display:none}.tp.on{display:block}
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
</style>
</head>
<body>
<header class="hdr">
    <?php if($logo):?><img src="<?=$logo?>" alt="S.NET" class="h-logo"><?php endif;?>
    <div style="flex:1;min-width:0">
        <div class="h-n"><?=h($custRow['full_name'])?></div>
        <div class="h-id"><?=h($custRow['pppoe_username'])?></div>
    </div>
    <a href="/portal/logout.php" class="h-lo">⏻ Keluar</a>
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
<?php if(!empty($wifi)&&($wifi['ssid_24']||$wifi['ssid_5g'])):?>
<div class="wfc-cur">
    <div class="wfb">
        <div class="wfb-band" style="color:var(--blue-d)">📡 WiFi 2.4 GHz</div>
        <div class="wfb-ssid"><?=h($wifi['ssid_24']??'—')?></div>
        <div class="wfb-pass">
            <span class="pw-val" id="pw24" data-val="<?=h($wifi['pass_24']??'')?>" data-show="0">••••••••</span>
            <button class="cbtn" onclick="tpw('pw24',this)">👁</button>
            <?php if($wifi['pass_24']):?><button class="cbtn" onclick="cpTxt('<?=h($wifi['pass_24'])?>', this)">📋</button><?php endif;?>
        </div>
    </div>
    <div class="wfb">
        <div class="wfb-band" style="color:var(--purple)">📡 WiFi 5 GHz</div>
        <div class="wfb-ssid"><?=h($wifi['ssid_5g']??'—')?></div>
        <div class="wfb-pass">
            <span class="pw-val" id="pw5g" data-val="<?=h($wifi['pass_5g']??'')?>" data-show="0">••••••••</span>
            <button class="cbtn" onclick="tpw('pw5g',this)">👁</button>
            <?php if($wifi['pass_5g']):?><button class="cbtn" onclick="cpTxt('<?=h($wifi['pass_5g'])?>', this)">📋</button><?php endif;?>
        </div>
    </div>
</div>
<?php endif;?>

<!-- TABS -->
<div class="tabnav">
    <button class="tab on" data-tab="wifi" onclick="sw('wifi')">✏️ Ubah WiFi</button>
    <button class="tab" data-tab="clients" onclick="sw('clients')">📱 Client (<?=count($clients)?>)</button>
    <?php if(!empty($wanList)):?><button class="tab" data-tab="wan" onclick="sw('wan')">🌐 WAN</button><?php endif;?>
    <button class="tab" data-tab="billing" onclick="sw('billing')">💳 Tagihan & Pembayaran <?php if($hasUnpaid):?><span style="background:var(--red);color:#fff;border-radius:10px;padding:1px 6px;font-size:.65rem;margin-left:3px">Ada Tagihan</span><?php endif;?></button>
    <button class="tab" data-tab="settings" onclick="sw('settings')">⚙️ Pengaturan</button>
</div>

<!-- ══════════════════════════════════════════
     TAB WIFI — 1 FORM: SSID 2.4G, SSID 5G, + 1 PASSWORD (berlaku keduanya)
     ══════════════════════════════════════════ -->
<div class="tp on" id="tp-wifi">
<?php if(!$dev):?>
<div class="alert ainf">Perangkat tidak terdeteksi — tidak dapat mengubah WiFi.</div>
<?php else:?>
<div class="card">
    <div class="ch"><div class="ct">✏️ Ubah WiFi — 2.4G & 5G</div></div>
    <div class="cb">
        <form method="POST">
            <?=csrfField()?>
            <input type="hidden" name="action" value="change_wifi">

            <div class="alert ainf" style="margin-bottom:14px">
                📋 <strong>Cara pakai:</strong> Isi SSID (nama WiFi) untuk 2.4G dan/atau 5G. Isi satu password yang berlaku untuk keduanya. Kosongkan field yang tidak ingin diubah.
            </div>

            <!-- SSID 2.4G dan 5G dalam 1 baris -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
                <div class="fg" style="margin:0">
                    <label class="fl">📡 Nama WiFi 2.4 GHz</label>
                    <input type="text" name="ssid_24" class="fc" maxlength="32"
                        placeholder="<?=h($wifi['ssid_24']??'Nama WiFi 2.4G')?>"
                        value="<?=h($wifi['ssid_24']??'')?>">
                </div>
                <div class="fg" style="margin:0">
                    <label class="fl" style="color:var(--purple)">📡 Nama WiFi 5 GHz</label>
                    <input type="text" name="ssid_5g" class="fc" maxlength="32"
                        placeholder="<?=h($wifi['ssid_5g']??'Nama WiFi 5G')?>"
                        value="<?=h($wifi['ssid_5g']??'')?>">
                </div>
            </div>

            <!-- PASSWORD — 1 input untuk keduanya -->
            <div class="fg">
                <label class="fl">🔑 Password WiFi <span style="font-weight:500;color:var(--blue-d)">(berlaku untuk 2.4G & 5G)</span></label>
                <div style="position:relative">
                    <input type="password" name="wifi_pass" id="wPwInp" class="fc"
                        placeholder="Minimal 8 karakter — kosongkan jika tidak ingin diubah"
                        maxlength="63" style="padding-right:90px">
                    <button type="button" onclick="togglePw()" id="wPwBtn"
                        style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:var(--g100);border:1px solid var(--g200);border-radius:6px;padding:3px 10px;font-size:.7rem;cursor:pointer;color:var(--g600);font-family:'Exo 2',sans-serif;font-weight:600">
                        Lihat
                    </button>
                </div>
                <div class="fhint">⚠️ Password yang sama akan dikirim ke 2.4 GHz dan 5 GHz. Semua perangkat harus pakai password ini.</div>
            </div>

            <button type="submit" class="btn btn-p btn-full">📡 Kirim ke ONT</button>
        </form>
    </div>
</div>
<?php endif;?>
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
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:11px">
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
function sw(id){document.querySelectorAll('.tab').forEach(t=>t.classList.remove('on'));document.querySelectorAll('.tp').forEach(p=>p.classList.remove('on'));document.querySelector(`.tab[data-tab="${id}"]`)?.classList.add('on');document.getElementById('tp-'+id)?.classList.add('on');}
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
</script>
</body>
</html>
