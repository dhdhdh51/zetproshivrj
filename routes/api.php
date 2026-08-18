<?php

declare(strict_types=1);

/**
 * JSON endpoints used by the dashboard UI (same-origin, session authenticated,
 * CSRF protected through the X-CSRF-Token header).
 *
 * @var App\Core\Router $router
 */

use App\Controllers\AiController;
use App\Controllers\ClientController;

$router->group(['prefix' => 'api', 'middleware' => ['auth']], static function ($router): void {
    /* AI ------------------------------------------------------------------ */
    $router->post('/ai/document', [AiController::class, 'generateDocument'], ['verified']);
    $router->post('/ai/write', [AiController::class, 'write'], ['verified']);
    $router->post('/ai/email', [AiController::class, 'clientEmail'], ['verified']);
    $router->post('/ai/terms', [AiController::class, 'terms'], ['verified']);

    /* Server-side money maths -------------------------------------------- */
    $router->post('/documents/calculate', [AiController::class, 'calculate']);

    /* Clients ------------------------------------------------------------- */
    $router->get('/clients', [ClientController::class, 'search']);
    $router->post('/clients', [ClientController::class, 'quickStore']);
});
