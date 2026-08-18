<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Settings;

/**
 * One time passcodes for sign-in and password reset.
 *
 * Only a SHA-256 of the code is stored, codes expire, each code is single use,
 * verification attempts are counted, and issuing is throttled per user. When no
 * SMS gateway is configured the code is written to the application log so
 * staging environments remain usable without silently "sending" nothing.
 */
final class Otp
{
    public const PURPOSE_LOGIN = 'login';
    public const PURPOSE_RESET = 'password_reset';
    public const PURPOSE_LATE = 'late_approval';

    /**
     * Issue a code for a user.
     *
     * @return array{sent:bool, channel:string, destination:string, message:string, debug_code:?string}
     */
    public static function issue(array $user, string $purpose = self::PURPOSE_LOGIN): array
    {
        $userId = (int) $user['id'];

        // Throttle: at most 3 codes per 10 minutes per user and purpose.
        $recent = (int) Database::scalar(
            'SELECT COUNT(*) FROM otp_codes
              WHERE user_id = :uid AND purpose = :purpose AND created_at > :since',
            ['uid' => $userId, 'purpose' => $purpose, 'since' => date('Y-m-d H:i:s', time() - 600)]
        );

        if ($recent >= 3) {
            return [
                'sent' => false,
                'channel' => 'none',
                'destination' => '',
                'message' => 'Too many codes requested. Please wait a few minutes and try again.',
                'debug_code' => null,
            ];
        }

        // Invalidate anything still outstanding for this purpose.
        Database::update(
            'otp_codes',
            ['consumed_at' => now()],
            'user_id = :uid AND purpose = :purpose AND consumed_at IS NULL',
            ['uid' => $userId, 'purpose' => $purpose]
        );

        $length = max(4, min(8, (int) Config::get('security.otp_length', 6)));
        $code = str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
        $ttl = max(60, (int) Config::get('security.otp_ttl_seconds', 300));

        $mobile = (string) ($user['mobile'] ?? '');
        $email = (string) ($user['email'] ?? '');

        $channel = 'log';
        $destination = $mobile !== '' ? $mobile : $email;

        if ($mobile !== '' && Settings::bool('sms_enabled', false)) {
            $channel = 'sms';
        } elseif ($email !== '' && (string) Config::get('mail.host', '') !== '' && !(bool) Config::get('mail.log_only', true)) {
            $channel = 'email';
        }

        Database::insert('otp_codes', [
            'user_id' => $userId,
            'purpose' => $purpose,
            'code_hash' => hash('sha256', $code),
            'channel' => $channel,
            'destination' => $destination !== '' ? mb_substr($destination, 0, 190) : null,
            'attempts' => 0,
            'expires_at' => date('Y-m-d H:i:s', time() + $ttl),
            'ip_address' => PHP_SAPI === 'cli' ? null : (new Request())->ip(),
            'created_at' => now(),
        ]);

        $message = sprintf(
            '%s: your LRMS verification code is %s. It expires in %d minutes. Do not share it.',
            app_name(),
            $code,
            (int) round($ttl / 60)
        );

        $sent = match ($channel) {
            'sms' => self::sendSms($mobile, $message, $code),
            'email' => self::sendEmail($email, $message),
            default => false,
        };

        if (!$sent) {
            // Deliberately logged, never returned to the browser in production.
            Logger::warning(sprintf(
                'OTP for user #%d (%s) delivered via log only: %s',
                $userId,
                $purpose,
                $code
            ));
        }

        Audit::log(Audit::LOGIN_OTP_SENT, [
            'user_id' => $userId,
            'entity_type' => 'user',
            'entity_id' => $userId,
            'description' => sprintf('Verification code issued for %s via %s.', $purpose, $channel),
        ]);

        return [
            'sent' => $sent,
            'channel' => $channel,
            'destination' => self::maskDestination($channel, $destination),
            'message' => $sent
                ? sprintf('A verification code has been sent to %s.', self::maskDestination($channel, $destination))
                : 'A verification code has been generated. Ask your administrator to check the LRMS log if you do not receive it.',
            // Surfaced on screen only when the app is in debug mode.
            'debug_code' => (bool) Config::get('app.debug', false) ? $code : null,
        ];
    }

    /**
     * @return array{ok:bool, message:string}
     */
    public static function verify(int $userId, string $code, string $purpose = self::PURPOSE_LOGIN): array
    {
        $code = trim($code);

        $row = Database::selectOne(
            'SELECT * FROM otp_codes
              WHERE user_id = :uid AND purpose = :purpose AND consumed_at IS NULL
              ORDER BY id DESC LIMIT 1',
            ['uid' => $userId, 'purpose' => $purpose]
        );

        if ($row === null) {
            return ['ok' => false, 'message' => 'No verification code is pending. Please request a new one.'];
        }

        if (strtotime((string) $row['expires_at']) < time()) {
            return ['ok' => false, 'message' => 'That code has expired. Please request a new one.'];
        }

        $maxAttempts = max(1, (int) Config::get('security.otp_max_attempts', 5));

        if ((int) $row['attempts'] >= $maxAttempts) {
            Database::update('otp_codes', ['consumed_at' => now()], 'id = :id', ['id' => (int) $row['id']]);

            return ['ok' => false, 'message' => 'Too many incorrect attempts. Please request a new code.'];
        }

        if (!hash_equals((string) $row['code_hash'], hash('sha256', $code))) {
            Database::update(
                'otp_codes',
                ['attempts' => (int) $row['attempts'] + 1],
                'id = :id',
                ['id' => (int) $row['id']]
            );

            $remaining = $maxAttempts - ((int) $row['attempts'] + 1);

            return [
                'ok' => false,
                'message' => $remaining > 0
                    ? sprintf('That code is not correct. %d attempt(s) left.', $remaining)
                    : 'That code is not correct. Please request a new code.',
            ];
        }

        Database::update('otp_codes', ['consumed_at' => now()], 'id = :id', ['id' => (int) $row['id']]);

        return ['ok' => true, 'message' => 'Verified.'];
    }

    /**
     * Generic HTTP SMS gateway. Placeholders in the configured endpoint are
     * replaced, which covers the URL based gateways common in Indian banking
     * deployments without hard-coding a specific vendor.
     */
    private static function sendSms(string $mobile, string $message, string $code): bool
    {
        $endpoint = Settings::string('sms_endpoint', '');

        if ($endpoint === '' || $mobile === '') {
            return false;
        }

        $url = str_replace(
            ['{mobile}', '{message}', '{otp}', '{sender}', '{api_key}'],
            [
                rawurlencode($mobile),
                rawurlencode($message),
                rawurlencode($code),
                rawurlencode(Settings::string('sms_sender_id', '')),
                rawurlencode(Settings::string('sms_api_key', '')),
            ],
            $endpoint
        );

        if (!function_exists('curl_init')) {
            return false;
        }

        $handle = curl_init($url);

        if ($handle === false) {
            return false;
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        if (strtoupper((string) Config::get('sms.method', 'GET')) === 'POST') {
            curl_setopt($handle, CURLOPT_POST, true);
        }

        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($response === false || $status < 200 || $status >= 300) {
            Logger::error(sprintf('SMS gateway failed (HTTP %d): %s', $status, $error));

            return false;
        }

        return true;
    }

    /**
     * Plain PHP mail() is used when SMTP credentials are configured by the host;
     * OTP delivery is expected to be by SMS in production, so this is a fallback.
     */
    private static function sendEmail(string $email, string $message): bool
    {
        if ($email === '' || !function_exists('mail')) {
            return false;
        }

        $from = (string) Config::get('mail.from_email', '');
        $headers = 'Content-Type: text/plain; charset=UTF-8';

        if ($from !== '') {
            $headers .= "\r\nFrom: " . (string) Config::get('mail.from_name', 'LRMS') . ' <' . $from . '>';
        }

        return @mail($email, app_name() . ' verification code', $message, $headers);
    }

    private static function maskDestination(string $channel, string $destination): string
    {
        if ($destination === '') {
            return 'your registered contact';
        }

        if ($channel === 'email' || str_contains($destination, '@')) {
            [$local, $domain] = array_pad(explode('@', $destination, 2), 2, '');

            return mb_substr($local, 0, 2) . str_repeat('•', max(1, mb_strlen($local) - 2)) . '@' . $domain;
        }

        return mask_mobile($destination);
    }

    /**
     * Housekeeping for the cron job.
     */
    public static function purgeExpired(int $days = 7): int
    {
        return Database::delete(
            'otp_codes',
            'expires_at < :cutoff',
            ['cutoff' => date('Y-m-d H:i:s', time() - ($days * 86400))]
        );
    }
}
