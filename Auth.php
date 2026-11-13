<?php
declare(strict_types=1);

namespace App\Core;

final class Auth
{
    private static ?array $user = null;
    private static bool $loaded = false;

    public static function user(): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            $id = $_SESSION['uid'] ?? null;
            if ($id) {
                $u = Db::one('SELECT * FROM users WHERE id = ?', [$id]);
                if ($u && $u['status'] === 'active') {
                    $u['roles'] = array_column(Db::all('SELECT role FROM user_roles WHERE user_id = ?', [$id]), 'role');
                    self::$user = $u;
                } else {
                    unset($_SESSION['uid']);
                }
            }
        }
        return self::$user;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function id(): ?int
    {
        $u = self::user();
        return $u ? (int)$u['id'] : null;
    }

    public static function hasRole(string $role): bool
    {
        $u = self::user();
        return $u !== null && in_array($role, $u['roles'], true);
    }

    public static function hasAny(array $roles): bool
    {
        foreach ($roles as $r) {
            if (self::hasRole($r)) {
                return true;
            }
        }
        return false;
    }

    public static function isStaff(): bool
    {
        return self::hasAny(['admin', 'finance_admin', 'support_admin']);
    }

    /** @return true|string true on success, otherwise a user-safe error message */
    public static function attempt(string $email, string $password): bool|string
    {
        $u = Db::one('SELECT * FROM users WHERE email = ?', [strtolower($email)]);
        // Same message for unknown email and wrong password (no account enumeration).
        $generic = 'Email or password is incorrect.';
        if (!$u) {
            password_verify($password, '$2y$10$usesomesillystringforsaleuwuwuwuwuwuwuwuwuwuwuwuwuwuwuwuw');
            return $generic;
        }
        if ($u['locked_until'] && $u['locked_until'] > now()) {
            return 'Too many failed attempts. Try again in a few minutes.';
        }
        if (!password_verify($password, $u['password_hash'])) {
            $fails = (int)$u['failed_logins'] + 1;
            $lock = $fails >= 5 ? gmdate('Y-m-d H:i:s', time() + 900) : null;
            Db::run('UPDATE users SET failed_logins = ?, locked_until = ? WHERE id = ?', [$fails >= 5 ? 0 : $fails, $lock, $u['id']]);
            Audit::log((int)$u['id'], 'login.failed', 'user', (int)$u['id']);
            return $generic;
        }
        if ($u['status'] !== 'active') {
            return 'This account is ' . $u['status'] . '. Contact support.';
        }
        Db::run('UPDATE users SET failed_logins = 0, locked_until = NULL WHERE id = ?', [$u['id']]);
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            Db::run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
        }
        self::login((int)$u['id']);
        Audit::log((int)$u['id'], 'login.success', 'user', (int)$u['id']);
        return true;
    }

    public static function login(int $userId): void
    {
        session_regenerate_id(true);
        $_SESSION['uid'] = $userId;
        self::$loaded = false;
        self::$user = null;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
        }
        session_destroy();
        self::$user = null;
        self::$loaded = false;
    }
}
