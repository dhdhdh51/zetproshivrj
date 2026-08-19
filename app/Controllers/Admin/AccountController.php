<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Core\Acl;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Services\Allocation;
use App\Services\Audit;
use App\Services\CkccRenewals;
use App\Services\Excel\SystemFields;
use App\Services\Forms;
use App\Services\KrmOts;
use App\Services\LoanAccounts;

/**
 * The loan book: search, account history, and the allocation screens.
 */
final class AccountController extends BaseController
{
    private const PER_PAGE = 40;

    /**
     * The manual entry form.
     *
     * Most of the loan book arrives as a spreadsheet, but not all of it: an
     * account opened after the monthly extract, or one the branch reports over the
     * phone, previously had no way in at all short of building a one-row Excel
     * file. The form takes the same fields the importer understands, so the two
     * routes cannot disagree about what an account is.
     */
    public function create(Request $request): void
    {
        $this->page('admin.accounts.form', [
            'title' => 'Add loan account',
            'fields' => SystemFields::all(),
            'branches' => $this->branchOptions(),
            'supervisors' => $this->supervisorOptions(),
        ]);
    }

    public function store(Request $request): void
    {
        $data = $this->validate($request, $this->accountRules(), [
            'account_number.unique' => 'That account number is already on the loan book. Open the existing '
                . 'account instead of creating a second one.',
        ], '/admin/accounts/create');

        $branchId = (int) $data['branch_id'];

        // The branch is chosen from a dropdown of ids here, so there is no raw
        // sheet text to record; branch_code_raw stays null and marks this row as
        // hand-entered rather than imported.
        $result = LoanAccounts::upsert($data, $branchId, null);
        $accountId = $result['id'];

        Audit::log(Audit::ACCOUNT_CREATED, [
            'entity_type' => 'loan_account',
            'entity_id' => $accountId,
            'description' => sprintf(
                'Loan account %s (%s) added by hand.',
                $data['account_number'],
                $data['borrower_name']
            ),
            'new' => $data,
        ]);

        $allocation = (string) $request->input('allocation_mode', 'none');

        if ($allocation === 'auto' || $allocation === 'supervisor') {
            $bcCode = null;

            if ($allocation === 'supervisor') {
                $supervisorId = (int) $request->input('bc_supervisor_id', 0);
                $bcCode = $supervisorId > 0
                    ? (string) Database::scalar(
                        'SELECT bc_code FROM bc_supervisors WHERE id = :id',
                        ['id' => $supervisorId]
                    )
                    : null;
            }

            // Reuses the importer's allocation path: by BC code when one is given,
            // otherwise the least-loaded supervisor in that branch.
            $outcome = (new Allocation())->allocateImported($accountId, $branchId, $bcCode, null);

            $this->success(
                ($outcome['assigned'] ?? false)
                    ? 'Account added and allocated. The supervisor has been notified.'
                    : 'Account added. It could not be allocated automatically — no active supervisor was '
                        . 'available in that branch, so allocate it by hand.'
            );
        } else {
            $this->success('Account added. It is not allocated to anyone yet.');
        }

        $this->redirect('/admin/accounts/' . $accountId);
    }

    /**
     * Validation for the manual form.
     *
     * Lengths come from SystemFields, which mirrors the column widths, so the form
     * cannot accept a value the column will silently truncate.
     *
     * @return array<string, string>
     */
    private function accountRules(): array
    {
        $rules = [
            'account_number' => sprintf(
                'required|max:%d|unique:loan_accounts,account_number',
                SystemFields::textLength('account_number')
            ),
            'borrower_name' => 'required|max:' . SystemFields::textLength('borrower_name'),
            'branch_id' => 'required|integer|exists:branches,id',
            'loan_category' => 'required|in:general,krm_ots,ckcc_od2',
        ];

        $skip = ['account_number', 'borrower_name', 'branch_name', 'branch_code', 'bc_code'];

        foreach (SystemFields::all() as $key => $field) {
            if (in_array($key, $skip, true)) {
                continue;
            }

            $options = SystemFields::options($key);

            if ($options !== []) {
                $rules[$key] = 'nullable|in:' . implode(',', array_keys($options));

                continue;
            }

            $rules[$key] = match ($field['type']) {
                'date' => 'nullable|date',
                'amount' => 'nullable|numeric|min:0',
                default => 'nullable|max:' . SystemFields::textLength($key),
            };
        }

        return $rules;
    }

    public function index(Request $request): void
    {
        $filters = $this->filters($request, [
            'search', 'branch_id', 'bc_supervisor_id', 'status', 'recovery_status',
            'category', 'allocation', 'visited', 'sort', 'direction',
        ]);

        [$scope, $params] = Acl::branchScope('a');
        $where = [$scope];

        if (!empty($filters['branch_id'])) {
            $where[] = 'a.branch_id = :branch';
            $params['branch'] = (int) $filters['branch_id'];
        }

        if (!empty($filters['search'])) {
            $where[] = '(a.account_number LIKE :search OR a.cif LIKE :search OR a.borrower_name LIKE :search
                         OR a.father_name LIKE :search OR a.mobile LIKE :search OR a.village LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['status'])) {
            $where[] = 'a.status = :status';
            $params['status'] = (string) $filters['status'];
        }

        if (!empty($filters['recovery_status'])) {
            $where[] = 'a.recovery_status = :recovery_status';
            $params['recovery_status'] = (string) $filters['recovery_status'];
        }

        if (!empty($filters['category'])) {
            $where[] = 'a.loan_category = :category';
            $params['category'] = (string) $filters['category'];
        }

        if (!empty($filters['bc_supervisor_id'])) {
            $where[] = 'x.bc_supervisor_id = :bc';
            $params['bc'] = (int) $filters['bc_supervisor_id'];
        }

        if (($filters['allocation'] ?? '') === 'unassigned') {
            $where[] = 'x.id IS NULL';
        } elseif (($filters['allocation'] ?? '') === 'assigned') {
            $where[] = 'x.id IS NOT NULL';
        }

        if (($filters['visited'] ?? '') === 'never') {
            $where[] = 'a.visit_count = 0';
        } elseif (($filters['visited'] ?? '') === 'visited') {
            $where[] = 'a.visit_count > 0';
        }

        // Whitelisted sorting: the column name is interpolated, so it can only
        // ever be one of these.
        $sortable = [
            'overdue' => 'a.overdue',
            'outstanding' => 'a.outstanding',
            'borrower' => 'a.borrower_name',
            'account' => 'a.account_number',
            'last_visit' => 'a.last_visit_at',
            'visits' => 'a.visit_count',
        ];

        $sort = $sortable[$filters['sort'] ?? 'overdue'] ?? 'a.overdue';
        $direction = strtolower($filters['direction'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

        $sql = 'SELECT a.*, b.name AS branch_name, b.code AS branch_code,
                       s.bc_code, u.name AS supervisor_name, s.id AS bc_supervisor_id,
                       x.assigned_at, x.method AS allocation_method
                  FROM loan_accounts a
                  JOIN branches b ON b.id = a.branch_id
             LEFT JOIN account_assignments x ON x.loan_account_id = a.id AND x.is_active = 1
             LEFT JOIN bc_supervisors s ON s.id = x.bc_supervisor_id
             LEFT JOIN users u ON u.id = s.user_id
                 WHERE ' . implode(' AND ', $where)
            . sprintf(' ORDER BY %s %s, a.id DESC', $sort, $direction);

        $page = $this->page_number($request);
        $total = (int) Database::scalar('SELECT COUNT(*) FROM (' . $sql . ') AS t', $params);
        $rows = Database::select($sql . sprintf(' LIMIT %d OFFSET %d', self::PER_PAGE, ($page - 1) * self::PER_PAGE), $params);

        $totals = Database::selectOne(
            'SELECT COALESCE(SUM(outstanding), 0) AS outstanding, COALESCE(SUM(overdue), 0) AS overdue
               FROM (' . $sql . ') AS t',
            $params
        ) ?? [];

        $this->page('admin.accounts.index', [
            'title' => 'Loan accounts',
            'accounts' => $rows,
            'filters' => $filters,
            'branches' => $this->branchOptions(),
            'supervisors' => $this->supervisorOptions(
                empty($filters['branch_id']) ? null : (int) $filters['branch_id']
            ),
            'total' => $total,
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'lastPage' => max(1, (int) ceil($total / self::PER_PAGE)),
            'sumOutstanding' => (float) ($totals['outstanding'] ?? 0),
            'sumOverdue' => (float) ($totals['overdue'] ?? 0),
        ]);
    }

    public function show(Request $request): void
    {
        $id = $request->paramInt('id');

        $account = Database::selectOne(
            'SELECT a.*, b.name AS branch_name, b.code AS branch_code,
                    s.id AS bc_supervisor_id, s.bc_code, u.name AS supervisor_name, u.mobile AS supervisor_mobile,
                    x.assigned_at, x.method AS allocation_method,
                    i.original_name AS import_name
               FROM loan_accounts a
               JOIN branches b ON b.id = a.branch_id
          LEFT JOIN account_assignments x ON x.loan_account_id = a.id AND x.is_active = 1
          LEFT JOIN bc_supervisors s ON s.id = x.bc_supervisor_id
          LEFT JOIN users u ON u.id = s.user_id
          LEFT JOIN excel_imports i ON i.id = a.excel_import_id
              WHERE a.id = :id',
            ['id' => $id]
        );

        if ($account === null) {
            $this->abort(404, 'Loan account not found.');
        }

        $this->assertBranch((int) $account['branch_id']);

        $this->page('admin.accounts.show', [
            'title' => 'Account ' . $account['account_number'],
            'account' => $account,
            'visits' => Database::select(
                "SELECT v.*, u.name AS supervisor_name, s.bc_code,
                        (SELECT COUNT(*) FROM visit_photos p WHERE p.visit_id = v.id) AS photos,
                        (SELECT COUNT(*) FROM inspections i WHERE i.visit_id = v.id AND i.status = 'submitted') AS inspections
                   FROM visits v
                   JOIN bc_supervisors s ON s.id = v.bc_supervisor_id
                   JOIN users u ON u.id = s.user_id
                  WHERE v.loan_account_id = :id
                  ORDER BY v.visit_date DESC, v.id DESC",
                ['id' => $id]
            ),
            'recoveries' => Database::select(
                'SELECT r.*, u.name AS supervisor_name FROM recoveries r
              LEFT JOIN bc_supervisors s ON s.id = r.bc_supervisor_id
              LEFT JOIN users u ON u.id = s.user_id
                  WHERE r.loan_account_id = :id ORDER BY r.recovery_date DESC, r.id DESC',
                ['id' => $id]
            ),
            'promises' => Database::select(
                'SELECT * FROM promises WHERE loan_account_id = :id ORDER BY promise_date DESC',
                ['id' => $id]
            ),
            'followups' => Database::select(
                'SELECT * FROM followups WHERE loan_account_id = :id ORDER BY followup_date DESC',
                ['id' => $id]
            ),
            'assignments' => Database::select(
                'SELECT x.*, s.bc_code, u.name AS supervisor_name, ab.name AS assigned_by_name
                   FROM account_assignments x
                   JOIN bc_supervisors s ON s.id = x.bc_supervisor_id
                   JOIN users u ON u.id = s.user_id
              LEFT JOIN users ab ON ab.id = x.assigned_by
                  WHERE x.loan_account_id = :id
                  ORDER BY x.id DESC',
                ['id' => $id]
            ),
            'krmCase' => Database::selectOne(
                'SELECT * FROM krm_ots_cases WHERE loan_account_id = :id ORDER BY id DESC LIMIT 1',
                ['id' => $id]
            ),
            'ckccCase' => Database::selectOne(
                'SELECT * FROM ckcc_renewals WHERE loan_account_id = :id ORDER BY id DESC LIMIT 1',
                ['id' => $id]
            ),
            'supervisors' => $this->supervisorOptions((int) $account['branch_id']),
            'canManage' => Auth::isAdmin(),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Allocation                                                         */
    /* ------------------------------------------------------------------ */

    public function allocation(Request $request): void
    {
        $branchId = (int) $request->query('branch_id', 0);

        $this->page('admin.accounts.allocation', [
            'title' => 'Account allocation',
            'branches' => $this->branchOptions(),
            'branchId' => $branchId,
            'distribution' => Allocation::distribution($branchId > 0 ? $branchId : null),
            'unassigned' => Allocation::unassignedCount($branchId > 0 ? $branchId : null),
            'unassignedByBranch' => Database::select(
                "SELECT b.id, b.name, b.code, COUNT(a.id) AS pending,
                        (SELECT COUNT(*) FROM bc_supervisors s WHERE s.branch_id = b.id AND s.status = 'active') AS supervisors
                   FROM branches b
                   JOIN loan_accounts a ON a.branch_id = b.id AND a.status = 'active'
                        AND NOT EXISTS (SELECT 1 FROM account_assignments x
                                         WHERE x.loan_account_id = a.id AND x.is_active = 1)
                  GROUP BY b.id, b.name, b.code
                  ORDER BY pending DESC"
            ),
            'recent' => Database::select(
                'SELECT x.*, a.account_number, a.borrower_name, s.bc_code, u.name AS supervisor_name,
                        ab.name AS assigned_by_name
                   FROM account_assignments x
                   JOIN loan_accounts a ON a.id = x.loan_account_id
                   JOIN bc_supervisors s ON s.id = x.bc_supervisor_id
                   JOIN users u ON u.id = s.user_id
              LEFT JOIN users ab ON ab.id = x.assigned_by
                  ORDER BY x.id DESC LIMIT 15'
            ),
        ]);
    }

    /**
     * Spread every unallocated account of a branch across its active supervisors.
     */
    public function balance(Request $request): void
    {
        $branchId = (int) $request->input('branch_id', 0);

        if ($branchId <= 0) {
            $this->error('Choose a branch to balance.');
            $this->back('/admin/allocation');

            return;
        }

        $supervisors = (int) Database::scalar(
            "SELECT COUNT(*) FROM bc_supervisors WHERE branch_id = :b AND status = 'active'",
            ['b' => $branchId]
        );

        if ($supervisors === 0) {
            $this->error('That branch has no active BC Supervisor to allocate to.');
            $this->back('/admin/allocation');

            return;
        }

        $count = (new Allocation())->balanceBranch($branchId);

        $this->success($count > 0
            ? sprintf('%d account(s) allocated across %d supervisor(s).', $count, $supervisors)
            : 'Nothing to allocate — every account in that branch already has an owner.');
        $this->back('/admin/allocation');
    }

    public function reassign(Request $request): void
    {
        $accountId = $request->paramInt('id');

        $account = Database::selectOne('SELECT * FROM loan_accounts WHERE id = :id', ['id' => $accountId]);

        if ($account === null) {
            $this->abort(404, 'Loan account not found.');
        }

        $data = $this->validate($request, [
            'bc_supervisor_id' => 'required|integer|exists:bc_supervisors,id',
            'reason' => 'required|max:255',
        ]);

        (new Allocation())->reassign(
            $accountId,
            (int) $data['bc_supervisor_id'],
            (string) $data['reason']
        );

        $this->success('Account reassigned. Both supervisors have been notified.');
        $this->back('/admin/accounts/' . $accountId);
    }

    public function unassign(Request $request): void
    {
        $accountId = $request->paramInt('id');
        $reason = trim((string) $request->input('reason', ''));

        if ((new Allocation())->unassign($accountId, $reason)) {
            $this->success('Allocation removed.');
        } else {
            $this->info('That account had no active allocation.');
        }

        $this->back('/admin/accounts/' . $accountId);
    }

    /**
     * Bulk reassignment from the accounts list.
     */
    public function bulkReassign(Request $request): void
    {
        $ids = $request->raw('account_ids');
        $ids = is_array($ids) ? array_map('intval', $ids) : [];
        $supervisorId = (int) $request->input('bc_supervisor_id', 0);
        $reason = trim((string) $request->input('reason', 'Bulk reassignment'));

        if ($ids === [] || $supervisorId <= 0) {
            $this->error('Select at least one account and a BC Supervisor.');
            $this->back('/admin/accounts');

            return;
        }

        $allocation = new Allocation();
        $moved = 0;
        $failed = [];

        foreach ($ids as $accountId) {
            try {
                $allocation->reassign($accountId, $supervisorId, $reason);
                $moved++;
            } catch (\Throwable $e) {
                $failed[] = $accountId;
            }
        }

        if ($moved > 0) {
            $this->success(sprintf('%d account(s) reassigned.', $moved));
        }

        if ($failed !== []) {
            $this->error(sprintf(
                '%d account(s) could not be moved — they belong to a different branch than the chosen supervisor.',
                count($failed)
            ));
        }

        $this->back('/admin/accounts');
    }

    /* ------------------------------------------------------------------ */
    /* Work stream tagging                                                */
    /* ------------------------------------------------------------------ */

    public function saveKrmOts(Request $request): void
    {
        $accountId = $request->paramInt('id');
        $account = Database::selectOne('SELECT branch_id FROM loan_accounts WHERE id = :id', ['id' => $accountId]);

        if ($account === null) {
            $this->abort(404, 'Loan account not found.');
        }

        $this->assertBranch((int) $account['branch_id']);

        KrmOts::save($accountId, $request->all());

        $this->success('KRM OTS case saved.');
        $this->back('/admin/accounts/' . $accountId);
    }

    public function saveCkcc(Request $request): void
    {
        $accountId = $request->paramInt('id');
        $account = Database::selectOne('SELECT branch_id FROM loan_accounts WHERE id = :id', ['id' => $accountId]);

        if ($account === null) {
            $this->abort(404, 'Loan account not found.');
        }

        $this->assertBranch((int) $account['branch_id']);

        CkccRenewals::save($accountId, $request->all());

        $this->success('CKCC OD-2 renewal saved.');
        $this->back('/admin/accounts/' . $accountId);
    }

    /**
     * Manual edit of the few fields that are safe to correct by hand — the rest
     * come from the Excel upload and should be fixed at source.
     */
    public function update(Request $request): void
    {
        $id = $request->paramInt('id');
        $account = Database::selectOne('SELECT * FROM loan_accounts WHERE id = :id', ['id' => $id]);

        if ($account === null) {
            $this->abort(404, 'Loan account not found.');
        }

        $data = $this->validate($request, [
            'mobile' => 'nullable|max:20',
            'village' => 'nullable|max:160',
            'address' => 'nullable|max:500',
            'loan_category' => 'required|in:general,krm_ots,ckcc_od2',
            'status' => 'required|in:active,closed,settled,written_off,excluded',
        ]);

        $payload = [
            'mobile' => $data['mobile'] ?: null,
            'village' => $data['village'] ?: null,
            'address' => $data['address'] ?: null,
            'loan_category' => (string) $data['loan_category'],
            'status' => (string) $data['status'],
            'updated_at' => now(),
        ];

        Database::update('loan_accounts', $payload, 'id = :id', ['id' => $id]);

        Audit::logChange(
            Audit::ACCOUNT_UPDATED,
            'loan_account',
            $id,
            $account,
            $payload,
            sprintf('Account %s updated manually.', $account['account_number'])
        );

        $this->success('Account updated.');
        $this->back('/admin/accounts/' . $id);
    }
}
