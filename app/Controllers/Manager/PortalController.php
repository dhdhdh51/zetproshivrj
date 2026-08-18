<?php

declare(strict_types=1);

namespace App\Controllers\Manager;

use App\Controllers\BaseController;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Services\Dashboard;
use App\Services\Inspections;
use App\Services\Notify;

/**
 * Branch Manager portal.
 *
 * Every query here is constrained to Auth::branchId(); the middleware refuses a
 * manager without a branch, and Acl::branchScope() is used for the shared report
 * engine so a manager physically cannot read another branch's rows.
 */
final class PortalController extends BaseController
{
    public function dashboard(Request $request): void
    {
        $branchId = (int) Auth::branchId();

        $branch = Database::selectOne('SELECT * FROM branches WHERE id = :id', ['id' => $branchId]);

        $this->page('manager.dashboard', [
            'title' => 'Branch dashboard',
            'branch' => $branch,
            'summary' => Dashboard::summary($branchId),
            'trend' => Dashboard::trend($branchId, 14),
            'coverage' => Inspections::coverage($branchId),
            'supervisors' => Database::select(
                "SELECT s.id, s.bc_code, u.name, s.status,
                        (SELECT COUNT(*) FROM account_assignments x WHERE x.bc_supervisor_id = s.id AND x.is_active = 1) AS accounts,
                        (SELECT COUNT(*) FROM visits v WHERE v.bc_supervisor_id = s.id AND v.visit_date = CURDATE()
                           AND v.status <> 'draft') AS visits_today,
                        (SELECT COALESCE(SUM(r.amount), 0) FROM recoveries r
                          WHERE r.bc_supervisor_id = s.id AND r.recovery_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                            AND r.status <> 'rejected') AS recovery_month,
                        t.check_in_at, t.check_out_at, t.status AS attendance_status,
                        rs.status AS report_status
                   FROM bc_supervisors s
                   JOIN users u ON u.id = s.user_id
              LEFT JOIN attendance t ON t.bc_supervisor_id = s.id AND t.attendance_date = CURDATE()
              LEFT JOIN report_submissions rs ON rs.bc_supervisor_id = s.id AND rs.report_date = CURDATE()
                  WHERE s.branch_id = :branch
                  ORDER BY visits_today DESC, u.name ASC",
                ['branch' => $branchId]
            ),
            'activity' => Dashboard::activity($branchId, 12),
            'pendingAccounts' => (int) Database::scalar(
                "SELECT COUNT(*) FROM loan_accounts a
                  WHERE a.branch_id = :branch AND a.status = 'active' AND a.visit_count = 0",
                ['branch' => $branchId]
            ),
        ]);
    }

    /**
     * BC Supervisors of this branch, read-only.
     */
    public function supervisors(Request $request): void
    {
        $branchId = (int) Auth::branchId();

        $this->page('manager.supervisors', [
            'title' => 'BC supervisors',
            'supervisors' => Database::select(
                "SELECT s.*, u.name, u.email, u.last_login_at,
                        (SELECT COUNT(*) FROM account_assignments x WHERE x.bc_supervisor_id = s.id AND x.is_active = 1) AS accounts,
                        (SELECT COUNT(*) FROM visits v WHERE v.bc_supervisor_id = s.id
                           AND v.visit_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01') AND v.status <> 'draft') AS visits_month,
                        (SELECT COALESCE(SUM(r.amount), 0) FROM recoveries r
                          WHERE r.bc_supervisor_id = s.id AND r.recovery_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                            AND r.status <> 'rejected') AS recovery_month,
                        (SELECT COUNT(*) FROM inspections i WHERE i.bc_supervisor_id = s.id AND i.status = 'submitted'
                           AND i.result <> 'work_verified'
                           AND i.inspection_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) AS adverse_30d,
                        d.last_seen_at, d.model
                   FROM bc_supervisors s
                   JOIN users u ON u.id = s.user_id
              LEFT JOIN devices d ON d.id = (
                        SELECT id FROM devices WHERE user_id = s.user_id AND status = 'active'
                        ORDER BY last_seen_at DESC LIMIT 1
                   )
                  WHERE s.branch_id = :branch
                  ORDER BY u.name",
                ['branch' => $branchId]
            ),
            'offlineMinutes' => (int) setting('supervisor_offline_minutes', 15),
        ]);
    }

    /**
     * Accounts in this branch that have never been visited — the manager's
     * main follow-up list.
     */
    public function pending(Request $request): void
    {
        $branchId = (int) Auth::branchId();
        $page = $this->page_number($request);
        $perPage = 50;

        $sql = "SELECT a.*, s.bc_code, u.name AS supervisor_name
                  FROM loan_accounts a
             LEFT JOIN account_assignments x ON x.loan_account_id = a.id AND x.is_active = 1
             LEFT JOIN bc_supervisors s ON s.id = x.bc_supervisor_id
             LEFT JOIN users u ON u.id = s.user_id
                 WHERE a.branch_id = :branch AND a.status = 'active' AND a.visit_count = 0
                 ORDER BY a.overdue DESC";

        $params = ['branch' => $branchId];
        $total = (int) Database::scalar('SELECT COUNT(*) FROM (' . $sql . ') AS t', $params);

        $this->page('manager.pending', [
            'title' => 'Pending accounts',
            'accounts' => Database::select($sql . sprintf(' LIMIT %d OFFSET %d', $perPage, ($page - 1) * $perPage), $params),
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'lastPage' => max(1, (int) ceil($total / $perPage)),
        ]);
    }

    /**
     * Recovery and PTP position for the branch.
     */
    public function recovery(Request $request): void
    {
        $branchId = (int) Auth::branchId();
        $from = (string) $request->query('from', date('Y-m-01'));
        $to = (string) $request->query('to', today());
        $params = ['branch' => $branchId, 'from' => $from, 'to' => $to];

        $this->page('manager.recovery', [
            'title' => 'Recovery and PTP',
            'from' => $from,
            'to' => $to,
            'summary' => Database::selectOne(
                "SELECT COALESCE(SUM(amount), 0) AS total, COUNT(*) AS entries
                   FROM recoveries
                  WHERE branch_id = :branch AND recovery_date BETWEEN :from AND :to AND status <> 'rejected'",
                $params
            ) ?? [],
            'byMode' => Database::select(
                "SELECT payment_mode, COUNT(*) AS entries, COALESCE(SUM(amount), 0) AS total
                   FROM recoveries
                  WHERE branch_id = :branch AND recovery_date BETWEEN :from AND :to AND status <> 'rejected'
                  GROUP BY payment_mode ORDER BY total DESC",
                $params
            ),
            'bySupervisor' => Database::select(
                "SELECT u.name, s.bc_code,
                        COALESCE(SUM(r.amount), 0) AS total, COUNT(r.id) AS entries
                   FROM bc_supervisors s
                   JOIN users u ON u.id = s.user_id
              LEFT JOIN recoveries r ON r.bc_supervisor_id = s.id
                        AND r.recovery_date BETWEEN :from AND :to AND r.status <> 'rejected'
                  WHERE s.branch_id = :branch
                  GROUP BY s.id, u.name, s.bc_code
                  ORDER BY total DESC",
                $params
            ),
            'promises' => Database::select(
                "SELECT p.*, a.account_number, a.borrower_name, u.name AS supervisor_name
                   FROM promises p
                   JOIN loan_accounts a ON a.id = p.loan_account_id
              LEFT JOIN bc_supervisors s ON s.id = p.bc_supervisor_id
              LEFT JOIN users u ON u.id = s.user_id
                  WHERE p.branch_id = :branch AND p.status = 'pending'
                  ORDER BY p.promise_date ASC
                  LIMIT 50",
                ['branch' => $branchId]
            ),
        ]);
    }

    /**
     * Per-supervisor performance for the branch.
     */
    public function performance(Request $request): void
    {
        $this->redirect('/manager/reports/bc_performance' . query_string());
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
        $this->back('/manager/notifications');
    }

    public function readAllNotifications(Request $request): void
    {
        Notify::markAllRead(auth_user() ?? []);
        $this->success('All notifications marked as read.');
        $this->back('/manager/notifications');
    }
}
