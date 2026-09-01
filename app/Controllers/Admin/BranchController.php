<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Core\Database;
use App\Core\Request;
use App\Services\Audit;

final class BranchController extends BaseController
{
    public function index(Request $request): void
    {
        $search = trim((string) $request->query('search', ''));
        $params = [];
        $where = ['1 = 1'];

        if ($search !== '') {
            $where[] = '(b.name LIKE :search OR b.code LIKE :search OR b.district LIKE :search OR b.region LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $status = (string) $request->query('status', '');

        if (in_array($status, ['active', 'inactive'], true)) {
            $where[] = 'b.status = :status';
            $params['status'] = $status;
        }

        $branches = Database::select(
            "SELECT b.*,
                    (SELECT COUNT(*) FROM bc_supervisors s WHERE s.branch_id = b.id AND s.status = 'active') AS supervisors,
                    (SELECT COUNT(*) FROM branch_managers m WHERE m.branch_id = b.id AND m.status = 'active') AS managers,
                    (SELECT COUNT(*) FROM loan_accounts a WHERE a.branch_id = b.id AND a.status = 'active') AS accounts,
                    (SELECT COALESCE(SUM(a.overdue), 0) FROM loan_accounts a WHERE a.branch_id = b.id AND a.status = 'active') AS overdue
               FROM branches b
              WHERE " . implode(' AND ', $where)
            . ' ORDER BY b.name ASC',
            $params
        );

        $this->page('admin.branches.index', [
            'title' => 'Branches',
            'branches' => $branches,
            'search' => $search,
            'status' => $status,
        ]);
    }

    public function create(Request $request): void
    {
        $this->page('admin.branches.form', [
            'title' => 'Add branch',
            'branch' => null,
        ]);
    }

    public function store(Request $request): void
    {
        $data = $this->validate($request, [
            'code' => 'required|max:40|unique:branches,code',
            'name' => 'required|max:160',
            'region' => 'nullable|max:120',
            'zone' => 'nullable|max:120',
            'district' => 'nullable|max:120',
            'state' => 'nullable|max:120',
            'address' => 'nullable|max:255',
            'pincode' => 'nullable|max:12',
            'phone' => 'nullable|max:20',
            'email' => 'nullable|email|max:160',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'status' => 'required|in:active,inactive',
        ], [], '/admin/branches/create');

        $id = Database::insert('branches', array_merge($this->payload($data), [
            'created_by' => auth_id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        Audit::log(Audit::BRANCH_CREATED, [
            'entity_type' => 'branch',
            'entity_id' => $id,
            'description' => sprintf('Branch %s (%s) created.', $data['name'], $data['code']),
            'new' => $data,
        ]);

        $this->success('Branch created.');
        $this->redirect('/admin/branches');
    }

    public function edit(Request $request): void
    {
        $branch = Database::selectOne('SELECT * FROM branches WHERE id = :id', ['id' => $request->paramInt('id')]);

        if ($branch === null) {
            $this->abort(404, 'Branch not found.');
        }

        $this->page('admin.branches.form', [
            'title' => 'Edit branch',
            'branch' => $branch,
        ]);
    }

    public function update(Request $request): void
    {
        $id = $request->paramInt('id');
        $branch = Database::selectOne('SELECT * FROM branches WHERE id = :id', ['id' => $id]);

        if ($branch === null) {
            $this->abort(404, 'Branch not found.');
        }

        $data = $this->validate($request, [
            'code' => 'required|max:40|unique:branches,code,' . $id,
            'name' => 'required|max:160',
            'region' => 'nullable|max:120',
            'zone' => 'nullable|max:120',
            'district' => 'nullable|max:120',
            'state' => 'nullable|max:120',
            'address' => 'nullable|max:255',
            'pincode' => 'nullable|max:12',
            'phone' => 'nullable|max:20',
            'email' => 'nullable|email|max:160',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'status' => 'required|in:active,inactive',
        ], [], '/admin/branches/' . $id . '/edit');

        $payload = $this->payload($data);
        $payload['updated_at'] = now();

        Database::update('branches', $payload, 'id = :id', ['id' => $id]);

        Audit::logChange(
            Audit::BRANCH_UPDATED,
            'branch',
            $id,
            $branch,
            $payload,
            sprintf('Branch %s updated.', $data['name'])
        );

        $this->success('Branch updated.');
        $this->redirect('/admin/branches');
    }

    /**
     * Branches are never hard deleted — accounts, visits and history reference
     * them. Deactivating removes them from allocation and new imports.
     */
    public function deactivate(Request $request): void
    {
        $id = $request->paramInt('id');
        $branch = Database::selectOne('SELECT * FROM branches WHERE id = :id', ['id' => $id]);

        if ($branch === null) {
            $this->abort(404, 'Branch not found.');
        }

        $newStatus = (string) $branch['status'] === 'active' ? 'inactive' : 'active';

        Database::update('branches', ['status' => $newStatus, 'updated_at' => now()], 'id = :id', ['id' => $id]);

        Audit::log(Audit::BRANCH_UPDATED, [
            'entity_type' => 'branch',
            'entity_id' => $id,
            'description' => sprintf('Branch %s set to %s.', $branch['name'], $newStatus),
            'old' => ['status' => $branch['status']],
            'new' => ['status' => $newStatus],
        ]);

        $this->success('Branch marked ' . $newStatus . '.');
        $this->back('/admin/branches');
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function payload(array $data): array
    {
        return [
            'code' => strtoupper(trim((string) $data['code'])),
            'name' => (string) $data['name'],
            'region' => $data['region'] ?: null,
            'zone' => $data['zone'] ?: null,
            'district' => $data['district'] ?: null,
            'state' => $data['state'] ?: null,
            'address' => $data['address'] ?: null,
            'pincode' => $data['pincode'] ?: null,
            'phone' => $data['phone'] ?: null,
            'email' => $data['email'] ?: null,
            'latitude' => $data['latitude'] === null || $data['latitude'] === '' ? null : (float) $data['latitude'],
            'longitude' => $data['longitude'] === null || $data['longitude'] === '' ? null : (float) $data['longitude'],
            'status' => (string) $data['status'],
        ];
    }
}
