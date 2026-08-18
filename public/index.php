<?php

declare(strict_types=1);

/**
 * LRMS front controller. The only PHP file exposed to the web.
 */

// Allow `php -S localhost:8000 -t public public/index.php` to serve static assets.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . '/' . ltrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');

    if ($file !== __FILE__ && is_file($file)) {
        return false;
    }
}

// API requests are token authenticated and must not receive a cookie session.
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if (str_contains($path, '/api/')) {
    define('LRMS_STATELESS', true);
}

require __DIR__ . '/../app/bootstrap.php';

App\Core\Kernel::handle();
