<?php
/**
 * PPPoE Customers — Save to DB, Mikrotik & Auto-Push GenieACS (Zero-Touch Provisioning)
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../include/functions.php';

auth_check();
csrf_verify();

$id              = (int)post('id');
$selRid          = (int)post('router_id');
$username        = trim(post('pppoe_username'));
$password        = post('pppoe_password'); // Can be empty on edit
$full_name       = trim(post('full_name'));
$phone           = trim(post('phone'));
$address         = trim(post('address'));
$profile         = trim(post('profile'));
$is_free         = (int)post('is_free', 0);
$monthly_price   = $is_free ? 0 : (int)post('monthly_price');
$due_day         = (int)post('due_day');
$status          = post('status') ?: 'active';
$ont_sn          = strtoupper(trim(post('ont_sn')));
$notes           = trim(post('notes'));
$old_username    = post('old_username');
$portal_username = trim(post('portal_username'));
$portal_password = post('portal_password');

// Provisioning params
$push_ont        = (int)post('push_ont', 0);
$genie_server_id = (int)post('genie_server_id', 0);
$ont_wan_slot    = (int)post('ont_wan_slot', 0);
$ont_vlan        = (int)post('ont_vlan', 100);
$ont_wifi_ssid1  = trim(post('ont_wifi_ssid1'));
$ont_wifi_ssid2  = trim(post('ont_wifi_ssid2'));
$ont_wifi_pass   = trim(post('ont_wifi_pass'));

if (!$selRid || !$username || !$full_name || !$profile) {
    flash_set('error', 'Semua form dengan tanda (*) wajib diisi.');
    header('Location: ' . ($id ? "/index.php?page=pppoe_edit&router_id=$selRid&id=$id" : "/index.php?page=pppoe_add&router_id=$selRid"));
    exit;
}

// Pastikan kolom-kolom baru ada di tabel (Self-Healing)
try {
    $cols = ['is_free' => "TINYINT(1) DEFAULT 0", 'ont_vlan' => "INT DEFAULT 100", 'ont_wifi_ssid' => "VARCHAR(100) DEFAULT ''", 'ont_wifi_pass' => "VARCHAR(100) DEFAULT ''"];
    foreach ($cols as $colName => $colDef) {
        $c = db_fetch_one("SHOW COLUMNS FROM pppoe_customers LIKE '$colName'");
        if (!$c) {
            db_execute("ALTER TABLE pppoe_customers ADD COLUMN $colName $colDef");
        }
    }
} catch (Exception $e) {}

$routers = get_all_routers();
$selRouter = null;
foreach ($routers as $r) {
    if ($r['id'] == $selRid) { $selRouter = $r; break; }
}

if (!$selRouter) {
    flash_set('error', 'Router tidak valid.');
    header('Location: /index.php?page=pppoe_customers');
    exit;
}

$ontPushLog = '';
$customerId = $id;

try {
    // 1. Sync ke MikroTik PPP Secret
    require_once __DIR__ . '/../lib/routeros_api.class.php';
    $api = new RouterosAPI();
    $api->debug = false;
    $api->timeout = 2;
    $api->attempts = 1;
    $api->delay = 0;
    
    if ($api->connect($selRouter['ip_address'], $selRouter['api_user'], $selRouter['api_password'], (int)$selRouter['api_port'])) {
        $actualProfile = $status === 'isolated' ? 'isolir' : $profile;

        if ($id && $old_username) {
            // EDIT SECRET
            $secs = $api->comm('/ppp/secret/print', ['?name' => $old_username]);
            if (!empty($secs)) {
                $cmd = [
                    '/ppp/secret/set',
                    '.id' => $secs[0]['.id'],
                    'name' => $username,
                    'profile' => $actualProfile,
                    'disabled' => $status === 'suspended' ? 'yes' : 'no'
                ];
                if ($password) $cmd['password'] = $password;
                $api->comm(null, $cmd);
            } else {
                $cmd = [
                    '/ppp/secret/add',
                    'name' => $username,
                    'profile' => $actualProfile,
                    'service' => 'pppoe',
                    'disabled' => $status === 'suspended' ? 'yes' : 'no'
                ];
                if ($password) $cmd['password'] = $password;
                $api->comm(null, $cmd);
            }

            if ($old_username !== $username || $status !== 'active') {
                $acts = $api->comm('/ppp/active/print', ['?name' => $old_username]);
                foreach ($acts as $a) {
                    $api->comm('/ppp/active/remove', ['.id' => $a['.id']]);
                }
            }

        } else {
            // ADD SECRET
            if (!$password) $password = (string)rand(10000, 99999);
            
            $cmd = [
                '/ppp/secret/add',
                'name' => $username,
                'password' => $password,
                'profile' => $actualProfile,
                'service' => 'pppoe',
                'disabled' => $status === 'suspended' ? 'yes' : 'no'
            ];
            $api->comm(null, $cmd);
        }
        $api->disconnect();
    } else {
        throw new Exception("Gagal terhubung ke Router API MikroTik.");
    }

    // 2. Save ke Database
    if ($id) {
        // UPDATE
        $sql = "UPDATE pppoe_customers SET 
                pppoe_username = ?, full_name = ?, phone = ?, address = ?, 
                profile = ?, monthly_price = ?, is_free = ?, due_day = ?, status = ?, ont_sn = ?, ont_vlan = ?, ont_wifi_ssid = ?, ont_wifi_pass = ?, notes = ?, portal_username = ?";
        $params = [$username, $full_name, $phone, $address, $profile, $monthly_price, $is_free, $due_day, $status, $ont_sn, $ont_vlan, $ont_wifi_ssid1, $ont_wifi_pass, $notes, $portal_username];
        $types = "sssssiiisssisss";
        
        if ($portal_password !== '') {
            $sql .= ", portal_password = ?";
            $params[] = password_hash($portal_password, PASSWORD_DEFAULT);
            $types .= "s";
        }
        
        if ($status === 'isolated') {
            $sql .= ", isolated_at = NOW(), isolated_reason = 'Manual isolated'";
        } elseif ($status === 'active') {
            $sql .= ", isolated_at = NULL, isolated_reason = ''";
        }
        
        $sql .= " WHERE id = ?";
        $params[] = $id;
        $types .= "i";
        
        db_execute($sql, $types, $params);
    } else {
        // INSERT
        $sql = "INSERT INTO pppoe_customers (
            router_id, pppoe_username, portal_username, portal_password, full_name, phone, address, profile, monthly_price, is_free, due_day, status, ont_sn, ont_vlan, ont_wifi_ssid, ont_wifi_pass, notes
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $params = [$selRid, $username, $portal_username, password_hash($portal_password, PASSWORD_DEFAULT), $full_name, $phone, $address, $profile, $monthly_price, $is_free, $due_day, $status, $ont_sn, $ont_vlan, $ont_wifi_ssid1, $ont_wifi_pass, $notes];
        $types = "isssssssiiisssiss";
        
        db_execute($sql, $types, $params);
        $insertCust = db_fetch_one("SELECT id FROM pppoe_customers WHERE router_id = ? AND pppoe_username = ? LIMIT 1", 'is', [$selRid, $username]);
        $customerId = $insertCust['id'] ?? 0;
    }

    // 3. ⚡ AUTO-PUSH PROVISIONING KE GENIEACS (TR-069)
    if ($push_ont && !empty($ont_sn)) {
        try {
            require_once __DIR__ . '/../include/GenieACS.php';
            
            $server = null;
            if ($genie_server_id > 0) {
                $server = db_fetch_one("SELECT * FROM genie_config WHERE id = ? AND is_active = 1 LIMIT 1", 'i', [$genie_server_id]);
            }
            if (!$server) {
                $server = db_fetch_one("SELECT * FROM genie_config WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
            }

            if ($server) {
                $genie = new GenieACS($server['url'], $server['username'], $server['password']);
                
                // Cari device ONT di GenieACS berdasarkan SN
                $cleanSn = strtoupper(trim($ont_sn));
                $devs = $genie->getDevices(json_encode([
                    '$or' => [
                        ['_deviceId._SerialNumber' => $cleanSn],
                        ['_id' => ['$regex' => $cleanSn, '$options' => 'i']],
                        ['InternetGatewayDevice.DeviceInfo.SerialNumber._value' => $cleanSn],
                        ['InternetGatewayDevice.DeviceInfo.X_HW_SerialNumber._value' => $cleanSn]
                    ]
                ]));

                if (!empty($devs)) {
                    $dev = $devs[0];
                    $devId = $dev['_id'];
                    $brand = $genie->detectBrandName($dev);

                    // Tentukan slot WAN (FiberHome default Slot 2, lainnya Slot 1)
                    $wanSlot = $ont_wan_slot > 0 ? $ont_wan_slot : ((stripos($brand, 'FiberHome') !== false) ? 2 : 1);

                    // Konfigurasi WAN PPPoE
                    $wanCfg = [
                        'wan_slot' => $wanSlot,
                        'conn_mode' => 'route',
                        'service_list' => 'INTERNET',
                        'addr_type' => 'pppoe',
                        'pppoe_user' => $username,
                        'pppoe_pass' => $password,
                        'vlan_enable' => $ont_vlan > 0 ? 1 : 0,
                        'vlan_id' => $ont_vlan,
                        'wan_name' => 'PPPoE_' . $username
                    ];

                    $wanOk = $genie->provisionPppoe($devId, $dev, $wanCfg);

                    // Konfigurasi Wi-Fi SSID 1 & 2
                    $wifiOk = false;
                    $s24 = $ont_wifi_ssid1 ?: ("S.NET - " . explode(' ', $full_name)[0]);
                    $s5g = $ont_wifi_ssid2 ?: ($s24 . " 5G");
                    $kPass = $ont_wifi_pass ?: $password;

                    $wifiOk = $genie->setWifi($devId, $dev, $s24, $kPass, $s5g, $kPass, true);

                    // ── DUAL-WAN: PUSH WAN 2 HOTSPOT S.NET (BRIDGED KE SSID 2 & 6) ──
                    $rawSettings = db_fetch_all("SELECT setting_key, setting_value FROM pppoe_settings");
                    $pSettings = [];
                    foreach ($rawSettings as $s) {
                        $pSettings[$s['setting_key']] = $s['setting_value'];
                    }

                    $enableHotspot = !empty($_POST['enable_hotspot']) || !empty($pSettings['ont_enable_hotspot']);
                    $hotspotLog = '';

                    if ($enableHotspot) {
                        $hsVlan = (int)($_POST['hotspot_vlan'] ?? ($pSettings['ont_hotspot_vlan'] ?? 100));
                        $hsSsid2 = trim($_POST['hotspot_ssid2'] ?? ($pSettings['ont_hotspot_ssid2'] ?? 'S.NET @Hotspot'));
                        $hsSsid6 = trim($_POST['hotspot_ssid6'] ?? ($pSettings['ont_hotspot_ssid6'] ?? 'S.NET @Hotspot 5G'));
                        $hsSlot = (stripos($brand, 'FiberHome') !== false) 
                            ? (int)($pSettings['ont_hotspot_slot_fh'] ?? 3)
                            : (int)($pSettings['ont_hotspot_slot_other'] ?? 2);

                        $hotspotCfg = [
                            'wan_slot' => $hsSlot,
                            'vlan_id'  => $hsVlan,
                            'wan_name' => $hsSlot . '_HOTSPOT_B_VID_' . $hsVlan,
                            'ssid2'    => $hsSsid2,
                            'ssid6'    => $hsSsid6
                        ];

                        $genie->provisionHotspotBridge($devId, $dev, $hotspotCfg);
                        $hotspotLog = " + 📶 Dual-WAN Hotspot (Slot $hsSlot, VLAN $hsVlan, SSID: $hsSsid2)";
                    }

                    // Catat ke ont_configs
                    try {
                        db_execute(
                            "INSERT INTO ont_configs (customer_id, genie_device_id, config_type, config_name, config_data, push_status) VALUES (?, ?, 'wan', ?, ?, 'success')",
                            'isss',
                            [$customerId, $devId, "WAN PPPoE (Slot $wanSlot, VLAN $ont_vlan)", json_encode(array_merge($wanCfg, ['wifi_24'=>$s24, 'wifi_5g'=>$s5g]))]
                        );
                    } catch (Exception $e) {}

                    $ontPushLog = " | ⚡ ONT $cleanSn ($brand) berhasil di-push (WAN PPPoE Slot $wanSlot, VLAN $ont_vlan, Wi-Fi: $s24)" . $hotspotLog;
                } else {
                    $ontPushLog = " | ⚠️ Catatan: ONT $cleanSn belum online di GenieACS (pengaturan tersimpan di database).";
                }
            }
        } catch (Exception $e) {
            $ontPushLog = " | ⚠️ Gagal push GenieACS: " . $e->getMessage();
        }
    }

    $succMsg = $id ? "Pelanggan {$full_name} berhasil diubah" : "Pelanggan {$full_name} berhasil ditambahkan (User: {$username}, Pass: {$password})";
    flash_set('success', $succMsg . $ontPushLog);

} catch (Exception $e) {
    flash_set('error', 'Terjadi kesalahan: ' . $e->getMessage());
}

header("Location: /index.php?page=pppoe_customers&router_id=$selRid");
exit;
