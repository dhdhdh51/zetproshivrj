<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Dot-notation configuration loader for /config/config.php.
 */
final class Config
{
    private static array $items = [];
    private static bool $loaded = false;

    public static function load(string $path): void
    {
        if (!is_file($path)) {
            $example = dirname($path) . '/config.example.php';
            if (is_file($example)) {
                throw new RuntimeException(
                    'Missing config/config.php. Copy config/config.example.php to config/config.php and fill in your credentials.'
                );
            }
            throw new RuntimeException('Configuration file not found: ' . $path);
        }

        /** @var array $config */
        $config = require $path;

        if (!is_array($config)) {
            throw new RuntimeException('config/config.php must return an array.');
        }

        // Optional, git-ignored local overrides (handy for development machines).
        $local = dirname($path) . '/config.local.php';
        if (is_file($local)) {
            $overrides = require $local;
            if (is_array($overrides)) {
                $config = self::mergeRecursive($config, $overrides);
            }
        }

        self::$items = $config;
        self::$loaded = true;
    }

    private static function mergeRecursive(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = self::mergeRecursive($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    public static function loaded(): bool
    {
        return self::$loaded;
    }

    /**
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = self::$items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public static function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $ref = &self::$items;

        foreach ($segments as $segment) {
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }

        $ref = $value;
    }

    public static function all(): array
    {
        return self::$items;
    }
}
