<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;

/**
 * Promise to pay (PTP) register.
 *
 * A promise moves pending → kept / partially_kept / broken / cancelled. "Broken"
 * is decided by the passage of time, so it is derived by a scheduled sweep rather
 * than needing anyone to remember to update it.
 */
final class Promises
{
    /**
     * @param array<string, mixed> $payload promise_amount, promise_date,
     *                                      followup_date, remarks, uuid
     */
    public static function record(
        int $accountId,
        int $branchId,
        ?int $bcSupervisorId,
        array $payload,
        ?int $visitId = null
    ): int {
        $uuid = Recoveries::uuid($payload['uuid'] ?? null);

        $existing = Database::selectOne('SELECT id FROM promises WHERE uuid = :uuid', ['uuid' => $uuid]);

        if ($existing !== null) {
            return (int) $existing['id'];
        }

        $amount = (float) str_replace(',', '', (string) ($payload['promise_amount'] ?? 0));

        if ($amount <= 0) {
            throw new HttpException(422, 'The promise amount must be greater than zero.');
        }

        $promiseDate = $payload['promise_date'] ?? null;
        $timestamp = $promiseDate === null || $promiseDate === '' ? false : strtotime((string) $promiseDate);

        if ($timestamp === false) {
            throw new HttpException(422, 'A valid promise date is required.');
        }

        $followupDate = null;

        if (!empty($payload['followup_date'])) {
            $followupTimestamp = strtotime((string) $payload['followup_date']);
            $followupDate = $followupTimestamp === false ? null : date('Y-m-d', $followupTimestamp);
        }

        $id = Database::insert('promises', [
            'uuid' => $uuid,
            'loan_account_id' => $accountId,
            'visit_id' => $visitId,
            'bc_supervisor_id' => $bcSupervisorId,
            'branch_id' => $branchId,
            'promise_amount' => round($amount, 2),
            'promise_date' => date('Y-m-d', $timestamp),
            'followup_date' => $followupDate,
            'remarks' => isset($payload['remarks']) ? mb_substr((string) $payload['remarks'], 0, 500) : null,
            'status' => 'pending',
            'created_by' => Auth::id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new Visits())->refreshAccount($accountId);

        $account = Database::selectOne(
            'SELECT account_number, borrower_name FROM loan_accounts WHERE id = :id',
            ['id' => $accountId]
        );

        Audit::log(Audit::PROMISE_RECORDED, [
            'entity_type' => 'promise',
            'entity_id' => $id,
            'description' => sprintf(
                'PTP %s by %s for %s (%s).',
                money($amount),
                format_date(date('Y-m-d', $timestamp)),
                $account['borrower_name'] ?? '',
                $account['account_number'] ?? ''
            ),
            'new' => ['amount' => $amount, 'promise_date' => date('Y-m-d', $timestamp)],
        ]);

        return $id;
    }

    /**
     * Manually set the outcome of a promise.
     */
    public static function updateStatus(int $promiseId, string $status, string $remarks = '', ?float $keptAmount = null): void
    {
        $allowed = ['pending', 'kept', 'partially_kept', 'broken', 'cancelled'];

        if (!in_array($status, $allowed, true)) {
            throw new HttpException(422, 'Unknown promise status.');
        }

        $promise = Database::selectOne('SELECT * FROM promises WHERE id = :id', ['id' => $promiseId]);

        if ($promise === null) {
            throw new HttpException(404, 'Promise not found.');
        }

        Database::update('promises', [
            'status' => $status,
            'kept_amount' => $keptAmount ?? (float) $promise['kept_amount'],
            'remarks' => $remarks !== ''
                ? mb_substr(trim(((string) $promise['remarks']) . ' | ' . $remarks, ' |'), 0, 500)
                : $promise['remarks'],
            'closed_at' => $status === 'pending' ? null : now(),
            'closed_by' => $status === 'pending' ? null : Auth::id(),
            'updated_at' => now(),
        ], 'id = :id', ['id' => $promiseId]);

        (new Visits())->refreshAccount((int) $promise['loan_account_id']);

        Audit::log(Audit::PROMISE_UPDATED, [
            'entity_type' => 'promise',
            'entity_id' => $promiseId,
            'description' => sprintf('Promise marked %s.%s', enum_label($status), $remarks !== '' ? ' ' . $remarks : ''),
            'old' => ['status' => $promise['status']],
            'new' => ['status' => $status, 'kept_amount' => $keptAmount],
        ]);
    }

    /**
     * When money arrives, close out the promises it satisfies.
     */
    public static function applyRecovery(int $accountId, float $amount): void
    {
        $pending = Database::select(
            "SELECT * FROM promises
              WHERE loan_account_id = :id AND status = 'pending'
              ORDER BY promise_date ASC",
            ['id' => $accountId]
        );

        $remaining = $amount;

        foreach ($pending as $promise) {
            if ($remaining <= 0) {
                break;
            }

            $promised = (float) $promise['promise_amount'];
            $alreadyKept = (float) $promise['kept_amount'];
            $applied = min($remaining, max(0, $promised - $alreadyKept));
            $keptTotal = $alreadyKept + $applied;
            $remaining -= $applied;

            Database::update('promises', [
                'kept_amount' => round($keptTotal, 2),
                'status' => $keptTotal + 0.01 >= $promised ? 'kept' : 'partially_kept',
                'closed_at' => $keptTotal + 0.01 >= $promised ? now() : null,
                'updated_at' => now(),
            ], 'id = :id', ['id' => (int) $promise['id']]);
        }
    }

    /**
     * Mark promises whose date has passed without payment as broken, and notify
     * the supervisor. Intended for the daily cron.
     *
     * @return int promises updated
     */
    public static function sweepOverdue(): int
    {
        $overdue = Database::select(
            "SELECT p.*, s.user_id, a.account_number, a.borrower_name
               FROM promises p
               LEFT JOIN bc_supervisors s ON s.id = p.bc_supervisor_id
               JOIN loan_accounts a ON a.id = p.loan_account_id
              WHERE p.status = 'pending' AND p.promise_date < CURDATE()"
        );

        $count = 0;

        foreach ($overdue as $promise) {
            Database::update('promises', [
                'status' => (float) $promise['kept_amount'] > 0 ? 'partially_kept' : 'broken',
                'closed_at' => now(),
                'updated_at' => now(),
            ], 'id = :id', ['id' => (int) $promise['id']]);

            if ($promise['user_id'] !== null) {
                Notify::user(
                    (int) $promise['user_id'],
                    'Promise not kept',
                    sprintf(
                        '%s promised %s by %s for account %s. Please follow up.',
                        $promise['borrower_name'],
                        money((float) $promise['promise_amount']),
                        format_date((string) $promise['promise_date']),
                        $promise['account_number']
                    ),
                    ['type' => 'warning', 'related_type' => 'promise', 'related_id' => (int) $promise['id']]
                );
            }

            $count++;
        }

        return $count;
    }
}
