<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

final class AdminMiddleware
{
    public function handle(Request $request): void
    {
        if (!Auth::check()) {
            Session::put('_intended_url', $request->fullUrl());
            Session::flash('error', 'Please sign in with an administrator account.');
            Response::redirect('/login');
            exit;
        }

        if (!Auth::isAdmin()) {
            throw new HttpException(403, 'You do not have permission to access the admin panel.');
        }
    }
}
