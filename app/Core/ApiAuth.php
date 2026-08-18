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
                return [null, 'This device has been blocked. Contact your Admin/Supervisor.'];
            }

            if ((int) $existing['user_id'] !== $userId) {
                return [null, 'This device is already registered to another user.'];
            }

            Database::update('devices', $payload, 'id = :id', ['id' => (int) $existing['id']]);

            return [array_merge($existing, $payload), ''];
        }

        // One bound device per BC Supervisor unless an Admin resets it.
        if ((bool) Config::get('security.device_binding', true)) {
            $bound = Database::selectOne(
                "SELECT id, device_uuid FROM devices
                  WHERE user_id = :uid AND status = 'active'
                  LIMIT 1",
                ['uid' => $userId]
            );

            if ($bound !== null) {
                return [null, 'Your account is already bound to a different device. Ask your Admin/Supervisor to reset the device binding.'];
            }
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
}
