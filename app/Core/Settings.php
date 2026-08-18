<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Runtime settings: values managed from the Admin Panel are stored in the
 * `settings` table and override the matching config/config.php defaults.
 */
final class Settings
{
    private static ?array $cache = null;

    /** setting key => config.php fallback path */
    public const FALLBACKS = [
        'site_name' => 'app.name',
        'site_logo' => null,
        'contact_email' => 'smtp.from_email',
        'registration_enabled' => null,
        'maintenance_mode' => null,
        'default_currency' => 'app.currency',
        'default_template' => null,

        'ai_enabled' => 'openrouter.enabled',
        'openrouter_api_key' => 'openrouter.api_key',
        'openrouter_model' => 'openrouter.model',
        'openrouter_base_url' => 'openrouter.base_url',
        'ai_temperature' => 'openrouter.temperature',
        'ai_max_tokens' => 'openrouter.max_tokens',

        'smtp_host' => 'smtp.host',
        'smtp_port' => 'smtp.port',
        'smtp_username' => 'smtp.username',
        'smtp_password' => 'smtp.password',
        'smtp_encryption' => 'smtp.encryption',
        'smtp_from_email' => 'smtp.from_email',
        'smtp_from_name' => 'smtp.from_name',

        'payu_mode' => 'payu.mode',
        'payu_merchant_key' => 'payu.merchant_key',
        'payu_merchant_salt' => 'payu.merchant_salt',
        'payu_base_url' => 'payu.base_url',
    ];

    private const DEFAULTS = [
        'registration_enabled' => '1',
        'maintenance_mode' => '0',
        'default_template' => 'modern',
        'default_currency' => 'INR',
        'ai_enabled' => '1',
    ];

    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        self::$cache = [];

        try {
            foreach (Database::select('SELECT `key`, `value` FROM settings') as $row) {
                self::$cache[(string) $row['key']] = $row['value'];
            }
        } catch (\Throwable $e) {
            // Database not reachable / not installed yet: fall back to config values.
            Logger::warning('Settings could not be loaded from database: ' . $e->getMessage());
        }

        return self::$cache;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::all()[$key] ?? null;

        if ($value !== null && $value !== '') {
            return $value;
        }

        $fallback = self::FALLBACKS[$key] ?? null;
        if ($fallback !== null) {
            $configValue = Config::get($fallback);
            if ($configValue !== null && $configValue !== '') {
                return $configValue;
            }
        }

        if (array_key_exists($key, self::DEFAULTS)) {
            return self::DEFAULTS[$key];
        }

        return $default;
    }

    public static function string(string $key, string $default = ''): string
    {
        $value = self::get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public static function float(string $key, float $default = 0.0): float
    {
        $value = self::get($key, $default);

        return is_numeric($value) ? (float) $value : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key, $default ? '1' : '0');

        if (is_bool($value)) {
            return $value;
        }

        return in_array((string) $value, ['1', 'true', 'on', 'yes'], true);
    }

    public static function set(string $key, mixed $value, string $group = 'general'): void
    {
        $value = is_bool($value) ? ($value ? '1' : '0') : (string) $value;

        Database::statement(
            'INSERT INTO settings (`key`, `value`, `group`, created_at, updated_at)
             VALUES (:key, :value, :group, :now, :now)
             ON DUPLICATE KEY UPDATE `value` = :value, `group` = :group, updated_at = :now',
            [
                'key' => $key,
                'value' => $value,
                'group' => $group,
                'now' => now(),
            ]
        );

        if (self::$cache !== null) {
            self::$cache[$key] = $value;
        }
    }

    public static function setMany(array $values, string $group = 'general'): void
    {
        foreach ($values as $key => $value) {
            self::set((string) $key, $value, $group);
        }
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}
