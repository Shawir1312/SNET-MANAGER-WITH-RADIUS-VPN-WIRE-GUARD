<?php
/**
 * Quick Actions for Pelanggan Rumahan:
 * 1. Ganti Nama & Kode Wi-Fi (SSID & Password) + Auto Push ke ONT via GenieACS
 * 2. Atur ID & Password Portal Pelanggan + Kirim Notifikasi WhatsApp
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../include/functions.php';

auth_check();
csrf_verify();

$action     = post('action');
$customerId = (int)post('customer_id');
$routerId   = (int)post('router_id', 0);

if (!$customerId) {
    flash_set('error', 'Pelanggan tidak valid.');
    header("Location: /index.php?page=pelanggan_rumahan&router_id=$routerId");
    exit;
}

$customer = db_fetch_one("SELECT * FROM pppoe_customers WHERE id = ?", 'i', [$customerId]);
if (!$customer) {
    flash_set('error', 'Data pelanggan tidak ditemukan.');
    header("Location: /index.php?page=pelanggan_rumahan&router_id=$routerId");
    exit;
}

try {
    // ══════════════════════════════════════════════════════════════════
    // 1. UPDATE WI-FI (SSID & KODE WI-FI) + PUSH ONT VIA GENIEACS
    // ══════════════════════════════════════════════════════════════════
    if ($action === 'update_wifi') {
        $wifiSsid = trim(post('wifi_ssid'));
        $wifiPass = trim(post('wifi_pass'));
        $pushOnt  = (int)post('push_ont', 0);

        if (empty($wifiSsid)) {
            throw new Exception("Nama Wi-Fi (SSID) tidak boleh kosong.");
        }
        if (strlen($wifiPass) < 8) {
            throw new Exception("Password Wi-Fi minimal 8 karakter.");
        }

        // Simpan ke database
        db_execute(
            "UPDATE pppoe_customers SET ont_wifi_ssid = ?, ont_wifi_pass = ? WHERE id = ?",
            'ssi',
            [$wifiSsid, $wifiPass, $customerId]
        );

        $pushLog = '';
        if ($pushOnt && !empty($customer['ont_sn']) && $customer['ont_sn'] !== '0') {
            try {
                require_once __DIR__ . '/../include/GenieACS.php';
                $server = db_fetch_one("SELECT * FROM genie_config WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
                
                if ($server) {
                    $genie = new GenieACS($server['url'], $server['username'], $server['password']);
                    $cleanSn = strtoupper(trim($customer['ont_sn']));

                    $devs = $genie->getDevices(json_encode([
                        '$or' => [
                            ['_deviceId._SerialNumber' => $cleanSn],
                            ['_id' => ['$regex' => $cleanSn, '$options' => 'i']],
                            ['InternetGatewayDevice.DeviceInfo.SerialNumber._value' => $cleanSn],
                            ['InternetGatewayDevice.DeviceInfo.X_HW_SerialNumber._value' => $cleanSn]
                        ]
                    ]));

                    if (!empty($devs)) {
                        $dev   = $devs[0];
                        $devId = $dev['_id'];
                        $s24   = $wifiSsid;
                        $s5g   = $wifiSsid; // SSID 2.4 GHz dan 5 GHz sama persis tanpa tambahan 5G

                        $wifiOk = $genie->setWifi($devId, $dev, $s24, $wifiPass, $s5g, $wifiPass, true);
                        if ($wifiOk) {
                            $pushLog = " | ⚡ Pengaturan Wi-Fi berhasil di-push langsung ke modem ONT.";
                        } else {
                            $pushLog = " | ⚠️ Gagal push ke ONT: " . ($genie->error ?: 'Timeout/Belum merespon.');
                        }
                    } else {
                        $pushLog = " | ⚠️ Modem ONT ($cleanSn) tidak ditemukan online di server GenieACS.";
                    }
                } else {
                    $pushLog = " | ⚠️ Server GenieACS belum terkonfigurasi.";
                }
            } catch (Throwable $ge) {
                $pushLog = " | ⚠️ Gagal push TR-069: " . $ge->getMessage();
            }
        }

        flash_set('success', "Wi-Fi pelanggan {$customer['full_name']} berhasil diubah (SSID: {$wifiSsid})$pushLog");
    }

    // ══════════════════════════════════════════════════════════════════
    // 2. ATUR ID & PASSWORD PORTAL PELANGGAN
    // ══════════════════════════════════════════════════════════════════
    elseif ($action === 'update_portal') {
        $portalUser = trim(post('portal_username'));
        $portalPass = post('portal_password');
        $sendWa     = (int)post('send_wa', 0);

        if (empty($portalUser)) {
            $portalUser = $customer['pppoe_username'];
        }

        if ($portalPass !== '') {
            if (strlen($portalPass) < 4) {
                throw new Exception("Password portal minimal 4 karakter.");
            }
            $hashedPass = password_hash($portalPass, PASSWORD_DEFAULT);
            db_execute(
                "UPDATE pppoe_customers SET portal_username = ?, portal_password = ?, portal_password_plain = ? WHERE id = ?",
                'sssi',
                [$portalUser, $hashedPass, $portalPass, $customerId]
            );
        } else {
            db_execute(
                "UPDATE pppoe_customers SET portal_username = ? WHERE id = ?",
                'si',
                [$portalUser, $customerId]
            );
        }

        $waLog = '';
        if ($sendWa && !empty($customer['phone'])) {
            try {
                require_once __DIR__ . '/../include/WhatsAppGateway.php';
                $wa = WhatsAppGateway::getInstance();

                $rawSettings = db_fetch_all("SELECT setting_key, setting_value FROM pppoe_settings");
                $pSettings = [];
                foreach ($rawSettings as $s) {
                    $pSettings[$s['setting_key']] = $s['setting_value'];
                }
                $companyName  = $pSettings['company_name'] ?? (defined('APP_COMPANY') ? APP_COMPANY : 'S.NET Internet');
                $companyPhone = $pSettings['company_phone'] ?? '';
                $portalLink   = 'https://' . WhatsAppGateway::getAppDomain() . '/portal/login.php';

                $passInfo = ($portalPass !== '') ? "*$portalPass*" : "*(Tidak berubah)*";

                $msg = "Halo Kak *{$customer['full_name']}*, informasi akses Portal Pelanggan {$companyName} Anda telah diperbarui:\n\n"
                     . "🌐 *Link Portal:* {$portalLink}\n"
                     . "👤 *Username / ID:* *{$portalUser}*\n"
                     . "🔑 *Password:* {$passInfo}\n\n"
                     . "Melalui portal ini, Anda dapat mengecek status tagihan, ganti nama & password Wi-Fi mandiri, serta bayar iuran secara online.\n\n"
                     . "Jika butuh bantuan, hubungi kami di {$companyPhone}. Terima kasih! 🙏";

                $sendRes = $wa->send($customer['phone'], $msg, $customerId, 'portal_credentials', $customer['full_name']);
                if ($sendRes['success']) {
                    $waLog = " | 📱 Info akses terkirim via WhatsApp ke {$customer['phone']}";
                } else {
                    $waLog = " | ⚠️ Gagal kirim WA: " . $sendRes['message'];
                }
            } catch (Throwable $we) {
                $waLog = " | ⚠️ Gagal kirim WA: " . $we->getMessage();
            }
        }

        flash_set('success', "Akun portal pelanggan {$customer['full_name']} berhasil diperbarui (ID: {$portalUser})!$waLog");
    } else {
        throw new Exception("Aksi tidak valid.");
    }

} catch (Exception $e) {
    flash_set('error', 'Terjadi kesalahan: ' . $e->getMessage());
}

header("Location: /index.php?page=pelanggan_rumahan&router_id=$routerId");
exit;
