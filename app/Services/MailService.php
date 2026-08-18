<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use App\Core\Settings;
use App\Models\EmailLog;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

/**
 * SMTP delivery for verification, password reset, document delivery and test mails.
 * Every attempt (success or failure) is written to the `email_logs` table.
 */
final class MailService
{
    private EmailLog $logs;

    public function __construct()
    {
        $this->logs = new EmailLog();
    }

    /* ------------------------------------------------------------------ */
    /* Configuration                                                       */
    /* ------------------------------------------------------------------ */

    public function config(): array
    {
        return [
            'host' => Settings::string('smtp_host'),
            'port' => Settings::int('smtp_port', 587),
            'username' => Settings::string('smtp_username'),
            'password' => Settings::string('smtp_password'),
            'encryption' => strtolower(Settings::string('smtp_encryption', 'tls')),
            'from_email' => Settings::string('smtp_from_email'),
            'from_name' => Settings::string('smtp_from_name', 'DocuPilot AI'),
        ];
    }

    public function isConfigured(): bool
    {
        $config = $this->config();

        return $config['host'] !== '' && $config['from_email'] !== '';
    }

    public function isAvailable(): bool
    {
        return class_exists(PHPMailer::class);
    }

    /* ------------------------------------------------------------------ */
    /* Sending                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * @param array{
     *     type?:string, user_id?:int|null, document_id?:int|null, attachments?:array<int,array{path:string,name:string}>,
     *     reply_to?:string, to_name?:string, cc?:string
     * } $options
     * @return array{success:bool, message:string}
     */
    public function send(string $to, string $subject, string $htmlBody, array $options = []): array
    {
        $type = (string) ($options['type'] ?? 'general');
        $attachments = $options['attachments'] ?? [];
        $config = $this->config();

        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return $this->fail($to, $subject, $htmlBody, $type, $options, 'Invalid recipient email address.');
        }

        if (!$this->isAvailable()) {
            return $this->fail(
                $to,
                $subject,
                $htmlBody,
                $type,
                $options,
                'PHPMailer is not installed. Run "composer install" on the server.'
            );
        }

        if ((bool) config('mail.log_only', false)) {
            $this->logs->log($this->logRow($to, $subject, $htmlBody, $type, $options, 'sent', null, $attachments));

            return ['success' => true, 'message' => 'Email logged (log_only mode is enabled).'];
        }

        if (!$this->isConfigured()) {
            return $this->fail(
                $to,
                $subject,
                $htmlBody,
                $type,
                $options,
                'SMTP is not configured. Add your SMTP host and From address in Admin > Email Settings.'
            );
        }

        $mailer = new PHPMailer(true);

        try {
            $mailer->isSMTP();
            $mailer->Host = $config['host'];
            $mailer->Port = $config['port'];
            $mailer->CharSet = PHPMailer::CHARSET_UTF8;
            $mailer->Timeout = 20;

            if ($config['username'] !== '') {
                $mailer->SMTPAuth = true;
                $mailer->Username = $config['username'];
                $mailer->Password = $config['password'];
            }

            if ($config['encryption'] === 'ssl') {
                $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($config['encryption'] === 'tls') {
                $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mailer->SMTPSecure = '';
                $mailer->SMTPAutoTLS = false;
            }

            $mailer->SMTPDebug = SMTP::DEBUG_OFF;

            $mailer->setFrom($config['from_email'], $config['from_name']);
            $mailer->addAddress($to, (string) ($options['to_name'] ?? ''));

            if (!empty($options['reply_to']) && filter_var($options['reply_to'], FILTER_VALIDATE_EMAIL) !== false) {
                $mailer->addReplyTo((string) $options['reply_to']);
            }

            if (!empty($options['cc']) && filter_var($options['cc'], FILTER_VALIDATE_EMAIL) !== false) {
                $mailer->addCC((string) $options['cc']);
            }

            foreach ($attachments as $attachment) {
                $path = (string) ($attachment['path'] ?? '');
                if ($path !== '' && is_file($path)) {
                    $mailer->addAttachment($path, (string) ($attachment['name'] ?? basename($path)));
                }
            }

            $mailer->isHTML(true);
            $mailer->Subject = $subject;
            $mailer->Body = $htmlBody;
            $mailer->AltBody = trim(strip_tags(str_replace(['<br>', '<br/>', '</p>'], "\n", $htmlBody)));

            $mailer->send();

            $this->logs->log($this->logRow($to, $subject, $htmlBody, $type, $options, 'sent', null, $attachments));

            return ['success' => true, 'message' => 'Email sent successfully.'];
        } catch (\Throwable $e) {
            $error = $mailer->ErrorInfo !== '' ? $mailer->ErrorInfo : $e->getMessage();
            Logger::error('Email send failed: ' . $error, ['to' => $to, 'type' => $type]);

            return $this->fail($to, $subject, $htmlBody, $type, $options, $error);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Application emails                                                  */
    /* ------------------------------------------------------------------ */

    public function sendVerification(array $user, string $token): array
    {
        $link = url('email/verify/' . $token);

        return $this->send(
            (string) $user['email'],
            'Confirm your email address · ' . app_name(),
            $this->render('verify', [
                'name' => (string) $user['name'],
                'link' => $link,
            ]),
            ['type' => 'verification', 'user_id' => (int) $user['id'], 'to_name' => (string) $user['name']]
        );
    }

    public function sendPasswordReset(array $user, string $token): array
    {
        $link = url('password/reset/' . $token);

        return $this->send(
            (string) $user['email'],
            'Reset your password · ' . app_name(),
            $this->render('reset', [
                'name' => (string) $user['name'],
                'link' => $link,
            ]),
            ['type' => 'password_reset', 'user_id' => (int) $user['id'], 'to_name' => (string) $user['name']]
        );
    }

    /**
     * Document delivery with the generated PDF attached.
     */
    public function sendDocument(array $document, array $payload, ?string $pdfPath, array $profile = []): array
    {
        $attachments = [];

        if ($pdfPath !== null && is_file($pdfPath)) {
            $attachments[] = [
                'path' => $pdfPath,
                'name' => $document['document_number'] . '.pdf',
            ];
        }

        $body = $this->render('document', [
            'message' => (string) ($payload['message'] ?? ''),
            'document' => $document,
            'profile' => $profile,
            'share_url' => (string) ($payload['share_url'] ?? ''),
        ]);

        return $this->send(
            (string) $payload['email'],
            (string) $payload['subject'],
            $body,
            [
                'type' => 'document',
                'user_id' => (int) $document['user_id'],
                'document_id' => (int) $document['id'],
                'attachments' => $attachments,
                'reply_to' => (string) ($profile['email'] ?? ''),
                'to_name' => (string) ($document['client_name'] ?? ''),
            ]
        );
    }

    public function sendTest(string $to, ?int $userId = null): array
    {
        return $this->send(
            $to,
            'SMTP test from ' . app_name(),
            $this->render('test', ['when' => now('d M Y, H:i')]),
            ['type' => 'test', 'user_id' => $userId]
        );
    }

    /* ------------------------------------------------------------------ */
    /* Templates                                                           */
    /* ------------------------------------------------------------------ */

    public function render(string $template, array $data = []): string
    {
        $file = base_path('resources/emails/' . $template . '.php');

        if (!is_file($file)) {
            return nl2br(e((string) ($data['message'] ?? '')));
        }

        $content = $this->capture($file, $data);
        $layout = base_path('resources/emails/layout.php');

        if (!is_file($layout)) {
            return $content;
        }

        return $this->capture($layout, array_merge($data, ['content' => $content]));
    }

    private function capture(string $file, array $data): string
    {
        ob_start();
        extract($data, EXTR_SKIP);
        require $file;

        return (string) ob_get_clean();
    }

    /* ------------------------------------------------------------------ */
    /* Logging helpers                                                     */
    /* ------------------------------------------------------------------ */

    private function fail(string $to, string $subject, string $body, string $type, array $options, string $error): array
    {
        $this->logs->log($this->logRow($to, $subject, $body, $type, $options, 'failed', $error, $options['attachments'] ?? []));

        return ['success' => false, 'message' => $error];
    }

    private function logRow(
        string $to,
        string $subject,
        string $body,
        string $type,
        array $options,
        string $status,
        ?string $error,
        array $attachments
    ): array {
        return [
            'user_id' => $options['user_id'] ?? null,
            'document_id' => $options['document_id'] ?? null,
            'type' => $type,
            'to_email' => $to,
            'subject' => mb_substr($subject, 0, 255),
            'body' => $body,
            'attachment' => $attachments === [] ? null : basename((string) ($attachments[0]['name'] ?? '')),
            'status' => $status,
            'error_message' => $error,
            'sent_at' => $status === 'sent' ? now() : null,
        ];
    }
}
