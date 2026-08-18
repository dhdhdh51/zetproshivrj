<?php

declare(strict_types=1);

/**
 * Web routes.
 *
 * @var App\Core\Router $router
 */

use App\Controllers\AuthController;
use App\Controllers\BillingController;
use App\Controllers\ClientController;
use App\Controllers\DashboardController;
use App\Controllers\DocumentController;
use App\Controllers\GoogleAuthController;
use App\Controllers\PageController;
use App\Controllers\ProfileController;
use App\Controllers\ShareController;

/* -------------------------------------------------------------------------- */
/* Public marketing pages                                                     */
/* -------------------------------------------------------------------------- */

$router->get('/', [PageController::class, 'landing']);
$router->get('/pricing', [BillingController::class, 'pricing']);
$router->get('/privacy', [PageController::class, 'privacy']);
$router->get('/terms', [PageController::class, 'terms']);
$router->get('/contact', [PageController::class, 'contact']);
$router->post('/contact', [PageController::class, 'sendContact']);

$router->get('/robots.txt', [PageController::class, 'robots']);
$router->get('/sitemap.xml', [PageController::class, 'sitemap']);
$router->get('/health', [PageController::class, 'health']);

/* -------------------------------------------------------------------------- */
/* Public document sharing + media                                            */
/* -------------------------------------------------------------------------- */

$router->get('/documents/share/{token}', [ShareController::class, 'show']);
$router->get('/documents/share/{token}/download', [ShareController::class, 'download']);
$router->get('/media/logo/{file}', [ProfileController::class, 'logo']);

/* -------------------------------------------------------------------------- */
/* Guest authentication                                                       */
/* -------------------------------------------------------------------------- */

$router->group(['middleware' => ['guest']], static function ($router): void {
    $router->get('/register', [AuthController::class, 'showRegister']);
    $router->post('/register', [AuthController::class, 'register']);

    $router->get('/login', [AuthController::class, 'showLogin']);
    $router->post('/login', [AuthController::class, 'login']);

    $router->get('/password/forgot', [AuthController::class, 'showForgot']);
    $router->post('/password/forgot', [AuthController::class, 'sendReset']);
    $router->get('/password/reset/{token}', [AuthController::class, 'showReset']);
    $router->post('/password/reset', [AuthController::class, 'resetPassword']);

    $router->get('/auth/google', [GoogleAuthController::class, 'start']);
    $router->get('/auth/google/callback', [GoogleAuthController::class, 'callback']);
});

$router->post('/logout', [AuthController::class, 'logout'], ['auth']);

/* Email verification (link works whether or not the user is signed in). */
$router->get('/email/verify/{token}', [AuthController::class, 'verify']);
$router->get('/email/verify', [AuthController::class, 'verifyNotice'], ['auth']);
$router->post('/email/verify/resend', [AuthController::class, 'resendVerification'], ['auth']);

/* -------------------------------------------------------------------------- */
/* Authenticated application                                                  */
/* -------------------------------------------------------------------------- */

$router->group(['middleware' => ['auth']], static function ($router): void {
    $router->get('/dashboard', [DashboardController::class, 'index']);

    /* Business profile & account ------------------------------------------ */
    $router->get('/profile/business', [ProfileController::class, 'business']);
    $router->post('/profile/business', [ProfileController::class, 'saveBusiness']);
    $router->post('/profile/logo/delete', [ProfileController::class, 'deleteLogo']);
    $router->get('/profile/account', [ProfileController::class, 'account']);
    $router->post('/profile/account', [ProfileController::class, 'updateAccount']);
    $router->post('/profile/password', [ProfileController::class, 'updatePassword']);

    /* Clients ------------------------------------------------------------- */
    $router->get('/clients', [ClientController::class, 'index']);
    $router->get('/clients/create', [ClientController::class, 'create']);
    $router->post('/clients', [ClientController::class, 'store']);
    $router->get('/clients/{id:[0-9]+}', [ClientController::class, 'show']);
    $router->get('/clients/{id:[0-9]+}/edit', [ClientController::class, 'edit']);
    $router->post('/clients/{id:[0-9]+}', [ClientController::class, 'update']);
    $router->post('/clients/{id:[0-9]+}/delete', [ClientController::class, 'destroy']);

    /* Documents ----------------------------------------------------------- */
    $router->get('/documents', [DocumentController::class, 'index']);
    $router->get('/documents/create', [DocumentController::class, 'create'], ['verified']);
    $router->post('/documents', [DocumentController::class, 'store'], ['verified']);
    $router->get('/documents/{id:[0-9]+}', [DocumentController::class, 'show']);
    $router->get('/documents/{id:[0-9]+}/edit', [DocumentController::class, 'edit']);
    $router->post('/documents/{id:[0-9]+}', [DocumentController::class, 'update']);
    $router->post('/documents/{id:[0-9]+}/duplicate', [DocumentController::class, 'duplicate']);
    $router->post('/documents/{id:[0-9]+}/delete', [DocumentController::class, 'destroy']);
    $router->post('/documents/{id:[0-9]+}/status', [DocumentController::class, 'status']);
    $router->get('/documents/{id:[0-9]+}/preview', [DocumentController::class, 'preview']);
    $router->post('/documents/{id:[0-9]+}/pdf', [DocumentController::class, 'generatePdf']);
    $router->get('/documents/{id:[0-9]+}/download', [DocumentController::class, 'download']);
    $router->post('/documents/{id:[0-9]+}/share', [DocumentController::class, 'enableShare']);
    $router->post('/documents/{id:[0-9]+}/unshare', [DocumentController::class, 'disableShare']);
    $router->get('/documents/{id:[0-9]+}/send', [DocumentController::class, 'sendForm']);
    $router->post('/documents/{id:[0-9]+}/send', [DocumentController::class, 'send']);

    /* Billing ------------------------------------------------------------- */
    $router->get('/billing', [BillingController::class, 'index']);
    $router->post('/billing/checkout', [BillingController::class, 'checkout']);
});

/* PayU posts back from its own domain — CSRF tokens cannot travel with it,
   so these two endpoints verify the PayU signature instead. */
$router->any('/billing/payu/success', [BillingController::class, 'payuSuccess'], ['nocsrf']);
$router->any('/billing/payu/failure', [BillingController::class, 'payuFailure'], ['nocsrf']);
