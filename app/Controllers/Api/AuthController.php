<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\ApiAuth;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Settings;
use App\Services\Audit;
use App\Services\Deadline;
use App\Services\Otp;

/**
 * Android sign-in, device binding and token lifecycle.
 */
final class AuthController extends ApiController
{
    /**
     * POST /api/v1/auth/login
     *
     * Body: username, password, device { uuid, model, manufacturer, os_version,
     * app_version, fcm_token }
     */
    public function login(Request $request): void
    {
        $data = $this->validateApi($request, [
            'username' => 'required|max:190',
            'password' => 'required|max:255',
        ]);

        $login = (string) $data['username'];
        $ipKey = 'api-login:ip:' . $request->ip();
        $userKey = 'api-login:user:' . strtolower($login);
        $maxAttempts = (int) Config::get('security.login_max_attempts', 5);
        $decay = ((int) Config::get('security.login_decay_minutes', 15)) * 60;

        foreach ([$ipKey, $userKey] as $key) {
            if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
                $this->fail(
                    sprintf(
                        'Too many sign-in attempts. Try again in %d minute(s).',
                        max(1, (int) ceil(RateLimiter::availableIn($key) / 60))
                    ),
                    429,
                    'rate_limited'
                );

                return;
            }
        }

        $result = Auth::verify($login, (string) $data['password']);

        if (!$result['ok']) {
            RateLimiter::hit($ipKey, $decay);
            RateLimiter::hit($userKey, $decay);

            Audit::log(Audit::LOGIN_FAILED, [
                'user_id' => $result['user'] === null ? null : (int) $result['user']['id'],
                'description' => sprintf('Failed app sign-in for "%s" (%s).', $login, $result['reason']),
            ]);

            $this->fail(
                match ($result['reason']) {
                    'locked' => 'This account is temporarily locked after repeated failed attempts.',
                    'inactive' => 'This account is not active. Contact your Admin/Supervisor.',
                    default => 'Incorrect username or password.',
                },
                401,
                $result['reason'] === 'locked' ? 'locked' : 'invalid_credentials'
            );

            return;
        }

        $user = $result['user'];

        RateLimiter::clear($ipKey);
        RateLimiter::clear($userKey);

        if ((string) $user['role'] !== Auth::ROLE_BC) {
            $this->fail(
                'This app is for BC Supervisors. Admin/Supervisor and Branch Manager accounts use the web portal.',
                403,
                'wrong_role'
            );

            return;
        }

        // Two-step sign-in, when enabled.
        if (Settings::bool('otp_app_login', false)) {
            $issued = Otp::issue($user, Otp::PURPOSE_LOGIN);

            $this->ok([
                'otp_required' => true,
                'user_id' => (int) $user['id'],
                'message' => $issued['message'],
                'destination' => $issued['destination'],
                'expires_in' => (int) Config::get('security.otp_ttl_seconds', 300),
                'debug_code' => $issued['debug_code'],
            ]);

            return;
        }

        $this->issueSession($request, $user);
    }

    /**
     * POST /api/v1/auth/verify-otp
     */
    public function verifyOtp(Request $request): void
    {
        $data = $this->validateApi($request, [
            'user_id' => 'required|integer',
            'code' => 'required|max:12',
        ]);

        $userId = (int) $data['user_id'];

        if (RateLimiter::tooManyAttempts('api-otp:' . $userId, 10)) {
            $this->fail('Too many verification attempts. Request a new code shortly.', 429, 'rate_limited');

            return;
        }

        RateLimiter::hit('api-otp:' . $userId, 600);

        $check = Otp::verify($userId, (string) $data['code'], Otp::PURPOSE_LOGIN);

        if (!$check['ok']) {
            $this->fail($check['message'], 401, 'invalid_code');

            return;
        }

        $user = Auth::findById($userId);

        if ($user === null || (string) $user['status'] !== 'active' || (string) $user['role'] !== Auth::ROLE_BC) {
            $this->fail('That account can no longer sign in.', 403, 'forbidden');

            return;
        }

        $this->issueSession($request, $user);
    }

    /**
     * Bind the device and mint a token.
     *
     * @param array<string, mixed> $user
     */
    private function issueSession(Request $request, array $user): void
    {
        $device = $request->raw('device');
        $device = is_array($device) ? $device : [];

        $deviceUuid = trim((string) ($device['uuid'] ?? $request->header('X-Device-Id') ?? ''));

        if ($deviceUuid === '') {
            $this->fail('The app did not send a device identifier.', 422, 'device_required');

            return;
        }

        [$deviceRow, $error] = ApiAuth::registerDevice((int) $user['id'], $deviceUuid, [
            'model' => $device['model'] ?? '',
            'manufacturer' => $device['manufacturer'] ?? '',
            'os_version' => $device['os_version'] ?? '',
            'app_version' => $device['app_version'] ?? '',
            'fcm_token' => $device['fcm_token'] ?? null,
        ]);

        if ($deviceRow === null) {
            Audit::log(Audit::LOGIN_FAILED, [
                'user_id' => (int) $user['id'],
                'description' => 'App sign-in refused: ' . $error,
            ]);

            $this->fail($error, 403, 'device_not_allowed');

            return;
        }

        $token = ApiAuth::issue((int) $user['id'], (int) $deviceRow['id']);

        Database::update(
            'users',
            ['last_login_at' => now(), 'last_login_ip' => $request->ip()],
            'id = :id',
            ['id' => (int) $user['id']]
        );

        Auth::setUser($user);

        Audit::log(Audit::LOGIN, [
            'user_id' => (int) $user['id'],
            'entity_type' => 'user',
            'entity_id' => (int) $user['id'],
            'description' => sprintf('%s signed in from the Android app (%s).', $user['name'], $deviceRow['model'] ?: 'unknown device'),
        ]);

        if ((int) $deviceRow['bound_at'] !== 0 && $deviceRow['bound_at'] !== null) {
            Audit::log(Audit::DEVICE_BOUND, [
                'user_id' => (int) $user['id'],
                'entity_type' => 'device',
                'entity_id' => (int) $deviceRow['id'],
                'description' => sprintf('Device %s in use.', $deviceRow['device_uuid']),
            ]);
        }

        $supervisor = Database::selectOne(
            'SELECT s.*, b.name AS branch_name, b.code AS branch_code
               FROM bc_supervisors s JOIN branches b ON b.id = s.branch_id
              WHERE s.user_id = :uid',
            ['uid' => (int) $user['id']]
        );

        $this->ok([
            'otp_required' => false,
            'token' => $token['token'],
            'token_type' => 'Bearer',
            'expires_at' => $token['expires_at'],
            'user' => [
                'id' => (int) $user['id'],
                'name' => $user['name'],
                'username' => $user['username'],
                'mobile' => $user['mobile'],
                'role' => $user['role'],
                'must_change_password' => (int) $user['must_change_password'] === 1,
            ],
            'supervisor' => $supervisor === null ? null : [
                'id' => (int) $supervisor['id'],
                'bc_code' => $supervisor['bc_code'],
                'branch_id' => (int) $supervisor['branch_id'],
                'branch_name' => $supervisor['branch_name'],
                'branch_code' => $supervisor['branch_code'],
            ],
            'device' => [
                'id' => (int) $deviceRow['id'],
                'uuid' => $deviceRow['device_uuid'],
            ],
        ]);
    }

    /**
     * POST /api/v1/auth/logout
     */
    public function logout(Request $request): void
    {
        $tokenId = ApiAuth::tokenId();

        if ($tokenId !== null) {
            ApiAuth::revoke($tokenId);
        }

        Audit::log(Audit::LOGOUT, [
            'description' => 'Signed out of the Android app.',
        ]);

        $this->ok(['message' => 'Signed out.']);
    }

    /**
     * POST /api/v1/auth/change-password
     */
    public function changePassword(Request $request): void
    {
        $data = $this->validateApi($request, [
            'current_password' => 'required',
            'password' => 'required|password|confirmed',
        ]);

        $user = auth_user();

        if ($user === null) {
            $this->fail('Please sign in again.', 401, 'unauthenticated');

            return;
        }

        if (!password_verify((string) $data['current_password'], (string) $user['password'])) {
            $this->fail('Your current password is not correct.', 422, 'invalid_password');

            return;
        }

        Database::update('users', [
            'password' => Auth::hashPassword((string) $data['password']),
            'must_change_password' => 0,
            'password_changed_at' => now(),
            'updated_at' => now(),
        ], 'id = :id', ['id' => (int) $user['id']]);

        Audit::log(Audit::PASSWORD_CHANGED, [
            'user_id' => (int) $user['id'],
            'description' => 'Password changed from the Android app.',
        ]);

        $this->ok(['message' => 'Password updated.']);
    }

    /**
     * GET /api/v1/me — profile, today's position and the rules the app enforces.
     */
    public function me(Request $request): void
    {
        $user = auth_user() ?? [];
        $supervisor = $this->supervisor();
        $supervisorId = (int) $supervisor['id'];

        $counts = Deadline::dayCounts($supervisorId);

        $targets = Database::select(
            "SELECT period, visit_target, recovery_target, period_start, period_end
               FROM targets
              WHERE bc_supervisor_id = :bc AND scope = 'bc_supervisor'
                AND period_start <= :today AND period_end >= :today",
            ['bc' => $supervisorId, 'today' => today()]
        );

        $attendance = \App\Services\Attendance::today($supervisorId);

        $this->ok([
            'user' => [
                'id' => (int) $user['id'],
                'name' => $user['name'],
                'username' => $user['username'],
                'mobile' => $user['mobile'],
                'must_change_password' => (int) ($user['must_change_password'] ?? 0) === 1,
            ],
            'supervisor' => [
                'id' => $supervisorId,
                'bc_code' => $supervisor['bc_code'],
                'branch_id' => (int) $supervisor['branch_id'],
                'branch_name' => $supervisor['branch_name'],
                'branch_code' => $supervisor['branch_code'],
                'village' => $supervisor['village'],
            ],
            'today' => [
                'date' => today(),
                'visits' => $counts['visits'],
                'recovery' => $counts['recovery'],
                'promises' => $counts['promises'],
                'allocated_accounts' => (int) Database::scalar(
                    'SELECT COUNT(*) FROM account_assignments WHERE bc_supervisor_id = :bc AND is_active = 1',
                    ['bc' => $supervisorId]
                ),
                'pending_accounts' => (int) Database::scalar(
                    "SELECT COUNT(*) FROM loan_accounts a
                       JOIN account_assignments x ON x.loan_account_id = a.id AND x.is_active = 1
                      WHERE x.bc_supervisor_id = :bc AND a.status = 'active' AND a.visit_count = 0",
                    ['bc' => $supervisorId]
                ),
            ],
            'attendance' => $attendance === null ? null : [
                'date' => $attendance['attendance_date'],
                'check_in_at' => $attendance['check_in_at'],
                'check_out_at' => $attendance['check_out_at'],
                'working_minutes' => (int) $attendance['working_minutes'],
                'status' => $attendance['status'],
            ],
            'targets' => array_map(static fn (array $target): array => [
                'period' => $target['period'],
                'visit_target' => (int) $target['visit_target'],
                'recovery_target' => (float) $target['recovery_target'],
                'period_start' => $target['period_start'],
                'period_end' => $target['period_end'],
            ], $targets),
            'deadline' => Deadline::status(),
            'unread_notifications' => \App\Services\Notify::unreadCount($user),
        ]);
    }
}
