<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Services\Allocation;

/**
 * Shared plumbing for the two authenticated web areas (admin panel and branch
 * portal): filter option lists, request filter extraction and the sidebar badge
 * counts.
 */
abstract class BaseController extends Controller
{
    /**
     * Render with the sidebar badges always populated.
     */
    protected function page(string $view, array $data = []): void
    {
        $data['navBadges'] = $this->navBadges();

        $this->view($view, $data, 'layouts.app');
    }

    /**
     * @return array<string, int>
     */
    protected function navBadges(): array
    {
        $branchId = Auth::branchId();

        if (Auth::isAdmin()) {
            return [
                'unassigned' => Allocation::unassignedCount(),
                'late_pending' => (int) Database::scalar(
                    "SELECT COUNT(*) FROM report_submissions WHERE status = 'late_pending'"
                ),
            ];
        }

        return [
            'unassigned' => $branchId === null ? 0 : Allocation::unassignedCount($branchId),
            'late_pending' => 0,
        ];
    }

    /**
     * Branches the current user may filter by.
     *
     * @return array<int, array{id:int, name:string, code:string}>
     */
    protected function branchOptions(): array
    {
        if (Auth::isAdmin()) {
            return Database::select("SELECT id, name, code FROM branches WHERE status = 'active' ORDER BY name");
        }

        $branchId = Auth::branchId();

        if ($branchId === null) {
            return [];
        }

        return Database::select('SELECT id, name, code FROM branches WHERE id = :id', ['id' => $branchId]);
    }

    /**
     * BC Supervisors the current user may filter by.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function supervisorOptions(?int $branchId = null): array
    {
        $where = ["u.status = 'active'"];
        $params = [];

        $scopeBranch = Auth::isAdmin() ? $branchId : Auth::branchId();

        if ($scopeBranch !== null) {
            $where[] = 's.branch_id = :branch';
            $params['branch'] = $scopeBranch;
        }

        return Database::select(
            'SELECT s.id, s.bc_code, s.status, u.name, b.name AS branch_name
               FROM bc_supervisors s
               JOIN users u ON u.id = s.user_id
               JOIN branches b ON b.id = s.branch_id
              WHERE ' . implode(' AND ', $where)
            . ' ORDER BY b.name, u.name',
            $params
        );
    }

    /**
     * Pull the recognised filters for a screen out of the query string.
     *
     * @param array<int, string> $allowed
     * @return array<string, string>
     */
    protected function filters(Request $request, array $allowed): array
    {
        $filters = [];

        foreach ($allowed as $key) {
            $value = $request->query($key);

            if ($value === null || $value === '' || is_array($value)) {
                continue;
            }

            $filters[$key] = trim((string) $value);
        }

        // A Branch Manager's reports are always pinned to their own branch.
        if (!Auth::isAdmin() && Auth::branchId() !== null) {
            $filters['branch_id'] = (string) Auth::branchId();
        }

        return $filters;
    }

    protected function page_number(Request $request): int
    {
        return max(1, (int) $request->query('page', 1));
    }

    /**
     * Guard: a Branch Manager may only touch their own branch's records.
     */
    protected function assertBranch(?int $branchId): void
    {
        \App\Core\Acl::authorizeBranch($branchId === null ? null : (int) $branchId);
    }
}
