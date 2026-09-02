<?php
/**
 * PPPoE Customers — Delete from DB and Mikrotik
 */
$selRid = (int)get('router_id');
$id = (int)get('id');

$routers = get_all_routers();
$selRouter = null;
foreach ($routers as $r) {
    if ($r['id'] == $selRid) { $selRouter = $r; break; }
}

if ($selRouter && $id) {
    $c = db_fetch_one("SELECT pppoe_username, full_name FROM pppoe_customers WHERE id = ? AND router_id = ?", 'ii', [$id, $selRid]);
    
    if ($c) {
        try {
            require_once __DIR__ . '/../../../lib/routeros_api.class.php';
            $api = new RouterosAPI();
            $api->debug = false;
            if ($api->connect($selRouter['ip_address'], $selRouter['api_user'], $selRouter['api_password'], (int)$selRouter['api_port'])) {
                // Delete secret
                $secs = $api->comm('/ppp/secret/print', ['?name' => $c['pppoe_username']]);
                if (!empty($secs)) {
                    $api->comm('/ppp/secret/remove', ['.id' => $secs[0]['.id']]);
                }
                
                // Kick active session
                $acts = $api->comm('/ppp/active/print', ['?name' => $c['pppoe_username']]);
                foreach ($acts as $a) {
                    $api->comm('/ppp/active/remove', ['.id' => $a['.id']]);
                }
                
                $api->disconnect();
            }
            
            // Delete from Database & FreeRADIUS
            db_execute("DELETE FROM pppoe_payments WHERE customer_id = ?", 'i', [$id]);
            db_execute("DELETE FROM pppoe_customers WHERE id = ?", 'i', [$id]);
            db_execute("DELETE FROM radcheck WHERE username = ?", 's', [$c['pppoe_username']]);
            db_execute("DELETE FROM radreply WHERE username = ?", 's', [$c['pppoe_username']]);
            db_execute("DELETE FROM radusergroup WHERE username = ?", 's', [$c['pppoe_username']]);
            
            flash_set('success', "Pelanggan '{$c['full_name']}' berhasil dihapus permanen dari Database, FreeRADIUS, dan MikroTik.");
            
        } catch (Exception $e) {
            flash_set('error', 'Gagal menghapus: ' . $e->getMessage());
        }
    }
}

header("Location: /index.php?page=pppoe_customers&router_id=$selRid");
exit;
