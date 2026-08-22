<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\ApiAuth;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;

/**
 * Base class for the Android API.
 *
 * Every response uses the same envelope so the app can handle success and
 * failure uniformly:
 *
 *   { "success": true,  "data": { ... }, "server_time": "..." }
 *   { "success": false, "message": "...", "code": "...", "errors": { ... } }
 */
abstract class ApiController extends Controller
{
    /**
     * @param array<string, mixed> $data
     */
    protected function ok(array $data = [], int $status = 200): void
    {
        $this->json([
            'success' => true,
            'data' => $data,
            'server_time' => now(),
        ], $status);
    }

    /**
     * @param array<string, string> $errors
     */
    protected function fail(string $message, int $status = 422, string $code = 'error', array $errors = []): void
    {
        $payload = [
            'success' => false,
            'message' => $message,
            'code' => $code,
        ];

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        $this->json($payload, $status);
    }

    /**
     * Validate JSON input, returning a 422 envelope instead of redirecting.
     *
     * @param array<string, string> $rules
     * @return array<string, mixed>
     */
    protected function validateApi(Request $request, array $rules): array
    {
        $validator = \App\Core\Validator::make($request->all(), $rules);

        if ($validator->passes()) {
            return $validator->validated();
        }

        $this->fail(
            $validator->firstError() ?? 'Some of the information supplied was not valid.',
            422,
            'validation_failed',
            array_map(
                static fn ($messages): string => is_array($messages) ? (string) reset($messages) : (string) $messages,
                $validator->errors()
            )
        );

        exit;
    }

    /**
     * The BCA profile of the authenticated user.
     *
     * The API is only meaningful for field staff, so anything else is refused
     * here rather than in every action.
     *
     * @return array<string, mixed>
     */
    protected function supervisor(): array
    {
        $user = auth_user();

        if ($user === null) {
            throw new HttpException(401, 'Please sign in again.');
        }

        if ((string) $user['role'] !== Auth::ROLE_BC) {
            throw new HttpException(
                403,
                'The mobile API is for BCA accounts. BC Supervisor and Branch Manager users work in the web portal.'
            );
        }

        $supervisor = Database::selectOne(
            'SELECT s.*, b.name AS branch_name, b.code AS branch_code
               FROM bc_supervisors s
               JOIN branches b ON b.id = s.branch_id
              WHERE s.user_id = :uid
              LIMIT 1',
            ['uid' => (int) $user['id']]
        );

        if ($supervisor === null) {
            throw new HttpException(403, 'Your account is not linked to a BCA profile. Contact your BC Supervisor.');
        }

        if ((string) $supervisor['status'] !== 'active') {
            throw new HttpException(403, 'Your BCA profile is not active. Contact your BC Supervisor.');
        }

        return $supervisor;
    }

    protected function supervisorId(): int
    {
        return (int) $this->supervisor()['id'];
    }

    protected function deviceId(): ?int
    {
        return ApiAuth::deviceId();
    }

    /**
     * Record the device's last known position from any request that carries one.
     *
     * @param array<string, mixed> $gps
     */
    protected function touchLocation(array $gps): void
    {
        $deviceId = $this->deviceId();

        if ($deviceId === null || ($gps['latitude'] ?? '') === '' || ($gps['longitude'] ?? '') === '') {
            return;
        }

        Database::update('devices', [
            'last_latitude' => round((float) $gps['latitude'], 7),
            'last_longitude' => round((float) $gps['longitude'], 7),
            'last_location_at' => now(),
            'last_address' => isset($gps['address']) ? mb_substr((string) $gps['address'], 0, 255) : null,
            'updated_at' => now(),
        ], 'id = :id', ['id' => $deviceId]);
    }

    protected function page(Request $request): int
    {
        return max(1, (int) $request->input('page', 1));
    }

    protected function perPage(Request $request, int $default = 50, int $max = 200): int
    {
        return max(1, min($max, (int) $request->input('per_page', $default)));
    }
}
