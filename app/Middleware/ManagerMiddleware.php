<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

/**
 * Branch Manager portal. A manager without a branch assignment is refused
 * outright — every query in the portal is scoped by that branch id.
 */
final class ManagerMiddleware
{
    public function handle(Request $request): void
    {
        if (!Auth::check()) {
            Session::put('_intended_url', $request->fullUrl());
            Session::flash('error', 'Please sign in to continue.');
            Response::redirect('/login');
            exit;
        }

        if (!Auth::isManager()) {
            throw new HttpException(403, 'The branch portal is limited to Branch Manager accounts.');
        }

        if (Auth::branchId() === null) {
            throw new HttpException(403, 'Your account is not linked to a branch yet. Contact your BC Supervisor.');
        }
    }
}
