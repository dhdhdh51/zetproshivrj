<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Core\Acl;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\Export\RecordExport;
use App\Services\Forms;
use App\Services\Visits;

/**
 * TYPE A records as seen from the office: the Customer Visit Report, its
 * evidence, and the approve / reject review actions.
 */
final class VisitController extends BaseController
{
    public function show(Request $request): void
    {
        Acl::authorize('visits.view');

        $id = $request->paramInt('id');

        $visit = Database::selectOne(
            'SELECT v.*, a.account_number, a.cif, a.borrower_name, a.father_name, a.mobile, a.village,
                    a.address, a.loan_type, a.outstanding, a.overdue, a.limit_amount, a.npa_date,
                    b.name AS branch_name, b.code AS branch_code,
                    u.name AS supervisor_name, s.bc_code, s.id AS supervisor_id, s.mobile AS supervisor_mobile,
                    f.name AS form_name, approver.name AS approver_name,
                    d.model AS device_model, d.app_version
               FROM visits v
               JOIN loan_accounts a ON a.id = v.loan_account_id
               JOIN branches b ON b.id = v.branch_id
               JOIN bc_supervisors s ON s.id = v.bc_supervisor_id
               JOIN users u ON u.id = s.user_id
          LEFT JOIN visit_forms f ON f.id = v.form_id
          LEFT JOIN users approver ON approver.id = v.approved_by
          LEFT JOIN devices d ON d.id = v.device_id
              WHERE v.id = :id',
            ['id' => $id]
        );

        if ($visit === null) {
            $this->abort(404, 'Visit not found.');
        }

        $this->assertBranch((int) $visit['branch_id']);

        $this->page('admin.visits.show', [
            'title' => 'Visit report',
            'visit' => $visit,
            'answers' => Forms::values(Forms::KIND_VISIT, $id),
            'photos' => Database::select('SELECT * FROM visit_photos WHERE visit_id = :id ORDER BY id', ['id' => $id]),
            'points' => Database::select('SELECT * FROM visit_gps WHERE visit_id = :id ORDER BY id', ['id' => $id]),
            'recoveries' => Database::select('SELECT * FROM recoveries WHERE visit_id = :id ORDER BY id', ['id' => $id]),
            'promises' => Database::select('SELECT * FROM promises WHERE visit_id = :id ORDER BY id', ['id' => $id]),
            'followups' => Database::select('SELECT * FROM followups WHERE visit_id = :id ORDER BY id', ['id' => $id]),
            'inspections' => Database::select(
                'SELECT i.*, u.name AS inspector_name
                   FROM inspections i JOIN users u ON u.id = i.admin_user_id
                  WHERE i.visit_id = :id ORDER BY i.id DESC',
                ['id' => $id]
            ),
            'canReview' => Auth::isAdmin(),
        ]);
    }

    public function pdf(Request $request): void
    {
        Acl::authorize('reports.export');

        $id = $request->paramInt('id');
        $branchId = (int) Database::scalar('SELECT branch_id FROM visits WHERE id = :id', ['id' => $id]);

        if ($branchId === 0) {
            $this->abort(404, 'Visit not found.');
        }

        $this->assertBranch($branchId);

        $file = RecordExport::visitPdf($id);

        Response::download($file['path'], $file['file_name'], 'application/pdf');
    }

    public function approve(Request $request): void
    {
        $id = $request->paramInt('id');
        $branchId = (int) Database::scalar('SELECT branch_id FROM visits WHERE id = :id', ['id' => $id]);
        $this->assertBranch($branchId);

        Visits::approve($id, trim((string) $request->input('remarks', '')));

        $this->success('Visit approved.');
        $this->back('/admin/visits/' . $id);
    }

    public function reject(Request $request): void
    {
        $id = $request->paramInt('id');
        $branchId = (int) Database::scalar('SELECT branch_id FROM visits WHERE id = :id', ['id' => $id]);
        $this->assertBranch($branchId);

        $data = $this->validate($request, ['reason' => 'required|max:255']);

        Visits::reject($id, (string) $data['reason']);

        $this->success('Visit rejected and the BC Supervisor notified.');
        $this->back('/admin/visits/' . $id);
    }
}
