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

if (!$api->connect($router['ip_address'], $router['api_user'], $router['api_password'], (int)$router['api_port'])) {
    echo json_encode(['success' => false, 'message' => 'Gagal terhubung ke router MikroTik']);
    exit;
}

function send_json($success, $data = []) {
    global $api;
    $api->disconnect();
    
    $response = ['success' => $success];
    if (is_string($data)) {
        $response['message'] = $data;
    } else if (is_array($data)) {
        $response = array_merge($response, $data);
    }
    
    echo json_encode($response);
    exit;
}
function sync_mac_queue($api, $mac, $name, $all_leases = null) {
    if ($all_leases === null) {
        $all_leases = $api->comm('/ip/dhcp-server/lease/print');
    }
    
    $lease = null;
    foreach ($all_leases as $l) {
        if (strtoupper($l['mac-address'] ?? '') === strtoupper($mac)) {
            if (($l['status'] ?? '') === 'bound') {
                $lease = $l;
                break;
            } else if (!$lease) {
                $lease = $l;
            }
        }
    }
    
    if (!$lease) return false;
    
    if (($lease['dynamic'] ?? 'false') === 'true') {
        $api->comm('/ip/dhcp-server/lease/make-static', ['.id' => $lease['.id']]);
    }
    
    $address = $lease['address'] ?? '';
    if (!$address) return false;
    
    $queues = $api->comm('/queue/simple/print');
    $first_queue_id = !empty($queues) ? $queues[0]['.id'] : null;
    $queue_id = null;
    
    foreach ($queues as $q) {
        if (strpos($q['target'] ?? '', $address) === 0) {
            $queue_id = $q['.id'];
            break;
        }
    }
    
    if ($queue_id) {
        $api->comm('/queue/simple/set', [
            '.id' => $queue_id,
            'name' => $name,
            'max-limit' => '2M/2M'
        ]);
        if ($first_queue_id && $first_queue_id !== $queue_id) {
             // Move to top if it's not already
             $api->comm('/queue/simple/move', [
                 'numbers' => $queue_id,
                 'destination' => $first_queue_id
             ]);
        }
    } else {
        $params = [
            'name' => $name,
            'target' => $address,
            'max-limit' => '2M/2M'
        ];
        if ($first_queue_id) {
            $params['place-before'] = $first_queue_id;
        }
        $api->comm('/queue/simple/add', $params);
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

            if (!is_array($bindings)) $bindings = [];
            if (!is_array($leases))   $leases = [];
            if (!is_array($hosts))    $hosts = [];
            if (!is_array($arps))     $arps = [];

            $lease_map = [];
            foreach ($leases as $l) {
                if (!empty($l['mac-address'])) {
                    $lease_map[strtoupper(trim($l['mac-address']))] = $l;
                }
            }

            $host_map = [];
            foreach ($hosts as $h) {
                if (!empty($h['mac-address'])) {
                    $host_map[strtoupper(trim($h['mac-address']))] = $h;
                }
            }

            $arp_map = [];
            foreach ($arps as $a) {
                if (!empty($a['mac-address']) && ($a['complete'] ?? 'true') !== 'false' && ($a['disabled'] ?? 'false') === 'false') {
                    $arp_map[strtoupper(trim($a['mac-address']))] = $a;
                }
            }

            $list = [];
            foreach ($bindings as $b) {
                $mac = strtoupper(trim($b['mac-address'] ?? ''));
                $l   = $lease_map[$mac] ?? null;
                $h   = $host_map[$mac] ?? null;
                $arp = $arp_map[$mac] ?? null;

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

                $list[] = [
                    'id'         => $b['.id'] ?? '',
                    'mac'        => $b['mac-address'] ?? '',
                    'type'       => $b['type'] ?? '',
                    'comment'    => $b['comment'] ?? '',
                    'disabled'   => ($b['disabled'] ?? 'false') === 'true',
                    'is_static'  => $is_static,
                    'is_online'  => $is_online,
                    'ip_address' => $ip_address,
                    'uptime'     => $uptime,
                    'traffic'    => $traffic_str,
                    'host_name'  => $host_name,
                ];
            }
            send_json(true, ['data' => $list]);
            break;

        case 'add':
            $mac  = trim($_POST['mac'] ?? '');
            $name = trim($_POST['name'] ?? '');

            if (!$mac || !$name) {
                send_json(false, 'MAC dan Nama wajib diisi');
            }

            $result = $api->comm('/ip/hotspot/ip-binding/add', [
                'mac-address' => $mac,
                'type' => 'bypassed',
                'comment' => $name
            ]);

            if (isset($result['!trap'])) {
                send_json(false, $result['!trap'][0]['message'] ?? 'Error dari MikroTik');
            }
            
            $synced = sync_mac_queue($api, $mac, $name);
            
            if ($synced) {
                send_json(true, 'Bypass berhasil, IP dibuat statik & Limit 2M/2M dibuat.');
            } else {
                send_json(true, 'Bypass berhasil, tetapi perangkat tidak aktif/tidak ada IP. Klik tombol Singkron nanti.');
            }
            break;

        case 'update':
            $id   = trim($_POST['id'] ?? '');
            $mac  = trim($_POST['mac'] ?? '');
            $name = trim($_POST['name'] ?? '');

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
            
            send_json(true, 'Binding berhasil diperbarui');
            break;

        case 'delete':
            $id = trim($_POST['id'] ?? '');
            
            if (!$id) {
                send_json(false, 'ID tidak valid');
            }

            $bindings = $api->comm('/ip/hotspot/ip-binding/print', ['?.id' => $id]);
            if (!empty($bindings)) {
                $mac = $bindings[0]['mac-address'] ?? '';
                if ($mac) {
                    $leases = $api->comm('/ip/dhcp-server/lease/print', ['?mac-address' => $mac]);
                    if (!empty($leases)) {
                        $address = $leases[0]['address'] ?? '';
                        if ($address) {
                            $queues = $api->comm('/queue/simple/print');
                            foreach ($queues as $q) {
                                if (strpos($q['target'] ?? '', $address) === 0) {
                                    $api->comm('/queue/simple/remove', ['.id' => $q['.id']]);
                                }
                            }
                        }
                    }
                }
            }

            $result = $api->comm('/ip/hotspot/ip-binding/remove', ['.id' => $id]);

            if (isset($result['!trap'])) {
                send_json(false, $result['!trap'][0]['message'] ?? 'Error dari MikroTik');
            }
            
            send_json(true, 'Binding dan antrian berhasil dihapus');
            break;

        case 'sync_all':
            $bindings = $api->comm('/ip/hotspot/ip-binding/print', ['?type' => 'bypassed']);
            $leases = $api->comm('/ip/dhcp-server/lease/print');
            
            $synced_count = 0;
            $failed_count = 0;
            
            foreach ($bindings as $b) {
                $mac = $b['mac-address'] ?? '';
                $name = $b['comment'] ?? 'Bypass MAC';
                
                if ($mac) {
                    $is_synced = sync_mac_queue($api, $mac, $name, $leases);
                    if ($is_synced) {
                        $synced_count++;
                    } else {
                        $failed_count++;
                    }
                }
            }
            
            send_json(true, "Sinkronisasi selesai. $synced_count berhasil, $failed_count gagal/offline.");
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
