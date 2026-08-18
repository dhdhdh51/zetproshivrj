<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Core\Database;
use App\Core\Request;
use App\Services\Allocation;
use App\Services\Dashboard;
use App\Services\Inspections;
use App\Services\Notify;

final class DashboardController extends BaseController
{
    public function index(Request $request): void
    {
        $summary = Dashboard::summary();

        $this->page('admin.dashboard', [
            'title' => 'Dashboard',
            'summary' => $summary,
            'trend' => Dashboard::trend(null, 14),
            'coverage' => Inspections::coverage(),
            'distribution' => array_slice(Allocation::distribution(), 0, 8),
            'activity' => Dashboard::activity(null, 12),
            'topBranches' => Database::select(
                "SELECT b.name, b.code,
                        (SELECT COUNT(*) FROM visits v
                          WHERE v.branch_id = b.id AND v.visit_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                            AND v.status <> 'draft') AS visits,
                        (SELECT COALESCE(SUM(r.amount), 0) FROM recoveries r
                          WHERE r.branch_id = b.id AND r.recovery_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                            AND r.status <> 'rejected') AS recovered
                   FROM branches b
                  WHERE b.status = 'active'
                  ORDER BY recovered DESC, visits DESC
                  LIMIT 6"
            ),
            'pendingLate' => Database::select(
                "SELECT s.id AS submission_id, s.report_date, s.submitted_at, s.late_reason,
                        u.name AS supervisor_name, bs.bc_code, b.name AS branch_name
                   FROM report_submissions s
                   JOIN bc_supervisors bs ON bs.id = s.bc_supervisor_id
                   JOIN users u ON u.id = bs.user_id
                   JOIN branches b ON b.id = s.branch_id
                  WHERE s.status = 'late_pending'
                  ORDER BY s.submitted_at ASC
                  LIMIT 5"
            ),
        ]);
    }

    public function notifications(Request $request): void
    {
        $user = auth_user() ?? [];

        $this->page('admin.notifications', [
            'title' => 'Notifications',
            'notifications' => Notify::forUser($user, 60),
            'unread' => Notify::unreadCount($user),
        ]);
    }

    public function readNotification(Request $request): void
    {
        Notify::markRead($request->paramInt('id'), auth_user() ?? []);
        $this->back('/admin/notifications');
    }

    public function readAllNotifications(Request $request): void
    {
        $count = Notify::markAllRead(auth_user() ?? []);
        $this->success($count > 0 ? $count . ' notification(s) marked as read.' : 'Nothing unread.');
        $this->back('/admin/notifications');
    }
}
