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

    /** Section 4, "Applicable Scheme". */
    public const SCHEMES = [
        'krm_ots' => 'KRM OTS',
        'general_ots' => 'General OTS',
        'other' => 'Other',
    ];

    /** Section 4, "Customer Response". */
    public const CUSTOMER_RESPONSES = [
        'agreed' => 'Agreed for OTS',
        'requested_time' => 'Requested time',
        'financial_difficulty' => 'Financial difficulty',
        'refused' => 'Refused OTS',
        'not_eligible' => 'Not eligible',
    ];

    /** Section 9, the KRM OTS recommendation. */
    public const RECOMMENDATIONS = [
        'proposal_recommended' => 'OTS proposal recommended',
        'followup_required' => 'Follow-up required',
        'customer_refused' => 'Customer refused',
        'not_eligible' => 'Not eligible',
    ];

    /** Section 13, "Final Report Status". */
    public const FINAL_STATUSES = [
        'customer_contacted' => 'Customer contacted',
        'customer_verified' => 'Customer verified',
        'ots_accepted' => 'OTS accepted',
        'ots_rejected' => 'OTS rejected',
        'initial_deposit_received' => 'Initial deposit received',
        'ots_closed' => 'OTS closed',
        'followup_required' => 'Follow-up required',
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

        $scheme = self::option($payload['scheme'] ?? null, self::SCHEMES) ?? 'krm_ots';

        $data = [
            'branch_id' => (int) $account['branch_id'],
            'bc_supervisor_id' => $account['bc_supervisor_id'] === null ? null : (int) $account['bc_supervisor_id'],
            'visit_id' => $visitId,
            'ots_eligible' => self::tristate($payload['ots_eligible'] ?? null),
            'scheme' => $scheme,
            'scheme_other' => $scheme === 'other' && isset($payload['scheme_other'])
                ? mb_substr(trim((string) $payload['scheme_other']), 0, 120)
                : null,
            'outstanding' => $outstanding,
            'ots_amount' => $otsAmount,
            'borrower_share' => self::optionalMoney($payload['borrower_share'] ?? null),
            'initial_deposit_required' => self::optionalMoney($payload['initial_deposit_required'] ?? null),
            'sanctioned_amount' => self::optionalMoney($payload['sanctioned_amount'] ?? null),
            'paid_amount' => self::money($payload['paid_amount'] ?? 0),
            'customer_response' => self::option($payload['customer_response'] ?? null, self::CUSTOMER_RESPONSES),
            'ots_status' => $status,
            'visit_date' => self::date($payload['visit_date'] ?? null),
            'promise_date' => self::date($payload['promise_date'] ?? null),
            'recommendation' => self::option($payload['recommendation'] ?? null, self::RECOMMENDATIONS),
            'final_status' => self::option($payload['final_status'] ?? null, self::FINAL_STATUSES),
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

        // Section 4 of the report is filled in on the form, so the answers are
        // read back from the submitted values. An explicit payload still wins,
        // which is what lets the web screen and the API set these directly.
        $payload = array_merge(self::fromFormValues($visitId), $payload);

        $payload['visit_date'] = $payload['visit_date'] ?? $visit['visit_date'];
        $payload['remarks'] = $payload['remarks'] ?? $visit['remarks'];

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

        // A visit of this type always opens a case: the report itself is the
        // record, even when the borrower agreed to nothing.
        $hasSubstance = ($payload['ots_amount'] ?? 0) > 0
            || isset($payload['ots_status'])
            || isset($payload['ots_eligible'])
            || isset($payload['customer_response'])
            || isset($payload['final_status']);

        if (!$hasSubstance) {
            return;
        }

        self::save((int) $visit['loan_account_id'], $payload, $visitId);
    }

    /**
     * Map the report form's section 4, 9 and 13 answers onto the payload keys
     * this service understands. The form keys are prefixed (`ots_recommendation`)
     * to keep them distinct from the shared fields on the same form.
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
            'ots_eligible' => 'ots_eligible',
            'scheme' => 'scheme',
            'scheme_other' => 'scheme_other',
            'ots_amount' => 'ots_amount',
            'borrower_share' => 'borrower_share',
            'initial_deposit_required' => 'initial_deposit_required',
            'customer_response' => 'customer_response',
            'promise_date' => 'promise_date',
            'ots_recommendation' => 'recommendation',
            'ots_final_status' => 'final_status',
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

    private static function optionalMoney(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : self::money($value);
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
     * human label the report form shows ("Agreed for OTS" -> `agreed`).
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

    private static function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $timestamp = strtotime((string) $value);

        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }
}
