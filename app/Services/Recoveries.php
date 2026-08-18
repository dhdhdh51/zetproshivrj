<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;

/**
 * Money collected in the field. Every row is idempotent on its client uuid and
 * keeps its full history — recoveries are never edited in place, they are
 * verified or rejected by an Admin/Supervisor.
 */
final class Recoveries
{
    /**
     * @param array<string, mixed> $payload amount, recovery_date, payment_mode,
     *                                      receipt_number, remarks, uuid
     */
    public static function record(
        int $accountId,
        int $branchId,
        ?int $bcSupervisorId,
        array $payload,
        ?int $visitId = null
    ): int {
        $uuid = self::uuid($payload['uuid'] ?? null);

        $existing = Database::selectOne('SELECT id FROM recoveries WHERE uuid = :uuid', ['uuid' => $uuid]);

        if ($existing !== null) {
            return (int) $existing['id'];
        }

        $amount = self::amount($payload['amount'] ?? null);
        $date = self::date($payload['recovery_date'] ?? null);
        $mode = self::mode($payload['payment_mode'] ?? null);
        $receipt = isset($payload['receipt_number']) && $payload['receipt_number'] !== ''
            ? mb_substr(trim((string) $payload['receipt_number']), 0, 80)
            : null;

        // A receipt number, when given, must be unique: it is the audit link back
        // to the branch's own books.
        if ($receipt !== null) {
            $clash = Database::selectOne(
                'SELECT id FROM recoveries WHERE receipt_number = :r LIMIT 1',
                ['r' => $receipt]
            );

            if ($clash !== null) {
                throw new HttpException(422, sprintf('Receipt number %s has already been recorded.', $receipt));
            }
        }

        $id = Database::insert('recoveries', [
            'uuid' => $uuid,
            'loan_account_id' => $accountId,
            'visit_id' => $visitId,
            'bc_supervisor_id' => $bcSupervisorId,
            'branch_id' => $branchId,
            'amount' => $amount,
            'recovery_date' => $date,
            'payment_mode' => $mode,
            'receipt_number' => $receipt,
            'remarks' => isset($payload['remarks']) ? mb_substr((string) $payload['remarks'], 0, 500) : null,
            'status' => 'recorded',
            'created_by' => Auth::id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Any pending promise on this account is settled by the payment.
        Promises::applyRecovery($accountId, $amount);

        (new Visits())->refreshAccount($accountId);

        $account = Database::selectOne(
            'SELECT account_number, borrower_name FROM loan_accounts WHERE id = :id',
            ['id' => $accountId]
        );

        Audit::log(Audit::RECOVERY_RECORDED, [
            'entity_type' => 'recovery',
            'entity_id' => $id,
            'description' => sprintf(
                '%s recovered from %s (%s) by %s%s.',
                money($amount),
                $account['borrower_name'] ?? '',
                $account['account_number'] ?? '',
                $mode,
                $receipt !== null ? ', receipt ' . $receipt : ''
            ),
            'new' => ['amount' => $amount, 'mode' => $mode, 'receipt' => $receipt, 'date' => $date],
        ]);

        Notify::branch(
            $branchId,
            'Recovery recorded',
            sprintf(
                '%s collected against %s (%s).',
                money($amount),
                $account['account_number'] ?? '',
                $account['borrower_name'] ?? ''
            ),
            ['type' => 'info', 'related_type' => 'recovery', 'related_id' => $id]
        );

        return $id;
    }

    public static function verify(int $recoveryId, bool $approve, string $remarks = ''): void
    {
        $recovery = Database::selectOne('SELECT * FROM recoveries WHERE id = :id', ['id' => $recoveryId]);

        if ($recovery === null) {
            throw new HttpException(404, 'Recovery entry not found.');
        }

        Database::update('recoveries', [
            'status' => $approve ? 'verified' : 'rejected',
            'verified_by' => Auth::id(),
            'verified_at' => now(),
            'remarks' => $remarks !== ''
                ? mb_substr(trim(((string) $recovery['remarks']) . ' | ' . $remarks, ' |'), 0, 500)
                : $recovery['remarks'],
            'updated_at' => now(),
        ], 'id = :id', ['id' => $recoveryId]);

        (new Visits())->refreshAccount((int) $recovery['loan_account_id']);

        Audit::log(Audit::RECOVERY_VERIFIED, [
            'entity_type' => 'recovery',
            'entity_id' => $recoveryId,
            'description' => sprintf(
                '%s of %s %s.',
                $approve ? 'Verified' : 'Rejected',
                money((float) $recovery['amount']),
                $remarks !== '' ? '— ' . $remarks : ''
            ),
            'old' => ['status' => $recovery['status']],
            'new' => ['status' => $approve ? 'verified' : 'rejected'],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Shared validation helpers                                          */
    /* ------------------------------------------------------------------ */

    public static function uuid(mixed $value): string
    {
        $uuid = strtolower(trim((string) ($value ?? '')));

        if ($uuid === '') {
            return uuid4();
        }

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid) !== 1) {
            throw new HttpException(422, 'The supplied record id is not a valid UUID.');
        }

        return $uuid;
    }

    private static function amount(mixed $value): float
    {
        $amount = is_numeric($value) ? (float) $value : (float) str_replace(',', '', (string) $value);

        if ($amount <= 0) {
            throw new HttpException(422, 'The recovery amount must be greater than zero.');
        }

        if ($amount > 99999999999.99) {
            throw new HttpException(422, 'That recovery amount is out of range.');
        }

        return round($amount, 2);
    }

    private static function date(mixed $value): string
    {
        if ($value === null || $value === '') {
            return today();
        }

        $timestamp = strtotime((string) $value);

        if ($timestamp === false) {
            throw new HttpException(422, 'The recovery date is not valid.');
        }

        if ($timestamp > time() + 86400) {
            throw new HttpException(422, 'A recovery cannot be dated in the future.');
        }

        return date('Y-m-d', $timestamp);
    }

    private static function mode(mixed $value): string
    {
        $mode = trim((string) ($value ?? ''));
        $allowed = payment_modes();

        foreach ($allowed as $candidate) {
            if (strcasecmp($candidate, $mode) === 0) {
                return $candidate;
            }
        }

        return $allowed[0] ?? 'Cash';
    }
}
