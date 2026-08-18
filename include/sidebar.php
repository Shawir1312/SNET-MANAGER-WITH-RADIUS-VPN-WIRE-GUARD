<?php
/**
 * S.NET RADIUS Manager — Sidebar Navigation
 */
$current_page = get('page', 'dashboard');
$admin = current_admin();

function nav_active(string $page, $match): string {
    $pages = (array)$match;
    return in_array($page, $pages) ? 'active' : '';
}
?>
<aside id="sidebar">
    <!-- Brand -->
    <div class="sidebar-brand">
        <img src="/assets/img/logo.png?v=<?= filemtime(__DIR__ . '/../assets/img/logo.png') ?>" alt="<?= APP_COMPANY ?>">
        <div class="sidebar-brand-text" style="line-height:1.2; font-size:1.1rem; padding-top:4px;">
            <?= APP_NAME ?>
        </div>
    </div>

    <!-- Navigation -->
    <ul class="sidebar-nav">
        <!-- Dashboard -->
        <li class="nav-label">Utama</li>
        <li>
            <a href="/index.php?page=dashboard" class="nav-link <?= nav_active($current_page, 'dashboard') ?>">
                <i class="bi bi-speedometer2"></i> Dashboard
            </a>
        </li>

        <!-- Monitoring -->
        <li class="nav-label">Monitoring</li>
        <li>
            <a href="/index.php?page=active_users" class="nav-link <?= nav_active($current_page, 'active_users') ?>">
                <i class="bi bi-wifi"></i> User Aktif
                <span id="active-users-badge" class="badge bg-danger ms-auto" style="display:none"></span>
            </a>
        </li>
        <li>
            <a href="/index.php?page=mac_list" class="nav-link <?= nav_active($current_page, 'mac_list') ?>">
                <i class="bi bi-laptop"></i> Daftar MAC
            </a>
        </li>

        <!-- PPPoE & ONT -->
        <li class="nav-label">Broadband (PPPoE)</li>
        <li>
            <a href="/index.php?page=pppoe_customers" class="nav-link <?= nav_active($current_page, ['pppoe_customers','pppoe_add','pppoe_edit']) ?>">
                <i class="bi bi-people"></i> Pelanggan PPPoE
            </a>
        </li>
        <li>
            <a href="/index.php?page=pppoe_profiles" class="nav-link <?= nav_active($current_page, ['pppoe_profiles','pppoe_profile_add','pppoe_profile_edit']) ?>">
                <i class="bi bi-box"></i> Paket PPPoE
            </a>
        </li>
        <li>
            <a href="/index.php?page=pppoe_payments" class="nav-link <?= nav_active($current_page, ['pppoe_payments','pppoe_receipt']) ?>">
                <i class="bi bi-wallet2"></i> Riwayat Pembayaran
            </a>
        </li>
        <li>
            <a href="/index.php?page=monitor_ont" class="nav-link <?= nav_active($current_page, 'monitor_ont') ?>">
                <i class="bi bi-hdd-network"></i> Monitor ONT
            </a>
        </li>
        <?php if ($admin['role'] === 'superadmin'): ?>
        <li>
            <a href="/index.php?page=pppoe_settings" class="nav-link <?= nav_active($current_page, 'pppoe_settings') ?>">
                <i class="bi bi-gear-wide-connected"></i> Pengaturan PPPoE
            </a>
        </li>
        <?php endif; ?>

        <!-- Vouchers -->
        <li class="nav-label">Voucher</li>
        <li>
            <a href="/index.php?page=generate_voucher" class="nav-link <?= nav_active($current_page, 'generate_voucher') ?>">
                <i class="bi bi-plus-circle"></i> Generate Voucher
            </a>
        </li>
        <li>
            <a href="/index.php?page=voucher_list" class="nav-link <?= nav_active($current_page, ['voucher_list','voucher_print']) ?>">
                <i class="bi bi-ticket-perforated"></i> Daftar Voucher
            </a>
        </li>

        <!-- Profiles -->
        <?php if ($admin['role'] === 'superadmin'): ?>
        <li class="nav-label">Paket</li>
        <li>
            <a href="/index.php?page=profile_list" class="nav-link <?= nav_active($current_page, ['profile_list','profile_add','profile_edit']) ?>">
                <i class="bi bi-collection"></i> Profil / Paket
            </a>
        </li>

        <!-- Routers -->
        <li class="nav-label">Infrastruktur</li>
        <li>
            <a href="/index.php?page=router_list" class="nav-link <?= nav_active($current_page, ['router_list','router_add','router_edit']) ?>">
                <i class="bi bi-router"></i> Router / NAS
            </a>
        </li>

        <!-- WireGuard VPN -->
        <li class="nav-label">VPN WireGuard</li>
        <li>
            <a href="/index.php?page=wg_routers" class="nav-link <?= nav_active($current_page, ['wg_routers','wg_router_add','wg_router_edit','wg_router_detail']) ?>">
                <i class="bi bi-shield-lock"></i> Router / Peer VPN
            </a>
        </li>
        <li>
            <a href="/index.php?page=wg_port_forwarding" class="nav-link <?= nav_active($current_page, 'wg_port_forwarding') ?>">
                <i class="bi bi-arrow-left-right"></i> Port Forwarding (NAT)
            </a>
        </li>
        <li>
            <a href="/index.php?page=wg_settings" class="nav-link <?= nav_active($current_page, 'wg_settings') ?>">
                <i class="bi bi-gear"></i> Server WireGuard
            </a>
        </li>
        <li>
            <a href="/index.php?page=wg_logs" class="nav-link <?= nav_active($current_page, 'wg_logs') ?>">
                <i class="bi bi-journal-text"></i> Log WireGuard
            </a>
        </li>
        <?php endif; ?>

        <!-- Reports -->
        <li class="nav-label">Laporan</li>
        <li>
            <a href="/index.php?page=report_sales" class="nav-link <?= nav_active($current_page, 'report_sales') ?>">
                <i class="bi bi-bar-chart-line"></i> Penjualan Hotspot
            </a>
        </li>
        <li>
            <a href="/index.php?page=pppoe_payments" class="nav-link <?= nav_active($current_page, 'pppoe_payments') ?>">
                <i class="bi bi-wallet2"></i> Pembayaran PPPoE
            </a>
        </li>
        <li>
            <a href="/index.php?page=report_usage" class="nav-link <?= nav_active($current_page, 'report_usage') ?>">
                <i class="bi bi-graph-up-arrow"></i> Pemakaian Data
            </a>
        </li>
        <li>
            <a href="/index.php?page=penagihan_report" class="nav-link <?= nav_active($current_page, 'penagihan_report') ?>">
                <i class="bi bi-wallet2"></i> Laporan Penagihan
            </a>
        </li>

        <!-- Admin (superadmin only) -->
        <?php if ($admin['role'] === 'superadmin'): ?>
        <li class="nav-label">Administrasi</li>
        <li>
            <a href="/index.php?page=admin_list" class="nav-link <?= nav_active($current_page, ['admin_list','admin_add','admin_edit']) ?>">
                <i class="bi bi-person-gear"></i> Kelola Admin
            </a>
        </li>
        <li>
            <a href="/index.php?page=genieacs_servers" class="nav-link <?= nav_active($current_page, ['genieacs_servers','genieacs_add','genieacs_edit']) ?>">
                <i class="bi bi-hdd-rack"></i> Config GenieACS
            </a>
        </li>
        <li>
            <a href="/index.php?page=audit_log" class="nav-link <?= nav_active($current_page, 'audit_log') ?>">
                <i class="bi bi-journal-text"></i> Audit Log
            </a>
        </li>
        <li>
            <a href="/index.php?page=backup" class="nav-link <?= nav_active($current_page, 'backup') ?>">
                <i class="bi bi-cloud-download"></i> Backup / Restore
            </a>
        </li>
        <li>
            <a href="/index.php?page=settings" class="nav-link <?= nav_active($current_page, 'settings') ?>">
                <i class="bi bi-sliders"></i> Pengaturan
            </a>
        </li>
        <?php endif; ?>
    </ul>

    <!-- Sidebar Footer -->
    <div class="sidebar-footer">
        <div><?= APP_NAME ?> v<?= APP_VERSION ?></div>
        <div>&copy; <?= date('Y') ?> <?= APP_COMPANY ?></div>
    </div>
</aside>
