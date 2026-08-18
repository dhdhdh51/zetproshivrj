<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Settings;

/**
 * TYPE A — BC Supervisor customer recovery visits.
 *
 * The flow is deliberately three steps so a field device on a bad connection can
 * make progress and resume:
 *
 *   1. start()        the visit row + mandatory GPS point (idempotent on uuid)
 *   2. photos         uploaded one at a time (Photos service, idempotent on hash)
 *   3. submit()       validates the form and evidence, then applies side effects
 *
 * A replayed step is never an error: the same client uuid always resolves to the
 * same visit, which is what makes the offline queue safe to retry.
 */
final class Visits
{
    /** How far back a queued offline visit may be dated. */
    private const MAX_BACKDATE_DAYS = 7;

    /* ------------------------------------------------------------------ */
    /* Step 1 — start                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $payload
     * @return array{visit: array<string, mixed>, created: bool, gps: array<string, mixed>}
     */
    public function start(int $bcSupervisorId, array $payload, ?int $deviceId = null, ?int $syncBatchId = null): array
    {
        $uuid = $this->uuid($payload['uuid'] ?? null);

        $existing = Database::selectOne('SELECT * FROM visits WHERE uuid = :uuid', ['uuid' => $uuid]);

        if ($existing !== null) {
            if ((int) $existing['bc_supervisor_id'] !== $bcSupervisorId) {
                throw new HttpException(403, 'That visit belongs to another BC Supervisor.');
            }

            return ['visit' => $existing, 'created' => false, 'gps' => []];
        }

        $accountId = (int) ($payload['loan_account_id'] ?? 0);
        $account = $this->assignedAccount($accountId, $bcSupervisorId);

        $visitDate = $this->visitDate($payload['visit_date'] ?? null);
        $visitType = $this->visitType($payload['visit_type'] ?? null, $account);

        $form = Forms::defaultForm(Forms::KIND_VISIT, $visitType);

        // GPS is mandatory to start a visit: without a validated point the visit
        // has no evidentiary value.
        $gpsPayload = is_array($payload['gps'] ?? null) ? $payload['gps'] : [];

        if ($gpsPayload === []) {
            throw new HttpException(422, 'Location is required to start a visit. Enable GPS and try again.');
        }

        $gpsCheck = Gps::validate($gpsPayload, (int) $account['branch_id']);

        if (!$gpsCheck['valid']) {
            throw new HttpException(
                422,
                'The captured location was rejected: ' . ($gpsCheck['note'] !== '' ? $gpsCheck['note'] : 'invalid coordinates.')
            );
        }

        $startedAt = Gps::normaliseTimestamp($payload['started_at'] ?? null) ?? now();

        $visitId = Database::insert('visits', [
            'uuid' => $uuid,
            'loan_account_id' => (int) $account['id'],
            'bc_supervisor_id' => $bcSupervisorId,
            'branch_id' => (int) $account['branch_id'],
            'form_id' => $form === null ? null : (int) $form['id'],
            'visit_type' => $visitType,
            'visit_date' => $visitDate,
            'started_at' => $startedAt,
            'server_received_at' => now(),
            'client_created_at' => Gps::normaliseTimestamp($payload['client_created_at'] ?? null),
            'status' => 'draft',
            'gps_verified' => 1,
            'device_id' => $deviceId,
            'sync_batch_id' => $syncBatchId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Gps::recordVisitPoint($visitId, 'start', $gpsPayload, (int) $account['branch_id']);

        return [
            'visit' => Database::selectOne('SELECT * FROM visits WHERE id = :id', ['id' => $visitId]) ?? [],
            'created' => true,
            'gps' => $gpsCheck,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Step 3 — submit                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * Validate and finalise a visit.
     *
     * @param array<string, mixed> $payload
     * @return array{
     *   visit: array<string, mixed>,
     *   already_submitted: bool,
     *   deadline: array<string, mixed>,
     *   recovery_id: ?int,
     *   promise_id: ?int,
     *   followup_id: ?int
     * }
     */
    public function submit(string $uuid, int $bcSupervisorId, array $payload): array
    {
        $visit = Database::selectOne('SELECT * FROM visits WHERE uuid = :uuid', ['uuid' => $uuid]);

        if ($visit === null) {
            throw new HttpException(404, 'Visit not found. Start the visit before submitting it.');
        }

        if ((int) $visit['bc_supervisor_id'] !== $bcSupervisorId) {
            throw new HttpException(403, 'That visit belongs to another BC Supervisor.');
        }

        $visitId = (int) $visit['id'];

        if ((string) $visit['status'] !== 'draft') {
            // Replayed submission: report success with the stored result.
            return [
                'visit' => $visit,
                'already_submitted' => true,
                'deadline' => Deadline::status((string) $visit['visit_date']),
                'recovery_id' => null,
                'promise_id' => null,
                'followup_id' => null,
            ];
        }

        /* Evidence checks -------------------------------------------------- */
        $minPhotos = Settings::int('min_visit_photos', 1);
        $photoCount = (int) Database::scalar(
            'SELECT COUNT(*) FROM visit_photos WHERE visit_id = :id',
            ['id' => $visitId]
        );

        if ($photoCount < $minPhotos) {
            throw new HttpException(
                422,
                sprintf(
                    'At least %d photograph%s required. %d uploaded so far.',
                    $minPhotos,
                    $minPhotos === 1 ? ' is' : 's are',
                    $photoCount
                )
            );
        }

        $validGpsPoints = (int) Database::scalar(
            'SELECT COUNT(*) FROM visit_gps WHERE visit_id = :id AND is_valid = 1',
            ['id' => $visitId]
        );

        if ($validGpsPoints === 0) {
            throw new HttpException(422, 'This visit has no validated GPS point and cannot be submitted.');
        }

        /* Configurable form ------------------------------------------------ */
        $formId = $visit['form_id'] === null ? null : (int) $visit['form_id'];
        $fields = $formId === null ? [] : Forms::fields(Forms::KIND_VISIT, $formId);
        $formInput = is_array($payload['form'] ?? null) ? $payload['form'] : [];

        // Evidence-backed fields are marked as captured so their "required"
        // setting is satisfied by the real uploads.
        foreach ($fields as $field) {
            $type = (string) $field['field_type'];
            $key = (string) $field['field_key'];

            if ($type === 'photo' && $photoCount > 0) {
                $formInput[$key] = sprintf('%d photograph(s) captured', $photoCount);
            } elseif ($type === 'gps' && $validGpsPoints > 0) {
                $formInput[$key] = 'Captured and verified';
            }
        }

        $validated = Forms::validate($fields, $formInput);

        if ($validated['errors'] !== []) {
            throw new HttpException(422, reset($validated['errors']));
        }

        /* Borrower signature ----------------------------------------------- */
        $photos = new Photos();
        $borrowerSignature = $visit['borrower_signature'];

        if (!empty($payload['borrower_signature'])) {
            $borrowerSignature = $photos->storeSignature((string) $payload['borrower_signature'], 'borrower-' . $visitId);
        }

        if (Settings::bool('require_borrower_signature', false) && $borrowerSignature === null) {
            throw new HttpException(422, 'The borrower signature is required by your configuration.');
        }

        $supervisorSignature = $visit['supervisor_signature'];

        if (!empty($payload['supervisor_signature'])) {
            $supervisorSignature = $photos->storeSignature((string) $payload['supervisor_signature'], 'supervisor-' . $visitId);
        }

        /* Deadline classification ------------------------------------------ */
        $visitDate = (string) $visit['visit_date'];
        $deadline = Deadline::status($visitDate);
        $isLate = (bool) $deadline['has_passed'] && $deadline['is_working_day'];

        $values = $validated['values'];

        $updates = [
            'submitted_at' => now(),
            'status' => 'submitted',
            'is_late' => $isLate ? 1 : 0,
            'visit_status' => $this->visitStatus($payload, $values),
            'customer_available' => $this->tristate($values['customer_available'] ?? ($payload['customer_available'] ?? null)),
            'family_met' => $this->tristate($values['family_met'] ?? null),
            'phone_contact' => $this->tristate($values['phone_contact'] ?? null),
            'house_locked' => $this->tristate($values['house_locked'] ?? null),
            'is_alive' => $this->tristate($values['is_alive'] ?? null),
            'current_address' => isset($values['current_address']) ? mb_substr($values['current_address'], 0, 500) : null,
            'occupation' => isset($values['occupation']) ? mb_substr($values['occupation'], 0, 160) : null,
            'recovery_possibility' => $this->recoveryPossibility($values['recovery_possibility'] ?? ($payload['recovery_possibility'] ?? null)),
            'recommendation' => isset($values['recommendation']) ? mb_substr($values['recommendation'], 0, 500) : null,
            'remarks' => $values['remarks'] ?? ($payload['remarks'] ?? null),
            'borrower_signature' => $borrowerSignature,
            'supervisor_signature' => $supervisorSignature,
            'photo_count' => $photoCount,
            'updated_at' => now(),
        ];

        $recoveryId = null;
        $promiseId = null;
        $followupId = null;

        Database::transaction(function () use (
            $visitId,
            $updates,
            $fields,
            $values,
            $visit,
            $payload,
            $bcSupervisorId,
            &$recoveryId,
            &$promiseId,
            &$followupId
        ): void {
            Database::update('visits', $updates, 'id = :id', ['id' => $visitId]);

            if ($fields !== []) {
                Forms::saveValues(Forms::KIND_VISIT, $visitId, $fields, $values);
            }

            $accountId = (int) $visit['loan_account_id'];
            $branchId = (int) $visit['branch_id'];

            /* Recovery -------------------------------------------------- */
            if (!empty($payload['recovery']) && is_array($payload['recovery'])) {
                $recoveryId = Recoveries::record(
                    $accountId,
                    $branchId,
                    $bcSupervisorId,
                    $payload['recovery'],
                    $visitId
                );
            }

            /* Promise to pay -------------------------------------------- */
            $promise = is_array($payload['promise'] ?? null) ? $payload['promise'] : [];

            // The visit form's own promise fields are honoured too.
            if ($promise === [] && !empty($values['promise_amount']) && !empty($values['promise_date'])) {
                $promise = [
                    'promise_amount' => $values['promise_amount'],
                    'promise_date' => $values['promise_date'],
                    'remarks' => $values['reason'] ?? null,
                ];
            }

            if ($promise !== []) {
                $promiseId = Promises::record($accountId, $branchId, $bcSupervisorId, $promise, $visitId);
            }

            /* Follow-up ------------------------------------------------- */
            if (!empty($payload['followup']) && is_array($payload['followup'])) {
                $followupId = Followups::record(
                    $accountId,
                    $branchId,
                    $bcSupervisorId,
                    $payload['followup'],
                    $visitId,
                    $promiseId
                );
            }

            /* Account roll-up ------------------------------------------- */
            $this->refreshAccount($accountId);

            /* Dedicated work streams ------------------------------------ */
            if ((string) $visit['visit_type'] === 'krm_ots') {
                KrmOts::syncFromVisit($visitId, $payload['krm_ots'] ?? []);
            } elseif ((string) $visit['visit_type'] === 'ckcc_od2') {
                CkccRenewals::syncFromVisit($visitId, $payload['ckcc_od2'] ?? []);
            }
        });

        $fresh = Database::selectOne('SELECT * FROM visits WHERE id = :id', ['id' => $visitId]) ?? [];

        Audit::log(Audit::VISIT_SUBMITTED, [
            'entity_type' => 'visit',
            'entity_id' => $visitId,
            'description' => sprintf(
                'Customer visit submitted for account %s (%s)%s.',
                (string) Database::scalar('SELECT account_number FROM loan_accounts WHERE id = :id', ['id' => (int) $visit['loan_account_id']]),
                visit_status_label((string) ($updates['visit_status'] ?? '')),
                $isLate ? ' after the report deadline' : ''
            ),
            'new' => [
                'visit_status' => $updates['visit_status'],
                'recovery_possibility' => $updates['recovery_possibility'],
                'photos' => $photoCount,
                'is_late' => $isLate,
            ],
        ]);

        // Keep the day's submission counters current.
        Deadline::submissionFor($bcSupervisorId, $visitDate);
        $counts = Deadline::dayCounts($bcSupervisorId, $visitDate);

        Database::update('report_submissions', [
            'visits_count' => $counts['visits'],
            'recovery_amount' => $counts['recovery'],
            'promises_count' => $counts['promises'],
            'updated_at' => now(),
        ], 'bc_supervisor_id = :bc AND report_date = :date', ['bc' => $bcSupervisorId, 'date' => $visitDate]);

        // Attendance visit counter.
        Database::update('attendance', [
            'visits_count' => $counts['visits'],
            'updated_at' => now(),
        ], 'bc_supervisor_id = :bc AND attendance_date = :date', ['bc' => $bcSupervisorId, 'date' => $visitDate]);

        return [
            'visit' => $fresh,
            'already_submitted' => false,
            'deadline' => $deadline,
            'recovery_id' => $recoveryId,
            'promise_id' => $promiseId,
            'followup_id' => $followupId,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * The account must be actively allocated to this supervisor. This is the
     * check that stops a field device requesting or reporting on customers it was
     * never given.
     *
     * @return array<string, mixed>
     */
    public function assignedAccount(int $accountId, int $bcSupervisorId): array
    {
        $account = Database::selectOne(
            'SELECT a.*
               FROM loan_accounts a
               JOIN account_assignments x ON x.loan_account_id = a.id AND x.is_active = 1
              WHERE a.id = :id AND x.bc_supervisor_id = :bc
              LIMIT 1',
            ['id' => $accountId, 'bc' => $bcSupervisorId]
        );

        if ($account === null) {
            throw new HttpException(403, 'That account is not allocated to you.');
        }

        if ((string) $account['status'] !== 'active') {
            throw new HttpException(422, 'That account is closed and cannot be visited.');
        }

        return $account;
    }

    private function uuid(mixed $value): string
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

    private function visitDate(mixed $value): string
    {
        if ($value === null || $value === '') {
            return today();
        }

        $timestamp = strtotime((string) $value);

        if ($timestamp === false) {
            throw new HttpException(422, 'The visit date is not valid.');
        }

        $date = date('Y-m-d', $timestamp);

        if ($date > today()) {
            throw new HttpException(422, 'A visit cannot be dated in the future.');
        }

        if ($timestamp < strtotime('-' . self::MAX_BACKDATE_DAYS . ' days')) {
            throw new HttpException(
                422,
                sprintf('A visit cannot be dated more than %d days ago.', self::MAX_BACKDATE_DAYS)
            );
        }

        return $date;
    }

    private function visitType(mixed $requested, array $account): string
    {
        $requested = (string) ($requested ?? '');

        if (in_array($requested, ['customer', 'krm_ots', 'ckcc_od2'], true)) {
            return $requested;
        }

        return match ((string) $account['loan_category']) {
            'krm_ots' => 'krm_ots',
            'ckcc_od2' => 'ckcc_od2',
            default => 'customer',
        };
    }

    /**
     * @param array<string, mixed>  $payload
     * @param array<string, string> $values
     */
    private function visitStatus(array $payload, array $values): ?string
    {
        $allowed = ['customer_met', 'family_met', 'phone_contact', 'house_locked', 'not_available',
            'address_not_found', 'deceased', 'shifted', 'refused', 'other'];

        $candidate = (string) ($payload['visit_status'] ?? '');

        if (in_array($candidate, $allowed, true)) {
            return $candidate;
        }

        // Map the human label used by the default form back to the enum.
        $label = strtolower(trim((string) ($values['visit_status'] ?? '')));

        return match ($label) {
            'customer met' => 'customer_met',
            'family met' => 'family_met',
            'phone contact only', 'phone contact' => 'phone_contact',
            'house locked' => 'house_locked',
            'customer not available', 'not available' => 'not_available',
            'address not found' => 'address_not_found',
            'deceased' => 'deceased',
            'shifted' => 'shifted',
            'refused to pay', 'refused' => 'refused',
            'other' => 'other',
            default => null,
        };
    }

    private function tristate(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = strtolower((string) $value);

        if (in_array($value, ['yes', '1', 'true'], true)) {
            return 1;
        }

        if (in_array($value, ['no', '0', 'false'], true)) {
            return 0;
        }

        return null;
    }

    private function recoveryPossibility(mixed $value): ?string
    {
        $value = strtolower(trim((string) ($value ?? '')));

        return in_array($value, ['high', 'medium', 'low', 'nil'], true) ? $value : null;
    }

    /**
     * Recompute the roll-up columns on the account after any field activity.
     */
    public function refreshAccount(int $accountId): void
    {
        $stats = Database::selectOne(
            "SELECT COUNT(*) AS visits, MAX(submitted_at) AS last_visit
               FROM visits WHERE loan_account_id = :id AND status <> 'draft'",
            ['id' => $accountId]
        ) ?? [];

        $recovered = (float) Database::scalar(
            "SELECT COALESCE(SUM(amount), 0) FROM recoveries
              WHERE loan_account_id = :id AND status <> 'rejected'",
            ['id' => $accountId]
        );

        $account = Database::selectOne('SELECT outstanding, loan_category FROM loan_accounts WHERE id = :id', ['id' => $accountId]);
        $outstanding = (float) ($account['outstanding'] ?? 0);

        $openPromise = (int) Database::scalar(
            "SELECT COUNT(*) FROM promises WHERE loan_account_id = :id AND status = 'pending'",
            ['id' => $accountId]
        );

        $openOts = (int) Database::scalar(
            "SELECT COUNT(*) FROM krm_ots_cases
              WHERE loan_account_id = :id AND ots_status NOT IN ('closed','cancelled','rejected')",
            ['id' => $accountId]
        );

        $lastVisitStatus = (string) Database::scalar(
            "SELECT visit_status FROM visits
              WHERE loan_account_id = :id AND status <> 'draft'
              ORDER BY submitted_at DESC LIMIT 1",
            ['id' => $accountId]
        );

        $recoveryStatus = match (true) {
            $recovered > 0 && $outstanding > 0 && $recovered >= $outstanding => 'recovered',
            $recovered > 0 => 'partly_recovered',
            $openOts > 0 => 'ots',
            $openPromise > 0 => 'ptp',
            in_array($lastVisitStatus, ['address_not_found', 'shifted', 'deceased'], true) => 'not_traceable',
            (int) ($stats['visits'] ?? 0) > 0 => 'in_progress',
            default => 'pending',
        };

        Database::update('loan_accounts', [
            'visit_count' => (int) ($stats['visits'] ?? 0),
            'last_visit_at' => $stats['last_visit'] ?? null,
            'total_recovered' => $recovered,
            'recovery_status' => $recoveryStatus,
            'updated_at' => now(),
        ], 'id = :id', ['id' => $accountId]);
    }

    /* ------------------------------------------------------------------ */
    /* Review actions (Admin/Supervisor)                                  */
    /* ------------------------------------------------------------------ */

    public static function approve(int $visitId, string $remarks = ''): void
    {
        $visit = Database::selectOne('SELECT * FROM visits WHERE id = :id', ['id' => $visitId]);

        if ($visit === null) {
            throw new HttpException(404, 'Visit not found.');
        }

        Database::update('visits', [
            'status' => 'approved',
            'approved_by' => Auth::id(),
            'approved_at' => now(),
            'updated_at' => now(),
        ], 'id = :id', ['id' => $visitId]);

        Audit::log(Audit::VISIT_APPROVED, [
            'entity_type' => 'visit',
            'entity_id' => $visitId,
            'description' => 'Visit approved.' . ($remarks !== '' ? ' ' . $remarks : ''),
            'old' => ['status' => $visit['status']],
            'new' => ['status' => 'approved'],
        ]);
    }

    public static function reject(int $visitId, string $reason): void
    {
        $visit = Database::selectOne('SELECT * FROM visits WHERE id = :id', ['id' => $visitId]);

        if ($visit === null) {
            throw new HttpException(404, 'Visit not found.');
        }

        Database::update('visits', [
            'status' => 'rejected',
            'approved_by' => Auth::id(),
            'approved_at' => now(),
            'updated_at' => now(),
        ], 'id = :id', ['id' => $visitId]);

        $userId = (int) Database::scalar(
            'SELECT user_id FROM bc_supervisors WHERE id = :id',
            ['id' => (int) $visit['bc_supervisor_id']]
        );

        if ($userId > 0) {
            Notify::user(
                $userId,
                'Visit returned for correction',
                sprintf('Your visit dated %s was rejected. %s', format_date((string) $visit['visit_date']), $reason),
                ['type' => 'alert', 'related_type' => 'visit', 'related_id' => $visitId]
            );
        }

        Audit::log(Audit::VISIT_REJECTED, [
            'entity_type' => 'visit',
            'entity_id' => $visitId,
            'description' => 'Visit rejected: ' . $reason,
            'old' => ['status' => $visit['status']],
            'new' => ['status' => 'rejected', 'reason' => $reason],
        ]);
    }
}
