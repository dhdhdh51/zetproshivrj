<?php

declare(strict_types=1);

/**
 * Android API (v1).
 *
 * Token authenticated and stateless: no cookies are issued, so CSRF does not
 * apply (the router skips it for the `api` middleware). Every route below is for
 * BCA field work.
 *
 * @var App\Core\Router $router
 */

use App\Controllers\Api\AuthController;
use App\Controllers\Api\FieldController;
use App\Controllers\Api\SyncController;
use App\Core\Response;
use App\Core\Settings;

$router->group(['prefix' => 'api/v1'], static function ($router): void {
    /* ----------------------------------------------------------- Public -- */

    // Lets the app check connectivity, the server clock and its own version
    // support before asking a supervisor to sign in.
    $router->get('/ping', static function (): void {
        Response::json([
            'success' => true,
            'data' => [
                'app' => app_name(),
                'api_version' => 'v1',
                'server_time' => now(),
                'timezone' => date_default_timezone_get(),
                'maintenance' => Settings::bool('maintenance_mode', false),
                'otp_required_for_login' => Settings::bool('otp_app_login', false),
                'device_binding' => Settings::bool('device_binding', true),
            ],
            'server_time' => now(),
        ]);
    });

    $router->post('/auth/login', [AuthController::class, 'login']);
    $router->post('/auth/verify-otp', [AuthController::class, 'verifyOtp']);

    /* ---------------------------------------------------- Authenticated -- */

    $router->group(['middleware' => ['api']], static function ($router): void {
        $router->post('/auth/logout', [AuthController::class, 'logout']);
        $router->post('/auth/change-password', [AuthController::class, 'changePassword']);
        $router->get('/me', [AuthController::class, 'me']);

        /* Offline synchronisation */
        $router->get('/sync/pull', [SyncController::class, 'pull']);
        $router->post('/sync/push', [SyncController::class, 'push']);
        $router->post('/sync/location', [SyncController::class, 'location']);

        /* Accounts */
        $router->get('/accounts', [FieldController::class, 'accounts']);
        $router->get('/accounts/{id:\d+}', [FieldController::class, 'account']);

        /* Visits (TYPE A) */
        $router->get('/visit-form', [FieldController::class, 'visitForm']);
        $router->get('/visits', [FieldController::class, 'visits']);
        $router->post('/visits', [FieldController::class, 'startVisit']);
        $router->post('/visits/{uuid:[0-9a-fA-F-]+}/photos', [FieldController::class, 'uploadVisitPhoto']);
        $router->post('/visits/{uuid:[0-9a-fA-F-]+}/submit', [FieldController::class, 'submitVisit']);

        /* Money and follow-up */
        $router->post('/recoveries', [FieldController::class, 'recovery']);
        $router->post('/promises', [FieldController::class, 'promise']);
        $router->get('/followups', [FieldController::class, 'followups']);
        $router->post('/followups', [FieldController::class, 'followup']);

        /* Work streams */
        $router->post('/krm-ots', [FieldController::class, 'krmOts']);
        $router->post('/ckcc', [FieldController::class, 'ckcc']);

        /* Attendance */
        $router->get('/attendance', [FieldController::class, 'attendanceToday']);
        $router->post('/attendance/check-in', [FieldController::class, 'checkIn']);
        $router->post('/attendance/check-out', [FieldController::class, 'checkOut']);

        /* Social Security Scheme enrolments */
        $router->get('/sss', [FieldController::class, 'sss']);
        $router->post('/sss', [FieldController::class, 'recordSss']);

        /* Deadline and the daily report */
        $router->get('/deadline', [FieldController::class, 'deadline']);
        $router->post('/reports/daily', [FieldController::class, 'submitDailyReport']);

        /* Notifications */
        $router->get('/notifications', [FieldController::class, 'notifications']);
        $router->post('/notifications/{id:\d+}/read', [FieldController::class, 'readNotification']);
        $router->post('/notifications/read-all', [FieldController::class, 'readAllNotifications']);
    });
});
