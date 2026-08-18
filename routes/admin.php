<?php

declare(strict_types=1);

/**
 * Admin panel routes (all behind the `admin` middleware).
 *
 * @var App\Core\Router $router
 */

use App\Controllers\Admin\AdminDashboardController;
use App\Controllers\Admin\DocumentController as AdminDocumentController;
use App\Controllers\Admin\PaymentController as AdminPaymentController;
use App\Controllers\Admin\PlanController as AdminPlanController;
use App\Controllers\Admin\SettingsController;
use App\Controllers\Admin\UserController as AdminUserController;

$router->group(['prefix' => 'admin', 'middleware' => ['admin']], static function ($router): void {
    $router->get('/', [AdminDashboardController::class, 'index']);

    /* Users ---------------------------------------------------------------- */
    $router->get('/users', [AdminUserController::class, 'index']);
    $router->get('/users/{id:[0-9]+}', [AdminUserController::class, 'show']);
    $router->post('/users/{id:[0-9]+}/status', [AdminUserController::class, 'toggleStatus']);
    $router->post('/users/{id:[0-9]+}/role', [AdminUserController::class, 'updateRole']);
    $router->post('/users/{id:[0-9]+}/plan', [AdminUserController::class, 'assignPlan']);
    $router->post('/users/{id:[0-9]+}/plan/cancel', [AdminUserController::class, 'cancelSubscription']);

    /* Documents ------------------------------------------------------------ */
    $router->get('/documents', [AdminDocumentController::class, 'index']);
    $router->get('/documents/{id:[0-9]+}', [AdminDocumentController::class, 'show']);
    $router->get('/documents/{id:[0-9]+}/preview', [AdminDocumentController::class, 'preview']);
    $router->post('/documents/{id:[0-9]+}/delete', [AdminDocumentController::class, 'destroy']);

    /* AI settings ---------------------------------------------------------- */
    $router->get('/ai', [SettingsController::class, 'ai']);
    $router->post('/ai', [SettingsController::class, 'saveAi']);
    $router->post('/ai/test', [SettingsController::class, 'testAi']);

    /* Plans ---------------------------------------------------------------- */
    $router->get('/plans', [AdminPlanController::class, 'index']);
    $router->post('/plans', [AdminPlanController::class, 'store']);
    $router->post('/plans/{id:[0-9]+}', [AdminPlanController::class, 'update']);
    $router->post('/plans/{id:[0-9]+}/toggle', [AdminPlanController::class, 'toggle']);

    /* Payments ------------------------------------------------------------- */
    $router->get('/payments', [AdminPaymentController::class, 'index']);
    $router->get('/payments/{id:[0-9]+}', [AdminPaymentController::class, 'show']);
    $router->post('/payments/{id:[0-9]+}/verify', [AdminPaymentController::class, 'verify']);

    /* Email settings ------------------------------------------------------- */
    $router->get('/email', [SettingsController::class, 'email']);
    $router->post('/email', [SettingsController::class, 'saveEmail']);
    $router->post('/email/test', [SettingsController::class, 'testEmail']);

    /* PayU settings -------------------------------------------------------- */
    $router->get('/payu', [SettingsController::class, 'payu']);
    $router->post('/payu', [SettingsController::class, 'savePayu']);

    /* Templates ------------------------------------------------------------ */
    $router->get('/templates', [SettingsController::class, 'templates']);
    $router->post('/templates/{id:[0-9]+}/toggle', [SettingsController::class, 'toggleTemplate']);
    $router->post('/templates/{id:[0-9]+}/default', [SettingsController::class, 'defaultTemplate']);

    /* System settings ------------------------------------------------------ */
    $router->get('/settings', [SettingsController::class, 'system']);
    $router->post('/settings', [SettingsController::class, 'saveSystem']);
    $router->post('/settings/logo/delete', [SettingsController::class, 'deleteSiteLogo']);
});
