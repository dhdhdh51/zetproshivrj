<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Database;
use App\Core\Request;
use App\Services\Attendance;
use App\Services\Deadline;
use App\Services\Followups;
use App\Services\Forms;
use App\Services\Notify;
use App\Services\Photos;
use App\Services\Promises;
use App\Services\Recoveries;
use App\Services\Sss;
use App\Services\Visits;

/**
 * The online endpoints the app uses when it does have a connection: accounts,
 * the three-step visit flow, money entries, attendance, the daily report and
 * notifications. The same services back the offline queue in SyncController, so
 * behaviour is identical either way.
 */
final class FieldController extends ApiController
{
    /* ------------------------------------------------------------------ */
    /* Accounts                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * GET /api/v1/accounts?search=&filter=&page=
     */
    public function accounts(Request $request): void
    {
        $supervisorId = $this->supervisorId();
        $page = $this->page($request);
        $perPage = $this->perPage($request, 50);

        $where = ["a.status = 'active'", 'x.bc_supervisor_id = :bc'];
        $params = ['bc' => $supervisorId];

        $search = trim((string) $request->input('search', ''));

        if ($search !== '') {
            $where[] = '(a.account_number LIKE :search OR a.borrower_name LIKE :search
                         OR a.father_name LIKE :search OR a.mobile LIKE :search OR a.village LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $filter = (string) $request->input('filter', '');

        if ($filter === 'pending') {
            $where[] = 'a.visit_count = 0';
        } elseif ($filter === 'visited') {
            $where[] = 'a.visit_count > 0';
        } elseif ($filter === 'ptp') {
            $where[] = "a.recovery_status = 'ptp'";
        } elseif ($filter === 'krm_ots') {
            $where[] = "a.loan_category = 'krm_ots'";
        } elseif ($filter === 'ckcc_od2') {
            $where[] = "a.loan_category = 'ckcc_od2'";
        }

        $sql = 'SELECT a.id, a.account_number, a.cif, a.borrower_name, a.father_name, a.mobile,
                       a.village, a.address, a.loan_type, a.npa_date, a.outstanding, a.overdue,
                       a.total_recovered, a.loan_category, a.recovery_status, a.visit_count, a.last_visit_at
                  FROM loan_accounts a
                  JOIN account_assignments x ON x.loan_account_id = a.id AND x.is_active = 1
                 WHERE ' . implode(' AND ', $where)
            . ' ORDER BY a.overdue DESC, a.borrower_name ASC';

        $total = (int) Database::scalar('SELECT COUNT(*) FROM (' . $sql . ') AS t', $params);
        $rows = Database::select($sql . sprintf(' LIMIT %d OFFSET %d', $perPage, ($page - 1) * $perPage), $params);

        $this->ok([
            'accounts' => $rows,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ]);
    }

    /**
     * GET /api/v1/accounts/{id}
     */
    public function account(Request $request): void
    {
        $supervisorId = $this->supervisorId();
        $accountId = $request->paramInt('id');

        $account = (new Visits())->assignedAccount($accountId, $supervisorId);

        $this->ok([
            'account' => $account,
            'visits' => Database::select(
                "SELECT id, uuid, visit_date, visit_status, recovery_possibility, remarks, status,
                        photo_count, gps_verified, submitted_at
                   FROM visits
                  WHERE loan_account_id = :id AND status <> 'draft'
                  ORDER BY visit_date DESC LIMIT 20",
                ['id' => $accountId]
            ),
            'recoveries' => Database::select(
                'SELECT id, uuid, amount, recovery_date, payment_mode, receipt_number, status
                   FROM recoveries WHERE loan_account_id = :id ORDER BY recovery_date DESC LIMIT 20',
                ['id' => $accountId]
            ),
            'promises' => Database::select(
                'SELECT id, uuid, promise_amount, promise_date, kept_amount, status, remarks
                   FROM promises WHERE loan_account_id = :id ORDER BY promise_date DESC LIMIT 20',
                ['id' => $accountId]
            ),
            'followups' => Database::select(
                'SELECT id, uuid, followup_date, action, status, notes
                   FROM followups WHERE loan_account_id = :id ORDER BY followup_date DESC LIMIT 20',
                ['id' => $accountId]
            ),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Visit flow                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * GET /api/v1/visit-form — the configured customer visit form.
     */
    public function visitForm(Request $request): void
    {
        $this->supervisor();

        $type = (string) $request->input('visit_type', 'customer');
        $form = Forms::defaultForm(Forms::KIND_VISIT, in_array($type, ['customer', 'krm_ots', 'ckcc_od2'], true) ? $type : 'customer');

        if ($form === null) {
            $this->fail('No active visit form is configured. Contact your Admin/Supervisor.', 503, 'no_form');

            return;
        }

        $this->ok([
            'form' => [
                'id' => (int) $form['id'],
                'name' => $form['name'],
                'version' => (int) $form['version'],
                'visit_type' => $form['visit_type'],
                'fields' => Forms::definitionForApp(Forms::KIND_VISIT, (int) $form['id']),
            ],
        ]);
    }

    /**
     * POST /api/v1/visits — step 1: start a visit (GPS mandatory).
     */
    public function startVisit(Request $request): void
    {
        $supervisorId = $this->supervisorId();

        $result = (new Visits())->start($supervisorId, $request->all(), $this->deviceId());

        $gps = $request->raw('gps');

        if (is_array($gps)) {
            $this->touchLocation($gps);
        }

        $this->ok([
            'visit' => [
                'id' => (int) $result['visit']['id'],
                'uuid' => $result['visit']['uuid'],
                'status' => $result['visit']['status'],
                'visit_date' => $result['visit']['visit_date'],
                'form_id' => $result['visit']['form_id'] === null ? null : (int) $result['visit']['form_id'],
            ],
            'created' => $result['created'],
            'min_photos' => (int) setting('min_visit_photos', 1),
        ], $result['created'] ? 201 : 200);
    }

    /**
     * POST /api/v1/visits/{uuid}/photos — step 2, one photo per call.
     *
     * Accepts a multipart upload ("photo") or a base64 string ("data"), so a
     * flaky connection can retry a single photograph rather than the whole visit.
     */
    public function uploadVisitPhoto(Request $request): void
    {
        $supervisorId = $this->supervisorId();
        $uuid = (string) $request->param('uuid');

        $visit = Database::selectOne('SELECT * FROM visits WHERE uuid = :uuid', ['uuid' => $uuid]);

        if ($visit === null) {
            $this->fail('Visit not found. Start the visit first.', 404, 'not_found');

            return;
        }

        if ((int) $visit['bc_supervisor_id'] !== $supervisorId) {
            $this->fail('That visit belongs to another BC Supervisor.', 403, 'forbidden');

            return;
        }

        $file = $request->file('photo');
        $source = $file ?? (string) $request->input('data', '');

        if ($file === null && $source === '') {
            $this->fail('No photograph was received.', 422, 'photo_required');

            return;
        }

        $meta = [
            'photo_type' => $request->input('photo_type', 'other'),
            'caption' => $request->input('caption'),
            'latitude' => $request->input('latitude'),
            'longitude' => $request->input('longitude'),
            'accuracy' => $request->input('accuracy'),
            'address' => $request->input('address'),
            'captured_at' => $request->input('captured_at'),
        ];

        $stored = (new Photos())->storeForVisit((int) $visit['id'], $source, $meta);

        $this->ok([
            'photo_id' => $stored['id'],
            'duplicate' => $stored['duplicate'],
            'photo_count' => (int) Database::scalar(
                'SELECT COUNT(*) FROM visit_photos WHERE visit_id = :id',
                ['id' => (int) $visit['id']]
            ),
        ], $stored['duplicate'] ? 200 : 201);
    }

    /**
     * POST /api/v1/visits/{uuid}/submit — step 3.
     */
    public function submitVisit(Request $request): void
    {
        $supervisorId = $this->supervisorId();
        $uuid = (string) $request->param('uuid');

        $result = (new Visits())->submit($uuid, $supervisorId, $request->all());

        $this->ok([
            'visit' => [
                'id' => (int) $result['visit']['id'],
                'uuid' => $result['visit']['uuid'],
                'status' => $result['visit']['status'],
                'is_late' => (int) ($result['visit']['is_late'] ?? 0) === 1,
                'submitted_at' => $result['visit']['submitted_at'] ?? null,
            ],
            'already_submitted' => $result['already_submitted'],
            'recovery_id' => $result['recovery_id'],
            'promise_id' => $result['promise_id'],
            'followup_id' => $result['followup_id'],
            'deadline' => $result['deadline'],
        ]);
    }

    /**
     * GET /api/v1/visits?date=&page=
     */
    public function visits(Request $request): void
    {
        $supervisorId = $this->supervisorId();
        $date = (string) $request->input('date', '');
        $params = ['bc' => $supervisorId];
        $where = ['v.bc_supervisor_id = :bc'];

        if ($date !== '') {
            $where[] = 'v.visit_date = :date';
            $params['date'] = date('Y-m-d', (int) strtotime($date));
        }

        $this->ok([
            'visits' => Database::select(
                'SELECT v.id, v.uuid, v.visit_date, v.visit_status, v.status, v.is_late,
                        v.recovery_possibility, v.remarks, v.photo_count, v.gps_verified, v.submitted_at,
                        a.id AS account_id, a.account_number, a.borrower_name, a.village
                   FROM visits v
                   JOIN loan_accounts a ON a.id = v.loan_account_id
                  WHERE ' . implode(' AND ', $where)
                . ' ORDER BY v.visit_date DESC, v.id DESC LIMIT 200',
                $params
            ),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Money and follow-up                                                */
    /* ------------------------------------------------------------------ */

    /**
     * POST /api/v1/recoveries
     *
     * Legacy. No supported build of the app posts here: the field work is the visit,
     * and money never passes through an agent — the borrower pays the bank.
     *
     * Still accepted, because an app older than that can be holding an unsynced
     * payment in its outbox, and outbox entries are pushed with the type they were
     * written with. Refusing them would not undo a payment somebody already made; it
     * would only lose the record of it and leave the entry failing on the phone
     * forever. Accepted, stored as reported, and visible in the panel is what lets a
     * supervisor see it and act.
     */
    public function recovery(Request $request): void
    {
        $supervisor = $this->supervisor();
        $accountId = (int) $request->input('loan_account_id', 0);

        (new Visits())->assignedAccount($accountId, (int) $supervisor['id']);

        $id = Recoveries::record(
            $accountId,
            (int) $supervisor['branch_id'],
            (int) $supervisor['id'],
            $request->all(),
            $this->visitIdFromUuid($request->input('visit_uuid'))
        );

        $this->ok(['recovery_id' => $id], 201);
    }

    /**
     * POST /api/v1/promises
     */
    public function promise(Request $request): void
    {
        $supervisor = $this->supervisor();
        $accountId = (int) $request->input('loan_account_id', 0);

        (new Visits())->assignedAccount($accountId, (int) $supervisor['id']);

        $id = Promises::record(
            $accountId,
            (int) $supervisor['branch_id'],
            (int) $supervisor['id'],
            $request->all(),
            $this->visitIdFromUuid($request->input('visit_uuid'))
        );

        $this->ok(['promise_id' => $id], 201);
    }

    /**
     * POST /api/v1/followups
     */
    public function followup(Request $request): void
    {
        $supervisor = $this->supervisor();
        $accountId = (int) $request->input('loan_account_id', 0);

        (new Visits())->assignedAccount($accountId, (int) $supervisor['id']);

        $id = Followups::record(
            $accountId,
            (int) $supervisor['branch_id'],
            (int) $supervisor['id'],
            $request->all(),
            $this->visitIdFromUuid($request->input('visit_uuid'))
        );

        $this->ok(['followup_id' => $id], 201);
    }

    /**
     * GET /api/v1/followups?status=pending
     */
    public function followups(Request $request): void
    {
        $supervisorId = $this->supervisorId();
        $status = (string) $request->input('status', 'pending');
        $status = in_array($status, ['pending', 'done', 'cancelled'], true) ? $status : 'pending';

        $this->ok([
            'followups' => Database::select(
                'SELECT f.id, f.uuid, f.followup_date, f.action, f.status, f.notes,
                        a.id AS account_id, a.account_number, a.borrower_name, a.village, a.mobile
                   FROM followups f
                   JOIN loan_accounts a ON a.id = f.loan_account_id
                  WHERE f.bc_supervisor_id = :bc AND f.status = :status
                  ORDER BY f.followup_date ASC LIMIT 200',
                ['bc' => $supervisorId, 'status' => $status]
            ),
        ]);
    }

    private function visitIdFromUuid(mixed $uuid): ?int
    {
        if (!is_string($uuid) || $uuid === '') {
            return null;
        }

        $id = Database::scalar('SELECT id FROM visits WHERE uuid = :uuid', ['uuid' => $uuid]);

        return $id === null ? null : (int) $id;
    }

    /* ------------------------------------------------------------------ */
    /* Attendance                                                         */
    /* ------------------------------------------------------------------ */

    public function checkIn(Request $request): void
    {
        $supervisor = $this->supervisor();
        $row = (new Attendance())->checkIn(
            (int) $supervisor['id'],
            (int) $supervisor['user_id'],
            $request->all(),
            $this->deviceId()
        );

        $this->ok(['attendance' => $row], 201);
    }

    public function checkOut(Request $request): void
    {
        $supervisor = $this->supervisor();
        $row = (new Attendance())->checkOut((int) $supervisor['id'], $request->all(), $this->deviceId());

        $this->ok(['attendance' => $row]);
    }

    public function attendanceToday(Request $request): void
    {
        $supervisorId = $this->supervisorId();

        $this->ok([
            'attendance' => Attendance::today($supervisorId),
            'history' => Database::select(
                'SELECT attendance_date, check_in_at, check_out_at, working_minutes, visits_count, status
                   FROM attendance WHERE bc_supervisor_id = :bc
                  ORDER BY attendance_date DESC LIMIT 30',
                ['bc' => $supervisorId]
            ),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Social Security Scheme enrolments                                  */
    /* ------------------------------------------------------------------ */

    /**
     * The figures for one day, plus recent history and the month so far.
     *
     * The app opens this screen on a day that may already have figures — either because
     * the supervisor is correcting them or because they were typed on another device — so
     * the current values come down with the form.
     */
    public function sss(Request $request): void
    {
        $supervisorId = $this->supervisorId();
        $date = $this->sssDate($request->input('date'));

        $this->ok([
            'date' => $date,
            'entry' => Sss::forDate($supervisorId, $date),
            'schemes' => Sss::schemes(),
            'scheme_names' => Sss::schemeNames(),
            'max_per_scheme' => Sss::MAX_PER_SCHEME,
            // Month to date, so the supervisor can see the running figure they are
            // measured on without waiting for a report to be run in the panel.
            'month' => Sss::summary(date('Y-m-01', strtotime($date)), $date, $supervisorId),
            'history' => Database::select(
                'SELECT enrolment_date, apy_count, pmjjby_count, pmsby_count, pmjdy_count,
                        (apy_count + pmjjby_count + pmsby_count + pmjdy_count) AS total,
                        remarks, source
                   FROM sss_enrolments WHERE bc_supervisor_id = :bc
                  ORDER BY enrolment_date DESC LIMIT 30',
                ['bc' => $supervisorId]
            ),
        ]);
    }

    /**
     * Record or correct a day's figures.
     *
     * Posting the same day twice rewrites it rather than adding to it, so the offline
     * outbox can retry safely.
     */
    public function recordSss(Request $request): void
    {
        $supervisor = $this->supervisor();

        $result = Sss::record(
            (int) $supervisor['id'],
            $request->all(),
            'app',
            $this->deviceId()
        );

        $this->ok([
            'sss' => Sss::forDate((int) $supervisor['id'], (string) ($result['date'] ?? today())),
            'total' => $result['total'],
        ], $result['created'] ? 201 : 200);
    }

    /**
     * The date a read is asking about. Unreadable is refused rather than quietly turned
     * into today, because a supervisor shown the wrong day's figures would correct them.
     */
    private function sssDate(mixed $value): string
    {
        $raw = trim((string) ($value ?? ''));

        if ($raw === '') {
            return today();
        }

        $timestamp = strtotime($raw);

        if ($timestamp === false) {
            throw new \App\Core\HttpException(422, 'That is not a date the system can read.');
        }

        return date('Y-m-d', $timestamp);
    }

    /* ------------------------------------------------------------------ */
    /* Daily report and deadline                                          */
    /* ------------------------------------------------------------------ */

    /**
     * GET /api/v1/deadline — the countdown source of truth.
     */
    public function deadline(Request $request): void
    {
        $supervisorId = $this->supervisorId();
        $date = (string) $request->input('date', today());

        $submission = Deadline::submissionFor($supervisorId, $date);

        $this->ok([
            'deadline' => Deadline::status($date),
            'counts' => Deadline::dayCounts($supervisorId, $date),
            'submission' => [
                'id' => (int) $submission['id'],
                'status' => $submission['status'],
                'submitted_at' => $submission['submitted_at'],
                'is_late' => (int) $submission['is_late'] === 1,
                'deadline_at' => $submission['deadline_at'],
                'late_reason' => $submission['late_reason'],
                'approval_remarks' => $submission['approval_remarks'],
            ],
        ]);
    }

    /**
     * POST /api/v1/reports/daily
     */
    public function submitDailyReport(Request $request): void
    {
        $supervisorId = $this->supervisorId();

        $result = Deadline::submit($supervisorId, $request->input('report_date'), array_merge($request->all(), [
            'device_id' => $this->deviceId(),
        ]));

        $this->ok([
            'submission_id' => $result['submission_id'],
            'status' => $result['status'],
            'is_late' => $result['is_late'],
            'message' => $result['message'],
            'deadline' => Deadline::status((string) ($request->input('report_date') ?: today())),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Notifications                                                      */
    /* ------------------------------------------------------------------ */

    public function notifications(Request $request): void
    {
        $user = auth_user() ?? [];

        $this->ok([
            'unread' => Notify::unreadCount($user),
            'notifications' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'title' => $row['title'],
                'body' => $row['body'],
                'type' => $row['type'],
                'link' => $row['link'],
                'is_read' => (int) $row['is_read'] === 1,
                'created_at' => $row['created_at'],
            ], Notify::forUser($user, $this->perPage($request, 40, 100))),
        ]);
    }

    public function readNotification(Request $request): void
    {
        Notify::markRead($request->paramInt('id'), auth_user() ?? []);

        $this->ok(['message' => 'Marked as read.']);
    }

    public function readAllNotifications(Request $request): void
    {
        $count = Notify::markAllRead(auth_user() ?? []);

        $this->ok(['marked' => $count]);
    }

    /* ------------------------------------------------------------------ */
    /* Dedicated work streams                                             */
    /* ------------------------------------------------------------------ */

    /**
     * POST /api/v1/krm-ots and /api/v1/ckcc — record work-stream detail from the
     * field, for accounts already tagged into those streams.
     */
    public function krmOts(Request $request): void
    {
        $supervisor = $this->supervisor();
        $accountId = (int) $request->input('loan_account_id', 0);

        (new Visits())->assignedAccount($accountId, (int) $supervisor['id']);

        $id = \App\Services\KrmOts::save($accountId, $request->all(), $this->visitIdFromUuid($request->input('visit_uuid')));

        $this->ok(['krm_ots_id' => $id], 201);
    }

    public function ckcc(Request $request): void
    {
        $supervisor = $this->supervisor();
        $accountId = (int) $request->input('loan_account_id', 0);

        (new Visits())->assignedAccount($accountId, (int) $supervisor['id']);

        $id = \App\Services\CkccRenewals::save($accountId, $request->all(), $this->visitIdFromUuid($request->input('visit_uuid')));

        $this->ok(['ckcc_renewal_id' => $id], 201);
    }
}
