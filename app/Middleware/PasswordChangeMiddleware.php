<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

/**
 * Accounts created by an Admin/Supervisor start with a temporary password.
 * Until it is changed, every page redirects to the change-password screen.
 */
final class PasswordChangeMiddleware
{
    public function handle(Request $request): void
    {
        if (!Auth::check() || !Auth::mustChangePassword()) {
            return;
        }

        if (str_starts_with($request->path(), '/password')) {
            return;
        }

        Session::flash('warning', 'Please set a new password before continuing.');
        Response::redirect('/password/change');
        exit;
    }
}
