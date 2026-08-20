<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Core\Database;
use App\Core\Lang;
use App\Core\Request;
use App\Core\Settings;
use App\Services\Audit;
use App\Services\Dashboard;
use App\Services\Notify;

/**
 * Live monitoring, audit log, notifications and system settings.
 */
final class SystemController extends BaseController
{
    /* ------------------------------------------------------------------ */
    /* Live monitoring                                                    */
    /* ------------------------------------------------------------------ */

    public function monitoring(Request $request): void
    {
        $branchId = (int) $request->query('branch_id', 0);

        $rows = Dashboard::monitoring($branchId > 0 ? $branchId : null);

        $this->page('admin.monitoring', [
            'title' => 'Live monitoring',
            'rows' => $rows,
            'branches' => $this->branchOptions(),
            'branchId' => $branchId,
            'offlineMinutes' => Settings::int('supervisor_offline_minutes', 15),
            'online' => count(array_filter($rows, static fn (array $r): bool => (int) $r['is_online'] === 1)),
        ]);
    }

    /**
     * Today's route for one supervisor: the ordered points they recorded.
     */
    public function route(Request $request): void
    {
        $supervisorId = $request->paramInt('id');
        $date = (string) $request->query('date', today());

        $supervisor = Database::selectOne(
            'SELECT s.*, u.name, b.name AS branch_name
               FROM bc_supervisors s JOIN users u ON u.id = s.user_id JOIN branches b ON b.id = s.branch_id
              WHERE s.id = :id',
            ['id' => $supervisorId]
        );

        if ($supervisor === null) {
            $this->abort(404, 'BC Supervisor not found.');
        }

        $this->assertBranch((int) $supervisor['branch_id']);

        $this->page('admin.route', [
            'title' => 'Route: ' . $supervisor['name'],
            'supervisor' => $supervisor,
            'date' => $date,
            'points' => Database::select(
                "SELECT g.*, a.account_number, a.borrower_name, a.village, v.id AS visit_id, v.visit_status
                   FROM visit_gps g
                   JOIN visits v ON v.id = g.visit_id
                   JOIN loan_accounts a ON a.id = v.loan_account_id
                  WHERE v.bc_supervisor_id = :bc AND v.visit_date = :date
                  ORDER BY g.captured_at ASC, g.id ASC",
                ['bc' => $supervisorId, 'date' => $date]
            ),
            'attendance' => Database::selectOne(
                'SELECT * FROM attendance WHERE bc_supervisor_id = :bc AND attendance_date = :date',
                ['bc' => $supervisorId, 'date' => $date]
            ),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Audit log                                                          */
    /* ------------------------------------------------------------------ */

    public function audit(Request $request): void
    {
        $filters = $this->filters($request, ['action', 'user_id', 'entity_type', 'from', 'to', 'search']);
        $where = ['1 = 1'];
        $params = [];

        if (!empty($filters['action'])) {
            $where[] = 'action = :action';
            $params['action'] = (string) $filters['action'];
        }

        if (!empty($filters['user_id'])) {
            $where[] = 'user_id = :uid';
            $params['uid'] = (int) $filters['user_id'];
        }

        if (!empty($filters['entity_type'])) {
            $where[] = 'entity_type = :entity';
            $params['entity'] = (string) $filters['entity_type'];
        }

        if (!empty($filters['from'])) {
            $where[] = 'created_at >= :from';
            $params['from'] = date('Y-m-d 00:00:00', (int) strtotime((string) $filters['from']));
        }

        if (!empty($filters['to'])) {
            $where[] = 'created_at <= :to';
            $params['to'] = date('Y-m-d 23:59:59', (int) strtotime((string) $filters['to']));
        }

        if (!empty($filters['search'])) {
            $where[] = '(description LIKE :search OR user_name LIKE :search OR request_path LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }

        $sql = 'SELECT * FROM audit_logs WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC';

        $page = $this->page_number($request);
        $perPage = 60;
        $total = (int) Database::scalar('SELECT COUNT(*) FROM audit_logs WHERE ' . implode(' AND ', $where), $params);
        $rows = Database::select($sql . sprintf(' LIMIT %d OFFSET %d', $perPage, ($page - 1) * $perPage), $params);

        $this->page('admin.audit', [
            'title' => 'Audit log',
            'logs' => $rows,
            'filters' => $filters,
            'actions' => Database::select('SELECT DISTINCT action FROM audit_logs ORDER BY action'),
            'entityTypes' => Database::select('SELECT DISTINCT entity_type FROM audit_logs WHERE entity_type IS NOT NULL ORDER BY entity_type'),
            'users' => Database::select(
                'SELECT u.id, u.name, r.slug AS role FROM users u JOIN roles r ON r.id = u.role_id ORDER BY u.name'
            ),
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'lastPage' => max(1, (int) ceil($total / $perPage)),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Settings                                                           */
    /* ------------------------------------------------------------------ */

    public function settings(Request $request): void
    {
        $this->page('admin.settings', [
            'title' => 'Settings',
            'stats' => [
                'php' => PHP_VERSION,
                'database' => (string) Database::scalar('SELECT VERSION()'),
                'timezone' => date_default_timezone_get(),
                'server_time' => now(),
                'gd' => extension_loaded('gd'),
                'zip' => extension_loaded('zip'),
                'storage_writable' => is_writable(storage_path('uploads')),
                'photos' => (int) Database::scalar('SELECT COUNT(*) FROM visit_photos')
                    + (int) Database::scalar('SELECT COUNT(*) FROM inspection_photos'),
                'accounts' => (int) Database::scalar('SELECT COUNT(*) FROM loan_accounts'),
                'visits' => (int) Database::scalar('SELECT COUNT(*) FROM visits'),
                'inspections' => (int) Database::scalar('SELECT COUNT(*) FROM inspections'),
                'audit_rows' => (int) Database::scalar('SELECT COUNT(*) FROM audit_logs'),
            ],
        ]);
    }

    public function saveSettings(Request $request): void
    {
        $before = Settings::all();

        // An unknown code would leave the panel with no strings at all, so only a
        // language that ships a catalogue can become the default.
        $locale = trim((string) $request->input('default_locale', Lang::FALLBACK));

        $values = [
            'site_name' => trim((string) $request->input('site_name', 'LRMS')),
            'organisation_name' => trim((string) $request->input('organisation_name', '')),
            'default_locale' => Lang::isSupported($locale) ? $locale : Lang::FALLBACK,
            'maintenance_mode' => $request->boolean('maintenance_mode') ? '1' : '0',
            'supervisor_offline_minutes' => (string) max(1, (int) $request->input('supervisor_offline_minutes', 15)),
        ];

        $field = [
            'min_visit_photos' => (string) max(0, (int) $request->input('min_visit_photos', 1)),
            'min_inspection_photos' => (string) max(0, (int) $request->input('min_inspection_photos', 1)),
            // At least a day, or the app could never record anything at all.
            'sss_backdate_days' => (string) max(1, (int) $request->input('sss_backdate_days', 30)),
            'watermark_photos' => $request->boolean('watermark_photos') ? '1' : '0',
            'payment_modes' => trim((string) $request->input('payment_modes', 'UPI,Bank Transfer,Cheque,Other')),
        ];

        $gps = [
            'gps_max_accuracy_metres' => (string) max(0, (int) $request->input('gps_max_accuracy_metres', 200)),
            'gps_max_drift_metres' => (string) max(0, (int) $request->input('gps_max_drift_metres', 0)),
            'gps_mock_location_allowed' => $request->boolean('gps_mock_location_allowed') ? '1' : '0',
        ];

        $security = [
            'otp_web_login' => $request->boolean('otp_web_login') ? '1' : '0',
            'otp_app_login' => $request->boolean('otp_app_login') ? '1' : '0',
            'device_binding' => $request->boolean('device_binding') ? '1' : '0',
            'api_token_ttl_days' => (string) max(1, (int) $request->input('api_token_ttl_days', 30)),
        ];

        $sms = [
            'sms_enabled' => $request->boolean('sms_enabled') ? '1' : '0',
            'sms_endpoint' => trim((string) $request->input('sms_endpoint', '')),
            'sms_sender_id' => trim((string) $request->input('sms_sender_id', '')),
        ];

        // The API key is only overwritten when a new value is actually supplied.
        $apiKey = trim((string) $request->input('sms_api_key', ''));

        if ($apiKey !== '') {
            $sms['sms_api_key'] = $apiKey;
        }

        Settings::setMany($values, 'general');
        Settings::setMany($field, 'field');
        Settings::setMany($gps, 'gps');
        Settings::setMany($security, 'security');
        Settings::setMany($sms, 'sms');

        $after = array_merge($values, $field, $gps, $security, $sms);
        $changed = [];
        $previous = [];

        foreach ($after as $key => $value) {
            if ((string) ($before[$key] ?? '') !== (string) $value) {
                $changed[$key] = $key === 'sms_api_key' ? '[updated]' : $value;
                $previous[$key] = $key === 'sms_api_key' ? '[redacted]' : ($before[$key] ?? null);
            }
        }

        if ($changed !== []) {
            Audit::log(Audit::SETTINGS_CHANGED, [
                'entity_type' => 'system_settings',
                'description' => sprintf('%d setting(s) changed.', count($changed)),
                'old' => $previous,
                'new' => $changed,
            ]);
        }

        Settings::flush();

        $this->success('Settings saved.');
        $this->redirect('/admin/settings');
    }

    /* ------------------------------------------------------------------ */
    /* Notifications                                                      */
    /* ------------------------------------------------------------------ */

    public function broadcast(Request $request): void
    {
        $data = $this->validate($request, [
            'title' => 'required|max:190',
            'body' => 'nullable|max:500',
            'audience' => 'required|in:all_bc,all_managers,branch',
            'branch_id' => 'nullable|integer',
        ], [], '/admin/notifications');

        $audience = (string) $data['audience'];

        if ($audience === 'all_bc') {
            Notify::role(\App\Core\Auth::ROLE_BC, (string) $data['title'], (string) ($data['body'] ?? ''), ['type' => 'info']);
            $target = 'all BC Supervisors';
        } elseif ($audience === 'all_managers') {
            Notify::role(\App\Core\Auth::ROLE_MANAGER, (string) $data['title'], (string) ($data['body'] ?? ''), ['type' => 'info']);
            $target = 'all Branch Managers';
        } else {
            $branchId = (int) ($data['branch_id'] ?? 0);

            if ($branchId <= 0) {
                $this->error('Choose a branch for a branch announcement.');
                $this->back('/admin/notifications');

                return;
            }

            Notify::branch($branchId, (string) $data['title'], (string) ($data['body'] ?? ''), ['type' => 'info']);
            $target = 'branch #' . $branchId;
        }

        Audit::log(Audit::NOTIFICATION_SENT, [
            'entity_type' => 'notification',
            'description' => sprintf('Announcement sent to %s: %s', $target, $data['title']),
        ]);

        $this->success('Announcement sent to ' . $target . '.');
        $this->back('/admin/notifications');
    }
}
