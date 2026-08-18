<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;

/**
 * Follow-up actions queued from a visit, a broken promise, or by an
 * Admin/Supervisor.
 */
final class Followups
{
    /**
     * @param array<string, mixed> $payload followup_date, action, notes, uuid
     */
    public static function record(
        int $accountId,
        int $branchId,
        ?int $bcSupervisorId,
        array $payload,
        ?int $visitId = null,
        ?int $promiseId = null
    ): int {
        $uuid = Recoveries::uuid($payload['uuid'] ?? null);

        $existing = Database::selectOne('SELECT id FROM followups WHERE uuid = :uuid', ['uuid' => $uuid]);

        if ($existing !== null) {
            return (int) $existing['id'];
        }

        $date = $payload['followup_date'] ?? null;
        $timestamp = $date === null || $date === '' ? strtotime('+3 days') : strtotime((string) $date);

        if ($timestamp === false) {
            throw new HttpException(422, 'The follow-up date is not valid.');
        }

        $action = (string) ($payload['action'] ?? 'visit');
        $action = in_array($action, ['call', 'visit', 'notice', 'legal', 'other'], true) ? $action : 'visit';

        $id = Database::insert('followups', [
            'uuid' => $uuid,
            'loan_account_id' => $accountId,
            'visit_id' => $visitId,
            'promise_id' => $promiseId,
            'bc_supervisor_id' => $bcSupervisorId,
            'branch_id' => $branchId,
            'followup_date' => date('Y-m-d', $timestamp),
            'action' => $action,
            'notes' => isset($payload['notes']) ? mb_substr((string) $payload['notes'], 0, 500) : null,
            'status' => 'pending',
            'created_by' => Auth::id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Audit::log(Audit::FOLLOWUP_RECORDED, [
            'entity_type' => 'followup',
            'entity_id' => $id,
            'description' => sprintf('Follow-up (%s) scheduled for %s.', $action, format_date(date('Y-m-d', $timestamp))),
            'new' => ['action' => $action, 'followup_date' => date('Y-m-d', $timestamp)],
        ]);

        return $id;
    }

    public static function complete(int $followupId, string $notes = ''): void
    {
        $followup = Database::selectOne('SELECT * FROM followups WHERE id = :id', ['id' => $followupId]);

        if ($followup === null) {
            throw new HttpException(404, 'Follow-up not found.');
        }

        Database::update('followups', [
            'status' => 'done',
            'completed_at' => now(),
            'notes' => $notes !== ''
                ? mb_substr(trim(((string) $followup['notes']) . ' | ' . $notes, ' |'), 0, 500)
                : $followup['notes'],
            'updated_at' => now(),
        ], 'id = :id', ['id' => $followupId]);
    }

    public static function cancel(int $followupId, string $reason = ''): void
    {
        Database::update('followups', [
            'status' => 'cancelled',
            'completed_at' => now(),
            'notes' => $reason !== '' ? mb_substr($reason, 0, 500) : null,
            'updated_at' => now(),
        ], 'id = :id', ['id' => $followupId]);
    }

    /**
     * Remind supervisors of what is due today. For the daily cron.
     */
    public static function remindDue(): int
    {
        $due = Database::select(
            "SELECT f.id, f.action, f.followup_date, s.user_id, a.account_number, a.borrower_name
               FROM followups f
               JOIN bc_supervisors s ON s.id = f.bc_supervisor_id
               JOIN loan_accounts a ON a.id = f.loan_account_id
              WHERE f.status = 'pending' AND f.followup_date = CURDATE()"
        );

        $sent = 0;

        foreach ($due as $followup) {
            $already = (int) Database::scalar(
                "SELECT COUNT(*) FROM notifications
                  WHERE user_id = :uid AND related_type = 'followup' AND related_id = :id
                    AND DATE(created_at) = CURDATE()",
                ['uid' => (int) $followup['user_id'], 'id' => (int) $followup['id']]
            );

            if ($already > 0) {
                continue;
            }

            Notify::user(
                (int) $followup['user_id'],
                'Follow-up due today',
                sprintf(
                    '%s: %s (%s).',
                    enum_label((string) $followup['action']),
                    $followup['borrower_name'],
                    $followup['account_number']
                ),
                ['type' => 'info', 'related_type' => 'followup', 'related_id' => (int) $followup['id']]
            );

            $sent++;
        }

        return $sent;
    }
}
