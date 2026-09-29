<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../include/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: login.php");
    exit;
}

$username = trim(post('username'));
$password = post('password');

if (empty($username) || empty($password)) {
    flash_set('error', 'Username dan Password wajib diisi.');
    header("Location: login.php");
    exit;
}

// Cari pelanggan berdasarkan username PPPoE
$customer = db_fetch_one("SELECT * FROM pppoe_customers WHERE pppoe_username = ?", 's', [$username]);

if ($customer) {
    // Verifikasi password portal
    $passValid = false;
    if (!empty($customer['portal_password']) && password_verify($password, $customer['portal_password'])) {
        $passValid = true;
        if (empty($customer['portal_password_plain'])) {
            db_execute("UPDATE pppoe_customers SET portal_password_plain = ? WHERE id = ?", 'si', [$password, $customer['id']]);
        }
    } elseif (!empty($customer['portal_password_plain']) && $password === $customer['portal_password_plain']) {
        $passValid = true;
    } elseif (!empty($customer['portal_password']) && $password === $customer['portal_password']) {
        $passValid = true;
    }

    if ($passValid) {
        $_SESSION['portal_customer_id'] = $customer['id'];
        $_SESSION['portal_username'] = $customer['portal_username'] ?: $customer['pppoe_username'];
        $_SESSION['portal_name'] = $customer['full_name'];
        header("Location: index.php");
        exit;
    } else {
        // Jika belum ada password portal, coba fallback ke password PPPoE? 
        // Tapi kita tidak menyimpan password PPPoE di DB. 
        // Kita tolak login dan beri pesan untuk menghubungi admin.
        flash_set('error', 'Akun Anda belum memiliki Password Portal. Silakan hubungi Admin.');
        header("Location: login.php");
        exit;
    }
}

flash_set('error', 'Username atau Password salah.');
header("Location: login.php");
exit;
