<?php
/**
 * Two-Way PPPoE Secrets Synchronization: MikroTik ↔ Database
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../include/functions.php';
require_once __DIR__ . '/../lib/routeros_api.class.php';

auth_require_superadmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: /index.php?page=pppoe_customers");
    exit;
}

csrf_verify();

$router_id = (int)post('router_id', 0);
if ($router_id <= 0) {
    flash_set('error', 'Router tidak valid.');
    header("Location: /index.php?page=pppoe_customers");
    exit;
}

$router = db_fetch_one("SELECT * FROM routers WHERE id = ?", 'i', [$router_id]);
if (!$router) {
    flash_set('error', 'Router tidak ditemukan.');
    header("Location: /index.php?page=pppoe_customers");
    exit;
}

// Ambil setting isolir profile
$isoSetting = db_fetch_one("SELECT setting_value FROM pppoe_settings WHERE setting_key = 'isolir_profile'");
$isoProfile = $isoSetting['setting_value'] ?? 'isolir';

// Buat map harga per profile dari data existing jika ada
$profilePrices = [];
$existingPrices = db_fetch_all("SELECT profile, monthly_price FROM pppoe_customers WHERE router_id = ? AND monthly_price > 0 GROUP BY profile, monthly_price", 'i', [$router_id]);
foreach ($existingPrices as $ep) {
    if (!isset($profilePrices[$ep['profile']])) {
        $profilePrices[$ep['profile']] = (float)$ep['monthly_price'];
    }
}

$api = new RouterosAPI();
$api->debug = false;

$inserted = 0;
$updated = 0;
$skipped = 0;
$errorMsg = '';

try {
    if ($api->connect($router['ip_address'], $router['api_user'], $router['api_password'], (int)$router['api_port'])) {
        $secrets = $api->comm('/ppp/secret/print');
        $api->disconnect();

        if (empty($secrets)) {
            flash_set('warning', 'Tidak ada PPPoE Secret yang ditemukan di router ' . htmlspecialchars($router['name']));
            header("Location: /index.php?page=pppoe_customers&router_id=$router_id");
            exit;
        }

        foreach ($secrets as $sec) {
            $name = trim($sec['name'] ?? '');
            if (empty($name)) {
                $skipped++;
                continue;
            }

            $profile = trim($sec['profile'] ?? 'default');
            $password = trim($sec['password'] ?? '');
            $comment = trim($sec['comment'] ?? '');
            $disabled = ($sec['disabled'] ?? 'false') === 'true';

            $status = ($disabled || $profile === $isoProfile) ? 'isolated' : 'active';
            $fullName = !empty($comment) ? $comment : $name;
            $price = $profilePrices[$profile] ?? 0;

            // Cek apakah user sudah ada di database untuk router ini
            $exist = db_fetch_one("SELECT * FROM pppoe_customers WHERE pppoe_username = ? AND router_id = ?", 'si', [$name, $router_id]);

            if ($exist) {
                // Update profile dan status jika berbeda
                $needsUpdate = false;
                $updateFields = [];
                $updateParams = [];
                $updateTypes = "";

                if ($exist['profile'] !== $profile && $profile !== $isoProfile) {
                    $updateFields[] = "profile = ?";
                    $updateParams[] = $profile;
                    $updateTypes .= "s";
                    $needsUpdate = true;
                }

                if ($exist['status'] !== $status) {
                    $updateFields[] = "status = ?";
                    $updateParams[] = $status;
                    $updateTypes .= "s";
                    $needsUpdate = true;
                }

                if ((empty($exist['full_name']) || $exist['full_name'] === $name) && !empty($comment)) {
                    $updateFields[] = "full_name = ?";
                    $updateParams[] = $comment;
                    $updateTypes .= "s";
                    $needsUpdate = true;
                }

                if ($needsUpdate) {
                    $updateParams[] = $exist['id'];
                    $updateTypes .= "i";
                    $sql = "UPDATE pppoe_customers SET " . implode(", ", $updateFields) . " WHERE id = ?";
                    db_execute($sql, $updateTypes, $updateParams);
                    $updated++;
                } else {
                    $skipped++;
                }
            } else {
                // Insert pelanggan baru dari MikroTik
                $portalPass = password_hash($password ?: '123456', PASSWORD_DEFAULT);
                db_execute(
                    "INSERT INTO pppoe_customers (router_id, pppoe_username, full_name, profile, monthly_price, due_day, status, portal_username, portal_password) VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?)",
                    'isssdsss',
                    [$router_id, $name, $fullName, $profile, $price, $status, $name, $portalPass]
                );
                $inserted++;
            }
        }

        audit_log('SYNC_PPPOE', "Sinkronisasi MikroTik ({$router['name']}): +$inserted baru, ~$updated diupdate, =$skipped sama.");
        flash_set('success', "Sinkronisasi berhasil! <b>+$inserted</b> pelanggan baru ditambahkan, <b>~$updated</b> diperbarui, dan <b>$skipped</b> sudah sinkron.");
    } else {
        throw new Exception("Gagal terhubung ke API Router MikroTik pada {$router['ip_address']}:{$router['api_port']}");
    }
} catch (Exception $e) {
    flash_set('error', 'Gagal sinkronisasi: ' . $e->getMessage());
}

header("Location: /index.php?page=pppoe_customers&router_id=$router_id");
exit;
