<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ApiAuth;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Request;

/**
 * Append-only audit trail.
 *
 * Every security or money relevant action funnels through here so the trail can
 * be relied upon: who did what, from where, on which record, and what changed.
 */
final class Audit
{
    /* Action constants keep the log queryable instead of full of free text. */
    public const LOGIN = 'login';
    public const LOGIN_FAILED = 'login_failed';
    public const LOGIN_OTP_SENT = 'login_otp_sent';
    public const LOGOUT = 'logout';
    public const PASSWORD_CHANGED = 'password_changed';
    public const PASSWORD_RESET = 'password_reset';

    public const USER_CREATED = 'user_created';
    public const USER_UPDATED = 'user_updated';
    public const USER_STATUS_CHANGED = 'user_status_changed';
    public const BRANCH_CREATED = 'branch_created';
    public const BRANCH_UPDATED = 'branch_updated';

    public const DEVICE_BOUND = 'device_bound';
    public const DEVICE_RESET = 'device_reset';
    public const DEVICE_BLOCKED = 'device_blocked';

    public const EXCEL_UPLOADED = 'excel_uploaded';
    public const EXCEL_MAPPED = 'excel_mapped';
    public const EXCEL_IMPORTED = 'excel_imported';
    public const EXCEL_CANCELLED = 'excel_cancelled';
    public const MAPPING_SAVED = 'mapping_template_saved';
    public const MAPPING_DELETED = 'mapping_template_deleted';

    public const ACCOUNT_CREATED = 'account_created';
    public const ACCOUNT_UPDATED = 'account_updated';
    public const ACCOUNT_ASSIGNED = 'account_assigned';
    public const ACCOUNT_REASSIGNED = 'account_reassigned';
    public const ACCOUNT_UNASSIGNED = 'account_unassigned';

    public const VISIT_SUBMITTED = 'visit_submitted';
    public const VISIT_APPROVED = 'visit_approved';
    public const VISIT_REJECTED = 'visit_rejected';
    public const INSPECTION_STARTED = 'inspection_started';
    public const INSPECTION_SUBMITTED = 'inspection_submitted';

    public const RECOVERY_RECORDED = 'recovery_recorded';
    public const RECOVERY_VERIFIED = 'recovery_verified';
    public const PROMISE_RECORDED = 'promise_recorded';
    public const PROMISE_UPDATED = 'promise_updated';
    public const FOLLOWUP_RECORDED = 'followup_recorded';
    public const ATTENDANCE_CHECK_IN = 'attendance_check_in';
    public const ATTENDANCE_CHECK_OUT = 'attendance_check_out';
    public const SSS_RECORDED = 'sss_recorded';
    public const SSS_UPDATED = 'sss_updated';

    public const FORM_CREATED = 'form_created';
    public const FORM_UPDATED = 'form_updated';
    public const FORM_FIELD_SAVED = 'form_field_saved';
    public const FORM_FIELD_DELETED = 'form_field_deleted';

    public const TARGET_CHANGED = 'target_changed';
    public const DEADLINE_CHANGED = 'deadline_changed';
    public const SETTINGS_CHANGED = 'settings_changed';
    public const SCHEMA_UPGRADED = 'schema_upgraded';
    public const LATE_SUBMISSION_REQUESTED = 'late_submission_requested';
    public const LATE_SUBMISSION_APPROVED = 'late_submission_approved';
    public const LATE_SUBMISSION_REJECTED = 'late_submission_rejected';
    public const REPORT_GENERATED = 'report_generated';
    public const REPORT_EXPORTED = 'report_exported';
    public const NOTIFICATION_SENT = 'notification_sent';
    public const SYNC_PUSH = 'sync_push';

    /**
     * @param array{
     *     entity_type?: string,
     *     entity_id?: int|null,
     *     description?: string,
     *     old?: array<string, mixed>|null,
     *     new?: array<string, mixed>|null,
     *     user_id?: int|null
     * } $context
     */
    public static function log(string $action, array $context = []): void
    {
        try {
            $request = Request::capture();
            $user = Auth::user();
            $userId = $context['user_id'] ?? ($user === null ? null : (int) $user['id']);

            // Resolve the actor's name even when the action failed before login.
            $userName = $user['name'] ?? null;
            $roleSlug = $user['role'] ?? null;

            if ($userName === null && $userId !== null) {
                $row = Database::selectOne(
                    'SELECT u.name, r.slug FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = :id',
                    ['id' => $userId]
                );
                $userName = $row['name'] ?? null;
                $roleSlug = $row['slug'] ?? null;
            }

            Database::insert('audit_logs', [
                'user_id' => $userId,
                'user_name' => $userName,
                'role_slug' => $roleSlug,
                'action' => $action,
                'entity_type' => $context['entity_type'] ?? null,
                'entity_id' => $context['entity_id'] ?? null,
                'description' => isset($context['description'])
                    ? mb_substr((string) $context['description'], 0, 500)
                    : null,
                'old_values' => self::encode($context['old'] ?? null),
                'new_values' => self::encode($context['new'] ?? null),
                'ip_address' => PHP_SAPI === 'cli' ? null : $request->ip(),
                'user_agent' => PHP_SAPI === 'cli' ? 'cli' : $request->userAgent(),
                'device_id' => ApiAuth::deviceId(),
                'request_method' => PHP_SAPI === 'cli' ? null : $request->method(),
                'request_path' => PHP_SAPI === 'cli' ? null : mb_substr($request->path(), 0, 255),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // The audit trail must never break the operation it is recording,
            // but a failure to write it is itself worth knowing about.
            Logger::error('Audit write failed for ' . $action . ': ' . $e->getMessage());
        }
    }

    /**
     * Convenience wrapper that diffs two record snapshots and logs only the
     * columns that actually changed.
     */
    public static function logChange(
        string $action,
        string $entityType,
        int $entityId,
        array $before,
        array $after,
        string $description = ''
    ): void {
        $old = [];
        $new = [];

        foreach ($after as $key => $value) {
            $previous = $before[$key] ?? null;

            if ((string) $previous === (string) $value) {
                continue;
            }

            $old[$key] = $previous;
            $new[$key] = $value;
        }

        if ($old === [] && $new === []) {
            return;
        }

        self::log($action, [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'description' => $description,
            'old' => $old,
            'new' => $new,
        ]);
    }

    private static function encode(?array $values): ?string
    {
        if ($values === null || $values === []) {
            return null;
        }

        // Never write credentials or tokens into the trail.
        foreach (['password', 'password_confirmation', 'token', 'token_hash', 'code_hash', 'remember_token'] as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = '[redacted]';
            }
        }

        return json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, string> action => human label, for the log filter UI */
    public static function actionLabels(): array
    {
        static $labels = null;

        if ($labels !== null) {
            return $labels;
        }

        $labels = [];
        $reflection = new \ReflectionClass(self::class);

        foreach ($reflection->getConstants() as $value) {
            if (is_string($value)) {
                $labels[$value] = ucwords(str_replace('_', ' ', $value));
            }
        }

        ksort($labels);

        return $labels;
    }
}
