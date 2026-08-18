<?php
/**
 * Process — Simpan Router WireGuard (Tambah / Edit)
 */
define('IN_APP', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../include/functions.php';
require_once __DIR__ . '/../../include/wireguard_functions.php';

auth_check();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /index.php?page=wg_routers');
    exit;
}

if (empty($_POST['csrf']) || $_POST['csrf'] !== $_SESSION['csrf_token']) {
    flash_set('error', 'Invalid CSRF token.');
    header('Location: /index.php?page=wg_routers');
    exit;
}

$action     = post('action', 'create');
$id         = (int)post('id', 0);
$name       = trim(post('name'));
$location   = trim(post('location', ''));
$tunnel_ip  = trim(post('tunnel_ip'));
$public_key = trim(post('public_key'));
$private_key= trim(post('private_key'));
$lan_subnets= trim(post('lan_subnets', ''));
$notes      = trim(post('notes', ''));

if (!$name || !$tunnel_ip || !$public_key || !$private_key) {
    flash_set('error', 'Semua kolom bertanda bintang (*) wajib diisi.');
    header('Location: ' . ($id ? "/index.php?page=wg_router_edit&id={$id}" : "/index.php?page=wg_router_add"));
    exit;
}

// Validasi IP
if (!filter_var($tunnel_ip, FILTER_VALIDATE_IP)) {
    flash_set('error', "Format IP Tunnel '{$tunnel_ip}' tidak valid.");
    header('Location: ' . ($id ? "/index.php?page=wg_router_edit&id={$id}" : "/index.php?page=wg_router_add"));
    exit;
}

try {
    if ($action === 'update' && $id > 0) {
        // Ambil data lama
        $old = db_fetch_one("SELECT * FROM wg_routers WHERE id = ?", 'i', [$id]);
        if (!$old) throw new Exception('Router tidak ditemukan.');

        // Update DB
        db_execute(
            "UPDATE wg_routers SET name = ?, location = ?, tunnel_ip = ?, public_key = ?, private_key = ?, lan_subnets = ?, notes = ? WHERE id = ?",
            'sssssssi',
            [$name, $location, $tunnel_ip, $public_key, $private_key, $lan_subnets, $notes, $id]
        );

        // Jika public key berubah, hapus yang lama dan tambah yang baru
        if ($old['public_key'] !== $public_key) {
            wg_sync_remove_peer($old['public_key']);
            wg_sync_add_peer($public_key, $tunnel_ip);
        } else {
            wg_sync_update_peer($public_key, $tunnel_ip, $lan_subnets);
        }

        wg_log('edit_router', $id, $name, "IP: {$tunnel_ip}, Subnets: {$lan_subnets}");
        flash_set('success', "Router '{$name}' berhasil diperbarui.");
        header("Location: /index.php?page=wg_router_detail&id={$id}");
        exit;

    } else {
        // Cek duplikasi
        $existIp = db_fetch_one("SELECT id FROM wg_routers WHERE tunnel_ip = ?", 's', [$tunnel_ip]);
        if ($existIp) throw new Exception("IP Tunnel '{$tunnel_ip}' sudah digunakan oleh router lain.");

        $existPub = db_fetch_one("SELECT id FROM wg_routers WHERE public_key = ?", 's', [$public_key]);
        if ($existPub) throw new Exception("Public Key ini sudah terdaftar pada router lain.");

        // Insert DB
        db_execute(
            "INSERT INTO wg_routers (name, location, tunnel_ip, public_key, private_key, lan_subnets, notes) VALUES (?, ?, ?, ?, ?, ?, ?)",
            'sssssss',
            [$name, $location, $tunnel_ip, $public_key, $private_key, $lan_subnets, $notes]
        );
        $newId = db_last_id();

        // Sync ke sistem WireGuard Linux
        wg_sync_add_peer($public_key, $tunnel_ip);
        if (!empty($lan_subnets)) {
            wg_sync_update_peer($public_key, $tunnel_ip, $lan_subnets);
        }

        wg_log('add_router', $newId, $name, "IP: {$tunnel_ip}, Subnets: {$lan_subnets}");
        flash_set('success', "Router '{$name}' berhasil ditambahkan! Silakan salin skrip konfigurasi MikroTik.");
        header("Location: /index.php?page=wg_router_detail&id={$newId}");
        exit;
    }

} catch (Throwable $e) {
    flash_set('error', 'Gagal menyimpan router: ' . $e->getMessage());
    header('Location: ' . ($id ? "/index.php?page=wg_router_edit&id={$id}" : "/index.php?page=wg_router_add"));
    exit;
}
