<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Settings;

/**
 * TYPE B — Admin/Supervisor inspection of a BC Supervisor's field work.
 *
 * This is NOT a customer recovery visit. The Admin/Supervisor goes to the field
 * to verify that the BC Supervisor actually did the allocated work: was the
 * customer visited, was the location right, were the photographs taken, was the
 * recorded information true. Inspections live in their own tables and have their
 * own report, and are never mixed into the customer visit records.
 */
final class Inspections
{
    /* ------------------------------------------------------------------ */
    /* Context: what has the supervisor been doing?                       */
    /* ------------------------------------------------------------------ */

    /**
     * The work picture an inspector needs before choosing what to check:
     * allocated accounts, today's visits, completed and pending work.
     *
     * @return array<string, mixed>
     */
    public static function supervisorWorkload(int $bcSupervisorId, ?string $date = null): array
    {
        $date ??= today();

        $supervisor = Database::selectOne(
            'SELECT s.*, u.name, u.mobile AS user_mobile, u.status AS user_status, b.name AS branch_name, b.code AS branch_code
               FROM bc_supervisors s
               JOIN users u ON u.id = s.user_id
               JOIN branches b ON b.id = s.branch_id
              WHERE s.id = :id',
            ['id' => $bcSupervisorId]
        );

        if ($supervisor === null) {
            throw new HttpException(404, 'BC Supervisor not found.');
        }

        $assigned = (int) Database::scalar(
            'SELECT COUNT(*) FROM account_assignments WHERE bc_supervisor_id = :bc AND is_active = 1',
            ['bc' => $bcSupervisorId]
        );

        $visitsToday = Database::select(
            "SELECT v.*, a.account_number, a.borrower_name, a.village, a.mobile,
                    (SELECT COUNT(*) FROM visit_photos p WHERE p.visit_id = v.id) AS photos,
                    (SELECT COUNT(*) FROM inspections i WHERE i.visit_id = v.id AND i.status = 'submitted') AS inspections,
                    g.latitude, g.longitude, g.accuracy, g.address
               FROM visits v
               JOIN loan_accounts a ON a.id = v.loan_account_id
          LEFT JOIN visit_gps g ON g.id = (
                    SELECT id FROM visit_gps WHERE visit_id = v.id ORDER BY (event = 'start') DESC, id ASC LIMIT 1
               )
              WHERE v.bc_supervisor_id = :bc AND v.visit_date = :date
              ORDER BY v.submitted_at DESC, v.id DESC",
            ['bc' => $bcSupervisorId, 'date' => $date]
        );

        // Accounts allocated but not yet visited at all — the "pending" work.
        $pending = Database::select(
            "SELECT a.id, a.account_number, a.borrower_name, a.village, a.outstanding, a.overdue, a.last_visit_at
               FROM loan_accounts a
               JOIN account_assignments x ON x.loan_account_id = a.id AND x.is_active = 1
              WHERE x.bc_supervisor_id = :bc AND a.status = 'active'
                AND NOT EXISTS (SELECT 1 FROM visits v WHERE v.loan_account_id = a.id AND v.status <> 'draft')
              ORDER BY a.overdue DESC
              LIMIT 100",
            ['bc' => $bcSupervisorId]
        );

        $attendance = Database::selectOne(
            'SELECT * FROM attendance WHERE bc_supervisor_id = :bc AND attendance_date = :date',
            ['bc' => $bcSupervisorId, 'date' => $date]
        );

        $device = Database::selectOne(
            "SELECT * FROM devices WHERE user_id = :uid AND status = 'active' ORDER BY last_seen_at DESC LIMIT 1",
            ['uid' => (int) $supervisor['user_id']]
        );

        return [
            'supervisor' => $supervisor,
            'date' => $date,
            'assigned_accounts' => $assigned,
            'visits_today' => $visitsToday,
            'completed_today' => count(array_filter(
                $visitsToday,
                static fn (array $visit): bool => (string) $visit['status'] !== 'draft'
            )),
            'draft_today' => count(array_filter(
                $visitsToday,
                static fn (array $visit): bool => (string) $visit['status'] === 'draft'
            )),
            'pending_accounts' => $pending,
            'pending_count' => (int) Database::scalar(
                "SELECT COUNT(*) FROM loan_accounts a
                   JOIN account_assignments x ON x.loan_account_id = a.id AND x.is_active = 1
                  WHERE x.bc_supervisor_id = :bc AND a.status = 'active'
                    AND NOT EXISTS (SELECT 1 FROM visits v WHERE v.loan_account_id = a.id AND v.status <> 'draft')",
                ['bc' => $bcSupervisorId]
            ),
            'attendance' => $attendance,
            'device' => $device,
            'recovery_today' => (float) Database::scalar(
                "SELECT COALESCE(SUM(amount), 0) FROM recoveries
                  WHERE bc_supervisor_id = :bc AND recovery_date = :date AND status <> 'rejected'",
                ['bc' => $bcSupervisorId, 'date' => $date]
            ),
            'inspections' => Database::select(
                'SELECT i.*, u.name AS inspector_name, a.account_number, a.borrower_name
                   FROM inspections i
                   JOIN users u ON u.id = i.admin_user_id
              LEFT JOIN loan_accounts a ON a.id = i.loan_account_id
                  WHERE i.bc_supervisor_id = :bc
                  ORDER BY i.inspection_date DESC, i.id DESC
                  LIMIT 20',
                ['bc' => $bcSupervisorId]
            ),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Start                                                              */
    /* ------------------------------------------------------------------ */

    /**
     * Open a draft inspection and capture the inspector's own GPS point.
     *
     * @param array<string, mixed> $payload bc_supervisor_id, loan_account_id,
     *                                      visit_id, inspection_date, gps{}
     */
    public function start(array $payload): array
    {
        $bcSupervisorId = (int) ($payload['bc_supervisor_id'] ?? 0);

        $supervisor = Database::selectOne(
            'SELECT * FROM bc_supervisors WHERE id = :id',
            ['id' => $bcSupervisorId]
        );

        if ($supervisor === null) {
            throw new HttpException(422, 'Choose the BC Supervisor being inspected.');
        }

        $visitId = isset($payload['visit_id']) && $payload['visit_id'] !== '' ? (int) $payload['visit_id'] : null;
        $accountId = isset($payload['loan_account_id']) && $payload['loan_account_id'] !== ''
            ? (int) $payload['loan_account_id']
            : null;

        if ($visitId !== null) {
            $visit = Database::selectOne('SELECT * FROM visits WHERE id = :id', ['id' => $visitId]);

            if ($visit === null) {
                throw new HttpException(404, 'The visit being inspected was not found.');
            }

            if ((int) $visit['bc_supervisor_id'] !== $bcSupervisorId) {
                throw new HttpException(422, 'That visit does not belong to the selected BC Supervisor.');
            }

            $accountId ??= (int) $visit['loan_account_id'];
        }

        if ($accountId !== null) {
            $account = Database::selectOne('SELECT branch_id FROM loan_accounts WHERE id = :id', ['id' => $accountId]);

            if ($account === null) {
                throw new HttpException(404, 'The account being inspected was not found.');
            }
        }

        $inspectionDate = today();

        if (!empty($payload['inspection_date'])) {
            $timestamp = strtotime((string) $payload['inspection_date']);

            if ($timestamp === false || $timestamp > time() + 86400) {
                throw new HttpException(422, 'The inspection date is not valid.');
            }

            $inspectionDate = date('Y-m-d', $timestamp);
        }

        $form = Forms::defaultForm(Forms::KIND_INSPECTION);
        $uuid = Recoveries::uuid($payload['uuid'] ?? null);

        $existing = Database::selectOne('SELECT * FROM inspections WHERE uuid = :uuid', ['uuid' => $uuid]);

        if ($existing !== null) {
            return $existing;
        }

        $inspectionId = Database::insert('inspections', [
            'uuid' => $uuid,
            'admin_user_id' => (int) Auth::id(),
            'bc_supervisor_id' => $bcSupervisorId,
            'branch_id' => (int) $supervisor['branch_id'],
            'loan_account_id' => $accountId,
            'visit_id' => $visitId,
            'form_id' => $form === null ? null : (int) $form['id'],
            'inspection_date' => $inspectionDate,
            'started_at' => now(),
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // The inspector's location is the whole point of a field inspection, but
        // a web browser may legitimately fail to provide it (desk review), so it
        // is recorded when present and its absence is visible on the report.
        $gps = is_array($payload['gps'] ?? null) ? $payload['gps'] : [];

        if ($gps !== [] && ($gps['latitude'] ?? '') !== '') {
            $result = Gps::recordInspectionPoint(
                $inspectionId,
                'start',
                $gps,
                (int) $supervisor['branch_id'],
                $visitId
            );

            Database::update(
                'inspections',
                ['gps_verified' => $result['valid'] ? 1 : 0, 'updated_at' => now()],
                'id = :id',
                ['id' => $inspectionId]
            );
        }

        Audit::log(Audit::INSPECTION_STARTED, [
            'entity_type' => 'inspection',
            'entity_id' => $inspectionId,
            'description' => sprintf(
                'Inspection started for BC Supervisor #%d%s.',
                $bcSupervisorId,
                $visitId !== null ? ' against visit #' . $visitId : ''
            ),
        ]);

        return Database::selectOne('SELECT * FROM inspections WHERE id = :id', ['id' => $inspectionId]) ?? [];
    }

    /* ------------------------------------------------------------------ */
    /* Submit                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Validate and submit the inspection.
     *
     * @param array<string, mixed> $payload result, remarks, form{}, gps{}
     */
    public function submit(int $inspectionId, array $payload): array
    {
        $inspection = Database::selectOne('SELECT * FROM inspections WHERE id = :id', ['id' => $inspectionId]);

        if ($inspection === null) {
            throw new HttpException(404, 'Inspection not found.');
        }

        if ((string) $inspection['status'] === 'submitted') {
            return $inspection;
        }

        $result = (string) ($payload['result'] ?? '');

        if (!array_key_exists($result, inspection_results())) {
            throw new HttpException(422, 'Choose the verification result.');
        }

        $remarks = trim((string) ($payload['remarks'] ?? ''));

        // Every negative outcome must be explained: this is the evidence a
        // supervisor's appraisal or a disciplinary process would rest on.
        if (inspection_result_is_negative($result) && $remarks === '') {
            throw new HttpException(
                422,
                sprintf('Remarks are required when the result is "%s".', inspection_result_label($result))
            );
        }

        $minPhotos = Settings::int('min_inspection_photos', 1);
        $photoCount = (int) Database::scalar(
            'SELECT COUNT(*) FROM inspection_photos WHERE inspection_id = :id',
            ['id' => $inspectionId]
        );

        if ($photoCount < $minPhotos) {
            throw new HttpException(
                422,
                sprintf(
                    'At least %d inspection photograph%s required. %d uploaded so far.',
                    $minPhotos,
                    $minPhotos === 1 ? ' is' : 's are',
                    $photoCount
                )
            );
        }

        $formId = $inspection['form_id'] === null ? null : (int) $inspection['form_id'];
        $fields = $formId === null ? [] : Forms::fields(Forms::KIND_INSPECTION, $formId);
        $formInput = is_array($payload['form'] ?? null) ? $payload['form'] : [];

        foreach ($fields as $field) {
            $type = (string) $field['field_type'];

            if ($type === 'photo' && $photoCount > 0) {
                $formInput[(string) $field['field_key']] = sprintf('%d photograph(s) captured', $photoCount);
            } elseif ($type === 'gps') {
                $hasPoint = (int) Database::scalar(
                    'SELECT COUNT(*) FROM inspection_gps WHERE inspection_id = :id',
                    ['id' => $inspectionId]
                );

                if ($hasPoint > 0) {
                    $formInput[(string) $field['field_key']] = 'Captured';
                }
            }
        }

        $validated = Forms::validate($fields, $formInput);

        if ($validated['errors'] !== []) {
            throw new HttpException(422, reset($validated['errors']));
        }

        // A late GPS point (captured on the form page rather than at start).
        $gps = is_array($payload['gps'] ?? null) ? $payload['gps'] : [];

        if ($gps !== [] && ($gps['latitude'] ?? '') !== '') {
            $point = Gps::recordInspectionPoint(
                $inspectionId,
                'submit',
                $gps,
                (int) $inspection['branch_id'],
                $inspection['visit_id'] === null ? null : (int) $inspection['visit_id']
            );

            if ($point['valid']) {
                Database::update('inspections', ['gps_verified' => 1, 'updated_at' => now()], 'id = :id', ['id' => $inspectionId]);
            }
        }

        $photos = new Photos();
        $inspectorSignature = $inspection['inspector_signature'];
        $bcSignature = $inspection['bc_signature'];

        if (!empty($payload['inspector_signature'])) {
            $inspectorSignature = $photos->storeSignature((string) $payload['inspector_signature'], 'inspector-' . $inspectionId);
        }

        if (!empty($payload['bc_signature'])) {
            $bcSignature = $photos->storeSignature((string) $payload['bc_signature'], 'bc-' . $inspectionId);
        }

        $followupRequired = self::followupRequired($payload, $validated['values'], $result);

        Database::transaction(function () use (
            $inspectionId,
            $result,
            $remarks,
            $fields,
            $validated,
            $photoCount,
            $inspectorSignature,
            $bcSignature,
            $followupRequired
        ): void {
            Database::update('inspections', [
                'result' => $result,
                'remarks' => $remarks !== '' ? $remarks : null,
                'followup_required' => $followupRequired ? 1 : 0,
                'status' => 'submitted',
                'submitted_at' => now(),
                'photo_count' => $photoCount,
                'inspector_signature' => $inspectorSignature,
                'bc_signature' => $bcSignature,
                'updated_at' => now(),
            ], 'id = :id', ['id' => $inspectionId]);

            if ($fields !== []) {
                Forms::saveValues(Forms::KIND_INSPECTION, $inspectionId, $fields, $validated['values']);
            }
        });

        $fresh = Database::selectOne('SELECT * FROM inspections WHERE id = :id', ['id' => $inspectionId]) ?? [];

        $supervisor = Database::selectOne(
            'SELECT s.bc_code, s.user_id, u.name
               FROM bc_supervisors s JOIN users u ON u.id = s.user_id
              WHERE s.id = :id',
            ['id' => (int) $inspection['bc_supervisor_id']]
        );

        Audit::log(Audit::INSPECTION_SUBMITTED, [
            'entity_type' => 'inspection',
            'entity_id' => $inspectionId,
            'description' => sprintf(
                'Inspection of %s (%s) submitted: %s.',
                $supervisor['name'] ?? '',
                $supervisor['bc_code'] ?? '',
                inspection_result_label($result)
            ),
            'new' => ['result' => $result, 'remarks' => $remarks, 'photos' => $photoCount],
        ]);

        // Tell the supervisor and their branch manager what was found.
        if ($supervisor !== null && $supervisor['user_id'] !== null) {
            Notify::user(
                (int) $supervisor['user_id'],
                'Field work inspected: ' . inspection_result_label($result),
                $remarks !== '' ? $remarks : 'Your field work was inspected by an Admin/Supervisor.',
                [
                    'type' => inspection_result_is_negative($result) ? 'alert' : 'inspection',
                    'related_type' => 'inspection',
                    'related_id' => $inspectionId,
                ]
            );
        }

        if (inspection_result_is_negative($result)) {
            Notify::branch(
                (int) $inspection['branch_id'],
                'Adverse inspection result',
                sprintf(
                    '%s (%s): %s. %s',
                    $supervisor['name'] ?? '',
                    $supervisor['bc_code'] ?? '',
                    inspection_result_label($result),
                    str_excerpt($remarks, 160)
                ),
                ['type' => 'alert', 'related_type' => 'inspection', 'related_id' => $inspectionId]
            );
        }

        return $fresh;
    }

    /**
     * @param array<string, mixed>  $payload
     * @param array<string, string> $values
     */
    private static function followupRequired(array $payload, array $values, string $result): bool
    {
        if (array_key_exists('followup_required', $payload)) {
            return in_array(strtolower((string) $payload['followup_required']), ['1', 'yes', 'true', 'on'], true);
        }

        if (isset($values['followup_required'])) {
            return strcasecmp($values['followup_required'], 'Yes') === 0;
        }

        // Anything other than a clean verification needs a follow-up by default.
        return inspection_result_is_negative($result);
    }

    /* ------------------------------------------------------------------ */
    /* Reading                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Everything the inspection report needs in one call.
     *
     * @return array<string, mixed>
     */
    public static function detail(int $inspectionId): array
    {
        $inspection = Database::selectOne(
            'SELECT i.*,
                    admin.name AS inspector_name, admin.employee_code AS inspector_code,
                    u.name AS supervisor_name, s.bc_code, s.mobile AS supervisor_mobile,
                    b.name AS branch_name, b.code AS branch_code,
                    a.account_number, a.cif, a.borrower_name, a.father_name, a.mobile AS borrower_mobile,
                    a.village, a.address, a.loan_type, a.outstanding, a.overdue, a.npa_date,
                    v.uuid AS visit_uuid, v.visit_date, v.submitted_at AS visit_submitted_at,
                    v.visit_status, v.remarks AS visit_remarks, v.photo_count AS visit_photo_count,
                    f.name AS form_name
               FROM inspections i
               JOIN users admin ON admin.id = i.admin_user_id
               JOIN bc_supervisors s ON s.id = i.bc_supervisor_id
               JOIN users u ON u.id = s.user_id
               JOIN branches b ON b.id = i.branch_id
          LEFT JOIN loan_accounts a ON a.id = i.loan_account_id
          LEFT JOIN visits v ON v.id = i.visit_id
          LEFT JOIN inspection_forms f ON f.id = i.form_id
              WHERE i.id = :id',
            ['id' => $inspectionId]
        );

        if ($inspection === null) {
            throw new HttpException(404, 'Inspection not found.');
        }

        return [
            'inspection' => $inspection,
            'answers' => Forms::values(Forms::KIND_INSPECTION, $inspectionId),
            'photos' => Database::select(
                'SELECT * FROM inspection_photos WHERE inspection_id = :id ORDER BY id ASC',
                ['id' => $inspectionId]
            ),
            'gps' => Database::select(
                'SELECT * FROM inspection_gps WHERE inspection_id = :id ORDER BY id ASC',
                ['id' => $inspectionId]
            ),
            'visit_photos' => $inspection['visit_id'] === null ? [] : Database::select(
                'SELECT * FROM visit_photos WHERE visit_id = :id ORDER BY id ASC',
                ['id' => (int) $inspection['visit_id']]
            ),
            'visit_gps' => $inspection['visit_id'] === null ? [] : Database::select(
                'SELECT * FROM visit_gps WHERE visit_id = :id ORDER BY id ASC',
                ['id' => (int) $inspection['visit_id']]
            ),
        ];
    }

    /**
     * Coverage statistics for the dashboard: how much of the field work has
     * actually been inspected.
     *
     * @return array<string, mixed>
     */
    public static function coverage(?int $branchId = null, ?string $from = null, ?string $to = null): array
    {
        $from ??= date('Y-m-01');
        $to ??= today();

        $params = ['from' => $from, 'to' => $to];
        $branchClause = '';

        if ($branchId !== null) {
            $branchClause = ' AND branch_id = :branch';
            $params['branch'] = $branchId;
        }

        $visits = (int) Database::scalar(
            "SELECT COUNT(*) FROM visits
              WHERE visit_date BETWEEN :from AND :to AND status <> 'draft'" . $branchClause,
            $params
        );

        $inspected = (int) Database::scalar(
            "SELECT COUNT(DISTINCT visit_id) FROM inspections
              WHERE inspection_date BETWEEN :from AND :to AND status = 'submitted' AND visit_id IS NOT NULL"
            . $branchClause,
            $params
        );

        $submitted = (int) Database::scalar(
            "SELECT COUNT(*) FROM inspections
              WHERE inspection_date BETWEEN :from AND :to AND status = 'submitted'" . $branchClause,
            $params
        );

        $pending = (int) Database::scalar(
            "SELECT COUNT(*) FROM inspections
              WHERE inspection_date BETWEEN :from AND :to AND status = 'draft'" . $branchClause,
            $params
        );

        $byResult = Database::select(
            "SELECT result, COUNT(*) AS total FROM inspections
              WHERE inspection_date BETWEEN :from AND :to AND status = 'submitted'" . $branchClause
            . ' GROUP BY result ORDER BY total DESC',
            $params
        );

        return [
            'from' => $from,
            'to' => $to,
            'visits' => $visits,
            'visits_inspected' => $inspected,
            'coverage_percent' => percent_of($inspected, $visits),
            'inspections_submitted' => $submitted,
            'inspections_pending' => $pending,
            'by_result' => $byResult,
            'adverse' => array_sum(array_map(
                static fn (array $row): int => inspection_result_is_negative((string) $row['result']) ? (int) $row['total'] : 0,
                $byResult
            )),
        ];
    }
}
