<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Central authorisation helper.
 *
 * Two rules are enforced everywhere:
 *   1. Role permissions — what a role is allowed to do at all.
 *   2. Branch isolation — a Branch Manager may only ever touch rows belonging
 *      to their own branch, and a BCA only rows assigned to them.
 *
 * Controllers call these helpers instead of hand-rolling checks so that a
 * missed condition cannot silently expose customer data.
 */
final class Acl
{
    /** role slug => list of ability keys */
    private const ABILITIES = [
        Auth::ROLE_ADMIN => ['*'],

        Auth::ROLE_MANAGER => [
            'dashboard.view',
            'accounts.view',
            'supervisors.view',
            'visits.view',
            'inspections.view',
            'recoveries.view',
            'promises.view',
            'followups.view',
            'attendance.view',
            'sss.view',
            'targets.view',
            'reports.view',
            'reports.export',
            'photos.view',
            'gps.view',
        ],

        Auth::ROLE_BC => [
            'app.login',
            'app.accounts.view',
            'app.visits.create',
            'app.recoveries.create',
            'app.promises.create',
            'app.followups.create',
            'app.attendance.mark',
            'app.sss.submit',
            'app.reports.submit',
        ],
    ];

    public static function allows(string $ability, ?string $role = null): bool
    {
        $role ??= Auth::role();

        if ($role === null) {
            return false;
        }

        $abilities = self::ABILITIES[$role] ?? [];

        return in_array('*', $abilities, true) || in_array($ability, $abilities, true);
    }

    public static function denies(string $ability, ?string $role = null): bool
    {
        return !self::allows($ability, $role);
    }

    /**
     * Abort the request with 403 unless the current user has the ability.
     */
    public static function authorize(string $ability): void
    {
        if (self::denies($ability)) {
            throw new HttpException(403, 'You do not have permission to perform this action.');
        }
    }

    /**
     * Guard a branch-scoped record. Admin passes; everyone else must match
     * their own branch.
     */
    public static function authorizeBranch(?int $branchId): void
    {
        if (Auth::isAdmin()) {
            return;
        }

        $own = Auth::branchId();

        if ($own === null || $branchId === null || $own !== $branchId) {
            throw new HttpException(403, 'This record belongs to another branch.');
        }
    }

    /**
     * SQL fragment + params that constrain a query to what the current user may
     * see. Pass the table alias that holds the branch key, and the column name
     * when it is not `branch_id` (the `branches` table itself is keyed by `id`).
     *
     * @return array{0:string, 1:array<string,mixed>}
     */
    public static function branchScope(string $alias = 'a', string $column = 'branch_id'): array
    {
        if (Auth::isAdmin()) {
            return ['1 = 1', []];
        }

        $branchId = Auth::branchId();

        if ($branchId === null) {
            // Fail closed: a non-admin without a branch sees nothing.
            return ['1 = 0', []];
        }

        return [sprintf('%s.%s = :acl_branch_id', $alias, $column), ['acl_branch_id' => $branchId]];
    }

    /**
     * True when the signed-in BCA owns the given supervisor row.
     */
    public static function ownsSupervisor(int $bcSupervisorId): bool
    {
        if (!Auth::isBcSupervisor()) {
            return false;
        }

        $id = (int) Database::scalar(
            'SELECT id FROM bc_supervisors WHERE user_id = :uid LIMIT 1',
            ['uid' => (int) Auth::id()]
        );

        return $id === $bcSupervisorId;
    }

    /** @return array<string, string> role slug => human label */
    public static function roleLabels(): array
    {
        return [
            Auth::ROLE_ADMIN => 'BC Supervisor',
            Auth::ROLE_MANAGER => 'Branch Manager',
            Auth::ROLE_BC => 'BCA',
        ];
    }
}
