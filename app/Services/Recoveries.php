<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;

/**
 * Repayments the borrower made to the bank, recorded by the BCA who
 * followed them up.
 *
 * Not money the agent took, and no longer something the app records at all. The field
 * work is the visit: the borrower pays the bank directly and this system's job is to
 * report on the follow-up, not to handle a rupee of it.
 *
 * What still writes here is a build of the app older than that policy, flushing a
 * payment it had already queued offline. Those are accepted and stored as reported —
 * see FieldController::recovery for why refusing them would lose the record rather
 * than prevent the payment. Rows already in this table stay: they are history, and the
 * panel reports read them.
 *
 * Every row is idempotent on its client uuid and keeps its full history: recoveries
 * are never edited in place, they are verified or rejected by an BC Supervisor.
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
                '%s paid against %s (%s).',
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

    /**
     * The payment mode, recorded as reported.
     *
     * A mode this system does not offer is kept verbatim rather than replaced. The
     * fallback used to be the first entry of the allowed list, which was 'Cash' — so
     * any mode the server did not recognise, including a typo or a value from an app
     * version older than this one, was silently filed as a cash collection that never
     * happened. In a repayment record that is not a tidy-up, it is an invention.
     *
     * An older app still installed in the field can therefore report the cash mode it
     * used to offer, and it is stored as cash: what that phone said is the honest
     * record, and a supervisor can see it and follow it up. What the server must not do
     * is put words in the report's mouth in either direction.
     */
    private static function mode(mixed $value): string
    {
        $mode = trim((string) ($value ?? ''));

        foreach (payment_modes() as $candidate) {
            if (strcasecmp($candidate, $mode) === 0) {
                return $candidate;
            }
        }

        return $mode === '' ? 'Other' : mb_substr($mode, 0, 40);
    }
}
