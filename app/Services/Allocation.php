<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;

/**
 * Account allocation.
 *
 * Rules, in order:
 *   1. If the sheet carries a BC Code that matches an active BCA in
 *      the account's branch, the account goes to that supervisor.
 *   2. Otherwise the account is spread across the active supervisors of its
 *      branch by current workload, so 40/40/39 stays balanced as new rows
 *      arrive rather than dumping everything on the first supervisor.
 *
 * Every assignment and reassignment is written to `account_assignments` (history
 * preserved, one active row enforced by the database) and to `audit_logs`.
 */
final class Allocation
{
    /** branch id => list of ['id' => bc id, 'load' => active accounts] */
    private array $workload = [];

    /** bc_code (normalised) => ['id' => .., 'branch_id' => ..] */
    private ?array $codeIndex = null;

    /* ------------------------------------------------------------------ */
    /* Lookups                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Resolve a raw BC Code from a spreadsheet to an active supervisor.
     *
     * @return array{id:int, branch_id:int, bc_code:string}|null
     */
    public function findByCode(?string $bcCode): ?array
    {
        if ($bcCode === null || trim($bcCode) === '') {
            return null;
        }

        if ($this->codeIndex === null) {
            $this->codeIndex = [];

            $rows = Database::select(
                "SELECT id, branch_id, bc_code FROM bc_supervisors WHERE status = 'active'"
            );

            foreach ($rows as $row) {
                $this->codeIndex[self::normaliseCode((string) $row['bc_code'])] = [
                    'id' => (int) $row['id'],
                    'branch_id' => (int) $row['branch_id'],
                    'bc_code' => (string) $row['bc_code'],
                ];
            }
        }

        return $this->codeIndex[self::normaliseCode($bcCode)] ?? null;
    }

    public static function normaliseCode(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim($code)) ?? '');
    }

    /**
     * Active supervisors of a branch with their current live workload, lightest
     * first. Cached per branch for the duration of an import batch.
     *
     * @return array<int, array{id:int, load:int}>
     */
    public function branchWorkload(int $branchId): array
    {
        if (isset($this->workload[$branchId])) {
            return $this->workload[$branchId];
        }

        // `load` is reserved in MariaDB, hence active_load.
        $rows = Database::select(
            "SELECT s.id,
                    (SELECT COUNT(*) FROM account_assignments a
                      WHERE a.bc_supervisor_id = s.id AND a.is_active = 1) AS active_load
               FROM bc_supervisors s
              WHERE s.branch_id = :branch AND s.status = 'active'
              ORDER BY active_load ASC, s.id ASC",
            ['branch' => $branchId]
        );

        $this->workload[$branchId] = array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'load' => (int) $row['active_load']],
            $rows
        );

        return $this->workload[$branchId];
    }

    /**
     * Pick the least loaded active supervisor in a branch, counting the
     * assignments already made earlier in this same batch.
     */
    public function pickForBranch(int $branchId): ?int
    {
        $supervisors = $this->branchWorkload($branchId);

        if ($supervisors === []) {
            return null;
        }

        $bestIndex = 0;

        foreach ($supervisors as $index => $supervisor) {
            if ($supervisor['load'] < $supervisors[$bestIndex]['load']) {
                $bestIndex = $index;
            }
        }

        // Reserve the slot so the next row in the batch balances against it.
        $this->workload[$branchId][$bestIndex]['load']++;

        return $supervisors[$bestIndex]['id'];
    }

    /** Undo a reservation when the caller decides not to assign after all. */
    public function releaseForBranch(int $branchId, int $bcSupervisorId): void
    {
        foreach ($this->workload[$branchId] ?? [] as $index => $supervisor) {
            if ($supervisor['id'] === $bcSupervisorId && $supervisor['load'] > 0) {
                $this->workload[$branchId][$index]['load']--;

                return;
            }
        }
    }

    public function resetCache(): void
    {
        $this->workload = [];
        $this->codeIndex = null;
    }

    /* ------------------------------------------------------------------ */
    /* Assigning                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Decide and record the owner of a freshly imported account.
     *
     * @return array{assigned:bool, bc_supervisor_id:?int, method:string, note:string}
     */
    public function allocateImported(int $accountId, int $branchId, ?string $rawBcCode, ?int $importId = null): array
    {
        $byCode = $this->findByCode($rawBcCode);

        if ($byCode !== null) {
            if ($byCode['branch_id'] !== $branchId) {
                // The code exists but belongs to another branch: fall through to
                // balancing rather than assigning across branch boundaries.
                $note = sprintf(
                    'BC Code %s belongs to another branch; allocated by workload instead.',
                    $byCode['bc_code']
                );
            } else {
                $this->assign($accountId, $byCode['id'], 'excel_bc_code', 'Matched BC Code from the uploaded sheet.', $importId);

                return [
                    'assigned' => true,
                    'bc_supervisor_id' => $byCode['id'],
                    'method' => 'excel_bc_code',
                    'note' => '',
                ];
            }
        } else {
            $note = '';
        }

        $picked = $this->pickForBranch($branchId);

        if ($picked === null) {
            return [
                'assigned' => false,
                'bc_supervisor_id' => null,
                'method' => 'auto_balance',
                'note' => 'No active BCA in this branch; the account is unassigned.',
            ];
        }

        $this->assign($accountId, $picked, 'auto_balance', 'Balanced by current workload.', $importId);

        return [
            'assigned' => true,
            'bc_supervisor_id' => $picked,
            'method' => 'auto_balance',
            'note' => $note,
        ];
    }

    /**
     * Create the active assignment, retiring any previous one.
     */
    public function assign(
        int $accountId,
        int $bcSupervisorId,
        string $method = 'manual',
        string $reason = '',
        ?int $importId = null
    ): int {
        $supervisor = Database::selectOne(
            'SELECT id, branch_id, status FROM bc_supervisors WHERE id = :id',
            ['id' => $bcSupervisorId]
        );

        if ($supervisor === null) {
            throw new HttpException(422, 'The selected BCA does not exist.');
        }

        if ((string) $supervisor['status'] !== 'active') {
            throw new HttpException(422, 'The selected BCA is not active.');
        }

        $account = Database::selectOne(
            'SELECT id, branch_id, account_number FROM loan_accounts WHERE id = :id',
            ['id' => $accountId]
        );

        if ($account === null) {
            throw new HttpException(404, 'Loan account not found.');
        }

        if ((int) $account['branch_id'] !== (int) $supervisor['branch_id']) {
            throw new HttpException(
                422,
                'A BCA can only be allocated accounts from their own branch.'
            );
        }

        return Database::transaction(function () use ($accountId, $bcSupervisorId, $supervisor, $method, $reason, $importId, $account): int {
            $current = Database::selectOne(
                'SELECT * FROM account_assignments WHERE loan_account_id = :id AND is_active = 1 LIMIT 1',
                ['id' => $accountId]
            );

            if ($current !== null) {
                if ((int) $current['bc_supervisor_id'] === $bcSupervisorId) {
                    return (int) $current['id'];
                }

                Database::update('account_assignments', [
                    'is_active' => null,
                    'unassigned_by' => Auth::id(),
                    'unassigned_at' => now(),
                    'updated_at' => now(),
                ], 'id = :id', ['id' => (int) $current['id']]);
            }

            $id = Database::insert('account_assignments', [
                'loan_account_id' => $accountId,
                'bc_supervisor_id' => $bcSupervisorId,
                'branch_id' => (int) $supervisor['branch_id'],
                'method' => $method,
                'reason' => $reason !== '' ? mb_substr($reason, 0, 255) : null,
                'is_active' => 1,
                'assigned_by' => Auth::id(),
                'assigned_at' => now(),
                'excel_import_id' => $importId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $isReassign = $current !== null;

            Audit::log($isReassign ? Audit::ACCOUNT_REASSIGNED : Audit::ACCOUNT_ASSIGNED, [
                'entity_type' => 'loan_account',
                'entity_id' => $accountId,
                'description' => sprintf(
                    'Account %s %s BCA #%d (%s).',
                    $account['account_number'],
                    $isReassign ? 'reassigned to' : 'assigned to',
                    $bcSupervisorId,
                    $method
                ),
                'old' => $isReassign ? ['bc_supervisor_id' => (int) $current['bc_supervisor_id']] : null,
                'new' => ['bc_supervisor_id' => $bcSupervisorId, 'method' => $method, 'reason' => $reason],
            ]);

            return $id;
        });
    }

    /**
     * Manual reassignment from the admin UI. Notifies both supervisors.
     */
    public function reassign(int $accountId, int $bcSupervisorId, string $reason): int
    {
        $previous = Database::selectOne(
            'SELECT bc_supervisor_id FROM account_assignments WHERE loan_account_id = :id AND is_active = 1',
            ['id' => $accountId]
        );

        $id = $this->assign($accountId, $bcSupervisorId, 'reassign', $reason);

        $account = Database::selectOne(
            'SELECT account_number, borrower_name FROM loan_accounts WHERE id = :id',
            ['id' => $accountId]
        );

        $this->notifySupervisor(
            $bcSupervisorId,
            'New account allocated',
            sprintf(
                'Account %s (%s) has been allocated to you.',
                $account['account_number'] ?? '',
                $account['borrower_name'] ?? ''
            ),
            $accountId
        );

        if ($previous !== null && (int) $previous['bc_supervisor_id'] !== $bcSupervisorId) {
            $this->notifySupervisor(
                (int) $previous['bc_supervisor_id'],
                'Account removed from your list',
                sprintf(
                    'Account %s (%s) has been reassigned.%s',
                    $account['account_number'] ?? '',
                    $account['borrower_name'] ?? '',
                    $reason !== '' ? ' Reason: ' . $reason : ''
                ),
                $accountId
            );
        }

        return $id;
    }

    /**
     * Remove the active owner without picking a new one.
     */
    public function unassign(int $accountId, string $reason = ''): bool
    {
        $current = Database::selectOne(
            'SELECT * FROM account_assignments WHERE loan_account_id = :id AND is_active = 1',
            ['id' => $accountId]
        );

        if ($current === null) {
            return false;
        }

        Database::update('account_assignments', [
            'is_active' => null,
            'unassigned_by' => Auth::id(),
            'unassigned_at' => now(),
            'reason' => $reason !== '' ? mb_substr($reason, 0, 255) : $current['reason'],
            'updated_at' => now(),
        ], 'id = :id', ['id' => (int) $current['id']]);

        Audit::log(Audit::ACCOUNT_UNASSIGNED, [
            'entity_type' => 'loan_account',
            'entity_id' => $accountId,
            'description' => 'Active allocation removed.' . ($reason !== '' ? ' Reason: ' . $reason : ''),
            'old' => ['bc_supervisor_id' => (int) $current['bc_supervisor_id']],
        ]);

        return true;
    }

    /**
     * Spread every currently unassigned account of a branch across its active
     * supervisors. Returns the number of accounts allocated.
     */
    public function balanceBranch(int $branchId): int
    {
        $this->resetCache();

        $accounts = Database::select(
            "SELECT a.id FROM loan_accounts a
              WHERE a.branch_id = :branch AND a.status = 'active'
                AND NOT EXISTS (
                    SELECT 1 FROM account_assignments x
                     WHERE x.loan_account_id = a.id AND x.is_active = 1
                )
              ORDER BY a.id ASC",
            ['branch' => $branchId]
        );

        $count = 0;

        foreach ($accounts as $account) {
            $picked = $this->pickForBranch($branchId);

            if ($picked === null) {
                break;
            }

            $this->assign((int) $account['id'], $picked, 'auto_balance', 'Branch rebalance.');
            $count++;
        }

        return $count;
    }

    private function notifySupervisor(int $bcSupervisorId, string $title, string $body, int $accountId): void
    {
        $userId = (int) Database::scalar(
            'SELECT user_id FROM bc_supervisors WHERE id = :id',
            ['id' => $bcSupervisorId]
        );

        if ($userId > 0) {
            Notify::user($userId, $title, $body, [
                'type' => 'assignment',
                'related_type' => 'loan_account',
                'related_id' => $accountId,
            ]);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Reporting helpers                                                  */
    /* ------------------------------------------------------------------ */

    /**
     * Live distribution used by the allocation screen.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function distribution(?int $branchId = null): array
    {
        $where = $branchId === null ? '' : ' WHERE s.branch_id = :branch';
        $params = $branchId === null ? [] : ['branch' => $branchId];

        return Database::select(
            "SELECT s.id, s.bc_code, s.status, u.name, b.name AS branch_name, b.id AS branch_id,
                    (SELECT COUNT(*) FROM account_assignments a
                      WHERE a.bc_supervisor_id = s.id AND a.is_active = 1) AS accounts,
                    (SELECT COUNT(*) FROM visits v
                      WHERE v.bc_supervisor_id = s.id AND v.visit_date = CURDATE()) AS visits_today
               FROM bc_supervisors s
               JOIN users u ON u.id = s.user_id
               JOIN branches b ON b.id = s.branch_id"
            . $where
            . ' ORDER BY b.name ASC, accounts DESC',
            $params
        );
    }

    public static function unassignedCount(?int $branchId = null): int
    {
        $sql = "SELECT COUNT(*) FROM loan_accounts a
                 WHERE a.status = 'active'
                   AND NOT EXISTS (
                       SELECT 1 FROM account_assignments x
                        WHERE x.loan_account_id = a.id AND x.is_active = 1
                   )";
        $params = [];

        if ($branchId !== null) {
            $sql .= ' AND a.branch_id = :branch';
            $params['branch'] = $branchId;
        }

        return (int) Database::scalar($sql, $params);
    }
}
