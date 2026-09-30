<?php
/**
 * Pelanggan Rumahan — List
 * Kriteria Pelanggan Rumahan:
 * 1. Sudah di-mapping ONT (ont_sn != '')
 * 2. Sudah di-mapping Secret PPPoE (pppoe_username != '')
 * 3. Sudah di-mapping Profil Paket (profile != '')
 * 4. TIDAK BEBAS IURAN (is_free = 0 dan monthly_price > 0)
 */
$page_title = 'Pelanggan Rumahan';
$routers = get_all_routers();
$selRid = (int)get('router_id', 0); // 0 = Semua Cabang

$selRouter = null;
if ($selRid > 0) {
    foreach ($routers as $r) {
        if ($r['id'] == $selRid) {
            $selRouter = $r;
            break;
        }
    }
}

$search = trim(get('q', ''));
$filter_status = get('status', '');

// Filter Bulan Pemakaian Data & Pembayaran
$filter_month = get('month', date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $filter_month)) {
    $filter_month = date('Y-m');
}
list($filter_year_str, $filter_m_str) = explode('-', $filter_month);
$filter_year = (int)$filter_year_str;
$filter_m    = (int)$filter_m_str;
$is_curr_m   = ($filter_month === date('Y-m'));

$month_options = [];
$mIndo = [1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',5=>'Mei',6=>'Juni',7=>'Juli',8=>'Agustus',9=>'September',10=>'Oktober',11=>'November',12=>'Desember'];
for ($i = 0; $i < 12; $i++) {
    $time = strtotime("-$i months");
    $val = date('Y-m', $time);
    $moNum = (int)date('n', $time);
    $yrNum = date('Y', $time);
    $lbl = ($i === 0 ? 'Bulan Ini (' : '') . ($mIndo[$moNum] ?? '') . ' ' . $yrNum . ($i === 0 ? ')' : '');
    $month_options[$val] = $lbl;
}
$selected_month_label = $month_options[$filter_month] ?? (($mIndo[$filter_m] ?? '') . ' ' . $filter_year);

// Base criteria for Pelanggan Rumahan:
// ONT mapped, Secret PPPoE mapped, Profile mapped, NOT bebas iuran
$where_clauses = [
    "pc.ont_sn IS NOT NULL AND pc.ont_sn != '' AND pc.ont_sn != '0'",
    "pc.pppoe_username IS NOT NULL AND pc.pppoe_username != ''",
    "pc.profile IS NOT NULL AND pc.profile != ''",
    "(pc.is_free = 0 OR pc.is_free IS NULL)",
    "pc.monthly_price > 0"
];
$params = [];
$types = "";

if ($selRid > 0) {
    $where_clauses[] = "pc.router_id = ?";
    $params[] = $selRid;
    $types .= "i";
}

if ($search !== '') {
    $where_clauses[] = "(pc.pppoe_username LIKE ? OR pc.full_name LIKE ? OR pc.ont_sn LIKE ? OR pc.phone LIKE ? OR pc.address LIKE ?)";
    $kw = "%$search%";
    $params[] = $kw;
    $params[] = $kw;
    $params[] = $kw;
    $params[] = $kw;
    $params[] = $kw;
    $types .= "sssss";
}

if ($filter_status === 'active') {
    $where_clauses[] = "pc.status = 'active'";
} elseif ($filter_status === 'isolated') {
    $where_clauses[] = "pc.status = 'isolated'";
} elseif ($filter_status === 'suspended') {
    $where_clauses[] = "pc.status = 'suspended'";
}

$where_sql = "WHERE " . implode(" AND ", $where_clauses);

// Query Pelanggan Rumahan beserta data pembayaran bulan yang dipilih
$sql = "SELECT pc.*, 
               r.name AS router_name,
               COALESCE(pay.paid_this_month, 0) AS paid_this_month,
               COALESCE(pay.pending_this_month, 0) AS pending_this_month
        FROM pppoe_customers pc 
        JOIN routers r ON pc.router_id = r.id
        LEFT JOIN (
            SELECT customer_id,
                   SUM(CASE WHEN (midtrans_status = 'paid' OR payment_method = 'cash' OR (midtrans_status NOT IN ('pending','cancel','deny','expire') AND midtrans_status IS NOT NULL)) THEN amount ELSE 0 END) AS paid_this_month,
                   SUM(CASE WHEN midtrans_status = 'pending' THEN 1 ELSE 0 END) AS pending_this_month
            FROM pppoe_payments
            WHERE period_year = {$filter_year} AND period_month = {$filter_m}
            GROUP BY customer_id
        ) pay ON pay.customer_id = pc.id
        $where_sql 
        ORDER BY pc.status ASC, pc.full_name ASC";

$customers = db_fetch_all($sql, $types, $params);

// Hitung statistik untuk badge & widget atas
$stat_total = count($customers);
$stat_mrr = 0;
$stat_lunas = 0;
$stat_lunas_nominal = 0;
$stat_nunggak = 0;
$stat_nunggak_nominal = 0;
$stat_isolir = 0;

$today = (int)date('j');
foreach ($customers as $c) {
    $price = (float)$c['monthly_price'];
    $stat_mrr += $price;
    $is_paid = (float)$c['paid_this_month'] > 0;
    
    if ($c['status'] === 'isolated') {
        $stat_isolir++;
    }
    
    if ($is_paid) {
        $stat_lunas++;
        $stat_lunas_nominal += (float)$c['paid_this_month'];
    } else {
        if ($c['due_day'] <= $today) {
            $stat_nunggak++;
            $stat_nunggak_nominal += $price;
        }
    }
}

// ── 1. AMBIL SESI AKTIF DARI RADIUS (radacct) ──
$active_sessions = [];
try {
    $radSessions = db_fetch_all("SELECT username, framedipaddress AS address, acctstarttime FROM radacct WHERE acctstoptime IS NULL");
    if (is_array($radSessions)) {
        foreach ($radSessions as $rs) {
            $u = trim($rs['username'] ?? '');
            if ($u !== '') {
                $start = strtotime($rs['acctstarttime'] ?? '');
                $uptime = '';
                if ($start > 0) {
                    $diff = max(0, time() - $start);
                    $h = floor($diff / 3600);
                    $m = floor(($diff % 3600) / 60);
                    $uptime = ($h > 0 ? "{$h}j " : "") . "{$m}m";
                }
                $active_sessions[$u] = [
                    'name'    => $u,
                    'address' => $rs['address'] ?: 'Online',
                    'uptime'  => $uptime,
                    'source'  => 'radius'
                ];
            }
        }
    }
} catch (Throwable $e) {}

// ── 1b. AMBIL PEMAKAIAN DATA TIAP BULAN DARI RADIUS (radacct) ──
$monthly_usage = [];
$stat_total_usage_bytes = 0;
$stat_total_dl_bytes = 0;
$stat_total_ul_bytes = 0;
try {
    if ($is_curr_m) {
        $radUsage = db_fetch_all(
            "SELECT username,
                    COALESCE(SUM(CASE WHEN acctstoptime IS NOT NULL THEN acctoutputoctets ELSE 0 END), 0) AS closed_dl,
                    COALESCE(SUM(CASE WHEN acctstoptime IS NOT NULL THEN acctinputoctets ELSE 0 END), 0) AS closed_ul,
                    COALESCE(SUM(CASE WHEN acctstoptime IS NULL THEN acctoutputoctets ELSE 0 END), 0) AS active_dl,
                    COALESCE(SUM(CASE WHEN acctstoptime IS NULL THEN acctinputoctets ELSE 0 END), 0) AS active_ul,
                    COALESCE(SUM(acctoutputoctets), 0) AS dl_bytes,
                    COALESCE(SUM(acctinputoctets), 0) AS ul_bytes,
                    COALESCE(SUM(acctoutputoctets + acctinputoctets), 0) AS total_bytes,
                    COALESCE(SUM(acctsessiontime), 0) AS total_secs,
                    COUNT(*) AS session_count
             FROM radacct
             WHERE (YEAR(acctstarttime) = ? AND MONTH(acctstarttime) = ?)
                OR (acctstoptime IS NULL)
             GROUP BY username",
            'ii', [$filter_year, $filter_m]
        );
    } else {
        $radUsage = db_fetch_all(
            "SELECT username,
                    COALESCE(SUM(acctoutputoctets), 0) AS dl_bytes,
                    COALESCE(SUM(acctinputoctets), 0) AS ul_bytes,
                    COALESCE(SUM(acctoutputoctets + acctinputoctets), 0) AS total_bytes,
                    COALESCE(SUM(acctsessiontime), 0) AS total_secs,
                    COUNT(*) AS session_count
             FROM radacct
             WHERE YEAR(acctstarttime) = ? AND MONTH(acctstarttime) = ?
             GROUP BY username",
            'ii', [$filter_year, $filter_m]
        );
    }
    if (is_array($radUsage)) {
        foreach ($radUsage as $ru) {
            $u = trim($ru['username'] ?? '');
            if ($u !== '') {
                $monthly_usage[$u] = [
                    'dl'         => (float)$ru['dl_bytes'],
                    'ul'         => (float)$ru['ul_bytes'],
                    'total'      => (float)$ru['total_bytes'],
                    'closed_dl'  => (float)($ru['closed_dl'] ?? 0),
                    'closed_ul'  => (float)($ru['closed_ul'] ?? 0),
                    'active_dl'  => (float)($ru['active_dl'] ?? 0),
                    'active_ul'  => (float)($ru['active_ul'] ?? 0),
                    'secs'       => (int)$ru['total_secs'],
                    'sessions'   => (int)$ru['session_count'],
                    'total_fmt'  => format_bytes((float)$ru['total_bytes']),
                    'dl_fmt'     => format_bytes((float)$ru['dl_bytes']),
                    'ul_fmt'     => format_bytes((float)$ru['ul_bytes']),
                ];
            }
        }
    }
} catch (Throwable $e) {}

// Akumulasi total pemakaian data untuk pelanggan yang tampil
foreach ($customers as $c) {
    $u = trim($c['pppoe_username'] ?? '');
    if (isset($monthly_usage[$u])) {
        $stat_total_usage_bytes += $monthly_usage[$u]['total'];
        $stat_total_dl_bytes += $monthly_usage[$u]['dl'];
        $stat_total_ul_bytes += $monthly_usage[$u]['ul'];
    }
}

// ── 2. CEK SESI MIKROTIK ROUTEROS API ──
$api_error = '';
$routersToCheck = [];
if ($selRouter) {
    $routersToCheck[] = $selRouter;
} else {
    // Ambil router unik dari pelanggan yang sedang tampil (maks 5 router agar respon tetap cepat)
    $custRouterIds = array_unique(array_filter(array_column($customers, 'router_id')));
    if (!empty($custRouterIds)) {
        $idsList = implode(',', array_map('intval', array_slice($custRouterIds, 0, 5)));
        try {
            $routersToCheck = db_fetch_all("SELECT * FROM routers WHERE id IN ($idsList) AND (status = 'active' OR status IS NULL OR status = '')");
            if (empty($routersToCheck)) {
                $routersToCheck = db_fetch_all("SELECT * FROM routers WHERE id IN ($idsList)");
            }
        } catch (Throwable $e) {}
    }
    if (empty($routersToCheck)) {
        try {
            $routersToCheck = db_fetch_all("SELECT * FROM routers WHERE status = 'active' LIMIT 5");
            if (empty($routersToCheck)) {
                $routersToCheck = db_fetch_all("SELECT * FROM routers LIMIT 5");
            }
        } catch (Throwable $e) {}
    }
}

$mikrotik_traffic = [];
if (!empty($routersToCheck)) {
    require_once __DIR__ . '/../../../lib/routeros_api.class.php';
    foreach ($routersToCheck as $rtr) {
        try {
            $api = new RouterosAPI();
            $api->debug = false;
            $api->timeout = 3.0; // 3 detik agar stabil via VPN / WireGuard
            $api->attempts = 1;
            $api->delay = 0;
            $rPort = !empty($rtr['api_port']) ? (int)$rtr['api_port'] : 8728;
            if ($api->connect($rtr['ip_address'], $rtr['api_user'], $rtr['api_password'], $rPort)) {
                // 1. Ambil Sesi Aktif PPPoE
                $acts = $api->comm('/ppp/active/print', [
                    '.proplist' => 'name,address,uptime'
                ]);
                if (is_array($acts)) {
                    foreach ($acts as $a) {
                        if (isset($a['name']) && $a['name'] !== '') {
                            $actItem = [
                                'name'    => $a['name'],
                                'address' => $a['address'] ?? '',
                                'uptime'  => $a['uptime'] ?? '',
                                'source'  => 'mikrotik'
                            ];
                            $active_sessions[$a['name']] = $actItem;
                            $uClean = preg_replace('/@.*$/', '', $a['name']);
                            $active_sessions[$uClean] = $actItem;
                        }
                    }
                }

                // 2. Ambil Real-Time Tx & Rx Byte dari Interface PPPoE Server Binding (<pppoe-username>)
                $ifaces = $api->comm('/interface/print', [
                    '.proplist' => 'name,type,tx-byte,rx-byte,bytes,running'
                ]);
                if (is_array($ifaces)) {
                    foreach ($ifaces as $if) {
                        $ifName = trim($if['name'] ?? '');
                        if (preg_match('/^<?pppoe-(.+?)>?$/i', $ifName, $mMatch)) {
                            $uName = $mMatch[1];
                            $txB = (float)($if['tx-byte'] ?? $if['tx_byte'] ?? 0);
                            $rxB = (float)($if['rx-byte'] ?? $if['rx_byte'] ?? 0);
                            if ($txB === 0.0 && $rxB === 0.0 && !empty($if['bytes'])) {
                                $bParts = explode('/', (string)$if['bytes']);
                                if (count($bParts) === 2) {
                                    $rxB = (float)trim($bParts[0]);
                                    $txB = (float)trim($bParts[1]);
                                }
                            }
                            $totB = $txB + $rxB; // Total Pemakaian = Tx Byte + Rx Byte
                            $trafficItem = [
                                'tx'        => $txB,
                                'rx'        => $rxB,
                                'total'     => $totB,
                                'tx_fmt'    => format_bytes($txB),
                                'rx_fmt'    => format_bytes($rxB),
                                'total_fmt' => format_bytes($totB)
                            ];
                            $mikrotik_traffic[$uName] = $trafficItem;
                            $uClean = preg_replace('/@.*$/', '', $uName);
                            $mikrotik_traffic[$uClean] = $trafficItem;
                        }
                    }
                }

                $api->disconnect();
            }
        } catch (Throwable $e) {
            if ($selRouter) {
                $api_error = $e->getMessage();
            }
        }
    }
}

// ── Sinkronisasi Real-Time Tx/Rx MikroTik ke Data Pemakaian Bulan Berjalan ──
if ($is_curr_m && !empty($mikrotik_traffic)) {
    foreach ($mikrotik_traffic as $uName => $mt) {
        if (!isset($monthly_usage[$uName])) {
            $monthly_usage[$uName] = [
                'dl'         => $mt['tx'],
                'ul'         => $mt['rx'],
                'total'      => $mt['total'], // Tx + Rx = Total Pemakaian
                'closed_dl'  => 0,
                'closed_ul'  => 0,
                'active_dl'  => $mt['tx'],
                'active_ul'  => $mt['rx'],
                'secs'       => 0,
                'sessions'   => 1,
                'total_fmt'  => $mt['total_fmt'],
                'dl_fmt'     => $mt['tx_fmt'],
                'ul_fmt'     => $mt['rx_fmt'],
                'live'       => true,
            ];
        } else {
            // Gabungkan sesi yang sudah selesai (closed) dengan sesi aktif realtime dari MikroTik
            $cDl = (float)($monthly_usage[$uName]['closed_dl'] ?? 0);
            $cUl = (float)($monthly_usage[$uName]['closed_ul'] ?? 0);
            $aDl = max((float)($monthly_usage[$uName]['active_dl'] ?? 0), (float)$mt['tx']);
            $aUl = max((float)($monthly_usage[$uName]['active_ul'] ?? 0), (float)$mt['rx']);

            $newDl = $cDl + $aDl;
            $newUl = $cUl + $aUl;
            $newTot = $newDl + $newUl; // Tx Byte + Rx Byte = Total Pemakaian

            $monthly_usage[$uName]['dl'] = $newDl;
            $monthly_usage[$uName]['ul'] = $newUl;
            $monthly_usage[$uName]['total'] = $newTot;
            $monthly_usage[$uName]['dl_fmt'] = format_bytes($newDl);
            $monthly_usage[$uName]['ul_fmt'] = format_bytes($newUl);
            $monthly_usage[$uName]['total_fmt'] = format_bytes($newTot);
            $monthly_usage[$uName]['live'] = true;
        }
    }
    
    // Hitung ulang akumulasi total pemakaian
    $stat_total_usage_bytes = 0;
    $stat_total_dl_bytes = 0;
    $stat_total_ul_bytes = 0;
    foreach ($customers as $c) {
        $u = trim($c['pppoe_username'] ?? '');
        if (isset($monthly_usage[$u])) {
            $stat_total_usage_bytes += $monthly_usage[$u]['total'];
            $stat_total_dl_bytes += $monthly_usage[$u]['dl'];
            $stat_total_ul_bytes += $monthly_usage[$u]['ul'];
        }
    }
}

// ── 3. CEK STATUS MODEM ONT DI GENIEACS (TR-069) ──
$ont_status_map = [];
try {
    $genieServer = null;
    if ($selRouter && !empty($selRouter['genie_server_id'])) {
        $genieServer = db_fetch_one("SELECT * FROM genie_config WHERE id = ? AND is_active = 1", 'i', [$selRouter['genie_server_id']]);
    }
    if (!$genieServer) {
        $genieServer = db_fetch_one("SELECT * FROM genie_config WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
    }

    $hasOnt = false;
    foreach ($customers as $c) {
        if (!empty($c['ont_sn']) && $c['ont_sn'] !== '0') {
            $hasOnt = true;
            break;
        }
    }

    if ($genieServer && $hasOnt) {
        require_once __DIR__ . '/../../../include/GenieACS.php';
        $genieApi = new GenieACS($genieServer['url'], $genieServer['username'], $genieServer['password']);
        $genieApi->connectTimeout = 2;
        $genieApi->timeout = 4;
        
        $proj = '_id,_lastInform,_deviceId._SerialNumber,InternetGatewayDevice.DeviceInfo.SerialNumber';
        $genieDevs = $genieApi->getDevices('{}', $proj);
        if (is_array($genieDevs)) {
            foreach ($genieDevs as $gd) {
                $snVal = $gd['_deviceId']['_SerialNumber'] 
                      ?? ($gd['InternetGatewayDevice']['DeviceInfo']['SerialNumber']['_value'] ?? '') 
                      ?? ($gd['_id'] ?? '');
                $snVal = strtoupper(trim((string)$snVal));
                
                if (strpos($snVal, '-') !== false) {
                    $parts = explode('-', $snVal);
                    $pureSn = strtoupper(trim(end($parts)));
                } else {
                    $pureSn = $snVal;
                }
                
                $lastInform = strtotime($gd['_lastInform'] ?? '0');
                $diffMin = $lastInform > 0 ? (time() - $lastInform) / 60 : 9999;
                $isOntOnline = ($diffMin < 20); // Margin 20 menit
                
                $data = [
                    'online'      => $isOntOnline,
                    'diff_min'    => round($diffMin),
                    'last_inform' => $lastInform,
                    'raw_id'      => $gd['_id'] ?? ''
                ];
                
                if ($pureSn !== '') {
                    $ont_status_map[$pureSn] = $data;
                }
                if ($snVal !== '' && $snVal !== $pureSn) {
                    $ont_status_map[$snVal] = $data;
                }
            }
        }
    }
} catch (Throwable $e) {}

// Filter tambahan jika user memilih filter koneksi (online / offline)
if ($filter_status === 'online') {
    $customers = array_filter($customers, function($c) use ($active_sessions, $ont_status_map) {
        $sn = strtoupper(trim($c['ont_sn'] ?? ''));
        $is_ppp = isset($active_sessions[$c['pppoe_username']]);
        $is_ont = !empty($sn) && !empty($ont_status_map[$sn]['online']);
        return ($is_ppp || $is_ont);
    });
} elseif ($filter_status === 'offline') {
    $customers = array_filter($customers, function($c) use ($active_sessions, $ont_status_map) {
        $sn = strtoupper(trim($c['ont_sn'] ?? ''));
        $is_ppp = isset($active_sessions[$c['pppoe_username']]);
        $is_ont = !empty($sn) && !empty($ont_status_map[$sn]['online']);
        return (!$is_ppp && !$is_ont);
    });
}

// Ambil template WA
$wa_templates = [];
try {
    $wa_templates = db_fetch_all("SELECT * FROM wa_templates WHERE is_active = 1 ORDER BY id ASC");
} catch (Exception $e) {}

// Ambil setting PPPoE
$settings_raw = [];
try {
    $settings_raw = db_fetch_all("SELECT setting_key, setting_value FROM pppoe_settings");
} catch (Exception $e) {}
$pppoe_settings = [];
foreach ($settings_raw as $s) {
    $pppoe_settings[$s['setting_key']] = $s['setting_value'];
}
$company_name = $pppoe_settings['company_name'] ?? (defined('APP_COMPANY') ? APP_COMPANY : 'S.NET Internet');
$company_phone = $pppoe_settings['company_phone'] ?? '';

include __DIR__ . '/../../../include/header.php';
?>
<style>
.sts-active { background:#DCFCE7; color:#15803D; padding:3px 8px; border-radius:12px; font-size:12px; font-weight:600; display:inline-flex; align-items:center; gap:4px; }
.sts-isolated { background:#FEE2E2; color:#DC2626; padding:3px 8px; border-radius:12px; font-size:12px; font-weight:600; display:inline-flex; align-items:center; gap:4px; }
.sts-suspended { background:#FEF3C7; color:#D97706; padding:3px 8px; border-radius:12px; font-size:12px; font-weight:600; display:inline-flex; align-items:center; gap:4px; }
.online-dot { width:8px; height:8px; border-radius:50%; background:#22C55E; display:inline-block; box-shadow:0 0 0 3px rgba(34,197,94,.2); animation:dp 2s infinite; }
@keyframes dp { 0%{box-shadow:0 0 0 0 rgba(34,197,94,.4)} 70%{box-shadow:0 0 0 6px rgba(34,197,94,0)} 100%{box-shadow:0 0 0 0 rgba(34,197,94,0)} }
.rumahan-card { border-radius:10px; border:none; box-shadow:0 2px 6px rgba(0,0,0,0.06); transition:transform .15s; }
.rumahan-card:hover { transform: translateY(-2px); }
@keyframes spin { 100% { transform: rotate(360deg); } }
.spin-animation { display: inline-block; animation: spin 0.8s linear infinite; }
</style>

<div class="page-header">
    <div>
        <h1 class="page-title"><i class="bi bi-house-door text-primary me-2"></i>Pelanggan Rumahan</h1>
        <p class="page-subtitle">Pelanggan broadband rumahan aktif berbayar — telah ter-mapping ONT, PPPoE Secret, dan Paket Profil.</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if ($selRouter): ?>
        <form method="POST" action="/process/sync_pppoe_mikrotik.php" class="d-inline"
              onsubmit="return confirm('Tarik dan sinkronkan seluruh PPPoE Secrets dari router <?= htmlspecialchars(addslashes($selRouter['name'])) ?> ke Database?')">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="router_id" value="<?= $selRid ?>">
            <button type="submit" class="btn btn-outline-info" title="Tarik & Sinkronkan Secrets dari MikroTik">
                <i class="bi bi-arrow-repeat me-1"></i> Sync MikroTik
            </button>
        </form>
        <?php endif; ?>
        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalPayGlobal" <?= empty($customers) ? 'disabled' : '' ?>>
            <i class="bi bi-cash-stack me-1"></i> Bayar Kasir
        </button>
        <a href="/index.php?page=pppoe_add<?= $selRid ? '&router_id=' . $selRid : '' ?>" class="btn btn-primary">
            <i class="bi bi-person-plus me-1"></i> Tambah Pelanggan
        </a>
    </div>
</div>

<?php if ($api_error): ?>
<div class="alert alert-warning py-2 mb-3"><i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($api_error) ?></div>
<?php endif; ?>

<!-- Stat Widget Banner -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-md-4 col-xl">
        <div class="card rumahan-card bg-white p-3 h-100 border-start border-4 border-primary">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small fw-bold text-uppercase">Total Rumahan</div>
                    <div class="fs-4 fw-bold text-primary"><?= number_format($stat_total, 0, ',', '.') ?> <span class="fs-6 text-muted font-normal">Klien</span></div>
                </div>
                <div class="bg-primary-subtle text-primary p-3 rounded-circle"><i class="bi bi-house-door-fill fs-4"></i></div>
            </div>
            <div class="small text-muted mt-2">Potensi MRR: <strong><?= format_price($stat_mrr) ?></strong></div>
        </div>
    </div>
    
    <div class="col-sm-6 col-md-4 col-xl">
        <div class="card rumahan-card bg-white p-3 h-100 border-start border-4 border-success">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small fw-bold text-uppercase">Lunas (<?= htmlspecialchars($filter_month === date('Y-m') ? 'Bulan Ini' : $selected_month_label) ?>)</div>
                    <div class="fs-4 fw-bold text-success"><?= number_format($stat_lunas, 0, ',', '.') ?> <span class="fs-6 text-muted font-normal">Klien</span></div>
                </div>
                <div class="bg-success-subtle text-success p-3 rounded-circle"><i class="bi bi-check-circle-fill fs-4"></i></div>
            </div>
            <div class="small text-muted mt-2">Terkumpul: <strong><?= format_price($stat_lunas_nominal) ?></strong></div>
        </div>
    </div>

    <div class="col-sm-6 col-md-4 col-xl">
        <div class="card rumahan-card bg-white p-3 h-100 border-start border-4 border-danger">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small fw-bold text-uppercase">Nunggak / Lewat JT</div>
                    <div class="fs-4 fw-bold text-danger"><?= number_format($stat_nunggak, 0, ',', '.') ?> <span class="fs-6 text-muted font-normal">Klien</span></div>
                </div>
                <div class="bg-danger-subtle text-danger p-3 rounded-circle"><i class="bi bi-exclamation-triangle-fill fs-4"></i></div>
            </div>
            <div class="small text-muted mt-2">Tunggakan: <strong><?= format_price($stat_nunggak_nominal) ?></strong></div>
        </div>
    </div>

    <div class="col-sm-6 col-md-4 col-xl">
        <div class="card rumahan-card bg-white p-3 h-100 border-start border-4 border-warning">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small fw-bold text-uppercase">Status Koneksi</div>
                    <div class="fs-4 fw-bold text-warning"><?= count($active_sessions) ?> <span class="fs-6 text-muted font-normal">Online</span></div>
                </div>
                <div class="bg-warning-subtle text-warning p-3 rounded-circle"><i class="bi bi-broadcast fs-4"></i></div>
            </div>
            <div class="small text-muted mt-2">
                <span class="text-danger fw-semibold"><?= $stat_isolir ?> Terisolir</span>
                <?php 
                $cntOntOnline = count(array_filter($ont_status_map, fn($o) => !empty($o['online'])));
                if ($cntOntOnline > 0): 
                ?>
                <span class="text-info fw-semibold">| <?= $cntOntOnline ?> ONT</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-md-4 col-xl">
        <div class="card rumahan-card bg-white p-3 h-100 border-start border-4 border-info">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small fw-bold text-uppercase">Pemakaian (Tx + Rx)</div>
                    <div class="fs-4 fw-bold text-info"><?= format_bytes($stat_total_usage_bytes) ?></div>
                </div>
                <div class="bg-info-subtle text-info p-3 rounded-circle"><i class="bi bi-speedometer2 fs-4"></i></div>
            </div>
            <div class="small text-muted mt-2">
                <span title="Tx Byte (Download)"><i class="bi bi-arrow-down text-success"></i> Tx: <?= format_bytes($stat_total_dl_bytes) ?></span>
                <span class="mx-1">·</span>
                <span title="Rx Byte (Upload)"><i class="bi bi-arrow-up text-primary"></i> Rx: <?= format_bytes($stat_total_ul_bytes) ?></span>
            </div>
        </div>
    </div>
</div>

<div class="card table-card border-0 shadow-sm rounded-4 overflow-hidden mb-4">
    <div class="table-toolbar p-3 bg-white border-bottom">
        <form method="GET" class="row g-2 align-items-center m-0 w-100">
            <input type="hidden" name="page" value="pelanggan_rumahan">
            
            <div class="col-12 col-lg-auto d-flex align-items-center">
                <span class="fw-bold text-dark me-2" style="font-size: 13px;">
                    <span class="badge bg-primary rounded-pill me-1"><?= count($customers) ?></span> Pelanggan Rumahan
                </span>
            </div>
            
            <div class="col-6 col-lg-auto flex-grow-1">
                <select name="router_id" class="form-select form-select-sm shadow-none w-100" style="border-radius: 8px;" onchange="this.form.submit()">
                    <option value="0">🌐 Semua Cabang</option>
                    <?php foreach ($routers as $rt): ?>
                    <option value="<?= $rt['id'] ?>" <?= $selRid == $rt['id'] ? 'selected' : '' ?>>
                        📍 <?= htmlspecialchars($rt['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-6 col-lg-auto flex-grow-1">
                <select name="status" class="form-select form-select-sm shadow-none w-100" style="border-radius: 8px;" onchange="this.form.submit()">
                    <option value="">Semua Status</option>
                    <option value="active" <?= $filter_status === 'active' ? 'selected' : '' ?>>🟢 Status Aktif</option>
                    <option value="isolated" <?= $filter_status === 'isolated' ? 'selected' : '' ?>>🔴 Status Isolir</option>
                    <option value="online" <?= $filter_status === 'online' ? 'selected' : '' ?>>📶 Sedang Online</option>
                    <option value="offline" <?= $filter_status === 'offline' ? 'selected' : '' ?>>📴 Offline</option>
                </select>
            </div>

            <div class="col-6 col-lg-auto flex-grow-1">
                <select name="month" class="form-select form-select-sm shadow-none w-100" style="border-radius: 8px;" onchange="this.form.submit()">
                    <?php foreach ($month_options as $mVal => $mLbl): ?>
                    <option value="<?= $mVal ?>" <?= $filter_month === $mVal ? 'selected' : '' ?>>
                        📅 <?= htmlspecialchars($mLbl) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-12 col-lg-auto flex-grow-1">
                <div class="input-group input-group-sm">
                    <input type="text" name="q" class="form-control shadow-none" style="border-radius: 8px 0 0 8px;" placeholder="Cari nama, user, ONT SN..." value="<?= htmlspecialchars($search) ?>">
                    <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i></button>
                    <?php if ($search !== '' || $filter_status !== '' || $selRid > 0 || $filter_month !== date('Y-m')): ?>
                    <a href="/index.php?page=pelanggan_rumahan" class="btn btn-outline-danger" title="Reset Filter" style="border-radius: 0 8px 8px 0;"><i class="bi bi-x-lg"></i></a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>

    <!-- Tampilan Desktop: Tabel Lengkap (Layar >= 992px) -->
    <div class="table-responsive d-none d-lg-block">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>Status</th>
                    <th>Username PPPoE</th>
                    <th>Nama & Kontak</th>
                    <th>Mapping ONT (Modem)</th>
                    <th>Paket Profil</th>
                    <th>Pemakaian (Tx + Rx)</th>
                    <th>Tagihan & JT</th>
                    <th>Sesi Online</th>
                    <th style="min-width: 140px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($customers)): ?>
            <tr>
                <td colspan="9" class="text-center text-muted py-5">
                    <i class="bi bi-house-x display-4 text-muted d-block mb-2"></i>
                    Belum ada data pelanggan rumahan yang cocok dengan filter.<br>
                    <small class="text-muted">Pelanggan rumahan harus memiliki: ONT SN terisi, PPPoE Username terisi, Paket Profil terisi, dan bukan Bebas Iuran.</small>
                </td>
            </tr>
            <?php else: ?>
            <?php foreach ($customers as $c): 
                $sn = strtoupper(trim($c['ont_sn'] ?? ''));
                $is_ppp_online = isset($active_sessions[$c['pppoe_username']]);
                $is_ont_online = (!empty($sn) && !empty($ont_status_map[$sn]['online']));
                $is_online = ($is_ppp_online || $is_ont_online);
                $is_late = ($c['status'] === 'active' && $c['due_day'] <= $today && !(float)$c['paid_this_month']);
                $cleanPhone = preg_replace('/[^0-9]/', '', $c['phone'] ?? '');
                if (str_starts_with($cleanPhone, '0')) {
                    $cleanPhone = '62' . substr($cleanPhone, 1);
                }
            ?>
            <tr <?= $c['status'] === 'isolated' ? 'style="background-color: var(--red-pale);"' : '' ?>>
                <td>
                    <?php if ($c['status'] === 'isolated'): ?>
                        <span class="sts-isolated">🔴 Isolir</span>
                    <?php elseif ($is_ppp_online): ?>
                        <span class="sts-active" title="PPPoE Dial Online (<?= htmlspecialchars($active_sessions[$c['pppoe_username']]['address'] ?? '') ?>)">🟢 Online</span>
                    <?php elseif ($is_ont_online): ?>
                        <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle fw-semibold px-2 py-1" title="Modem ONT Terhubung ke OLT/GenieACS">📶 ONT Online</span>
                    <?php elseif ($is_late): ?>
                        <span class="sts-suspended">⚠️ Jatuh Tempo</span>
                    <?php elseif ($c['status'] === 'active'): ?>
                        <span class="badge bg-secondary-subtle text-secondary border fw-semibold px-2 py-1">● Offline</span>
                    <?php else: ?>
                        <span class="sts-isolated">🔴 <?= htmlspecialchars(ucfirst($c['status'])) ?></span>
                    <?php endif; ?>
                </td>
                
                <td>
                    <strong class="font-mono text-primary" style="font-size:13px;"><?= htmlspecialchars($c['pppoe_username']) ?></strong>
                    <div class="text-muted" style="font-size:11px;">
                        <i class="bi bi-router"></i> <?= htmlspecialchars($c['router_name']) ?>
                    </div>
                    <div class="mt-1 d-flex align-items-center gap-1 flex-wrap">
                        <span class="badge bg-light text-secondary border font-mono" style="font-size:10px;" title="ID Login Portal Pelanggan">
                            <i class="bi bi-person-lock text-primary me-1"></i><?= htmlspecialchars($c['portal_username'] ?: $c['pppoe_username']) ?>
                        </span>
                        <?php if (!empty($c['portal_password_plain'])): ?>
                        <span class="badge bg-warning-subtle text-dark border border-warning font-mono" style="font-size:10px;" title="Password Portal Pelanggan">
                            <i class="bi bi-key-fill text-warning me-1"></i><?= htmlspecialchars($c['portal_password_plain']) ?>
                        </span>
                        <?php endif; ?>
                        <button type="button" class="btn btn-outline-secondary py-0 px-1 btn-quick-portal"
                                style="font-size: 10px; line-height: 1.3;"
                                data-id="<?= $c['id'] ?>"
                                data-router="<?= $c['router_id'] ?>"
                                data-name="<?= htmlspecialchars($c['full_name']) ?>"
                                data-username="<?= htmlspecialchars($c['pppoe_username']) ?>"
                                data-portal-user="<?= htmlspecialchars($c['portal_username'] ?: $c['pppoe_username']) ?>"
                                data-portal-pass="<?= htmlspecialchars($c['portal_password_plain'] ?? '') ?>"
                                data-phone="<?= htmlspecialchars($c['phone'] ?? '') ?>"
                                title="Lihat & Atur Password Portal">
                            <i class="bi bi-eye-fill text-primary"></i>
                        </button>
                    </div>
                </td>
                
                <td>
                    <div class="fw-bold text-dark"><?= htmlspecialchars($c['full_name']) ?></div>
                    <?php if (!empty($c['phone'])): ?>
                    <a href="https://wa.me/<?= $cleanPhone ?>" target="_blank" class="text-success text-decoration-none small" style="font-size:11px;">
                        <i class="bi bi-whatsapp"></i> <?= htmlspecialchars($c['phone']) ?>
                    </a>
                    <?php endif; ?>
                    <?php if (!empty($c['address'])): ?>
                    <div class="text-muted text-truncate" style="max-width:200px; font-size:11px;" title="<?= htmlspecialchars($c['address']) ?>">
                        <i class="bi bi-geo-alt"></i> <?= htmlspecialchars($c['address']) ?>
                    </div>
                    <?php endif; ?>
                </td>
                
                <td>
                    <div class="d-flex align-items-center gap-1 flex-wrap">
                        <span class="badge bg-light text-primary border font-mono fw-bold" style="font-size:12px;">
                            <i class="bi bi-hdd-network me-1"></i><?= htmlspecialchars($c['ont_sn']) ?>
                        </span>
                        <?php if ($is_ont_online): ?>
                            <span class="badge bg-success-subtle text-success border border-success-subtle font-mono" style="font-size:10px;" title="Modem ONT Online di GenieACS">
                                <span class="online-dot me-1"></span>ONT Online
                            </span>
                        <?php elseif (!empty($sn)): ?>
                            <span class="badge bg-secondary-subtle text-secondary border font-mono" style="font-size:10px;">
                                ONT Offline
                            </span>
                        <?php endif; ?>
                        <a href="/index.php?page=monitor_ont&search=<?= urlencode($c['ont_sn']) ?>" class="btn btn-sm btn-outline-secondary p-0 px-1" title="Lihat di Monitor ONT" target="_blank">
                            <i class="bi bi-box-arrow-up-right" style="font-size:10px;"></i>
                        </a>
                    </div>
                    <div class="mt-1 d-flex align-items-center justify-content-between pt-1 border-top border-light">
                        <div class="text-truncate" style="max-width: 130px;" title="Nama Wi-Fi: <?= htmlspecialchars($c['ont_wifi_ssid'] ?: '-') ?>">
                            <i class="bi bi-wifi text-primary"></i> <span class="fw-bold text-dark" style="font-size:11px;"><?= htmlspecialchars($c['ont_wifi_ssid'] ?: 'Belum diatur') ?></span>
                        </div>
                        <button type="button" class="btn btn-outline-primary py-0 px-1 btn-quick-wifi"
                                style="font-size: 10px; line-height: 1.3;"
                                data-id="<?= $c['id'] ?>"
                                data-router="<?= $c['router_id'] ?>"
                                data-name="<?= htmlspecialchars($c['full_name']) ?>"
                                data-sn="<?= htmlspecialchars($c['ont_sn']) ?>"
                                data-ssid="<?= htmlspecialchars($c['ont_wifi_ssid'] ?: ('S.NET - ' . explode(' ', $c['full_name'])[0])) ?>"
                                data-real-ssid="<?= htmlspecialchars($c['ont_wifi_ssid'] ?: '') ?>"
                                data-pass="<?= htmlspecialchars($c['ont_wifi_pass'] ?: '') ?>"
                                title="Ganti Nama & Kode Wi-Fi (Push ONT)">
                            <i class="bi bi-pencil-square"></i> Ubah
                        </button>
                    </div>
                </td>
                
                <td>
                    <?php 
                    $currentIsoProfile = !empty($pppoe_settings['isolir_profile']) ? $pppoe_settings['isolir_profile'] : 'isolir';
                    if ($c['status'] === 'isolated'): 
                    ?>
                        <span class="badge bg-danger text-white px-2 py-1"><i class="bi bi-shield-slash me-1"></i><?= htmlspecialchars($currentIsoProfile) ?></span>
                        <div class="text-muted" style="font-size: 11px; margin-top: 3px;">Paket: <?= htmlspecialchars($c['profile'] ?: '-') ?></div>
                    <?php else: ?>
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle fw-bold"><?= htmlspecialchars($c['profile'] ?: '-') ?></span>
                    <?php endif; ?>
                    <div class="fw-bold text-dark mt-1" style="font-size:12px;">
                        <?= format_price((float)$c['monthly_price']) ?> <span class="text-muted fw-normal" style="font-size:10px;">/bln</span>
                    </div>
                </td>
                
                <td>
                    <?php 
                    $uData = $monthly_usage[$c['pppoe_username']] ?? null;
                    if ($uData && $uData['total'] > 0): 
                        $uHours = floor($uData['secs'] / 3600);
                        $uMins = floor(($uData['secs'] % 3600) / 60);
                        $uptimeStr = ($uHours > 0 ? "{$uHours}j " : "") . "{$uMins}m";
                        $isLiveWinbox = !empty($uData['live']) || isset($mikrotik_traffic[$c['pppoe_username']]);
                    ?>
                        <div class="d-flex align-items-center gap-1">
                            <span class="fw-bold font-mono text-dark" style="font-size:13px;" title="Total Pemakaian: Tx Byte + Rx Byte">
                                <i class="bi bi-speedometer2 text-success me-1"></i><?= $uData['total_fmt'] ?>
                            </span>
                            <?php if ($isLiveWinbox): ?>
                            <span class="badge bg-success-subtle text-success border border-success-subtle font-mono py-0 px-1" style="font-size:9px;" title="Live Real-time dari Interface WinBox">
                                Live
                            </span>
                            <?php endif; ?>
                        </div>
                        <div class="text-muted" style="font-size:11px;">
                            <span title="Tx Byte (Download)"><i class="bi bi-arrow-down text-success"></i> Tx: <strong><?= $uData['dl_fmt'] ?></strong></span>
                            <span class="mx-1">·</span>
                            <span title="Rx Byte (Upload)"><i class="bi bi-arrow-up text-primary"></i> Rx: <strong><?= $uData['ul_fmt'] ?></strong></span>
                        </div>
                        <div class="text-muted" style="font-size:10px;">
                            <span class="text-secondary font-mono" style="font-size:10px;">(Tx + Rx)</span>
                            <?php if ($uData['secs'] > 0): ?>
                            &bull; <i class="bi bi-clock-history"></i> <?= $uptimeStr ?>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <span class="badge bg-light text-muted border font-mono" style="font-size:11px;" title="Belum ada catatan pemakaian data pada periode ini">
                            0 B
                        </span>
                    <?php endif; ?>
                </td>
                
                <td>
                    <strong>Tgl <?= $c['due_day'] ?></strong>
                    <?php if ($c['paid_this_month'] > 0): ?>
                        <br><span class="badge bg-success-subtle text-success border border-success-subtle"><i class="bi bi-check-circle-fill me-1"></i>Lunas</span>
                    <?php elseif (!empty($c['pending_this_month']) && (int)$c['pending_this_month'] > 0): ?>
                        <br><span class="badge bg-warning-subtle text-warning border border-warning-subtle"><i class="bi bi-hourglass-split me-1"></i>Pending</span>
                    <?php elseif ($is_late): ?>
                        <br><span class="badge bg-danger-subtle text-danger border border-danger-subtle"><i class="bi bi-exclamation-circle-fill me-1"></i>Nunggak</span>
                    <?php else: ?>
                        <br><small class="text-muted">Belum bayar</small>
                    <?php endif; ?>
                </td>
                
                <td>
                    <?php if ($is_ppp_online): ?>
                        <div class="d-flex align-items-center gap-2">
                            <span class="online-dot"></span>
                            <span class="font-mono text-success fw-bold" style="font-size:12px"><?= htmlspecialchars($active_sessions[$c['pppoe_username']]['address'] ?? '') ?></span>
                        </div>
                        <?php if (!empty($active_sessions[$c['pppoe_username']]['uptime'])): ?>
                        <div style="font-size:11px; color:var(--bs-success); margin-left:14px; margin-top:2px;">
                            Up: <?= htmlspecialchars($active_sessions[$c['pppoe_username']]['uptime']) ?>
                        </div>
                        <?php endif; ?>
                    <?php elseif ($is_ont_online): ?>
                        <div class="d-flex align-items-center gap-1">
                            <i class="bi bi-hdd-network text-info"></i>
                            <span class="text-info fw-semibold font-mono" style="font-size:11px">Modem Online</span>
                        </div>
                        <div class="text-muted" style="font-size:10px; margin-left:16px;">
                            Inform: <?= !empty($ont_status_map[$sn]['diff_min']) ? $ont_status_map[$sn]['diff_min'].'m lalu' : 'Baru saja' ?>
                        </div>
                    <?php else: ?>
                        <span class="text-muted" style="font-size:12px">Offline</span>
                    <?php endif; ?>
                </td>
                
                <td>
                    <div class="d-flex gap-1 flex-nowrap">
                        <!-- Kirim Notifikasi WhatsApp Cepat -->
                        <?php if (!empty($c['phone'])): ?>
                        <button type="button" class="btn btn-sm btn-outline-success btn-icon btn-quick-wa"
                                data-id="<?= $c['id'] ?>"
                                data-name="<?= htmlspecialchars($c['full_name']) ?>"
                                data-username="<?= htmlspecialchars($c['pppoe_username']) ?>"
                                data-phone="<?= htmlspecialchars($c['phone']) ?>"
                                data-price="<?= (float)$c['monthly_price'] ?>"
                                data-due="<?= (int)$c['due_day'] ?>"
                                data-status="<?= $c['status'] ?>"
                                title="Kirim WhatsApp Tagihan / Info">
                            <i class="bi bi-whatsapp"></i>
                        </button>
                        <?php endif; ?>

                        <!-- Ganti Nama & Kode Wi-Fi Cepat (Push ONT) -->
                        <button type="button" class="btn btn-sm btn-outline-primary btn-icon btn-quick-wifi"
                                data-id="<?= $c['id'] ?>"
                                data-router="<?= $c['router_id'] ?>"
                                data-name="<?= htmlspecialchars($c['full_name']) ?>"
                                data-sn="<?= htmlspecialchars($c['ont_sn']) ?>"
                                data-ssid="<?= htmlspecialchars($c['ont_wifi_ssid'] ?: ('S.NET - ' . explode(' ', $c['full_name'])[0])) ?>"
                                data-real-ssid="<?= htmlspecialchars($c['ont_wifi_ssid'] ?: '') ?>"
                                data-pass="<?= htmlspecialchars($c['ont_wifi_pass'] ?: '') ?>"
                                title="Ganti Nama & Kode Wi-Fi (Push ONT)">
                            <i class="bi bi-wifi"></i>
                        </button>

                        <!-- Atur ID & Password Portal Pelanggan -->
                        <button type="button" class="btn btn-sm btn-outline-dark btn-icon btn-quick-portal"
                                data-id="<?= $c['id'] ?>"
                                data-router="<?= $c['router_id'] ?>"
                                data-name="<?= htmlspecialchars($c['full_name']) ?>"
                                data-username="<?= htmlspecialchars($c['pppoe_username']) ?>"
                                data-portal-user="<?= htmlspecialchars($c['portal_username'] ?: $c['pppoe_username']) ?>"
                                data-portal-pass="<?= htmlspecialchars($c['portal_password_plain'] ?? '') ?>"
                                data-phone="<?= htmlspecialchars($c['phone'] ?? '') ?>"
                                title="Lihat & Atur Password Portal Pelanggan">
                            <i class="bi bi-person-lock"></i>
                        </button>

                        <!-- Bayar Kasir Cepat -->
                        <button type="button" class="btn btn-sm btn-outline-success btn-icon btn-quick-pay"
                                data-id="<?= $c['id'] ?>"
                                data-router="<?= $c['router_id'] ?>"
                                data-name="<?= htmlspecialchars($c['full_name']) ?>"
                                data-username="<?= htmlspecialchars($c['pppoe_username']) ?>"
                                data-price="<?= (float)$c['monthly_price'] ?>"
                                data-status="<?= $c['status'] ?>"
                                title="Catat Bayar Kasir">
                            <i class="bi bi-cash-coin"></i>
                        </button>

                        <!-- Aksi Cepat Isolir / Buka Isolir -->
                        <?php if ($c['status'] === 'active'): ?>
                        <form method="POST" action="/process/toggle_pppoe_status.php" class="d-inline"
                              onsubmit="return confirm('Apakah Anda yakin ingin MENGISOLIR pelanggan <?= htmlspecialchars(addslashes($c['full_name'])) ?>?')">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="customer_id" value="<?= $c['id'] ?>">
                            <input type="hidden" name="router_id" value="<?= $c['router_id'] ?>">
                            <input type="hidden" name="redirect_page" value="pelanggan_rumahan">
                            <input type="hidden" name="target_status" value="isolated">
                            <button type="submit" class="btn btn-sm btn-outline-warning btn-icon" title="Isolir Sekarang">
                                <i class="bi bi-slash-circle"></i>
                            </button>
                        </form>
                        <?php elseif ($c['status'] === 'isolated'): ?>
                        <form method="POST" action="/process/toggle_pppoe_status.php" class="d-inline"
                              onsubmit="return confirm('Apakah Anda yakin ingin MEMBUKA ISOLIR pelanggan <?= htmlspecialchars(addslashes($c['full_name'])) ?>?')">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="customer_id" value="<?= $c['id'] ?>">
                            <input type="hidden" name="router_id" value="<?= $c['router_id'] ?>">
                            <input type="hidden" name="redirect_page" value="pelanggan_rumahan">
                            <input type="hidden" name="target_status" value="active">
                            <button type="submit" class="btn btn-sm btn-outline-info btn-icon" title="Buka Isolir / Aktifkan Kembali">
                                <i class="bi bi-play-circle-fill"></i>
                            </button>
                        </form>
                        <?php endif; ?>

                        <a href="/index.php?page=pppoe_edit&router_id=<?= $c['router_id'] ?>&id=<?= $c['id'] ?>&from=pelanggan_rumahan"
                           class="btn btn-sm btn-outline-primary btn-icon" title="Edit Data Pelanggan">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <a href="/index.php?page=pppoe_delete&router_id=<?= $c['router_id'] ?>&id=<?= $c['id'] ?>"
                           class="btn btn-sm btn-outline-danger btn-icon"
                           data-confirm="Hapus pelanggan '<?= htmlspecialchars($c['full_name']) ?>' secara permanen dari Database dan MikroTik?"
                           title="Hapus">
                            <i class="bi bi-trash"></i>
                        </a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Tampilan Mobile: Kartu Responsif Modern (Layar HP / Tablet < 992px tanpa scroll ke samping) -->
    <div class="d-lg-none p-2 p-sm-3 bg-light">
        <?php if (empty($customers)): ?>
        <div class="text-center text-muted py-5 bg-white rounded-4 border">
            <i class="bi bi-house-x display-4 text-muted d-block mb-2"></i>
            Belum ada data pelanggan rumahan yang cocok dengan filter.<br>
            <small class="text-muted">Pelanggan rumahan harus memiliki: ONT SN terisi, PPPoE Username terisi, Paket Profil terisi, dan bukan Bebas Iuran.</small>
        </div>
        <?php else: ?>
        <div class="d-flex flex-column gap-3">
        <?php foreach ($customers as $c): 
            $sn = strtoupper(trim($c['ont_sn'] ?? ''));
            $is_ppp_online = isset($active_sessions[$c['pppoe_username']]);
            $is_ont_online = (!empty($sn) && !empty($ont_status_map[$sn]['online']));
            $is_online = ($is_ppp_online || $is_ont_online);
            $is_late = ($c['status'] === 'active' && $c['due_day'] <= $today && !(float)$c['paid_this_month']);
            $cleanPhone = preg_replace('/[^0-9]/', '', $c['phone'] ?? '');
            if (str_starts_with($cleanPhone, '0')) {
                $cleanPhone = '62' . substr($cleanPhone, 1);
            }
            
            // Inisial Nama & Warna Avatar Modern
            $nameParts = explode(' ', trim($c['full_name']));
            $initials = strtoupper(substr($nameParts[0], 0, 1) . (isset($nameParts[1]) ? substr($nameParts[1], 0, 1) : ''));
            if (empty($initials)) { $initials = 'P'; }
            $avatarColors = ['#2563EB', '#7C3AED', '#059669', '#D97706', '#DB2777', '#0891B2', '#4F46E5'];
            $avatarBg = $avatarColors[$c['id'] % count($avatarColors)];
            
            $cardBorderClass = 'border-secondary';
            if ($c['status'] === 'isolated') {
                $cardBorderClass = 'border-danger';
            } elseif ($c['status'] === 'active' && $is_late) {
                $cardBorderClass = 'border-warning';
            } elseif ($c['status'] === 'active' && $is_ppp_online) {
                $cardBorderClass = 'border-success';
            } elseif ($c['status'] === 'active' && $is_ont_online) {
                $cardBorderClass = 'border-info';
            }
        ?>
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white border-start border-4 <?= $cardBorderClass ?>">
            <!-- Header Kartu: Avatar, Nama, Router & Badge Status -->
            <div class="p-3 pb-2 bg-white">
                <div class="d-flex align-items-start justify-content-between gap-2">
                    <div class="d-flex align-items-center gap-2 min-w-0 flex-grow-1">
                        <div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-bold flex-shrink-0 shadow-sm"
                             style="width: 42px; height: 42px; font-size: 14px; background: <?= $avatarBg ?>;">
                            <?= $initials ?>
                        </div>
                        <div class="min-w-0 flex-grow-1">
                            <h6 class="fw-bold text-dark mb-0 fs-6" style="word-break: break-word; line-height: 1.25;">
                                <?= htmlspecialchars($c['full_name']) ?>
                            </h6>
                            <div class="d-flex align-items-center gap-1 mt-1 flex-wrap">
                                <span class="badge bg-light text-primary border font-mono px-2 py-0" style="font-size: 11px;">
                                    <i class="bi bi-person me-1"></i><?= htmlspecialchars($c['pppoe_username']) ?>
                                </span>
                                <span class="text-muted" style="font-size: 11px;">(<?= htmlspecialchars($c['router_name']) ?>)</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="text-end flex-shrink-0">
                        <?php if ($c['status'] === 'isolated'): ?>
                            <span class="badge bg-danger text-white fw-semibold px-2 py-1" style="font-size: 11px;">
                                🔴 Isolir
                            </span>
                        <?php elseif ($is_ppp_online): ?>
                            <span class="badge bg-success-subtle text-success border border-success-subtle fw-semibold px-2 py-1" style="font-size: 11px;">
                                <span class="online-dot me-1"></span>Online
                            </span>
                        <?php elseif ($is_ont_online): ?>
                            <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle fw-semibold px-2 py-1" style="font-size: 11px;" title="Modem ONT Terhubung ke OLT/GenieACS">
                                📶 ONT Online
                            </span>
                        <?php elseif ($is_late): ?>
                            <span class="badge bg-warning-subtle text-warning border border-warning-subtle fw-semibold px-2 py-1" style="font-size: 11px;">
                                ⚠️ Jatuh Tempo
                            </span>
                        <?php elseif ($c['status'] === 'active'): ?>
                            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle fw-semibold px-2 py-1" style="font-size: 11px;">
                                ● Offline
                            </span>
                        <?php else: ?>
                            <span class="badge bg-danger text-white fw-semibold px-2 py-1" style="font-size: 11px;">
                                🔴 Isolir
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Strip Tagihan & Status Pembayaran -->
            <div class="px-3 py-2 border-top border-bottom customer-billing-strip">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted text-uppercase fw-semibold d-block" style="font-size: 10px; letter-spacing: 0.5px;">Biaya Langganan</span>
                        <span class="fw-bold text-dark fs-6 font-mono"><?= format_price((float)$c['monthly_price']) ?></span>
                        <span class="text-muted" style="font-size: 11px;">/bln</span>
                    </div>
                    <div class="text-end">
                        <span class="text-muted text-uppercase fw-semibold d-block" style="font-size: 10px; letter-spacing: 0.5px;">Jatuh Tempo: Tgl <?= $c['due_day'] ?></span>
                        <?php if ($c['paid_this_month'] > 0): ?>
                            <span class="badge bg-success text-white px-2 py-1" style="font-size: 11px;"><i class="bi bi-check-circle-fill me-1"></i>Lunas</span>
                        <?php elseif (!empty($c['pending_this_month']) && (int)$c['pending_this_month'] > 0): ?>
                            <span class="badge bg-warning text-dark px-2 py-1" style="font-size: 11px;"><i class="bi bi-clock-history me-1"></i>Pending</span>
                        <?php elseif ($is_late): ?>
                            <span class="badge bg-danger text-white px-2 py-1" style="font-size: 11px;"><i class="bi bi-exclamation-circle-fill me-1"></i>Nunggak</span>
                        <?php else: ?>
                            <span class="badge bg-secondary text-white px-2 py-1" style="font-size: 11px;">Belum Bayar</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Konten Kartu -->
            <div class="p-3 bg-white">
                <!-- Box Modem ONT & Wi-Fi Modern -->
                <div class="rounded-3 p-2 mb-2 customer-ont-box">
                    <div class="d-flex align-items-center justify-content-between pb-2 mb-2 border-bottom border-light-subtle flex-wrap gap-1">
                        <div class="d-flex align-items-center gap-1 flex-wrap">
                            <i class="bi bi-hdd-network text-primary"></i>
                            <span class="fw-semibold text-secondary" style="font-size: 11px;">SN ONT:</span>
                            <span class="badge bg-white text-dark border font-mono fw-bold px-2 py-1" style="font-size: 11px;">
                                <?= htmlspecialchars($c['ont_sn']) ?>
                            </span>
                            <?php if ($is_ont_online): ?>
                                <span class="badge bg-success-subtle text-success border border-success-subtle font-mono px-2 py-1" style="font-size: 10px;" title="Modem ONT Terhubung ke GenieACS">
                                    <span class="online-dot me-1"></span>ONT Online
                                </span>
                            <?php elseif (!empty($sn)): ?>
                                <span class="badge bg-secondary-subtle text-secondary border font-mono px-2 py-1" style="font-size: 10px;">
                                    ONT Offline
                                </span>
                            <?php endif; ?>
                            <a href="/index.php?page=monitor_ont&search=<?= urlencode($c['ont_sn']) ?>" class="btn btn-sm btn-light border py-0 px-1 text-primary" title="Lihat di Monitor ONT" target="_blank" style="font-size: 11px;">
                                <i class="bi bi-box-arrow-up-right"></i>
                            </a>
                        </div>
                        <button type="button" class="btn btn-sm btn-primary py-1 px-2 btn-quick-wifi fw-semibold"
                                style="font-size: 11px; border-radius: 6px;"
                                data-id="<?= $c['id'] ?>"
                                data-router="<?= $c['router_id'] ?>"
                                data-name="<?= htmlspecialchars($c['full_name']) ?>"
                                data-sn="<?= htmlspecialchars($c['ont_sn']) ?>"
                                data-ssid="<?= htmlspecialchars($c['ont_wifi_ssid'] ?: ('S.NET - ' . explode(' ', $c['full_name'])[0])) ?>"
                                data-real-ssid="<?= htmlspecialchars($c['ont_wifi_ssid'] ?: '') ?>"
                                data-pass="<?= htmlspecialchars($c['ont_wifi_pass'] ?: '') ?>"
                                title="Ganti Nama & Kode Wi-Fi">
                            <i class="bi bi-wifi me-1"></i>Ganti Wi-Fi
                        </button>
                    </div>
                    
                    <!-- Nama Wi-Fi (Tampil Penuh, Tanpa Terpotong) & Password -->
                    <div class="d-flex align-items-start justify-content-between flex-wrap gap-2">
                        <div class="min-w-0" style="flex: 1 1 150px;">
                            <span class="text-muted d-block" style="font-size: 10px; font-weight: 600; text-transform: uppercase;">Nama Wi-Fi (SSID):</span>
                            <div class="fw-bold text-dark" style="font-size: 13px; word-break: break-word;">
                                <i class="bi bi-broadcast text-success me-1"></i><?= htmlspecialchars($c['ont_wifi_ssid'] ?: 'Belum diatur') ?>
                            </div>
                        </div>
                        <?php if (!empty($c['ont_wifi_pass'])): ?>
                        <div class="text-end" style="flex: 0 0 auto;">
                            <span class="text-muted d-block" style="font-size: 10px; font-weight: 600; text-transform: uppercase;">Pass Wi-Fi:</span>
                            <span class="badge bg-white text-dark border font-mono px-2 py-1" style="font-size: 11px;">
                                <i class="bi bi-key-fill text-muted me-1"></i><?= htmlspecialchars($c['ont_wifi_pass']) ?>
                            </span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Box Akun Portal Pelanggan -->
                <div class="d-flex align-items-center justify-content-between rounded-3 p-2 mb-2 customer-portal-box">
                    <div class="d-flex align-items-center gap-1 flex-wrap">
                        <i class="bi bi-shield-lock text-warning-emphasis"></i>
                        <span class="text-secondary fw-semibold" style="font-size: 11px;">Portal:</span>
                        <span class="badge bg-white text-dark border font-mono px-2 py-1" style="font-size: 11px;">
                            <?= htmlspecialchars($c['portal_username'] ?: $c['pppoe_username']) ?>
                        </span>
                        <?php if (!empty($c['portal_password_plain'])): ?>
                        <span class="badge bg-white text-dark border border-warning font-mono px-2 py-1" style="font-size: 11px;" title="Password Portal">
                            <i class="bi bi-key-fill text-warning me-1"></i><?= htmlspecialchars($c['portal_password_plain']) ?>
                        </span>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-warning text-dark py-0 px-2 btn-quick-portal fw-semibold"
                            style="font-size: 11px; border-radius: 6px;"
                            data-id="<?= $c['id'] ?>"
                            data-router="<?= $c['router_id'] ?>"
                            data-name="<?= htmlspecialchars($c['full_name']) ?>"
                            data-username="<?= htmlspecialchars($c['pppoe_username']) ?>"
                            data-portal-user="<?= htmlspecialchars($c['portal_username'] ?: $c['pppoe_username']) ?>"
                            data-portal-pass="<?= htmlspecialchars($c['portal_password_plain'] ?? '') ?>"
                            data-phone="<?= htmlspecialchars($c['phone'] ?? '') ?>"
                            title="Atur Password Portal">
                        <i class="bi bi-pencil-square me-1"></i>Atur
                    </button>
                </div>

                <!-- Box Pemakaian Data Bulan Terpilih (Tx + Rx Byte) -->
                <?php 
                $uData = $monthly_usage[$c['pppoe_username']] ?? null;
                $totFmt = $uData ? $uData['total_fmt'] : '0 B';
                $txFmt  = $uData ? $uData['dl_fmt'] : '0 B';
                $rxFmt  = $uData ? $uData['ul_fmt'] : '0 B';
                $totSecs = $uData ? $uData['secs'] : 0;
                $uHours = floor($totSecs / 3600);
                $uMins = floor(($totSecs % 3600) / 60);
                $uptimeStr = ($uHours > 0 ? "{$uHours}j " : "") . "{$uMins}m";
                $isLiveWinbox = !empty($uData['live']) || isset($mikrotik_traffic[$c['pppoe_username']]);
                ?>
                <div class="rounded-3 p-2 mb-2 customer-usage-box">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <span class="text-muted d-block" style="font-size: 10px; font-weight: 600; text-transform: uppercase;">
                                <i class="bi bi-speedometer2 text-success me-1"></i>Pemakaian Data (Tx + Rx Byte)
                            </span>
                            <div class="d-flex align-items-center gap-1 mt-1">
                                <span class="fw-bold text-success font-mono fs-6">
                                    <?= $totFmt ?>
                                </span>
                                <?php if ($isLiveWinbox): ?>
                                <span class="badge bg-success text-white py-0 px-1 font-mono" style="font-size:9px;">
                                    Live WinBox
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="text-end" style="font-size: 11px;">
                            <div class="text-secondary" title="Tx Byte (Download)"><i class="bi bi-arrow-down text-success"></i> Tx: <span class="fw-bold font-mono text-dark"><?= $txFmt ?></span></div>
                            <div class="text-secondary" title="Rx Byte (Upload)"><i class="bi bi-arrow-up text-primary"></i> Rx: <span class="fw-bold font-mono text-dark"><?= $rxFmt ?></span></div>
                        </div>
                    </div>
                    <div class="pt-1 mt-1 border-top border-success-subtle d-flex align-items-center justify-content-between usage-summary-footer" style="font-size: 10px;">
                        <span><i class="bi bi-calculator me-1"></i>Total = Tx Byte + Rx Byte</span>
                        <?php if ($totSecs > 0): ?>
                        <span><i class="bi bi-clock-history me-1"></i><?= $uptimeStr ?> (<?= $uData['sessions'] ?> Sesi)</span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Kontak, Paket & Info Teknis -->
                <div class="row g-2 mb-2" style="font-size: 12px;">
                    <div class="col-6">
                        <span class="text-muted d-block" style="font-size: 10px; font-weight: 600; text-transform: uppercase;">WhatsApp:</span>
                        <?php if (!empty($c['phone'])): ?>
                            <a href="https://wa.me/<?= $cleanPhone ?>" target="_blank" class="text-success text-decoration-none fw-semibold d-inline-flex align-items-center gap-1">
                                <i class="bi bi-whatsapp"></i> <?= htmlspecialchars($c['phone']) ?>
                            </a>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </div>
                    <div class="col-6 text-end">
                        <span class="text-muted d-block" style="font-size: 10px; font-weight: 600; text-transform: uppercase;">Paket Profil:</span>
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle font-mono"><?= htmlspecialchars($c['profile'] ?: '-') ?></span>
                    </div>
                    <div class="col-6">
                        <span class="text-muted d-block" style="font-size: 10px; font-weight: 600; text-transform: uppercase;">Sesi IP:</span>
                        <?php if ($is_ppp_online): ?>
                            <span class="text-success fw-semibold font-mono" style="font-size: 11px;"><?= htmlspecialchars($active_sessions[$c['pppoe_username']]['address'] ?? '') ?></span>
                            <?php if (!empty($active_sessions[$c['pppoe_username']]['uptime'])): ?>
                                <span class="text-muted" style="font-size: 10px;"> (<?= htmlspecialchars($active_sessions[$c['pppoe_username']]['uptime']) ?>)</span>
                            <?php endif; ?>
                        <?php elseif ($is_ont_online): ?>
                            <span class="text-info fw-semibold font-mono" style="font-size: 11px;">
                                <i class="bi bi-hdd-network me-1"></i>Modem Online (ONT)
                            </span>
                        <?php else: ?>
                            <span class="text-muted" style="font-size: 11px;">Offline</span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($c['address'])): ?>
                    <div class="col-6 text-end">
                        <span class="text-muted d-block" style="font-size: 10px; font-weight: 600; text-transform: uppercase;">Alamat / Lokasi:</span>
                        <span class="text-dark text-truncate d-inline-block" style="max-width: 150px;" title="<?= htmlspecialchars($c['address']) ?>">
                            <i class="bi bi-geo-alt text-danger me-1"></i><?= htmlspecialchars($c['address']) ?>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Tombol Aksi Simetris (Row 1: 3 Tombol Utama, Row 2: Isolir, Edit, Hapus) -->
                <div class="pt-2 border-top">
                    <!-- Row 1: Aksi Utama (3 Kolom Seimbang) -->
                    <div class="d-grid gap-2 mb-2" style="grid-template-columns: 1fr 1fr 1fr;">
                        <?php if (!empty($c['phone'])): ?>
                        <button type="button" class="btn btn-sm btn-outline-success fw-semibold btn-quick-wa d-flex align-items-center justify-content-center gap-1 py-2"
                                data-id="<?= $c['id'] ?>"
                                data-name="<?= htmlspecialchars($c['full_name']) ?>"
                                data-username="<?= htmlspecialchars($c['pppoe_username']) ?>"
                                data-phone="<?= htmlspecialchars($c['phone']) ?>"
                                data-price="<?= (float)$c['monthly_price'] ?>"
                                data-due="<?= (int)$c['due_day'] ?>"
                                data-status="<?= $c['status'] ?>"
                                title="Kirim Pesan WhatsApp">
                            <i class="bi bi-whatsapp"></i> <span>WA</span>
                        </button>
                        <?php else: ?>
                        <button type="button" class="btn btn-sm btn-light text-muted border d-flex align-items-center justify-content-center gap-1 py-2" disabled title="Tidak ada nomor WhatsApp">
                            <i class="bi bi-whatsapp"></i> <span>No WA</span>
                        </button>
                        <?php endif; ?>

                        <button type="button" class="btn btn-sm btn-outline-primary fw-semibold btn-quick-pay d-flex align-items-center justify-content-center gap-1 py-2"
                                data-id="<?= $c['id'] ?>"
                                data-router="<?= $c['router_id'] ?>"
                                data-name="<?= htmlspecialchars($c['full_name']) ?>"
                                data-username="<?= htmlspecialchars($c['pppoe_username']) ?>"
                                data-price="<?= (float)$c['monthly_price'] ?>"
                                data-status="<?= $c['status'] ?>"
                                title="Catat Bayar Kasir">
                            <i class="bi bi-cash-coin"></i> <span>Bayar</span>
                        </button>

                        <button type="button" class="btn btn-sm btn-outline-dark fw-semibold btn-quick-portal d-flex align-items-center justify-content-center gap-1 py-2"
                                data-id="<?= $c['id'] ?>"
                                data-router="<?= $c['router_id'] ?>"
                                data-name="<?= htmlspecialchars($c['full_name']) ?>"
                                data-username="<?= htmlspecialchars($c['pppoe_username']) ?>"
                                data-portal-user="<?= htmlspecialchars($c['portal_username'] ?: $c['pppoe_username']) ?>"
                                data-portal-pass="<?= htmlspecialchars($c['portal_password_plain'] ?? '') ?>"
                                data-phone="<?= htmlspecialchars($c['phone'] ?? '') ?>"
                                title="Lihat & Atur Akun Portal">
                            <i class="bi bi-person-lock"></i> <span>Portal</span>
                        </button>
                    </div>

                    <!-- Row 2: Aksi Manajemen (Isolir, Edit, Hapus) -->
                    <div class="d-flex align-items-center gap-2">
                        <?php if ($c['status'] === 'active'): ?>
                        <form method="POST" action="/process/toggle_pppoe_status.php" class="flex-grow-1 m-0"
                              onsubmit="return confirm('Isolir pelanggan <?= htmlspecialchars(addslashes($c['full_name'])) ?>?')">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="customer_id" value="<?= $c['id'] ?>">
                            <input type="hidden" name="router_id" value="<?= $c['router_id'] ?>">
                            <input type="hidden" name="redirect_page" value="pelanggan_rumahan">
                            <input type="hidden" name="target_status" value="isolated">
                            <button type="submit" class="btn btn-sm btn-outline-warning text-dark w-100 fw-semibold d-flex align-items-center justify-content-center gap-1 py-2" title="Isolir Layanan">
                                <i class="bi bi-slash-circle text-danger"></i> <span>Isolir</span>
                            </button>
                        </form>
                        <?php elseif ($c['status'] === 'isolated'): ?>
                        <form method="POST" action="/process/toggle_pppoe_status.php" class="flex-grow-1 m-0"
                              onsubmit="return confirm('Buka isolir pelanggan <?= htmlspecialchars(addslashes($c['full_name'])) ?>?')">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="customer_id" value="<?= $c['id'] ?>">
                            <input type="hidden" name="router_id" value="<?= $c['router_id'] ?>">
                            <input type="hidden" name="redirect_page" value="pelanggan_rumahan">
                            <input type="hidden" name="target_status" value="active">
                            <button type="submit" class="btn btn-sm btn-outline-success w-100 fw-semibold d-flex align-items-center justify-content-center gap-1 py-2" title="Buka Isolir Layanan">
                                <i class="bi bi-play-circle-fill"></i> <span>Buka Isolir</span>
                            </button>
                        </form>
                        <?php endif; ?>

                        <a href="/index.php?page=pppoe_edit&router_id=<?= $c['router_id'] ?>&id=<?= $c['id'] ?>&from=pelanggan_rumahan"
                           class="btn btn-sm btn-light border text-primary fw-semibold px-3 py-2 d-flex align-items-center gap-1" title="Edit Data Pelanggan">
                            <i class="bi bi-pencil"></i> <span>Edit</span>
                        </a>

                        <a href="/index.php?page=pppoe_delete&router_id=<?= $c['router_id'] ?>&id=<?= $c['id'] ?>"
                           class="btn btn-sm btn-light border text-danger fw-semibold px-3 py-2 d-flex align-items-center gap-1"
                           data-confirm="Hapus pelanggan '<?= htmlspecialchars($c['full_name']) ?>' secara permanen?"
                           title="Hapus Pelanggan">
                            <i class="bi bi-trash"></i> <span>Hapus</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Catat Pembayaran Kasir (Cepat per Pelanggan) -->
<div class="modal fade" id="modalPayCustomer" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="/process/save_pppoe_payment.php" class="modal-content">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="router_id" id="pay_router_id" value="<?= $selRid ?>">
            <input type="hidden" name="customer_id" id="pay_customer_id" value="">
            <input type="hidden" name="redirect_page" value="pelanggan_rumahan">

            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-cash-stack text-success me-2"></i>Catat Pembayaran Pelanggan Rumahan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-light border mb-3">
                    <div class="fw-bold fs-6" id="pay_customer_name">-</div>
                    <div class="font-mono text-muted small" id="pay_customer_username">-</div>
                </div>

                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label fw-bold">Periode Bulan <span class="text-danger">*</span></label>
                        <select name="period_month" class="form-select" required>
                            <?php 
                            $months = [1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',5=>'Mei',6=>'Juni',7=>'Juli',8=>'Agustus',9=>'September',10=>'Oktober',11=>'November',12=>'Desember'];
                            $curM = (int)date('n');
                            foreach ($months as $k => $m):
                            ?>
                            <option value="<?= $k ?>" <?= $curM === $k ? 'selected' : '' ?>><?= $m ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-6">
                        <label class="form-label fw-bold">Periode Tahun <span class="text-danger">*</span></label>
                        <input type="number" name="period_year" class="form-control" value="<?= date('Y') ?>" required min="2024" max="2099">
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-bold">Nominal Pembayaran (Rp) <span class="text-danger">*</span></label>
                        <input type="number" name="amount" id="pay_amount" class="form-control fs-5 fw-bold text-success" required min="1000" step="1000">
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-bold">Metode Pembayaran</label>
                        <select name="payment_method" class="form-select">
                            <option value="cash">💵 Tunai / Cash (Kasir Kantor)</option>
                            <option value="transfer">🏦 Transfer Bank</option>
                            <option value="qris">📱 QRIS / E-Wallet</option>
                            <option value="other">Lainnya</option>
                        </select>
                    </div>

                    <div class="col-12">
                        <label class="form-label">Catatan / Keterangan (Opsional)</label>
                        <input type="text" name="notes" class="form-control" placeholder="Contoh: Lunas bayar di loket">
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-bold">Petugas / Teknisi Penerima</label>
                        <?php $curAdmin = current_admin(); $defCollector = ($curAdmin['full_name'] ?? '') ?: (($curAdmin['name'] ?? '') ?: ($curAdmin['username'] ?? 'Kasir / Admin')); ?>
                        <input type="text" name="collector_name" class="form-control" value="<?= htmlspecialchars($defCollector) ?>" placeholder="Nama teknisi / admin penerima uang">
                    </div>

                    <div class="col-12">
                        <div class="form-check form-switch mt-1">
                            <input class="form-check-input" type="checkbox" name="send_wa" value="1" id="checkSendWaCustomer" checked>
                            <label class="form-check-label fw-bold text-success" for="checkSendWaCustomer">
                                <i class="bi bi-whatsapp me-1"></i> Kirim WhatsApp Bukti Pembayaran Lunas ke Pelanggan
                            </label>
                        </div>
                    </div>

                    <div class="col-12" id="pay_unisolir_wrap">
                        <div class="form-check form-switch mt-1">
                            <input class="form-check-input" type="checkbox" name="auto_unisolir" value="1" id="checkAutoUnisolir" checked>
                            <label class="form-check-label fw-bold text-primary" for="checkAutoUnisolir">
                                Otomatis Buka Isolir di MikroTik jika pelanggan terisolir
                            </label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-success px-4"><i class="bi bi-check-lg me-1"></i> Simpan Pembayaran</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Catat Pembayaran Global (Pilih Pelanggan Rumahan) -->
<div class="modal fade" id="modalPayGlobal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="/process/save_pppoe_payment.php" class="modal-content">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="redirect_page" value="pelanggan_rumahan">

            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-cash-stack text-success me-2"></i>Catat Bayar Pelanggan Rumahan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label fw-bold">Pilih Pelanggan Rumahan <span class="text-danger">*</span></label>
                        <select name="customer_id" class="form-select select2-customer" required onchange="updateGlobalPrice(this)">
                            <option value="">-- Cari Nama / Username PPPoE --</option>
                            <?php foreach ($customers as $c): ?>
                            <option value="<?= $c['id'] ?>" data-price="<?= (float)$c['monthly_price'] ?>" data-router="<?= $c['router_id'] ?>">
                                <?= htmlspecialchars($c['full_name']) ?> (<?= htmlspecialchars($c['pppoe_username']) ?>) — <?= format_price((float)$c['monthly_price']) ?> [Cabang <?= htmlspecialchars($c['router_name']) ?>]
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-6">
                        <label class="form-label fw-bold">Periode Bulan <span class="text-danger">*</span></label>
                        <select name="period_month" class="form-select" required>
                            <?php foreach ($months as $k => $m): ?>
                            <option value="<?= $k ?>" <?= $curM === $k ? 'selected' : '' ?>><?= $m ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-6">
                        <label class="form-label fw-bold">Periode Tahun <span class="text-danger">*</span></label>
                        <input type="number" name="period_year" class="form-control" value="<?= date('Y') ?>" required min="2024" max="2099">
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-bold">Nominal Pembayaran (Rp) <span class="text-danger">*</span></label>
                        <input type="number" name="amount" id="global_pay_amount" class="form-control fs-5 fw-bold text-success" required min="1000" step="1000">
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-bold">Metode Pembayaran</label>
                        <select name="payment_method" class="form-select">
                            <option value="cash">💵 Tunai / Cash (Kasir Kantor)</option>
                            <option value="transfer">🏦 Transfer Bank</option>
                            <option value="qris">📱 QRIS / E-Wallet</option>
                            <option value="other">Lainnya</option>
                        </select>
                    </div>

                    <div class="col-12">
                        <label class="form-label">Catatan / Keterangan</label>
                        <input type="text" name="notes" class="form-control" placeholder="Contoh: Lunas bayar di kantor">
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-bold">Petugas / Teknisi Penerima</label>
                        <input type="text" name="collector_name" class="form-control" value="<?= htmlspecialchars($defCollector) ?>" placeholder="Nama teknisi / admin penerima uang">
                    </div>

                    <div class="col-12">
                        <div class="form-check form-switch mt-1">
                            <input class="form-check-input" type="checkbox" name="send_wa" value="1" id="checkSendWaGlobal" checked>
                            <label class="form-check-label fw-bold text-success" for="checkSendWaGlobal">
                                <i class="bi bi-whatsapp me-1"></i> Kirim WhatsApp Bukti Pembayaran Lunas ke Pelanggan
                            </label>
                        </div>
                    </div>

                    <div class="col-12">
                        <div class="form-check form-switch mt-1">
                            <input class="form-check-input" type="checkbox" name="auto_unisolir" value="1" id="checkAutoUnisolirGlobal" checked>
                            <label class="form-check-label fw-bold text-primary" for="checkAutoUnisolirGlobal">
                                Otomatis Buka Isolir di MikroTik jika pelanggan terisolir
                            </label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-success px-4"><i class="bi bi-check-lg me-1"></i> Simpan Pembayaran</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Kirim WhatsApp Cepat -->
<div class="modal fade" id="modalQuickWa" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="/process/send_wa_manual.php" class="modal-content">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="customer_id" id="wa_customer_id" value="">
            <input type="hidden" name="redirect" value="/index.php?page=pelanggan_rumahan&router_id=<?= $selRid ?>">

            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-whatsapp text-success me-2"></i>Kirim Notifikasi WhatsApp</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-light border mb-3">
                    <div class="fw-bold fs-6" id="wa_customer_name">-</div>
                    <div class="small font-mono text-muted" id="wa_customer_info">-</div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">Nomor WhatsApp Tujuan <span class="text-danger">*</span></label>
                    <input type="text" name="phone" id="wa_inp_phone" class="form-control font-mono" required>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">Pilih Template Pesan</label>
                    <select id="wa_sel_template" class="form-select" onchange="applyQuickTemplate(this.value)">
                        <option value="">-- Pilih Template Pesan --</option>
                        <?php foreach ($wa_templates as $wt): ?>
                        <option value="<?= htmlspecialchars($wt['code']) ?>"><?= htmlspecialchars($wt['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">Isi Pesan WhatsApp <span class="text-danger">*</span></label>
                    <textarea name="message" id="wa_inp_message" class="form-control font-mono small" rows="7" required placeholder="Tulis isi pesan..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-success px-4"><i class="bi bi-send-fill me-1"></i> Kirim WhatsApp</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Ganti Nama & Password Wi-Fi (Push ONT) -->
<div class="modal fade" id="modalQuickWifi" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="/process/quick_rumahan_action.php" class="modal-content shadow border-0" onsubmit="document.getElementById('btnSubmitWifi').innerHTML = '<span class=\'spinner-border spinner-border-sm me-1\'></span> Mengirim ke ONT...'; document.getElementById('btnSubmitWifi').disabled = true;">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="update_wifi">
            <input type="hidden" name="customer_id" id="wifi_customer_id" value="">
            <input type="hidden" name="router_id" id="wifi_router_id" value="<?= $selRid ?>">

            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fs-6"><i class="bi bi-wifi me-2"></i>Ganti Nama &amp; Password Wi-Fi (Push ONT)</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="alert alert-light border d-flex align-items-center justify-content-between mb-3 py-2">
                    <div>
                        <div class="fw-bold text-dark fs-6" id="wifi_customer_name">-</div>
                        <div class="text-muted small">SN ONT: <strong class="font-mono text-primary" id="wifi_customer_sn">-</strong></div>
                    </div>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1"><i class="bi bi-router me-1"></i>TR-069</span>
                </div>

                <!-- Data Wi-Fi Saat Ini -->
                <div class="card border-primary-subtle bg-light shadow-sm mb-3">
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-center mb-2 pb-1 border-bottom border-light-subtle">
                            <span class="text-uppercase fw-bold text-muted" style="font-size:11px;">
                                <i class="bi bi-info-circle-fill text-primary me-1"></i>Data Wi-Fi Saat Ini
                            </span>
                            <button type="button" class="btn btn-xs btn-outline-primary py-0 px-2" id="btnSyncWifiOnt" onclick="fetchLiveWifiFromOnt(true)" style="font-size:11px;">
                                <i class="bi bi-arrow-repeat me-1" id="iconSyncWifi"></i><span id="textSyncWifi">Cek Langsung dari ONT</span>
                            </button>
                        </div>
                        <div class="row g-2">
                            <div class="col-6">
                                <div class="text-muted small text-uppercase" style="font-size:10px; font-weight:600;">SSID Saat Ini:</div>
                                <div class="fw-bold text-dark fs-6 text-truncate" id="display_current_ssid">-</div>
                            </div>
                            <div class="col-6">
                                <div class="text-muted small text-uppercase" style="font-size:10px; font-weight:600;">Password Saat Ini:</div>
                                <div class="d-flex align-items-center gap-1">
                                    <span class="fw-bold font-mono text-primary fs-6" id="display_current_pass">-</span>
                                    <button type="button" class="btn btn-sm btn-link text-muted p-0 ms-1" onclick="toggleCurrentWifiPassVisibility()" id="btnToggleCurWifiPass" title="Lihat/Sembunyikan Sandi">
                                        <i class="bi bi-eye" id="iconToggleCurWifiPass"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-link text-primary p-0 ms-1" onclick="copyCurrentWifiPass()" title="Salin Sandi">
                                        <i class="bi bi-clipboard"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label fw-bold">Nama Wi-Fi / SSID Baru (2.4 GHz &amp; 5 GHz) <small class="text-muted fw-normal">(Kosongkan jika tetap nama lama)</small></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-broadcast"></i></span>
                            <input type="text" name="wifi_ssid" id="modal_wifi_ssid" class="form-control form-control-lg fw-bold" placeholder="Biarkan kosong jika tidak diubah (tetap nama lama)">
                        </div>
                        <div class="form-text">Biarkan kosong jika hanya ingin mengganti password. Jika diisi, nama Wi-Fi akan diperbarui untuk sinyal 2.4 GHz &amp; 5 GHz.</div>
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-bold">Password / Kode Wi-Fi Baru (WPA2) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-key-fill"></i></span>
                            <input type="text" name="wifi_pass" id="modal_wifi_pass" class="form-control form-control-lg font-mono fw-bold text-primary" minlength="8" required placeholder="Minimal 8 karakter">
                            <button type="button" class="btn btn-outline-secondary" onclick="toggleModalWifiPassInput()" id="btnToggleInputWifiPass" title="Lihat/Sembunyikan Sandi">
                                <i class="bi bi-eye-slash" id="iconToggleInputWifiPass"></i>
                            </button>
                            <button type="button" class="btn btn-outline-secondary" onclick="generateRandomWifiPass()" title="Buat Sandi Acak">
                                <i class="bi bi-shuffle me-1"></i>Acak
                            </button>
                            <button type="button" class="btn btn-outline-secondary" onclick="copyWifiPass()" title="Salin Sandi">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </div>
                        <div class="form-text">Minimal 8 karakter. Berlaku sama untuk sinyal Wi-Fi 2.4 GHz &amp; 5 GHz.</div>
                    </div>

                    <div class="col-12">
                        <div class="form-check form-switch p-3 bg-light rounded-3 border">
                            <input class="form-check-input ms-0 me-2" type="checkbox" name="push_ont" value="1" id="checkPushWifiOnt" checked style="float:none;">
                            <label class="form-check-label fw-bold text-success" for="checkPushWifiOnt">
                                <i class="bi bi-lightning-charge-fill me-1"></i> Push langsung ke Modem ONT via GenieACS (TR-069)
                            </label>
                            <div class="text-muted small mt-1 ms-4">Konfigurasi baru akan otomatis dikirim ke modem pelanggan tanpa perlu teknisi datang ke rumah.</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary fw-bold px-4" id="btnSubmitWifi">
                    <i class="bi bi-check2-circle me-1"></i> Simpan &amp; Terapkan Wi-Fi
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Atur ID & Password Portal Pelanggan -->
<div class="modal fade" id="modalQuickPortal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="/process/quick_rumahan_action.php" class="modal-content shadow border-0">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="update_portal">
            <input type="hidden" name="customer_id" id="portal_customer_id" value="">
            <input type="hidden" name="router_id" id="portal_router_id" value="<?= $selRid ?>">

            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title fs-6"><i class="bi bi-person-lock me-2"></i>Atur Akun Portal Pelanggan</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="alert alert-light border mb-3 py-2">
                    <div class="fw-bold text-dark fs-6" id="portal_customer_name">-</div>
                    <div class="text-muted small">Username PPPoE: <strong class="font-mono text-primary" id="portal_customer_pppoe">-</strong></div>
                </div>

                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label fw-bold">ID / Username Login Portal <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-person-fill"></i></span>
                            <input type="text" name="portal_username" id="modal_portal_user" class="form-control form-control-lg font-mono fw-bold" required placeholder="Username portal">
                        </div>
                        <div class="form-text">Digunakan oleh pelanggan saat login ke halaman web portal mandiri.</div>
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-bold text-dark d-flex align-items-center justify-content-between">
                            <span><i class="bi bi-key-fill text-warning me-1"></i>Password Portal Saat Ini</span>
                            <span class="badge bg-warning-subtle text-dark border border-warning font-normal" style="font-size:11px">Dilihat saat pelanggan lupa</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text bg-white"><i class="bi bi-shield-check text-success"></i></span>
                            <input type="text" id="modal_current_portal_pass" class="form-control form-control-lg font-mono fw-bold text-dark bg-light" readonly value="">
                            <button type="button" class="btn btn-outline-secondary" onclick="toggleCurrentPortalPass()" id="btnTogglePortalPass" title="Sembunyikan/Tampilkan Sandi">
                                <i class="bi bi-eye-slash" id="iconTogglePortalPass"></i>
                            </button>
                            <button type="button" class="btn btn-outline-primary" onclick="copyCurrentPortalPass()" title="Salin Sandi Portal">
                                <i class="bi bi-clipboard me-1"></i>Salin
                            </button>
                        </div>
                        <div class="form-text text-muted">Password aktif yang tersimpan di sistem untuk akun pelanggan ini.</div>
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-bold">Ganti Password Baru Portal (Opsional)</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-shield-lock-fill"></i></span>
                            <input type="text" name="portal_password" id="modal_portal_pass" class="form-control form-control-lg font-mono fw-bold text-dark" placeholder="Kosongkan jika tidak ingin ubah password">
                            <button type="button" class="btn btn-outline-secondary" onclick="generateRandomPortalPass()" title="Buat Sandi Acak">
                                <i class="bi bi-shuffle me-1"></i>Acak
                            </button>
                        </div>
                        <div class="form-text">Hanya isi kolom ini jika ingin mereset/mengganti password portal pelanggan (minimal 4 karakter).</div>
                    </div>

                    <div class="col-12" id="portal_wa_wrapper">
                        <div class="form-check form-switch p-3 bg-light rounded-3 border">
                            <input class="form-check-input ms-0 me-2" type="checkbox" name="send_wa" value="1" id="checkSendWaPortal" checked style="float:none;">
                            <label class="form-check-label fw-bold text-success" for="checkSendWaPortal">
                                <i class="bi bi-whatsapp me-1"></i> Kirim info login &amp; link portal via WhatsApp ke pelanggan
                            </label>
                            <div class="text-muted small mt-1 ms-4" id="portal_wa_phone_info">No WhatsApp: -</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-dark fw-bold px-4">
                    <i class="bi bi-check2-circle me-1"></i> Simpan Akun Portal
                </button>
            </div>
        </form>
    </div>
</div>

<script>
const waTemplatesList = <?= json_encode($wa_templates) ?>;
let activeWaCustomer = null;
let activeWifiBtn = null;

document.addEventListener('DOMContentLoaded', function() {
    const payButtons = document.querySelectorAll('.btn-quick-pay');
    const modalEl = document.getElementById('modalPayCustomer');
    if (modalEl && payButtons.length > 0) {
        const modal = new bootstrap.Modal(modalEl);
        payButtons.forEach(btn => {
            btn.addEventListener('click', function() {
                document.getElementById('pay_customer_id').value = this.dataset.id;
                document.getElementById('pay_router_id').value = this.dataset.router || '<?= $selRid ?>';
                document.getElementById('pay_customer_name').textContent = this.dataset.name;
                document.getElementById('pay_customer_username').textContent = 'Username: ' + this.dataset.username;
                document.getElementById('pay_amount').value = this.dataset.price;
                modal.show();
            });
        });
    }

    const waButtons = document.querySelectorAll('.btn-quick-wa');
    const modalWaEl = document.getElementById('modalQuickWa');
    if (modalWaEl && waButtons.length > 0) {
        const modalWa = new bootstrap.Modal(modalWaEl);
        waButtons.forEach(btn => {
            btn.addEventListener('click', function() {
                activeWaCustomer = {
                    id: this.dataset.id,
                    name: this.dataset.name,
                    username: this.dataset.username,
                    phone: this.dataset.phone,
                    price: this.dataset.price,
                    due: this.dataset.due,
                    status: this.dataset.status
                };
                document.getElementById('wa_customer_id').value = activeWaCustomer.id;
                document.getElementById('wa_customer_name').textContent = activeWaCustomer.name;
                document.getElementById('wa_customer_info').textContent = 'Username: ' + activeWaCustomer.username + ' | Tagihan: Rp ' + Number(activeWaCustomer.price).toLocaleString('id-ID');
                document.getElementById('wa_inp_phone').value = activeWaCustomer.phone;
                
                // Set default template based on status
                let defaultCode = (activeWaCustomer.status === 'isolated') ? 'isolir' : 'reminder_h3';
                document.getElementById('wa_sel_template').value = defaultCode;
                applyQuickTemplate(defaultCode);
                
                modalWa.show();
            });
        });
    }

    // Modal Quick Wi-Fi
    const wifiButtons = document.querySelectorAll('.btn-quick-wifi');
    const modalWifiEl = document.getElementById('modalQuickWifi');
    if (modalWifiEl && wifiButtons.length > 0) {
        const modalWifi = new bootstrap.Modal(modalWifiEl);

        wifiButtons.forEach(btn => {
            btn.addEventListener('click', function() {
                activeWifiBtn = this;
                const custId = this.dataset.id;
                const routerId = this.dataset.router || '<?= $selRid ?>';
                const name = this.dataset.name;
                const sn = this.dataset.sn || '-';
                const realSsid = this.dataset.realSsid || '';
                const suggestedSsid = this.dataset.ssid || '';
                const pass = this.dataset.pass || '';

                document.getElementById('wifi_customer_id').value = custId;
                document.getElementById('wifi_router_id').value = routerId;
                document.getElementById('wifi_customer_name').textContent = name;
                document.getElementById('wifi_customer_sn').textContent = sn;

                // Tampilkan SSID Saat Ini & Password Saat Ini
                document.getElementById('display_current_ssid').textContent = realSsid || '(Belum diatur)';
                
                const elCurPass = document.getElementById('display_current_pass');
                if (pass) {
                    elCurPass.dataset.realPass = pass;
                    elCurPass.textContent = pass;
                    document.getElementById('iconToggleCurWifiPass').className = 'bi bi-eye';
                } else {
                    elCurPass.dataset.realPass = '';
                    elCurPass.textContent = '(Belum tersimpan)';
                    document.getElementById('iconToggleCurWifiPass').className = 'bi bi-eye';
                }

                // Isi ke field input (Nama Wi-Fi baru dikosongkan secara default agar tetap pakai nama lama jika tidak diisi)
                document.getElementById('modal_wifi_ssid').value = '';
                document.getElementById('modal_wifi_pass').value = pass;
                document.getElementById('modal_wifi_pass').type = 'text';
                document.getElementById('iconToggleInputWifiPass').className = 'bi bi-eye-slash';

                modalWifi.show();

                // Jika password kosong tapi punya SN ONT, otomatis cek langsung ke ONT via AJAX
                if (!pass && sn && sn !== '-' && sn !== '0') {
                    fetchLiveWifiFromOnt(false);
                }
            });
        });
    }

    // Modal Quick Portal
    const portalButtons = document.querySelectorAll('.btn-quick-portal');
    const modalPortalEl = document.getElementById('modalQuickPortal');
    if (modalPortalEl && portalButtons.length > 0) {
        const modalPortal = new bootstrap.Modal(modalPortalEl);
        portalButtons.forEach(btn => {
            btn.addEventListener('click', function() {
                document.getElementById('portal_customer_id').value = this.dataset.id;
                document.getElementById('portal_router_id').value = this.dataset.router || '<?= $selRid ?>';
                document.getElementById('portal_customer_name').textContent = this.dataset.name;
                document.getElementById('portal_customer_pppoe').textContent = this.dataset.username || '-';
                document.getElementById('modal_portal_user').value = this.dataset.portalUser || this.dataset.username || '';
                document.getElementById('modal_portal_pass').value = '';
                
                const curPass = this.dataset.portalPass || '';
                const curPassInp = document.getElementById('modal_current_portal_pass');
                if (curPass) {
                    curPassInp.value = curPass;
                    curPassInp.type = 'text';
                    document.getElementById('iconTogglePortalPass').className = 'bi bi-eye-slash';
                } else {
                    curPassInp.value = '(Belum tersimpan / default PPPoE)';
                    curPassInp.type = 'text';
                }
                
                const phone = this.dataset.phone || '';
                const phoneInfo = document.getElementById('portal_wa_phone_info');
                const chkWa = document.getElementById('checkSendWaPortal');
                if (phone) {
                    phoneInfo.textContent = 'Nomor WhatsApp: ' + phone;
                    chkWa.disabled = false;
                    chkWa.checked = true;
                } else {
                    phoneInfo.textContent = 'Pelanggan tidak memiliki nomor WhatsApp tersimpan.';
                    chkWa.disabled = true;
                    chkWa.checked = false;
                }
                modalPortal.show();
            });
        });
    }
});

function toggleCurrentPortalPass() {
    const inp = document.getElementById('modal_current_portal_pass');
    const icon = document.getElementById('iconTogglePortalPass');
    if (!inp) return;
    if (inp.type === 'password') {
        inp.type = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        inp.type = 'password';
        icon.className = 'bi bi-eye';
    }
}

function copyCurrentPortalPass() {
    const inp = document.getElementById('modal_current_portal_pass');
    if (!inp || !inp.value || inp.value.startsWith('(')) {
        alert('Tidak ada password tersimpan untuk disalin.');
        return;
    }
    navigator.clipboard.writeText(inp.value).then(() => {
        alert('Password portal (' + inp.value + ') berhasil disalin ke clipboard!');
    }).catch(() => {
        inp.select();
        document.execCommand('copy');
        alert('Password portal disalin!');
    });
}

function generateRandomWifiPass() {
    const chars = 'abcdefghjkmnpqrstuvwxyz23456789';
    let res = '';
    for (let i = 0; i < 8; i++) {
        res += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    document.getElementById('modal_wifi_pass').value = res;
}

function copyWifiPass() {
    const inp = document.getElementById('modal_wifi_pass');
    if (!inp || !inp.value) return;
    navigator.clipboard.writeText(inp.value).then(() => {
        alert('Password Wi-Fi (' + inp.value + ') berhasil disalin ke clipboard!');
    }).catch(() => {
        inp.select();
        document.execCommand('copy');
        alert('Password Wi-Fi disalin!');
    });
}

function toggleModalWifiPassInput() {
    const inp = document.getElementById('modal_wifi_pass');
    const icon = document.getElementById('iconToggleInputWifiPass');
    if (!inp) return;
    if (inp.type === 'password') {
        inp.type = 'text';
        if (icon) icon.className = 'bi bi-eye-slash';
    } else {
        inp.type = 'password';
        if (icon) icon.className = 'bi bi-eye';
    }
}

function toggleCurrentWifiPassVisibility() {
    const el = document.getElementById('display_current_pass');
    const icon = document.getElementById('iconToggleCurWifiPass');
    if (!el) return;
    const realPass = el.dataset.realPass || '';
    if (!realPass) return;
    
    if (el.dataset.hidden === '1') {
        el.textContent = realPass;
        el.dataset.hidden = '0';
        if (icon) icon.className = 'bi bi-eye';
    } else {
        el.textContent = '••••••••';
        el.dataset.hidden = '1';
        if (icon) icon.className = 'bi bi-eye-slash';
    }
}

function copyCurrentWifiPass() {
    const el = document.getElementById('display_current_pass');
    const pass = el ? (el.dataset.realPass || (el.textContent !== '(Belum tersimpan)' && el.textContent !== '-' ? el.textContent : '')) : '';
    if (!pass) {
        alert('Tidak ada password tersimpan untuk disalin.');
        return;
    }
    navigator.clipboard.writeText(pass).then(() => {
        alert('Password Wi-Fi saat ini (' + pass + ') berhasil disalin ke clipboard!');
    }).catch(() => {
        alert('Password Wi-Fi saat ini: ' + pass);
    });
}

function fetchLiveWifiFromOnt(manualTrigger = false) {
    const custId = document.getElementById('wifi_customer_id').value;
    if (!custId) return;
    
    const icon = document.getElementById('iconSyncWifi');
    const text = document.getElementById('textSyncWifi');
    const btn = document.getElementById('btnSyncWifiOnt');
    
    if (icon) icon.classList.add('spin-animation');
    if (text) text.textContent = 'Membaca ONT...';
    if (btn) btn.disabled = true;
    
    const url = 'ajax/get_customer_wifi.php?customer_id=' + encodeURIComponent(custId) + (manualTrigger ? '&force=1' : '');
    
    fetch(url)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                if (data.ssid) {
                    document.getElementById('display_current_ssid').textContent = data.ssid;
                    if (activeWifiBtn) activeWifiBtn.dataset.realSsid = data.ssid;
                }
                if (data.pass) {
                    const elCurPass = document.getElementById('display_current_pass');
                    elCurPass.dataset.realPass = data.pass;
                    elCurPass.textContent = data.pass;
                    elCurPass.dataset.hidden = '0';
                    const iconToggle = document.getElementById('iconToggleCurWifiPass');
                    if (iconToggle) iconToggle.className = 'bi bi-eye';
                    
                    const modalPassInp = document.getElementById('modal_wifi_pass');
                    if (modalPassInp && !modalPassInp.value) {
                        modalPassInp.value = data.pass;
                    }
                    if (activeWifiBtn) activeWifiBtn.dataset.pass = data.pass;
                }
                if (manualTrigger) {
                    alert('Data Wi-Fi berhasil disinkronkan dari ' + (data.source === 'ont' ? 'Modem ONT live!' : 'database.'));
                }
            } else if (manualTrigger) {
                alert(data.error || 'Gagal membaca Wi-Fi dari modem ONT.');
            }
        })
        .catch(err => {
            console.error('Sync ONT WiFi error:', err);
            if (manualTrigger) alert('Gagal menghubungi server untuk sinkronisasi ONT.');
        })
        .finally(() => {
            if (icon) icon.classList.remove('spin-animation');
            if (text) text.textContent = 'Cek Langsung dari ONT';
            if (btn) btn.disabled = false;
        });
}

function generateRandomPortalPass() {
    const rand = Math.floor(100000 + Math.random() * 900000);
    document.getElementById('modal_portal_pass').value = String(rand);
}

const companyName = <?= json_encode($company_name) ?>;
const companyPhone = <?= json_encode($company_phone) ?>;
const currentMonthYear = '<?= date('F Y') ?>';
const currentDateTime = '<?= date('d M Y, H:i') . ' WIB' ?>';

function applyQuickTemplate(code) {
    if (!code || !activeWaCustomer) return;
    const t = waTemplatesList.find(item => item.code === code);
    if (!t) return;
    
    let msg = t.message;
    const custId = activeWaCustomer.id || '1';
    msg = msg.replace(/{nama}/g, activeWaCustomer.name)
             .replace(/{username}/g, activeWaCustomer.username)
             .replace(/{nama_layanan}/g, companyName)
             .replace(/{tagihan}/g, 'Rp ' + Number(activeWaCustomer.price).toLocaleString('id-ID'))
             .replace(/{jatuh_tempo}/g, 'Tanggal ' + activeWaCustomer.due)
             .replace(/{bulan}/g, currentMonthYear)
             .replace(/{waktu_bayar}/g, currentDateTime)
             .replace(/{no_invoice}/g, 'INV-' + '<?= date('Ymd') ?>-' + String(custId).padStart(3, '0'))
             .replace(/{link_receipt}/g, window.location.origin + '/portal/receipt.php?id=' + custId)
             .replace(/{cs_phone}/g, companyPhone)
             .replace(/{link_portal}/g, window.location.origin + '/portal/isolir.php?user=' + encodeURIComponent(activeWaCustomer.username));
    
    document.getElementById('wa_inp_message').value = msg;
}

function updateGlobalPrice(sel) {
    const opt = sel.options[sel.selectedIndex];
    const price = opt ? opt.getAttribute('data-price') : 0;
    document.getElementById('global_pay_amount').value = price || '';
}
</script>

<?php include __DIR__ . '/../../../include/footer.php'; ?>
