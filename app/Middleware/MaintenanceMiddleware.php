<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Core\View;

final class MaintenanceMiddleware
{
    public function handle(Request $request): void
    {
        if (!Settings::bool('maintenance_mode', false) || Auth::isAdmin()) {
            return;
        }

        Response::html(View::render('errors.maintenance', [], 'layouts.error'), 503);
        exit;
    }
}
