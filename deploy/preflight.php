<?php

declare(strict_types=1);

/**
 * LRMS hosting preflight check.
 *
 * Answers "is this server able to run LRMS, and is it configured safely" without
 * you having to guess from a blank white page.
 *
 * RUN IT
 *   From CyberPanel's Terminal (preferred):
 *       cd /home/<domain>/public_html && php deploy/preflight.php
 *
 *   Or in a browser, if you have no shell: copy this one file into the web root
 *       cp deploy/preflight.php public/preflight.php
 *   then open https://your-domain/preflight.php
 *
 * DELETE IT AFTERWARDS
 *   rm public/preflight.php
 *
 * It prints no passwords, no tokens and no database contents — only whether
 * things work. Even so, it tells an outsider which PHP extensions you have, so
 * it does not belong on a live site any longer than it takes to read.
 */

$cli = PHP_SAPI === 'cli';

if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
}

/** Locate the application root whether this runs from deploy/ or the web root. */
$root = null;

foreach ([__DIR__ . '/..', __DIR__, __DIR__ . '/../..'] as $candidate) {
    if (is_file($candidate . '/app/bootstrap.php')) {
        $root = realpath($candidate);
        break;
    }
}

$pass = 0;
$warn = 0;
$fail = 0;

/**
 * The three ways a "404 on every page" happens, in the order they occur.
 *
 * This runs before anything else because when one of these is true nothing else
 * matters, and the message you get from the web server ("404") says nothing about
 * which one it is.
 */
function lrms_diagnose_404(?string $root): void
{
    $here = __DIR__;

    // 1. The archive was never flattened, so there is no application root at all
    //    and this script is sitting inside a wrapper folder.
    if ($root === null) {
        echo "\n";
        echo "  DIAGNOSIS: the files are not where the web server expects them.\n\n";
        echo "  There is no app/bootstrap.php next to this script, which means the\n";
        echo "  archive was extracted without being flattened. You probably have:\n\n";
        echo "      public_html/dhdhdh51-zetpro-<sha>/app/...\n\n";
        echo "  when you need:\n\n";
        echo "      public_html/app/...\n";
        echo "      public_html/public/index.php\n\n";
        echo "  Move the contents of that inner folder up into public_html,\n";
        echo "  including the hidden files (.htaccess). In a shell:\n\n";
        echo "      cd /home/<your-domain>/public_html\n";
        echo "      mv */.[!.]* */* . 2>/dev/null\n\n";
        echo "  Script location: " . $here . "\n";

        return;
    }

    // 2. The front controller is missing, so the document root cannot be valid.
    if (!is_file($root . '/public/index.php')) {
        echo "\n";
        echo "  DIAGNOSIS: public/index.php is missing from " . $root . "\n\n";
        echo "  The web server has nothing to serve, so every URL returns 404.\n";
        echo "  Re-upload the package and confirm public/ came with it.\n";

        return;
    }

    // 3. The rewrite rules are missing, which breaks every URL except the
    //    home page.
    if (!is_file($root . '/public/.htaccess')) {
        echo "\n";
        echo "  WARNING: public/.htaccess is missing.\n\n";
        echo "  File managers hide dotfiles, so it is easy to leave behind when\n";
        echo "  moving files. Without it the home page may load but every other\n";
        echo "  URL returns 404. Re-copy it, or paste the rules from\n";
        echo "  deploy/openlitespeed-rewrite.conf into CyberPanel's Rewrite Rules.\n";
    }
}

function heading(string $text): void
{
    echo "\n" . $text . "\n" . str_repeat('-', strlen($text)) . "\n";
}

function result(string $state, string $label, string $detail = ''): void
{
    global $pass, $warn, $fail;

    match ($state) {
        'pass' => $pass++,
        'warn' => $warn++,
        default => $fail++,
    };

    printf(
        "  [%-4s] %-42s %s\n",
        strtoupper($state),
        $label,
        $detail
    );
}

echo "LRMS preflight check\n";
echo str_repeat('=', 64) . "\n";
printf("  when   : %s\n", date('d M Y H:i:s'));
printf("  php    : %s (%s)\n", PHP_VERSION, PHP_SAPI);
printf("  root   : %s\n", $root ?? 'NOT FOUND');
printf("  server : %s\n", $_SERVER['SERVER_SOFTWARE'] ?? 'command line');

if ($root === null) {
    lrms_diagnose_404(null);
    exit(1);
}

// Say so immediately when the site cannot possibly work, rather than burying it
// among thirty passing checks.
lrms_diagnose_404($root);

/* -------------------------------------------------------------------------- */
heading('PHP version and extensions');
/* -------------------------------------------------------------------------- */

if (PHP_VERSION_ID >= 80200) {
    result('pass', 'PHP 8.2 or newer', PHP_VERSION);
} else {
    result('fail', 'PHP 8.2 or newer required', 'found ' . PHP_VERSION
        . ' — set the PHP version for this site in CyberPanel');
}

$required = [
    'pdo_mysql' => 'database access',
    'mbstring' => 'text handling',
    'gd' => 'photo watermarking and resizing',
    'zip' => 'reading .xlsx uploads',
    'json' => 'API responses',
    'fileinfo' => 'upload type checking',
    'openssl' => 'password hashing and HTTPS calls',
];

foreach ($required as $extension => $why) {
    if (extension_loaded($extension)) {
        result('pass', 'extension ' . $extension, $why);
    } else {
        result('fail', 'extension ' . $extension . ' MISSING', $why
            . ' — enable it in CyberPanel ▸ PHP ▸ Edit PHP Extensions');
    }
}

foreach (['curl' => 'outbound SMS/webhook calls', 'intl' => 'nicer sorting'] as $extension => $why) {
    extension_loaded($extension)
        ? result('pass', 'extension ' . $extension, $why)
        : result('warn', 'extension ' . $extension . ' absent', 'optional: ' . $why);
}

/* -------------------------------------------------------------------------- */
heading('PHP settings');
/* -------------------------------------------------------------------------- */

$bytes = static function (string $value): int {
    $value = trim($value);
    $unit = strtolower(substr($value, -1));
    $number = (int) $value;

    return match ($unit) {
        'g' => $number * 1024 * 1024 * 1024,
        'm' => $number * 1024 * 1024,
        'k' => $number * 1024,
        default => $number,
    };
};

$uploadMax = $bytes((string) ini_get('upload_max_filesize'));
$postMax = $bytes((string) ini_get('post_max_size'));

// A branch NPA list is routinely several megabytes.
$uploadMax >= 16 * 1024 * 1024
    ? result('pass', 'upload_max_filesize', (string) ini_get('upload_max_filesize'))
    : result('warn', 'upload_max_filesize is small', (string) ini_get('upload_max_filesize')
        . ' — raise to 32M for large Excel uploads');

$postMax >= $uploadMax
    ? result('pass', 'post_max_size', (string) ini_get('post_max_size'))
    : result('fail', 'post_max_size below upload_max_filesize', 'uploads will be silently truncated');

$memory = $bytes((string) ini_get('memory_limit'));
$memory === -1 || $memory >= 256 * 1024 * 1024
    ? result('pass', 'memory_limit', (string) ini_get('memory_limit'))
    : result('warn', 'memory_limit is low', (string) ini_get('memory_limit')
        . ' — raise to 256M before importing large sheets');

$maxTime = (int) ini_get('max_execution_time');
$maxTime === 0 || $maxTime >= 120
    ? result('pass', 'max_execution_time', $maxTime === 0 ? 'unlimited' : $maxTime . 's')
    : result('warn', 'max_execution_time is short', $maxTime . 's — a big import may time out');

ini_get('file_uploads')
    ? result('pass', 'file_uploads enabled')
    : result('fail', 'file_uploads disabled', 'Excel import and photos cannot work');

/* -------------------------------------------------------------------------- */
heading('Directories and permissions');
/* -------------------------------------------------------------------------- */

foreach (['storage/uploads', 'storage/generated', 'storage/logs'] as $relative) {
    $path = $root . '/' . $relative;

    if (!is_dir($path)) {
        if (@mkdir($path, 0775, true)) {
            result('warn', $relative . ' created', 'was missing from the upload');
            continue;
        }

        result('fail', $relative . ' missing and cannot be created', 'create it and chmod 775');
        continue;
    }

    if (is_writable($path)) {
        result('pass', $relative . ' writable', substr(sprintf('%o', fileperms($path)), -4));
    } else {
        result('fail', $relative . ' NOT writable', 'chown to the site user and chmod 775');
    }
}

// Running as root makes every permission check pass regardless of the mode, so
// the result would be misleading: the web server runs as the site user, not as
// root. Say so rather than reporting a false all-clear.
if ($cli && function_exists('posix_geteuid') && posix_geteuid() === 0) {
    result(
        'warn',
        'running as root',
        'permission results are optimistic — re-run as the site user, e.g. su -s /bin/bash <site-user>'
    );
}

// A real write, because "is_writable" can lie about mounted or SELinux paths.
$probe = $root . '/storage/logs/.preflight-' . bin2hex(random_bytes(4));

if (@file_put_contents($probe, 'ok') !== false) {
    @unlink($probe);
    result('pass', 'actually wrote a file to storage/logs');
} else {
    result('fail', 'could not write to storage/logs', 'the panel user does not own these files');
}

/* -------------------------------------------------------------------------- */
heading('Web exposure');
/* -------------------------------------------------------------------------- */

// If this file is reachable over the web from the application root rather than
// from public/, the document root is wrong and config/ is exposed.
if (!$cli) {
    $script = (string) ($_SERVER['SCRIPT_FILENAME'] ?? '');

    if ($script !== '' && realpath(dirname($script)) === realpath($root . '/public')) {
        result('pass', 'running from inside public/', 'document root looks correct');
    } elseif ($script !== '' && realpath(dirname($script)) === realpath($root . '/deploy')) {
        result(
            'fail',
            'deploy/ is reachable from the web',
            'the document root points at the application root, not public/ — fix this first'
        );
    } else {
        result('warn', 'could not confirm the document root', 'check it points at public_html/public');
    }
} else {
    result('pass', 'command line run', 'document root not checked; verify in a browser');
}

// config/config.php must never be downloadable. When running in a browser we
// know our own address, so this is tested rather than left as an instruction —
// it is the single worst misconfiguration possible here.
if (!$cli) {
    $scheme = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');

    if ($host === '') {
        result('warn', 'could not determine the site address', 'check config/config.php is not downloadable');
    } else {
        foreach (['config/config.php', 'app/bootstrap.php', 'database/schema.sql'] as $secret) {
            $target = sprintf('%s://%s/%s', $scheme, $host, $secret);
            $status = null;

            if (function_exists('curl_init')) {
                $curl = curl_init($target);
                curl_setopt_array($curl, [
                    CURLOPT_NOBODY => true,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 5,
                    CURLOPT_FOLLOWLOCATION => false,
                    // A self-signed or still-provisioning certificate must not
                    // make this check silently pass.
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => 0,
                ]);
                curl_exec($curl);
                $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
                curl_close($curl);
            }

            if ($status === null || $status === 0) {
                // A single-worker server (PHP's built-in one) cannot answer a
                // request to itself while serving this page. OpenLiteSpeed can,
                // so this is normally only seen in local testing.
                result('warn', 'could not test /' . $secret, 'open it in a browser: it must not return the file');
            } elseif ($status === 200) {
                result('fail', '/' . $secret . ' IS DOWNLOADABLE', 'HTTP 200 — fix the document root now');
            } else {
                result('pass', '/' . $secret . ' not reachable', 'HTTP ' . $status);
            }
        }
    }
} else {
    result(
        'warn',
        'check by hand: config/config.php',
        'https://your-domain/config/config.php must NOT return the file'
    );
}

/* -------------------------------------------------------------------------- */
heading('Configuration and database');
/* -------------------------------------------------------------------------- */

if (!is_file($root . '/config/config.php')) {
    result('fail', 'config/config.php missing', 'copy config.example.php to config.php and edit it');
} else {
    result('pass', 'config/config.php present');

    /** @var array<string, mixed> $config */
    $config = require $root . '/config/config.php';

    $local = $root . '/config/config.local.php';

    if (is_file($local)) {
        $overrides = require $local;
        $config = array_replace_recursive($config, is_array($overrides) ? $overrides : []);
        result('warn', 'config/config.local.php present', 'developer override file — remove it on a server');
    }

    $app = is_array($config['app'] ?? null) ? $config['app'] : [];
    $db = is_array($config['database'] ?? null) ? $config['database'] : [];

    // Debug must be off in production: it prints stack traces to visitors.
    empty($app['debug'])
        ? result('pass', 'app.debug is off')
        : result('fail', 'app.debug is ON', 'set it to false — errors would be shown to users');

    $url = (string) ($app['url'] ?? '');

    if ($url === '') {
        result('warn', 'app.url is empty', 'set it to https://your-domain so links and PDFs are correct');
    } elseif (!str_starts_with($url, 'https://')) {
        result('warn', 'app.url is not https', $url . ' — issue a certificate and use https');
    } else {
        result('pass', 'app.url', $url);
    }

    $key = (string) ($app['key'] ?? '');
    strlen($key) >= 32 && !str_contains(strtolower($key), 'change')
        ? result('pass', 'app.key looks set')
        : result('fail', 'app.key is missing or still the placeholder', 'set a long random string');

    // Database connectivity — the single most common cause of a blank page.
    $name = (string) ($db['database'] ?? '');
    $user = (string) ($db['username'] ?? '');
    $host = (string) ($db['host'] ?? '127.0.0.1');
    $socket = (string) ($db['socket'] ?? '');

    if ($name === '' || $user === '') {
        result('fail', 'database credentials not filled in', 'edit config/config.php');
    } else {
        $dsn = $socket !== ''
            ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $socket, $name)
            : sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $host,
                (int) ($db['port'] ?? 3306),
                $name
            );

        try {
            $pdo = new PDO($dsn, $user, (string) ($db['password'] ?? ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);

            result('pass', 'database connection', $name . ' as ' . $user);

            $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
            $isMaria = stripos($version, 'mariadb') !== false;
            $numeric = (float) $version;

            if (($isMaria && $numeric >= 10.4) || (!$isMaria && $numeric >= 5.7)) {
                result('pass', 'database version', $version);
            } else {
                result('warn', 'database version may be too old', $version . ' — MySQL 5.7+ / MariaDB 10.4+');
            }

            $tables = (int) $pdo->query(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
            )->fetchColumn();

            if ($tables === 0) {
                result('warn', 'schema not installed yet', 'run: php database/migrate.php --seed');
            } elseif ($tables >= 40) {
                result('pass', 'schema installed', $tables . ' tables');

                // Still on the seeded password? That is an open door.
                try {
                    $seeded = (int) $pdo->query(
                        "SELECT COUNT(*) FROM users WHERE email = 'admin@lrms.local' AND must_change_password = 1"
                    )->fetchColumn();

                    $seeded > 0
                        ? result('warn', 'seeded admin has not signed in yet', 'change the password immediately')
                        : result('pass', 'seeded admin password has been changed');
                } catch (Throwable) {
                    result('warn', 'could not check the admin account', 'users table not readable yet');
                }
            } else {
                result('warn', 'schema looks incomplete', $tables . ' tables — expected 40; run database/upgrade.php');
            }
        } catch (Throwable $e) {
            // The message can contain the host but never the password.
            result('fail', 'database connection FAILED', $e->getMessage());
            echo "\n         In CyberPanel the database user is usually prefixed with the\n";
            echo "         site name, and the host is localhost. Check Databases ▸ List\n";
            echo "         Databases, and that the user is attached to that database.\n";
        }
    }
}

/* -------------------------------------------------------------------------- */
heading('Scheduled tasks');
/* -------------------------------------------------------------------------- */

is_file($root . '/bin/cron.php')
    ? result('pass', 'bin/cron.php present', 'add the cron entry — see HOSTING-CYBERPANEL.md')
    : result('fail', 'bin/cron.php missing', 'deadline reminders and promise sweeping will not run');

$cronLog = $root . '/storage/logs/cron.log';

if (is_file($cronLog)) {
    $age = time() - (int) filemtime($cronLog);
    $age < 3600
        ? result('pass', 'cron has run recently', round($age / 60) . ' minutes ago')
        : result('warn', 'cron log is stale', 'last wrote ' . round($age / 3600) . ' hours ago');
} else {
    result('warn', 'cron has never run', 'expected once the cron entry is installed');
}

/* -------------------------------------------------------------------------- */

echo "\n" . str_repeat('=', 64) . "\n";
printf("  %d passed, %d warning(s), %d failure(s)\n", $pass, $warn, $fail);

if ($fail > 0) {
    echo "\n  Fix the failures above before going live. Nothing will work\n";
    echo "  reliably until they are resolved.\n";
} elseif ($warn > 0) {
    echo "\n  No blockers. Read the warnings — most matter for security or for\n";
    echo "  large imports.\n";
} else {
    echo "\n  Ready. Delete this file now:  rm public/preflight.php\n";
}

echo "\n";

exit($fail > 0 ? 1 : 0);
