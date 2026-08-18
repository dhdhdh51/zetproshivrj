<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Core\Database;
use App\Core\Request;
use App\Services\Audit;
use App\Services\Notify;

/**
 * Daily and monthly visit / recovery targets for supervisors and branches,
 * with achievement computed from the actual visit and recovery records.
 */
final class TargetController extends BaseController
{
    public function index(Request $request): void
    {
        $period = (string) $request->query('period', 'monthly');
        $period = in_array($period, ['daily', 'monthly'], true) ? $period : 'monthly';

        $periodStart = $period === 'daily'
            ? (string) $request->query('period_start', today())
            : (string) $request->query('period_start', date('Y-m-01'));

        $timestamp = strtotime($periodStart) ?: time();
        $periodStart = $period === 'daily' ? date('Y-m-d', $timestamp) : date('Y-m-01', $timestamp);
        $periodEnd = $period === 'daily' ? $periodStart : date('Y-m-t', $timestamp);

        $targets = Database::select(
            "SELECT t.*,
                    COALESCE(u.name, CONCAT('Branch: ', b.name)) AS subject,
                    s.bc_code, b.name AS branch_name,
                    (SELECT COUNT(*) FROM visits v
                      WHERE v.status <> 'draft'
                        AND v.visit_date BETWEEN t.period_start AND t.period_end
                        AND ((t.scope = 'bc_supervisor' AND v.bc_supervisor_id = t.bc_supervisor_id)
                          OR (t.scope = 'branch' AND v.branch_id = t.branch_id))) AS visits_done,
                    (SELECT COALESCE(SUM(r.amount), 0) FROM recoveries r
                      WHERE r.status <> 'rejected'
                        AND r.recovery_date BETWEEN t.period_start AND t.period_end
                        AND ((t.scope = 'bc_supervisor' AND r.bc_supervisor_id = t.bc_supervisor_id)
                          OR (t.scope = 'branch' AND r.branch_id = t.branch_id))) AS recovery_done
               FROM targets t
          LEFT JOIN bc_supervisors s ON s.id = t.bc_supervisor_id
          LEFT JOIN users u ON u.id = s.user_id
          LEFT JOIN branches b ON b.id = COALESCE(t.branch_id, s.branch_id)
              WHERE t.period = :period AND t.period_start = :start
              ORDER BY b.name ASC, subject ASC",
            ['period' => $period, 'start' => $periodStart]
        );

        $this->page('admin.targets.index', [
            'title' => 'Target management',
            'period' => $period,
            'periodStart' => $periodStart,
            'periodEnd' => $periodEnd,
            'targets' => $targets,
            'branches' => $this->branchOptions(),
            'supervisors' => $this->supervisorOptions(),
        ]);
    }

    public function store(Request $request): void
    {
        $data = $this->validate($request, [
            'scope' => 'required|in:bc_supervisor,branch',
            'period' => 'required|in:daily,monthly',
            'period_start' => 'required|date',
            'visit_target' => 'nullable|integer|min:0',
            'recovery_target' => 'nullable|numeric|min:0',
            'notes' => 'nullable|max:255',
        ]);

        $scope = (string) $data['scope'];
        $period = (string) $data['period'];
        $timestamp = strtotime((string) $data['period_start']) ?: time();

        $periodStart = $period === 'daily' ? date('Y-m-d', $timestamp) : date('Y-m-01', $timestamp);
        $periodEnd = $period === 'daily' ? $periodStart : date('Y-m-t', $timestamp);

        $supervisorIds = [];
        $branchIds = [];

        if ($scope === 'bc_supervisor') {
            $raw = $request->raw('bc_supervisor_ids');
            $supervisorIds = is_array($raw) ? array_map('intval', $raw) : [];

            if ($supervisorIds === [] && (int) $request->input('bc_supervisor_id', 0) > 0) {
                $supervisorIds = [(int) $request->input('bc_supervisor_id')];
            }

            if ($supervisorIds === []) {
                $this->error('Choose at least one BC Supervisor.');
                $this->back('/admin/targets');

                return;
            }
        } else {
            $branchId = (int) $request->input('branch_id', 0);

            if ($branchId <= 0) {
                $this->error('Choose a branch.');
                $this->back('/admin/targets');

                return;
            }

            $branchIds = [$branchId];
        }

        $visitTarget = (int) ($data['visit_target'] ?? 0);
        $recoveryTarget = (float) ($data['recovery_target'] ?? 0);
        $saved = 0;

        foreach ($scope === 'bc_supervisor' ? $supervisorIds : $branchIds as $subjectId) {
            $payload = [
                'scope' => $scope,
                'bc_supervisor_id' => $scope === 'bc_supervisor' ? $subjectId : null,
                'branch_id' => $scope === 'branch' ? $subjectId : null,
                'period' => $period,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'visit_target' => $visitTarget,
                'recovery_target' => $recoveryTarget,
                'notes' => $data['notes'] ?: null,
                'created_by' => auth_id(),
                'updated_at' => now(),
            ];

            $existing = Database::selectOne(
                'SELECT * FROM targets
                  WHERE scope = :scope AND period = :period AND period_start = :start
                    AND ((:bc IS NULL AND bc_supervisor_id IS NULL) OR bc_supervisor_id = :bc)
                    AND ((:branch IS NULL AND branch_id IS NULL) OR branch_id = :branch)
                  LIMIT 1',
                [
                    'scope' => $scope,
                    'period' => $period,
                    'start' => $periodStart,
                    'bc' => $payload['bc_supervisor_id'],
                    'branch' => $payload['branch_id'],
                ]
            );

            if ($existing !== null) {
                Database::update('targets', $payload, 'id = :id', ['id' => (int) $existing['id']]);

                Audit::logChange(
                    Audit::TARGET_CHANGED,
                    'target',
                    (int) $existing['id'],
                    $existing,
                    $payload,
                    sprintf('%s target for %s updated.', ucfirst($period), $periodStart)
                );
            } else {
                $payload['created_at'] = now();
                $id = Database::insert('targets', $payload);

                Audit::log(Audit::TARGET_CHANGED, [
                    'entity_type' => 'target',
                    'entity_id' => $id,
                    'description' => sprintf(
                        '%s target set for %s: %d visits, %s recovery.',
                        ucfirst($period),
                        $periodStart,
                        $visitTarget,
                        money($recoveryTarget)
                    ),
                    'new' => $payload,
                ]);
            }

            // Tell the supervisor what is expected of them.
            if ($scope === 'bc_supervisor') {
                $userId = (int) Database::scalar('SELECT user_id FROM bc_supervisors WHERE id = :id', ['id' => $subjectId]);

                if ($userId > 0) {
                    Notify::user(
                        $userId,
                        sprintf('%s target updated', ucfirst($period)),
                        sprintf(
                            '%s: %d visit(s) and %s recovery for %s.',
                            $period === 'daily' ? 'Today' : 'This month',
                            $visitTarget,
                            money($recoveryTarget),
                            format_date($periodStart)
                        ),
                        ['type' => 'info', 'related_type' => 'target']
                    );
                }
            }

            $saved++;
        }

        $this->success(sprintf('%d target(s) saved.', $saved));
        $this->redirect('/admin/targets?period=' . $period . '&period_start=' . $periodStart);
    }

    public function destroy(Request $request): void
    {
        $id = $request->paramInt('id');
        $target = Database::selectOne('SELECT * FROM targets WHERE id = :id', ['id' => $id]);

        if ($target === null) {
            $this->abort(404, 'Target not found.');
        }

        Database::delete('targets', 'id = :id', ['id' => $id]);

        Audit::log(Audit::TARGET_CHANGED, [
            'entity_type' => 'target',
            'entity_id' => $id,
            'description' => sprintf('Target for %s removed.', $target['period_start']),
            'old' => $target,
        ]);

        $this->success('Target removed.');
        $this->back('/admin/targets');
    }
}
