<?php
/**
 * DocuPilot AI — configuration template.
 *
 * Copy this file to config/config.php and fill in your credentials.
 * Values stored here are defaults; anything managed from the Admin Panel
 * (AI / Email / PayU / System settings) is stored in the `settings` table and
 * transparently overrides these values at runtime.
 */

return [
    'app' => [
        'name' => 'DocuPilot AI',
        'url' => 'https://yourdomain.com',
        'debug' => false,
        'timezone' => 'Asia/Kolkata',
        'currency' => 'INR',
        'key' => 'change-this-to-a-long-random-string',
    ],

    'database' => [
        'host' => 'localhost',
        'port' => 3306,
        'database' => 'docupilot',
        'username' => 'database_user',
        'password' => 'database_password',
        'charset' => 'utf8mb4',
    ],

    'google' => [
        'client_id' => '',
        'client_secret' => '',
        'redirect_uri' => 'https://yourdomain.com/auth/google/callback',
    ],

    'openrouter' => [
        'api_key' => '',
        'model' => 'openai/gpt-4o-mini',
        'base_url' => 'https://openrouter.ai/api/v1',
        'temperature' => 0.4,
        'max_tokens' => 2000,
        'enabled' => true,
        'timeout' => 90,
    ],

    'smtp' => [
        'host' => '',
        'port' => 587,
        'username' => '',
        'password' => '',
        'encryption' => 'tls',
        'from_email' => '',
        'from_name' => 'DocuPilot AI',
    ],

    'payu' => [
        'mode' => 'test',
        'merchant_key' => '',
        'merchant_salt' => '',
        'base_url' => 'https://test.payu.in/_payment',
        'verify_url' => 'https://test.payu.in/merchant/postservice.php?form=2',
    ],

    'session' => [
        'name' => 'docupilot_session',
        'lifetime' => 7200,
        'secure' => true,
        'same_site' => 'Lax',
        'remember_days' => 30,
    ],

    'security' => [
        'login_max_attempts' => 5,
        'login_decay_minutes' => 15,
        'ai_max_per_minute' => 5,
        'upload_max_bytes' => 2097152,
    ],

    'mail' => [
        // Set to true on hosts without SMTP credentials to log mails instead of sending.
        'log_only' => false,
    ],
];
