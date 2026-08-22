<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\Export\RecordExport;
use App\Services\Forms;
use App\Services\Inspections;
use App\Services\Photos;
use App\Services\Reports;

/**
 * BC SUPERVISOR INSPECTIONS (TYPE B).
 *
 * This is the BC Supervisor's own field activity: going out to verify that a
 * BCA really performed the allocated work. It is not a customer
 * recovery visit, it lives in its own tables, and it produces its own report.
 *
 * Flow: choose supervisor → see their allocated work and today's visits →
 * pick the account/visit to check → start (captures inspector GPS) →
 * add photographs → complete the configurable form → record the result → submit.
 */
final class InspectionController extends BaseController
{
    public function index(Request $request): void
    {
        $date = (string) $request->query('date', today());
        $branchId = (int) $request->query('branch_id', 0);

        $params = ['date' => $date];
        $where = ["u.status = 'active'", "s.status = 'active'"];

        if ($branchId > 0) {
            $where[] = 's.branch_id = :branch';
            $params['branch'] = $branchId;
        }

        // Who has work today, and how much of it has been inspected.
        $supervisors = Database::select(
            "SELECT s.id, s.bc_code, u.name, b.name AS branch_name,
                    (SELECT COUNT(*) FROM account_assignments x
                      WHERE x.bc_supervisor_id = s.id AND x.is_active = 1) AS allocated,
                    (SELECT COUNT(*) FROM visits v
                      WHERE v.bc_supervisor_id = s.id AND v.visit_date = :date AND v.status <> 'draft') AS visits_today,
                    (SELECT COUNT(*) FROM inspections i
                      WHERE i.bc_supervisor_id = s.id AND i.inspection_date = :date AND i.status = 'submitted') AS inspected_today,
                    (SELECT COUNT(*) FROM inspections i
                      WHERE i.bc_supervisor_id = s.id AND i.status = 'submitted'
                        AND i.result <> 'work_verified'
                        AND i.inspection_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) AS adverse_30d,
                    (SELECT MAX(i.inspection_date) FROM inspections i
                      WHERE i.bc_supervisor_id = s.id AND i.status = 'submitted') AS last_inspected,
                    t.check_in_at, t.status AS attendance_status
               FROM bc_supervisors s
               JOIN users u ON u.id = s.user_id
               JOIN branches b ON b.id = s.branch_id
          LEFT JOIN attendance t ON t.bc_supervisor_id = s.id AND t.attendance_date = :date
              WHERE " . implode(' AND ', $where)
            . ' ORDER BY visits_today DESC, adverse_30d DESC, u.name ASC',
            $params
        );

        $this->page('admin.inspections.index', [
            'title' => 'BCA inspections',
            'date' => $date,
            'branchId' => $branchId,
            'branches' => $this->branchOptions(),
            'supervisors' => $supervisors,
            'coverage' => Inspections::coverage($branchId > 0 ? $branchId : null),
            'drafts' => Database::select(
                'SELECT i.*, u.name AS supervisor_name, s.bc_code, a.account_number, a.borrower_name
                   FROM inspections i
                   JOIN bc_supervisors s ON s.id = i.bc_supervisor_id
                   JOIN users u ON u.id = s.user_id
              LEFT JOIN loan_accounts a ON a.id = i.loan_account_id
                  WHERE i.status = :status AND i.admin_user_id = :uid
                  ORDER BY i.id DESC',
                ['status' => 'draft', 'uid' => (int) auth_id()]
            ),
            'recent' => Database::select(
                "SELECT i.*, u.name AS supervisor_name, s.bc_code, a.account_number, a.borrower_name,
                        admin.name AS inspector_name
                   FROM inspections i
                   JOIN bc_supervisors s ON s.id = i.bc_supervisor_id
                   JOIN users u ON u.id = s.user_id
                   JOIN users admin ON admin.id = i.admin_user_id
              LEFT JOIN loan_accounts a ON a.id = i.loan_account_id
                  WHERE i.status = 'submitted'
                  ORDER BY i.submitted_at DESC LIMIT 12"
            ),
        ]);
    }

    /**
     * A single supervisor's work picture — what an inspector reviews before
     * deciding what to verify in the field.
     */
    public function supervisor(Request $request): void
    {
        $id = $request->paramInt('id');
        $date = (string) $request->query('date', today());

        $workload = Inspections::supervisorWorkload($id, $date);
        $this->assertBranch((int) $workload['supervisor']['branch_id']);

        $this->page('admin.inspections.supervisor', array_merge($workload, [
            'title' => 'Field work: ' . $workload['supervisor']['name'],
        ]));
    }

    /**
     * Start form: choose what is being inspected.
     */
    public function create(Request $request): void
    {
        $bcSupervisorId = (int) $request->query('bc_supervisor_id', 0);

        $supervisor = null;
        $existingThisMonth = null;
        $monthAnchor = date('Y-m-01');

        if ($bcSupervisorId > 0) {
            $supervisor = Database::selectOne(
                'SELECT s.*, u.name, b.name AS branch_name
                   FROM bc_supervisors s JOIN users u ON u.id = s.user_id JOIN branches b ON b.id = s.branch_id
                  WHERE s.id = :id',
                ['id' => $bcSupervisorId]
            );

            if ($supervisor === null) {
                $this->abort(404, 'BCA not found.');
            }

            $this->assertBranch((int) $supervisor['branch_id']);

            // Once a month is the expectation, so the screen has to say when that month is
            // already accounted for. The most recent one is the one worth offering: after a
            // Poor grade a second visit is legitimate, and the inspector wants the latest.
            $existingThisMonth = Database::selectOne(
                "SELECT id, inspection_date, status, result
                   FROM inspections
                  WHERE bc_supervisor_id = :bc
                    AND inspection_date >= :month
                    AND inspection_date < DATE_ADD(:month, INTERVAL 1 MONTH)
               ORDER BY inspection_date DESC, id DESC
                  LIMIT 1",
                ['bc' => $bcSupervisorId, 'month' => $monthAnchor]
            );
        }

        $this->page('admin.inspections.create', [
            'title' => 'Start inspection',
            'supervisors' => $this->supervisorOptions(),
            'supervisor' => $supervisor,
            'existingThisMonth' => $existingThisMonth,
            'monthLabel' => date('F Y', (int) strtotime($monthAnchor)),
            'form' => Forms::defaultForm(Forms::KIND_INSPECTION),
        ]);
    }

    public function store(Request $request): void
    {
        $inspection = (new Inspections())->start($request->all());

        $this->success('Inspection started. Capture photographs and complete the form.');
        $this->redirect('/admin/inspections/' . (int) $inspection['id'] . '/edit');
    }

    /**
     * The working screen: GPS, photos, questionnaire, result.
     */
    public function edit(Request $request): void
    {
        $id = $request->paramInt('id');
        $detail = Inspections::detail($id);
        $inspection = $detail['inspection'];

        $this->assertBranch((int) $inspection['branch_id']);

        if ((string) $inspection['status'] === 'submitted') {
            $this->info('That inspection has already been submitted.');
            $this->redirect('/admin/inspections/' . $id);

            return;
        }

        $formId = $inspection['form_id'] === null ? null : (int) $inspection['form_id'];

        $fields = $formId === null ? [] : Forms::fields(Forms::KIND_INSPECTION, $formId);

        $this->page('admin.inspections.edit', array_merge($detail, [
            'title' => 'Inspection in progress',
            'fields' => $fields,
            // What the system already knows: the BCA's own record, and the standing facts this
            // outlet gave last month. The inspector edits rather than retypes.
            'prefill' => Inspections::prefill(
                (int) $inspection['bc_supervisor_id'],
                $fields,
                $detail['answers'] ?? []
            ),
            'minPhotos' => setting('min_inspection_photos', 1),
        ]));
    }

    public function uploadPhoto(Request $request): void
    {
        $id = $request->paramInt('id');
        $inspection = Database::selectOne('SELECT * FROM inspections WHERE id = :id', ['id' => $id]);

        if ($inspection === null) {
            $this->abort(404, 'Inspection not found.');
        }

        $this->assertBranch((int) $inspection['branch_id']);

        $file = $request->file('photo');

        if ($file === null) {
            $this->error('Choose a photograph to upload.');
            $this->back('/admin/inspections/' . $id . '/edit');

            return;
        }

        (new Photos())->storeForInspection($id, $file, [
            'photo_type' => (string) $request->input('photo_type', 'other'),
            'caption' => (string) $request->input('caption', ''),
            'latitude' => $request->input('latitude'),
            'longitude' => $request->input('longitude'),
            'accuracy' => $request->input('accuracy'),
            'address' => $request->input('address'),
            'captured_at' => now(),
        ]);

        $this->success('Photograph added and watermarked.');
        $this->back('/admin/inspections/' . $id . '/edit');
    }

    public function submit(Request $request): void
    {
        $id = $request->paramInt('id');
        $inspection = Database::selectOne('SELECT * FROM inspections WHERE id = :id', ['id' => $id]);

        if ($inspection === null) {
            $this->abort(404, 'Inspection not found.');
        }

        $this->assertBranch((int) $inspection['branch_id']);

        (new Inspections())->submit($id, $request->all());

        $this->success('Inspection submitted. The BCA has been notified of the result.');
        $this->redirect('/admin/inspections/' . $id);
    }

    public function show(Request $request): void
    {
        $id = $request->paramInt('id');
        $detail = Inspections::detail($id);

        $this->assertBranch((int) $detail['inspection']['branch_id']);

        $this->page('admin.inspections.show', array_merge($detail, [
            'title' => 'BCA Inspection Report',
        ]));
    }

    public function pdf(Request $request): void
    {
        $id = $request->paramInt('id');
        $detail = Inspections::detail($id);

        $this->assertBranch((int) $detail['inspection']['branch_id']);

        $file = RecordExport::inspectionPdf($id);

        Response::download($file['path'], $file['file_name'], 'application/pdf');
    }

    /**
     * Discard a draft inspection that was started by mistake.
     */
    public function destroy(Request $request): void
    {
        $id = $request->paramInt('id');
        $inspection = Database::selectOne('SELECT * FROM inspections WHERE id = :id', ['id' => $id]);

        if ($inspection === null) {
            $this->abort(404, 'Inspection not found.');
        }

        if ((string) $inspection['status'] !== 'draft') {
            $this->error('A submitted inspection cannot be deleted — it is part of the audit record.');
            $this->back('/admin/inspections');

            return;
        }

        if ((int) $inspection['admin_user_id'] !== (int) auth_id()) {
            $this->abort(403, 'Only the inspector who started a draft may discard it.');
        }

        Database::delete('inspections', 'id = :id', ['id' => $id]);

        $this->success('Draft inspection discarded.');
        $this->redirect('/admin/inspections');
    }

    /**
     * The full inspection register, powered by the report engine.
     */
    public function register(Request $request): void
    {
        $this->redirect('/admin/reports/bc_inspection' . query_string());
    }
}
