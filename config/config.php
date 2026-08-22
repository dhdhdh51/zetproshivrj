<?php
/**
 * LRMS — configuration template.
 *
 * Copy this file to config/config.php and fill in your credentials.
 * Anything managed from the BC Supervisor Panel is stored in `system_settings` and
 * transparently overrides these values at runtime (see App\Core\Settings).
 *
 * NEVER commit real credentials. config/config.local.php is git-ignored and
 * recursively overrides this file, which is handy on development machines.
 */

return [
    'app' => [
        'name' => 'LRMS',
        'long_name' => 'Loan Recovery Management System',
        'url' => 'https://yourdomain.com',
        'debug' => false,
        'timezone' => 'Asia/Kolkata',
        'currency' => 'INR',
        // php -r "echo bin2hex(random_bytes(32));"
        'key' => 'change-this-to-a-long-random-string',
        // Force HTTPS redirects + HSTS. Disable only for local development.
        'force_https' => true,
    ],

    'database' => [
        'driver' => 'mysql',
        'host' => 'localhost',
        'port' => 3306,
        'database' => 'lrms',
        'username' => 'database_user',
        'password' => 'database_password',
        'charset' => 'utf8mb4',
        'socket' => '',
    ],

    'session' => [
        'name' => 'lrms_session',
        // Auto logout after this many seconds of inactivity.
        'lifetime' => 3600,
        'secure' => true,
        'same_site' => 'Lax',
        'remember_days' => 0, // 0 disables "remember me" for this security domain.
    ],

    'security' => [
        'login_max_attempts' => 5,
        'login_decay_minutes' => 15,
        // Consecutive failures before the account itself is locked.
        'account_lock_attempts' => 8,
        'account_lock_minutes' => 30,
        'password_min_length' => 8,
        'otp_length' => 6,
        'otp_ttl_seconds' => 300,
        'otp_max_attempts' => 5,
        // Require OTP as a second factor for web (admin/manager) sign-in.
        'otp_web_login' => false,
        // Require OTP for the Android BCA sign-in.
        'otp_app_login' => false,
        'api_token_ttl_days' => 30,
        // A BCA account may only be used from one bound device.
        'device_binding' => true,
        'api_rate_per_minute' => 90,
        'upload_max_bytes' => 8388608, // 8 MB per photo
    ],

    'gps' => [
        // Reject a visit when the device reports worse accuracy than this (metres).
        'max_accuracy_metres' => 200,
        // Warn when the captured point is further than this from the last known
        // village/branch centroid (metres). 0 disables the distance check.
        'max_drift_metres' => 0,
        'mock_location_allowed' => false,
    ],

    'field' => [
        // Minimum photographs required to submit a customer visit.
        'min_visit_photos' => 1,
        'min_inspection_photos' => 1,
        'require_borrower_signature' => false,
        'watermark_photos' => true,
    ],

    'sms' => [
        // Generic HTTP SMS gateway used for OTP delivery.
        // Placeholders: {mobile} {message} {otp}
        'enabled' => false,
        'endpoint' => '',
        'method' => 'GET',
        'sender_id' => '',
        'api_key' => '',
        // When disabled, OTPs are written to storage/logs so staging still works.
        'log_only' => true,
    ],

    'mail' => [
        'host' => '',
        'port' => 587,
        'username' => '',
        'password' => '',
        'encryption' => 'tls',
        'from_email' => '',
        'from_name' => 'LRMS',
        'log_only' => true,
    ],

    'reports' => [
        // Server time is authoritative for the daily report deadline.
        'deadline_time' => '18:00',
        'working_days' => [1, 2, 3, 4, 5, 6], // 1 = Monday … 7 = Sunday
        'reminder_minutes' => [60, 30, 10],
        'allow_late_submission_requests' => true,
    ],

    'storage' => [
        // Photographs and generated exports live outside the web root and are
        // streamed through authorised controllers only.
        'photos' => 'storage/uploads/photos',
        'exports' => 'storage/generated',
        'signatures' => 'storage/uploads/signatures',
    ],
];
