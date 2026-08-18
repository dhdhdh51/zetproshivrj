<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Core\Acl;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Services\Audit;
use App\Services\Reports;

/**
 * Reports and analytics.
 *
 * One controller serves all thirteen reports (and doubles as the visit /
 * inspection / OTS / renewal registers) because each report declares its own
 * columns and filters in App\Services\Reports.
 */
final class ReportController extends BaseController
{
    public function index(Request $request): void
    {
        $groups = [];

        foreach (Reports::catalogue() as $slug => $report) {
            $groups[$report['group']][$slug] = $report;
        }

        $this->page('admin.reports.index', [
            'title' => 'Reports',
            'groups' => $groups,
            'recentExports' => Database::select(
                'SELECT e.*, u.name AS user_name
                   FROM report_exports e JOIN users u ON u.id = e.user_id
                  WHERE e.user_id = :uid OR :is_admin = 1
                  ORDER BY e.id DESC LIMIT 12',
                ['uid' => (int) Auth::id(), 'is_admin' => Auth::isAdmin() ? 1 : 0]
            ),
        ]);
    }

    /**
     * Run a report on screen.
     */
    public function show(Request $request): void
    {
        Acl::authorize('reports.view');

        $slug = (string) $request->param('slug');

        if (!Reports::exists($slug)) {
            $this->abort(404, 'Unknown report.');
        }

        $filters = $this->filters($request, Reports::filtersFor($slug));
        $perPage = min(200, max(10, (int) $request->query('per_page', 50)));

        $result = Reports::run($slug, $filters, $this->page_number($request), $perPage);

        Audit::log(Audit::REPORT_GENERATED, [
            'entity_type' => 'report',
            'description' => sprintf('%s viewed (%d rows matched).', Reports::name($slug), $result['total']),
            'new' => ['filters' => $filters],
        ]);

        $this->page('admin.reports.table', array_merge($result, [
            'title' => Reports::name($slug),
            'description' => Reports::catalogue()[$slug]['description'],
            'branches' => $this->branchOptions(),
            'supervisors' => $this->supervisorOptions(
                empty($filters['branch_id']) ? null : (int) $filters['branch_id']
            ),
            'availableFilters' => Reports::filtersFor($slug),
            'detailRoute' => Reports::detailRoute($slug),
            'basePath' => Auth::isAdmin() ? '/admin' : '/manager',
            'summary' => Reports::filterSummary($filters),
        ]));
    }

    /**
     * Generate an export and hand back the file.
     */
    public function export(Request $request): void
    {
        Acl::authorize('reports.export');

        $slug = (string) $request->param('slug');
        $format = strtolower((string) $request->param('format'));

        if (!Reports::exists($slug)) {
            $this->abort(404, 'Unknown report.');
        }

        if (!array_key_exists($format, Reports::FORMATS)) {
            $this->abort(422, 'Unsupported export format.');
        }

        $filters = $this->filters($request, Reports::filtersFor($slug));
        $export = Reports::export($slug, $filters, $format);

        if (($export['status'] ?? '') !== 'completed') {
            $this->error('The export could not be generated. Please try again.');
            $this->back();

            return;
        }

        $this->redirect('/files/export/' . (int) $export['id']);
    }
}
