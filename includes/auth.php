<?php
// /finance/includes/auth.php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

// Dynamic role setup: ensure 'super_admin' role exists and assign to user ID 1
try {
    global $pdo;
    $st = $pdo->prepare("SELECT id FROM roles WHERE name = 'super_admin' LIMIT 1");
    $st->execute();
    $superAdminRoleId = $st->fetchColumn();
    if (!$superAdminRoleId) {
        $st = $pdo->prepare("INSERT INTO roles (name, label, is_system) VALUES ('super_admin', 'អ្នកគ្រប់គ្រងជាន់ខ្ពស់', 1)");
        $st->execute();
        $superAdminRoleId = $pdo->lastInsertId();
    }
    // Assign super_admin role to user ID 1 (Kosol) if he doesn't have it
    $st = $pdo->prepare("SELECT COUNT(*) FROM user_roles WHERE user_id = 1 AND role_id = ?");
    $st->execute([$superAdminRoleId]);
    if ((int)$st->fetchColumn() === 0) {
        $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (1, ?)")->execute([$superAdminRoleId]);
    }
} catch (Throwable $e) {
    // Ignore migration errors
}

/**
 * USER AUTH
 */
function fin_current_user_id(): ?int {
    return isset($_SESSION['fin_user_id']) ? (int)$_SESSION['fin_user_id'] : null;
}

function fin_is_super_admin(?int $user_id = null): bool {
    global $pdo;
    if ($user_id === null) {
        $user_id = fin_current_user_id();
    }
    if (!$user_id) return false;

    static $super_admin_cache = [];
    if (!isset($super_admin_cache[$user_id])) {
        try {
            $st = $pdo->prepare("
                SELECT COUNT(*) 
                FROM user_roles ur 
                JOIN roles r ON r.id = ur.role_id 
                WHERE ur.user_id = ? AND r.name = 'super_admin'
            ");
            $st->execute([$user_id]);
            $super_admin_cache[$user_id] = ((int)$st->fetchColumn() > 0);
        } catch (Throwable $e) {
            $super_admin_cache[$user_id] = false;
        }
    }
    return $super_admin_cache[$user_id];
}

function fin_require_login(): void {
    if (!fin_current_user_id()) {
        header('Location: ' . FIN_BASE_URL . '/admin/login.php');
        exit;
    }
}

/**
 * BUSINESS (COMPANY / SHOP) CONTEXT
 */
function fin_current_business_id(): ?int {
    return isset($_SESSION['fin_business_id']) ? (int)$_SESSION['fin_business_id'] : null;
}

function fin_set_business_id(?int $id): void {
    if ($id === null) {
        unset($_SESSION['fin_business_id']);
    } else {
        $_SESSION['fin_business_id'] = (int)$id;
    }
}

/**
 * Get all businesses that a user belongs to (via business_user_roles).
 */
function fin_user_businesses(PDO $pdo, int $user_id): array {
    if (fin_is_super_admin($user_id)) {
        $sql = "
            SELECT DISTINCT b.id, b.name, b.type, b.is_active
            FROM businesses b
            WHERE b.is_active = 1
            ORDER BY b.name
        ";
        $st = $pdo->query($sql);
        return $st->fetchAll() ?: [];
    }
    $sql = "
        SELECT DISTINCT b.id, b.name, b.type, b.is_active
        FROM businesses b
        JOIN business_user_roles bur ON bur.business_id = b.id
        WHERE bur.user_id = ?
          AND b.is_active = 1
        ORDER BY b.name
    ";
    $st = $pdo->prepare($sql);
    $st->execute([$user_id]);
    return $st->fetchAll() ?: [];
}

/**
 * Ensure we have a valid current business.
 * If none (or user not allowed) -> redirect to choose_business.php
 */
function fin_require_business(): int {
    global $pdo;

    fin_require_login();
    $uid = fin_current_user_id();

    // If we already have a business in session, verify user has access to it
    $bid = fin_current_business_id();
    if ($bid) {
        if (fin_is_super_admin($uid)) {
            return (int)$bid;
        }
        $st = $pdo->prepare("
            SELECT 1
            FROM business_user_roles
            WHERE user_id = ? AND business_id = ?
            LIMIT 1
        ");
        $st->execute([$uid, $bid]);
        if ($st->fetchColumn()) {
            return (int)$bid;
        }
        // No access -> clear and force reselect
        fin_set_business_id(null);
    }

    header('Location: ' . FIN_BASE_URL . '/admin/choose_business.php');
    exit;
}

/**
 * PERMISSIONS (global, not yet per-business)
 * Uses: users -> user_roles -> roles -> role_permissions -> permissions
 */
function fin_user_has_permission(string $perm_code): bool {
    global $pdo;
    $uid = fin_current_user_id();
    if (!$uid) return false;

    static $cache = [];
    if (!isset($cache[$uid])) {
        // Super admin role automatically has all permissions (super admin bypass)
        $st = $pdo->prepare("
            SELECT COUNT(*) 
            FROM user_roles ur 
            JOIN roles r ON r.id = ur.role_id 
            WHERE ur.user_id = ? AND r.name = 'super_admin'
        ");
        $st->execute([$uid]);
        $isAdmin = (int)$st->fetchColumn() > 0;

        if ($isAdmin) {
            $cache[$uid] = ['__all__' => true];
        } else {
            $sql = "
                SELECT p.code
                FROM users u
                JOIN user_roles ur       ON ur.user_id = u.id
                JOIN roles r             ON r.id = ur.role_id
                JOIN role_permissions rp ON rp.role_id = r.id
                JOIN permissions p       ON p.id = rp.permission_id
                WHERE u.id = ? AND u.is_active = 1
            ";
            $st = $pdo->prepare($sql);
            $st->execute([$uid]);
            $perms = $st->fetchAll(PDO::FETCH_COLUMN);
            $cache[$uid] = array_flip($perms);
        }
    }
    return isset($cache[$uid]['__all__']) || isset($cache[$uid][$perm_code]);
}

function fin_require_permission(string $perm_code): void {
    if (!fin_user_has_permission($perm_code)) {
        http_response_code(403);
        echo '<h3>403 Forbidden</h3><p>អ្នកមិនមានសិទ្ធិចូលទំព័រនេះទេ។</p>';
        exit;
    }
}
