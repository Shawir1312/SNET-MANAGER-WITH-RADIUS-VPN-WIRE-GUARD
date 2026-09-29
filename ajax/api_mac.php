<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../include/functions.php';
require_once __DIR__ . '/../lib/routeros_api.class.php';

header('Content-Type: application/json; charset=utf-8');

auth_start();
if (empty($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$router_id = (int)($_GET['router_id'] ?? $_POST['router_id'] ?? 0);

if (!$router_id) {
    echo json_encode(['success' => false, 'message' => 'Router belum dipilih']);
    exit;
}

$router = get_router($router_id);
if (!$router) {
    echo json_encode(['success' => false, 'message' => 'Router tidak ditemukan']);
    exit;
}

$api = new RouterosAPI();
$api->debug = false;
$api->timeout = 5;
$api->attempts = 2;

if (!$api->connect($router['ip_address'], $router['api_user'], $router['api_password'], (int)$router['api_port'])) {
    echo json_encode(['success' => false, 'message' => 'Gagal terhubung ke router MikroTik']);
    exit;
}

function send_json($success, $data = []) {
    global $api;
    if ($api) {
        $api->disconnect();
    }
    
    $response = ['success' => $success];
    if (is_string($data)) {
        $response['message'] = $data;
    } else if (is_array($data)) {
        $response = array_merge($response, $data);
    }
    
    echo json_encode($response);
    exit;
}

/**
 * Normalisasi format MAC Address untuk perbandingan yang 100% konsisten
 */
function norm_mac($mac) {
    return strtoupper(str_replace([':', '-', '.'], '', trim((string)$mac)));
}

/**
 * Sinkronisasi static IP dan Simple Queue limit (2M atau custom) untuk satu MAC
 */
function sync_mac_queue($api, $mac, $name, $limit = '2M/2M', $all_leases = null, $all_hosts = null, $all_arps = null, $all_queues = null, $binding_ip = '') {
    $norm = norm_mac($mac);
    if (!$norm) return false;

    if ($all_leases === null) {
        $all_leases = $api->comm('/ip/dhcp-server/lease/print');
        if (!is_array($all_leases)) $all_leases = [];
    }
    if ($all_hosts === null) {
        $all_hosts = $api->comm('/ip/hotspot/host/print');
        if (!is_array($all_hosts)) $all_hosts = [];
    }
    if ($all_arps === null) {
        $all_arps = $api->comm('/ip/arp/print');
        if (!is_array($all_arps)) $all_arps = [];
    }
    if ($all_queues === null) {
        $all_queues = $api->comm('/queue/simple/print');
        if (!is_array($all_queues)) $all_queues = [];
    }

    // Cari IP dari berbagai sumber (DHCP Lease -> Hotspot Host -> ARP Table -> Binding IP)
    $address = '';
    $matchedLease = null;

    foreach ($all_leases as $l) {
        if (norm_mac($l['mac-address'] ?? '') === $norm) {
            $lStatus = $l['status'] ?? '';
            $lAddr = $l['active-address'] ?? ($l['address'] ?? '');
            if ($lStatus === 'bound' && $lAddr) {
                $address = $lAddr;
                $matchedLease = $l;
                break;
            } elseif (!$matchedLease && $lAddr) {
                $address = $lAddr;
                $matchedLease = $l;
            }
        }
    }

    if (!$address) {
        foreach ($all_hosts as $h) {
            if (norm_mac($h['mac-address'] ?? '') === $norm && !empty($h['address'])) {
                $address = $h['address'];
                break;
            }
        }
    }

    if (!$address) {
        foreach ($all_arps as $a) {
            if (norm_mac($a['mac-address'] ?? '') === $norm && !empty($a['address'])) {
                $address = $a['address'];
                break;
            }
        }
    }

    if (!$address && !empty($binding_ip)) {
        $address = $binding_ip;
    }

    if (!$address) {
        return false; // Perangkat belum aktif atau belum punya IP
    }

    // Buat static DHCP Lease jika masih dynamic
    if ($matchedLease && ($matchedLease['dynamic'] ?? 'false') === 'true') {
        try {
            $api->comm('/ip/dhcp-server/lease/make-static', ['.id' => $matchedLease['.id']]);
        } catch (Throwable $e) {}
    }

    $cleanIp = explode('/', $address)[0];
    $targetCidr = $cleanIp . '/32';
    $rateLimit = !empty($limit) ? $limit : '2M/2M';

    // Cari antrian simple queue yang cocok berdasarkan IP target atau Nama
    $queue_id = null;
    foreach ($all_queues as $q) {
        $qTarget = explode('/', $q['target'] ?? '')[0];
        $qName = $q['name'] ?? '';
        if ($qTarget === $cleanIp || $qName === $name) {
            $queue_id = $q['.id'];
            break;
        }
    }

    // Cari ID antrian statik pertama untuk urutan (hindari queue dynamic)
    $first_static_id = null;
    foreach ($all_queues as $q) {
        if (($q['dynamic'] ?? 'false') !== 'true' && !empty($q['.id'])) {
            $first_static_id = $q['.id'];
            break;
        }
    }

    if ($queue_id) {
        $setParams = [
            '.id'       => $queue_id,
            'name'      => $name,
            'target'    => $targetCidr,
            'max-limit' => $rateLimit,
            'comment'   => 'Bypass MAC - ' . $mac
        ];
        $res = $api->comm('/queue/simple/set', $setParams);
        if (isset($res['!trap'])) {
            $api->comm('/queue/simple/set', [
                '.id'       => $queue_id,
                'target'    => $targetCidr,
                'max-limit' => $rateLimit
            ]);
        }
    } else {
        $addParams = [
            'name'      => $name,
            'target'    => $targetCidr,
            'max-limit' => $rateLimit,
            'comment'   => 'Bypass MAC - ' . $mac
        ];

        $added = false;
        if ($first_static_id) {
            $addWithPlace = $addParams;
            $addWithPlace['place-before'] = $first_static_id;
            $res = $api->comm('/queue/simple/add', $addWithPlace);
            if (!isset($res['!trap'])) {
                $added = true;
                if (is_string($res)) {
                    $all_queues[] = ['id' => $res, '.id' => $res, 'name' => $name, 'target' => $targetCidr, 'max-limit' => $rateLimit];
                }
            }
        }

        if (!$added) {
            $res = $api->comm('/queue/simple/add', $addParams);
            if (!isset($res['!trap'])) {
                $added = true;
                if (is_string($res)) {
                    $all_queues[] = ['id' => $res, '.id' => $res, 'name' => $name, 'target' => $targetCidr, 'max-limit' => $rateLimit];
                }
            } else {
                return false;
            }
        }
    }

    return true;
}

try {
    switch ($action) {
        case 'list':
            $bindings = $api->comm('/ip/hotspot/ip-binding/print');
            $leases   = $api->comm('/ip/dhcp-server/lease/print');
            $hosts    = $api->comm('/ip/hotspot/host/print');
            $arps     = $api->comm('/ip/arp/print');
            $queues   = $api->comm('/queue/simple/print');

            if (!is_array($bindings)) $bindings = [];
            if (!is_array($leases))   $leases = [];
            if (!is_array($hosts))    $hosts = [];
            if (!is_array($arps))     $arps = [];
            if (!is_array($queues))   $queues = [];

            $lease_map = [];
            foreach ($leases as $l) {
                $m = norm_mac($l['mac-address'] ?? '');
                if ($m) {
                    $lease_map[$m] = $l;
                }
            }

            $host_map = [];
            foreach ($hosts as $h) {
                $m = norm_mac($h['mac-address'] ?? '');
                if ($m) {
                    $host_map[$m] = $h;
                }
            }

            $arp_map = [];
            foreach ($arps as $a) {
                $m = norm_mac($a['mac-address'] ?? '');
                if ($m && ($a['complete'] ?? 'true') !== 'false' && ($a['disabled'] ?? 'false') === 'false') {
                    $arp_map[$m] = $a;
                }
            }

            $queue_map = [];
            foreach ($queues as $q) {
                $qTarget = explode('/', $q['target'] ?? '')[0];
                if ($qTarget) {
                    $queue_map[$qTarget] = $q['max-limit'] ?? '';
                }
                if (!empty($q['name'])) {
                    $queue_map['name:' . $q['name']] = $q['max-limit'] ?? '';
                }
            }

            $list = [];
            foreach ($bindings as $b) {
                $mac = strtoupper(trim($b['mac-address'] ?? ''));
                $norm = norm_mac($mac);
                $l   = $lease_map[$norm] ?? null;
                $h   = $host_map[$norm] ?? null;
                $arp = $arp_map[$norm] ?? null;

                $is_static = ($l && ($l['dynamic'] ?? 'false') === 'false');

                $is_online   = false;
                $ip_address  = '';
                $uptime      = '';
                $traffic_str = '';
                $host_name   = $l['host-name'] ?? '';

                if ($h) {
                    $is_online   = true;
                    $ip_address  = $h['address'] ?? '';
                    $uptime      = $h['uptime'] ?? '';
                    $bytes_in    = (int)($h['bytes-in'] ?? 0);
                    $bytes_out   = (int)($h['bytes-out'] ?? 0);
                    if ($bytes_in > 0 || $bytes_out > 0) {
                        $traffic_str = '↓ ' . format_bytes($bytes_in) . ' · ↑ ' . format_bytes($bytes_out);
                    }
                } elseif ($arp) {
                    $is_online  = true;
                    $ip_address = $arp['address'] ?? '';
                }

                if (!$ip_address && $l) {
                    $ip_address = $l['active-address'] ?? ($l['address'] ?? '');
                }

                if (!$ip_address && !empty($b['address'])) {
                    $ip_address = $b['address'];
                }

                $limit_val = $queue_map[$ip_address] ?? ($queue_map['name:' . ($b['comment'] ?? '')] ?? '');

                $list[] = [
                    'id'         => $b['.id'] ?? '',
                    'mac'        => $mac,
                    'type'       => $b['type'] ?? '',
                    'comment'    => $b['comment'] ?? '',
                    'disabled'   => ($b['disabled'] ?? 'false') === 'true',
                    'is_static'  => $is_static,
                    'is_online'  => $is_online,
                    'ip_address' => $ip_address,
                    'uptime'     => $uptime,
                    'traffic'    => $traffic_str,
                    'host_name'  => $host_name,
                    'limit'      => $limit_val ?: '2M/2M',
                    'has_queue'  => !empty($limit_val)
                ];
            }
            send_json(true, ['data' => $list]);
            break;

        case 'add':
            $mac   = trim($_POST['mac'] ?? '');
            $name  = trim($_POST['name'] ?? '');
            $limit = trim($_POST['limit'] ?? '2M/2M');
            if (empty($limit)) $limit = '2M/2M';

            if (!$mac || !$name) {
                send_json(false, 'MAC dan Nama wajib diisi');
            }

            // Validasi format MAC
            $norm = norm_mac($mac);
            if (strlen($norm) !== 12) {
                send_json(false, 'Format MAC address tidak valid (harus 12 karakter hex)');
            }

            // Cek apakah MAC sudah terdaftar di IP-Binding MikroTik
            $existBindings = $api->comm('/ip/hotspot/ip-binding/print');
            if (is_array($existBindings)) {
                foreach ($existBindings as $eb) {
                    if (norm_mac($eb['mac-address'] ?? '') === $norm) {
                        send_json(false, "MAC $mac sudah terdaftar sebelumnya di MikroTik (" . ($eb['comment'] ?? 'Bypass') . ")");
                    }
                }
            }

            $result = $api->comm('/ip/hotspot/ip-binding/add', [
                'mac-address' => $mac,
                'type'        => 'bypassed',
                'comment'     => $name
            ]);

            if (isset($result['!trap'])) {
                send_json(false, $result['!trap'][0]['message'] ?? 'Error dari MikroTik saat menambahkan IP Binding');
            }
            
            $synced = sync_mac_queue($api, $mac, $name, $limit);
            
            if ($synced) {
                send_json(true, "Bypass berhasil! IP statik & Limit $limit langsung aktif.");
            } else {
                send_json(true, "Bypass berhasil didaftarkan. Perangkat belum terhubung / belum punya IP. Antrian limit $limit akan dibuat otomatis saat tombol 'Singkron Limit' diklik.");
            }
            break;

        case 'update':
            $id    = trim($_POST['id'] ?? '');
            $mac   = trim($_POST['mac'] ?? '');
            $name  = trim($_POST['name'] ?? '');
            $limit = trim($_POST['limit'] ?? '2M/2M');

            if (!$id) {
                send_json(false, 'ID tidak valid');
            }

            $params = ['.id' => $id];
            if ($mac) $params['mac-address'] = $mac;
            if ($name) $params['comment'] = $name;

            $result = $api->comm('/ip/hotspot/ip-binding/set', $params);
            if (isset($result['!trap'])) {
                send_json(false, $result['!trap'][0]['message'] ?? 'Error dari MikroTik');
            }

            if ($mac && $name) {
                sync_mac_queue($api, $mac, $name, $limit);
            }
            
            send_json(true, 'Binding dan limit antrian berhasil diperbarui');
            break;

        case 'delete':
            $id = trim($_POST['id'] ?? '');
            
            if (!$id) {
                send_json(false, 'ID tidak valid');
            }

            $bindings = $api->comm('/ip/hotspot/ip-binding/print', ['?.id' => $id]);
            if (!empty($bindings)) {
                $b = $bindings[0];
                $mac = $b['mac-address'] ?? '';
                $name = $b['comment'] ?? '';
                $norm = norm_mac($mac);

                $queues = $api->comm('/queue/simple/print');
                if (is_array($queues)) {
                    foreach ($queues as $q) {
                        $qName = $q['name'] ?? '';
                        $qComment = $q['comment'] ?? '';
                        if (($name && $qName === $name) || ($norm && strpos(norm_mac($qComment), $norm) !== false)) {
                            $api->comm('/queue/simple/remove', ['.id' => $q['.id']]);
                        }
                    }
                }
            }

            $result = $api->comm('/ip/hotspot/ip-binding/remove', ['.id' => $id]);
            if (isset($result['!trap'])) {
                send_json(false, $result['!trap'][0]['message'] ?? 'Error dari MikroTik');
            }
            
            send_json(true, 'Binding dan antrian limit berhasil dihapus');
            break;

        case 'sync_all':
            $limit = trim($_POST['limit'] ?? '2M/2M');
            if (empty($limit)) $limit = '2M/2M';

            $bindings = $api->comm('/ip/hotspot/ip-binding/print', ['?type' => 'bypassed']);
            if (!is_array($bindings)) $bindings = [];
            
            // Ambil semua tabel hanya 1 KALI di awal (mencegah bottleneck & timeout)
            $all_leases = $api->comm('/ip/dhcp-server/lease/print');
            $all_hosts  = $api->comm('/ip/hotspot/host/print');
            $all_arps   = $api->comm('/ip/arp/print');
            $all_queues = $api->comm('/queue/simple/print');

            if (!is_array($all_leases)) $all_leases = [];
            if (!is_array($all_hosts))  $all_hosts  = [];
            if (!is_array($all_arps))   $all_arps   = [];
            if (!is_array($all_queues)) $all_queues = [];

            $synced_count = 0;
            $offline_count = 0;

            foreach ($bindings as $b) {
                $mac = $b['mac-address'] ?? '';
                $name = $b['comment'] ?? 'Bypass MAC';
                $bIp = $b['address'] ?? '';
                
                if ($mac) {
                    $is_synced = sync_mac_queue($api, $mac, $name, $limit, $all_leases, $all_hosts, $all_arps, $all_queues, $bIp);
                    if ($is_synced) {
                        $synced_count++;
                    } else {
                        $offline_count++;
                    }
                }
            }
            
            $msg = "Sinkronisasi selesai! $synced_count antrian limit ($limit) berhasil diperbarui.";
            if ($offline_count > 0) {
                $msg .= " ($offline_count perangkat offline / belum ada IP)";
            }
            send_json(true, $msg);
            break;

        case 'sync_one':
            $id = trim($_POST['id'] ?? '');
            $limit = trim($_POST['limit'] ?? '2M/2M');
            if (empty($limit)) $limit = '2M/2M';
            
            if (!$id) {
                send_json(false, 'ID binding tidak valid');
            }
            
            $bindings = $api->comm('/ip/hotspot/ip-binding/print', ['?.id' => $id]);
            if (empty($bindings)) {
                send_json(false, 'Data binding tidak ditemukan di MikroTik');
            }
            
            $b = $bindings[0];
            $mac = $b['mac-address'] ?? '';
            $name = $b['comment'] ?? 'Bypass MAC';
            $bIp = $b['address'] ?? '';
            
            $synced = sync_mac_queue($api, $mac, $name, $limit, null, null, null, null, $bIp);
            if ($synced) {
                send_json(true, "Limit $limit berhasil disinkronkan untuk $name ($mac).");
            } else {
                send_json(false, "Perangkat $name ($mac) sedang offline / belum ada IP di jaringan.");
            }
            break;

        case 'toggle_status':
            $id = trim($_POST['id'] ?? '');
            $status = trim($_POST['status'] ?? '');
            
            if (!$id || !in_array($status, ['enable', 'disable'])) {
                send_json(false, 'Parameter tidak valid');
            }

            $endpoint = $status === 'enable' ? '/ip/hotspot/ip-binding/enable' : '/ip/hotspot/ip-binding/disable';
            $result = $api->comm($endpoint, ['.id' => $id]);

            if (isset($result['!trap'])) {
                send_json(false, $result['!trap'][0]['message'] ?? 'Error dari MikroTik');
            }
            
            send_json(true, $status === 'enable' ? 'MAC berhasil diaktifkan' : 'MAC berhasil dinonaktifkan');
            break;

        default:
            send_json(false, 'Action tidak valid');
    }
} catch (Exception $e) {
    send_json(false, 'Terjadi kesalahan sistem: ' . $e->getMessage());
}
