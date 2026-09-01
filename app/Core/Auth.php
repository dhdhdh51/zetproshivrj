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
     * Resolve a user from whatever they typed in the login field.
     *
     * Five identifiers now: email, username, employee code, BCBF code and mobile number.
     *
     * A BCA knows their BCBF code — it is on their paperwork and in the bank's spreadsheets —
     * and they know their own phone number. Neither has to be invented, written down and read
     * back over a bad line, which is what the username was. The staff form no longer offers a
     * username or an employee code at all; both columns stay, because accounts created before
     * that still have them and those people can still sign in.
     *
     * The first four are unique columns, so that query returns one row or none. Mobile is not
     * unique and is handled separately below.
     */
    public static function findByLogin(string $login): ?array
    {
        $login = trim($login);

        if ($login === '') {
            return null;
        }

        $user = Database::selectOne(
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

        return $user ?? self::findByMobile($login);
    }

    /**
     * A mobile number reduced to the ten digits that identify it, or null if it is not one.
     *
     * 9876543210, 09876543210, +91 98765 43210 and 91-9876543210 are one number written four
     * ways. An Admin types it into the staff form the way it was written on the paperwork; the
     * BCA types it the way they say it out loud. Without this they are different logins, and
     * the BCA is locked out by a space somebody else typed.
     *
     * Only those four shapes are accepted. Taking the last ten digits of any long number would
     * let a twelve-digit Aadhaar typed into the login box resolve to somebody's phone.
     */
    public static function normaliseMobile(string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        $length = strlen($digits);

        if ($length === 10) {
            return $digits;
        }

        if ($length === 11 && $digits[0] === '0') {
            return substr($digits, 1);
        }

        if ($length === 12 && str_starts_with($digits, '91')) {
            return substr($digits, 2);
        }

        return null;
    }

    /**
     * SQL that strips the punctuation people put in phone numbers, for MySQL 5.7.
     *
     * `REGEXP_REPLACE` says this in one call, and it is what this started as — but it arrived in
     * MySQL 8.0.4 and this project documents 5.7 as supported (README, and deploy/preflight.php
     * checks for it). CI runs 8.0, so that version would have gone out green and then thrown a
     * PDOException on every mobile-shaped sign-in and every staff-form save on a 5.7 host. A
     * REPLACE chain is uglier and works everywhere.
     *
     * Only the characters that actually turn up in a written phone number are removed. Anything
     * else survives, so a value with letters in it matches nothing — which is right, because it
     * is not a phone number.
     */
    public static function mobileSql(string $column): string
    {
        $expression = sprintf("COALESCE(%s, '')", $column);

        foreach ([' ', '+', '-', '(', ')', '.', "\t"] as $strip) {
            $expression = sprintf("REPLACE(%s, '%s', '')", $expression, $strip);
        }

        return $expression;
    }

    /**
     * The three ways a stored value may legitimately spell one ten-digit mobile.
     *
     * Compared exactly, rather than by taking the last ten digits of whatever is in the column.
     * That shortcut is the one normaliseMobile() refuses on the typed side, and allowing it on
     * the stored side would put the same hole back from the other end: a mistyped
     * `9876543210123` would reduce to `6543210123` and become a working login for digits nobody
     * owns, while whoever does own them would resolve to the wrong account.
     *
     * @return array<int, string>
     */
    public static function mobileCandidates(string $tenDigits): array
    {
        return [$tenDigits, '0' . $tenDigits, '91' . $tenDigits];
    }

    /**
     * The key the sign-in throttle counts against, for whatever was typed.
     *
     * Keying on the raw string was sound while every identifier was compared literally against a
     * unique column: one account, one key. A phone number is not like that. `9876543210`,
     * `09876543210`, `+91 98765 43210` and `987-654-3210` all resolve to the same row, and on
     * the raw string each spelling gets its own attempt budget — so the per-account limit stops
     * bounding attempts per account exactly where it matters most.
     *
     * Reducing a phone number to its ten digits collapses every spelling onto one bucket. It is
     * also what makes unlocking work: clearing the key for the spelling an Admin happened to
     * store cannot cover a set that has no end to it.
     */
    public static function throttleKey(string $login): string
    {
        return self::normaliseMobile($login) ?? strtolower(trim($login));
    }

    /**
     * Sign-in by phone number.
     *
     * Deliberately a second query rather than another OR on the one above. `users.mobile` has no
     * unique key and no index, so this cannot use one — running it only when the indexed
     * identifiers have already missed, and only when the input normalises to a phone number,
     * keeps that scan off every other sign-in. The table is staff, not customers.
     *
     * More than one row is the case that matters. `mobile` is not unique, so two accounts can
     * hold one number and there is then no way to tell which of them is signing in; `LIMIT 1`
     * would quietly pick one and could hand somebody another BCA's day of work.
     *
     * An active row is preferred before giving up. The common collision is not two working
     * accounts — it is a number that moved to a new BCA while the old account was disabled
     * rather than corrected, and refusing on that would take mobile sign-in away from the person
     * who actually holds the number, permanently, over a row nobody uses. A single match of any
     * status still resolves, so a suspended user is still told their account is suspended rather
     * than that their number is wrong.
     */
    private static function findByMobile(string $login): ?array
    {
        $mobile = self::normaliseMobile($login);

        if ($mobile === null) {
            return null;
        }

        $matches = Database::select(
            'SELECT u.*, r.slug AS role, r.name AS role_name
               FROM users u
               JOIN roles r ON r.id = u.role_id
              WHERE ' . self::mobileSql('u.mobile') . ' IN (:m1, :m2, :m3)
              LIMIT 10',
            array_combine(['m1', 'm2', 'm3'], self::mobileCandidates($mobile))
        );

        if (count($matches) === 1) {
            return $matches[0];
        }

        if ($matches === []) {
            return null;
        }

        $active = array_values(array_filter(
            $matches,
            static fn (array $row): bool => (string) $row['status'] === 'active'
        ));

        if (count($active) === 1) {
            return $active[0];
        }

        // The ids, not only the last four digits. Without them this is a complaint nobody can
        // act on: the panel's BCA search runs against bc_supervisors.mobile, so a colliding
        // manager or disabled user is not findable from the screens at all.
        Logger::warning(sprintf(
            'Sign-in by mobile refused: %d accounts hold the number ending %s (user ids %s).',
            count($matches),
            substr($mobile, -4),
            implode(', ', array_map(static fn (array $row): string => (string) $row['id'], $matches))
        ));

        return null;
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
