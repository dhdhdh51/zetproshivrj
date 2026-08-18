<?php

declare(strict_types=1);

namespace App\Core;

final class Auth
{
    private const SESSION_KEY = 'auth_user_id';
    private const REMEMBER_COOKIE = 'docupilot_remember';

    private static ?array $user = null;
    private static bool $resolved = false;

    public static function attempt(string $email, string $password, bool $remember = false): bool
    {
        $user = Database::selectOne('SELECT * FROM users WHERE email = :email LIMIT 1', ['email' => $email]);

        if ($user === null || empty($user['password'])) {
            return false;
        }

        if (!password_verify($password, (string) $user['password'])) {
            return false;
        }

        if ((string) $user['status'] !== 'active') {
            Session::flash('error', 'Your account has been deactivated. Please contact support.');

            return false;
        }

        // Transparently upgrade the hash if PHP's default algorithm changed.
        if (password_needs_rehash((string) $user['password'], PASSWORD_DEFAULT)) {
            Database::update('users', ['password' => password_hash($password, PASSWORD_DEFAULT)], 'id = :id', ['id' => (int) $user['id']]);
        }

        self::login($user, $remember);

        return true;
    }

    public static function login(array $user, bool $remember = false): void
    {
        Session::regenerate();
        Session::put(self::SESSION_KEY, (int) $user['id']);

        self::$user = $user;
        self::$resolved = true;

        Database::update('users', ['last_login_at' => now()], 'id = :id', ['id' => (int) $user['id']]);

        if ($remember) {
            self::setRememberCookie((int) $user['id']);
        }
    }

    public static function loginById(int $id, bool $remember = false): bool
    {
        $user = Database::selectOne('SELECT * FROM users WHERE id = :id LIMIT 1', ['id' => $id]);

        if ($user === null || (string) $user['status'] !== 'active') {
            return false;
        }

        self::login($user, $remember);

        return true;
    }

    public static function logout(): void
    {
        $id = self::id();

        if ($id !== null) {
            try {
                Database::update('users', ['remember_token' => null], 'id = :id', ['id' => $id]);
            } catch (\Throwable $e) {
                Logger::warning('Could not clear remember token: ' . $e->getMessage());
            }
        }

        self::$user = null;
        self::$resolved = true;

        Session::destroy();
        self::forgetRememberCookie();
    }

    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }

        self::$resolved = true;
        self::$user = null;

        $id = Session::get(self::SESSION_KEY);

        if ($id === null) {
            $id = self::resolveRememberCookie();
        }

        if ($id === null) {
            return null;
        }

        $user = Database::selectOne('SELECT * FROM users WHERE id = :id LIMIT 1', ['id' => (int) $id]);

        if ($user === null || (string) $user['status'] !== 'active') {
            Session::forget(self::SESSION_KEY);

            return null;
        }

        self::$user = $user;

        return self::$user;
    }

    public static function refresh(): void
    {
        self::$resolved = false;
        self::$user = null;
    }

    public static function id(): ?int
    {
        $user = self::user();

        return $user === null ? null : (int) $user['id'];
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function isAdmin(): bool
    {
        $user = self::user();

        return $user !== null && (string) $user['role'] === 'admin';
    }

    public static function isVerified(): bool
    {
        $user = self::user();

        return $user !== null && !empty($user['email_verified_at']);
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /* ------------------------------------------------------------------ */
    /* Remember me                                                         */
    /* ------------------------------------------------------------------ */

    private static function setRememberCookie(int $userId): void
    {
        $token = bin2hex(random_bytes(32));

        Database::update('users', ['remember_token' => hash('sha256', $token)], 'id = :id', ['id' => $userId]);

        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }

        $days = (int) Config::get('session.remember_days', 30);

        setcookie(self::REMEMBER_COOKIE, $userId . '|' . $token, [
            'expires' => time() + ($days * 86400),
            'path' => '/',
            'secure' => self::secureCookies(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function forgetRememberCookie(): void
    {
        if (isset($_COOKIE[self::REMEMBER_COOKIE])) {
            unset($_COOKIE[self::REMEMBER_COOKIE]);
        }

        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }

        setcookie(self::REMEMBER_COOKIE, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => self::secureCookies(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function resolveRememberCookie(): ?int
    {
        $cookie = $_COOKIE[self::REMEMBER_COOKIE] ?? '';

        if (!is_string($cookie) || !str_contains($cookie, '|')) {
            return null;
        }

        [$id, $token] = explode('|', $cookie, 2);
        $id = (int) $id;

        if ($id <= 0 || $token === '') {
            return null;
        }

        $user = Database::selectOne('SELECT id, remember_token, status FROM users WHERE id = :id LIMIT 1', ['id' => $id]);

        if ($user === null || empty($user['remember_token']) || (string) $user['status'] !== 'active') {
            self::forgetRememberCookie();

            return null;
        }

        if (!hash_equals((string) $user['remember_token'], hash('sha256', $token))) {
            self::forgetRememberCookie();

            return null;
        }

        Session::put(self::SESSION_KEY, $id);

        return $id;
    }

    private static function secureCookies(): bool
    {
        if ((bool) Config::get('session.secure', true) === false) {
            return false;
        }

        return ($_SERVER['HTTPS'] ?? '') !== '' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }
}
