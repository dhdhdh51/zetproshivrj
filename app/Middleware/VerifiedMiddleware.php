<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;

/**
 * Blocks unverified accounts when "require email verification" is switched on
 * in Admin > System Settings.
 */
final class VerifiedMiddleware
{
    public function handle(Request $request): void
    {
        if (!Settings::bool('require_email_verification', false)) {
            return;
        }

        if (Auth::isVerified() || Auth::isAdmin()) {
            return;
        }

        if ($request->isAjax()) {
            Response::json(['success' => false, 'message' => 'Please verify your email address first.'], 403);
            exit;
        }

        Session::flash('error', 'Please verify your email address to continue.');
        Response::redirect('/email/verify');
        exit;
    }
}
