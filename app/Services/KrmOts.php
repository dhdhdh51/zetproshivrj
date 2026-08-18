<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;

/**
 * KRM OTS (One Time Settlement) tracking — a work stream of its own, kept
 * separate from customer visit records and reported on separately.
 */
final class KrmOts
{
    public const STATUSES = [
        'proposed' => 'Proposed',
        'under_review' => 'Under review',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'partly_paid' => 'Partly paid',
        'paid' => 'Paid',
        'closed' => 'Closed',
        'cancelled' => 'Cancelled',
    ];

    /**
     * Create or update the OTS case for an account.
     *
     * @param array<string, mixed> $payload
     */
    public static function save(int $accountId, array $payload, ?int $visitId = null): int
    {
        $account = Database::selectOne(
            'SELECT a.*, x.bc_supervisor_id
               FROM loan_accounts a
          LEFT JOIN account_assignments x ON x.loan_account_id = a.id AND x.is_active = 1
              WHERE a.id = :id',
            ['id' => $accountId]
        );

        if ($account === null) {
            throw new HttpException(404, 'Loan account not found.');
        }

        $status = (string) ($payload['ots_status'] ?? 'proposed');

        if (!array_key_exists($status, self::STATUSES)) {
            throw new HttpException(422, 'Unknown OTS status.');
        }

        $otsAmount = self::money($payload['ots_amount'] ?? 0);
        $outstanding = isset($payload['outstanding'])
            ? self::money($payload['outstanding'])
            : (float) $account['outstanding'];

        if ($otsAmount > 0 && $outstanding > 0 && $otsAmount > $outstanding) {
            throw new HttpException(422, 'The OTS amount cannot exceed the outstanding balance.');
        }

        $data = [
            'branch_id' => (int) $account['branch_id'],
            'bc_supervisor_id' => $account['bc_supervisor_id'] === null ? null : (int) $account['bc_supervisor_id'],
            'visit_id' => $visitId,
            'outstanding' => $outstanding,
            'ots_amount' => $otsAmount,
            'sanctioned_amount' => isset($payload['sanctioned_amount']) && $payload['sanctioned_amount'] !== ''
                ? self::money($payload['sanctioned_amount'])
                : null,
            'paid_amount' => self::money($payload['paid_amount'] ?? 0),
            'ots_status' => $status,
            'visit_date' => self::date($payload['visit_date'] ?? null),
            'promise_date' => self::date($payload['promise_date'] ?? null),
            'remarks' => isset($payload['remarks']) ? mb_substr((string) $payload['remarks'], 0, 500) : null,
            'updated_at' => now(),
        ];

        $existing = Database::selectOne(
            "SELECT * FROM krm_ots_cases
              WHERE loan_account_id = :id AND ots_status NOT IN ('closed','cancelled','rejected')
              ORDER BY id DESC LIMIT 1",
            ['id' => $accountId]
        );

        if ($existing !== null) {
            Database::update('krm_ots_cases', $data, 'id = :id', ['id' => (int) $existing['id']]);
            $id = (int) $existing['id'];

            Audit::logChange(
                'krm_ots_updated',
                'krm_ots_case',
                $id,
                $existing,
                $data,
                sprintf('KRM OTS case updated for account %s.', $account['account_number'])
            );
        } else {
            $id = Database::insert('krm_ots_cases', array_merge($data, [
                'loan_account_id' => $accountId,
                'created_by' => Auth::id(),
                'created_at' => now(),
            ]));

            Audit::log('krm_ots_created', [
                'entity_type' => 'krm_ots_case',
                'entity_id' => $id,
                'description' => sprintf(
                    'KRM OTS case opened for account %s at %s.',
                    $account['account_number'],
                    money($otsAmount)
                ),
                'new' => $data,
            ]);
        }

        // Tag the account so it appears in the OTS work stream and reports.
        Database::update(
            'loan_accounts',
            ['loan_category' => 'krm_ots', 'updated_at' => now()],
            'id = :id',
            ['id' => $accountId]
        );

        (new Visits())->refreshAccount($accountId);

        return $id;
    }

    /**
     * Called when a visit of type krm_ots is submitted.
     *
     * @param array<string, mixed>|mixed $payload
     */
    public static function syncFromVisit(int $visitId, mixed $payload): void
    {
        $visit = Database::selectOne('SELECT * FROM visits WHERE id = :id', ['id' => $visitId]);

        if ($visit === null) {
            return;
        }

        $payload = is_array($payload) ? $payload : [];
        $payload['visit_date'] = $payload['visit_date'] ?? $visit['visit_date'];

        // Fall back to the promise captured in the visit form.
        if (!isset($payload['ots_amount'])) {
            $promise = Database::selectOne(
                'SELECT promise_amount, promise_date FROM promises WHERE visit_id = :id ORDER BY id DESC LIMIT 1',
                ['id' => $visitId]
            );

            if ($promise !== null) {
                $payload['ots_amount'] = $promise['promise_amount'];
                $payload['promise_date'] = $payload['promise_date'] ?? $promise['promise_date'];
            }
        }

        if (($payload['ots_amount'] ?? 0) <= 0 && !isset($payload['ots_status'])) {
            // Nothing concrete to record yet.
            return;
        }

        self::save((int) $visit['loan_account_id'], $payload, $visitId);
    }

    private static function money(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        return round((float) str_replace(',', '', (string) $value), 2);
    }

    private static function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $timestamp = strtotime((string) $value);

        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }
}
