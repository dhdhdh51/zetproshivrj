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

    /** Section 5, "Renewal Due". */
    public const DUE_BUCKETS = [
        'within_30_days' => 'Within 30 days',
        'within_15_days' => 'Within 15 days',
        'within_7_days' => 'Within 7 days',
        'overdue' => 'Overdue',
    ];

    /** Section 5, "KYC Status". */
    public const KYC_STATUSES = [
        'complete' => 'Complete',
        'pending' => 'Pending',
    ];

    /** Section 5, "Aadhaar Authentication". */
    public const AUTHENTICATION = [
        'completed' => 'Completed',
        'pending' => 'Pending',
    ];

    /** Section 9, the CKCC renewal recommendation. */
    public const RECOMMENDATIONS = [
        'renew_immediately' => 'Renewal immediately recommended',
        'documents_complete' => 'Documents complete',
        'documents_pending' => 'Pending documents',
        'customer_not_interested' => 'Customer not interested',
        'branch_followup_required' => 'Branch follow-up required',
    ];

    /** Section 13, "Final Report Status". */
    public const FINAL_STATUSES = [
        'customer_contacted' => 'Customer contacted',
        'customer_verified' => 'Customer verified',
        'documents_collected' => 'Documents collected',
        'renewal_submitted' => 'Renewal submitted',
        'renewal_approved' => 'Renewal approved',
        'pending_at_branch' => 'Pending at branch',
        'became_npa' => 'Account became NPA',
        'followup_required' => 'Follow-up required',
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
            // Section 5 of the report.
            'renewal_eligible' => self::tristate($payload['renewal_eligible'] ?? null),
            'renewal_due_bucket' => self::option($payload['renewal_due_bucket'] ?? null, self::DUE_BUCKETS),
            'renewal_due_date' => self::date($payload['renewal_due_date'] ?? null),
            'expected_npa_date' => self::date($payload['expected_npa_date'] ?? null)
                ?? self::date($account['npa_date'] ?? null),
            'days_remaining' => self::days($payload['days_remaining'] ?? null, self::date($payload['renewal_due_date'] ?? null)),
            'kyc_status' => self::option($payload['kyc_status'] ?? null, self::KYC_STATUSES),
            'aadhaar_seeded' => self::tristate($payload['aadhaar_seeded'] ?? null),
            'mobile_linked' => self::tristate($payload['mobile_linked'] ?? null),
            'aadhaar_authentication' => self::option($payload['aadhaar_authentication'] ?? null, self::AUTHENTICATION),
            'renewal_consent' => self::tristate($payload['renewal_consent'] ?? null),
            'renewal_form_signed' => self::tristate($payload['renewal_form_signed'] ?? null),
            'biometrics_completed' => self::tristate($payload['biometrics_completed'] ?? null),
            'renewal_status' => $status,
            'visit_date' => self::date($payload['visit_date'] ?? null),
            'customer_availability' => $availability,
            'documents_status' => $documents,
            'recommendation' => self::option($payload['recommendation'] ?? null, self::RECOMMENDATIONS),
            'final_status' => self::option($payload['final_status'] ?? null, self::FINAL_STATUSES),
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

        // Section 5 of the report is filled in on the form, so the answers are
        // read back from the submitted values. An explicit payload still wins.
        $payload = array_merge(self::fromFormValues($visitId), $payload);

        $payload['visit_date'] = $payload['visit_date'] ?? $visit['visit_date'];
        $payload['remarks'] = $payload['remarks'] ?? $visit['remarks'];

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

    /**
     * Map the report form's section 5, 9 and 13 answers onto the payload keys
     * this service understands.
     *
     * @return array<string, mixed>
     */
    private static function fromFormValues(int $visitId): array
    {
        $values = [];

        foreach (Forms::values(Forms::KIND_VISIT, $visitId) as $row) {
            $value = trim((string) $row['value']);

            if ($value !== '') {
                $values[(string) $row['field_key']] = $value;
            }
        }

        $map = [
            'renewal_eligible' => 'renewal_eligible',
            'renewal_due_bucket' => 'renewal_due_bucket',
            'renewal_due_date' => 'renewal_due_date',
            'expected_npa_date' => 'expected_npa_date',
            'days_remaining' => 'days_remaining',
            'kyc_status' => 'kyc_status',
            'aadhaar_seeded' => 'aadhaar_seeded',
            'mobile_linked' => 'mobile_linked',
            'aadhaar_authentication' => 'aadhaar_authentication',
            'renewal_consent' => 'renewal_consent',
            'renewal_form_signed' => 'renewal_form_signed',
            'biometrics_completed' => 'biometrics_completed',
            'renewal_recommendation' => 'recommendation',
            'renewal_final_status' => 'final_status',
        ];

        $payload = [];

        foreach ($map as $formKey => $payloadKey) {
            if (isset($values[$formKey])) {
                $payload[$payloadKey] = $values[$formKey];
            }
        }

        return $payload;
    }

    private static function money(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        return round((float) str_replace(',', '', (string) $value), 2);
    }

    private static function tristate(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = strtolower(trim((string) $value));

        if (in_array($value, ['yes', '1', 'true'], true)) {
            return 1;
        }

        return in_array($value, ['no', '0', 'false'], true) ? 0 : null;
    }

    /**
     * Resolve a value that may arrive either as the stored enum key or as the
     * human label the report form shows ("Within 30 Days" -> `within_30_days`).
     *
     * @param array<string, string> $options
     */
    private static function option(mixed $value, array $options): ?string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return null;
        }

        if (array_key_exists($value, $options)) {
            return $value;
        }

        foreach ($options as $key => $label) {
            if (strcasecmp($label, $value) === 0) {
                return $key;
            }
        }

        return null;
    }

    /**
     * "Days Remaining". Uses what the supervisor recorded, and otherwise counts
     * from today to the renewal due date.
     */
    private static function days(mixed $value, ?string $dueDate): ?int
    {
        if ($value !== null && $value !== '' && is_numeric((string) $value)) {
            return (int) $value;
        }

        if ($dueDate === null) {
            return null;
        }

        $due = strtotime($dueDate . ' 00:00:00');
        $today = strtotime(today() . ' 00:00:00');

        return $due === false || $today === false ? null : (int) floor(($due - $today) / 86400);
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
