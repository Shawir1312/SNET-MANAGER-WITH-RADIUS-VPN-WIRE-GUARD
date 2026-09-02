<?php
/**
 * Process — Save Router (Add/Edit)
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../include/functions.php';

auth_check();
auth_require_superadmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /index.php?page=router_list'); exit; }
if (empty($_POST['csrf']) || $_POST['csrf'] !== $_SESSION['csrf_token']) {
    flash_set('error', 'Invalid CSRF.'); header('Location: /index.php?page=router_list'); exit;
}

$id          = (int)post('id');
$name        = sanitize(post('name'));
$ip          = sanitize(post('ip_address'));
$secret      = trim(post('radius_secret'));
$api_user    = sanitize(post('api_user', 'admin'));
$api_pass    = post('api_password', '');
$api_port    = (int)post('api_port', 8728);
$nas_ip      = sanitize(post('nas_ip', '0.0.0.0/0'));
if ($nas_ip === '') $nas_ip = '0.0.0.0/0';
$location        = sanitize(post('location', ''));
$status          = post('status', 'active') === 'inactive' ? 'inactive' : 'active';
$genie_server_id = post('genie_server_id') !== '' ? (int)post('genie_server_id') : null;
$default_vlan    = (int)post('default_vlan', 100);

if (!$name || !$ip || !$secret) {
    flash_set('error', 'Nama, IP, dan RADIUS secret wajib diisi.');
    header('Location: /index.php?page=' . ($id ? 'router_edit&id='.$id : 'router_add')); exit;
}

// Self-healing columns
try {
    $c1 = db_fetch_one("SHOW COLUMNS FROM routers LIKE 'genie_server_id'");
    if (!$c1) db_execute("ALTER TABLE routers ADD COLUMN genie_server_id INT DEFAULT NULL AFTER status");
    $c2 = db_fetch_one("SHOW COLUMNS FROM routers LIKE 'default_vlan'");
    if (!$c2) db_execute("ALTER TABLE routers ADD COLUMN default_vlan INT DEFAULT 100 AFTER genie_server_id");
} catch (Exception $e) {}

db_begin();
try {
    if ($id > 0) {
        // Update
        db_execute(
            "UPDATE routers SET name=?, ip_address=?, nas_ip=?, radius_secret=?, api_user=?, api_password=?,
             api_port=?, location=?, status=?, genie_server_id=?, default_vlan=? WHERE id=?",
            'ssssssissiii', [$name, $ip, $nas_ip, $secret, $api_user, $api_pass, $api_port, $location, $status, $genie_server_id, $default_vlan, $id]
        );
        // Update nas table
        $router = get_router($id);
        if ($router['nas_id']) {
            db_execute("UPDATE nas SET nasname=?, shortname=?, secret=?, description=? WHERE id=?",
                'ssssi', [$nas_ip, $name, $secret, $location ?: $name, $router['nas_id']]);
        }
        $action = 'edit_router';
        $target = "router_id:{$id}";
    } else {
        // Insert into nas first
        db_execute(
            "INSERT INTO nas (nasname, shortname, type, secret, description) VALUES (?,?,'other',?,?)",
            'ssss', [$nas_ip, $name, $secret, $location ?: $name]
        );
        $nas_id = db_last_id();

        // Insert router
        db_execute(
            "INSERT INTO routers (name, ip_address, nas_ip, nas_id, api_user, api_password, api_port, radius_secret, location, status, genie_server_id, default_vlan)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
            'sssississsii', [$name, $ip, $nas_ip, $nas_id, $api_user, $api_pass, $api_port, $secret, $location, $status, $genie_server_id, $default_vlan]
        );
        $action = 'add_router';
        $target = "ip:{$ip}";
    }

    db_commit();
    audit_log($action, $target, $id, json_encode(['name' => $name, 'ip' => $ip]));
    flash_set('success', "Router '{$name}' berhasil " . ($id ? 'diperbarui' : 'ditambahkan') . ".");
} catch (Throwable $e) {
    db_rollback();
    flash_set('error', 'Gagal simpan router: ' . $e->getMessage());
}

header('Location: /index.php?page=router_list');
