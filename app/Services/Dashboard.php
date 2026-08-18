<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Settings;

/**
 * Dashboard aggregates for the Admin/Supervisor panel and the Branch Manager
 * portal. Both use the same queries with an optional branch constraint, so the
 * numbers a manager sees always reconcile with the organisation-wide totals.
 */
final class Dashboard
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(?int $branchId = null): array
    {
        $branchClause = $branchId === null ? '' : ' AND branch_id = :branch';
        $params = $branchId === null ? [] : ['branch' => $branchId];

        $today = today();
        $monthStart = date('Y-m-01');

        $accounts = Database::selectOne(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(outstanding), 0) AS outstanding,
                    COALESCE(SUM(overdue), 0) AS overdue,
                    COALESCE(SUM(total_recovered), 0) AS recovered,
                    SUM(loan_category = 'krm_ots') AS krm_ots,
                    SUM(loan_category = 'ckcc_od2') AS ckcc_od2
               FROM loan_accounts
              WHERE status = 'active'" . $branchClause,
            $params
        ) ?? [];

        $assigned = (int) Database::scalar(
            "SELECT COUNT(*) FROM loan_accounts a
               JOIN account_assignments x ON x.loan_account_id = a.id AND x.is_active = 1
              WHERE a.status = 'active'" . ($branchId === null ? '' : ' AND a.branch_id = :branch'),
            $params
        );

        $visitsToday = (int) Database::scalar(
            "SELECT COUNT(*) FROM visits WHERE visit_date = :today AND status <> 'draft'" . $branchClause,
            $params + ['today' => $today]
        );

        $visitsMonth = (int) Database::scalar(
            "SELECT COUNT(*) FROM visits
              WHERE visit_date BETWEEN :start AND :today AND status <> 'draft'" . $branchClause,
            $params + ['start' => $monthStart, 'today' => $today]
        );

        $recoveryToday = (float) Database::scalar(
            "SELECT COALESCE(SUM(amount), 0) FROM recoveries
              WHERE recovery_date = :today AND status <> 'rejected'" . $branchClause,
            $params + ['today' => $today]
        );

        $recoveryMonth = (float) Database::scalar(
            "SELECT COALESCE(SUM(amount), 0) FROM recoveries
              WHERE recovery_date BETWEEN :start AND :today AND status <> 'rejected'" . $branchClause,
            $params + ['start' => $monthStart, 'today' => $today]
        );

        $promises = Database::selectOne(
            "SELECT COUNT(*) AS total,
                    SUM(status = 'pending') AS pending,
                    SUM(status = 'kept') AS kept,
                    SUM(status = 'broken') AS broken,
                    COALESCE(SUM(CASE WHEN status = 'pending' THEN promise_amount ELSE 0 END), 0) AS pending_amount
               FROM promises
              WHERE 1 = 1" . $branchClause,
            $params
        ) ?? [];

        $inspections = Database::selectOne(
            "SELECT SUM(status = 'submitted') AS completed,
                    SUM(status = 'draft') AS pending,
                    SUM(status = 'submitted' AND result <> 'work_verified') AS adverse,
                    SUM(status = 'submitted' AND inspection_date = :today) AS today
               FROM inspections
              WHERE inspection_date >= :start" . $branchClause,
            $params + ['start' => $monthStart, 'today' => $today]
        ) ?? [];

        $offlineMinutes = max(1, Settings::int('supervisor_offline_minutes', 15));

        $supervisors = Database::selectOne(
            "SELECT COUNT(*) AS total,
                    SUM(s.status = 'active') AS active,
                    SUM(EXISTS (
                        SELECT 1 FROM devices d
                         WHERE d.user_id = s.user_id AND d.status = 'active'
                           AND d.last_seen_at >= DATE_SUB(NOW(), INTERVAL {$offlineMinutes} MINUTE)
                    )) AS online
               FROM bc_supervisors s
               JOIN users u ON u.id = s.user_id
              WHERE u.status = 'active'" . ($branchId === null ? '' : ' AND s.branch_id = :branch'),
            $params
        ) ?? [];

        $reportStatus = Database::selectOne(
            "SELECT SUM(status IN ('submitted','late_approved')) AS submitted,
                    SUM(status = 'late_pending') AS late_pending,
                    SUM(status = 'pending') AS pending,
                    SUM(status = 'locked') AS locked
               FROM report_submissions
              WHERE report_date = :today" . $branchClause,
            $params + ['today' => $today]
        ) ?? [];

        $krmOts = Database::selectOne(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(ots_amount), 0) AS amount,
                    SUM(ots_status IN ('proposed','under_review')) AS open,
                    SUM(ots_status = 'approved') AS approved,
                    SUM(ots_status IN ('paid','closed')) AS closed
               FROM krm_ots_cases WHERE 1 = 1" . $branchClause,
            $params
        ) ?? [];

        $ckcc = Database::selectOne(
            "SELECT COUNT(*) AS total,
                    SUM(renewal_status IN ('pending','documents_awaited','submitted')) AS open,
                    SUM(renewal_status = 'renewed') AS renewed
               FROM ckcc_renewals WHERE 1 = 1" . $branchClause,
            $params
        ) ?? [];

        return [
            'branches' => $branchId === null
                ? (int) Database::scalar("SELECT COUNT(*) FROM branches WHERE status = 'active'")
                : 1,
            'branch_managers' => (int) Database::scalar(
                "SELECT COUNT(*) FROM branch_managers m JOIN users u ON u.id = m.user_id
                  WHERE m.status = 'active' AND u.status = 'active'"
                . ($branchId === null ? '' : ' AND m.branch_id = :branch'),
                $params
            ),
            'supervisors' => (int) ($supervisors['total'] ?? 0),
            'supervisors_active' => (int) ($supervisors['active'] ?? 0),
            'supervisors_online' => (int) ($supervisors['online'] ?? 0),
            'supervisors_offline' => max(0, (int) ($supervisors['active'] ?? 0) - (int) ($supervisors['online'] ?? 0)),

            'accounts' => (int) ($accounts['total'] ?? 0),
            'accounts_assigned' => $assigned,
            'accounts_unassigned' => max(0, (int) ($accounts['total'] ?? 0) - $assigned),
            'outstanding' => (float) ($accounts['outstanding'] ?? 0),
            'overdue' => (float) ($accounts['overdue'] ?? 0),
            'recovered_total' => (float) ($accounts['recovered'] ?? 0),
            'krm_accounts' => (int) ($accounts['krm_ots'] ?? 0),
            'ckcc_accounts' => (int) ($accounts['ckcc_od2'] ?? 0),

            'visits_today' => $visitsToday,
            'visits_month' => $visitsMonth,
            'recovery_today' => $recoveryToday,
            'recovery_month' => $recoveryMonth,

            'promises_total' => (int) ($promises['total'] ?? 0),
            'promises_pending' => (int) ($promises['pending'] ?? 0),
            'promises_kept' => (int) ($promises['kept'] ?? 0),
            'promises_broken' => (int) ($promises['broken'] ?? 0),
            'promises_pending_amount' => (float) ($promises['pending_amount'] ?? 0),

            'inspections_completed' => (int) ($inspections['completed'] ?? 0),
            'inspections_pending' => (int) ($inspections['pending'] ?? 0),
            'inspections_adverse' => (int) ($inspections['adverse'] ?? 0),
            'inspections_today' => (int) ($inspections['today'] ?? 0),

            'reports_submitted' => (int) ($reportStatus['submitted'] ?? 0),
            'reports_late_pending' => (int) ($reportStatus['late_pending'] ?? 0),
            'reports_pending' => (int) ($reportStatus['pending'] ?? 0),
            'reports_locked' => (int) ($reportStatus['locked'] ?? 0),

            'krm_total' => (int) ($krmOts['total'] ?? 0),
            'krm_open' => (int) ($krmOts['open'] ?? 0),
            'krm_amount' => (float) ($krmOts['amount'] ?? 0),
            'ckcc_total' => (int) ($ckcc['total'] ?? 0),
            'ckcc_open' => (int) ($ckcc['open'] ?? 0),
            'ckcc_renewed' => (int) ($ckcc['renewed'] ?? 0),

            'deadline' => Deadline::status(),
        ];
    }

    /**
     * Recovery and visit trend for the last N days, for the dashboard chart.
     *
     * @return array<int, array{date:string, visits:int, recovered:float}>
     */
    public static function trend(?int $branchId = null, int $days = 14): array
    {
        $from = date('Y-m-d', strtotime('-' . max(1, $days - 1) . ' days'));
        $params = ['from' => $from, 'to' => today()];
        $branchClause = '';

        if ($branchId !== null) {
            $branchClause = ' AND branch_id = :branch';
            $params['branch'] = $branchId;
        }

        $visits = Database::select(
            "SELECT visit_date AS d, COUNT(*) AS c FROM visits
              WHERE visit_date BETWEEN :from AND :to AND status <> 'draft'" . $branchClause
            . ' GROUP BY visit_date',
            $params
        );

        $recoveries = Database::select(
            "SELECT recovery_date AS d, SUM(amount) AS c FROM recoveries
              WHERE recovery_date BETWEEN :from AND :to AND status <> 'rejected'" . $branchClause
            . ' GROUP BY recovery_date',
            $params
        );

        $visitMap = array_column($visits, 'c', 'd');
        $recoveryMap = array_column($recoveries, 'c', 'd');

        $series = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime('-' . $i . ' days'));
            $series[] = [
                'date' => $date,
                'visits' => (int) ($visitMap[$date] ?? 0),
                'recovered' => (float) ($recoveryMap[$date] ?? 0),
            ];
        }

        return $series;
    }

    /**
     * Live-ish monitoring rows: last seen, last known village, today's work.
     *
     * Deliberately labelled "last known": when a device is offline or location
     * permission is off, LRMS shows the last recorded position and its age rather
     * than pretending to track continuously.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function monitoring(?int $branchId = null): array
    {
        $offlineMinutes = max(1, Settings::int('supervisor_offline_minutes', 15));
        $params = ['today' => today()];
        $where = ["u.status = 'active'"];

        if ($branchId !== null) {
            $where[] = 's.branch_id = :branch';
            $params['branch'] = $branchId;
        }

        return Database::select(
            "SELECT s.id, s.bc_code, s.status, u.name, u.mobile, b.name AS branch_name,
                    d.id AS device_id, d.model, d.app_version, d.last_seen_at,
                    d.last_latitude, d.last_longitude, d.last_location_at, d.last_address,
                    (d.last_seen_at >= DATE_SUB(NOW(), INTERVAL {$offlineMinutes} MINUTE)) AS is_online,
                    (SELECT COUNT(*) FROM visits v
                      WHERE v.bc_supervisor_id = s.id AND v.visit_date = :today AND v.status <> 'draft') AS visits_today,
                    (SELECT COALESCE(SUM(r.amount), 0) FROM recoveries r
                      WHERE r.bc_supervisor_id = s.id AND r.recovery_date = :today AND r.status <> 'rejected') AS recovery_today,
                    (SELECT COUNT(*) FROM account_assignments x
                      WHERE x.bc_supervisor_id = s.id AND x.is_active = 1) AS allocated,
                    (SELECT a.village FROM visits v
                       JOIN loan_accounts a ON a.id = v.loan_account_id
                      WHERE v.bc_supervisor_id = s.id AND v.status <> 'draft'
                      ORDER BY v.submitted_at DESC LIMIT 1) AS last_village,
                    (SELECT v.submitted_at FROM visits v
                      WHERE v.bc_supervisor_id = s.id AND v.status <> 'draft'
                      ORDER BY v.submitted_at DESC LIMIT 1) AS last_visit_at,
                    t.check_in_at, t.check_out_at, t.status AS attendance_status,
                    rs.status AS report_status
               FROM bc_supervisors s
               JOIN users u ON u.id = s.user_id
               JOIN branches b ON b.id = s.branch_id
          LEFT JOIN devices d ON d.id = (
                    SELECT id FROM devices WHERE user_id = s.user_id AND status = 'active'
                    ORDER BY last_seen_at DESC LIMIT 1
               )
          LEFT JOIN attendance t ON t.bc_supervisor_id = s.id AND t.attendance_date = :today
          LEFT JOIN report_submissions rs ON rs.bc_supervisor_id = s.id AND rs.report_date = :today
              WHERE " . implode(' AND ', $where)
            . ' ORDER BY is_online DESC, visits_today DESC, u.name ASC',
            $params
        );
    }

    /**
     * Recent activity feed for the dashboard.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function activity(?int $branchId = null, int $limit = 12): array
    {
        $limit = max(1, min(50, $limit));

        if ($branchId === null) {
            return Database::select(
                'SELECT action, user_name, role_slug, description, entity_type, entity_id, created_at
                   FROM audit_logs
                  ORDER BY id DESC
                  LIMIT ' . $limit
            );
        }

        // Branch scoped feed: visits, recoveries and inspections of that branch.
        return Database::select(
            "SELECT 'visit_submitted' AS action, u.name AS user_name, 'bc_supervisor' AS role_slug,
                    CONCAT('Visit at ', a.borrower_name, ' (', a.account_number, ')') AS description,
                    'visit' AS entity_type, v.id AS entity_id, v.submitted_at AS created_at
               FROM visits v
               JOIN loan_accounts a ON a.id = v.loan_account_id
               JOIN bc_supervisors s ON s.id = v.bc_supervisor_id
               JOIN users u ON u.id = s.user_id
              WHERE v.branch_id = :branch AND v.status <> 'draft'
              UNION ALL
             SELECT 'recovery_recorded', u2.name, 'bc_supervisor',
                    CONCAT('Recovery of ', FORMAT(r.amount, 2), ' from ', a2.borrower_name),
                    'recovery', r.id, r.created_at
               FROM recoveries r
               JOIN loan_accounts a2 ON a2.id = r.loan_account_id
          LEFT JOIN bc_supervisors s2 ON s2.id = r.bc_supervisor_id
          LEFT JOIN users u2 ON u2.id = s2.user_id
              WHERE r.branch_id = :branch
              UNION ALL
             SELECT 'inspection_submitted', u3.name, 'admin',
                    CONCAT('Inspection: ', i.result), 'inspection', i.id, i.submitted_at
               FROM inspections i
               JOIN users u3 ON u3.id = i.admin_user_id
              WHERE i.branch_id = :branch AND i.status = 'submitted'
              ORDER BY created_at DESC
              LIMIT " . $limit,
            ['branch' => $branchId]
        );
    }
}
