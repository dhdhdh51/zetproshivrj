<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Core\Database;
use App\Core\Request;
use App\Services\Audit;
use App\Services\Sss;

/**
 * SOCIAL SECURITY SCHEME ENROLMENTS.
 *
 * What the BC Supervisor signed people up for at the outlet, by day: APY, PMJJBY, PMSBY
 * and PMJDY. Supervisors report their own figures from the handset; this screen is for
 * reading them across a branch or a month, and for filling in a day the handset could not.
 *
 * A day belongs to a supervisor, so there is no delete. Correcting the figures is an edit
 * of the day, which keeps one row per supervisor per day and keeps every total built on
 * top of it arithmetically sound.
 */
final class SssController extends BaseController
{
    public function index(Request $request): void
    {
        // Month to date by default: the figure supervisors are actually measured on, and
        // the one somebody opening this screen is nearly always after.
        $from = trim((string) $request->query('from', '')) ?: date('Y-m-01');
        $to = trim((string) $request->query('to', '')) ?: today();

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $branchId = (int) $request->query('branch_id', 0);
        $supervisorId = (int) $request->query('bc_supervisor_id', 0);

        // A Branch Manager sees their own branch whatever the query string says.
        if (!\App\Core\Auth::isAdmin() && \App\Core\Auth::branchId() !== null) {
            $branchId = (int) \App\Core\Auth::branchId();
        }

        $where = ['e.enrolment_date BETWEEN :from AND :to'];
        $params = ['from' => $from, 'to' => $to];

        if ($branchId > 0) {
            $where[] = 'e.branch_id = :branch';
            $params['branch'] = $branchId;
        }

        if ($supervisorId > 0) {
            $where[] = 'e.bc_supervisor_id = :supervisor';
            $params['supervisor'] = $supervisorId;
        }

        $rows = Database::select(
            'SELECT e.*, (e.apy_count + e.pmjjby_count + e.pmsby_count + e.pmjdy_count) AS total,
                    u.name AS supervisor_name, s.bc_code, b.name AS branch_name,
                    r.name AS recorded_by_name
               FROM sss_enrolments e
               JOIN bc_supervisors s ON s.id = e.bc_supervisor_id
               JOIN users u ON u.id = s.user_id
               JOIN branches b ON b.id = e.branch_id
          LEFT JOIN users r ON r.id = e.recorded_by
              WHERE ' . implode(' AND ', $where)
            . ' ORDER BY e.enrolment_date DESC, b.name ASC, u.name ASC',
            $params
        );

        // Per supervisor over the same window, so a branch's figures can be read without
        // adding up a month of rows by eye.
        $perSupervisor = Database::select(
            'SELECT s.id, s.bc_code, u.name AS supervisor_name, b.name AS branch_name,
                    COUNT(*) AS days,
                    SUM(e.apy_count) AS apy_count,
                    SUM(e.pmjjby_count) AS pmjjby_count,
                    SUM(e.pmsby_count) AS pmsby_count,
                    SUM(e.pmjdy_count) AS pmjdy_count,
                    SUM(e.apy_count + e.pmjjby_count + e.pmsby_count + e.pmjdy_count) AS total
               FROM sss_enrolments e
               JOIN bc_supervisors s ON s.id = e.bc_supervisor_id
               JOIN users u ON u.id = s.user_id
               JOIN branches b ON b.id = e.branch_id
              WHERE ' . implode(' AND ', $where)
            . ' GROUP BY s.id, s.bc_code, u.name, b.name
                ORDER BY total DESC, u.name ASC',
            $params
        );

        $this->page('admin.sss.index', [
            'title' => 'SSS enrolments',
            'from' => $from,
            'to' => $to,
            'branchId' => $branchId,
            'supervisorId' => $supervisorId,
            'branches' => $this->branchOptions(),
            'supervisors' => $this->supervisorOptions($branchId > 0 ? $branchId : null),
            'schemes' => Sss::schemes(),
            'schemeNames' => Sss::schemeNames(),
            'summary' => Sss::summary($from, $to, $supervisorId > 0 ? $supervisorId : null, $branchId > 0 ? $branchId : null),
            'rows' => $rows,
            'perSupervisor' => $perSupervisor,
        ]);
    }

    public function create(Request $request): void
    {
        $this->page('admin.sss.form', [
            'title' => 'Record SSS enrolments',
            'entry' => null,
            'schemes' => Sss::schemes(),
            'schemeNames' => Sss::schemeNames(),
            'supervisors' => $this->supervisorOptions(),
            'maxPerScheme' => Sss::MAX_PER_SCHEME,
        ]);
    }

    public function store(Request $request): void
    {
        $data = $this->validate($request, array_merge([
            'bc_supervisor_id' => 'required|integer|exists:bc_supervisors,id',
            'enrolment_date' => 'required|date',
            'remarks' => 'nullable|max:500',
        ], $this->countRules()), [], '/admin/sss/create');

        $supervisorId = (int) $data['bc_supervisor_id'];
        $date = (string) $data['enrolment_date'];

        $supervisor = Database::selectOne(
            'SELECT branch_id FROM bc_supervisors WHERE id = :id',
            ['id' => $supervisorId]
        );
        $this->assertBranch($supervisor === null ? null : (int) $supervisor['branch_id']);

        // The day may already have figures — reported from the handset, or typed here
        // earlier. Overwriting silently would quietly replace what the supervisor
        // reported, so the correction is made deliberately on the edit screen instead.
        $existing = Sss::forDate($supervisorId, $date);

        if ($existing !== null) {
            $this->info('That day already has figures. Correct them here.');
            $this->redirect('/admin/sss/' . (int) $existing['id'] . '/edit');

            return;
        }

        $result = Sss::record($supervisorId, $data, 'panel');

        Audit::log(Audit::SSS_RECORDED, [
            'entity_type' => 'sss_enrolment',
            'entity_id' => $result['id'],
            'description' => sprintf('SSS enrolments recorded for %s: %d in total.', $date, $result['total']),
            'new' => $this->auditable($data, $date),
        ]);

        $this->success('Enrolments recorded.');
        $this->redirect('/admin/sss?' . http_build_query(['from' => $date, 'to' => $date]));
    }

    public function edit(Request $request): void
    {
        $entry = $this->entry($request->paramInt('id'));

        $this->page('admin.sss.form', [
            'title' => 'Correct SSS enrolments',
            'entry' => $entry,
            'schemes' => Sss::schemes(),
            'schemeNames' => Sss::schemeNames(),
            'supervisors' => $this->supervisorOptions(),
            'maxPerScheme' => Sss::MAX_PER_SCHEME,
        ]);
    }

    public function update(Request $request): void
    {
        $id = $request->paramInt('id');
        $entry = $this->entry($id);

        // Only the figures and the remark are editable. The supervisor and the date are
        // what identify the day; changing either would mean moving a day's work onto
        // somebody else, which is a new entry, not a correction.
        $data = $this->validate($request, array_merge([
            'remarks' => 'nullable|max:500',
        ], $this->countRules()), [], '/admin/sss/' . $id . '/edit');

        $result = Sss::record(
            (int) $entry['bc_supervisor_id'],
            array_merge($data, ['enrolment_date' => (string) $entry['enrolment_date']]),
            'panel'
        );

        Audit::logChange(
            Audit::SSS_UPDATED,
            'sss_enrolment',
            $id,
            $entry,
            $this->auditable($data, (string) $entry['enrolment_date']),
            sprintf(
                'SSS enrolments for %s corrected to %d in total.',
                (string) $entry['enrolment_date'],
                $result['total']
            )
        );

        $this->success('Enrolments corrected.');
        $this->redirect('/admin/sss?' . http_build_query([
            'from' => (string) $entry['enrolment_date'],
            'to' => (string) $entry['enrolment_date'],
        ]));
    }

    /**
     * One entry, with the names needed to show it, or a 404.
     *
     * @return array<string, mixed>
     */
    private function entry(int $id): array
    {
        $entry = Database::selectOne(
            'SELECT e.*, u.name AS supervisor_name, s.bc_code, b.name AS branch_name
               FROM sss_enrolments e
               JOIN bc_supervisors s ON s.id = e.bc_supervisor_id
               JOIN users u ON u.id = s.user_id
               JOIN branches b ON b.id = e.branch_id
              WHERE e.id = :id',
            ['id' => $id]
        );

        if ($entry === null) {
            $this->abort(404, 'That SSS entry does not exist.');
        }

        $this->assertBranch((int) $entry['branch_id']);

        return $entry;
    }

    /**
     * Validation for the four counts.
     *
     * Nullable because a scheme with no enrolments is left blank rather than typed as a
     * zero, and the service reads a blank as none. The upper bound is the service's, so
     * the form refuses an absurd figure with a field-level message instead of throwing.
     *
     * @return array<string, string>
     */
    private function countRules(): array
    {
        $rules = [];

        foreach (array_keys(Sss::schemes()) as $column) {
            $rules[$column] = 'nullable|integer|between:0,' . Sss::MAX_PER_SCHEME;
        }

        return $rules;
    }

    /**
     * The figures as the audit log should record them: counts as integers, not as the
     * blank strings the form submits.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function auditable(array $data, string $date): array
    {
        $record = ['enrolment_date' => $date];

        foreach (array_keys(Sss::schemes()) as $column) {
            $record[$column] = (int) ($data[$column] ?? 0);
        }

        $record['remarks'] = ($data['remarks'] ?? '') === '' ? null : (string) $data['remarks'];

        return $record;
    }
}
