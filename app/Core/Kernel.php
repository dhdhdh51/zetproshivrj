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
            self::guardMaintenance($request);

            $router = new Router();
            self::loadRoutes($router);
            $router->dispatch($request);

            if ($request->method() === 'GET') {
                Session::clearOld();
                Session::clearErrors();
            }
        } catch (Throwable $e) {
            self::renderThrowable($e, $request);
        }
    }

    private static function loadRoutes(Router $router): void
    {
        foreach (['web', 'api', 'admin'] as $file) {
            $path = base_path('routes/' . $file . '.php');

            if (is_file($path)) {
                (static function (Router $router) use ($path): void {
                    require $path;
                })($router);
            }
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

        Response::html(View::render('errors.maintenance', [
            'title' => 'We will be right back',
        ], 'layouts.error'), 503);

        exit;
    }

    public static function renderThrowable(Throwable $e, ?Request $request = null): void
    {
        $status = $e instanceof HttpException ? $e->getStatusCode() : 500;
        $message = $e->getMessage();

        // CLI scripts (installer, tests, cron) get a plain-text report.
        if (PHP_SAPI === 'cli') {
            Logger::error(sprintf('%s: %s in %s:%d', $e::class, $message, $e->getFile(), $e->getLine()));
            fwrite(STDERR, sprintf(
                "\n%s: %s\n  at %s:%d\n",
                $e::class,
                $message,
                $e->getFile(),
                $e->getLine()
            ));

            return;
        }

        $request ??= Request::capture();

        if ($status >= 500) {
            Logger::error(sprintf('%s: %s in %s:%d', $e::class, $message, $e->getFile(), $e->getLine()), [
                'path' => $request->path(),
                'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 8),
            ]);
        }

        $debug = (bool) Config::get('app.debug', false);

        if ($request->isAjax()) {
            Response::json([
                'success' => false,
                'message' => $status >= 500 && !$debug
                    ? 'Something went wrong on our side. Please try again.'
                    : ($message !== '' ? $message : 'Request failed.'),
            ], $status);

            return;
        }

        $view = 'errors.' . $status;

        if (!View::exists($view)) {
            $view = 'errors.500';
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
}
