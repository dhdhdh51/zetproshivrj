<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Stateless bearer-token authentication for the Android application.
 *
 * Tokens are random 40-byte strings; only their SHA-256 hash is stored, so a
 * database leak cannot be replayed against the API. Every token is bound to the
 * device it was issued to when `security.device_binding` is enabled.
 */
final class ApiAuth
{
    private static ?array $token = null;

    /* ------------------------------------------------------------------ */
    /* Issuing                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * @return array{token:string, expires_at:string, token_id:int}
     */
    public static function issue(int $userId, ?int $deviceId, string $name = 'android'): array
    {
        $plain = bin2hex(random_bytes(40));
        $days = max(1, (int) Config::get('security.api_token_ttl_days', 30));
        $expiresAt = date('Y-m-d H:i:s', time() + ($days * 86400));

        $id = Database::insert('api_tokens', [
            'user_id' => $userId,
            'device_id' => $deviceId,
            'name' => $name,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => $expiresAt,
            'last_used_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['token' => $plain, 'expires_at' => $expiresAt, 'token_id' => $id];
    }

    public static function revoke(int $tokenId): void
    {
        Database::update(
            'api_tokens',
            ['revoked_at' => now(), 'updated_at' => now()],
            'id = :id AND revoked_at IS NULL',
            ['id' => $tokenId]
        );
    }

    /**
     * Revoke every active token for a user (used when an account is disabled or
     * a device is unbound).
     */
    public static function revokeAllFor(int $userId): int
    {
        return Database::update(
            'api_tokens',
            ['revoked_at' => now(), 'updated_at' => now()],
            'user_id = :uid AND revoked_at IS NULL',
            ['uid' => $userId]
        );
    }

    /* ------------------------------------------------------------------ */
    /* Verifying                                                          */
    /* ------------------------------------------------------------------ */

    public static function bearer(Request $request): ?string
    {
        $header = (string) $request->header('Authorization');

        if (preg_match('/^Bearer\s+([A-Za-z0-9._-]+)$/', trim($header), $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * Resolve the bearer token, load its owner and expose them through Auth.
     * Returns the token row, or null when authentication fails.
     */
    public static function authenticate(Request $request): ?array
    {
        $plain = self::bearer($request);

        if ($plain === null) {
            return null;
        }

        $row = Database::selectOne(
            'SELECT t.*, d.device_uuid, d.status AS device_status
               FROM api_tokens t
          LEFT JOIN devices d ON d.id = t.device_id
              WHERE t.token_hash = :hash
              LIMIT 1',
            ['hash' => hash('sha256', $plain)]
        );

        if ($row === null || $row['revoked_at'] !== null) {
            return null;
        }

        if ($row['expires_at'] !== null && strtotime((string) $row['expires_at']) < time()) {
            return null;
        }

        $user = Auth::findById((int) $row['user_id']);

        if ($user === null || (string) $user['status'] !== 'active') {
            return null;
        }

        // Device binding: the caller must present the same device UUID the token
        // was issued to.
        if ((bool) Config::get('security.device_binding', true) && $row['device_id'] !== null) {
            $claimed = (string) $request->header('X-Device-Id');

            if ($claimed === '' || !hash_equals((string) $row['device_uuid'], $claimed)) {
                return null;
            }

            if ((string) $row['device_status'] === 'blocked') {
                return null;
            }
        }

        Auth::setUser($user);
        self::$token = $row;

        self::touch($row, $request);

        return $row;
    }

    private static function touch(array $token, Request $request): void
    {
        // Keep writes cheap: only refresh once a minute per token.
        $last = $token['last_used_at'] === null ? 0 : (int) strtotime((string) $token['last_used_at']);

        if (time() - $last < 60) {
            return;
        }

        Database::update('api_tokens', ['last_used_at' => now()], 'id = :id', ['id' => (int) $token['id']]);

        if ($token['device_id'] !== null) {
            Database::update(
                'devices',
                ['last_seen_at' => now(), 'last_ip' => $request->ip()],
                'id = :id',
                ['id' => (int) $token['device_id']]
            );
        }
    }

    public static function token(): ?array
    {
        return self::$token;
    }

    public static function tokenId(): ?int
    {
        return self::$token === null ? null : (int) self::$token['id'];
    }

    public static function deviceId(): ?int
    {
        $id = self::$token['device_id'] ?? null;

        return $id === null ? null : (int) $id;
    }

    /* ------------------------------------------------------------------ */
    /* Devices                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Register (or re-recognise) the calling device for a user.
     *
     * @param array<string, mixed> $info
     * @return array{0:?array, 1:string} device row + failure reason ('' on success)
     */
    public static function registerDevice(int $userId, string $deviceUuid, array $info = []): array
    {
        if ($deviceUuid === '') {
            return [null, 'A device identifier is required.'];
        }

        /*
         * One transaction, behind a lock on the account's own row.
         *
         * Everything below reads the state of this account's handsets and then writes based on
         * what it read. Two sign-ins arriving together — a BCA moving to a new phone, or the app
         * retrying a request that had in fact succeeded — could both find no other active
         * handset and both bind one, leaving the account with two. "One live handset per
         * account" is the rule this whole method exists to keep, so it cannot depend on the two
         * requests not overlapping.
         *
         * MySQL has no way to express the rule as a constraint: there is no partial unique index,
         * so "one row per user where status is active" cannot be declared and left to the
         * database. Serialising the decision is the alternative.
         *
         * The lock is taken on `users`, not on `devices`. The user row certainly exists — the
         * caller has just authenticated against it — so this is an ordinary row lock on a primary
         * key. Locking `devices` instead would mean locking a set that is often empty, and an
         * empty range in InnoDB is a gap lock, which deadlocks against a concurrent insert of the
         * very row being waited for.
         */
        return Database::transaction(static function () use ($userId, $deviceUuid, $info): array {
            Database::selectOne('SELECT id FROM users WHERE id = :id FOR UPDATE', ['id' => $userId]);

            return self::bindDevice($userId, $deviceUuid, $info);
        });
    }

    /**
     * The binding decision itself. Runs inside the transaction opened by registerDevice(), so
     * every read here is protected by the lock taken there.
     *
     * @param array<string, mixed> $info
     * @return array{0:?array, 1:string}
     */
    private static function bindDevice(int $userId, string $deviceUuid, array $info): array
    {
        $existing = Database::selectOne(
            'SELECT * FROM devices WHERE device_uuid = :uuid LIMIT 1',
            ['uuid' => $deviceUuid]
        );

        $payload = [
            'model' => (string) ($info['model'] ?? ''),
            'manufacturer' => (string) ($info['manufacturer'] ?? ''),
            'os_version' => (string) ($info['os_version'] ?? ''),
            'app_version' => (string) ($info['app_version'] ?? ''),
            'fcm_token' => $info['fcm_token'] ?? null,
            'last_seen_at' => now(),
            'updated_at' => now(),
        ];

        if ($existing !== null) {
            if ((string) $existing['status'] === 'blocked') {
                return [null, 'This handset has been blocked. Contact your BC Supervisor.'];
            }

            $someoneElses = (int) $existing['user_id'] !== $userId;

            // Somebody else's working handset. Only a release frees it, and until then only
            // they can be signed in on it — otherwise a phone left on a desk is a way into
            // another BCA's accounts.
            if ($someoneElses && (string) $existing['status'] === 'active') {
                return [null, sprintf(
                    'This handset is already bound to %s. Ask your BC Supervisor to release it first.',
                    self::describeHolder((int) $existing['user_id'])
                )];
            }

            // Signing in means the handset is in use, so the row has to end up active and
            // owned by whoever is signing in. Leaving a working phone as 'unbound' would show
            // it as released on the staff screen and let the account bind a second one.
            $reactivating = (string) $existing['status'] !== 'active';

            if ($someoneElses || $reactivating) {
                // Both of those turn this row active for $userId, so the one-handset rule has
                // to be checked here as well as on the insert below. Without it a BCA with a
                // working phone could pick up any released handset and end up with two.
                $clash = self::activeDeviceElsewhere($userId, (int) $existing['id']);

                if ($clash !== null) {
                    return [null, self::alreadyBoundMessage($clash)];
                }
            }

            if ($someoneElses) {
                /*
                 * Released by a BC Supervisor, so the handset has been handed on. The row
                 * moves to its new owner rather than standing in their way.
                 *
                 * This is the bug the branch kept hitting: `device_uuid` is unique, so a
                 * phone that had ever been used by one BCA could never serve another.
                 * Releasing it was not enough, because the row still named the first one and
                 * the second BCA's sign-in was refused as "registered to another user" — with
                 * nothing on any screen to say what would fix it.
                 */
                $payload['user_id'] = $userId;
            }

            if ($someoneElses || $reactivating) {
                $payload['status'] = 'active';
                $payload['bound_at'] = now();
            }

            Database::update('devices', $payload, 'id = :id', ['id' => (int) $existing['id']]);

            return [array_merge($existing, $payload), ''];
        }

        // One bound handset per BCA unless a BC Supervisor releases it.
        $clash = self::activeDeviceElsewhere($userId, null);

        if ($clash !== null) {
            return [null, self::alreadyBoundMessage($clash)];
        }

        $id = Database::insert('devices', array_merge($payload, [
            'user_id' => $userId,
            'device_uuid' => $deviceUuid,
            'status' => 'active',
            'bound_at' => now(),
            'created_at' => now(),
        ]));

        return [Database::selectOne('SELECT * FROM devices WHERE id = :id', ['id' => $id]), ''];
    }

    /**
     * The user's active handset other than `$exceptDeviceId`, if they have one.
     *
     * @return array<string, mixed>|null
     */
    private static function activeDeviceElsewhere(int $userId, ?int $exceptDeviceId): ?array
    {
        if (!(bool) Config::get('security.device_binding', true)) {
            return null;
        }

        return Database::selectOne(
            "SELECT id, device_uuid, model, manufacturer FROM devices
              WHERE user_id = :uid AND status = 'active' AND id <> :except
              LIMIT 1",
            ['uid' => $userId, 'except' => $exceptDeviceId ?? 0]
        );
    }

    /**
     * Name the handset the account is stuck on, and what to do about it.
     *
     * The model is the only thing a BCA can recognise their own phone by, so it goes in the
     * message. A device uuid would be accurate and useless.
     *
     * @param array<string, mixed> $device
     */
    private static function alreadyBoundMessage(array $device): string
    {
        $name = trim(
            (string) ($device['manufacturer'] ?? '') . ' ' . (string) ($device['model'] ?? '')
        );

        return sprintf(
            'Your account is already bound to %s. Ask your BC Supervisor to release that handset, then sign in again.',
            $name !== '' ? $name : 'another handset'
        );
    }

    /**
     * Who a handset belongs to, for the refusal shown to whoever picked it up.
     */
    private static function describeHolder(int $userId): string
    {
        $holder = Database::selectOne(
            'SELECT u.name, s.bc_code FROM users u
          LEFT JOIN bc_supervisors s ON s.user_id = u.id
              WHERE u.id = :id',
            ['id' => $userId]
        );

        if ($holder === null) {
            return 'another user';
        }

        $described = trim(
            (string) ($holder['name'] ?? '') . ' (' . (string) ($holder['bc_code'] ?? '') . ')',
            ' ()'
        );

        return $described !== '' ? $described : 'another user';
    }
}
