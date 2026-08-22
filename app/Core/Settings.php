<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Runtime settings: values managed from the BC Supervisor Panel are stored in
 * `system_settings` and override the matching config/config.php defaults.
 */
final class Settings
{
    private static ?array $cache = null;

    /** setting key => config.php fallback path */
    public const FALLBACKS = [
        'site_name' => 'app.name',
        'organisation_name' => 'app.long_name',
        'site_logo' => null,
        'maintenance_mode' => null,

        'report_deadline_time' => 'reports.deadline_time',
        'report_working_days' => null,
        'report_reminder_minutes' => null,
        'allow_late_submission_requests' => 'reports.allow_late_submission_requests',

        'gps_max_accuracy_metres' => 'gps.max_accuracy_metres',
        'gps_max_drift_metres' => 'gps.max_drift_metres',
        'gps_mock_location_allowed' => 'gps.mock_location_allowed',

        'min_visit_photos' => 'field.min_visit_photos',
        'min_inspection_photos' => 'field.min_inspection_photos',
        'watermark_photos' => 'field.watermark_photos',

        'otp_web_login' => 'security.otp_web_login',
        'otp_app_login' => 'security.otp_app_login',
        'device_binding' => 'security.device_binding',
        'api_token_ttl_days' => 'security.api_token_ttl_days',

        'sms_enabled' => 'sms.enabled',
        'sms_endpoint' => 'sms.endpoint',
        'sms_api_key' => 'sms.api_key',
        'sms_sender_id' => 'sms.sender_id',

        'payment_modes' => null,
        'default_visit_form_id' => null,
        'default_inspection_form_id' => null,
        'supervisor_offline_minutes' => null,
    ];

    private const DEFAULTS = [
        'maintenance_mode' => '0',
        'report_working_days' => '1,2,3,4,5,6',
        'report_reminder_minutes' => '60,30,10',
        'payment_modes' => 'UPI,Bank Transfer,Cheque,Other',
        // A supervisor whose device has not checked in for this long is shown as
        // offline rather than "live".
        'supervisor_offline_minutes' => '15',
    ];

    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        self::$cache = [];

        try {
            foreach (Database::select('SELECT `key`, `value` FROM system_settings') as $row) {
                self::$cache[(string) $row['key']] = $row['value'];
            }
        } catch (\Throwable $e) {
            // Database not installed yet: fall back to config values.
            Logger::warning('Settings could not be loaded: ' . $e->getMessage());
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

            if (is_array($configValue)) {
                return implode(',', $configValue);
            }

            if ($configValue !== null && $configValue !== '') {
                return is_bool($configValue) ? ($configValue ? '1' : '0') : $configValue;
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

    /**
     * Comma separated setting as a trimmed list.
     *
     * @return array<int, string>
     */
    public static function list(string $key, array $default = []): array
    {
        $raw = self::string($key, '');

        if ($raw === '') {
            return $default;
        }

        $items = array_map('trim', explode(',', $raw));

        return array_values(array_filter($items, static fn (string $v): bool => $v !== ''));
    }

    /** @return array<int, int> */
    public static function intList(string $key, array $default = []): array
    {
        $items = self::list($key);

        if ($items === []) {
            return $default;
        }

        return array_values(array_map('intval', $items));
    }

    public static function set(string $key, mixed $value, string $group = 'general'): void
    {
        if (is_array($value)) {
            $value = implode(',', $value);
        }

        $value = is_bool($value) ? ($value ? '1' : '0') : (string) $value;

        Database::statement(
            'INSERT INTO system_settings (`key`, `value`, `group`, created_at, updated_at)
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
