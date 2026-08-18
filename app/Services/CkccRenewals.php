<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;

/**
 * CKCC OD-2 renewal tracking — its own work stream and its own report, never
 * merged with customer visit or KRM OTS records.
 */
final class CkccRenewals
{
    public const STATUSES = [
        'pending' => 'Pending',
        'documents_awaited' => 'Documents awaited',
        'submitted' => 'Submitted to branch',
        'renewed' => 'Renewed',
        'rejected' => 'Rejected',
        'not_eligible' => 'Not eligible',
        'closed' => 'Closed',
    ];

    public const DOCUMENT_STATUSES = [
        'complete' => 'Complete',
        'partial' => 'Partial',
        'pending' => 'Pending',
        'not_submitted' => 'Not submitted',
    ];

    public const AVAILABILITY = [
        'available' => 'Available',
        'not_available' => 'Not available',
        'shifted' => 'Shifted',
        'deceased' => 'Deceased',
        'refused' => 'Refused',
    ];

    /**
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

        $status = (string) ($payload['renewal_status'] ?? 'pending');

        if (!array_key_exists($status, self::STATUSES)) {
            throw new HttpException(422, 'Unknown renewal status.');
        }

        $documents = (string) ($payload['documents_status'] ?? 'pending');

        if (!array_key_exists($documents, self::DOCUMENT_STATUSES)) {
            $documents = 'pending';
        }

        $availability = (string) ($payload['customer_availability'] ?? '');
        $availability = array_key_exists($availability, self::AVAILABILITY) ? $availability : null;

        $data = [
            'branch_id' => (int) $account['branch_id'],
            'bc_supervisor_id' => $account['bc_supervisor_id'] === null ? null : (int) $account['bc_supervisor_id'],
            'visit_id' => $visitId,
            'loan_type' => $account['loan_type'],
            'limit_amount' => isset($payload['limit_amount']) && $payload['limit_amount'] !== ''
                ? self::money($payload['limit_amount'])
                : (float) $account['limit_amount'],
            'outstanding' => isset($payload['outstanding']) && $payload['outstanding'] !== ''
                ? self::money($payload['outstanding'])
                : (float) $account['outstanding'],
            'overdue' => isset($payload['overdue']) && $payload['overdue'] !== ''
                ? self::money($payload['overdue'])
                : (float) $account['overdue'],
            'renewal_status' => $status,
            'visit_date' => self::date($payload['visit_date'] ?? null),
            'customer_availability' => $availability,
            'documents_status' => $documents,
            'documents_remarks' => isset($payload['documents_remarks'])
                ? mb_substr((string) $payload['documents_remarks'], 0, 500)
                : null,
            'renewed_on' => $status === 'renewed'
                ? (self::date($payload['renewed_on'] ?? null) ?? today())
                : self::date($payload['renewed_on'] ?? null),
            'remarks' => isset($payload['remarks']) ? mb_substr((string) $payload['remarks'], 0, 500) : null,
            'updated_at' => now(),
        ];

        $existing = Database::selectOne(
            "SELECT * FROM ckcc_renewals
              WHERE loan_account_id = :id AND renewal_status NOT IN ('renewed','closed','rejected','not_eligible')
              ORDER BY id DESC LIMIT 1",
            ['id' => $accountId]
        );

        if ($existing !== null) {
            Database::update('ckcc_renewals', $data, 'id = :id', ['id' => (int) $existing['id']]);
            $id = (int) $existing['id'];

            Audit::logChange(
                'ckcc_renewal_updated',
                'ckcc_renewal',
                $id,
                $existing,
                $data,
                sprintf('CKCC OD-2 renewal updated for account %s.', $account['account_number'])
            );
        } else {
            $id = Database::insert('ckcc_renewals', array_merge($data, [
                'loan_account_id' => $accountId,
                'created_by' => Auth::id(),
                'created_at' => now(),
            ]));

            Audit::log('ckcc_renewal_created', [
                'entity_type' => 'ckcc_renewal',
                'entity_id' => $id,
                'description' => sprintf('CKCC OD-2 renewal opened for account %s.', $account['account_number']),
                'new' => $data,
            ]);
        }

        Database::update(
            'loan_accounts',
            ['loan_category' => 'ckcc_od2', 'updated_at' => now()],
            'id = :id',
            ['id' => $accountId]
        );

        return $id;
    }

    /**
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

        // Derive customer availability from the visit when the app did not send it.
        if (!isset($payload['customer_availability'])) {
            $payload['customer_availability'] = match ((string) $visit['visit_status']) {
                'customer_met', 'family_met' => 'available',
                'shifted' => 'shifted',
                'deceased' => 'deceased',
                'refused' => 'refused',
                default => 'not_available',
            };
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
