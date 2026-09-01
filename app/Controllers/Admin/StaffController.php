<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Core\ApiAuth;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Services\Audit;
use App\Services\Notify;

/**
 * Branch Manager and BCA accounts.
 *
 * Both are `users` rows plus a role-specific profile row, created in one
 * transaction so a half-made account can never exist. Temporary passwords are
 * generated here and must be changed at first sign-in.
 */
final class StaffController extends BaseController
{
    /* ------------------------------------------------------------------ */
    /* Branch managers                                                    */
    /* ------------------------------------------------------------------ */

    public function managers(Request $request): void
    {
        $search = trim((string) $request->query('search', ''));
        $branchId = (int) $request->query('branch_id', 0);

        $where = ["r.slug = 'branch_manager'"];
        $params = [];

        if ($search !== '') {
            $where[] = '(u.name LIKE :search OR u.email LIKE :search OR u.username LIKE :search OR u.employee_code LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        if ($branchId > 0) {
            $where[] = 'u.branch_id = :branch';
            $params['branch'] = $branchId;
        }

        $managers = Database::select(
            'SELECT u.*, b.name AS branch_name, b.code AS branch_code,
                    m.designation, m.contact_number, m.status AS profile_status
               FROM users u
               JOIN roles r ON r.id = u.role_id
          LEFT JOIN branches b ON b.id = u.branch_id
          LEFT JOIN branch_managers m ON m.user_id = u.id
              WHERE ' . implode(' AND ', $where)
            . ' ORDER BY b.name ASC, u.name ASC',
            $params
        );

        $this->page('admin.staff.managers', [
            'title' => 'Branch managers',
            'managers' => $managers,
            'branches' => $this->branchOptions(),
            'search' => $search,
            'branchId' => $branchId,
        ]);
    }

    public function createManager(Request $request): void
    {
        $this->page('admin.staff.manager-form', [
            'title' => 'Add branch manager',
            'branches' => $this->branchOptions(),
            'manager' => null,
        ]);
    }

    public function storeManager(Request $request): void
    {
        $data = $this->validate($request, [
            'name' => 'required|max:160',
            'email' => 'required|email|max:190|unique:users,email',
            'username' => 'nullable|max:80|unique:users,username',
            'employee_code' => 'nullable|max:60|unique:users,employee_code',
            // A manager's number resolves a sign-in exactly like a BCA's does, so it has to be
            // unique for the same reason. Leaving it off here meant this form could quietly
            // break a BCA's mobile sign-in from a screen that never mentions authentication.
            'mobile' => 'nullable|max:20|mobile|unique_mobile',
            'branch_id' => 'required|integer|exists:branches,id',
            'designation' => 'nullable|max:120',
            'password' => 'nullable|password',
        ], [], '/admin/managers/create');

        $password = $data['password'] !== null && $data['password'] !== ''
            ? (string) $data['password']
            : $this->temporaryPassword();

        $roleId = (int) Database::scalar('SELECT id FROM roles WHERE slug = :s', ['s' => Auth::ROLE_MANAGER]);

        $userId = Database::transaction(function () use ($data, $password, $roleId): int {
            $userId = Database::insert('users', [
                'role_id' => $roleId,
                'branch_id' => (int) $data['branch_id'],
                'name' => (string) $data['name'],
                'email' => (string) $data['email'],
                'username' => $data['username'] ?: null,
                'employee_code' => $data['employee_code'] ?: null,
                'mobile' => $data['mobile'] ?: null,
                'password' => Auth::hashPassword($password),
                'status' => 'active',
                'must_change_password' => 1,
                'created_by' => auth_id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Database::insert('branch_managers', [
                'user_id' => $userId,
                'branch_id' => (int) $data['branch_id'],
                'designation' => $data['designation'] ?: 'Branch Manager',
                'contact_number' => $data['mobile'] ?: null,
                'status' => 'active',
                'assigned_at' => now(),
                'assigned_by' => auth_id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $userId;
        });

        Audit::log(Audit::USER_CREATED, [
            'entity_type' => 'user',
            'entity_id' => $userId,
            'description' => sprintf('Branch Manager %s created for branch #%d.', $data['name'], (int) $data['branch_id']),
            'new' => ['email' => $data['email'], 'branch_id' => $data['branch_id'], 'role' => Auth::ROLE_MANAGER],
        ]);

        $this->success(sprintf(
            'Branch Manager created. Temporary password: %s — it must be changed at first sign-in.',
            $password
        ));
        $this->redirect('/admin/managers');
    }

    public function editManager(Request $request): void
    {
        $manager = $this->findStaff($request->paramInt('id'), Auth::ROLE_MANAGER);

        $this->page('admin.staff.manager-form', [
            'title' => 'Edit branch manager',
            'branches' => $this->branchOptions(),
            'manager' => $manager,
        ]);
    }

    public function updateManager(Request $request): void
    {
        $id = $request->paramInt('id');
        $manager = $this->findStaff($id, Auth::ROLE_MANAGER);

        $data = $this->validate($request, [
            'name' => 'required|max:160',
            'email' => 'required|email|max:190|unique:users,email,' . $id,
            'username' => 'nullable|max:80|unique:users,username,' . $id,
            'employee_code' => 'nullable|max:60|unique:users,employee_code,' . $id,
            'mobile' => 'nullable|max:20|mobile|unique_mobile:' . $id,
            'branch_id' => 'required|integer|exists:branches,id',
            'designation' => 'nullable|max:120',
            'status' => 'required|in:active,inactive,suspended',
        ], [], '/admin/managers/' . $id . '/edit');

        $payload = [
            'name' => (string) $data['name'],
            'email' => (string) $data['email'],
            'username' => $data['username'] ?: null,
            'employee_code' => $data['employee_code'] ?: null,
            'mobile' => $data['mobile'] ?: null,
            'branch_id' => (int) $data['branch_id'],
            'status' => (string) $data['status'],
            'updated_at' => now(),
        ];

        Database::transaction(function () use ($id, $payload, $data): void {
            Database::update('users', $payload, 'id = :id', ['id' => $id]);

            Database::update('branch_managers', [
                'branch_id' => (int) $data['branch_id'],
                'designation' => $data['designation'] ?: 'Branch Manager',
                'contact_number' => $data['mobile'] ?: null,
                'status' => (string) $data['status'] === 'active' ? 'active' : 'inactive',
                'updated_at' => now(),
            ], 'user_id = :uid', ['uid' => $id]);
        });

        // A disabled account must not keep working sessions or tokens.
        if ((string) $data['status'] !== 'active') {
            ApiAuth::revokeAllFor($id);
        }

        Audit::logChange(Audit::USER_UPDATED, 'user', $id, $manager, $payload, sprintf('Branch Manager %s updated.', $data['name']));

        $this->success('Branch manager updated.');
        $this->redirect('/admin/managers');
    }

    /* ------------------------------------------------------------------ */
    /* BCAs                                                     */
    /* ------------------------------------------------------------------ */

    public function supervisors(Request $request): void
    {
        $search = trim((string) $request->query('search', ''));
        $branchId = (int) $request->query('branch_id', 0);
        $status = (string) $request->query('status', '');

        $where = ['1 = 1'];
        $params = [];

        if ($search !== '') {
            $where[] = '(u.name LIKE :search OR s.bc_code LIKE :search OR u.email LIKE :search OR s.mobile LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        if ($branchId > 0) {
            $where[] = 's.branch_id = :branch';
            $params['branch'] = $branchId;
        }

        if (in_array($status, ['active', 'inactive', 'suspended'], true)) {
            $where[] = 's.status = :status';
            $params['status'] = $status;
        }

        $supervisors = Database::select(
            "SELECT s.*, u.name, u.email, u.username, u.employee_code, u.status AS user_status,
                    u.last_login_at, b.name AS branch_name, b.code AS branch_code,
                    (SELECT COUNT(*) FROM account_assignments x WHERE x.bc_supervisor_id = s.id AND x.is_active = 1) AS accounts,
                    (SELECT COUNT(*) FROM visits v WHERE v.bc_supervisor_id = s.id AND v.visit_date = CURDATE()
                       AND v.status <> 'draft') AS visits_today,
                    (SELECT COALESCE(SUM(r.amount), 0) FROM recoveries r
                      WHERE r.bc_supervisor_id = s.id AND r.recovery_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                        AND r.status <> 'rejected') AS recovery_month,
                    d.id AS device_id, d.device_uuid, d.model, d.app_version, d.last_seen_at, d.status AS device_status
               FROM bc_supervisors s
               JOIN users u ON u.id = s.user_id
               JOIN branches b ON b.id = s.branch_id
          LEFT JOIN devices d ON d.id = (
                    SELECT id FROM devices WHERE user_id = s.user_id ORDER BY (status = 'active') DESC, last_seen_at DESC LIMIT 1
               )
              WHERE " . implode(' AND ', $where)
            . ' ORDER BY b.name ASC, u.name ASC',
            $params
        );

        $this->page('admin.staff.supervisors', [
            'title' => 'BCAs',
            'supervisors' => $supervisors,
            'branches' => $this->branchOptions(),
            'search' => $search,
            'branchId' => $branchId,
            'status' => $status,
        ]);
    }

    public function createSupervisor(Request $request): void
    {
        $this->page('admin.staff.supervisor-form', [
            'title' => 'Add BCA',
            'branches' => $this->branchOptions(),
            'supervisor' => null,
        ]);
    }

    public function storeSupervisor(Request $request): void
    {
        $data = $this->validate($request, [
            'name' => 'required|max:160',
            'bc_code' => 'required|max:60|unique:bc_supervisors,bc_code',
            'email' => 'nullable|email|max:190|unique:users,email',
            // The BCA signs in with the BCBF code or this number, so it has to identify one
            // person. There is no username or employee code on this form any more.
            'mobile' => 'required|max:20|mobile|unique_mobile',
            'branch_id' => 'required|integer|exists:branches,id',
            'village' => 'nullable|max:120',
            'address' => 'nullable|max:255',
            'joined_on' => 'nullable|date',
            'password' => 'nullable|password',
        ] + $this->supervisorProfileRules(), [], '/admin/supervisors/create');

        $password = $data['password'] !== null && $data['password'] !== ''
            ? (string) $data['password']
            : $this->temporaryPassword();

        $roleId = (int) Database::scalar('SELECT id FROM roles WHERE slug = :s', ['s' => Auth::ROLE_BC]);

        $profile = $this->supervisorProfile($data);

        $result = Database::transaction(function () use ($data, $password, $roleId, $profile): array {
            $userId = Database::insert('users', [
                'role_id' => $roleId,
                'branch_id' => (int) $data['branch_id'],
                'name' => (string) $data['name'],
                'email' => $data['email'] ?: null,
                /*
                 * NULL, not ''. Both columns are UNIQUE and nullable, and MySQL allows any
                 * number of NULLs in a unique index but only one empty string — so '' would
                 * create the first BCA and fail the second on uq_users_username with a
                 * constraint error this form has no way to explain.
                 */
                'username' => null,
                'employee_code' => null,
                'mobile' => (string) $data['mobile'],
                'password' => Auth::hashPassword($password),
                'status' => 'active',
                'must_change_password' => 0,
                'created_by' => auth_id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $supervisorId = Database::insert('bc_supervisors', array_merge($profile, [
                'user_id' => $userId,
                'branch_id' => (int) $data['branch_id'],
                'bc_code' => strtoupper(trim((string) $data['bc_code'])),
                'mobile' => (string) $data['mobile'],
                'village' => $data['village'] ?: null,
                'address' => $data['address'] ?: null,
                'joined_on' => $data['joined_on'] ?: null,
                'status' => 'active',
                'created_by' => auth_id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]));

            return ['user_id' => $userId, 'supervisor_id' => $supervisorId];
        });

        Audit::log(Audit::USER_CREATED, [
            'entity_type' => 'bc_supervisor',
            'entity_id' => $result['supervisor_id'],
            'description' => sprintf(
                'BCA %s (%s) created for branch #%d.',
                $data['name'],
                strtoupper((string) $data['bc_code']),
                (int) $data['branch_id']
            ),
            'new' => [
                'bc_code' => $data['bc_code'],
                'mobile' => $data['mobile'],
                'branch_id' => $data['branch_id'],
            ],
        ]);

        Notify::user(
            $result['user_id'],
            'Welcome to LRMS',
            'Sign in to the LRMS Android app with your BCBF code or your mobile number, and the '
                . 'password given to you. Change it after your first sign-in.',
            ['type' => 'info']
        );

        // The code, not the password alone. This message is what an Admin reads out or forwards,
        // and a password on its own leaves them to guess what to type it against.
        $this->success(sprintf(
            'BCA created. They sign in with BCBF code %s or mobile %s · password: %s (share securely).',
            strtoupper(trim((string) $data['bc_code'])),
            (string) $data['mobile'],
            $password
        ));
        $this->redirect('/admin/supervisors');
    }

    public function editSupervisor(Request $request): void
    {
        $supervisor = Database::selectOne(
            'SELECT s.*, u.name, u.email, u.username, u.employee_code, u.status AS user_status
               FROM bc_supervisors s JOIN users u ON u.id = s.user_id
              WHERE s.id = :id',
            ['id' => $request->paramInt('id')]
        );

        if ($supervisor === null) {
            $this->abort(404, 'BCA not found.');
        }

        $this->page('admin.staff.supervisor-form', [
            'title' => 'Edit BCA',
            'branches' => $this->branchOptions(),
            'supervisor' => $supervisor,
            'devices' => Database::select(
                'SELECT * FROM devices WHERE user_id = :uid ORDER BY last_seen_at DESC',
                ['uid' => (int) $supervisor['user_id']]
            ),
        ]);
    }

    public function updateSupervisor(Request $request): void
    {
        $id = $request->paramInt('id');
        $supervisor = Database::selectOne('SELECT * FROM bc_supervisors WHERE id = :id', ['id' => $id]);

        if ($supervisor === null) {
            $this->abort(404, 'BCA not found.');
        }

        $userId = (int) $supervisor['user_id'];

        $data = $this->validate($request, [
            'name' => 'required|max:160',
            'bc_code' => 'required|max:60|unique:bc_supervisors,bc_code,' . $id,
            'email' => 'nullable|email|max:190|unique:users,email,' . $userId,
            'mobile' => 'required|max:20|mobile|unique_mobile:' . $userId,
            'branch_id' => 'required|integer|exists:branches,id',
            'village' => 'nullable|max:120',
            'address' => 'nullable|max:255',
            'joined_on' => 'nullable|date',
            'status' => 'required|in:active,inactive,suspended',
        ] + $this->supervisorProfileRules(), [], '/admin/supervisors/' . $id . '/edit');

        // Moving a supervisor between branches would orphan their allocations.
        $newBranch = (int) $data['branch_id'];
        $activeAccounts = (int) Database::scalar(
            'SELECT COUNT(*) FROM account_assignments WHERE bc_supervisor_id = :id AND is_active = 1',
            ['id' => $id]
        );

        if ($newBranch !== (int) $supervisor['branch_id'] && $activeAccounts > 0) {
            $this->error(sprintf(
                'Reassign the %d account(s) currently allocated to this supervisor before moving them to another branch.',
                $activeAccounts
            ));
            $this->redirect('/admin/supervisors/' . $id . '/edit');

            return;
        }

        $profile = $this->supervisorProfile($data);

        Database::transaction(function () use ($id, $userId, $data, $newBranch, $profile): void {
            Database::update('users', [
                'name' => (string) $data['name'],
                'email' => $data['email'] ?: null,
                /*
                 * `username` and `employee_code` are deliberately not written here.
                 *
                 * The form no longer collects them, so it must not clear them either. A BCA
                 * created before this change still has a username and may still be signing in
                 * with it; setting it to NULL because an Admin corrected a village name would
                 * take that away with nothing on screen to say it had happened. They are left
                 * exactly as they are, and new BCAs simply never get one.
                 */
                'mobile' => (string) $data['mobile'],
                'branch_id' => $newBranch,
                'status' => (string) $data['status'],
                'updated_at' => now(),
            ], 'id = :id', ['id' => $userId]);

            Database::update('bc_supervisors', array_merge($profile, [
                'bc_code' => strtoupper(trim((string) $data['bc_code'])),
                'mobile' => (string) $data['mobile'],
                'village' => $data['village'] ?: null,
                'address' => $data['address'] ?: null,
                'joined_on' => $data['joined_on'] ?: null,
                'branch_id' => $newBranch,
                'status' => (string) $data['status'],
                'updated_at' => now(),
            ]), 'id = :id', ['id' => $id]);
        });

        if ((string) $data['status'] !== 'active') {
            ApiAuth::revokeAllFor($userId);
        }

        Audit::logChange(
            Audit::USER_UPDATED,
            'bc_supervisor',
            $id,
            $supervisor,
            ['bc_code' => $data['bc_code'], 'branch_id' => $newBranch, 'status' => $data['status']],
            sprintf('BCA %s updated.', $data['name'])
        );

        $this->success('BCA updated.');
        $this->redirect('/admin/supervisors');
    }

    /* ------------------------------------------------------------------ */
    /* Credentials and devices                                            */
    /* ------------------------------------------------------------------ */

    public function resetPassword(Request $request): void
    {
        $userId = $request->paramInt('id');
        $user = Auth::findById($userId);

        if ($user === null) {
            $this->abort(404, 'User not found.');
        }

        if ((string) $user['role'] === Auth::ROLE_ADMIN && $userId !== auth_id()) {
            $this->abort(403, 'Another BC Supervisor password can only be reset by its owner.');
        }

        $password = $this->temporaryPassword();

        Database::update('users', [
            'password' => Auth::hashPassword($password),
            'must_change_password' => 1,
            'failed_attempts' => 0,
            'locked_until' => null,
            'updated_at' => now(),
        ], 'id = :id', ['id' => $userId]);

        // Force the app to sign in again with the new credentials.
        ApiAuth::revokeAllFor($userId);

        Audit::log(Audit::PASSWORD_RESET, [
            'entity_type' => 'user',
            'entity_id' => $userId,
            'description' => sprintf('Temporary password issued for %s.', $user['name']),
        ]);

        $this->success(sprintf('Temporary password for %s: %s (share securely).', $user['name'], $password));
        $this->back('/admin/supervisors');
    }

    public function unlockUser(Request $request): void
    {
        $userId = $request->paramInt('id');

        Database::update('users', [
            'failed_attempts' => 0,
            'locked_until' => null,
            'updated_at' => now(),
        ], 'id = :id', ['id' => $userId]);

        // Clearing the database lock alone was not enough: the sign-in throttle
        // lives in its own counter, so the panel reported "Account unlocked" while
        // the supervisor's app kept getting 429 for the rest of the window. Every
        // identifier they might type is cleared, for the app and the web form.
        $identifiers = Database::selectOne(
            'SELECT u.email, u.username, u.employee_code, u.mobile, s.bc_code
               FROM users u
          LEFT JOIN bc_supervisors s ON s.user_id = u.id
              WHERE u.id = :id',
            ['id' => $userId]
        ) ?? [];

        $keys = [];

        foreach ($identifiers as $identifier) {
            $identifier = trim((string) $identifier);

            if ($identifier === '') {
                continue;
            }

            /*
             * Through Auth::throttleKey, which is what the sign-in counted against. A phone
             * number has no fixed spelling, so clearing the one the Admin happened to store
             * could never be enough on its own — that is the same bug this loop was written to
             * fix, one identifier later. Canonicalising at both ends is what closes it.
             */
            $keys[] = Auth::throttleKey($identifier);
        }

        foreach (array_unique($keys) as $key) {
            RateLimiter::clear('api-login:user:' . $key);
            RateLimiter::clear('login:user:' . $key);
        }

        Audit::log(Audit::USER_STATUS_CHANGED, [
            'entity_type' => 'user',
            'entity_id' => $userId,
            'description' => 'Account lock cleared.',
        ]);

        $this->success('Account unlocked.');
        $this->back('/admin/supervisors');
    }

    /**
     * Release a device binding so a supervisor can sign in on a new handset.
     */
    public function resetDevice(Request $request): void
    {
        $deviceId = $request->paramInt('id');
        $device = Database::selectOne('SELECT * FROM devices WHERE id = :id', ['id' => $deviceId]);

        if ($device === null) {
            $this->abort(404, 'Device not found.');
        }

        Database::update('devices', [
            'status' => 'unbound',
            'updated_at' => now(),
        ], 'id = :id', ['id' => $deviceId]);

        ApiAuth::revokeAllFor((int) $device['user_id']);

        Audit::log(Audit::DEVICE_RESET, [
            'entity_type' => 'device',
            'entity_id' => $deviceId,
            'description' => sprintf('Device binding released (%s).', $device['model'] ?: $device['device_uuid']),
            'old' => ['status' => $device['status']],
            'new' => ['status' => 'unbound'],
        ]);

        Notify::user(
            (int) $device['user_id'],
            'Device binding reset',
            'You can now sign in to the LRMS app on a new device.',
            ['type' => 'info']
        );

        $this->success('Device binding released. The supervisor can now register a new device.');
        $this->back('/admin/supervisors');
    }

    public function blockDevice(Request $request): void
    {
        $deviceId = $request->paramInt('id');
        $device = Database::selectOne('SELECT * FROM devices WHERE id = :id', ['id' => $deviceId]);

        if ($device === null) {
            $this->abort(404, 'Device not found.');
        }

        $blocked = (string) $device['status'] === 'blocked';

        Database::update('devices', [
            'status' => $blocked ? 'active' : 'blocked',
            'updated_at' => now(),
        ], 'id = :id', ['id' => $deviceId]);

        if (!$blocked) {
            ApiAuth::revokeAllFor((int) $device['user_id']);
        }

        Audit::log($blocked ? Audit::DEVICE_RESET : Audit::DEVICE_BLOCKED, [
            'entity_type' => 'device',
            'entity_id' => $deviceId,
            'description' => $blocked ? 'Device unblocked.' : 'Device blocked; its tokens were revoked.',
        ]);

        $this->success($blocked ? 'Device unblocked.' : 'Device blocked.');
        $this->back('/admin/supervisors');
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                            */
    /* ------------------------------------------------------------------ */

    /* ------------------------------------------------------------------ */
    /* BCA profile fields                                       */
    /* ------------------------------------------------------------------ */

    /**
     * The identity and address fields from the BC creation form, shared by the
     * create and edit paths so the two cannot drift apart.
     *
     * All are optional: a supervisor can be set up with the essentials and
     * completed later, which is how these are actually collected. They are the
     * fields the field visit verification report prints in sections 1 and 12.
     *
     * @return array<string, string>
     */
    private function supervisorProfileRules(): array
    {
        return [
            'sp_cbc_name' => 'nullable|max:190',
            'ssa' => 'nullable|max:160',
            'iibf_number' => 'nullable|max:60',
            'dra_id' => 'nullable|max:60',
            'designation' => 'nullable|max:120',
            // Accepts a full Aadhaar number or just the last four; only the last
            // four are stored (see below).
            'aadhaar_number' => 'nullable|max:20',
            'pan_number' => ['nullable', 'max:12', 'regex:/^[A-Za-z]{5}[0-9]{4}[A-Za-z]$/'],
            'block' => 'nullable|max:120',
            'tehsil' => 'nullable|max:120',
            'district' => 'nullable|max:120',
            'state' => 'nullable|max:120',
            'pincode' => 'nullable|max:12',
        ];
    }

    /**
     * Map the validated profile input onto `bc_supervisors` columns.
     *
     * @param array<string, mixed> $data
     * @return array<string, string|null>
     */
    private function supervisorProfile(array $data): array
    {
        $text = static function (mixed $value): ?string {
            $value = trim((string) ($value ?? ''));

            return $value === '' ? null : $value;
        };

        // Only the last four digits of Aadhaar are kept. LRMS never prints more
        // than XXXX-XXXX-nnnn, so storing the rest would be holding identity
        // data the system has no use for.
        $aadhaar = (string) preg_replace('/\D/', '', (string) ($data['aadhaar_number'] ?? ''));
        $aadhaarLast4 = strlen($aadhaar) >= 4 ? substr($aadhaar, -4) : null;

        $pan = $text($data['pan_number'] ?? null);

        return [
            'sp_cbc_name' => $text($data['sp_cbc_name'] ?? null),
            'ssa' => $text($data['ssa'] ?? null),
            'iibf_number' => $text($data['iibf_number'] ?? null),
            'dra_id' => $text($data['dra_id'] ?? null),
            'designation' => $text($data['designation'] ?? null),
            'aadhaar_last4' => $aadhaarLast4,
            'pan_number' => $pan === null ? null : strtoupper($pan),
            'block' => $text($data['block'] ?? null),
            'tehsil' => $text($data['tehsil'] ?? null),
            'district' => $text($data['district'] ?? null),
            'state' => $text($data['state'] ?? null),
            'pincode' => $text($data['pincode'] ?? null),
        ];
    }

    private function findStaff(int $id, string $roleSlug): array
    {
        $user = Database::selectOne(
            'SELECT u.*, r.slug AS role, m.designation
               FROM users u
               JOIN roles r ON r.id = u.role_id
          LEFT JOIN branch_managers m ON m.user_id = u.id
              WHERE u.id = :id AND r.slug = :role',
            ['id' => $id, 'role' => $roleSlug]
        );

        if ($user === null) {
            $this->abort(404, 'User not found.');
        }

        return $user;
    }

    /**
     * Readable but unguessable temporary password.
     */
    private function temporaryPassword(): string
    {
        $minLength = max(8, (int) Config::get('security.password_min_length', 8));
        $words = ['Field', 'Ledger', 'Branch', 'Recovery', 'Visit', 'Audit', 'Sanction'];

        return $words[random_int(0, count($words) - 1)]
            . '@'
            . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT)
            . substr(str_random(4), 0, max(0, $minLength - 9));
    }
}
