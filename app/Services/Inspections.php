<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Settings;

/**
 * TYPE B — BC Supervisor inspection of a BCA's field work.
 *
 * This is NOT a customer recovery visit. The BC Supervisor goes to the field
 * to verify that the BCA actually did the allocated work: was the
 * customer visited, was the location right, were the photographs taken, was the
 * recorded information true. Inspections live in their own tables and have their
 * own report, and are never mixed into the customer visit records.
 */
final class Inspections
{
    /**
     * The items that describe who the BCA is rather than what this month's visit found, and
     * so survive from one inspection to the next.
     *
     * Everything absent from this list is deliberately absent. A pre-filled observation is an
     * observation nobody made: yesterday's transaction count, the three months of
     * remuneration, the villagers' feedback, the boards, the registers, the equipment, the
     * photographs and the item 24 grade all start empty every month, because the inspector is
     * there to look at them.
     *
     * Two items read like standing facts and are not. Item 9 asks whether the appointment
     * letter is held and item 10 whether the identity card is available — the inspector is
     * being asked to be shown them today. Carrying last month's "No" forward would let a
     * missing identity card be signed off twelve months running by someone who never asked.
     *
     * Item 25, the visiting official, is also left out: it comes from whoever is signed in,
     * which is right when a different BC Supervisor makes this month's visit and last
     * month's name would be wrong.
     */
    private const CARRIED_FORWARD = [
        'bca_name',
        'branch_name',
        'cbc_name',
        'bca_qualification',
        'bca_age',
        'bca_address_contact',
        'iibf_certified',
        'iibf_certificate_no',
        'bc_working_since',
        'coordinator_contact',
        'ssa_name',
        'villages_covered',
    ];

    /* ------------------------------------------------------------------ */
    /* Social Security Scheme standing, as at the inspection              */
    /* ------------------------------------------------------------------ */

    /*
     * The inspection asks, at item 16, whether the agent is aware of the Social Security
     * Schemes. What it could not say was how many people they had actually enrolled — the
     * figures were in the panel's own SSS register and nowhere on the sheet the branch signs.
     *
     * So the sheet now carries them, for a window the inspector chooses.
     *
     * NOBODY TYPES THEM
     *
     * They are read from the enrolment records, never asked for. Sss says why in its own
     * header: a figure the system already holds must not also be typed by a person, or the
     * agent ends up measured on one number while defending another. That rules out making
     * these form fields, because a form field on this screen is an editable box.
     */

    /**
     * The window the scheme figures on a sheet cover.
     *
     * Defaults to the inspection's own month up to the inspection date: the window the panel's
     * SSS screen opens on, and what "as at this inspection" means on a monthly visit. It is a
     * default and not a rule — the inspector sets the dates, because how long a period is worth
     * looking at is a judgement. A fortnight after a warning is a different question from a
     * quarter before handing someone more work.
     *
     * @param array<string, mixed> $inspection
     * @return array{from: string, to: string}
     */
    public static function sssWindow(array $inspection): array
    {
        $date = trim((string) ($inspection['inspection_date'] ?? '')) ?: today();
        $from = trim((string) ($inspection['sss_from'] ?? ''));
        $to = trim((string) ($inspection['sss_to'] ?? ''));

        // Half a window is not a window. One end without the other goes back to the default
        // rather than reading the missing end as "today", which would quietly measure a period
        // nobody asked for.
        if ($from === '' || $to === '') {
            $from = date('Y-m-01', (int) strtotime($date));
            $to = $date;
        }

        // Entered backwards is a slip, not a request for nothing. Every other date range in the
        // panel swaps them — SssController does the same with its own from/to.
        return $to < $from ? ['from' => $to, 'to' => $from] : ['from' => $from, 'to' => $to];
    }

    /**
     * The scheme block for an inspection, or null when there is none to show.
     *
     * A submitted sheet reads its own frozen row and nothing else. Recomputing would be worse
     * than useless: a day's enrolments can be corrected after the event, and an Admin can hand
     * a submitted day back for exactly that, so a reprint would carry figures that were never
     * on the copy in the branch's file.
     *
     * An inspection submitted before this block existed has no frozen row, and gets no block.
     * Putting today's arithmetic onto a sheet signed last year is the same mistake pointing the
     * other way.
     *
     * A draft has nothing frozen yet, so it shows live figures for its window. Until it is
     * signed there is nothing to hold still.
     *
     * @param array<string, mixed> $inspection
     * @return array<string, mixed>|null
     */
    public static function sssPerformance(array $inspection): ?array
    {
        $frozen = Database::selectOne(
            'SELECT * FROM inspection_sss WHERE inspection_id = :id',
            ['id' => (int) $inspection['id']]
        );

        if ($frozen !== null) {
            $achievement = [];
            $target = [];

            foreach (array_keys(Sss::schemes()) as $column) {
                $achievement[$column] = (int) ($frozen[$column] ?? 0);
                $target[$column] = (int) ($frozen[Sss::targetColumn($column)] ?? 0);
            }

            return self::sssBlock(
                ['from' => (string) $frozen['period_from'], 'to' => (string) $frozen['period_to']],
                $achievement,
                $target,
                (int) $frozen['working_days'],
                (int) $frozen['days_reported'],
                (int) $frozen['days_reopened'],
                true
            );
        }

        if ((string) ($inspection['status'] ?? '') === 'submitted') {
            return null;
        }

        $window = self::sssWindow($inspection);
        $live = Sss::forSupervisor((int) $inspection['bc_supervisor_id'], $window['from'], $window['to']);

        return self::sssBlock(
            $window,
            $live['achievement'],
            $live['target'],
            (int) $live['working_days'],
            (int) $live['days_reported'],
            (int) $live['days_reopened'],
            false
        );
    }

    /**
     * One shape for the scheme block, whether it came from the frozen row or from live records.
     *
     * The total, the percentage and the gap are worked out here and stored nowhere, which is
     * why the frozen row holds only the counts. Two places building this by hand is how a
     * printed sheet and a screen come to disagree about the same arithmetic.
     *
     * @param array{from: string, to: string} $window
     * @param array<string, int> $achievement keyed by the *_count columns
     * @param array<string, int> $target      keyed by the same *_count columns, not *_target
     * @return array<string, mixed>
     */
    private static function sssBlock(
        array $window,
        array $achievement,
        array $target,
        int $workingDays,
        int $daysReported,
        int $daysReopened,
        bool $frozen
    ): array {
        $totalAchievement = array_sum($achievement);
        $totalTarget = array_sum($target);

        return [
            'window' => $window,
            // True once the inspection is signed. The screens say so, because "these figures
            // are as they were on the day" is different from "these figures are current".
            'frozen' => $frozen,
            'working_days' => $workingDays,
            'days_reported' => $daysReported,
            'days_reopened' => $daysReopened,
            'achievement' => $achievement,
            'target' => $target,
            'total_achievement' => $totalAchievement,
            'total_target' => $totalTarget,
            'has_target' => $totalTarget > 0,
            'percent' => percent_of($totalAchievement, $totalTarget),
            'gap' => max(0, $totalTarget - $totalAchievement),
        ];
    }

    /**
     * A posted window, reduced to two dates or to nothing.
     *
     * @param array<string, mixed> $payload
     * @return array{from: ?string, to: ?string}
     */
    private static function sssWindowInput(array $payload): array
    {
        $clean = static function (mixed $value): ?string {
            $value = trim((string) $value);

            if ($value === '') {
                return null;
            }

            // An unparseable date is dropped rather than raising: the window is not what the
            // inspector came to record, and refusing a whole sheet of observations over a
            // mistyped date would lose the visit.
            $stamp = strtotime($value);

            return $stamp === false ? null : date('Y-m-d', $stamp);
        };

        return [
            'from' => $clean($payload['sss_from'] ?? ''),
            'to' => $clean($payload['sss_to'] ?? ''),
        ];
    }

    /**
     * Freeze the scheme figures this sheet is being signed against.
     *
     * @param array{from: string, to: string} $window
     */
    private static function writeSssSnapshot(int $inspectionId, int $bcSupervisorId, array $window): void
    {
        $block = Sss::forSupervisor($bcSupervisorId, $window['from'], $window['to']);

        $figures = [];

        foreach (array_keys(Sss::schemes()) as $column) {
            $figures[$column] = (int) ($block['achievement'][$column] ?? 0);
            // The target arrives keyed by the count column, and is stored under its own name.
            $figures[Sss::targetColumn($column)] = (int) ($block['target'][$column] ?? 0);
        }

        $row = array_merge($figures, [
            'period_from' => $window['from'],
            'period_to' => $window['to'],
            'working_days' => (int) $block['working_days'],
            'days_reported' => (int) $block['days_reported'],
            'days_reopened' => (int) $block['days_reopened'],
            'updated_at' => now(),
        ]);

        // An upsert against the unique key, not a plain insert. submit() is deliberately
        // idempotent and the panel's submit button can be double-pressed, and two rows claiming
        // different figures for one sheet is not a state anything downstream could resolve.
        $existing = Database::selectOne(
            'SELECT id FROM inspection_sss WHERE inspection_id = :id',
            ['id' => $inspectionId]
        );

        if ($existing !== null) {
            Database::update('inspection_sss', $row, 'id = :id', ['id' => (int) $existing['id']]);

            return;
        }

        Database::insert('inspection_sss', array_merge($row, [
            'inspection_id' => $inspectionId,
            'created_at' => now(),
        ]));
    }

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
            throw new HttpException(404, 'BCA not found.');
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
            throw new HttpException(422, 'Choose the BCA being inspected.');
        }

        // Nothing is chosen but the supervisor. This inspection is of the BC point itself —
        // the board, the registers, the equipment, the earnings, what the villagers say —
        // and none of those belong to a customer visit or a loan account.
        //
        // The columns stay NULL rather than being dropped: inspections recorded when this
        // did verify a single visit still point at it, and an auditor opening one of those
        // needs the link to still work.
        $visitId = null;
        $accountId = null;

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
                'Inspection started for BCA #%d%s.',
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

        $remarks = trim((string) ($payload['remarks'] ?? ''));

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

        // The assessment is item 24 of the form, not a separate question. It used to be
        // asked twice, in two vocabularies: the form graded the outlet Excellent to Poor
        // while the screen asked whether a customer visit had been verified — on a monthly
        // inspection of a BC point, where there is no single visit to verify. The form wins,
        // because the form is what the Bank issued and what the inspector is holding.
        //
        // It may also be blank. Item 24 is not a required field, and a monthly inspection
        // with everything else answered is worth more than one refused over a grade.
        $result = self::grade($validated['values']['observation'] ?? null);

        // A Poor grade is the evidence an appraisal or a disciplinary process would rest
        // on, so it has to say why.
        if (inspection_result_is_negative($result) && $remarks === '') {
            throw new HttpException(
                422,
                sprintf(
                    'Remarks are required when item 24 is "%s".',
                    inspection_result_label($result)
                )
            );
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

        /*
         * The scheme window, resolved before the write so the sheet records the period it was
         * actually signed against.
         *
         * Whatever the inspector chose wins; anything they left alone falls back to what the
         * draft already held, and then to the default. It is written back to the inspection so
         * that a later change to the default cannot restate what this sheet measured.
         */
        $posted = self::sssWindowInput($payload);
        $sssWindow = self::sssWindow([
            'inspection_date' => $inspection['inspection_date'] ?? null,
            'sss_from' => $posted['from'] ?? $inspection['sss_from'] ?? null,
            'sss_to' => $posted['to'] ?? $inspection['sss_to'] ?? null,
        ]);
        $bcSupervisorId = (int) $inspection['bc_supervisor_id'];

        Database::transaction(function () use (
            $inspectionId,
            $result,
            $remarks,
            $fields,
            $validated,
            $photoCount,
            $inspectorSignature,
            $bcSignature,
            $followupRequired,
            $bcSupervisorId,
            $sssWindow
        ): void {
            Database::update('inspections', [
                'result' => $result,
                'remarks' => $remarks !== '' ? $remarks : null,
                'followup_required' => $followupRequired ? 1 : 0,
                'status' => 'submitted',
                'submitted_at' => now(),
                'photo_count' => $photoCount,
                'sss_from' => $sssWindow['from'],
                'sss_to' => $sssWindow['to'],
                'inspector_signature' => $inspectorSignature,
                'bc_signature' => $bcSignature,
                'updated_at' => now(),
            ], 'id = :id', ['id' => $inspectionId]);

            if ($fields !== []) {
                Forms::saveValues(Forms::KIND_INSPECTION, $inspectionId, $fields, $validated['values']);
            }

            // Inside the transaction with the rest of the sheet: a submitted inspection whose
            // figures did not get frozen would print no scheme block at all, and there would be
            // nothing to say whether that was the intent or a failure halfway through.
            self::writeSssSnapshot($inspectionId, $bcSupervisorId, $sssWindow);
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
                $result === null ? 'no grade recorded at item 24' : inspection_result_label($result)
            ),
            'new' => ['result' => $result, 'remarks' => $remarks, 'photos' => $photoCount],
        ]);

        // Tell the supervisor and their branch manager what was found.
        if ($supervisor !== null && $supervisor['user_id'] !== null) {
            Notify::user(
                (int) $supervisor['user_id'],
                $result === null
                    ? 'Your BC point was inspected'
                    : 'BC point inspected: ' . inspection_result_label($result),
                $remarks !== '' ? $remarks : 'Your BC point was inspected by an BC Supervisor.',
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
     * What the form already knows before anybody types.
     *
     * A monthly inspection of the same outlet asks the same standing facts every month — the
     * agent's name, age, address, IIBF number, how long they have been there. Most of those
     * were typed once already when the BCA was added to the system, and retyping them twelve
     * times a year is how a form stops being filled honestly.
     *
     * THREE SOURCES, IN THIS ORDER
     *
     *   1. What this inspection already holds. A draft being resumed wins over everything.
     *   2. What last month's inspection said, for standing facts only. This is deliberately
     *      above the staff record: if somebody corrected the address here last month because
     *      the record was wrong, that correction survives instead of being overwritten every
     *      month by the same stale master data.
     *   3. The BCA's staff record, as the first-ever seed.
     *
     * WHAT IS NEVER CARRIED FORWARD
     *
     * Anything the inspector is there to observe: yesterday's transactions, three months of
     * remuneration, what the villagers said, whether the boards are up, the registers, the
     * equipment, the photographs, and the item 24 grade. Pre-filling those would let a month
     * be signed off without looking, which is the one thing this form exists to prevent — and
     * an auditor comparing two identical months would be right to ask.
     *
     * @param array<int, array<string, mixed>> $fields
     * @param array<int, array<string, mixed>> $answers this inspection's saved answers
     * @return array<string, string>
     */
    public static function prefill(int $bcSupervisorId, array $fields, array $answers = []): array
    {
        $keys = [];

        foreach ($fields as $field) {
            $keys[(string) $field['field_key']] = (string) $field['field_type'];
        }

        $values = [];

        // 3. The staff record.
        foreach (self::fromStaffRecord($bcSupervisorId) as $key => $value) {
            if ($value !== '' && array_key_exists($key, $keys)) {
                $values[$key] = $value;
            }
        }

        // 2. Last month's standing facts.
        $previous = Database::selectOne(
            "SELECT id FROM inspections
              WHERE bc_supervisor_id = :bc AND status = 'submitted'
           ORDER BY inspection_date DESC, id DESC LIMIT 1",
            ['bc' => $bcSupervisorId]
        );

        if ($previous !== null) {
            foreach (Forms::values(Forms::KIND_INSPECTION, (int) $previous['id']) as $row) {
                $key = (string) $row['field_key'];

                if (!in_array($key, self::CARRIED_FORWARD, true) || !array_key_exists($key, $keys)) {
                    continue;
                }

                $value = trim((string) $row['value']);

                if ($value !== '') {
                    $values[$key] = $value;
                }
            }
        }

        // 1. This inspection's own answers.
        foreach ($answers as $row) {
            $key = (string) $row['field_key'];

            if (array_key_exists($key, $keys) && trim((string) $row['value']) !== '') {
                $values[$key] = (string) $row['value'];
            }
        }

        return $values;
    }

    /**
     * The BCA as the system already recorded them, mapped onto the form's items.
     *
     * @return array<string, string>
     */
    private static function fromStaffRecord(int $bcSupervisorId): array
    {
        $bca = Database::selectOne(
            'SELECT s.*, u.name AS agent_name, b.name AS branch_name
               FROM bc_supervisors s
               JOIN users u ON u.id = s.user_id
          LEFT JOIN branches b ON b.id = s.branch_id
              WHERE s.id = :id',
            ['id' => $bcSupervisorId]
        );

        if ($bca === null) {
            return [];
        }

        // Item 6 asks for one address with a contact number in it, so the parts the staff
        // record keeps separately are joined the way the paper wants them read.
        $address = array_values(array_filter([
            trim((string) ($bca['address'] ?? '')),
            trim((string) ($bca['village'] ?? '')),
            trim((string) ($bca['block'] ?? '')),
            trim((string) ($bca['district'] ?? '')),
            trim((string) ($bca['state'] ?? '')),
            trim((string) ($bca['pincode'] ?? '')),
        ], static fn (string $part): bool => $part !== ''));

        $mobile = trim((string) ($bca['mobile'] ?? ''));

        if ($mobile !== '') {
            $address[] = 'Mobile: ' . $mobile;
        }

        $iibf = trim((string) ($bca['iibf_number'] ?? ''));

        // Item 25 is the official doing the visit: the BC Supervisor signed in right now.
        $signedIn = Auth::user() ?? [];
        $official = trim((string) ($signedIn['name'] ?? ''));
        $officialMobile = trim((string) ($signedIn['mobile'] ?? ''));

        if ($official !== '' && $officialMobile !== '') {
            $official .= ', ' . $officialMobile;
        }

        return [
            'bca_name' => (string) $bca['agent_name'],
            'branch_name' => (string) ($bca['branch_name'] ?? ''),
            'cbc_name' => (string) ($bca['sp_cbc_name'] ?? ''),
            'bca_address_contact' => implode(', ', $address),
            'iibf_certified' => $iibf !== '' ? 'Yes' : '',
            'iibf_certificate_no' => $iibf,
            'bc_working_since' => (string) ($bca['joined_on'] ?? ''),
            'ssa_name' => (string) ($bca['ssa'] ?? ''),
            'visiting_official' => $official,
        ];
    }

    /**
     * Item 24's answer as a stored grade, or null when it was left blank.
     *
     * The form spells the four words out; the column stores them lowercase. Anything else
     * — a form edited in the panel to offer different words, or an older form with no item
     * 24 at all — is no grade rather than a wrong one.
     */
    private static function grade(mixed $observation): ?string
    {
        $value = strtolower(trim((string) ($observation ?? '')));

        return array_key_exists($value, inspection_results()) ? $value : null;
    }

    /**
     * @param array<string, mixed>  $payload
     * @param array<string, string> $values
     */
    private static function followupRequired(array $payload, array $values, ?string $result): bool
    {
        if (array_key_exists('followup_required', $payload)) {
            return in_array(strtolower((string) $payload['followup_required']), ['1', 'yes', 'true', 'on'], true);
        }

        if (isset($values['followup_required'])) {
            return strcasecmp($values['followup_required'], 'Yes') === 0;
        }

        // A Poor grade needs following up by default. An ungraded inspection does not:
        // nobody has said anything is wrong.
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
            // Null on a sheet that carries no scheme figures, which every consumer treats as
            // "print nothing" rather than as zeros. See sssPerformance().
            'sss' => self::sssPerformance($inspection),
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
     * Coverage for the dashboards: how many BCAs have had their monthly
     * inspection.
     *
     * This used to count customer visits — "how many of the month's visits were verified" —
     * which measured the old form, where an inspection was a check on one visit. The Bank's
     * form asks about the outlet, its registers, its equipment and its earnings, once a
     * month per agent. So the question is how many agents were seen, and the honest
     * denominator is how many there are.
     *
     * @return array<string, mixed>
     */
    public static function coverage(?int $branchId = null, ?string $from = null, ?string $to = null): array
    {
        $from ??= date('Y-m-01');
        $to ??= today();

        $params = ['from' => $from, 'to' => $to];
        $branchClause = '';
        $supervisorParams = [];
        $supervisorClause = '';

        if ($branchId !== null) {
            $branchClause = ' AND branch_id = :branch';
            $params['branch'] = $branchId;
            $supervisorClause = ' AND s.branch_id = :branch';
            $supervisorParams['branch'] = $branchId;
        }

        $supervisors = (int) Database::scalar(
            "SELECT COUNT(*) FROM bc_supervisors s JOIN users u ON u.id = s.user_id
              WHERE s.status = 'active' AND u.status = 'active'" . $supervisorClause,
            $supervisorParams
        );

        $inspected = (int) Database::scalar(
            "SELECT COUNT(DISTINCT bc_supervisor_id) FROM inspections
              WHERE inspection_date BETWEEN :from AND :to AND status = 'submitted'"
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
            'supervisors' => $supervisors,
            'supervisors_inspected' => $inspected,
            'coverage_percent' => percent_of($inspected, $supervisors),
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
