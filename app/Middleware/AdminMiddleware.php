<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

/**
 * BC Supervisor only. There is deliberately no separate "super admin"
 * role in LRMS: Admin and Supervisor are the same role.
 */
final class AdminMiddleware
{
    public function handle(Request $request): void
    {
        if (!Auth::check()) {
            Session::put('_intended_url', $request->fullUrl());
            Session::flash('error', 'Please sign in to continue.');
            Response::redirect('/login');
            exit;
        }

        if (!Auth::isAdmin()) {
            throw new HttpException(403, 'The admin panel is limited to BC Supervisor accounts.');
        }
    }
}
