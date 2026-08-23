<?php
require_once __DIR__ . '/Database.php';

class Auth
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public static function check(): bool
    {
        self::start();
        return !empty($_SESSION['user_id']);
    }

    public static function requireLogin(): void
    {
        self::start();
        if (empty($_SESSION['user_id'])) {
            header('Location: login.php');
            exit;
        }
    }

    public static function isSuperAdmin(): bool
    {
        self::start();
        return !empty($_SESSION['is_super_admin']);
    }

    /** Dealership IDs this user is scoped to. Empty for a super admin (unrestricted, checked separately). */
    public static function dealershipIds(): array
    {
        self::start();
        return $_SESSION['dealership_ids'] ?? [];
    }

    public static function canAccessDealership(int $id): bool
    {
        return self::isSuperAdmin() || in_array($id, self::dealershipIds(), true);
    }

    /** Sidebar section keys (see includes/SidebarSections.php) this user is allowed to see. Empty for a super admin (unrestricted, checked separately). */
    public static function sidebarSections(): array
    {
        self::start();
        return $_SESSION['sidebar_sections'] ?? [];
    }

    public static function canView(string $sectionKey): bool
    {
        return self::isSuperAdmin() || in_array($sectionKey, self::sidebarSections(), true);
    }

    /**
     * Super admin always has every capability; a scoped user needs the matching
     * can_refresh/can_edit/can_delete flag set on their account.
     * $capability: 'refresh' | 'edit' | 'delete'
     */
    public static function can(string $capability): bool
    {
        self::start();
        return self::isSuperAdmin() || !empty($_SESSION["can_{$capability}"]);
    }

    public static function requireSuperAdmin(): void
    {
        self::requireLogin();
        if (!self::isSuperAdmin()) {
            http_response_code(403);
            exit('Only A Super Admin Can View This Page.');
        }
    }

    public static function attempt(string $username, string $password): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM users WHERE username = :username");
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            self::start();
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['is_super_admin'] = (bool)$user['is_super_admin'];
            $_SESSION['can_refresh'] = (bool)$user['can_refresh'];
            $_SESSION['can_edit'] = (bool)$user['can_edit'];
            $_SESSION['can_delete'] = (bool)$user['can_delete'];

            $dealershipStmt = $db->prepare("SELECT dealership_id FROM user_dealerships WHERE user_id = :id");
            $dealershipStmt->execute(['id' => $user['id']]);
            $_SESSION['dealership_ids'] = array_map('intval', $dealershipStmt->fetchAll(PDO::FETCH_COLUMN));

            $sectionStmt = $db->prepare("SELECT section_key FROM user_sidebar_sections WHERE user_id = :id");
            $sectionStmt->execute(['id' => $user['id']]);
            $_SESSION['sidebar_sections'] = $sectionStmt->fetchAll(PDO::FETCH_COLUMN);

            return true;
        }
        return false;
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        session_destroy();
    }
}
