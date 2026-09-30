<?php
/**
 * S.NET RADIUS Hotspot Management
 * Application Configuration Constants
 */

define('APP_NAME',     'S.NET MANAGER');
define('APP_VERSION',  '1.0.0');
define('APP_COMPANY',  'PT Network Inovation Solutions');
define('APP_URL',      'https://dash.snetwifi.com');

// Auto-redirect to HTTPS (Supports Apache, Nginx, and Cloudflare)
if (php_sapi_name() !== 'cli') {
    if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
        if (empty($_SERVER['HTTP_X_FORWARDED_PROTO']) || $_SERVER['HTTP_X_FORWARDED_PROTO'] !== 'https') {
            $host = $_SERVER['HTTP_HOST'] ?? '';
            $uri = $_SERVER['REQUEST_URI'] ?? '';
            if ($host) {
                $redirect_url = 'https://' . $host . $uri;
                header('HTTP/1.1 301 Moved Permanently');
                header('Location: ' . $redirect_url);
                exit;
            }
        }
    }
}

// Session settings
define('SESSION_LIFETIME', 7200);  // 2 hours
define('SESSION_NAME',     'SNET_MANAGER');

// RADIUS CoA settings (RFC 3576)
define('COA_PORT',    3799);
define('COA_TIMEOUT', 5);     // seconds

// Voucher generation
define('VOUCHER_MAX_BATCH', 2000);
define('VOUCHER_RETRY',     10);   // retry attempts for unique username collision

// Paths
define('BASE_PATH', dirname(__DIR__));
define('CONFIG_PATH', __DIR__);
define('ASSETS_PATH', BASE_PATH . '/assets');
define('LIB_PATH',    BASE_PATH . '/lib');
define('LOG_PATH',    BASE_PATH . '/logs');

// Timezone
define('APP_TIMEZONE', 'Asia/Jayapura');  // WIT — change as needed
date_default_timezone_set(APP_TIMEZONE);

// Pagination
define('PER_PAGE', 25);

// Color theme (for CSS variables)
define('COLOR_PRIMARY', '#1565C0');  // Blue
define('COLOR_ACCENT',  '#C62828');  // Red
define('COLOR_WHITE',   '#FFFFFF');
