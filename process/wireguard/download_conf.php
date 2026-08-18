<?php
/**
 * S.NET RADIUS & VPN — Download File Konfigurasi WireGuard (.conf)
 */
define('IN_APP', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../include/functions.php';
require_once __DIR__ . '/../../include/wireguard_functions.php';

auth_check();

$id = (int)($_GET['id'] ?? 0);
$router = db_fetch_one("SELECT * FROM wg_routers WHERE id = ?", 'i', [$id]);

if (!$router) {
    die("Router tidak ditemukan.");
}

$confContent = wg_generate_client_conf($router);
$safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $router['name']);
$fileName = $safeName . '.conf';

header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Content-Length: ' . strlen($confContent));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

echo $confContent;
exit;
