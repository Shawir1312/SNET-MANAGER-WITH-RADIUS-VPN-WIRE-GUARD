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

if($_SERVER['REQUEST_METHOD']==='POST'){
    $act=$_POST['action']??'';

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

$logo=logoB64();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Portal — <?=h($custRow['full_name'])?></title>
<link href="https://fonts.googleapis.com/css2?family=Exo+2:wght@400;600;700;800;900&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet">
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
</script>
</body>
</html>
