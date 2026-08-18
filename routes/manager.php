<?php

declare(strict_types=1);

/**
 * Branch Manager portal. Read and report access, strictly scoped to one branch.
 *
 * @var App\Core\Router $router
 */

use App\Controllers\Admin\AccountController;
use App\Controllers\Admin\ReportController;
use App\Controllers\Admin\SystemController;
use App\Controllers\Admin\VisitController;
use App\Controllers\Manager\PortalController;

$router->group(['prefix' => 'manager', 'middleware' => ['manager', 'password']], static function ($router): void {
    $router->get('/', [PortalController::class, 'dashboard']);

    // Accounts and visits reuse the admin controllers; branch isolation is
    // enforced inside them by Acl::branchScope() and assertBranch().
    $router->get('/accounts', [AccountController::class, 'index']);
    $router->get('/accounts/{id:\d+}', [AccountController::class, 'show']);

    $router->get('/visits', static function (): void {
        App\Core\Response::redirect('/manager/reports/customer_visit' . query_string());
    });
    $router->get('/visits/{id:\d+}', [VisitController::class, 'show']);
    $router->get('/visits/{id:\d+}/pdf', [VisitController::class, 'pdf']);

    $router->get('/supervisors', [PortalController::class, 'supervisors']);
    $router->get('/pending', [PortalController::class, 'pending']);
    $router->get('/recovery', [PortalController::class, 'recovery']);
    $router->get('/performance', [PortalController::class, 'performance']);

    $router->get('/monitoring', [SystemController::class, 'monitoring']);
    $router->get('/monitoring/route/{id:\d+}', [SystemController::class, 'route']);

    $router->get('/reports', [ReportController::class, 'index']);
    $router->get('/reports/{slug:[a-z0-9_]+}', [ReportController::class, 'show']);
    $router->get('/reports/{slug:[a-z0-9_]+}/export/{format:[a-z]+}', [ReportController::class, 'export']);

    $router->get('/notifications', [PortalController::class, 'notifications']);
    $router->post('/notifications/{id:\d+}/read', [PortalController::class, 'readNotification']);
    $router->post('/notifications/read-all', [PortalController::class, 'readAllNotifications']);
});
