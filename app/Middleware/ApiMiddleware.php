<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\ApiAuth;
use App\Core\Config;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;

/**
 * Bearer-token gate for every authenticated API route used by the Android app.
 */
final class ApiMiddleware
{
    public function handle(Request $request): void
    {
        $token = ApiAuth::authenticate($request);

        if ($token === null) {
            Response::json([
                'success' => false,
                'message' => 'Your session has expired. Please sign in again.',
                'code' => 'unauthenticated',
            ], 401);
            exit;
        }

        $this->throttle($request, (int) $token['user_id']);
    }

    private function throttle(Request $request, int $userId): void
    {
        $max = (int) Config::get('security.api_rate_per_minute', 90);

        if ($max <= 0) {
            return;
        }

        $key = 'api:' . $userId . ':' . date('YmdHi');

        if (\App\Core\RateLimiter::tooManyAttempts($key, $max)) {
            throw new HttpException(429, 'Too many requests. Please wait a moment and retry.');
        }

        \App\Core\RateLimiter::hit($key, 60);
    }
}
