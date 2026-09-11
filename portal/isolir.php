<?php
// Portal Isolir — halaman yang muncul saat pelanggan PPPoE diisolir
// Pelanggan bisa lihat tagihan & bayar via Midtrans
define('IN_APP',true);
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../include/functions.php';

// Load settings
$settings=[];
foreach(db_fetch_all("SELECT setting_key,setting_value FROM pppoe_settings") as $s)
    $settings[$s['setting_key']]=$s['setting_value'];

$company=$settings['company_name']??'S.NET Internet';
$compPhone=$settings['company_phone']??'';
$midClientKey=$settings['midtrans_client_key']??'';
$midMode=$settings['midtrans_mode']??'sandbox';
$midServerKey=$settings['midtrans_server_key']??'';

$logoPath='/assets/img/logo.png?v=2';
$logoExists=file_exists(__DIR__.'/../assets/img/logo.png');

// Detect pelanggan dari IP atau query param
$clientIp=$_SERVER['HTTP_X_FORWARDED_FOR']??$_SERVER['REMOTE_ADDR']??'';
// Ambil IP pertama jika ada proxy chain
if(strpos($clientIp,',')!==false)$clientIp=trim(explode(',',$clientIp)[0]);

$username=trim($_GET['user']??'');
$routerId=(int)($_GET['rid']??0);

// Auto-detect pelanggan jika di-redirect langsung dari router (tanpa query param ?user=)
if(!$username && $clientIp){
    try {
        $acct = db_fetch_one("SELECT username FROM radacct WHERE framedipaddress = ? AND acctstoptime IS NULL ORDER BY radacctid DESC LIMIT 1", 's', [$clientIp]);
        if(!$acct){
            $acct = db_fetch_one("SELECT username FROM radacct WHERE framedipaddress = ? ORDER BY radacctid DESC LIMIT 1", 's', [$clientIp]);
        }
        if($acct && !empty($acct['username'])){
            $username = $acct['username'];
        }
    } catch(Throwable $e) {}
}

// Cari pelanggan dari username
$cust=null;
if($username){
    if($routerId) {
        $cust=db_fetch_one("SELECT pc.*,r.name router_name FROM pppoe_customers pc JOIN routers r ON pc.router_id=r.id WHERE pc.pppoe_username=? AND pc.router_id=?", 'si', [$username, $routerId]);
    } else {
        $cust=db_fetch_one("SELECT pc.*,r.name router_name FROM pppoe_customers pc JOIN routers r ON pc.router_id=r.id WHERE pc.pppoe_username=?", 's', [$username]);
    }
    if($cust) $routerId = $cust['router_id'];
}

// Handle Midtrans payment initiation
if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['action'])&&$_POST['action']==='pay'){
    header('Content-Type: application/json');
    if(!$midServerKey||!$midClientKey){
        echo json_encode(['error'=>'Payment gateway belum dikonfigurasi. Hubungi admin.']);exit;
    }
    if(!$cust){echo json_encode(['error'=>'Pelanggan tidak ditemukan']);exit;}
    
    $amount=(int)($cust['monthly_price']);
    if($amount<=0){echo json_encode(['error'=>'Harga belum dikonfigurasi. Hubungi admin.']);exit;}
    
    $orderId='INV-'.date('Ymd').'-'.strtoupper(substr($cust['pppoe_username'],0,6)).'-'.rand(1000,9999);
    $month=date('n');$year=date('Y');
    $monthName=date('F',mktime(0,0,0,$month,1));
    
    // Save pending payment
    db_execute("INSERT INTO pppoe_payments(customer_id,amount,payment_method,midtrans_order_id,midtrans_status,period_month,period_year,notes) VALUES(?,?,'midtrans',?,'pending',?,?,?)",
               'iisiis',
               [$cust['id'],$amount,$orderId,$month,$year,"Bayar via Midtrans $monthName $year"]);
    
    // Create Midtrans Snap token
    $payload=[
        'transaction_details'=>['order_id'=>$orderId,'gross_amount'=>$amount],
        'customer_details'=>[
            'first_name'=>$cust['full_name'],
            'phone'=>$cust['phone']??'',
            'notes'=>'Tagihan Internet '.$monthName.' '.$year
        ],
        'item_details'=>[['id'=>'INET-'.$month.$year,'price'=>$amount,'quantity'=>1,'name'=>'Internet '.$monthName.' '.$year.' - '.$cust['pppoe_username']]],
        'callbacks'=>[
            'finish'=>'https://'.$_SERVER['HTTP_HOST'].'/portal/isolir.php?user='.urlencode($username).'&rid='.$routerId.'&paid=1'
        ]
    ];
    
    $url=$midMode==='production'?'https://app.midtrans.com/snap/v1/transactions':'https://app.sandbox.midtrans.com/snap/v1/transactions';
    $ch=curl_init($url);
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>json_encode($payload),
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Basic '.base64_encode($midServerKey.':')]
    ]);
    $res=curl_exec($ch);$err=curl_error($ch);curl_close($ch);
    
    if($err){echo json_encode(['error'=>'Koneksi payment gateway gagal: '.$err]);exit;}
    $data=json_decode($res,true);
    if(isset($data['token'])){
        echo json_encode(['token'=>$data['token'],'order_id'=>$orderId,'client_key'=>$midClientKey,'mode'=>$midMode]);
    } else {
        echo json_encode(['error'=>$data['error_messages'][0]??'Gagal buat transaksi']);
    }
    exit;
}

// Midtrans webhook notification (mendukung POST action=webhook maupun raw JSON langsung dari Midtrans)
$rawInput = file_get_contents('php://input');
$jsonPayload = json_decode($rawInput, true);
$isWebhook = ($_SERVER['REQUEST_METHOD'] === 'POST' && (
    (isset($_POST['action']) && $_POST['action'] === 'webhook') ||
    (!empty($jsonPayload['order_id']) && !empty($jsonPayload['signature_key']))
));

if ($isWebhook && $jsonPayload) {
    $orderId = $jsonPayload['order_id'] ?? '';
    $status = $jsonPayload['transaction_status'] ?? '';
    $fraudStatus = $jsonPayload['fraud_status'] ?? '';
    $signatureKey = hash('sha512', ($jsonPayload['order_id'] ?? '') . ($jsonPayload['status_code'] ?? '') . ($jsonPayload['gross_amount'] ?? '') . $midServerKey);
    
    if ($signatureKey === ($jsonPayload['signature_key'] ?? '')) {
        if (in_array($status, ['settlement', 'capture']) && in_array($fraudStatus, ['accept', ''])) {
            $pay = db_fetch_one("SELECT pp.*, pc.id cid FROM pppoe_payments pp JOIN pppoe_customers pc ON pp.customer_id=pc.id WHERE pp.midtrans_order_id=?", 's', [$orderId]);
            if ($pay) {
                db_execute("UPDATE pppoe_payments SET midtrans_tx_id=?, midtrans_status='paid' WHERE midtrans_order_id=?", 'ss', [$jsonPayload['transaction_id'] ?? '', $orderId]);
                unisolir_pppoe_customer((int)$pay['cid']);
                send_pppoe_payment_notification((int)$pay['id'], 'Sistem Online (Midtrans)');
            }
        } elseif (in_array($status, ['cancel', 'deny', 'expire'])) {
            db_execute("UPDATE pppoe_payments SET midtrans_status=? WHERE midtrans_order_id=?", 'ss', [$status, $orderId]);
        }
        echo 'OK';
        exit;
    }
}

// Load payment history
$payments = [];
if ($cust) {
    $payments = db_fetch_all("SELECT * FROM pppoe_payments WHERE customer_id=? ORDER BY paid_at DESC LIMIT 6", 'i', [$cust['id']]);
}

$paid = isset($_GET['paid']) && $_GET['paid'] === '1';

// Jika kembali dari Midtrans (paid=1) atau pelanggan masih terisolir, sinkronkan otomatis
if ($cust) {
    if ($paid) {
        // Cek pending payment terakhir pelanggan ini
        $latestPending = db_fetch_one("SELECT * FROM pppoe_payments WHERE customer_id = ? AND midtrans_status = 'pending' ORDER BY id DESC LIMIT 1", 'i', [$cust['id']]);
        if ($latestPending && !empty($latestPending['midtrans_order_id'])) {
            $st = check_midtrans_order_status($latestPending['midtrans_order_id']);
            if ($st && in_array($st['transaction_status'] ?? '', ['settlement', 'capture']) && in_array($st['fraud_status'] ?? '', ['accept', ''])) {
                db_execute("UPDATE pppoe_payments SET midtrans_status='paid', midtrans_tx_id=? WHERE id=?", 'si', [$st['transaction_id'] ?? '', $latestPending['id']]);
                unisolir_pppoe_customer((int)$cust['id']);
                send_pppoe_payment_notification((int)$latestPending['id'], 'Sistem Online (Midtrans)');
            }
        } elseif ($cust['status'] === 'isolated') {
            unisolir_pppoe_customer((int)$cust['id']);
        }
    } elseif ($cust['status'] === 'isolated') {
        // Cek jika sudah lunas
        auto_unisolir_paid_customers((int)($cust['router_id'] ?? 0));
    }

    // Refresh data pelanggan terbaru
    $refreshedCust = db_fetch_one("SELECT pc.*, r.name router_name FROM pppoe_customers pc JOIN routers r ON pc.router_id=r.id WHERE pc.id=?", 'i', [$cust['id']]);
    if ($refreshedCust) {
        $cust = $refreshedCust;
    }
}

$dueDay = $cust['due_day'] ?? 1;
$monthName = date('F');
$year = date('Y');
$paidCount = $cust ? (db_fetch_one("SELECT COUNT(*) as c FROM pppoe_payments WHERE customer_id=? AND period_month=? AND period_year=? AND (midtrans_status = 'paid' OR payment_method = 'cash' OR (midtrans_status NOT IN ('pending','cancel','deny','expire') AND midtrans_status IS NOT NULL))", 'iii', [$cust['id'], (int)date('n'), (int)date('Y')])['c'] ?? 0) : 0;
$paidThisMonth = $paidCount > 0;

$snapJsUrl=$midMode==='production'?'https://app.midtrans.com/snap/snap.js':'https://app.sandbox.midtrans.com/snap/snap.js';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Tagihan Internet — <?=h($company)?></title>
<style>
:root{--red:#D42B2B;--red-d:#A51C1C;--red-l:#F23535;--blue:#1B3FA6;--blue-d:#122B7A;--blue-m:#1E4DBF;--blue-l:#2555D4;--green:#16A34A;--green-d:#15803D;--orange:#D97706;--purple:#7C3AED;--g50:#F8FAFF;--g100:#F0F3FA;--g200:#E0E6F5;--g400:#8A95B8;--g500:#6270A0;--g600:#5A6490;--g700:#3A4468;--g900:#1A2040}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Exo 2',-apple-system,BlinkMacSystemFont,sans-serif;background:var(--g50);min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:flex-start;padding:20px 16px;color:var(--g700)}
.logo-container{display:flex;align-items:center;justify-content:center;margin-bottom:8px;margin-top:20px}
.hdr-logo{height:52px;object-fit:contain;background:#fff;padding:6px 14px;border-radius:10px;box-shadow:0 4px 14px rgba(27,63,166,.1)}
.logo-text{font-size:1.8rem;font-weight:900;color:var(--blue-d)}
.subtitle{font-size:.85rem;color:var(--g500);text-align:center;margin-bottom:24px;font-weight:500}
.card{background:#fff;border-radius:16px;box-shadow:0 4px 24px rgba(27,63,166,.08);width:100%;max-width:420px;overflow:hidden;margin-bottom:16px;border:1px solid var(--g200)}
.card-head{padding:20px 24px 16px;border-bottom:1px solid var(--g100)}
.card-head.red{background:linear-gradient(135deg,#FEF2F2,#FEE2E2)}
.card-head.green{background:linear-gradient(135deg,#F0FDF4,#DCFCE7)}
.card-body{padding:20px 24px}
.info-row{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--g100);font-size:.88rem}
.info-row:last-child{border:none}
.info-label{color:var(--g500);font-size:.8rem}
.info-val{font-weight:600;color:var(--g900)}
.status-badge{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:20px;font-size:.82rem;font-weight:700}
.badge-red{background:#FEE2E2;color:var(--red-d)}
.badge-green{background:#DCFCE7;color:var(--green-d)}
.btn-pay{width:100%;padding:14px;background:linear-gradient(135deg,var(--blue-l),var(--blue-d));color:#fff;border:none;border-radius:12px;font-size:1rem;font-weight:700;cursor:pointer;transition:all .2s;margin-top:4px}
.btn-pay:hover{transform:translateY(-1px);box-shadow:0 8px 25px rgba(27,63,166,.3)}
.btn-pay:disabled{opacity:.5;cursor:not-allowed;transform:none}
.btn-wa{width:100%;padding:12px;background:var(--green);color:#fff;border:none;border-radius:12px;font-size:.9rem;font-weight:700;cursor:pointer;margin-top:8px;text-decoration:none;display:block;text-align:center;transition:all .2s}
.btn-wa:hover{background:var(--green-d)}
.amount{font-size:1.8rem;font-weight:900;color:var(--blue-d);font-family:'JetBrains Mono',monospace}
.due-warn{background:#FEF3C7;border-left:4px solid var(--orange);padding:10px 14px;border-radius:0 8px 8px 0;font-size:.82rem;color:#92400E;margin:12px 0}
.history-row{display:flex;justify-content:space-between;font-size:.78rem;padding:8px 0;border-bottom:1px solid var(--g100)}
.history-row:last-child{border:none}
.paid-ok{color:var(--green-d);font-weight:700;font-family:'JetBrains Mono',monospace;font-size:.9rem}
.paid-pend{color:var(--orange);font-weight:600;font-family:'JetBrains Mono',monospace;font-size:.9rem}
.success-anim{text-align:center;padding:30px}
.success-anim .icon{font-size:4rem;animation:pop .5s ease}
@keyframes pop{0%{transform:scale(0)}100%{transform:scale(1)}}
.loading-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;z-index:9999;display:none}
.spinner{width:48px;height:48px;border:5px solid #fff;border-top-color:transparent;border-radius:50%;animation:spin .8s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
</style>
<?php if($midClientKey):?>
<script src="<?=$snapJsUrl?>" data-client-key="<?=h($midClientKey)?>"></script>
<?php endif;?>
</head>
<body>

<div class="logo-container">
    <?php if($logoExists):?><img src="<?=$logoPath?>" alt="Logo" class="hdr-logo"><?php else:?><div class="logo-text">S.NET</div><?php endif;?>
</div>
<div class="subtitle">Portal Tagihan Internet</div>

<?php if($paid&&$cust): ?>
<!-- SUCCESS PAGE -->
<div class="card">
    <div class="card-head green">
        <div class="success-anim">
            <div class="icon">✅</div>
            <div style="font-size:1.2rem;font-weight:700;color:#276749;margin-top:8px">Pembayaran Berhasil!</div>
            <div style="font-size:.85rem;color:#2F855A;margin-top:4px">Koneksi internet akan aktif kembali dalam beberapa menit</div>
        </div>
    </div>
    <div class="card-body">
        <div class="info-row"><span class="info-label">Username</span><span class="info-val"><?=h($cust['pppoe_username'])?></span></div>
        <div class="info-row"><span class="info-label">Nama</span><span class="info-val"><?=h($cust['full_name'])?></span></div>
        <div class="info-row"><span class="info-label">Status</span><span class="status-badge badge-green">✅ Aktif</span></div>
    </div>
</div>
<?php if($compPhone): ?>
<div class="card" style="padding:16px 20px;text-align:center">
    <div style="font-size:.82rem;color:#718096">Butuh bantuan? Hubungi kami</div>
    <a href="https://wa.me/<?=preg_replace('/\D/','',$compPhone)?>" class="btn-wa" style="margin-top:8px">📱 Hubungi via WhatsApp</a>
</div>
<?php endif; ?>

<?php elseif(!$cust): ?>
<!-- NO USER FOUND -->
<div class="card">
    <div class="card-head red"><div style="padding:8px 0;font-size:1.1rem;font-weight:900;color:var(--red-d);text-align:center;text-transform:uppercase">PEMBERITAHUAN ISOLIR INTERNET</div></div>
    <div class="card-body">
        <div style="font-size:.9rem;color:var(--g700);line-height:1.6;margin-bottom:20px;border-bottom:1px solid var(--g100);padding-bottom:20px">
            <p style="margin-bottom:10px">Yth. Pelanggan,</p>
            <p style="margin-bottom:10px">Kami informasikan bahwa layanan internet Anda saat ini sedang diisolir sementara karena adanya keterlambatan pembayaran tagihan.</p>
            <p style="margin-bottom:10px">Agar layanan dapat kembali digunakan, silakan melakukan pembayaran tagihan sesuai ketentuan yang berlaku.</p>
            <p style="margin-bottom:10px">Setelah pembayaran dilakukan, layanan akan segera kami aktifkan kembali.</p>
            <p style="margin-bottom:15px">Terima kasih atas perhatian dan kerja samanya.</p>
            <p style="font-weight:700">Hormat kami,<br><span style="color:var(--blue-d)"><?=h($company)?></span></p>
        </div>
        <p style="font-size:.85rem;color:var(--g600);margin-bottom:12px;font-weight:600">Silakan masukkan Username PPPoE / ID Pelanggan Anda untuk melihat rincian tagihan:</p>
        <form method="GET" style="margin-bottom:20px">
            <input type="text" name="user" placeholder="Contoh: pelanggan01" style="width:100%;padding:12px;border:1px solid #CBD5E0;border-radius:8px;font-size:1rem;margin-bottom:10px" required>
            <button type="submit" class="btn-pay" style="margin-top:0">🔍 Cari Tagihan</button>
        </form>
        <?php if($compPhone): ?>
        <a href="https://wa.me/<?=preg_replace('/\D/','',$compPhone)?>" class="btn-wa">📱 Hubungi Admin via WhatsApp</a>
        <?php endif; ?>
    </div>
</div>

<?php else: ?>
<!-- MAIN PORTAL -->
<div class="card">
    <div class="card-head <?=$cust['status']==='isolated'?'red':'green'?>">
        <div style="display:flex;justify-content:space-between;align-items:flex-start">
            <div>
                <div style="font-size:1rem;font-weight:700;color:<?=$cust['status']==='isolated'?'var(--red-d)':'var(--green-d)'?>"><?=h($cust['full_name'])?></div>
                <div style="font-size:.8rem;color:<?=$cust['status']==='isolated'?'var(--red)':'var(--green)'?>;margin-top:2px">📡 <?=h($cust['pppoe_username'])?></div>
            </div>
            <span class="status-badge <?=$cust['status']==='isolated'?'badge-red':'badge-green'?>">
                <?=$cust['status']==='isolated'?'🔴 Terisolir':'✅ Aktif'?>
            </span>
        </div>
    </div>
<?php
$indMonths = [1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',5=>'Mei',6=>'Juni',7=>'Juli',8=>'Agustus',9=>'September',10=>'Oktober',11=>'November',12=>'Desember'];
$currentMonthInd = $indMonths[(int)date('n')] . ' ' . date('Y');
?>
        <div class="info-row"><span class="info-label">Tagihan Bulan</span><span class="info-val"><?=$currentMonthInd?></span></div>
        <div class="info-row"><span class="info-label">Jatuh Tempo</span><span class="info-val">Tgl <?=$cust['due_day']?> setiap bulan</span></div>
        <div class="info-row"><span class="info-label">Jumlah Tagihan</span><span class="amount">Rp <?=number_format($cust['monthly_price'],0,',','.')?></span></div>
        <?php if($cust['isolated_reason']): 
            $reasonTxt = $cust['isolated_reason'];
            if(stripos($reasonTxt, 'manual isolated') !== false) {
                $reasonTxt = 'Layanan diisolir sementara karena tagihan belum diselesaikan.';
            }
        ?>
        <div class="due-warn">⚠️ <?=h($reasonTxt)?></div>
        <?php endif; ?>
        
        <?php if($paidThisMonth>0): ?>
        <div style="background:#C6F6D5;border-radius:8px;padding:12px 14px;text-align:center;color:#276749;font-weight:700;margin:14px 0">
            ✅ Tagihan bulan ini sudah lunas. Jika internet belum aktif, silakan restart router/modem Anda.
        </div>
        <?php elseif($midClientKey&&$cust['monthly_price']>0): ?>
        <button class="btn-pay" id="payBtn" onclick="startPayment()">
            💳 Bayar Sekarang Online — Rp <?=number_format($cust['monthly_price'],0,',','.')?>
        </button>
        <?php else: ?>
        <div style="background:var(--g100);border:1px dashed var(--g400);border-radius:10px;padding:12px 14px;text-align:center;color:var(--g700);font-size:.84rem;margin:14px 0">
            ℹ️ Silakan lakukan pembayaran tagihan melalui kasir / transfer atau hubungi admin untuk konfirmasi aktivasi.
        </div>
        <?php endif; ?>
        
        <?php if($compPhone): ?>
        <a href="https://wa.me/<?=preg_replace('/\D/','',$compPhone)?>?text=Halo+admin%2C+saya+<?=urlencode($cust['full_name'])?>+username+<?=urlencode($cust['pppoe_username'])?>+ingin+konfirmasi+pembayaran+tagihan+bulan+<?=urlencode($currentMonthInd)?>." class="btn-wa">📱 Konfirmasi Bayar via WhatsApp</a>
        <?php endif; ?>
    </div>
</div>

<!-- Riwayat Pembayaran -->
<?php if(!empty($payments)): ?>
<div class="card">
    <div style="padding:14px 20px;font-weight:700;font-size:.88rem;border-bottom:1px solid var(--g100);background:var(--g50);color:var(--g900)">📋 Riwayat Pembayaran</div>
    <div style="padding:12px 20px">
        <?php foreach($payments as $p): 
            $mName = $indMonths[(int)$p['period_month']] ?? $p['period_month'];
            $isPaid=$p['midtrans_status']!=='pending'||$p['payment_method']==='cash';
        ?>
        <div class="history-row">
            <div>
                <div style="font-weight:600;color:var(--g900)"><?=$mName?> <?=$p['period_year']?></div>
                <div style="font-size:.7rem;color:var(--g400)"><?=date('d/m/Y',strtotime($p['paid_at']))?> · <?=strtoupper($p['payment_method'])?></div>
            </div>
            <div>
                <div class="<?=$isPaid?'paid-ok':'paid-pend'?>">Rp <?=number_format($p['amount'],0,',','.')?></div>
                <div style="font-size:.7rem;text-align:right;color:<?=$isPaid?'var(--green)':'var(--orange)'?>"><?=$isPaid?'✅ Lunas':'⏳ Pending'?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<div style="font-size:.72rem;color:#A0AEC0;text-align:center;margin-top:8px"><?=h($company)?> &copy; <?=date('Y')?></div>

<div class="loading-overlay" id="loadingOverlay">
    <div style="text-align:center">
        <div class="spinner"></div>
        <div style="color:#fff;margin-top:12px;font-size:.9rem">Memproses pembayaran...</div>
    </div>
</div>

<script>
<?php if($cust): ?>
const RID=<?=$routerId?>,UNAME=<?=json_encode($username)?>;
const PRICE=<?=$cust['monthly_price']??0?>;
const HAS_MIDTRANS=<?=$midClientKey?'true':'false'?>;

function startPayment(){
    if(!HAS_MIDTRANS){alert('Payment gateway belum dikonfigurasi. Hubungi admin.');return;}
    const btn=document.getElementById('payBtn');
    btn.disabled=true;btn.textContent='⏳ Memproses...';
    document.getElementById('loadingOverlay').style.display='flex';
    
    fetch('/portal/isolir.php?user='+encodeURIComponent(UNAME)+'&rid='+RID,{
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=pay'
    })
    .then(r=>r.json())
    .then(d=>{
        document.getElementById('loadingOverlay').style.display='none';
        if(d.error){btn.disabled=false;btn.textContent='💳 Bayar Sekarang — Rp <?=number_format($cust['monthly_price']??0,0,',','.')?>';alert('❌ '+d.error);return;}
        // Open Midtrans Snap
        window.snap.pay(d.token,{
            onSuccess:function(res){
                window.location='/portal/isolir.php?user='+encodeURIComponent(UNAME)+'&rid='+RID+'&paid=1';
            },
            onPending:function(res){
                btn.disabled=false;btn.textContent='💳 Bayar Sekarang';
                alert('ℹ️ Pembayaran pending. Selesaikan pembayaran untuk mengaktifkan internet.');
            },
            onError:function(res){
                btn.disabled=false;btn.textContent='💳 Bayar Sekarang';
                alert('❌ Pembayaran gagal. Silakan coba lagi.');
            },
            onClose:function(){
                btn.disabled=false;btn.textContent='💳 Bayar Sekarang — Rp <?=number_format($cust['monthly_price']??0,0,',','.')?>';
            }
        });
    })
    .catch(e=>{
        document.getElementById('loadingOverlay').style.display='none';
        btn.disabled=false;btn.textContent='💳 Bayar Sekarang';
        alert('❌ Error: '+e.message);
    });
}
<?php endif; ?>
</script>
</body>
</html>
