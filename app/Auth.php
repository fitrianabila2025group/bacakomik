<?php
namespace App;

/**
 * Authentication helper using $_SESSION.
 */
class Auth
{
    public static function user(): ?array
    {
        if (empty($_SESSION['user_id'])) return null;
        return Database::fetch('SELECT id, name, email, role, status FROM users WHERE id = ?', [$_SESSION['user_id']]);
    }

    public static function attempt(string $email, string $password): bool
    {
        $user = Database::fetch('SELECT * FROM users WHERE email = ? AND status = "active"', [$email]);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            return false;
        }
        // Mitigate session fixation: roll the session id on privilege change.
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_regenerate_id(true);
        }
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['user_role'] = $user['role'];
        return true;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_destroy();
    }

    public static function check(): bool { return !empty($_SESSION['user_id']); }
    public static function isAdmin(): bool { return ($_SESSION['user_role'] ?? '') === 'admin'; }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            header('Location: /login');
            exit;
        }
    }

    public static function requireAdmin(): void
    {
        self::requireLogin();
        if (!self::isAdmin()) {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }
    }
}
