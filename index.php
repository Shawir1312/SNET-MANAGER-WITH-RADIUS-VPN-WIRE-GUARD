<?php
/**
 * S.NET RADIUS Manager — Main Dispatcher (index.php)
 * Routes requests to appropriate page files.
 */

// Bootstrap
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/include/functions.php';

// Check installation
if (!file_exists(__DIR__ . '/config/.installed')) {
    header('Location: /install.php');
    exit;
}

// Require login
auth_check();

// Route
$page = get('page', 'dashboard');

// Restriksi Akses untuk Operator (Hanya 6 Menu Utama)
if (!is_superadmin()) {
    // Jika operator membuka Pelanggan PPPoE umum, alihkan ke Pelanggan Rumahan
    if ($page === 'pppoe_customers') {
        header('Location: /index.php?page=pelanggan_rumahan');
        exit;
    }

    $operator_allowed_pages = [
        // 1. Dashboard
        'dashboard',
        // 2. Monitoring (User / Voucher Aktif & Daftar MAC)
        'active_users',
        'mac_list',
        // 3. Pelanggan Rumahan (dan proses tambah/edit)
        'pelanggan_rumahan', 'pppoe_rumahan', 'pppoe_add', 'pppoe_edit',
        // 4. Voucher (Generate, List, Print)
        'generate_voucher',
        'voucher_list', 'voucher_print',
        // 5. Laporan
        'report_sales', 'pppoe_payments', 'report_usage', 'penagihan_report', 'pppoe_receipt', 'report_export'
    ];

    if (!in_array($page, $operator_allowed_pages)) {
        flash_set('error', 'Akses ditolak: Menu ini hanya dapat diakses oleh Super Admin.');
        header('Location: /index.php?page=dashboard');
        exit;
    }
}

// Whitelist of valid pages → file mapping
$routes = [
    'dashboard'        => 'pages/dashboard.php',
    // Routers
    'router_list'      => 'pages/routers/list.php',
    'router_add'       => 'pages/routers/add.php',
    'router_edit'      => 'pages/routers/edit.php',
    'router_delete'    => 'pages/routers/delete.php',
    // Profiles
    'profile_list'     => 'pages/profiles/list.php',
    'profile_add'      => 'pages/profiles/add.php',
    'profile_edit'     => 'pages/profiles/edit.php',
    'profile_delete'   => 'pages/profiles/delete.php',
    // Vouchers
    'voucher_list'     => 'pages/vouchers/list.php',
    'generate_voucher' => 'pages/vouchers/generate.php',
    'voucher_print'    => 'pages/vouchers/print.php',
    'voucher_delete'   => 'pages/vouchers/delete.php',
    // Monitoring
    'active_users'     => 'pages/monitoring/active.php',
    'mac_list'         => 'pages/mac/list.php',
    // PPPoE
    'pppoe_customers'  => 'pages/pppoe/customers/list.php',
    'pelanggan_rumahan'=> 'pages/pppoe/customers/rumahan.php',
    'pppoe_rumahan'    => 'pages/pppoe/customers/rumahan.php',
    'pppoe_add'        => 'pages/pppoe/customers/add.php',
    'pppoe_edit'       => 'pages/pppoe/customers/edit.php',
    'pppoe_delete'     => 'pages/pppoe/customers/delete.php',
    'pppoe_profiles'   => 'pages/pppoe/profiles/list.php',
    'pppoe_profile_add'=> 'pages/pppoe/profiles/add.php',
    'pppoe_profile_edit'=> 'pages/pppoe/profiles/edit.php',
    'pppoe_profile_delete'=> 'pages/pppoe/profiles/delete.php',
    'pppoe_payments'   => 'pages/pppoe/payments.php',
    'pppoe_settings'   => 'pages/pppoe/settings.php',
    'pppoe_whatsapp'   => 'pages/pppoe/whatsapp.php',
    'pppoe_receipt'    => 'pages/pppoe/receipt.php',
    // ONT & ACS
    'monitor_ont'      => 'pages/monitor_ont/list.php',
    'ont_detail'       => 'pages/monitor_ont/detail.php',
    // Reports
    'report_sales'     => 'pages/reports/sales.php',
    'report_usage'     => 'pages/reports/usage.php',
    'report_export'    => 'pages/reports/export.php',
    'report_sales_delete' => 'process/delete_sale.php',
    'report_usage_delete' => 'process/delete_usage.php',
    'penagihan_report' => 'pages/reports/penagihan.php',
    'penagihan_delete' => 'process/delete_penagihan.php',
    'rebuild_sales'    => 'process/rebuild_sales.php',
    'set_reseller_baseline' => 'process/set_reseller_baseline.php',
    // Admins (superadmin only)
    'admin_list'       => 'pages/admins/list.php',
    'admin_add'        => 'pages/admins/add.php',
    'admin_edit'       => 'pages/admins/edit.php',
    // Misc
    'audit_log'        => 'pages/settings/audit_log.php',
    'backup'           => 'pages/settings/backup.php',
    'settings'         => 'pages/settings/general.php',
    'general'          => 'pages/settings/general.php',
    
    // GenieACS
    'genieacs_servers' => 'pages/genieacs/servers/list.php',
    'genieacs_add'     => 'pages/genieacs/servers/add.php',
    'genieacs_edit'    => 'pages/genieacs/servers/edit.php',
    'genieacs_delete'  => 'pages/genieacs/servers/delete.php',

    // WireGuard VPN
    'wg_routers'       => 'pages/wireguard/routers.php',
    'wg_router_add'    => 'pages/wireguard/router_add.php',
    'wg_router_edit'   => 'pages/wireguard/router_edit.php',
    'wg_router_detail' => 'pages/wireguard/router_detail.php',
    'wg_port_forwarding'=> 'pages/wireguard/port_forwarding.php',
    'wg_settings'      => 'pages/wireguard/settings.php',
    'wg_logs'          => 'pages/wireguard/logs.php',
];

$file = $routes[$page] ?? null;

if ($file && file_exists(__DIR__ . '/' . $file)) {
    include __DIR__ . '/' . $file;
} else {
    // 404
    $page_title = '404 Not Found';
    include __DIR__ . '/include/header.php';
    echo '<div class="text-center py-5">
        <i class="bi bi-emoji-frown display-1 text-muted d-block mb-3"></i>
        <h3>Halaman tidak ditemukan</h3>
        <a href="/index.php?page=dashboard" class="btn btn-primary mt-2">Kembali ke Dashboard</a>
    </div>';
    include __DIR__ . '/include/footer.php';
}
