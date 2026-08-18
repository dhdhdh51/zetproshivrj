<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

final class AuthMiddleware
{
    public function handle(Request $request): void
    {
        if (Auth::check()) {
            return;
        }

        if ($request->isAjax()) {
            Response::json(['success' => false, 'message' => 'Please sign in to continue.'], 401);
            exit;
        }

        Session::put('_intended_url', $request->fullUrl());
        Session::flash('error', 'Please sign in to continue.');
        Response::redirect('/login');
        exit;
    }
}
