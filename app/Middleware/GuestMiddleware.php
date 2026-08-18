<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;

final class GuestMiddleware
{
    public function handle(Request $request): void
    {
        if (!Auth::check()) {
            return;
        }

        Response::redirect(Auth::isAdmin() ? '/admin' : '/dashboard');
        exit;
    }
}
