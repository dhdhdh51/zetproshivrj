<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Core\Database;
use App\Core\Request;
use App\Core\Settings;
use App\Services\Audit;
use App\Services\Deadline;

/**
 * Report deadline configuration and the late-submission approval queue.
 *
 * Server time is authoritative throughout: the device clock cannot influence
 * whether a submission counts as late.
 */
final class DeadlineController extends BaseController
{
    public function index(Request $request): void
    {
        $date = (string) $request->query('date', today());

        $this->page('admin.deadline.index', [
            'title' => 'Report deadline',
            'status' => Deadline::status($date),
            'date' => $date,
            'submissions' => Database::select(
                'SELECT s.*, u.name AS supervisor_name, bs.bc_code, b.name AS branch_name,
                        approver.name AS approver_name
                   FROM report_submissions s
                   JOIN bc_supervisors bs ON bs.id = s.bc_supervisor_id
                   JOIN users u ON u.id = bs.user_id
                   JOIN branches b ON b.id = s.branch_id
              LEFT JOIN users approver ON approver.id = s.approved_by
                  WHERE s.report_date = :date
                  ORDER BY b.name, u.name',
                ['date' => $date]
            ),
            'missing' => Database::select(
                "SELECT bs.id, bs.bc_code, u.name, b.name AS branch_name
                   FROM bc_supervisors bs
                   JOIN users u ON u.id = bs.user_id
                   JOIN branches b ON b.id = bs.branch_id
                  WHERE bs.status = 'active' AND u.status = 'active'
                    AND NOT EXISTS (
                        SELECT 1 FROM report_submissions s
                         WHERE s.bc_supervisor_id = bs.id AND s.report_date = :date
                    )
                  ORDER BY b.name, u.name",
                ['date' => $date]
            ),
        ]);
    }

    public function save(Request $request): void
    {
        $data = $this->validate($request, [
            'report_deadline_time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'allow_late_submission_requests' => 'nullable|boolean',
        ], [], '/admin/deadline');

        $days = $request->raw('working_days');
        $days = is_array($days) ? array_values(array_filter(array_map('intval', $days), static fn (int $d): bool => $d >= 1 && $d <= 7)) : [];

        if ($days === []) {
            $this->error('Select at least one working day.');
            $this->back('/admin/deadline');

            return;
        }

        $reminders = trim((string) $request->input('report_reminder_minutes', '60,30,10'));
        $reminders = implode(',', array_filter(array_map('intval', explode(',', $reminders)), static fn (int $m): bool => $m > 0));

        $before = [
            'report_deadline_time' => Settings::string('report_deadline_time'),
            'report_working_days' => Settings::string('report_working_days'),
            'report_reminder_minutes' => Settings::string('report_reminder_minutes'),
            'allow_late_submission_requests' => Settings::string('allow_late_submission_requests'),
        ];

        Settings::setMany([
            'report_deadline_time' => (string) $data['report_deadline_time'],
            'report_working_days' => implode(',', $days),
            'report_reminder_minutes' => $reminders !== '' ? $reminders : '60,30,10',
            'allow_late_submission_requests' => $request->boolean('allow_late_submission_requests') ? '1' : '0',
        ], 'reports');

        Audit::log(Audit::DEADLINE_CHANGED, [
            'entity_type' => 'system_settings',
            'description' => sprintf(
                'Report deadline set to %s on working days %s.',
                $data['report_deadline_time'],
                implode(',', $days)
            ),
            'old' => $before,
            'new' => [
                'report_deadline_time' => $data['report_deadline_time'],
                'report_working_days' => implode(',', $days),
                'report_reminder_minutes' => $reminders,
                'allow_late_submission_requests' => $request->boolean('allow_late_submission_requests') ? '1' : '0',
            ],
        ]);

        $this->success('Deadline settings saved. They apply from the next submission onwards.');
        $this->redirect('/admin/deadline');
    }

    /**
     * The approval queue for reports submitted after the deadline.
     */
    public function late(Request $request): void
    {
        $this->page('admin.deadline.late', [
            'title' => 'Late submissions',
            'pending' => Database::select(
                "SELECT s.*, u.name AS supervisor_name, bs.bc_code, b.name AS branch_name
                   FROM report_submissions s
                   JOIN bc_supervisors bs ON bs.id = s.bc_supervisor_id
                   JOIN users u ON u.id = bs.user_id
                   JOIN branches b ON b.id = s.branch_id
                  WHERE s.status = 'late_pending'
                  ORDER BY s.submitted_at ASC"
            ),
            'decided' => Database::select(
                "SELECT s.*, u.name AS supervisor_name, bs.bc_code, b.name AS branch_name,
                        approver.name AS approver_name
                   FROM report_submissions s
                   JOIN bc_supervisors bs ON bs.id = s.bc_supervisor_id
                   JOIN users u ON u.id = bs.user_id
                   JOIN branches b ON b.id = s.branch_id
              LEFT JOIN users approver ON approver.id = s.approved_by
                  WHERE s.status IN ('late_approved','late_rejected')
                  ORDER BY s.approved_at DESC
                  LIMIT 25"
            ),
        ]);
    }

    public function decide(Request $request): void
    {
        $id = $request->paramInt('id');
        $approve = $request->input('decision') === 'approve';
        $remarks = trim((string) $request->input('remarks', ''));

        if (!$approve && $remarks === '') {
            $this->error('Give a reason when rejecting a late submission.');
            $this->back('/admin/deadline/late');

            return;
        }

        if (!Deadline::decideLate($id, $approve, $remarks)) {
            $this->error('That submission is no longer awaiting a decision.');
            $this->back('/admin/deadline/late');

            return;
        }

        $this->success($approve ? 'Late submission approved.' : 'Late submission rejected.');
        $this->back('/admin/deadline/late');
    }

    /**
     * Lock every still-unsubmitted report for a past date, so the register is
     * closed rather than left ambiguous.
     */
    public function lock(Request $request): void
    {
        $date = (string) $request->input('date', today());
        $locked = Deadline::lockOverdue($date);

        $this->success($locked > 0
            ? sprintf('%d pending report(s) for %s locked.', $locked, format_date($date))
            : 'Nothing to lock for that date.');
        $this->back('/admin/deadline');
    }
}
