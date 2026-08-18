<?php

declare(strict_types=1);

namespace App\Core;

/**
 * File based rate limiter — works on shared hosting without extra services.
 */
final class RateLimiter
{
    private static function directory(): string
    {
        $dir = base_path('storage/logs/throttle');

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir;
    }

    private static function file(string $key): string
    {
        return self::directory() . '/' . sha1($key) . '.json';
    }

    private static function read(string $key): array
    {
        $file = self::file($key);

        if (!is_file($file)) {
            return ['hits' => 0, 'expires_at' => 0];
        }

        $data = json_decode((string) @file_get_contents($file), true);

        if (!is_array($data) || ($data['expires_at'] ?? 0) < time()) {
            return ['hits' => 0, 'expires_at' => 0];
        }

        return ['hits' => (int) ($data['hits'] ?? 0), 'expires_at' => (int) ($data['expires_at'] ?? 0)];
    }

    public static function hit(string $key, int $decaySeconds = 60): int
    {
        $data = self::read($key);
        $data['hits']++;

        if ($data['expires_at'] < time()) {
            $data['expires_at'] = time() + $decaySeconds;
        }

        @file_put_contents(self::file($key), json_encode($data), LOCK_EX);
        self::sweep();

        return $data['hits'];
    }

    public static function attempts(string $key): int
    {
        return self::read($key)['hits'];
    }

    public static function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        return self::attempts($key) >= $maxAttempts;
    }

    public static function availableIn(string $key): int
    {
        $data = self::read($key);

        return max(0, $data['expires_at'] - time());
    }

    public static function clear(string $key): void
    {
        @unlink(self::file($key));
    }

    /**
     * Throw a 429 when the limit is exceeded, otherwise record a hit.
     */
    public static function guard(string $key, int $maxAttempts, int $decaySeconds, string $message = ''): void
    {
        if (self::tooManyAttempts($key, $maxAttempts)) {
            $seconds = self::availableIn($key);
            throw new HttpException(
                429,
                $message !== '' ? $message : sprintf('Too many attempts. Please try again in %d seconds.', $seconds)
            );
        }

        self::hit($key, $decaySeconds);
    }

    /** Remove stale throttle files occasionally so the folder stays small. */
    private static function sweep(): void
    {
        if (random_int(1, 50) !== 1) {
            return;
        }

        foreach (glob(self::directory() . '/*.json') ?: [] as $file) {
            if (@filemtime($file) < time() - 86400) {
                @unlink($file);
            }
        }
    }
}
