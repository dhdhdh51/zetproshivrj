<?php

declare(strict_types=1);

namespace App\Core;

final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        if (PHP_SAPI === 'cli') {
            // CLI scripts (installer, tests, cron) use a plain in-memory array.
            $_SESSION = $_SESSION ?? [];

            return;
        }

        $secure = (bool) Config::get('session.secure', true);
        if (($_SERVER['HTTPS'] ?? '') === '' && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') !== 'https') {
            // Never set the Secure flag on a plain-HTTP request, it would break sessions.
            $secure = false;
        }

        session_name((string) Config::get('session.name', 'docupilot_session'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => (string) Config::get('session.same_site', 'Lax'),
        ]);

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) (int) Config::get('session.lifetime', 7200));

        session_start();

        $lifetime = (int) Config::get('session.lifetime', 7200);
        $now = time();

        if (isset($_SESSION['_last_activity']) && ($now - (int) $_SESSION['_last_activity']) > $lifetime) {
            self::destroy();
            session_start();
        }

        $_SESSION['_last_activity'] = $now;

        if (!isset($_SESSION['_started_at'])) {
            $_SESSION['_started_at'] = $now;
            session_regenerate_id(true);
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $_SESSION = [];

            return;
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies') && !headers_sent()) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => (bool) $params['secure'],
                'httponly' => true,
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }

        session_destroy();
    }

    /* ------------------------------------------------------------------ */
    /* Flash messages                                                      */
    /* ------------------------------------------------------------------ */

    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    public static function pullFlash(): array
    {
        $messages = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);

        return $messages;
    }

    /* ------------------------------------------------------------------ */
    /* Old input + validation errors                                       */
    /* ------------------------------------------------------------------ */

    public static function flashInput(array $input): void
    {
        unset($input['password'], $input['password_confirmation'], $input['_token']);
        $_SESSION['_old'] = $input;
    }

    public static function old(string $key, mixed $default = null): mixed
    {
        return $_SESSION['_old'][$key] ?? $default;
    }

    public static function clearOld(): void
    {
        unset($_SESSION['_old']);
    }

    public static function flashErrors(array $errors): void
    {
        $_SESSION['_errors'] = $errors;
    }

    public static function errors(): array
    {
        return $_SESSION['_errors'] ?? [];
    }

    public static function clearErrors(): void
    {
        unset($_SESSION['_errors']);
    }
}
