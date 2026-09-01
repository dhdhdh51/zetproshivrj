<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Settings;

/**
 * Daily report deadline.
 *
 * The server clock is the only authority here. The Android app is told the
 * remaining seconds and the absolute server deadline so it can show a countdown,
 * but a device with a rewound clock cannot buy itself extra time: every
 * submission is stamped and classified on the server.
 */
final class Deadline
{
    /** @return array<int, int> ISO day numbers, 1 = Monday … 7 = Sunday */
    public static function workingDays(): array
    {
        $days = Settings::intList('report_working_days', [1, 2, 3, 4, 5, 6]);
        $days = array_values(array_filter($days, static fn (int $d): bool => $d >= 1 && $d <= 7));

        return $days === [] ? [1, 2, 3, 4, 5, 6] : $days;
    }

    public static function time(): string
    {
        $time = Settings::string('report_deadline_time', '18:00');

        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) === 1 ? $time : '18:00';
    }

    public static function isWorkingDay(?string $date = null): bool
    {
        $date ??= today();

        return in_array((int) date('N', (int) strtotime($date)), self::workingDays(), true);
    }

    /**
     * Absolute deadline for a given working date, as a server DATETIME.
     */
    public static function at(?string $date = null): string
    {
        $date ??= today();
        [$hour, $minute] = array_map('intval', explode(':', self::time()));

        return date('Y-m-d H:i:s', (int) mktime($hour, $minute, 0, (int) date('n', (int) strtotime($date)), (int) date('j', (int) strtotime($date)), (int) date('Y', (int) strtotime($date))));
    }

    public static function hasPassed(?string $date = null): bool
    {
        return time() > strtotime(self::at($date));
    }

    public static function secondsRemaining(?string $date = null): int
    {
        return max(0, strtotime(self::at($date)) - time());
    }

    /**
     * Reminder thresholds that have just been crossed, used by the cron job.
     *
     * @return array<int, int> minutes
     */
    public static function reminderMinutes(): array
    {
        $minutes = Settings::intList('report_reminder_minutes', [60, 30, 10]);
        rsort($minutes);

        return $minutes;
    }

    /**
     * Everything the UI and the API need in one payload.
     *
     * @return array<string, mixed>
     */
    public static function status(?string $date = null): array
    {
        $date ??= today();
        $working = self::isWorkingDay($date);
        $deadlineAt = self::at($date);
        $remaining = self::secondsRemaining($date);
        $passed = self::hasPassed($date);

        return [
            'report_date' => $date,
            'is_working_day' => $working,
            'working_days' => self::workingDays(),
            'deadline_time' => self::time(),
            'deadline_at' => $deadlineAt,
            'server_time' => now(),
            'server_timezone' => date_default_timezone_get(),
            'seconds_remaining' => $passed ? 0 : $remaining,
            'has_passed' => $passed,
            'locked' => $working && $passed,
            'late_requests_allowed' => Settings::bool('allow_late_submission_requests', true),
            'reminder_minutes' => self::reminderMinutes(),
        ];
    }

    /**
     * Fetch (or create) the daily submission row for a supervisor.
     *
     * @return array<string, mixed>
     */
    public static function submissionFor(int $bcSupervisorId, ?string $date = null, string $typeSlug = 'daily_field_report'): array
    {
        $date ??= today();

        $type = Database::selectOne('SELECT id FROM report_types WHERE slug = :slug', ['slug' => $typeSlug]);

        if ($type === null) {
            throw new \RuntimeException('Report type not configured: ' . $typeSlug);
        }

        $typeId = (int) $type['id'];

        $existing = Database::selectOne(
            'SELECT * FROM report_submissions
              WHERE bc_supervisor_id = :bc AND report_type_id = :type AND report_date = :date
              LIMIT 1',
            ['bc' => $bcSupervisorId, 'type' => $typeId, 'date' => $date]
        );

        if ($existing !== null) {
            return $existing;
        }

        $branchId = (int) Database::scalar(
            'SELECT branch_id FROM bc_supervisors WHERE id = :id',
            ['id' => $bcSupervisorId]
        );

        $id = Database::insert('report_submissions', [
            'bc_supervisor_id' => $bcSupervisorId,
            'branch_id' => $branchId,
            'report_type_id' => $typeId,
            'report_date' => $date,
            'deadline_at' => self::at($date),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Database::selectOne('SELECT * FROM report_submissions WHERE id = :id', ['id' => $id]) ?? [];
    }

    /**
     * Record a day-end report submission.
     *
     * Before the deadline it is accepted outright. After the deadline the normal
     * path is locked: the submission is stored as `late_pending` and needs an
     * BC Supervisor approval, with the reason and the approver recorded.
     *
     * @return array{status:string, is_late:bool, submission_id:int, message:string}
     */
    public static function submit(int $bcSupervisorId, ?string $date = null, array $payload = []): array
    {
        $date ??= today();
        $submission = self::submissionFor($bcSupervisorId, $date);
        $submissionId = (int) $submission['id'];

        if (in_array((string) $submission['status'], ['submitted', 'late_approved'], true)) {
            return [
                'status' => (string) $submission['status'],
                'is_late' => (bool) $submission['is_late'],
                'submission_id' => $submissionId,
                'message' => 'Today\'s report has already been submitted.',
            ];
        }

        $deadlineAt = (string) $submission['deadline_at'];
        $isLate = time() > strtotime($deadlineAt);
        $lateReason = trim((string) ($payload['late_reason'] ?? ''));

        $counts = self::dayCounts($bcSupervisorId, $date);

        if ($isLate && !Settings::bool('allow_late_submission_requests', true)) {
            Database::update('report_submissions', [
                'status' => 'locked',
                'is_late' => 1,
                'updated_at' => now(),
            ], 'id = :id', ['id' => $submissionId]);

            return [
                'status' => 'locked',
                'is_late' => true,
                'submission_id' => $submissionId,
                'message' => 'The deadline has passed and late submissions are disabled. Contact your BC Supervisor.',
            ];
        }

        $status = $isLate ? 'late_pending' : 'submitted';

        Database::update('report_submissions', [
            'submitted_at' => now(),
            'is_late' => $isLate ? 1 : 0,
            'status' => $status,
            'visits_count' => $counts['visits'],
            'recovery_amount' => $counts['recovery'],
            'promises_count' => $counts['promises'],
            'summary' => isset($payload['summary']) ? mb_substr((string) $payload['summary'], 0, 500) : null,
            'late_reason' => $isLate && $lateReason !== '' ? mb_substr($lateReason, 0, 500) : null,
            'device_id' => $payload['device_id'] ?? null,
            'updated_at' => now(),
        ], 'id = :id', ['id' => $submissionId]);

        if ($isLate) {
            Audit::log(Audit::LATE_SUBMISSION_REQUESTED, [
                'entity_type' => 'report_submission',
                'entity_id' => $submissionId,
                'description' => sprintf('Late daily report for %s (deadline was %s).', $date, $deadlineAt),
                'new' => ['late_reason' => $lateReason, 'submitted_at' => now()],
            ]);

            $supervisor = Database::selectOne(
                'SELECT b.bc_code, u.name FROM bc_supervisors b JOIN users u ON u.id = b.user_id WHERE b.id = :id',
                ['id' => $bcSupervisorId]
            );

            Notify::admins(
                'Late report submission needs approval',
                sprintf(
                    '%s (%s) submitted the %s daily report after the %s deadline.',
                    $supervisor['name'] ?? 'A BCA',
                    $supervisor['bc_code'] ?? '—',
                    format_date($date),
                    format_time($deadlineAt)
                ),
                [
                    'type' => 'approval',
                    'link' => '/admin/deadline/late',
                    'related_type' => 'report_submission',
                    'related_id' => $submissionId,
                ]
            );

            return [
                'status' => $status,
                'is_late' => true,
                'submission_id' => $submissionId,
                'message' => 'Submitted after the deadline. It is pending BC Supervisor approval.',
            ];
        }

        return [
            'status' => $status,
            'is_late' => false,
            'submission_id' => $submissionId,
            'message' => 'Daily report submitted.',
        ];
    }

    /**
     * Approve or reject a late submission. Only BC Supervisor may call this.
     */
    public static function decideLate(int $submissionId, bool $approve, string $remarks = ''): bool
    {
        $submission = Database::selectOne('SELECT * FROM report_submissions WHERE id = :id', ['id' => $submissionId]);

        if ($submission === null || (string) $submission['status'] !== 'late_pending') {
            return false;
        }

        Database::update('report_submissions', [
            'status' => $approve ? 'late_approved' : 'late_rejected',
            'approved_by' => Auth::id(),
            'approved_at' => now(),
            'approval_remarks' => $remarks !== '' ? mb_substr($remarks, 0, 500) : null,
            'updated_at' => now(),
        ], 'id = :id', ['id' => $submissionId]);

        Audit::log($approve ? Audit::LATE_SUBMISSION_APPROVED : Audit::LATE_SUBMISSION_REJECTED, [
            'entity_type' => 'report_submission',
            'entity_id' => $submissionId,
            'description' => sprintf(
                'Late report for %s %s.',
                format_date((string) $submission['report_date']),
                $approve ? 'approved' : 'rejected'
            ),
            'old' => ['status' => 'late_pending'],
            'new' => ['status' => $approve ? 'late_approved' : 'late_rejected', 'remarks' => $remarks],
        ]);

        $userId = (int) Database::scalar(
            'SELECT user_id FROM bc_supervisors WHERE id = :id',
            ['id' => (int) $submission['bc_supervisor_id']]
        );

        if ($userId > 0) {
            Notify::user(
                $userId,
                $approve ? 'Late report approved' : 'Late report rejected',
                sprintf(
                    'Your %s daily report was %s.%s',
                    format_date((string) $submission['report_date']),
                    $approve ? 'approved' : 'rejected',
                    $remarks !== '' ? ' Remarks: ' . $remarks : ''
                ),
                ['type' => 'approval', 'related_type' => 'report_submission', 'related_id' => $submissionId]
            );
        }

        return true;
    }

    /**
     * Live day counts used both for the submission row and the app dashboard.
     *
     * @return array{visits:int, recovery:float, promises:int}
     */
    public static function dayCounts(int $bcSupervisorId, ?string $date = null): array
    {
        $date ??= today();
        $params = ['bc' => $bcSupervisorId, 'date' => $date];

        return [
            'visits' => (int) Database::scalar(
                "SELECT COUNT(*) FROM visits
                  WHERE bc_supervisor_id = :bc AND visit_date = :date AND status <> 'draft'",
                $params
            ),
            'recovery' => (float) Database::scalar(
                "SELECT COALESCE(SUM(amount), 0) FROM recoveries
                  WHERE bc_supervisor_id = :bc AND recovery_date = :date AND status <> 'rejected'",
                $params
            ),
            'promises' => (int) Database::scalar(
                'SELECT COUNT(*) FROM promises WHERE bc_supervisor_id = :bc AND DATE(created_at) = :date',
                $params
            ),
        ];
    }

    /**
     * Send the configured pre-deadline reminders. Intended to be called every
     * few minutes by cron (bin/cron.php).
     *
     * @return int notifications created
     */
    public static function sendReminders(): int
    {
        if (!self::isWorkingDay() || self::hasPassed()) {
            return 0;
        }

        $remainingMinutes = (int) floor(self::secondsRemaining() / 60);
        $sent = 0;

        foreach (self::reminderMinutes() as $threshold) {
            // Fire once inside a five minute window around each threshold.
            if ($remainingMinutes > $threshold || $remainingMinutes <= $threshold - 5) {
                continue;
            }

            $pending = Database::select(
                "SELECT b.id, b.user_id, u.name
                   FROM bc_supervisors b
                   JOIN users u ON u.id = b.user_id
                  WHERE b.status = 'active' AND u.status = 'active'
                    AND NOT EXISTS (
                        SELECT 1 FROM report_submissions s
                         WHERE s.bc_supervisor_id = b.id
                           AND s.report_date = :date
                           AND s.status IN ('submitted','late_approved')
                    )",
                ['date' => today()]
            );

            foreach ($pending as $supervisor) {
                // Skip if this exact reminder already went out today.
                $already = (int) Database::scalar(
                    "SELECT COUNT(*) FROM notifications
                      WHERE user_id = :uid AND type = 'deadline'
                        AND related_type = 'deadline_reminder' AND related_id = :threshold
                        AND DATE(created_at) = :date",
                    ['uid' => (int) $supervisor['user_id'], 'threshold' => $threshold, 'date' => today()]
                );

                if ($already > 0) {
                    continue;
                }

                Notify::user(
                    (int) $supervisor['user_id'],
                    sprintf('%d minutes left to submit your report', $threshold),
                    sprintf('Today\'s report deadline is %s (server time).', format_time(self::at())),
                    ['type' => 'deadline', 'related_type' => 'deadline_reminder', 'related_id' => $threshold]
                );

                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Lock the day: anything still unsubmitted after the deadline becomes
     * `locked` so the app stops offering the normal submit button.
     */
    public static function lockOverdue(?string $date = null): int
    {
        $date ??= today();

        if (!self::hasPassed($date)) {
            return 0;
        }

        return Database::update(
            'report_submissions',
            ['status' => 'locked', 'is_late' => 1, 'updated_at' => now()],
            "report_date = :date AND status = 'pending'",
            ['date' => $date]
        );
    }
}
