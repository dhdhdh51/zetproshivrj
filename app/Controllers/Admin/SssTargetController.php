<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Core\Database;
use App\Core\Request;
use App\Services\Audit;
use App\Services\Notify;
use App\Services\Sss;

/**
 * What each BCA is expected to enrol per scheme, per working day.
 *
 * The Admin sets these and nobody else can: the handset reads its target from the server
 * and has no way to send one back. That is the whole point of the screen — a supervisor
 * cannot move the bar they are measured against.
 *
 * ONE ROW PER SUPERVISOR PER MONTH, HOLDING A DAILY FIGURE
 *
 * The figure is per working day because that is how the work is handed out ("two APY a
 * day"). Month to date and the month are derived from it and the working-day settings, so
 * changing either corrects every total that was built on it instead of leaving a stored
 * number that is now wrong. See App\Services\Sss for the arithmetic.
 *
 * There is no per-day override. A month with a different expectation in its second half is
 * a second month as far as this register is concerned, and nobody has asked for that.
 */
final class SssTargetController extends BaseController
{
    public function index(Request $request): void
    {
        $month = Sss::month((string) $request->query('month', today()));
        $monthEnd = date('Y-m-t', (int) strtotime($month));
        $branchId = (int) $request->query('branch_id', 0);

        // A Branch Manager reaching this screen sees their own branch only. Admin-only
        // middleware guards the route today, but the pinning stays so sharing it later is
        // a routing change rather than a data-leak review.
        if (!\App\Core\Auth::isAdmin() && \App\Core\Auth::branchId() !== null) {
            $branchId = (int) \App\Core\Auth::branchId();
        }

        $where = ['t.target_month = :month'];
        $params = ['month' => $month];

        if ($branchId > 0) {
            $where[] = 's.branch_id = :branch';
            $params['branch'] = $branchId;
        }

        $sums = [];

        foreach (array_keys(Sss::targetSchemes()) as $column) {
            $sums[] = sprintf('t.`%s`', $column);
        }

        $targets = Database::select(
            sprintf(
                'SELECT t.id, t.bc_supervisor_id, t.target_month, t.notes, %s,
                        (%s) AS per_day_total,
                        u.name AS supervisor_name, s.bc_code, b.name AS branch_name,
                        c.name AS set_by_name
                   FROM sss_targets t
                   JOIN bc_supervisors s ON s.id = t.bc_supervisor_id
                   JOIN users u ON u.id = s.user_id
              LEFT JOIN branches b ON b.id = s.branch_id
              LEFT JOIN users c ON c.id = t.created_by
                  WHERE %s
               ORDER BY b.name ASC, u.name ASC',
                implode(', ', $sums),
                implode(' + ', $sums),
                implode(' AND ', $where)
            ),
            $params
        );

        // The same number every derived total is built from, shown so nobody has to guess
        // why a monthly figure is 26 times the daily one and not 31.
        $workingDays = Sss::workingDaysBetween($month, $monthEnd);

        $this->page('admin.sss.targets', [
            'title' => 'SSS targets',
            'month' => $month,
            'monthEnd' => $monthEnd,
            'workingDays' => $workingDays,
            'branchId' => $branchId,
            'branches' => $this->branchOptions(),
            'supervisors' => $this->supervisorOptions($branchId > 0 ? $branchId : null),
            'schemes' => Sss::schemes(),
            'schemeNames' => Sss::schemeNames(),
            'targetSchemes' => Sss::targetSchemes(),
            'targets' => $targets,
            'maxPerScheme' => Sss::MAX_PER_SCHEME,
        ]);
    }

    public function store(Request $request): void
    {
        $data = $this->validate($request, array_merge([
            'month' => 'required|date',
            'notes' => 'nullable|max:255',
        ], $this->targetRules()), [], '/admin/sss-targets');

        $month = Sss::month((string) $data['month']);

        $raw = $request->raw('bc_supervisor_ids');
        $supervisorIds = is_array($raw) ? array_values(array_unique(array_map('intval', $raw))) : [];

        if ($supervisorIds === [] && (int) $request->input('bc_supervisor_id', 0) > 0) {
            $supervisorIds = [(int) $request->input('bc_supervisor_id')];
        }

        if ($supervisorIds === []) {
            $this->error('Choose at least one BCA.');
            $this->back('/admin/sss-targets');

            return;
        }

        $monthEnd = date('Y-m-t', (int) strtotime($month));
        $workingDays = Sss::workingDaysBetween($month, $monthEnd);
        $saved = 0;

        foreach ($supervisorIds as $supervisorId) {
            $supervisor = Database::selectOne(
                'SELECT s.id, s.branch_id, s.user_id, u.name
                   FROM bc_supervisors s JOIN users u ON u.id = s.user_id
                  WHERE s.id = :id',
                ['id' => $supervisorId]
            );

            if ($supervisor === null) {
                continue;
            }

            $this->assertBranch((int) $supervisor['branch_id']);

            $previous = Database::selectOne(
                'SELECT * FROM sss_targets WHERE bc_supervisor_id = :bc AND target_month = :month',
                ['bc' => $supervisorId, 'month' => $month]
            );

            $result = Sss::saveTarget($supervisorId, $month, $data, (string) ($data['notes'] ?? ''));

            if ($previous !== null) {
                Audit::logChange(
                    Audit::SSS_TARGET_CHANGED,
                    'sss_target',
                    $result['id'],
                    $previous,
                    $this->auditable($data, $month),
                    sprintf(
                        'SSS target for %s changed to %d a day.',
                        format_date($month),
                        $result['per_day_total']
                    )
                );
            } else {
                Audit::log(Audit::SSS_TARGET_CHANGED, [
                    'entity_type' => 'sss_target',
                    'entity_id' => $result['id'],
                    'description' => sprintf(
                        'SSS target set for %s: %d a day.',
                        format_date($month),
                        $result['per_day_total']
                    ),
                    'new' => $this->auditable($data, $month),
                ]);
            }

            // A target nobody was told about is not a target. The supervisor sees the same
            // figure on the handset, but the notification is what makes it an instruction.
            if ((int) $supervisor['user_id'] > 0) {
                Notify::user(
                    (int) $supervisor['user_id'],
                    'SSS target updated',
                    sprintf(
                        '%d enrolment(s) a day for %s — %d working day(s), so %d for the month.',
                        $result['per_day_total'],
                        date('F Y', (int) strtotime($month)),
                        $workingDays,
                        $result['per_day_total'] * $workingDays
                    ),
                    ['type' => 'info', 'link' => '/admin/sss-targets', 'related_type' => 'sss_target']
                );
            }

            $saved++;
        }

        $this->success(sprintf('%d SSS target(s) saved.', $saved));
        $this->redirect('/admin/sss-targets?' . http_build_query(['month' => $month]));
    }

    public function destroy(Request $request): void
    {
        $id = $request->paramInt('id');

        $target = Database::selectOne(
            'SELECT t.*, s.branch_id FROM sss_targets t
               JOIN bc_supervisors s ON s.id = t.bc_supervisor_id
              WHERE t.id = :id',
            ['id' => $id]
        );

        if ($target === null) {
            $this->abort(404, 'That SSS target does not exist.');
        }

        $this->assertBranch((int) $target['branch_id']);

        Database::delete('sss_targets', 'id = :id', ['id' => $id]);

        Audit::log(Audit::SSS_TARGET_CHANGED, [
            'entity_type' => 'sss_target',
            'entity_id' => $id,
            'description' => sprintf('SSS target for %s removed.', format_date((string) $target['target_month'])),
            'old' => $target,
        ]);

        // Removing the target does not touch a single enrolment: the figures stay, they
        // simply stop being measured against anything.
        $this->success('SSS target removed. The enrolments already recorded are unchanged.');
        $this->back('/admin/sss-targets?' . http_build_query(['month' => (string) $target['target_month']]));
    }

    /**
     * Field-level rules for the four target boxes, so the form reports the problem next to
     * the box rather than letting the service throw.
     *
     * @return array<string, string>
     */
    private function targetRules(): array
    {
        $rules = [];

        foreach (array_keys(Sss::targetSchemes()) as $column) {
            $rules[$column] = 'nullable|integer|between:0,' . Sss::MAX_PER_SCHEME;
        }

        return $rules;
    }

    /**
     * Normalised for the audit log, so logChange() compares like with like.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function auditable(array $data, string $month): array
    {
        $out = ['target_month' => $month];

        foreach (array_keys(Sss::targetSchemes()) as $column) {
            $out[$column] = (int) ($data[$column] ?? 0);
        }

        $out['notes'] = trim((string) ($data['notes'] ?? '')) !== '' ? (string) $data['notes'] : null;

        return $out;
    }
}
