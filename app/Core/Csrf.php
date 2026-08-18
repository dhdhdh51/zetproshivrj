<?php

declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    public static function token(): string
    {
        if (!Session::has('_csrf_token')) {
            Session::put('_csrf_token', bin2hex(random_bytes(32)));
        }

        return (string) Session::get('_csrf_token');
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(self::token()) . '">';
    }

    public static function check(?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        return hash_equals(self::token(), $token);
    }

    /**
     * Validate the token from the current request (form field or X-CSRF-Token header).
     */
    public static function verifyRequest(Request $request): void
    {
        $token = $request->input('_token');

        if (!is_string($token) || $token === '') {
            $token = $request->header('X-CSRF-Token');
        }

        if (!self::check(is_string($token) ? $token : null)) {
            throw new HttpException(419, 'Your session has expired. Please refresh the page and try again.');
        }
    }
}
