<?php

declare(strict_types=1);

/**
 * Public and shared routes.
 *
 * @var App\Core\Router $router
 */

use App\Controllers\AuthController;
use App\Controllers\FileController;
use App\Core\Auth;
use App\Core\Response;

/* ----------------------------------------------------------------- Entry -- */

$router->get('/', static function (): void {
    Response::redirect(Auth::check() ? Auth::homeFor() : '/login');
});

$router->get('/health', [AuthController::class, 'health']);

/* ------------------------------------------------------------------ Auth -- */

$router->get('/login', [AuthController::class, 'showLogin'], ['guest']);
$router->post('/login', [AuthController::class, 'login'], ['guest']);
$router->get('/login/verify', [AuthController::class, 'showVerify'], ['guest']);
$router->post('/login/verify', [AuthController::class, 'verify'], ['guest']);
$router->post('/login/resend', [AuthController::class, 'resend'], ['guest']);
$router->get('/app-only', [AuthController::class, 'appOnly']);
$router->post('/logout', [AuthController::class, 'logout']);

$router->get('/password/change', [AuthController::class, 'showChangePassword'], ['auth']);
$router->post('/password/change', [AuthController::class, 'changePassword'], ['auth']);

/* ------------------------------------------------- Authorised file access -- */

// Photographs, signatures and generated exports live outside the web root and
// are streamed only to users who are allowed to see that record.
$router->get('/files/visit-photo/{id:\d+}', [FileController::class, 'visitPhoto'], ['auth']);
$router->get('/files/inspection-photo/{id:\d+}', [FileController::class, 'inspectionPhoto'], ['auth']);
$router->get('/files/signature/{type:[a-z_]+}/{id:\d+}/{which:[a-z_]+}', [FileController::class, 'signature'], ['auth']);
$router->get('/files/attendance-selfie/{id:\d+}', [FileController::class, 'attendanceSelfie'], ['auth']);
$router->get('/files/export/{id:\d+}', [FileController::class, 'export'], ['auth']);
