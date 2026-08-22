<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Acl;
use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Services\Export\CsvWriter;
use App\Services\Export\PdfWriter;
use App\Services\Export\XlsxWriter;

/**
 * The reporting engine.
 *
 * Every report is a declaration: its columns, its filters and one SQL statement.
 * Listing, totals, pagination and the three export formats are then generic, so a
 * new report is a data change rather than a new screen — and, more importantly,
 * branch isolation is applied in one place (`scope()`) instead of being
 * re-implemented per report where it could be forgotten.
 */
final class Reports
{
    public const FORMATS = ['csv' => 'CSV', 'excel' => 'Excel', 'pdf' => 'PDF'];

    /* ------------------------------------------------------------------ */
    /* Catalogue                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, array{name:string, description:string, icon:string, group:string}>
     */
    public static function catalogue(): array
    {
        return [
            'customer_visit' => [
                'name' => 'Customer Visit Report',
                'description' => 'TYPE A — BCA customer recovery visits with GPS, photos and outcome.',
                'icon' => 'clipboard',
                'group' => 'Field work',
            ],
            'bc_inspection' => [
                'name' => 'BCA Inspection Report',
                'description' => "TYPE B — the monthly inspection of each BC point and its agent, graded at item 24.",
                'icon' => 'search-check',
                'group' => 'Field work',
            ],
            'krm_ots' => [
                'name' => 'KRM OTS Report',
                'description' => 'One Time Settlement proposals, approvals and payments.',
                'icon' => 'rupee',
                'group' => 'Work streams',
            ],
            'ckcc_od2' => [
                'name' => 'CKCC OD-2 Renewal Report',
                'description' => 'Renewal status, customer availability and document position.',
                'icon' => 'refresh',
                'group' => 'Work streams',
            ],
            'recovery' => [
                'name' => 'Recovery Report',
                'description' => 'Amounts borrowers repaid, by date, mode, branch and supervisor.',
                'icon' => 'rupee',
                'group' => 'Money',
            ],
            'ptp' => [
                'name' => 'PTP Report',
                'description' => 'Promise to pay register with kept, broken and pending outcomes.',
                'icon' => 'calendar',
                'group' => 'Money',
            ],
            'followup' => [
                'name' => 'Follow-up Report',
                'description' => 'Pending and completed follow-up actions.',
                'icon' => 'history',
                'group' => 'Money',
            ],
            'attendance' => [
                'name' => 'Attendance Report',
                'description' => 'Check in/out, working hours and visits per day.',
                'icon' => 'clock',
                'group' => 'Supervision',
            ],
            'sss' => [
                'name' => 'SSS Enrolment Report',
                'description' => 'Social Security Scheme sign-ups per supervisor per day: APY, PMJJBY, PMSBY and PMJDY.',
                'icon' => 'shield',
                'group' => 'Supervision',
            ],
            'gps' => [
                'name' => 'GPS Report',
                'description' => 'Captured coordinates, accuracy and server-side validation results.',
                'icon' => 'map-pin',
                'group' => 'Supervision',
            ],
            'photo' => [
                'name' => 'Photo Report',
                'description' => 'Photographic evidence captured in the field.',
                'icon' => 'camera',
                'group' => 'Supervision',
            ],
            'target' => [
                'name' => 'Target Report',
                'description' => 'Target versus achievement with pending and percentage.',
                'icon' => 'target',
                'group' => 'Performance',
            ],
            'sss_target' => [
                'name' => 'SSS Target vs Achievement',
                'description' => 'Scheme enrolment target, achievement, percentage and gap per supervisor, ranked.',
                'icon' => 'target',
                'group' => 'Performance',
            ],
            'branch_performance' => [
                'name' => 'Branch Performance',
                'description' => 'Branch level accounts, visits, recovery and coverage.',
                'icon' => 'building',
                'group' => 'Performance',
            ],
            'bc_performance' => [
                'name' => 'BCA Performance',
                'description' => 'Per supervisor visits, recovery, attendance and inspection outcomes.',
                'icon' => 'user-check',
                'group' => 'Performance',
            ],
        ];
    }

    public static function exists(string $slug): bool
    {
        return array_key_exists($slug, self::catalogue());
    }

    /**
     * Where a row of this report can be opened in full. Used by the generic
     * report table so the same screen doubles as the visit / inspection / OTS
     * register without duplicating list views.
     *
     * @return array{path:string, key:string}|null
     */
    public static function detailRoute(string $slug): ?array
    {
        $prefix = Auth::isAdmin() ? '/admin' : '/manager';

        return match ($slug) {
            'customer_visit' => ['path' => $prefix . '/visits/', 'key' => 'id'],
            'bc_inspection' => Auth::isAdmin() ? ['path' => '/admin/inspections/', 'key' => 'id'] : null,
            'krm_ots', 'ckcc_od2' => ['path' => $prefix . '/accounts/', 'key' => 'account_id'],
            'photo' => ['path' => $prefix . '/visits/', 'key' => 'visit_id'],
            default => null,
        };
    }

    public static function name(string $slug): string
    {
        return self::catalogue()[$slug]['name'] ?? ucwords(str_replace('_', ' ', $slug));
    }

    /**
     * Which filters a given report accepts. Drives the filter bar in the UI.
     *
     * @return array<int, string>
     */
    public static function filtersFor(string $slug): array
    {
        $common = ['from', 'to', 'branch_id', 'bc_supervisor_id', 'search'];

        return match ($slug) {
            'customer_visit' => array_merge($common, ['visit_status', 'status', 'visit_type', 'late_only', 'gps_invalid_only']),
            'bc_inspection' => array_merge($common, ['result', 'status']),
            'krm_ots' => array_merge($common, ['ots_status', 'customer_response', 'final_status']),
            'ckcc_od2' => array_merge($common, [
                'renewal_status', 'documents_status', 'renewal_due_bucket', 'kyc_status', 'final_status',
            ]),
            'recovery' => array_merge($common, ['payment_mode', 'status']),
            'ptp' => array_merge($common, ['status']),
            'followup' => array_merge($common, ['status', 'action']),
            'attendance' => array_merge($common, ['status']),
            'sss' => array_merge($common, ['source']),
            'sss_target' => $common,
            'gps' => array_merge($common, ['gps_invalid_only']),
            'photo' => array_merge($common, ['photo_type']),
            'target' => ['from', 'to', 'branch_id', 'bc_supervisor_id', 'period'],
            'branch_performance' => ['from', 'to', 'branch_id'],
            'bc_performance' => ['from', 'to', 'branch_id', 'bc_supervisor_id'],
            default => $common,
        };
    }

    /* ------------------------------------------------------------------ */
    /* Running                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $filters
     * @return array{
     *   slug:string, name:string,
     *   columns:array<int, array{key:string, label:string, align?:string, type?:string}>,
     *   rows:array<int, array<string, mixed>>,
     *   totals:array<string, mixed>,
     *   total:int, page:int, per_page:int, last_page:int,
     *   filters:array<string, mixed>
     * }
     */
    public static function run(string $slug, array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $definition = self::definition($slug, $filters);

        $page = max(1, $page);
        $perPage = max(5, min(200, $perPage));
        $offset = ($page - 1) * $perPage;

        $total = (int) Database::scalar(
            'SELECT COUNT(*) FROM (' . $definition['sql'] . ') AS report_rows',
            $definition['params']
        );

        $rows = Database::select(
            $definition['sql'] . sprintf(' LIMIT %d OFFSET %d', $perPage, $offset),
            $definition['params']
        );

        return [
            'slug' => $slug,
            'name' => self::name($slug),
            'columns' => $definition['columns'],
            'rows' => $rows,
            'totals' => self::totals($definition, $rows),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => max(1, (int) ceil($total / $perPage)),
            'filters' => $filters,
        ];
    }

    /**
     * Every row, for exports. Capped to keep a runaway export from exhausting
     * memory on shared hosting.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function rows(string $slug, array $filters = [], int $limit = 20000): array
    {
        $definition = self::definition($slug, $filters);

        return Database::select(
            $definition['sql'] . sprintf(' LIMIT %d', max(1, $limit)),
            $definition['params']
        );
    }

    /**
     * Column totals for numeric columns declared as summable.
     *
     * @param array<string, mixed>             $definition
     * @param array<int, array<string, mixed>> $pageRows
     * @return array<string, mixed>
     */
    private static function totals(array $definition, array $pageRows): array
    {
        $summable = [];

        foreach ($definition['columns'] as $column) {
            if (($column['type'] ?? '') === 'money' || ($column['type'] ?? '') === 'count') {
                $summable[] = $column['key'];
            }
        }

        if ($summable === []) {
            return [];
        }

        // Totals are computed over the whole filtered set, not just this page.
        $selects = [];

        foreach ($summable as $key) {
            $selects[] = sprintf('COALESCE(SUM(`%s`), 0) AS `%s`', $key, $key);
        }

        $row = Database::selectOne(
            sprintf('SELECT %s FROM (%s) AS report_rows', implode(', ', $selects), $definition['sql']),
            $definition['params']
        ) ?? [];

        $row['_page_rows'] = count($pageRows);

        return $row;
    }

    /* ------------------------------------------------------------------ */
    /* Filter helpers                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * Branch isolation. A Branch Manager's reports are silently constrained to
     * their own branch; an explicit branch filter can only narrow further.
     *
     * @param array<string, mixed> $filters
     * @return array{0:string, 1:array<string, mixed>}
     */
    private static function scope(string $alias, array $filters, string $column = 'branch_id'): array
    {
        [$clause, $params] = Acl::branchScope($alias, $column);
        $conditions = [$clause];

        $requested = isset($filters['branch_id']) && $filters['branch_id'] !== '' ? (int) $filters['branch_id'] : null;

        if ($requested !== null) {
            if (!Auth::isAdmin() && Auth::branchId() !== null && $requested !== Auth::branchId()) {
                throw new HttpException(403, 'You may only report on your own branch.');
            }

            $conditions[] = sprintf('%s.%s = :filter_branch_id', $alias, $column);
            $params['filter_branch_id'] = $requested;
        }

        return [implode(' AND ', $conditions), $params];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{from:string, to:string}
     */
    private static function dateRange(array $filters, int $defaultDays = 30): array
    {
        $to = isset($filters['to']) && $filters['to'] !== ''
            ? date('Y-m-d', (int) strtotime((string) $filters['to']))
            : today();

        $from = isset($filters['from']) && $filters['from'] !== ''
            ? date('Y-m-d', (int) strtotime((string) $filters['from']))
            : date('Y-m-d', strtotime($to . ' -' . $defaultDays . ' days'));

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return ['from' => $from, 'to' => $to];
    }

    /**
     * @param array<string, mixed> $filters
     */
    private static function enumFilter(array $filters, string $key, array $allowed): ?string
    {
        $value = isset($filters[$key]) ? (string) $filters[$key] : '';

        return $value !== '' && in_array($value, $allowed, true) ? $value : null;
    }

    private static function truthy(array $filters, string $key): bool
    {
        return in_array((string) ($filters[$key] ?? ''), ['1', 'yes', 'true', 'on'], true);
    }

    /* ------------------------------------------------------------------ */
    /* Definitions                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $filters
     * @return array{sql:string, params:array<string, mixed>, columns:array<int, array<string, mixed>>}
     */
    private static function definition(string $slug, array $filters): array
    {
        if (!self::exists($slug)) {
            throw new HttpException(404, 'Unknown report.');
        }

        return match ($slug) {
            'customer_visit' => self::customerVisit($filters),
            'bc_inspection' => self::bcInspection($filters),
            'krm_ots' => self::krmOts($filters),
            'ckcc_od2' => self::ckccOd2($filters),
            'recovery' => self::recovery($filters),
            'ptp' => self::ptp($filters),
            'followup' => self::followup($filters),
            'attendance' => self::attendance($filters),
            'sss' => self::sss($filters),
            'sss_target' => self::sssTarget($filters),
            'gps' => self::gps($filters),
            'photo' => self::photo($filters),
            'target' => self::target($filters),
            'branch_performance' => self::branchPerformance($filters),
            'bc_performance' => self::bcPerformance($filters),
            default => throw new HttpException(404, 'Unknown report.'),
        };
    }

    /** TYPE A — customer recovery visits. */
    private static function customerVisit(array $filters): array
    {
        [$scope, $params] = self::scope('v', $filters);
        $range = self::dateRange($filters);
        $params += ['from' => $range['from'], 'to' => $range['to']];

        $where = [$scope, 'v.visit_date BETWEEN :from AND :to', "v.status <> 'draft'"];

        if (!empty($filters['bc_supervisor_id'])) {
            $where[] = 'v.bc_supervisor_id = :bc';
            $params['bc'] = (int) $filters['bc_supervisor_id'];
        }

        $visitStatus = self::enumFilter($filters, 'visit_status', ['customer_met', 'family_met', 'phone_contact',
            'house_locked', 'not_available', 'address_not_found', 'deceased', 'shifted', 'refused', 'other']);

        if ($visitStatus !== null) {
            $where[] = 'v.visit_status = :visit_status';
            $params['visit_status'] = $visitStatus;
        }

        $status = self::enumFilter($filters, 'status', ['submitted', 'approved', 'rejected', 'late_pending']);

        if ($status !== null) {
            $where[] = 'v.status = :status';
            $params['status'] = $status;
        }

        $visitType = self::enumFilter($filters, 'visit_type', ['customer', 'krm_ots', 'ckcc_od2']);

        if ($visitType !== null) {
            $where[] = 'v.visit_type = :visit_type';
            $params['visit_type'] = $visitType;
        }

        if (self::truthy($filters, 'late_only')) {
            $where[] = 'v.is_late = 1';
        }

        if (self::truthy($filters, 'gps_invalid_only')) {
            $where[] = 'v.gps_verified = 0';
        }

        if (!empty($filters['search'])) {
            $where[] = '(a.account_number LIKE :search OR a.borrower_name LIKE :search OR a.mobile LIKE :search OR a.village LIKE :search)';
            $params['search'] = '%' . trim((string) $filters['search']) . '%';
        }

        $sql = "SELECT v.id, v.uuid, v.visit_date, v.submitted_at, v.visit_status, v.status,
                       v.visit_type, v.is_late, v.gps_verified, v.photo_count, v.recovery_possibility,
                       v.remarks, v.recommendation,
                       a.account_number, a.cif, a.borrower_name, a.father_name, a.village, a.mobile,
                       a.loan_type, a.outstanding, a.overdue, a.npa_date,
                       b.name AS branch_name, b.code AS branch_code,
                       u.name AS supervisor_name, s.bc_code,
                       g.latitude, g.longitude, g.accuracy, g.address,
                       COALESCE(r.recovered, 0) AS recovered,
                       COALESCE(p.promised, 0) AS promised,
                       (SELECT COUNT(*) FROM inspections i WHERE i.visit_id = v.id AND i.status = 'submitted') AS inspected
                  FROM visits v
                  JOIN loan_accounts a ON a.id = v.loan_account_id
                  JOIN branches b ON b.id = v.branch_id
                  JOIN bc_supervisors s ON s.id = v.bc_supervisor_id
                  JOIN users u ON u.id = s.user_id
             LEFT JOIN visit_gps g ON g.id = (
                       SELECT id FROM visit_gps WHERE visit_id = v.id ORDER BY (event = 'start') DESC, id ASC LIMIT 1
                  )
             LEFT JOIN (
                       SELECT visit_id, SUM(amount) AS recovered FROM recoveries
                        WHERE visit_id IS NOT NULL AND status <> 'rejected' GROUP BY visit_id
                  ) r ON r.visit_id = v.id
             LEFT JOIN (
                       SELECT visit_id, SUM(promise_amount) AS promised FROM promises
                        WHERE visit_id IS NOT NULL GROUP BY visit_id
                  ) p ON p.visit_id = v.id
                 WHERE " . implode(' AND ', $where)
            . ' ORDER BY v.visit_date DESC, v.id DESC';

        return [
            'sql' => $sql,
            'params' => $params,
            'columns' => [
                ['key' => 'visit_date', 'label' => 'Visit date', 'type' => 'date', 'weight' => 0.9],
                ['key' => 'account_number', 'label' => 'Account', 'weight' => 1.3],
                ['key' => 'borrower_name', 'label' => 'Borrower', 'weight' => 1.4],
                ['key' => 'village', 'label' => 'Village', 'weight' => 1.0],
                ['key' => 'branch_name', 'label' => 'Branch', 'weight' => 1.0],
                ['key' => 'supervisor_name', 'label' => 'BCA', 'weight' => 1.2],
                ['key' => 'bc_code', 'label' => 'BC Code', 'weight' => 0.8],
                ['key' => 'visit_status', 'label' => 'Visit status', 'type' => 'visit_status', 'weight' => 1.1],
                ['key' => 'outstanding', 'label' => 'Outstanding', 'type' => 'money', 'align' => 'right', 'weight' => 1.0],
                ['key' => 'overdue', 'label' => 'Overdue', 'type' => 'money', 'align' => 'right', 'weight' => 1.0],
                ['key' => 'recovered', 'label' => 'Recovered', 'type' => 'money', 'align' => 'right', 'weight' => 1.0],
                ['key' => 'promised', 'label' => 'PTP', 'type' => 'money', 'align' => 'right', 'weight' => 0.9],
                ['key' => 'photo_count', 'label' => 'Photos', 'align' => 'center', 'weight' => 0.6],
                ['key' => 'gps_verified', 'label' => 'GPS', 'type' => 'boolean', 'align' => 'center', 'weight' => 0.6],
                ['key' => 'inspected', 'label' => 'Inspected', 'type' => 'boolean', 'align' => 'center', 'weight' => 0.7],
                ['key' => 'remarks', 'label' => 'Remarks', 'weight' => 1.8],
            ],
        ];
    }

    /** TYPE B — BCA inspections. */
    private static function bcInspection(array $filters): array
    {
        [$scope, $params] = self::scope('i', $filters);
        $range = self::dateRange($filters);
        $params += ['from' => $range['from'], 'to' => $range['to']];

        $where = [$scope, 'i.inspection_date BETWEEN :from AND :to'];

        if (!empty($filters['bc_supervisor_id'])) {
            $where[] = 'i.bc_supervisor_id = :bc';
            $params['bc'] = (int) $filters['bc_supervisor_id'];
        }

        // Current grades and the retired verification outcomes, so a register covering a
        // month from before the form changed can still be filtered by what it recorded.
        $result = self::enumFilter($filters, 'result', array_merge(
            array_keys(inspection_results()),
            array_keys(inspection_results_retired())
        ));

        if ($result !== null) {
            $where[] = 'i.result = :result';
            $params['result'] = $result;
        }

        $status = self::enumFilter($filters, 'status', ['draft', 'submitted']);
        $where[] = $status !== null ? 'i.status = :status' : "i.status = 'submitted'";

        if ($status !== null) {
            $params['status'] = $status;
        }

        if (!empty($filters['search'])) {
            $where[] = '(a.account_number LIKE :search OR a.borrower_name LIKE :search OR u.name LIKE :search OR s.bc_code LIKE :search)';
            $params['search'] = '%' . trim((string) $filters['search']) . '%';
        }

        $sql = "SELECT i.id, i.uuid, i.inspection_date, i.submitted_at, i.result, i.remarks,
                       i.followup_required, i.status, i.gps_verified, i.photo_count,
                       admin.name AS inspector_name,
                       u.name AS supervisor_name, s.bc_code,
                       b.name AS branch_name, b.code AS branch_code,
                       a.account_number, a.borrower_name, a.village,
                       v.visit_date, v.visit_status,
                       g.latitude, g.longitude, g.accuracy, g.address, g.distance_to_visit_metres
                  FROM inspections i
                  JOIN users admin ON admin.id = i.admin_user_id
                  JOIN bc_supervisors s ON s.id = i.bc_supervisor_id
                  JOIN users u ON u.id = s.user_id
                  JOIN branches b ON b.id = i.branch_id
             LEFT JOIN loan_accounts a ON a.id = i.loan_account_id
             LEFT JOIN visits v ON v.id = i.visit_id
             LEFT JOIN inspection_gps g ON g.id = (
                       SELECT id FROM inspection_gps WHERE inspection_id = i.id ORDER BY id ASC LIMIT 1
                  )
                 WHERE " . implode(' AND ', $where)
            . ' ORDER BY i.inspection_date DESC, i.id DESC';

        return [
            'sql' => $sql,
            'params' => $params,
            'columns' => [
                ['key' => 'inspection_date', 'label' => 'Date', 'type' => 'date', 'weight' => 0.9],
                ['key' => 'inspector_name', 'label' => 'Inspector', 'weight' => 1.2],
                ['key' => 'supervisor_name', 'label' => 'BCA', 'weight' => 1.2],
                ['key' => 'bc_code', 'label' => 'BC Code', 'weight' => 0.7],
                ['key' => 'branch_name', 'label' => 'Branch', 'weight' => 1.0],
                // The account, the borrower, the visit being checked and the distance to it
                // were the old form's subject. A monthly inspection of a BC point has none
                // of them, so they would be four empty columns on every new row. They are
                // still selected above and still shown on the inspection's own screen, which
                // is where an auditor opening a historic record will be.
                ['key' => 'result', 'label' => 'Observation', 'type' => 'inspection_result', 'weight' => 1.4],
                ['key' => 'photo_count', 'label' => 'Photos', 'align' => 'center', 'weight' => 0.6],
                ['key' => 'followup_required', 'label' => 'Follow-up', 'type' => 'boolean', 'align' => 'center', 'weight' => 0.8],
                ['key' => 'remarks', 'label' => 'Remarks', 'weight' => 2.0],
            ],
        ];
    }

    private static function krmOts(array $filters): array
    {
        [$scope, $params] = self::scope('k', $filters);
        $range = self::dateRange($filters, 365);
        $params += ['from' => $range['from'], 'to' => $range['to']];

        $where = [$scope, '(k.visit_date BETWEEN :from AND :to OR k.visit_date IS NULL)'];

        if (!empty($filters['bc_supervisor_id'])) {
            $where[] = 'k.bc_supervisor_id = :bc';
            $params['bc'] = (int) $filters['bc_supervisor_id'];
        }

        $status = self::enumFilter($filters, 'ots_status', array_keys(KrmOts::STATUSES));

        if ($status !== null) {
            $where[] = 'k.ots_status = :ots_status';
            $params['ots_status'] = $status;
        }

        // Section 4 and 13 of the verification report, so the register can be
        // narrowed to the cases that need a decision.
        $response = self::enumFilter($filters, 'customer_response', array_keys(KrmOts::CUSTOMER_RESPONSES));

        if ($response !== null) {
            $where[] = 'k.customer_response = :customer_response';
            $params['customer_response'] = $response;
        }

        $finalStatus = self::enumFilter($filters, 'final_status', array_keys(KrmOts::FINAL_STATUSES));

        if ($finalStatus !== null) {
            $where[] = 'k.final_status = :final_status';
            $params['final_status'] = $finalStatus;
        }

        if (!empty($filters['search'])) {
            $where[] = '(a.account_number LIKE :search OR a.cif LIKE :search OR a.borrower_name LIKE :search)';
            $params['search'] = '%' . trim((string) $filters['search']) . '%';
        }

        $sql = 'SELECT k.id, a.id AS account_id, a.account_number, a.cif, a.borrower_name, a.father_name, a.village, a.mobile,
                       b.name AS branch_name, b.code AS branch_code,
                       u.name AS supervisor_name, s.bc_code,
                       k.ots_eligible, k.scheme, k.outstanding, k.ots_amount, k.borrower_share,
                       k.initial_deposit_required, k.sanctioned_amount, k.paid_amount,
                       k.customer_response, k.ots_status, k.recommendation, k.final_status,
                       k.visit_date, k.promise_date, k.remarks,
                       (SELECT COUNT(*) FROM visit_photos p WHERE p.visit_id = k.visit_id) AS photos,
                       g.latitude, g.longitude
                  FROM krm_ots_cases k
                  JOIN loan_accounts a ON a.id = k.loan_account_id
                  JOIN branches b ON b.id = k.branch_id
             LEFT JOIN bc_supervisors s ON s.id = k.bc_supervisor_id
             LEFT JOIN users u ON u.id = s.user_id
             LEFT JOIN visit_gps g ON g.id = (
                       SELECT id FROM visit_gps WHERE visit_id = k.visit_id ORDER BY id ASC LIMIT 1
                  )
                 WHERE ' . implode(' AND ', $where)
            . ' ORDER BY k.updated_at DESC, k.id DESC';

        return [
            'sql' => $sql,
            'params' => $params,
            'columns' => [
                ['key' => 'account_number', 'label' => 'Account', 'weight' => 1.2],
                ['key' => 'cif', 'label' => 'CIF', 'weight' => 0.9],
                ['key' => 'borrower_name', 'label' => 'Borrower', 'weight' => 1.4],
                ['key' => 'branch_name', 'label' => 'Branch', 'weight' => 1.0],
                ['key' => 'supervisor_name', 'label' => 'BCA', 'weight' => 1.1],
                ['key' => 'ots_eligible', 'label' => 'Eligible', 'type' => 'boolean', 'align' => 'center', 'weight' => 0.7],
                ['key' => 'scheme', 'label' => 'Scheme', 'type' => 'labels', 'labels' => KrmOts::SCHEMES, 'weight' => 0.9],
                ['key' => 'outstanding', 'label' => 'Outstanding', 'type' => 'money', 'align' => 'right', 'weight' => 1.0],
                ['key' => 'ots_amount', 'label' => 'Proposed settlement', 'type' => 'money', 'align' => 'right', 'weight' => 1.0],
                ['key' => 'borrower_share', 'label' => "Borrower's share", 'type' => 'money', 'align' => 'right', 'weight' => 0.9],
                ['key' => 'initial_deposit_required', 'label' => 'Initial deposit', 'type' => 'money', 'align' => 'right', 'weight' => 0.9],
                ['key' => 'paid_amount', 'label' => 'Paid', 'type' => 'money', 'align' => 'right', 'weight' => 0.9],
                [
                    'key' => 'customer_response',
                    'label' => 'Customer response',
                    'type' => 'labels',
                    'labels' => KrmOts::CUSTOMER_RESPONSES,
                    'weight' => 1.2,
                ],
                ['key' => 'ots_status', 'label' => 'OTS status', 'type' => 'labels', 'labels' => KrmOts::STATUSES, 'weight' => 1.0],
                [
                    'key' => 'recommendation',
                    'label' => 'Recommendation',
                    'type' => 'labels',
                    'labels' => KrmOts::RECOMMENDATIONS,
                    'weight' => 1.3,
                ],
                [
                    'key' => 'final_status',
                    'label' => 'Final status',
                    'type' => 'labels',
                    'labels' => KrmOts::FINAL_STATUSES,
                    'weight' => 1.2,
                ],
                ['key' => 'visit_date', 'label' => 'Visit date', 'type' => 'date', 'weight' => 0.9],
                ['key' => 'promise_date', 'label' => 'Expected deposit', 'type' => 'date', 'weight' => 0.9],
                ['key' => 'remarks', 'label' => 'Remarks', 'weight' => 1.8],
            ],
        ];
    }

    private static function ckccOd2(array $filters): array
    {
        [$scope, $params] = self::scope('c', $filters);
        $range = self::dateRange($filters, 365);
        $params += ['from' => $range['from'], 'to' => $range['to']];

        $where = [$scope, '(c.visit_date BETWEEN :from AND :to OR c.visit_date IS NULL)'];

        if (!empty($filters['bc_supervisor_id'])) {
            $where[] = 'c.bc_supervisor_id = :bc';
            $params['bc'] = (int) $filters['bc_supervisor_id'];
        }

        $status = self::enumFilter($filters, 'renewal_status', array_keys(CkccRenewals::STATUSES));

        if ($status !== null) {
            $where[] = 'c.renewal_status = :renewal_status';
            $params['renewal_status'] = $status;
        }

        $documents = self::enumFilter($filters, 'documents_status', array_keys(CkccRenewals::DOCUMENT_STATUSES));

        if ($documents !== null) {
            $where[] = 'c.documents_status = :documents_status';
            $params['documents_status'] = $documents;
        }

        // Section 5 and 13 of the verification report. The due bucket is the one
        // an BC Supervisor actually works from: it answers "what falls due
        // next week".
        $bucket = self::enumFilter($filters, 'renewal_due_bucket', array_keys(CkccRenewals::DUE_BUCKETS));

        if ($bucket !== null) {
            $where[] = 'c.renewal_due_bucket = :renewal_due_bucket';
            $params['renewal_due_bucket'] = $bucket;
        }

        $kyc = self::enumFilter($filters, 'kyc_status', array_keys(CkccRenewals::KYC_STATUSES));

        if ($kyc !== null) {
            $where[] = 'c.kyc_status = :kyc_status';
            $params['kyc_status'] = $kyc;
        }

        $finalStatus = self::enumFilter($filters, 'final_status', array_keys(CkccRenewals::FINAL_STATUSES));

        if ($finalStatus !== null) {
            $where[] = 'c.final_status = :final_status';
            $params['final_status'] = $finalStatus;
        }

        if (!empty($filters['search'])) {
            $where[] = '(a.account_number LIKE :search OR a.cif LIKE :search OR a.borrower_name LIKE :search)';
            $params['search'] = '%' . trim((string) $filters['search']) . '%';
        }

        $sql = 'SELECT c.id, a.id AS account_id, a.account_number, a.cif, a.borrower_name, a.village, a.mobile,
                       b.name AS branch_name, b.code AS branch_code,
                       u.name AS supervisor_name, s.bc_code,
                       c.loan_type, c.limit_amount, c.outstanding, c.overdue,
                       c.renewal_eligible, c.renewal_due_bucket, c.renewal_due_date, c.expected_npa_date,
                       c.days_remaining, c.kyc_status, c.aadhaar_seeded, c.mobile_linked,
                       c.aadhaar_authentication, c.renewal_consent, c.renewal_form_signed,
                       c.biometrics_completed, c.recommendation, c.final_status,
                       c.renewal_status, c.visit_date, c.customer_availability, c.documents_status,
                       c.documents_remarks, c.renewed_on, c.remarks,
                       (SELECT COUNT(*) FROM visit_photos p WHERE p.visit_id = c.visit_id) AS photos
                  FROM ckcc_renewals c
                  JOIN loan_accounts a ON a.id = c.loan_account_id
                  JOIN branches b ON b.id = c.branch_id
             LEFT JOIN bc_supervisors s ON s.id = c.bc_supervisor_id
             LEFT JOIN users u ON u.id = s.user_id
                 WHERE ' . implode(' AND ', $where)
            . ' ORDER BY c.updated_at DESC, c.id DESC';

        return [
            'sql' => $sql,
            'params' => $params,
            'columns' => [
                ['key' => 'account_number', 'label' => 'Account', 'weight' => 1.2],
                ['key' => 'cif', 'label' => 'CIF', 'weight' => 0.9],
                ['key' => 'borrower_name', 'label' => 'Borrower', 'weight' => 1.3],
                ['key' => 'branch_name', 'label' => 'Branch', 'weight' => 1.0],
                ['key' => 'loan_type', 'label' => 'Loan type', 'weight' => 1.0],
                ['key' => 'limit_amount', 'label' => 'Limit', 'type' => 'money', 'align' => 'right', 'weight' => 1.0],
                ['key' => 'outstanding', 'label' => 'Outstanding', 'type' => 'money', 'align' => 'right', 'weight' => 1.0],
                ['key' => 'overdue', 'label' => 'Overdue', 'type' => 'money', 'align' => 'right', 'weight' => 1.0],
                ['key' => 'renewal_eligible', 'label' => 'Eligible', 'type' => 'boolean', 'align' => 'center', 'weight' => 0.7],
                [
                    'key' => 'renewal_due_bucket',
                    'label' => 'Renewal due',
                    'type' => 'labels',
                    'labels' => CkccRenewals::DUE_BUCKETS,
                    'weight' => 1.0,
                ],
                ['key' => 'renewal_due_date', 'label' => 'Due date', 'type' => 'date', 'weight' => 0.9],
                ['key' => 'days_remaining', 'label' => 'Days left', 'align' => 'right', 'weight' => 0.7],
                ['key' => 'expected_npa_date', 'label' => 'Expected NPA', 'type' => 'date', 'weight' => 0.9],
                [
                    'key' => 'kyc_status',
                    'label' => 'KYC',
                    'type' => 'labels',
                    'labels' => CkccRenewals::KYC_STATUSES,
                    'weight' => 0.8,
                ],
                ['key' => 'aadhaar_seeded', 'label' => 'Aadhaar seeded', 'type' => 'boolean', 'align' => 'center', 'weight' => 0.8],
                ['key' => 'mobile_linked', 'label' => 'Mobile linked', 'type' => 'boolean', 'align' => 'center', 'weight' => 0.8],
                [
                    'key' => 'aadhaar_authentication',
                    'label' => 'Aadhaar auth',
                    'type' => 'labels',
                    'labels' => CkccRenewals::AUTHENTICATION,
                    'weight' => 0.9,
                ],
                ['key' => 'renewal_consent', 'label' => 'Consent', 'type' => 'boolean', 'align' => 'center', 'weight' => 0.7],
                ['key' => 'renewal_form_signed', 'label' => 'Form signed', 'type' => 'boolean', 'align' => 'center', 'weight' => 0.8],
                ['key' => 'biometrics_completed', 'label' => 'Biometrics', 'type' => 'boolean', 'align' => 'center', 'weight' => 0.8],
                [
                    'key' => 'renewal_status',
                    'label' => 'Renewal status',
                    'type' => 'labels',
                    'labels' => CkccRenewals::STATUSES,
                    'weight' => 1.2,
                ],
                [
                    'key' => 'customer_availability',
                    'label' => 'Customer',
                    'type' => 'labels',
                    'labels' => CkccRenewals::AVAILABILITY,
                    'weight' => 1.0,
                ],
                [
                    'key' => 'documents_status',
                    'label' => 'Documents',
                    'type' => 'labels',
                    'labels' => CkccRenewals::DOCUMENT_STATUSES,
                    'weight' => 1.0,
                ],
                [
                    'key' => 'recommendation',
                    'label' => 'Recommendation',
                    'type' => 'labels',
                    'labels' => CkccRenewals::RECOMMENDATIONS,
                    'weight' => 1.4,
                ],
                [
                    'key' => 'final_status',
                    'label' => 'Final status',
                    'type' => 'labels',
                    'labels' => CkccRenewals::FINAL_STATUSES,
                    'weight' => 1.2,
                ],
                ['key' => 'visit_date', 'label' => 'Visit date', 'type' => 'date', 'weight' => 0.9],
                ['key' => 'remarks', 'label' => 'Remarks', 'weight' => 1.6],
            ],
        ];
    }

    private static function recovery(array $filters): array
    {
        [$scope, $params] = self::scope('r', $filters);
        $range = self::dateRange($filters);
        $params += ['from' => $range['from'], 'to' => $range['to']];

        $where = [$scope, 'r.recovery_date BETWEEN :from AND :to'];

        if (!empty($filters['bc_supervisor_id'])) {
            $where[] = 'r.bc_supervisor_id = :bc';
            $params['bc'] = (int) $filters['bc_supervisor_id'];
        }

        if (!empty($filters['payment_mode'])) {
            $where[] = 'r.payment_mode = :mode';
            $params['mode'] = (string) $filters['payment_mode'];
        }

        $status = self::enumFilter($filters, 'status', ['recorded', 'verified', 'rejected']);

        if ($status !== null) {
            $where[] = 'r.status = :status';
            $params['status'] = $status;
        }

        if (!empty($filters['search'])) {
            $where[] = '(a.account_number LIKE :search OR a.borrower_name LIKE :search OR r.receipt_number LIKE :search)';
            $params['search'] = '%' . trim((string) $filters['search']) . '%';
        }

        $sql = 'SELECT r.id, r.recovery_date, r.amount, r.payment_mode, r.receipt_number, r.status, r.remarks,
                       a.account_number, a.cif, a.borrower_name, a.village, a.outstanding, a.overdue,
                       b.name AS branch_name, b.code AS branch_code,
                       u.name AS supervisor_name, s.bc_code,
                       v.visit_date, v.uuid AS visit_uuid,
                       verifier.name AS verified_by_name, r.verified_at
                  FROM recoveries r
                  JOIN loan_accounts a ON a.id = r.loan_account_id
                  JOIN branches b ON b.id = r.branch_id
             LEFT JOIN bc_supervisors s ON s.id = r.bc_supervisor_id
             LEFT JOIN users u ON u.id = s.user_id
             LEFT JOIN visits v ON v.id = r.visit_id
             LEFT JOIN users verifier ON verifier.id = r.verified_by
                 WHERE ' . implode(' AND ', $where)
            . ' ORDER BY r.recovery_date DESC, r.id DESC';

        return [
            'sql' => $sql,
            'params' => $params,
            'columns' => [
                ['key' => 'recovery_date', 'label' => 'Date', 'type' => 'date', 'weight' => 0.9],
                ['key' => 'account_number', 'label' => 'Account', 'weight' => 1.2],
                ['key' => 'borrower_name', 'label' => 'Borrower', 'weight' => 1.4],
                ['key' => 'branch_name', 'label' => 'Branch', 'weight' => 1.0],
                ['key' => 'supervisor_name', 'label' => 'BCA', 'weight' => 1.2],
                ['key' => 'amount', 'label' => 'Amount', 'type' => 'money', 'align' => 'right', 'weight' => 1.1],
                ['key' => 'payment_mode', 'label' => 'Mode', 'weight' => 0.9],
                ['key' => 'receipt_number', 'label' => 'Receipt', 'weight' => 1.0],
                ['key' => 'status', 'label' => 'Status', 'type' => 'enum', 'align' => 'center', 'weight' => 0.9],
                ['key' => 'outstanding', 'label' => 'Outstanding', 'type' => 'money', 'align' => 'right', 'weight' => 1.0],
                ['key' => 'remarks', 'label' => 'Remarks', 'weight' => 1.5],
            ],
        ];
    }

    private static function ptp(array $filters): array
    {
        [$scope, $params] = self::scope('p', $filters);
        $range = self::dateRange($filters, 60);
        $params += ['from' => $range['from'], 'to' => $range['to']];

        $where = [$scope, 'p.promise_date BETWEEN :from AND :to'];

        if (!empty($filters['bc_supervisor_id'])) {
            $where[] = 'p.bc_supervisor_id = :bc';
            $params['bc'] = (int) $filters['bc_supervisor_id'];
        }

        $status = self::enumFilter($filters, 'status', ['pending', 'kept', 'partially_kept', 'broken', 'cancelled']);

        if ($status !== null) {
            $where[] = 'p.status = :status';
            $params['status'] = $status;
        }

        if (!empty($filters['search'])) {
            $where[] = '(a.account_number LIKE :search OR a.borrower_name LIKE :search)';
            $params['search'] = '%' . trim((string) $filters['search']) . '%';
        }

        $sql = 'SELECT p.id, p.promise_date, p.promise_amount, p.kept_amount, p.status, p.followup_date, p.remarks,
                       p.created_at,
                       a.account_number, a.borrower_name, a.village, a.mobile, a.outstanding, a.overdue,
                       b.name AS branch_name, u.name AS supervisor_name, s.bc_code,
                       v.visit_date
                  FROM promises p
                  JOIN loan_accounts a ON a.id = p.loan_account_id
                  JOIN branches b ON b.id = p.branch_id
             LEFT JOIN bc_supervisors s ON s.id = p.bc_supervisor_id
             LEFT JOIN users u ON u.id = s.user_id
             LEFT JOIN visits v ON v.id = p.visit_id
                 WHERE ' . implode(' AND ', $where)
            . ' ORDER BY p.promise_date DESC, p.id DESC';

        return [
            'sql' => $sql,
            'params' => $params,
            'columns' => [
                ['key' => 'promise_date', 'label' => 'Promise date', 'type' => 'date', 'weight' => 1.0],
                ['key' => 'account_number', 'label' => 'Account', 'weight' => 1.2],
                ['key' => 'borrower_name', 'label' => 'Borrower', 'weight' => 1.4],
                ['key' => 'branch_name', 'label' => 'Branch', 'weight' => 1.0],
                ['key' => 'supervisor_name', 'label' => 'BCA', 'weight' => 1.2],
                ['key' => 'promise_amount', 'label' => 'Promised', 'type' => 'money', 'align' => 'right', 'weight' => 1.0],
                ['key' => 'kept_amount', 'label' => 'Paid', 'type' => 'money', 'align' => 'right', 'weight' => 1.0],
                ['key' => 'status', 'label' => 'Status', 'type' => 'enum', 'align' => 'center', 'weight' => 1.0],
                ['key' => 'followup_date', 'label' => 'Follow-up', 'type' => 'date', 'weight' => 0.9],
                ['key' => 'remarks', 'label' => 'Remarks', 'weight' => 1.6],
            ],
        ];
    }

    private static function followup(array $filters): array
    {
        [$scope, $params] = self::scope('f', $filters);
        $range = self::dateRange($filters, 60);
        $params += ['from' => $range['from'], 'to' => $range['to']];

        $where = [$scope, 'f.followup_date BETWEEN :from AND :to'];

        if (!empty($filters['bc_supervisor_id'])) {
            $where[] = 'f.bc_supervisor_id = :bc';
            $params['bc'] = (int) $filters['bc_supervisor_id'];
        }

        $status = self::enumFilter($filters, 'status', ['pending', 'done', 'cancelled']);

        if ($status !== null) {
            $where[] = 'f.status = :status';
            $params['status'] = $status;
        }

        $action = self::enumFilter($filters, 'action', ['call', 'visit', 'notice', 'legal', 'other']);

        if ($action !== null) {
            $where[] = 'f.action = :action';
            $params['action'] = $action;
        }

        if (!empty($filters['search'])) {
            $where[] = '(a.account_number LIKE :search OR a.borrower_name LIKE :search)';
            $params['search'] = '%' . trim((string) $filters['search']) . '%';
        }

        $sql = 'SELECT f.id, f.followup_date, f.action, f.status, f.notes, f.completed_at,
                       a.account_number, a.borrower_name, a.village, a.mobile, a.overdue,
                       b.name AS branch_name, u.name AS supervisor_name, s.bc_code,
                       p.promise_amount, p.promise_date
                  FROM followups f
                  JOIN loan_accounts a ON a.id = f.loan_account_id
                  JOIN branches b ON b.id = f.branch_id
             LEFT JOIN bc_supervisors s ON s.id = f.bc_supervisor_id
             LEFT JOIN users u ON u.id = s.user_id
             LEFT JOIN promises p ON p.id = f.promise_id
                 WHERE ' . implode(' AND ', $where)
            . ' ORDER BY f.followup_date ASC, f.id DESC';

        return [
            'sql' => $sql,
            'params' => $params,
            'columns' => [
                ['key' => 'followup_date', 'label' => 'Due date', 'type' => 'date', 'weight' => 1.0],
                ['key' => 'action', 'label' => 'Action', 'type' => 'enum', 'weight' => 0.9],
                ['key' => 'account_number', 'label' => 'Account', 'weight' => 1.2],
                ['key' => 'borrower_name', 'label' => 'Borrower', 'weight' => 1.4],
                ['key' => 'branch_name', 'label' => 'Branch', 'weight' => 1.0],
                ['key' => 'supervisor_name', 'label' => 'BCA', 'weight' => 1.2],
                ['key' => 'overdue', 'label' => 'Overdue', 'type' => 'money', 'align' => 'right', 'weight' => 1.0],
                ['key' => 'status', 'label' => 'Status', 'type' => 'enum', 'align' => 'center', 'weight' => 0.9],
                ['key' => 'notes', 'label' => 'Notes', 'weight' => 1.8],
            ],
        ];
    }

    private static function attendance(array $filters): array
    {
        [$scope, $params] = self::scope('t', $filters);
        $range = self::dateRange($filters);
        $params += ['from' => $range['from'], 'to' => $range['to']];

        $where = [$scope, 't.attendance_date BETWEEN :from AND :to'];

        if (!empty($filters['bc_supervisor_id'])) {
            $where[] = 't.bc_supervisor_id = :bc';
            $params['bc'] = (int) $filters['bc_supervisor_id'];
        }

        $status = self::enumFilter($filters, 'status', ['present', 'half_day', 'absent', 'leave', 'holiday']);

        if ($status !== null) {
            $where[] = 't.status = :status';
            $params['status'] = $status;
        }

        if (!empty($filters['search'])) {
            $where[] = '(u.name LIKE :search OR s.bc_code LIKE :search)';
            $params['search'] = '%' . trim((string) $filters['search']) . '%';
        }

        $sql = 'SELECT t.id, t.attendance_date, t.check_in_at, t.check_out_at, t.working_minutes,
                       t.visits_count, t.status, t.check_in_address, t.check_out_address,
                       t.check_in_lat, t.check_in_lng, t.selfie_path, t.remarks,
                       u.name AS supervisor_name, s.bc_code, b.name AS branch_name,
                       COALESCE(r.recovered, 0) AS recovered
                  FROM attendance t
                  JOIN bc_supervisors s ON s.id = t.bc_supervisor_id
                  JOIN users u ON u.id = s.user_id
                  JOIN branches b ON b.id = t.branch_id
             LEFT JOIN (
                       SELECT bc_supervisor_id, recovery_date, SUM(amount) AS recovered
                         FROM recoveries WHERE status <> \'rejected\'
                        GROUP BY bc_supervisor_id, recovery_date
                  ) r ON r.bc_supervisor_id = t.bc_supervisor_id AND r.recovery_date = t.attendance_date
                 WHERE ' . implode(' AND ', $where)
            . ' ORDER BY t.attendance_date DESC, u.name ASC';

        return [
            'sql' => $sql,
            'params' => $params,
            'columns' => [
                ['key' => 'attendance_date', 'label' => 'Date', 'type' => 'date', 'weight' => 0.9],
                ['key' => 'supervisor_name', 'label' => 'BCA', 'weight' => 1.3],
                ['key' => 'bc_code', 'label' => 'BC Code', 'weight' => 0.8],
                ['key' => 'branch_name', 'label' => 'Branch', 'weight' => 1.0],
                ['key' => 'check_in_at', 'label' => 'Check in', 'type' => 'time', 'align' => 'center', 'weight' => 0.8],
                ['key' => 'check_out_at', 'label' => 'Check out', 'type' => 'time', 'align' => 'center', 'weight' => 0.8],
                ['key' => 'working_minutes', 'label' => 'Hours', 'type' => 'hours', 'align' => 'center', 'weight' => 0.8],
                ['key' => 'visits_count', 'label' => 'Visits', 'type' => 'count', 'align' => 'center', 'weight' => 0.6],
                ['key' => 'recovered', 'label' => 'Recovery', 'type' => 'money', 'align' => 'right', 'weight' => 1.0],
                ['key' => 'status', 'label' => 'Status', 'type' => 'enum', 'align' => 'center', 'weight' => 0.8],
                ['key' => 'check_in_address', 'label' => 'Check-in location', 'weight' => 1.8],
            ],
        ];
    }

    /**
     * SSS enrolments per supervisor per day.
     *
     * The four scheme columns are written out rather than looped because a report column
     * carries a label, a type and a print weight that the column name alone cannot supply.
     */
    private static function sss(array $filters): array
    {
        [$scope, $params] = self::scope('e', $filters);
        $range = self::dateRange($filters);
        $params += ['from' => $range['from'], 'to' => $range['to']];

        $where = [$scope, 'e.enrolment_date BETWEEN :from AND :to'];

        if (!empty($filters['bc_supervisor_id'])) {
            $where[] = 'e.bc_supervisor_id = :bc';
            $params['bc'] = (int) $filters['bc_supervisor_id'];
        }

        $source = self::enumFilter($filters, 'source', ['app', 'panel']);

        if ($source !== null) {
            $where[] = 'e.source = :source';
            $params['source'] = $source;
        }

        if (!empty($filters['search'])) {
            $where[] = '(u.name LIKE :search OR s.bc_code LIKE :search OR e.remarks LIKE :search)';
            $params['search'] = '%' . trim((string) $filters['search']) . '%';
        }

        $sql = 'SELECT e.id, e.enrolment_date, e.apy_count, e.pmjjby_count, e.pmsby_count, e.pmjdy_count,
                       (e.apy_count + e.pmjjby_count + e.pmsby_count + e.pmjdy_count) AS total,
                       e.source, e.remarks,
                       u.name AS supervisor_name, s.bc_code, b.name AS branch_name
                  FROM sss_enrolments e
                  JOIN bc_supervisors s ON s.id = e.bc_supervisor_id
                  JOIN users u ON u.id = s.user_id
                  JOIN branches b ON b.id = e.branch_id
                 WHERE ' . implode(' AND ', $where)
            . ' ORDER BY e.enrolment_date DESC, u.name ASC';

        return [
            'sql' => $sql,
            'params' => $params,
            'columns' => [
                ['key' => 'enrolment_date', 'label' => 'Date', 'type' => 'date', 'weight' => 0.9],
                ['key' => 'supervisor_name', 'label' => 'BCA', 'weight' => 1.3],
                ['key' => 'bc_code', 'label' => 'BC Code', 'weight' => 0.8],
                ['key' => 'branch_name', 'label' => 'Branch', 'weight' => 1.0],
                ['key' => 'apy_count', 'label' => 'APY', 'type' => 'count', 'align' => 'right', 'weight' => 0.6],
                ['key' => 'pmjjby_count', 'label' => 'PMJJBY', 'type' => 'count', 'align' => 'right', 'weight' => 0.7],
                ['key' => 'pmsby_count', 'label' => 'PMSBY', 'type' => 'count', 'align' => 'right', 'weight' => 0.7],
                ['key' => 'pmjdy_count', 'label' => 'PMJDY', 'type' => 'count', 'align' => 'right', 'weight' => 0.7],
                ['key' => 'total', 'label' => 'Total', 'type' => 'count', 'align' => 'right', 'weight' => 0.7],
                ['key' => 'source', 'label' => 'Source', 'type' => 'enum', 'align' => 'center', 'weight' => 0.7],
                ['key' => 'remarks', 'label' => 'Remarks', 'weight' => 1.6],
            ],
        ];
    }

    /**
     * SSS target against achievement per supervisor, ranked — the printable half of the
     * enrolment dashboard.
     *
     * TARGETS ARE STORED PER WORKING DAY, SO THE MULTIPLIER IS BUILT HERE
     *
     * `sss_targets` holds a daily figure per month. What a supervisor owed over the window
     * being reported is that figure times the working days of each month *inside* the
     * window, and working days come from a setting the database knows nothing about. So the
     * counts are worked out in PHP by App\Services\Sss and injected as bound parameters,
     * one per month — which keeps this a normal report definition, paged and exported by the
     * same machinery as every other, instead of a hand-rolled screen.
     *
     * A supervisor with a target and nothing recorded still appears, with the whole target
     * as their gap. That row is the entire point of the report, and a query starting from
     * the enrolments could never produce it.
     */
    private static function sssTarget(array $filters): array
    {
        [$scope, $params] = self::scope('s', $filters);
        $range = self::dateRange($filters);
        $params += ['from' => $range['from'], 'to' => $range['to']];

        $where = [$scope];

        if (!empty($filters['bc_supervisor_id'])) {
            $where[] = 's.id = :bc';
            $params['bc'] = (int) $filters['bc_supervisor_id'];
        }

        if (!empty($filters['search'])) {
            $where[] = '(u.name LIKE :search OR s.bc_code LIKE :search)';
            $params['search'] = '%' . trim((string) $filters['search']) . '%';
        }

        // One CASE arm per month in the window: target month => working days it contributes.
        //
        // These are written into the SQL rather than bound, because the multiplier is
        // repeated once per scheme column and this connection runs with
        // PDO::ATTR_EMULATE_PREPARES => false, where a named placeholder used more than once
        // is an error rather than a convenience. Nothing user-supplied reaches the string:
        // the month is re-formatted through date('Y-m-01') and the day count is an integer
        // counted in a loop, so both are regenerated here rather than trusted.
        $windows = Sss::monthWorkingDays((string) $range['from'], (string) $range['to']);
        $monthLiterals = [];
        $cases = [];

        foreach ($windows as $month => $days) {
            $safeMonth = date('Y-m-01', (int) (strtotime((string) $month) ?: time()));
            $monthLiterals[] = sprintf("'%s'", $safeMonth);
            $cases[] = sprintf("WHEN '%s' THEN %d", $safeMonth, (int) $days);
        }

        // No month in range means no target can apply; 0 keeps the arithmetic honest rather
        // than dividing by a NULL later.
        $multiplier = $cases === []
            ? '0'
            : sprintf('CASE t2.target_month %s ELSE 0 END', implode(' ', $cases));

        $targetSums = [];

        foreach (Sss::targetSchemes() as $column => $abbreviation) {
            $targetSums[] = sprintf('COALESCE(SUM(t2.`%s` * (%s)), 0) AS `%s`', $column, $multiplier, $column);
        }

        $totalTargetExpression = [];

        foreach (array_keys(Sss::targetSchemes()) as $column) {
            $totalTargetExpression[] = sprintf('t2.`%s`', $column);
        }

        $targetSubquery = $monthLiterals === []
            ? null
            : sprintf(
                'SELECT t2.bc_supervisor_id, %s,
                        COALESCE(SUM((%s) * (%s)), 0) AS total_target
                   FROM sss_targets t2
                  WHERE t2.target_month IN (%s)
               GROUP BY t2.bc_supervisor_id',
                implode(', ', $targetSums),
                implode(' + ', $totalTargetExpression),
                $multiplier,
                implode(', ', $monthLiterals)
            );

        $achievementSums = [];

        foreach (array_keys(Sss::schemes()) as $column) {
            $achievementSums[] = sprintf('SUM(e2.`%s`) AS `%s`', $column, $column);
        }

        $selectTargets = [];

        foreach (array_keys(Sss::targetSchemes()) as $column) {
            $selectTargets[] = $targetSubquery === null
                ? sprintf('0 AS `%s`', $column)
                : sprintf('COALESCE(t.`%s`, 0) AS `%s`', $column, $column);
        }

        $selectAchievements = [];

        foreach (array_keys(Sss::schemes()) as $column) {
            $selectAchievements[] = sprintf('COALESCE(e.`%s`, 0) AS `%s`', $column, $column);
        }

        $totalTargetColumn = $targetSubquery === null ? '0' : 'COALESCE(t.total_target, 0)';

        $sql = sprintf(
            'SELECT s.id AS bc_supervisor_id, u.name AS supervisor_name, s.bc_code,
                    b.name AS branch_name,
                    COALESCE(e.days, 0) AS days_reported,
                    %s AS total_target,
                    COALESCE(e.total, 0) AS total_achievement,
                    %s,
                    %s
               FROM bc_supervisors s
               JOIN users u ON u.id = s.user_id
          LEFT JOIN branches b ON b.id = s.branch_id
          LEFT JOIN (
                    SELECT e2.bc_supervisor_id, COUNT(*) AS days, %s,
                           SUM(e2.apy_count + e2.pmjjby_count + e2.pmsby_count + e2.pmjdy_count) AS total
                      FROM sss_enrolments e2
                     WHERE e2.enrolment_date BETWEEN :from AND :to
                  GROUP BY e2.bc_supervisor_id
               ) e ON e.bc_supervisor_id = s.id
               %s
              WHERE %s
           ORDER BY (CASE WHEN %s > 0 THEN 1 ELSE 0 END) DESC,
                    (COALESCE(e.total, 0) / NULLIF(%s, 0)) DESC,
                    COALESCE(e.total, 0) DESC,
                    u.name ASC',
            $totalTargetColumn,
            implode(', ', $selectAchievements),
            implode(', ', $selectTargets),
            implode(', ', $achievementSums),
            $targetSubquery === null
                ? ''
                : sprintf('LEFT JOIN (%s) t ON t.bc_supervisor_id = s.id', $targetSubquery),
            implode(' AND ', $where),
            $totalTargetColumn,
            $totalTargetColumn
        );

        return [
            'sql' => $sql,
            'params' => $params,
            'columns' => [
                ['key' => 'supervisor_name', 'label' => 'BCA', 'weight' => 1.3],
                ['key' => 'bc_code', 'label' => 'BC Code', 'weight' => 0.8],
                ['key' => 'branch_name', 'label' => 'Branch', 'weight' => 1.0],
                ['key' => 'days_reported', 'label' => 'Days', 'type' => 'count', 'align' => 'center', 'weight' => 0.6],
                ['key' => 'total_target', 'label' => 'Target', 'type' => 'count', 'align' => 'right', 'weight' => 0.7],
                ['key' => 'total_achievement', 'label' => 'Achieved', 'type' => 'count', 'align' => 'right', 'weight' => 0.8],
                ['key' => 'achievement_percent', 'label' => '%', 'type' => 'computed', 'align' => 'right', 'weight' => 0.7],
                ['key' => 'achievement_gap', 'label' => 'Gap', 'type' => 'computed', 'align' => 'right', 'weight' => 0.7],
                ['key' => 'apy_count', 'label' => 'APY done', 'type' => 'count', 'align' => 'right', 'weight' => 0.7],
                ['key' => 'apy_target', 'label' => 'APY target', 'type' => 'count', 'align' => 'right', 'weight' => 0.8],
                ['key' => 'pmjjby_count', 'label' => 'PMJJBY done', 'type' => 'count', 'align' => 'right', 'weight' => 0.8],
                ['key' => 'pmjjby_target', 'label' => 'PMJJBY target', 'type' => 'count', 'align' => 'right', 'weight' => 0.9],
                ['key' => 'pmsby_count', 'label' => 'PMSBY done', 'type' => 'count', 'align' => 'right', 'weight' => 0.8],
                ['key' => 'pmsby_target', 'label' => 'PMSBY target', 'type' => 'count', 'align' => 'right', 'weight' => 0.9],
                ['key' => 'pmjdy_count', 'label' => 'PMJDY done', 'type' => 'count', 'align' => 'right', 'weight' => 0.8],
                ['key' => 'pmjdy_target', 'label' => 'PMJDY target', 'type' => 'count', 'align' => 'right', 'weight' => 0.9],
            ],
        ];
    }

    private static function gps(array $filters): array
    {
        [$scope, $params] = self::scope('v', $filters);
        $range = self::dateRange($filters);
        $params += ['from' => $range['from'], 'to' => $range['to']];

        $where = [$scope, 'v.visit_date BETWEEN :from AND :to'];

        if (!empty($filters['bc_supervisor_id'])) {
            $where[] = 'v.bc_supervisor_id = :bc';
            $params['bc'] = (int) $filters['bc_supervisor_id'];
        }

        if (self::truthy($filters, 'gps_invalid_only')) {
            $where[] = 'g.is_valid = 0';
        }

        if (!empty($filters['search'])) {
            $where[] = '(a.account_number LIKE :search OR a.borrower_name LIKE :search OR g.address LIKE :search)';
            $params['search'] = '%' . trim((string) $filters['search']) . '%';
        }

        $sql = 'SELECT g.id, v.visit_date, g.event, g.latitude, g.longitude, g.accuracy, g.provider,
                       g.is_mock, g.is_valid, g.validation_note, g.address, g.captured_at, g.server_time,
                       a.account_number, a.borrower_name, a.village,
                       b.name AS branch_name, u.name AS supervisor_name, s.bc_code
                  FROM visit_gps g
                  JOIN visits v ON v.id = g.visit_id
                  JOIN loan_accounts a ON a.id = v.loan_account_id
                  JOIN branches b ON b.id = v.branch_id
                  JOIN bc_supervisors s ON s.id = v.bc_supervisor_id
                  JOIN users u ON u.id = s.user_id
                 WHERE ' . implode(' AND ', $where)
            . ' ORDER BY v.visit_date DESC, g.id DESC';

        return [
            'sql' => $sql,
            'params' => $params,
            'columns' => [
                ['key' => 'visit_date', 'label' => 'Visit date', 'type' => 'date', 'weight' => 0.9],
                ['key' => 'account_number', 'label' => 'Account', 'weight' => 1.2],
                ['key' => 'borrower_name', 'label' => 'Borrower', 'weight' => 1.3],
                ['key' => 'supervisor_name', 'label' => 'BCA', 'weight' => 1.2],
                ['key' => 'event', 'label' => 'Event', 'type' => 'enum', 'align' => 'center', 'weight' => 0.7],
                ['key' => 'latitude', 'label' => 'Latitude', 'align' => 'right', 'weight' => 1.0],
                ['key' => 'longitude', 'label' => 'Longitude', 'align' => 'right', 'weight' => 1.0],
                ['key' => 'accuracy', 'label' => 'Accuracy (m)', 'align' => 'right', 'weight' => 0.9],
                ['key' => 'provider', 'label' => 'Provider', 'weight' => 0.8],
                ['key' => 'is_mock', 'label' => 'Mock', 'type' => 'boolean', 'align' => 'center', 'weight' => 0.6],
                ['key' => 'is_valid', 'label' => 'Valid', 'type' => 'boolean', 'align' => 'center', 'weight' => 0.6],
                ['key' => 'validation_note', 'label' => 'Validation note', 'weight' => 1.6],
                ['key' => 'address', 'label' => 'Address', 'weight' => 1.6],
            ],
        ];
    }

    private static function photo(array $filters): array
    {
        [$scope, $params] = self::scope('v', $filters);
        $range = self::dateRange($filters);
        $params += ['from' => $range['from'], 'to' => $range['to']];

        $where = [$scope, 'v.visit_date BETWEEN :from AND :to'];

        if (!empty($filters['bc_supervisor_id'])) {
            $where[] = 'v.bc_supervisor_id = :bc';
            $params['bc'] = (int) $filters['bc_supervisor_id'];
        }

        $photoType = self::enumFilter($filters, 'photo_type', array_merge(array_keys(photo_types()), ['selfie']));

        if ($photoType !== null) {
            $where[] = 'p.photo_type = :photo_type';
            $params['photo_type'] = $photoType;
        }

        if (!empty($filters['search'])) {
            $where[] = '(a.account_number LIKE :search OR a.borrower_name LIKE :search)';
            $params['search'] = '%' . trim((string) $filters['search']) . '%';
        }

        $sql = 'SELECT p.id, p.photo_type, p.file_path, p.file_name, p.file_size, p.width, p.height,
                       p.latitude, p.longitude, p.accuracy, p.address, p.captured_at, p.watermarked, p.caption,
                       v.id AS visit_id, v.visit_date, v.visit_status,
                       a.account_number, a.borrower_name, a.village,
                       b.name AS branch_name, u.name AS supervisor_name, s.bc_code
                  FROM visit_photos p
                  JOIN visits v ON v.id = p.visit_id
                  JOIN loan_accounts a ON a.id = v.loan_account_id
                  JOIN branches b ON b.id = v.branch_id
                  JOIN bc_supervisors s ON s.id = v.bc_supervisor_id
                  JOIN users u ON u.id = s.user_id
                 WHERE ' . implode(' AND ', $where)
            . ' ORDER BY v.visit_date DESC, p.id DESC';

        return [
            'sql' => $sql,
            'params' => $params,
            'columns' => [
                ['key' => 'visit_date', 'label' => 'Visit date', 'type' => 'date', 'weight' => 0.9],
                ['key' => 'account_number', 'label' => 'Account', 'weight' => 1.2],
                ['key' => 'borrower_name', 'label' => 'Borrower', 'weight' => 1.3],
                ['key' => 'supervisor_name', 'label' => 'BCA', 'weight' => 1.2],
                ['key' => 'photo_type', 'label' => 'Type', 'type' => 'enum', 'weight' => 0.9],
                ['key' => 'captured_at', 'label' => 'Captured', 'type' => 'datetime', 'weight' => 1.2],
                ['key' => 'watermarked', 'label' => 'Watermark', 'type' => 'boolean', 'align' => 'center', 'weight' => 0.8],
                ['key' => 'latitude', 'label' => 'Latitude', 'align' => 'right', 'weight' => 1.0],
                ['key' => 'longitude', 'label' => 'Longitude', 'align' => 'right', 'weight' => 1.0],
                ['key' => 'address', 'label' => 'Address', 'weight' => 1.8],
            ],
        ];
    }

    private static function target(array $filters): array
    {
        [$scope, $params] = self::scope('t', $filters);
        $range = self::dateRange($filters, 60);
        $params += ['from' => $range['from'], 'to' => $range['to']];

        $where = [$scope, 't.period_start <= :to', 't.period_end >= :from'];

        if (!empty($filters['bc_supervisor_id'])) {
            $where[] = 't.bc_supervisor_id = :bc';
            $params['bc'] = (int) $filters['bc_supervisor_id'];
        }

        $period = self::enumFilter($filters, 'period', ['daily', 'monthly']);

        if ($period !== null) {
            $where[] = 't.period = :period';
            $params['period'] = $period;
        }

        // Achievement is computed against the target's own window, which is what
        // makes daily and monthly targets comparable in one table.
        $sql = "SELECT t.id, t.scope, t.period, t.period_start, t.period_end,
                       t.visit_target, t.recovery_target,
                       COALESCE(u.name, CONCAT('Branch: ', b.name)) AS subject,
                       s.bc_code, b.name AS branch_name,
                       COALESCE(vv.visits, 0) AS visits_done,
                       COALESCE(rr.recovered, 0) AS recovery_done
                  FROM targets t
             LEFT JOIN bc_supervisors s ON s.id = t.bc_supervisor_id
             LEFT JOIN users u ON u.id = s.user_id
             LEFT JOIN branches b ON b.id = COALESCE(t.branch_id, s.branch_id)
             LEFT JOIN (
                       SELECT bc_supervisor_id, branch_id, visit_date, COUNT(*) AS visits
                         FROM visits WHERE status <> 'draft'
                        GROUP BY bc_supervisor_id, branch_id, visit_date
                  ) v ON 1 = 0
             LEFT JOIN (
                       SELECT t2.id AS target_id, COUNT(v2.id) AS visits
                         FROM targets t2
                    LEFT JOIN visits v2
                           ON v2.status <> 'draft'
                          AND v2.visit_date BETWEEN t2.period_start AND t2.period_end
                          AND ((t2.scope = 'bc_supervisor' AND v2.bc_supervisor_id = t2.bc_supervisor_id)
                            OR (t2.scope = 'branch' AND v2.branch_id = t2.branch_id))
                        GROUP BY t2.id
                  ) vv ON vv.target_id = t.id
             LEFT JOIN (
                       SELECT t3.id AS target_id, COALESCE(SUM(r3.amount), 0) AS recovered
                         FROM targets t3
                    LEFT JOIN recoveries r3
                           ON r3.status <> 'rejected'
                          AND r3.recovery_date BETWEEN t3.period_start AND t3.period_end
                          AND ((t3.scope = 'bc_supervisor' AND r3.bc_supervisor_id = t3.bc_supervisor_id)
                            OR (t3.scope = 'branch' AND r3.branch_id = t3.branch_id))
                        GROUP BY t3.id
                  ) rr ON rr.target_id = t.id
                 WHERE " . implode(' AND ', $where)
            . ' ORDER BY t.period_start DESC, subject ASC';

        return [
            'sql' => $sql,
            'params' => $params,
            'columns' => [
                ['key' => 'period', 'label' => 'Period', 'type' => 'enum', 'weight' => 0.8],
                ['key' => 'period_start', 'label' => 'From', 'type' => 'date', 'weight' => 0.9],
                ['key' => 'period_end', 'label' => 'To', 'type' => 'date', 'weight' => 0.9],
                ['key' => 'subject', 'label' => 'Target for', 'weight' => 1.5],
                ['key' => 'branch_name', 'label' => 'Branch', 'weight' => 1.0],
                ['key' => 'visit_target', 'label' => 'Visit target', 'type' => 'count', 'align' => 'center', 'weight' => 0.9],
                ['key' => 'visits_done', 'label' => 'Visits done', 'type' => 'count', 'align' => 'center', 'weight' => 0.9],
                ['key' => 'visit_pending', 'label' => 'Visits pending', 'type' => 'computed', 'align' => 'center', 'weight' => 1.0],
                ['key' => 'visit_percent', 'label' => 'Visit %', 'type' => 'computed', 'align' => 'right', 'weight' => 0.8],
                ['key' => 'recovery_target', 'label' => 'Recovery target', 'type' => 'money', 'align' => 'right', 'weight' => 1.1],
                ['key' => 'recovery_done', 'label' => 'Recovered', 'type' => 'money', 'align' => 'right', 'weight' => 1.1],
                ['key' => 'recovery_pending', 'label' => 'Recovery pending', 'type' => 'computed', 'align' => 'right', 'weight' => 1.1],
                ['key' => 'recovery_percent', 'label' => 'Recovery %', 'type' => 'computed', 'align' => 'right', 'weight' => 0.9],
            ],
        ];
    }

    private static function branchPerformance(array $filters): array
    {
        // The branches table is keyed by `id`, not `branch_id`.
        [$scope, $params] = self::scope('b', $filters, 'id');
        $range = self::dateRange($filters);
        $params += ['from' => $range['from'], 'to' => $range['to']];

        $sql = "SELECT b.id, b.code AS branch_code, b.name AS branch_name, b.region, b.district,
                       (SELECT COUNT(*) FROM bc_supervisors s WHERE s.branch_id = b.id AND s.status = 'active') AS supervisors,
                       (SELECT COUNT(*) FROM loan_accounts a WHERE a.branch_id = b.id AND a.status = 'active') AS accounts,
                       (SELECT COALESCE(SUM(a.outstanding), 0) FROM loan_accounts a WHERE a.branch_id = b.id AND a.status = 'active') AS outstanding,
                       (SELECT COALESCE(SUM(a.overdue), 0) FROM loan_accounts a WHERE a.branch_id = b.id AND a.status = 'active') AS overdue,
                       (SELECT COUNT(*) FROM loan_accounts a
                          JOIN account_assignments x ON x.loan_account_id = a.id AND x.is_active = 1
                         WHERE a.branch_id = b.id AND a.status = 'active') AS allocated,
                       (SELECT COUNT(*) FROM visits v
                         WHERE v.branch_id = b.id AND v.status <> 'draft' AND v.visit_date BETWEEN :from AND :to) AS visits,
                       (SELECT COALESCE(SUM(r.amount), 0) FROM recoveries r
                         WHERE r.branch_id = b.id AND r.status <> 'rejected' AND r.recovery_date BETWEEN :from AND :to) AS recovered,
                       (SELECT COUNT(*) FROM promises p
                         WHERE p.branch_id = b.id AND p.promise_date BETWEEN :from AND :to) AS promises,
                       (SELECT COUNT(*) FROM inspections i
                         WHERE i.branch_id = b.id AND i.status = 'submitted' AND i.inspection_date BETWEEN :from AND :to) AS inspections
                  FROM branches b
                 WHERE " . $scope . " AND b.status = 'active'"
            . ' ORDER BY recovered DESC, b.name ASC';

        return [
            'sql' => $sql,
            'params' => $params,
            'columns' => [
                ['key' => 'branch_code', 'label' => 'Code', 'weight' => 0.7],
                ['key' => 'branch_name', 'label' => 'Branch', 'weight' => 1.4],
                ['key' => 'supervisors', 'label' => 'BCAs', 'type' => 'count', 'align' => 'center', 'weight' => 1.0],
                ['key' => 'accounts', 'label' => 'Accounts', 'type' => 'count', 'align' => 'center', 'weight' => 0.9],
                ['key' => 'allocated', 'label' => 'Allocated', 'type' => 'count', 'align' => 'center', 'weight' => 0.9],
                ['key' => 'outstanding', 'label' => 'Outstanding', 'type' => 'money', 'align' => 'right', 'weight' => 1.2],
                ['key' => 'overdue', 'label' => 'Overdue', 'type' => 'money', 'align' => 'right', 'weight' => 1.2],
                ['key' => 'visits', 'label' => 'Visits', 'type' => 'count', 'align' => 'center', 'weight' => 0.8],
                ['key' => 'recovered', 'label' => 'Recovered', 'type' => 'money', 'align' => 'right', 'weight' => 1.2],
                ['key' => 'promises', 'label' => 'PTP', 'type' => 'count', 'align' => 'center', 'weight' => 0.7],
                ['key' => 'inspections', 'label' => 'Inspections', 'type' => 'count', 'align' => 'center', 'weight' => 0.9],
            ],
        ];
    }

    private static function bcPerformance(array $filters): array
    {
        [$scope, $params] = self::scope('s', $filters);
        $range = self::dateRange($filters);
        $params += ['from' => $range['from'], 'to' => $range['to']];

        $where = [$scope];

        if (!empty($filters['bc_supervisor_id'])) {
            $where[] = 's.id = :bc';
            $params['bc'] = (int) $filters['bc_supervisor_id'];
        }

        $sql = "SELECT s.id, s.bc_code, s.status, u.name AS supervisor_name, b.name AS branch_name,
                       (SELECT COUNT(*) FROM account_assignments x WHERE x.bc_supervisor_id = s.id AND x.is_active = 1) AS allocated,
                       (SELECT COUNT(*) FROM visits v
                         WHERE v.bc_supervisor_id = s.id AND v.status <> 'draft'
                           AND v.visit_date BETWEEN :from AND :to) AS visits,
                       (SELECT COUNT(DISTINCT v.loan_account_id) FROM visits v
                         WHERE v.bc_supervisor_id = s.id AND v.status <> 'draft'
                           AND v.visit_date BETWEEN :from AND :to) AS accounts_covered,
                       (SELECT COALESCE(SUM(r.amount), 0) FROM recoveries r
                         WHERE r.bc_supervisor_id = s.id AND r.status <> 'rejected'
                           AND r.recovery_date BETWEEN :from AND :to) AS recovered,
                       (SELECT COUNT(*) FROM promises p
                         WHERE p.bc_supervisor_id = s.id AND p.promise_date BETWEEN :from AND :to) AS promises,
                       (SELECT COUNT(*) FROM promises p
                         WHERE p.bc_supervisor_id = s.id AND p.status = 'kept'
                           AND p.promise_date BETWEEN :from AND :to) AS promises_kept,
                       (SELECT COUNT(*) FROM attendance t
                         WHERE t.bc_supervisor_id = s.id AND t.status IN ('present','half_day')
                           AND t.attendance_date BETWEEN :from AND :to) AS days_present,
                       (SELECT COUNT(*) FROM visits v
                         WHERE v.bc_supervisor_id = s.id AND v.is_late = 1
                           AND v.visit_date BETWEEN :from AND :to) AS late_visits,
                       (SELECT COUNT(*) FROM inspections i
                         WHERE i.bc_supervisor_id = s.id AND i.status = 'submitted'
                           AND i.inspection_date BETWEEN :from AND :to) AS inspections,
                       (SELECT COUNT(*) FROM inspections i
                         WHERE i.bc_supervisor_id = s.id AND i.status = 'submitted'
                           AND i.result = 'work_verified'
                           AND i.inspection_date BETWEEN :from AND :to) AS verified_ok,
                       (SELECT COUNT(*) FROM inspections i
                         WHERE i.bc_supervisor_id = s.id AND i.status = 'submitted'
                           AND i.result NOT IN ('work_verified')
                           AND i.inspection_date BETWEEN :from AND :to) AS adverse
                  FROM bc_supervisors s
                  JOIN users u ON u.id = s.user_id
                  JOIN branches b ON b.id = s.branch_id
                 WHERE " . implode(' AND ', $where)
            . ' ORDER BY recovered DESC, visits DESC';

        return [
            'sql' => $sql,
            'params' => $params,
            'columns' => [
                ['key' => 'supervisor_name', 'label' => 'BCA', 'weight' => 1.4],
                ['key' => 'bc_code', 'label' => 'BC Code', 'weight' => 0.8],
                ['key' => 'branch_name', 'label' => 'Branch', 'weight' => 1.1],
                ['key' => 'allocated', 'label' => 'Allocated', 'type' => 'count', 'align' => 'center', 'weight' => 0.9],
                ['key' => 'visits', 'label' => 'Visits', 'type' => 'count', 'align' => 'center', 'weight' => 0.8],
                ['key' => 'accounts_covered', 'label' => 'Accounts covered', 'type' => 'count', 'align' => 'center', 'weight' => 1.1],
                ['key' => 'recovered', 'label' => 'Recovered', 'type' => 'money', 'align' => 'right', 'weight' => 1.2],
                ['key' => 'promises', 'label' => 'PTP', 'type' => 'count', 'align' => 'center', 'weight' => 0.7],
                ['key' => 'promises_kept', 'label' => 'PTP kept', 'type' => 'count', 'align' => 'center', 'weight' => 0.8],
                ['key' => 'days_present', 'label' => 'Days present', 'type' => 'count', 'align' => 'center', 'weight' => 1.0],
                ['key' => 'late_visits', 'label' => 'Late', 'type' => 'count', 'align' => 'center', 'weight' => 0.7],
                ['key' => 'inspections', 'label' => 'Inspected', 'type' => 'count', 'align' => 'center', 'weight' => 0.9],
                ['key' => 'verified_ok', 'label' => 'Verified', 'type' => 'count', 'align' => 'center', 'weight' => 0.8],
                ['key' => 'adverse', 'label' => 'Adverse', 'type' => 'count', 'align' => 'center', 'weight' => 0.8],
            ],
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Value formatting                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * Derived columns that are computed rather than selected, so target maths
     * lives in one place for the screen and all three export formats.
     *
     * @param array<string, mixed> $row
     */
    public static function value(array $column, array $row): mixed
    {
        $key = (string) $column['key'];

        if (($column['type'] ?? '') === 'computed') {
            return match ($key) {
                'visit_pending' => max(0, (int) ($row['visit_target'] ?? 0) - (int) ($row['visits_done'] ?? 0)),
                'visit_percent' => percent_of((int) ($row['visits_done'] ?? 0), (int) ($row['visit_target'] ?? 0)) . '%',
                'recovery_pending' => max(0.0, (float) ($row['recovery_target'] ?? 0) - (float) ($row['recovery_done'] ?? 0)),
                'recovery_percent' => percent_of((float) ($row['recovery_done'] ?? 0), (float) ($row['recovery_target'] ?? 0)) . '%',
                // SSS: the same arithmetic App\Services\Sss does for the screen and the
                // handset, so a printed percentage can never disagree with either.
                'achievement_gap' => max(
                    0,
                    (int) ($row['total_target'] ?? 0) - (int) ($row['total_achievement'] ?? 0)
                ),
                // No target is not 100%. percent_of() answers 100 for "achieved something
                // against nothing", which is the right answer for a pending figure and the
                // wrong one on a ranking somebody reads down: a supervisor nobody set a
                // target for would head the table. Null prints as a dash on screen and as
                // an empty cell in an export, which is what "not measured" looks like.
                'achievement_percent' => (int) ($row['total_target'] ?? 0) > 0
                    ? percent_of(
                        (int) ($row['total_achievement'] ?? 0),
                        (int) ($row['total_target'] ?? 0)
                    ) . '%'
                    : null,
                default => null,
            };
        }

        return $row[$key] ?? null;
    }

    /**
     * Render a value for display / export.
     *
     * @param array<string, mixed> $column
     */
    public static function format(array $column, mixed $value, bool $forExport = false): string
    {
        if ($value === null || $value === '') {
            return $forExport ? '' : '—';
        }

        return match ($column['type'] ?? '') {
            'money' => $forExport ? number_format((float) $value, 2, '.', '') : money((float) $value),
            'date' => $forExport ? (string) $value : format_date((string) $value),
            'datetime' => $forExport ? (string) $value : format_datetime((string) $value),
            'time' => $forExport ? (string) $value : format_time((string) $value),
            'hours' => minutes_to_hours((int) $value),
            'boolean' => ((int) $value === 1 || $value === true) ? 'Yes' : 'No',
            'enum' => enum_label((string) $value),
            // An enum with an explicit label map, for values whose printed
            // wording is not just the key tidied up: `agreed` must read
            // "Agreed for OTS", and `sma_2` must read "SMA-2", not "Sma 2".
            'labels' => (string) (($column['labels'] ?? [])[(string) $value] ?? enum_label((string) $value)),
            'visit_status' => visit_status_label((string) $value),
            'inspection_result' => inspection_result_label((string) $value),
            default => (string) $value,
        };
    }

    /* ------------------------------------------------------------------ */
    /* Exporting                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Generate an export file, record it in `report_exports` and return the row.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public static function export(string $slug, array $filters, string $format): array
    {
        if (!array_key_exists($format, self::FORMATS)) {
            throw new HttpException(422, 'Unsupported export format.');
        }

        $definition = self::definition($slug, $filters);
        $rows = self::rows($slug, $filters);
        $columns = $definition['columns'];

        $reportTypeId = Database::selectOne('SELECT id FROM report_types WHERE slug = :slug', ['slug' => $slug]);

        $exportId = Database::insert('report_exports', [
            'user_id' => (int) Auth::id(),
            'report_type_id' => $reportTypeId === null ? null : (int) $reportTypeId['id'],
            'report_slug' => $slug,
            'format' => $format,
            'filters' => json_encode($filters, JSON_UNESCAPED_UNICODE),
            'status' => 'queued',
            'row_count' => count($rows),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $extension = match ($format) {
            'excel' => 'xlsx',
            'pdf' => 'pdf',
            default => 'csv',
        };

        $fileName = sprintf('%s-%s.%s', str_replace('_', '-', $slug), date('Ymd-His'), $extension);
        $relative = 'generated/' . $fileName;
        $absolute = storage_path($relative);

        try {
            match ($format) {
                'csv' => self::writeCsv($absolute, $columns, $rows),
                'excel' => self::writeXlsx($absolute, $slug, $columns, $rows, $filters),
                'pdf' => self::writePdf($absolute, $slug, $columns, $rows, $filters),
            };
        } catch (\Throwable $e) {
            Database::update('report_exports', [
                'status' => 'failed',
                'error_message' => mb_substr($e->getMessage(), 0, 255),
                'updated_at' => now(),
            ], 'id = :id', ['id' => $exportId]);

            throw $e;
        }

        Database::update('report_exports', [
            'file_path' => $relative,
            'file_name' => $fileName,
            'file_size' => (int) (is_file($absolute) ? filesize($absolute) : 0),
            'status' => 'completed',
            'updated_at' => now(),
        ], 'id = :id', ['id' => $exportId]);

        Audit::log(Audit::REPORT_EXPORTED, [
            'entity_type' => 'report_export',
            'entity_id' => $exportId,
            'description' => sprintf(
                '%s exported as %s (%d rows).',
                self::name($slug),
                strtoupper($format),
                count($rows)
            ),
            'new' => ['filters' => $filters],
        ]);

        return Database::selectOne('SELECT * FROM report_exports WHERE id = :id', ['id' => $exportId]) ?? [];
    }

    /**
     * @param array<int, array<string, mixed>> $columns
     * @param array<int, array<string, mixed>> $rows
     */
    private static function writeCsv(string $path, array $columns, array $rows): void
    {
        $writer = new CsvWriter($path);
        $writer->headers(array_map(static fn (array $c): string => (string) $c['label'], $columns));

        foreach ($rows as $row) {
            $line = [];

            foreach ($columns as $column) {
                $line[] = self::format($column, self::value($column, $row), true);
            }

            $writer->row($line);
        }

        $writer->close();
    }

    private static function writeXlsx(string $path, string $slug, array $columns, array $rows, array $filters): void
    {
        $writer = new XlsxWriter(mb_substr(self::name($slug), 0, 31));
        $writer->title(self::name($slug), self::filterSummary($filters));
        $writer->headers(array_map(static fn (array $c): string => (string) $c['label'], $columns));

        foreach ($rows as $row) {
            $line = [];

            foreach ($columns as $column) {
                $value = self::value($column, $row);
                $type = $column['type'] ?? '';

                // Keep numbers and dates typed so the spreadsheet can total and
                // sort them instead of treating everything as text.
                if ($value === null || $value === '') {
                    $line[] = '';
                } elseif ($type === 'money') {
                    $line[] = (float) $value;
                } elseif ($type === 'count') {
                    $line[] = (int) $value;
                } elseif ($type === 'date') {
                    $line[] = date('Y-m-d', (int) strtotime((string) $value));
                } else {
                    $line[] = self::format($column, $value, true);
                }
            }

            $writer->row($line);
        }

        $writer->save($path);
    }

    private static function writePdf(string $path, string $slug, array $columns, array $rows, array $filters): void
    {
        // Wide reports are only readable in landscape.
        $pdf = new PdfWriter(count($columns) > 6 ? 'landscape' : 'portrait');

        $pdf->header(
            self::name($slug),
            org_name(),
            [
                self::filterSummary($filters),
                sprintf('%d row(s). Prepared by %s.', count($rows), Auth::name()),
            ]
        );

        $headers = [];
        $weights = [];
        $aligns = [];

        // PDF pages are narrow: show the most useful columns and drop the rest,
        // which the Excel/CSV exports still carry in full.
        $maxColumns = count($columns) > 6 ? 11 : 7;
        $selected = array_slice($columns, 0, $maxColumns);

        foreach ($selected as $column) {
            $headers[] = (string) $column['label'];
            $weights[] = (float) ($column['weight'] ?? 1.0);
            $aligns[] = (string) ($column['align'] ?? 'left');
        }

        $tableRows = [];

        foreach ($rows as $row) {
            $line = [];

            foreach ($selected as $column) {
                $line[] = self::format($column, self::value($column, $row));
            }

            $tableRows[] = $line;
        }

        $pdf->table($headers, $tableRows, $weights, $aligns);

        if (count($columns) > $maxColumns) {
            $pdf->paragraph(sprintf(
                'This PDF shows the first %d of %d columns. Export to Excel or CSV for the complete data set.',
                $maxColumns,
                count($columns)
            ), 8);
        }

        $pdf->save($path);
    }

    /**
     * @param array<string, mixed> $filters
     */
    public static function filterSummary(array $filters): string
    {
        $parts = [];

        if (!empty($filters['from']) || !empty($filters['to'])) {
            $parts[] = sprintf(
                'Period: %s to %s',
                format_date((string) ($filters['from'] ?? '')),
                format_date((string) ($filters['to'] ?? today()))
            );
        }

        if (!empty($filters['branch_id'])) {
            $parts[] = 'Branch: ' . (string) Database::scalar(
                'SELECT name FROM branches WHERE id = :id',
                ['id' => (int) $filters['branch_id']]
            );
        }

        if (!empty($filters['bc_supervisor_id'])) {
            $parts[] = 'BCA: ' . (string) Database::scalar(
                'SELECT CONCAT(u.name, " (", s.bc_code, ")")
                   FROM bc_supervisors s JOIN users u ON u.id = s.user_id WHERE s.id = :id',
                ['id' => (int) $filters['bc_supervisor_id']]
            );
        }

        foreach (['visit_status', 'status', 'result', 'ots_status', 'renewal_status', 'documents_status',
                  'payment_mode', 'action', 'photo_type', 'period', 'visit_type'] as $key) {
            if (!empty($filters[$key])) {
                $parts[] = ucwords(str_replace('_', ' ', $key)) . ': ' . enum_label((string) $filters[$key]);
            }
        }

        if (!empty($filters['search'])) {
            $parts[] = 'Search: "' . (string) $filters['search'] . '"';
        }

        if (self::truthy($filters, 'late_only')) {
            $parts[] = 'Late submissions only';
        }

        if (self::truthy($filters, 'gps_invalid_only')) {
            $parts[] = 'Failed GPS validation only';
        }

        return $parts === [] ? 'All records' : implode('   •   ', $parts);
    }
}
