<?php
// Portal Isolir — halaman yang muncul saat pelanggan PPPoE diisolir
// Pelanggan bisa lihat tagihan & bayar via Midtrans
define('IN_APP',true);
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../include/functions.php';
require_once __DIR__.'/../include/PaymentGateway.php';

// Load settings & Multi-Gateway
$settings = PaymentGateway::getSettings();
$availableGateways = PaymentGateway::getAvailableGateways();
$hasGateways       = !empty($availableGateways);

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

// Handle payment initiation (Multi-Gateway: Midtrans & Tripay)
if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['action'])&&$_POST['action']==='pay'){
    header('Content-Type: application/json');
    if(!$hasGateways){
        echo json_encode(['error'=>'Payment gateway belum dikonfigurasi. Hubungi admin.']);exit;
    }
    if(!$cust){echo json_encode(['error'=>'Pelanggan tidak ditemukan']);exit;}
    
    $amount=(int)($cust['monthly_price']);
    if($amount<=0){echo json_encode(['error'=>'Harga belum dikonfigurasi. Hubungi admin.']);exit;}
    
    $selectedGateway = trim($_POST['gateway'] ?? '');
    if (empty($selectedGateway)) {
        if (isset($availableGateways['midtrans']) && !isset($availableGateways['tripay'])) {
            $selectedGateway = 'midtrans';
        } elseif (isset($availableGateways['tripay']) && !isset($availableGateways['midtrans'])) {
            $selectedGateway = 'tripay';
        } else {
            $selectedGateway = 'midtrans';
        }
    }

    $orderId='INV-'.date('Ymd').'-'.strtoupper(substr($cust['pppoe_username'],0,6)).'-'.rand(1000,9999);
    $month=date('n');$year=date('Y');
    $monthName=date('F',mktime(0,0,0,$month,1));

    // --- GATEWAY: TRIPAY ---
    if ($selectedGateway === 'tripay') {
        if (!PaymentGateway::isTripayConfigured()) {
            echo json_encode(['error'=>'Tripay belum dikonfigurasi lengkap.']);exit;
        }

        $channel = trim($_POST['channel'] ?? 'QRIS');
        if (empty($channel)) $channel = 'QRIS';

        db_execute("INSERT INTO pppoe_payments(customer_id,amount,payment_method,payment_gateway,midtrans_order_id,midtrans_status,period_month,period_year,notes) VALUES(?,?,'tripay','tripay',?,'pending',?,?,?)",
                   'iisiis',
                   [$cust['id'],$amount,$orderId,$month,$year,"Bayar Isolir via Tripay $channel ($monthName $year)"]);
        $payDbId = db_last_id();

        $tripayRes = PaymentGateway::createTripayTransaction([
            'order_id'       => $orderId,
            'amount'         => $amount,
            'method'         => $channel,
            'customer_name'  => $cust['full_name'],
            'customer_phone' => $cust['phone'] ?? '',
            'customer_email' => $cust['email'] ?? '',
            'username'       => $cust['pppoe_username'],
            'item_name'      => 'Tagihan Internet ' . $monthName . ' ' . $year,
            'period_month'   => $month,
            'period_year'    => $year,
            'return_url'     => 'https://'.$_SERVER['HTTP_HOST'].'/portal/isolir.php?user='.urlencode($username).'&rid='.$routerId.'&paid=1&gateway=tripay'
        ]);

        if (!$tripayRes['success']) {
            echo json_encode(['error' => $tripayRes['error'] ?? 'Gagal membuat transaksi Tripay']);exit;
        }

        db_execute(
            "UPDATE pppoe_payments SET midtrans_tx_id = ?, checkout_url = ?, pay_code = ?, qr_url = ? WHERE id = ?",
            'ssssi',
            [$tripayRes['reference'] ?? '', $tripayRes['checkout_url'] ?? '', $tripayRes['pay_code'] ?? '', $tripayRes['qr_url'] ?? '', $payDbId]
        );

        echo json_encode([
            'gateway'      => 'tripay',
            'order_id'     => $orderId,
            'reference'    => $tripayRes['reference'] ?? '',
            'payment_name' => $tripayRes['payment_name'] ?? $channel,
            'method'       => $channel,
            'amount'       => $tripayRes['amount'] ?? $amount,
            'checkout_url' => $tripayRes['checkout_url'] ?? '',
            'pay_code'     => $tripayRes['pay_code'] ?? '',
            'qr_url'       => $tripayRes['qr_url'] ?? '',
            'instructions' => $tripayRes['instructions'] ?? []
        ]);
        exit;
    }
    
    // --- GATEWAY: MIDTRANS ---
    if(!$midServerKey||!$midClientKey){
        echo json_encode(['error'=>'Payment gateway Midtrans belum dikonfigurasi. Hubungi admin.']);exit;
    }

    db_execute("INSERT INTO pppoe_payments(customer_id,amount,payment_method,payment_gateway,midtrans_order_id,midtrans_status,period_month,period_year,notes) VALUES(?,?,'midtrans','midtrans',?,'pending',?,?,?)",
               'iisiis',
               [$cust['id'],$amount,$orderId,$month,$year,"Bayar Isolir via Midtrans $monthName $year"]);
    
    $midRes = PaymentGateway::createMidtransSnap([
        'order_id'       => $orderId,
        'amount'         => $amount,
        'customer_name'  => $cust['full_name'],
        'customer_phone' => $cust['phone'] ?? '',
        'item_name'      => 'Internet '.$monthName.' '.$year.' - '.$cust['pppoe_username'],
        'period_month'   => $month,
        'period_year'    => $year,
        'return_url'     => 'https://'.$_SERVER['HTTP_HOST'].'/portal/isolir.php?user='.urlencode($username).'&rid='.$routerId.'&paid=1&gateway=midtrans'
    ]);
    
    if(!$midRes['success']){
        echo json_encode(['error'=>$midRes['error'] ?? 'Gagal membuat transaksi Midtrans']);exit;
    }

    echo json_encode([
        'gateway'    => 'midtrans',
        'token'      => $midRes['token'],
        'order_id'   => $orderId,
        'client_key' => $midClientKey,
        'mode'       => $midMode
    ]);
    exit;
}

// Cek status pembayaran realtime via AJAX
if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['action'])&&$_POST['action']==='check_status'){
    header('Content-Type: application/json');
    $orderId = trim($_POST['order_id'] ?? '');
    $ref     = trim($_POST['reference'] ?? '');

    $pay = db_fetch_one(
        "SELECT * FROM pppoe_payments WHERE (midtrans_order_id = ? OR midtrans_tx_id = ?) ORDER BY id DESC LIMIT 1",
        'ss', [$orderId, $ref]
    );

    if ($pay) {
        if ($pay['midtrans_status'] === 'paid') {
            echo json_encode(['status' => 'paid']);
            exit;
        }
        if ($pay['payment_method'] === 'tripay') {
            $checkRef = !empty($pay['midtrans_tx_id']) ? $pay['midtrans_tx_id'] : $pay['midtrans_order_id'];
            $st = PaymentGateway::checkTripayStatus($checkRef);
            if ($st && isset($st['status']) && strtoupper($st['status']) === 'PAID') {
                PaymentGateway::processPaymentSuccess((int)$pay['id'], 'tripay', $st['reference'] ?? $checkRef);
                echo json_encode(['status' => 'paid']);
                exit;
            }
        } elseif ($pay['payment_method'] === 'midtrans') {
            $st = check_midtrans_order_status($pay['midtrans_order_id']);
            if ($st && in_array($st['transaction_status'] ?? '', ['settlement', 'capture']) && in_array($st['fraud_status'] ?? '', ['accept', ''])) {
                PaymentGateway::processPaymentSuccess((int)$pay['id'], 'midtrans', $st['transaction_id'] ?? '');
                echo json_encode(['status' => 'paid']);
                exit;
            }
        }
        echo json_encode(['status' => $pay['midtrans_status'] ?? 'pending']);
    } else {
        echo json_encode(['status' => 'not_found']);
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
        // Cek pending payment terakhir pelanggan ini (Midtrans atau Tripay)
        $latestPending = db_fetch_one("SELECT * FROM pppoe_payments WHERE customer_id = ? AND midtrans_status = 'pending' ORDER BY id DESC LIMIT 1", 'i', [$cust['id']]);
        if ($latestPending && !empty($latestPending['midtrans_order_id'])) {
            if ($latestPending['payment_method'] === 'tripay') {
                $ref = !empty($latestPending['midtrans_tx_id']) ? $latestPending['midtrans_tx_id'] : $latestPending['midtrans_order_id'];
                $st = PaymentGateway::checkTripayStatus($ref);
                if ($st && isset($st['status']) && strtoupper($st['status']) === 'PAID') {
                    PaymentGateway::processPaymentSuccess((int)$latestPending['id'], 'tripay', $st['reference'] ?? $ref);
                    unisolir_pppoe_customer((int)$cust['id']);
                }
            } else {
                $st = check_midtrans_order_status($latestPending['midtrans_order_id']);
                if ($st && in_array($st['transaction_status'] ?? '', ['settlement', 'capture']) && in_array($st['fraud_status'] ?? '', ['accept', ''])) {
                    PaymentGateway::processPaymentSuccess((int)$latestPending['id'], 'midtrans', $st['transaction_id'] ?? '');
                    unisolir_pppoe_customer((int)$cust['id']);
                }
            }
        } elseif ($cust['status'] === 'isolated') {
            unisolir_pppoe_customer((int)$cust['id']);
        }
    } elseif ($cust['status'] === 'isolated') {
        // Cek secara spesifik hanya untuk pelanggan ini: apakah gratis atau benar-benar sudah lunas
        $isFree = !empty($cust['is_free']) || (float)($cust['monthly_price'] ?? 0) <= 0;
        if ($isFree) {
            unisolir_pppoe_customer((int)$cust['id']);
        } else {
            $m1 = (int)date('n');
            $y1 = (int)date('Y');
            $m2 = $m1 - 1;
            $y2 = $y1;
            if ($m2 == 0) { $m2 = 12; $y2--; }

            $graceDays = 0;
            try {
                $gRow = db_fetch_one("SELECT setting_value FROM pppoe_settings WHERE setting_key = 'isolir_grace_days'");
                if ($gRow) $graceDays = max(0, (int)$gRow['setting_value']);
            } catch (Throwable $e) {}

            $dueDay = (int)($cust['due_day'] ?? 1);
            $d1 = min($dueDay, (int)date('t'));
            $dueDate1Str = sprintf('%04d-%02d-%02d', $y1, $m1, $d1);
            $effectiveDue1Ts = strtotime("$dueDate1Str +{$graceDays} days");
            $todayMidTs = strtotime(date('Y-m-d') . ' 00:00:00');

            // 1. Cek apakah sudah lunas tagihan bulan berjalan ($m1, $y1)
            $paidCurr = db_fetch_one(
                "SELECT id FROM pppoe_payments 
                 WHERE customer_id = ? 
                   AND period_month = ? AND period_year = ?
                   AND (midtrans_status = 'paid' OR payment_method = 'cash' OR (midtrans_status NOT IN ('pending','cancel','deny','expire') AND midtrans_status IS NOT NULL))
                 LIMIT 1",
                'iii', [$cust['id'], $m1, $y1]
            );

            if (!empty($paidCurr)) {
                // Bulan berjalan lunas -> buka isolir
                unisolir_pppoe_customer((int)$cust['id']);
            } elseif ($todayMidTs < $effectiveDue1Ts) {
                // Bulan berjalan belum jatuh tempo -> hanya buka isolir jika tagihan bulan lalu sudah lunas
                $paidPrev = db_fetch_one(
                    "SELECT id FROM pppoe_payments 
                     WHERE customer_id = ? 
                       AND period_month = ? AND period_year = ?
                       AND (midtrans_status = 'paid' OR payment_method = 'cash' OR (midtrans_status NOT IN ('pending','cancel','deny','expire') AND midtrans_status IS NOT NULL))
                     LIMIT 1",
                    'iii', [$cust['id'], $m2, $y2]
                );
                if (!empty($paidPrev)) {
                    unisolir_pppoe_customer((int)$cust['id']);
                }
            }
            // Jika bulan berjalan SUDAH jatuh tempo tapi BELUM lunas, JANGAN dibuka isolirnya!
        }
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

/* Dark mode overrides */
[data-theme="dark"] body { background: #0F172A; color: #E2E8F0; }
[data-theme="dark"] .subtitle { color: #94A3B8; }
[data-theme="dark"] .card { background: #1E293B; border-color: #334155; box-shadow: 0 4px 20px rgba(0,0,0,0.4); }
[data-theme="dark"] .card-head { border-bottom-color: #334155; }
[data-theme="dark"] .card-head.red { background: linear-gradient(135deg, rgba(220,38,38,0.2), rgba(185,28,28,0.3)); }
[data-theme="dark"] .card-head.green { background: linear-gradient(135deg, rgba(16,185,129,0.2), rgba(5,150,105,0.3)); }
[data-theme="dark"] .info-row { border-bottom-color: #334155; }
[data-theme="dark"] .info-label { color: #94A3B8; }
[data-theme="dark"] .info-val { color: #F8FAFC; }
[data-theme="dark"] .amount { color: #60A5FA; }
[data-theme="dark"] .due-warn { background: rgba(217,119,6,0.2); border-left-color: #F59E0B; color: #FDE68A; }
[data-theme="dark"] .history-row { border-bottom-color: #334155; color: #CBD5E1; }
[data-theme="dark"] .badge-red { background: rgba(220,38,38,0.25); color: #FCA5A5; }
[data-theme="dark"] .badge-green { background: rgba(16,185,129,0.25); color: #86EFAC; }
</style>
<script>
(function() {
    const t = localStorage.getItem('snet-portal-theme') || 'light';
    if (t === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
})();
</script>
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
        <?php elseif($hasGateways && $cust['monthly_price']>0): ?>
        <button class="btn-pay" id="payBtn" onclick="openIsolirPayment()">
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

<!-- MODAL: Pilih Payment Gateway Isolir -->
<div id="mIsoGateways" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9000;align-items:center;justify-content:center;padding:14px">
    <div style="background:#fff;border-radius:16px;max-width:420px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.3);overflow:hidden">
        <div style="padding:14px 18px;border-bottom:1px solid #E2E8F0;display:flex;align-items:center;justify-content:space-between">
            <div style="font-weight:800;font-size:.95rem;color:#0F172A">💳 Pilih Gateway Pembayaran</div>
            <button onclick="closeIsoModal()" style="border:none;background:#F1F5F9;border-radius:6px;width:26px;height:26px;cursor:pointer;font-weight:bold;color:#64748B">✕</button>
        </div>
        <div style="padding:16px">
            <div style="font-size:.8rem;color:#64748B;margin-bottom:12px">Tagihan Anda: <strong style="color:#1E293B">Rp <?=number_format($cust['monthly_price']??0,0,',','.')?></strong></div>

            <div style="display:flex;gap:8px;margin-bottom:14px">
                <?php if(isset($availableGateways['midtrans'])): ?>
                <button type="button" id="isoTabMid" class="btn btn-sm" style="flex:1;padding:8px;font-weight:700;border:1px solid #CBD5E1;border-radius:8px;background:#1B3FA6;color:#fff" onclick="switchIsoTab('midtrans')">
                    💳 Midtrans Snap
                </button>
                <?php endif; ?>
                <?php if(isset($availableGateways['tripay'])): ?>
                <button type="button" id="isoTabTri" class="btn btn-sm" style="flex:1;padding:8px;font-weight:700;border:1px solid #CBD5E1;border-radius:8px;background:#fff;color:#334155" onclick="switchIsoTab('tripay')">
                    ⚡ Tripay Gateway
                </button>
                <?php endif; ?>
            </div>

            <?php if(isset($availableGateways['midtrans'])): ?>
            <div id="isoContentMid">
                <p style="font-size:.78rem;color:#64748B;margin-bottom:14px;line-height:1.4">
                    Bayar menggunakan popup Midtrans Snap (QRIS GoPay/ShopeePay, VA BCA, Mandiri, BRI, BNI).
                </p>
                <button type="button" class="btn-pay" onclick="executeIsoPay('midtrans', '')">
                    Lanjut Bayar via Midtrans →
                </button>
            </div>
            <?php endif; ?>

            <?php if(isset($availableGateways['tripay'])): ?>
            <div id="isoContentTri" style="<?= isset($availableGateways['midtrans']) ? 'display:none' : '' ?>">
                <div style="font-size:.78rem;font-weight:700;color:#334155;margin-bottom:6px">Pilih Saluran Pembayaran:</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px">
                    <button type="button" class="iso-channel-btn" data-ch="QRIS" onclick="pickIsoChannel('QRIS', this)" style="border:2px solid #D97706;background:#FEF3C7;color:#92400E;padding:8px;border-radius:8px;font-weight:700;cursor:pointer;text-align:left">
                        <div style="font-size:.8rem">📱 QRIS Dinamis</div>
                        <div style="font-size:.65rem;opacity:.8">Semua E-Wallet</div>
                    </button>
                    <button type="button" class="iso-channel-btn" data-ch="BCAVA" onclick="pickIsoChannel('BCAVA', this)" style="border:1px solid #CBD5E1;background:#fff;color:#334155;padding:8px;border-radius:8px;font-weight:700;cursor:pointer;text-align:left">
                        <div style="font-size:.8rem">🏦 BCA VA</div>
                        <div style="font-size:.65rem;opacity:.8">Virtual Account</div>
                    </button>
                    <button type="button" class="iso-channel-btn" data-ch="BRIVA" onclick="pickIsoChannel('BRIVA', this)" style="border:1px solid #CBD5E1;background:#fff;color:#334155;padding:8px;border-radius:8px;font-weight:700;cursor:pointer;text-align:left">
                        <div style="font-size:.8rem">🏦 BRI VA</div>
                        <div style="font-size:.65rem;opacity:.8">Virtual Account</div>
                    </button>
                    <button type="button" class="iso-channel-btn" data-ch="MANDIRIVA" onclick="pickIsoChannel('MANDIRIVA', this)" style="border:1px solid #CBD5E1;background:#fff;color:#334155;padding:8px;border-radius:8px;font-weight:700;cursor:pointer;text-align:left">
                        <div style="font-size:.8rem">🏦 Mandiri VA</div>
                        <div style="font-size:.65rem;opacity:.8">Virtual Account</div>
                    </button>
                </div>
                <button type="button" class="btn-pay" style="background:#D97706" onclick="executeIsoTripayPay()">
                    Buat Kode Bayar Tripay →
                </button>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- MODAL: Detail Tripay Isolir (Bisa Scan QR via Data Seluler) -->
<div id="mIsoTripayDetail" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:9100;align-items:center;justify-content:center;padding:14px">
    <div style="background:#fff;border-radius:16px;max-width:420px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.3);overflow:hidden;text-align:center">
        <div style="padding:14px 18px;border-bottom:1px solid #E2E8F0;display:flex;align-items:center;justify-content:space-between">
            <div style="font-weight:800;font-size:.95rem;color:#0F172A" id="isoTpTitle">⚡ Pembayaran Tripay</div>
            <button onclick="closeIsoTripayDetail()" style="border:none;background:#F1F5F9;border-radius:6px;width:26px;height:26px;cursor:pointer;font-weight:bold;color:#64748B">✕</button>
        </div>
        <div style="padding:18px">
            <div style="font-size:.78rem;color:#64748B">Total yang harus dibayar:</div>
            <div style="font-size:1.6rem;font-weight:900;color:#1E293B;margin:4px 0" id="isoTpAmount">Rp 0</div>

            <div id="isoTpQrisArea" style="display:none;margin:12px 0">
                <div style="background:#fff;border:2px dashed #D97706;border-radius:12px;padding:10px;display:inline-block">
                    <img id="isoTpQrImg" src="" alt="QRIS" style="max-width:210px;width:100%;height:auto;display:block;margin:0 auto">
                </div>
                <div style="font-size:.74rem;color:#64748B;margin-top:8px">
                    Scan kode QRIS ini menggunakan m-banking atau e-wallet melalui paket data HP Anda.
                </div>
            </div>

            <div id="isoTpVaArea" style="display:none;margin:14px 0">
                <div style="font-size:.75rem;color:#64748B;margin-bottom:4px" id="isoTpVaLabel">Nomor Virtual Account:</div>
                <div style="background:#F1F5F9;border:1px solid #CBD5E1;border-radius:10px;padding:10px 14px;display:flex;align-items:center;justify-content:space-between">
                    <span id="isoTpVaCode" style="font-family:'JetBrains Mono',monospace;font-size:1.15rem;font-weight:800;color:#1B3FA6">-</span>
                    <button type="button" onclick="copyIsoTpCode()" id="isoBtnCopy" style="background:#1B3FA6;color:#fff;border:none;border-radius:6px;padding:6px 12px;font-size:.78rem;cursor:pointer;font-weight:700">Salin</button>
                </div>
            </div>

            <div style="display:flex;gap:8px;margin-top:14px">
                <a id="isoTpLink" href="#" target="_blank" style="flex:1;background:#F1F5F9;border:1px solid #CBD5E1;color:#334155;text-decoration:none;padding:10px;border-radius:10px;font-size:.82rem;font-weight:700;display:inline-flex;align-items:center;justify-content:center">
                    🔗 Buka Tripay
                </a>
                <button type="button" onclick="isoCheckStatusNow()" style="flex:1;background:#16A34A;color:#fff;border:none;padding:10px;border-radius:10px;font-size:.82rem;font-weight:700;cursor:pointer">
                    🔄 Cek Status
                </button>
            </div>
            <div style="font-size:.7rem;color:#94A3B8;margin-top:10px">
                Begitu pembayaran sukses, internet akan otomatis aktif seketika tanpa perlu restart ONT.
            </div>
        </div>
    </div>
</div>

<script>
<?php if($cust): ?>
const RID=<?=$routerId?>,UNAME=<?=json_encode($username)?>;
const PRICE=<?=$cust['monthly_price']??0?>;
const HAS_MIDTRANS=<?=isset($availableGateways['midtrans'])?'true':'false'?>;
const HAS_TRIPAY=<?=isset($availableGateways['tripay'])?'true':'false'?>;

let isoChannel = 'QRIS';
let isoActiveOrderId = '', isoActiveRef = '';
let isoPollTimer = null;

function openIsolirPayment(){
    if(HAS_MIDTRANS && !HAS_TRIPAY) {
        executeIsoPay('midtrans', '');
        return;
    }
    if(HAS_TRIPAY && !HAS_MIDTRANS) {
        switchIsoTab('tripay');
        document.getElementById('mIsoGateways').style.display='flex';
        return;
    }
    switchIsoTab('midtrans');
    document.getElementById('mIsoGateways').style.display='flex';
}

function closeIsoModal(){
    document.getElementById('mIsoGateways').style.display='none';
}

function switchIsoTab(gw){
    const tabM = document.getElementById('isoTabMid');
    const tabT = document.getElementById('isoTabTri');
    const conM = document.getElementById('isoContentMid');
    const conT = document.getElementById('isoContentTri');
    if(gw==='midtrans'&&conM){
        if(tabM){tabM.style.background='#1B3FA6';tabM.style.color='#fff';}
        if(tabT){tabT.style.background='#fff';tabT.style.color='#334155';}
        conM.style.display='block';
        if(conT) conT.style.display='none';
    }else if(gw==='tripay'&&conT){
        if(tabT){tabT.style.background='#D97706';tabT.style.color='#fff';}
        if(tabM){tabM.style.background='#fff';tabM.style.color='#334155';}
        conT.style.display='block';
        if(conM) conM.style.display='none';
    }
}

function pickIsoChannel(ch, btn){
    isoChannel = ch;
    document.querySelectorAll('.iso-channel-btn').forEach(b=>{
        b.style.borderColor='#CBD5E1';b.style.background='#fff';b.style.color='#334155';
    });
    btn.style.borderColor='#D97706';btn.style.background='#FEF3C7';btn.style.color='#92400E';
}

function executeIsoTripayPay(){
    executeIsoPay('tripay', isoChannel || 'QRIS');
}

function executeIsoPay(gw, ch){
    closeIsoModal();
    document.getElementById('loadingOverlay').style.display='flex';

    fetch('/portal/isolir.php?user='+encodeURIComponent(UNAME)+'&rid='+RID,{
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=pay&gateway='+encodeURIComponent(gw)+'&channel='+encodeURIComponent(ch||'')
    })
    .then(r=>r.json())
    .then(d=>{
        document.getElementById('loadingOverlay').style.display='none';
        if(d.error){alert('❌ '+d.error);return;}

        if(d.gateway==='midtrans' && d.token && window.snap){
            window.snap.pay(d.token,{
                onSuccess:function(res){
                    window.location='/portal/isolir.php?user='+encodeURIComponent(UNAME)+'&rid='+RID+'&paid=1&gateway=midtrans';
                },
                onPending:function(res){
                    alert('ℹ️ Pembayaran pending. Selesaikan pembayaran untuk mengaktifkan internet.');
                },
                onError:function(res){
                    alert('❌ Pembayaran gagal atau dibatalkan.');
                },
                onClose:function(){}
            });
        }else if(d.gateway==='tripay'){
            showIsoTripay(d);
        }else{
            alert('Respons tidak dikenali dari gateway.');
        }
    })
    .catch(e=>{
        document.getElementById('loadingOverlay').style.display='none';
        alert('❌ Error: '+e.message);
    });
}

function showIsoTripay(d){
    isoActiveOrderId = d.order_id;
    isoActiveRef = d.reference;

    document.getElementById('isoTpTitle').textContent = '⚡ ' + (d.payment_name || 'Pembayaran Tripay');
    document.getElementById('isoTpAmount').textContent = 'Rp ' + Number(d.amount).toLocaleString('id-ID');

    const qrArea = document.getElementById('isoTpQrisArea');
    const vaArea = document.getElementById('isoTpVaArea');
    const link = document.getElementById('isoTpLink');

    if(link && d.checkout_url){
        link.href = d.checkout_url;
        link.style.display = 'inline-flex';
    }else if(link){
        link.style.display = 'none';
    }

    if(d.method==='QRIS' && d.qr_url){
        qrArea.style.display = 'block';
        vaArea.style.display = 'none';
        document.getElementById('isoTpQrImg').src = d.qr_url;
    }else{
        qrArea.style.display = 'none';
        vaArea.style.display = 'block';
        document.getElementById('isoTpVaLabel').textContent = (d.method.indexOf('VA')!==-1 ? 'Nomor Virtual Account:' : 'Kode Pembayaran:');
        document.getElementById('isoTpVaCode').textContent = d.pay_code || '-';
    }

    document.getElementById('mIsoTripayDetail').style.display = 'flex';
    if(isoPollTimer) clearInterval(isoPollTimer);
    isoPollTimer = setInterval(isoCheckStatusNow, 4000);
}

function closeIsoTripayDetail(){
    if(isoPollTimer) clearInterval(isoPollTimer);
    document.getElementById('mIsoTripayDetail').style.display = 'none';
}

function copyIsoTpCode(){
    const v = document.getElementById('isoTpVaCode').textContent;
    if(!v || v==='-') return;
    navigator.clipboard.writeText(v).then(()=>{
        const btn = document.getElementById('isoBtnCopy');
        const orig = btn.textContent;
        btn.textContent = '✓ Tersalin';
        setTimeout(()=>btn.textContent = orig, 1800);
    });
}

function isoCheckStatusNow(){
    if(!isoActiveOrderId && !isoActiveRef) return;
    fetch('/portal/isolir.php?user='+encodeURIComponent(UNAME)+'&rid='+RID,{
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=check_status&order_id='+encodeURIComponent(isoActiveOrderId)+'&reference='+encodeURIComponent(isoActiveRef)
    })
    .then(r=>r.json())
    .then(res=>{
        if(res.status==='paid'){
            if(isoPollTimer) clearInterval(isoPollTimer);
            alert('✅ Pembayaran Berhasil! Internet Anda telah aktif.');
            window.location='/portal/isolir.php?user='+encodeURIComponent(UNAME)+'&rid='+RID+'&paid=1&gateway=tripay';
        }
    })
    .catch(()=>{});
}

function startPayment(){
    openIsolirPayment();
}
<?php endif; ?>
</script>
</body>
</html>
