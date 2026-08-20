<?php

declare(strict_types=1);

/**
 * Admin / Supervisor panel.
 *
 * Admin and Supervisor are the same role; there is deliberately no separate
 * super-admin. Every route here requires that role plus a changed password.
 *
 * @var App\Core\Router $router
 */

use App\Controllers\Admin\AccountController;
use App\Controllers\Admin\BranchController;
use App\Controllers\Admin\DashboardController;
use App\Controllers\Admin\DeadlineController;
use App\Controllers\Admin\FormBuilderController;
use App\Controllers\Admin\ImportController;
use App\Controllers\Admin\InspectionController;
use App\Controllers\Admin\SssController;
use App\Controllers\Admin\ReportController;
use App\Controllers\Admin\StaffController;
use App\Controllers\Admin\SystemController;
use App\Controllers\Admin\TargetController;
use App\Controllers\Admin\VisitController;

$router->group(['prefix' => 'admin', 'middleware' => ['admin', 'password']], static function ($router): void {
    /* ------------------------------------------------------------ Dashboard */
    $router->get('/', [DashboardController::class, 'index']);

    $router->get('/notifications', [DashboardController::class, 'notifications']);
    $router->post('/notifications/{id:\d+}/read', [DashboardController::class, 'readNotification']);
    $router->post('/notifications/read-all', [DashboardController::class, 'readAllNotifications']);
    $router->post('/notifications/broadcast', [SystemController::class, 'broadcast']);

    /* --------------------------------------------------------------- Branches */
    $router->get('/branches', [BranchController::class, 'index']);
    $router->get('/branches/create', [BranchController::class, 'create']);
    $router->post('/branches', [BranchController::class, 'store']);
    $router->get('/branches/{id:\d+}/edit', [BranchController::class, 'edit']);
    $router->post('/branches/{id:\d+}', [BranchController::class, 'update']);
    $router->post('/branches/{id:\d+}/toggle', [BranchController::class, 'deactivate']);

    /* ------------------------------------------------------------------ Staff */
    $router->get('/managers', [StaffController::class, 'managers']);
    $router->get('/managers/create', [StaffController::class, 'createManager']);
    $router->post('/managers', [StaffController::class, 'storeManager']);
    $router->get('/managers/{id:\d+}/edit', [StaffController::class, 'editManager']);
    $router->post('/managers/{id:\d+}', [StaffController::class, 'updateManager']);

    $router->get('/supervisors', [StaffController::class, 'supervisors']);
    $router->get('/supervisors/create', [StaffController::class, 'createSupervisor']);
    $router->post('/supervisors', [StaffController::class, 'storeSupervisor']);
    $router->get('/supervisors/{id:\d+}/edit', [StaffController::class, 'editSupervisor']);
    $router->post('/supervisors/{id:\d+}', [StaffController::class, 'updateSupervisor']);

    $router->post('/users/{id:\d+}/reset-password', [StaffController::class, 'resetPassword']);
    $router->post('/users/{id:\d+}/unlock', [StaffController::class, 'unlockUser']);
    $router->post('/devices/{id:\d+}/reset', [StaffController::class, 'resetDevice']);
    $router->post('/devices/{id:\d+}/block', [StaffController::class, 'blockDevice']);

    /* --------------------------------------------------------------- Accounts */
    $router->get('/accounts', [AccountController::class, 'index']);
    // Before the {id} route: a literal path must not be read as an account id.
    $router->get('/accounts/create', [AccountController::class, 'create']);
    $router->post('/accounts', [AccountController::class, 'store']);
    $router->get('/accounts/{id:\d+}', [AccountController::class, 'show']);
    $router->post('/accounts/{id:\d+}', [AccountController::class, 'update']);
    $router->post('/accounts/{id:\d+}/reassign', [AccountController::class, 'reassign']);
    $router->post('/accounts/{id:\d+}/unassign', [AccountController::class, 'unassign']);
    $router->post('/accounts/{id:\d+}/krm-ots', [AccountController::class, 'saveKrmOts']);
    $router->post('/accounts/{id:\d+}/ckcc', [AccountController::class, 'saveCkcc']);
    $router->post('/accounts/bulk-reassign', [AccountController::class, 'bulkReassign']);

    $router->get('/allocation', [AccountController::class, 'allocation']);
    $router->post('/allocation/balance', [AccountController::class, 'balance']);

    /* ---------------------------------------------------------- Excel imports */
    $router->get('/imports', [ImportController::class, 'index']);
    $router->get('/imports/create', [ImportController::class, 'create']);
    // The demo sheet, so nobody has to guess the column names.
    $router->get('/imports/sample', [ImportController::class, 'sample']);
    $router->post('/imports', [ImportController::class, 'store']);
    $router->get('/imports/{id:\d+}', [ImportController::class, 'show']);
    $router->get('/imports/{id:\d+}/mapping', [ImportController::class, 'mapping']);
    $router->post('/imports/{id:\d+}/mapping', [ImportController::class, 'saveMapping']);
    $router->post('/imports/{id:\d+}/redetect', [ImportController::class, 'redetect']);
    $router->post('/imports/{id:\d+}/apply-template', [ImportController::class, 'applyTemplate']);
    $router->post('/imports/{id:\d+}/save-template', [ImportController::class, 'saveTemplate']);
    $router->get('/imports/{id:\d+}/preview', [ImportController::class, 'preview']);
    $router->post('/imports/{id:\d+}/run', [ImportController::class, 'run']);
    $router->post('/imports/{id:\d+}/cancel', [ImportController::class, 'cancel']);
    $router->post('/mapping-templates/{id:\d+}/delete', [ImportController::class, 'deleteTemplate']);

    /* ------------------------------------------------- Customer visits (TYPE A) */
    $router->get('/visits', static function (): void {
        App\Core\Response::redirect('/admin/reports/customer_visit' . query_string());
    });
    $router->get('/visits/{id:\d+}', [VisitController::class, 'show']);
    $router->get('/visits/{id:\d+}/pdf', [VisitController::class, 'pdf']);
    $router->post('/visits/{id:\d+}/approve', [VisitController::class, 'approve']);
    $router->post('/visits/{id:\d+}/reject', [VisitController::class, 'reject']);

    /* ---------------------------------------- Social Security Scheme enrolments */
    $router->get('/sss', [SssController::class, 'index']);
    $router->get('/sss/create', [SssController::class, 'create']);
    $router->post('/sss', [SssController::class, 'store']);
    $router->get('/sss/{id:\d+}/edit', [SssController::class, 'edit']);
    $router->post('/sss/{id:\d+}', [SssController::class, 'update']);

    /* ------------------------------------------ BC Supervisor inspections (TYPE B) */
    $router->get('/inspections', [InspectionController::class, 'index']);
    $router->get('/inspections/create', [InspectionController::class, 'create']);
    $router->post('/inspections', [InspectionController::class, 'store']);
    $router->get('/inspections/register', [InspectionController::class, 'register']);
    $router->get('/inspections/supervisor/{id:\d+}', [InspectionController::class, 'supervisor']);
    $router->get('/inspections/{id:\d+}', [InspectionController::class, 'show']);
    $router->get('/inspections/{id:\d+}/edit', [InspectionController::class, 'edit']);
    $router->get('/inspections/{id:\d+}/pdf', [InspectionController::class, 'pdf']);
    $router->post('/inspections/{id:\d+}/photos', [InspectionController::class, 'uploadPhoto']);
    $router->post('/inspections/{id:\d+}/submit', [InspectionController::class, 'submit']);
    $router->post('/inspections/{id:\d+}/delete', [InspectionController::class, 'destroy']);

    /* --------------------------------------------------------- Work streams */
    $router->get('/krm-ots', static function (): void {
        App\Core\Response::redirect('/admin/reports/krm_ots' . query_string());
    });
    $router->get('/ckcc', static function (): void {
        App\Core\Response::redirect('/admin/reports/ckcc_od2' . query_string());
    });

    /* ------------------------------------------------------------- Targets */
    $router->get('/targets', [TargetController::class, 'index']);
    $router->post('/targets', [TargetController::class, 'store']);
    $router->post('/targets/{id:\d+}/delete', [TargetController::class, 'destroy']);

    /* ------------------------------------------------------------ Deadline */
    $router->get('/deadline', [DeadlineController::class, 'index']);
    $router->post('/deadline', [DeadlineController::class, 'save']);
    $router->get('/deadline/late', [DeadlineController::class, 'late']);
    $router->post('/deadline/{id:\d+}/decide', [DeadlineController::class, 'decide']);
    $router->post('/deadline/lock', [DeadlineController::class, 'lock']);

    /* ------------------------------------------------------------- Reports */
    $router->get('/reports', [ReportController::class, 'index']);
    $router->get('/reports/{slug:[a-z0-9_]+}', [ReportController::class, 'show']);
    $router->get('/reports/{slug:[a-z0-9_]+}/export/{format:[a-z]+}', [ReportController::class, 'export']);

    /* -------------------------------------------------------- Form builders */
    $router->get('/forms/{kind:visit|inspection}', [FormBuilderController::class, 'index']);
    $router->post('/forms/{kind:visit|inspection}', [FormBuilderController::class, 'storeForm']);
    $router->post('/forms/{kind:visit|inspection}/{id:\d+}', [FormBuilderController::class, 'updateForm']);
    $router->post('/forms/{kind:visit|inspection}/{id:\d+}/duplicate', [FormBuilderController::class, 'duplicateForm']);
    $router->post('/forms/{kind:visit|inspection}/{id:\d+}/default', [FormBuilderController::class, 'setDefault']);
    $router->post('/forms/{kind:visit|inspection}/{id:\d+}/fields', [FormBuilderController::class, 'saveField']);
    $router->post('/forms/{kind:visit|inspection}/{id:\d+}/fields/{field:\d+}/delete', [FormBuilderController::class, 'deleteField']);
    $router->post('/forms/{kind:visit|inspection}/{id:\d+}/reorder', [FormBuilderController::class, 'reorder']);

    /* -------------------------------------------------- Monitoring & system */
    $router->get('/monitoring', [SystemController::class, 'monitoring']);
    $router->get('/monitoring/route/{id:\d+}', [SystemController::class, 'route']);
    $router->get('/audit', [SystemController::class, 'audit']);
    $router->get('/settings', [SystemController::class, 'settings']);
    $router->post('/settings', [SystemController::class, 'saveSettings']);
    $router->get('/settings/upgrade', [SystemController::class, 'upgrade']);
    $router->post('/settings/upgrade', [SystemController::class, 'runUpgrade']);
});
