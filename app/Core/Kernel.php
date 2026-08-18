<?php

declare(strict_types=1);

namespace App\Core;

use ErrorException;
use Throwable;

final class Kernel
{
    public static function registerErrorHandlers(): void
    {
        set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }

            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(static function (Throwable $e): void {
            self::renderThrowable($e);
        });

        register_shutdown_function(static function (): void {
            $error = error_get_last();

            if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }

            Logger::error(sprintf('Fatal: %s in %s:%d', $error['message'], $error['file'], $error['line']));

            if (!headers_sent()) {
                http_response_code(500);
            }
        });
    }

    public static function handle(): void
    {
        $request = Request::capture();

        try {
            self::enforceHttps($request);
            self::securityHeaders($request);
            self::guardMaintenance($request);

            $router = new Router();
            self::loadRoutes($router);
            $router->dispatch($request);

            if ($request->method() === 'GET' && !self::isApi($request)) {
                Session::clearOld();
                Session::clearErrors();
            }
        } catch (Throwable $e) {
            self::renderThrowable($e, $request);
        }
    }

    private static function loadRoutes(Router $router): void
    {
        foreach (['web', 'admin', 'manager', 'api'] as $file) {
            $path = base_path('routes/' . $file . '.php');

            if (is_file($path)) {
                (static function (Router $router) use ($path): void {
                    require $path;
                })($router);
            }
        }
    }

    public static function isApi(?Request $request = null): bool
    {
        $request ??= Request::capture();

        return str_starts_with($request->path(), '/api/') || $request->path() === '/api';
    }

    /**
     * Customer financial data must never travel over plain HTTP in production.
     */
    private static function enforceHttps(Request $request): void
    {
        if (PHP_SAPI === 'cli' || !(bool) Config::get('app.force_https', true)) {
            return;
        }

        if ($request->isSecure()) {
            return;
        }

        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');

        // Never redirect local development hosts into https.
        if ($host === '' || preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/', $host) === 1) {
            return;
        }

        Response::redirect('https://' . $host . ($_SERVER['REQUEST_URI'] ?? '/'), 301);
        exit;
    }

    private static function securityHeaders(Request $request): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Permissions-Policy: geolocation=(self), camera=(self), microphone=()');

        if ($request->isSecure() && (bool) Config::get('app.force_https', true)) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }

        if (!self::isApi($request)) {
            // Inline styles/scripts are used by the dashboard views; no external
            // origins are permitted, which blocks the common XSS payloads.
            header(
                "Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; "
                . "style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; "
                . "connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'"
            );
        }
    }

    private static function guardMaintenance(Request $request): void
    {
        if (!Settings::bool('maintenance_mode', false)) {
            return;
        }

        $path = $request->path();
        $allowed = ['/login', '/logout', '/admin', '/health'];

        foreach ($allowed as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return;
            }
        }

        if (Auth::isAdmin()) {
            return;
        }

        if (self::isApi($request)) {
            Response::json([
                'success' => false,
                'message' => 'LRMS is temporarily under maintenance. Your data is saved on the device and will sync later.',
                'code' => 'maintenance',
            ], 503);
            exit;
        }

        Response::html(View::render('errors.maintenance', [
            'title' => 'Under maintenance',
        ], 'layouts.error'), 503);

        exit;
    }

    public static function renderThrowable(Throwable $e, ?Request $request = null): void
    {
        $status = $e instanceof HttpException ? $e->getStatusCode() : 500;
        $message = $e->getMessage();

        if (PHP_SAPI === 'cli') {
            Logger::error(sprintf('%s: %s in %s:%d', $e::class, $message, $e->getFile(), $e->getLine()));
            fwrite(STDERR, sprintf("\n%s: %s\n  at %s:%d\n", $e::class, $message, $e->getFile(), $e->getLine()));

            return;
        }

        $request ??= Request::capture();

        if ($status >= 500) {
            Logger::error(sprintf('%s: %s in %s:%d', $e::class, $message, $e->getFile(), $e->getLine()), [
                'path' => $request->path(),
                'user' => Auth::id(),
                'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 8),
            ]);
        }

        $debug = (bool) Config::get('app.debug', false);

        if ($request->isAjax() || self::isApi($request)) {
            Response::json([
                'success' => false,
                'message' => $status >= 500 && !$debug
                    ? 'Something went wrong on the server. Please try again.'
                    : ($message !== '' ? $message : 'Request failed.'),
                'code' => self::codeFor($status),
            ], $status);

            return;
        }

        $view = 'errors.' . $status;

        if (!View::exists($view)) {
            $view = $status < 500 ? 'errors.404' : 'errors.500';
        }

        try {
            Response::html(View::render($view, [
                'message' => $message,
                'exception' => $debug ? $e : null,
            ], 'layouts.error'), $status);
        } catch (Throwable $inner) {
            if (!headers_sent()) {
                http_response_code($status);
                header('Content-Type: text/plain; charset=UTF-8');
            }
            echo 'Error ' . $status . ': ' . ($debug ? $message . ' / ' . $inner->getMessage() : 'Something went wrong.');
        }
    }

    private static function codeFor(int $status): string
    {
        return match ($status) {
            401 => 'unauthenticated',
            403 => 'forbidden',
            404 => 'not_found',
            419 => 'session_expired',
            422 => 'validation_failed',
            423 => 'locked',
            429 => 'rate_limited',
            503 => 'unavailable',
            default => $status >= 500 ? 'server_error' : 'bad_request',
        };
    }
}
