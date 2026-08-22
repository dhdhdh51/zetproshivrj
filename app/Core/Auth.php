<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Session authentication for the web application (BC Supervisor and Branch
 * Manager). BCAs authenticate against the same `users` table but
 * receive API tokens instead of a cookie session — see App\Core\ApiAuth.
 */
final class Auth
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_MANAGER = 'branch_manager';
    public const ROLE_BC = 'bc_supervisor';

    private const SESSION_KEY = 'auth_user_id';
    private const PENDING_KEY = '_otp_pending_user_id';

    private static ?array $user = null;
    private static bool $resolved = false;

    /* ------------------------------------------------------------------ */
    /* Lookup                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Users may sign in with their email address, username or employee code.
     */
    /**
     * Resolve a user from whatever they typed in the login field.
     *
     * A BCA knows their BCBF code — it is on their paperwork and in the
     * bank's spreadsheets — far better than a username invented when their
     * account was created, so it signs them in too. The join is safe: one
     * bc_supervisors row per user is enforced by `uq_bc_user`, and `uq_bc_code`
     * keeps the code unique across supervisors.
     */
    public static function findByLogin(string $login): ?array
    {
        return Database::selectOne(
            'SELECT u.*, r.slug AS role, r.name AS role_name
               FROM users u
               JOIN roles r ON r.id = u.role_id
          LEFT JOIN bc_supervisors s ON s.user_id = u.id
              WHERE u.email = :login
                 OR u.username = :login
                 OR u.employee_code = :login
                 OR s.bc_code = :login
              LIMIT 1',
            ['login' => $login]
        );
    }

    public static function findById(int $id): ?array
    {
        return Database::selectOne(
            'SELECT u.*, r.slug AS role, r.name AS role_name
               FROM users u
               JOIN roles r ON r.id = u.role_id
              WHERE u.id = :id
              LIMIT 1',
            ['id' => $id]
        );
    }

    /* ------------------------------------------------------------------ */
    /* Credential checks                                                  */
    /* ------------------------------------------------------------------ */

    /**
     * Verify credentials without establishing a session.
     *
     * @return array{ok:bool, user:?array, reason:string}
     */
    public static function verify(string $login, string $password): array
    {
        $user = self::findByLogin($login);

        if ($user === null || empty($user['password'])) {
            return ['ok' => false, 'user' => null, 'reason' => 'invalid'];
        }

        if (self::isLocked($user)) {
            return ['ok' => false, 'user' => $user, 'reason' => 'locked'];
        }

        if (!password_verify($password, (string) $user['password'])) {
            self::recordFailure($user);

            return ['ok' => false, 'user' => $user, 'reason' => 'invalid'];
        }

        if ((string) $user['status'] !== 'active') {
            return ['ok' => false, 'user' => $user, 'reason' => 'inactive'];
        }

        // Transparently upgrade the hash if PHP's default algorithm changed.
        if (password_needs_rehash((string) $user['password'], PASSWORD_DEFAULT)) {
            Database::update(
                'users',
                ['password' => password_hash($password, PASSWORD_DEFAULT)],
                'id = :id',
                ['id' => (int) $user['id']]
            );
        }

        self::clearFailures($user);

        return ['ok' => true, 'user' => $user, 'reason' => ''];
    }

    public static function isLocked(array $user): bool
    {
        $until = $user['locked_until'] ?? null;

        return $until !== null && strtotime((string) $until) > time();
    }

    private static function recordFailure(array $user): void
    {
        $attempts = (int) ($user['failed_attempts'] ?? 0) + 1;
        $max = (int) Config::get('security.account_lock_attempts', 8);
        $data = ['failed_attempts' => $attempts];

        if ($attempts >= $max) {
            $minutes = (int) Config::get('security.account_lock_minutes', 30);
            $data['locked_until'] = date('Y-m-d H:i:s', time() + ($minutes * 60));
            $data['failed_attempts'] = 0;
        }

        Database::update('users', $data, 'id = :id', ['id' => (int) $user['id']]);
    }

    private static function clearFailures(array $user): void
    {
        if ((int) ($user['failed_attempts'] ?? 0) === 0 && ($user['locked_until'] ?? null) === null) {
            return;
        }

        Database::update(
            'users',
            ['failed_attempts' => 0, 'locked_until' => null],
            'id = :id',
            ['id' => (int) $user['id']]
        );
    }

    /* ------------------------------------------------------------------ */
    /* Session lifecycle                                                  */
    /* ------------------------------------------------------------------ */

    public static function login(array $user): void
    {
        Session::regenerate();
        Session::put(self::SESSION_KEY, (int) $user['id']);
        Session::forget(self::PENDING_KEY);

        self::$user = $user;
        self::$resolved = true;

        Database::update(
            'users',
            ['last_login_at' => now(), 'last_login_ip' => (new Request())->ip()],
            'id = :id',
            ['id' => (int) $user['id']]
        );
    }

    public static function logout(): void
    {
        self::$user = null;
        self::$resolved = true;

        Session::destroy();
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
            return null;
        }

        $user = self::findById((int) $id);

        if ($user === null || (string) $user['status'] !== 'active') {
            Session::forget(self::SESSION_KEY);

            return null;
        }

        self::$user = $user;

        return self::$user;
    }

    /**
     * Used by the API layer to run requests as the token owner.
     */
    public static function setUser(?array $user): void
    {
        self::$user = $user;
        self::$resolved = true;
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

    public static function role(): ?string
    {
        $user = self::user();

        return $user === null ? null : (string) $user['role'];
    }

    public static function is(string ...$roles): bool
    {
        $role = self::role();

        return $role !== null && in_array($role, $roles, true);
    }

    public static function isAdmin(): bool
    {
        return self::is(self::ROLE_ADMIN);
    }

    public static function isManager(): bool
    {
        return self::is(self::ROLE_MANAGER);
    }

    public static function isBcSupervisor(): bool
    {
        return self::is(self::ROLE_BC);
    }

    /**
     * Branch the signed-in user is restricted to, or null for BC Supervisor
     * (who legitimately sees every branch).
     */
    public static function branchId(): ?int
    {
        $user = self::user();

        if ($user === null || self::isAdmin()) {
            return null;
        }

        $branchId = $user['branch_id'] ?? null;

        return $branchId === null ? null : (int) $branchId;
    }

    public static function name(): string
    {
        return (string) (self::user()['name'] ?? 'Guest');
    }

    public static function mustChangePassword(): bool
    {
        return (int) (self::user()['must_change_password'] ?? 0) === 1;
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /* ------------------------------------------------------------------ */
    /* Two-step (OTP) sign-in                                             */
    /* ------------------------------------------------------------------ */

    public static function setPending(int $userId): void
    {
        Session::regenerate();
        Session::put(self::PENDING_KEY, $userId);
    }

    public static function pendingId(): ?int
    {
        $id = Session::get(self::PENDING_KEY);

        return $id === null ? null : (int) $id;
    }

    public static function clearPending(): void
    {
        Session::forget(self::PENDING_KEY);
    }

    /**
     * Landing page for a freshly signed-in user.
     */
    public static function homeFor(?string $role = null): string
    {
        return match ($role ?? self::role()) {
            self::ROLE_ADMIN => '/admin',
            self::ROLE_MANAGER => '/manager',
            // BCAs work in the Android app; the web portal only tells
            // them so rather than pretending to offer a field UI.
            self::ROLE_BC => '/app-only',
            default => '/login',
        };
    }
}
