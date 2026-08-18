<?php

declare(strict_types=1);

/**
 * LRMS — Loan Recovery Management System
 * Application bootstrap: autoloader, configuration, error handling, session.
 */

define('BASE_PATH', dirname(__DIR__));
define('LRMS_START', microtime(true));

/* -------------------------------------------------------------------------- */
/* Autoloading                                                                */
/* -------------------------------------------------------------------------- */

if (is_file(BASE_PATH . '/vendor/autoload.php')) {
    require BASE_PATH . '/vendor/autoload.php';
}

// PSR-4 fallback: the application has no hard Composer dependencies, so it must
// boot on a bare shared host where `composer install` was never run.
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }

    $file = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

require_once BASE_PATH . '/app/Helpers/functions.php';

/* -------------------------------------------------------------------------- */
/* Configuration                                                              */
/* -------------------------------------------------------------------------- */

use App\Core\Config;
use App\Core\Kernel;
use App\Core\Session;

try {
    Config::load(BASE_PATH . '/config/config.php');
} catch (Throwable $e) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Configuration error: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }

    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Configuration required</title>'
        . '<style>body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#0b1220;color:#e2e8f0;'
        . 'display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0}'
        . '.card{background:#151f35;padding:40px;border-radius:16px;max-width:560px;line-height:1.6}'
        . 'code{background:#0b1220;padding:2px 6px;border-radius:4px}</style></head><body><div class="card">'
        . '<h1>Configuration required</h1><p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES) . '</p>'
        . '<p>Copy <code>config/config.example.php</code> to <code>config/config.php</code>, add your database'
        . ' credentials, then reload this page.</p></div></body></html>';
    exit;
}

// Handy for tests and containers: run the app on a different host/port without
// editing config files.
$urlOverride = getenv('LRMS_APP_URL');

if (is_string($urlOverride) && $urlOverride !== '') {
    Config::set('app.url', rtrim($urlOverride, '/'));
}

date_default_timezone_set((string) Config::get('app.timezone', 'Asia/Kolkata'));

$debug = (bool) Config::get('app.debug', false);
error_reporting($debug ? E_ALL : E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');

Kernel::registerErrorHandlers();

// Stateless API requests must not start a cookie session.
if (!defined('LRMS_STATELESS')) {
    Session::start();
}
