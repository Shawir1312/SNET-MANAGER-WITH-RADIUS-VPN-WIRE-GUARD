<?php
/**
 * S.NET RADIUS Hotspot Management
 * Authentication & Session Helper
 */

function auth_start(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_name(SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => '/',
            'secure'   => false,  // set true in production with HTTPS
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function auth_is_logged_in(): bool {
    auth_start();
    return !empty($_SESSION['admin_id']);
}

function auth_check(): void {
    auth_start();
    if (empty($_SESSION['admin_id'])) {
        header('Location: /login.php');
        exit;
    }
    // Session timeout check
    if (!empty($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_LIFETIME) {
        session_destroy();
        header('Location: /login.php?timeout=1');
        exit;
    }
    $_SESSION['last_activity'] = time();
}

function auth_require_superadmin(): void {
    auth_check();
    if ($_SESSION['admin_role'] !== 'superadmin') {
        header('Location: /index.php?page=dashboard&error=403');
        exit;
    }
}

function auth_login(string $username, string $password): bool {
    require_once CONFIG_PATH . '/database.php';
    $admin = db_fetch_one(
        "SELECT id, username, password, full_name, role, router_access, is_active FROM admins WHERE username = ? LIMIT 1",
        's', [$username]
    );
    if (!$admin || !$admin['is_active']) return false;
    if (!password_verify($password, $admin['password'])) return false;

    auth_start();
    session_regenerate_id(true);
    $_SESSION['admin_id']       = $admin['id'];
    $_SESSION['admin_username'] = $admin['username'];
    $_SESSION['admin_name']     = $admin['full_name'];
    $_SESSION['admin_role']     = $admin['role'];
    $_SESSION['router_access']  = $admin['router_access'] ? json_decode($admin['router_access'], true) : null;
    $_SESSION['last_activity']  = time();

    // Update last login
    db_execute("UPDATE admins SET last_login = NOW() WHERE id = ?", 'i', [$admin['id']]);
    return true;
}

function auth_logout(): void {
    auth_start();
    session_destroy();
    header('Location: /login.php');
    exit;
}

function current_admin(): array {
    return [
        'id'       => $_SESSION['admin_id'] ?? 0,
        'username' => $_SESSION['admin_username'] ?? '',
        'name'     => $_SESSION['admin_name'] ?? '',
        'role'     => $_SESSION['admin_role'] ?? '',
        'routers'  => $_SESSION['router_access'] ?? null,
    ];
}

function is_superadmin(): bool {
    return ($_SESSION['admin_role'] ?? '') === 'superadmin';
}

/**
 * Check if current admin can access a specific router_id.
 * Superadmin always returns true.
 */
function can_access_router(int $router_id): bool {
    if (is_superadmin()) return true;
    $access = $_SESSION['router_access'] ?? null;
    if ($access === null) return true;  // null = all routers
    return in_array($router_id, (array)$access, true);
}

/**
 * Get list of router IDs accessible by current admin.
 * Returns null if superadmin (all routers).
 */
function accessible_router_ids(): ?array {
    if (is_superadmin()) return null;
    return $_SESSION['router_access'] ?? null;
}

/**
 * Write to audit_log table.
 */
function audit_log(string $action, string $target = '', int $router_id = 0, string $detail = ''): void {
    if (strlen($target) > 250) {
        $target = substr($target, 0, 247) . '...';
    }
    $admin = current_admin();
    db_execute(
        "INSERT INTO audit_log (admin_id, admin_name, action, target, router_id, detail, ip_address, created_at)
         VALUES (?,?,?,?,?,?,?,NOW())",
        'isssiss',
        [
            $admin['id'],
            $admin['username'],
            $action,
            $target,
            $router_id,
            $detail,
            $_SERVER['REMOTE_ADDR'] ?? 'cli',
        ]
    );
}
