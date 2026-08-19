<?php

declare(strict_types=1);

/**
 * LRMS browser installer.
 *
 * For hosting without a command line: open https://your-domain/install.php,
 * fill in the form, and it creates the configuration, the 40 tables, the roles,
 * report types, settings, the four configurable forms and your admin account.
 *
 * DELETE THIS FILE once the install finishes. It tries to remove itself and will
 * tell you if it could not. While it exists on an uninstalled site, anyone who
 * finds it can point your application at their own database.
 *
 * It refuses to run when the application is already installed, so it cannot be
 * used to wipe a working system.
 */

const LRMS_BASE = __DIR__ . '/..';

$configFile = LRMS_BASE . '/config/config.local.php';
$lockFile = LRMS_BASE . '/storage/installed.lock';
$schemaFile = LRMS_BASE . '/database/schema.sql';

/* -------------------------------------------------------------------------- */
/* Guard: never run against an installation that already exists               */
/* -------------------------------------------------------------------------- */

$blocked = null;

if (is_file($lockFile)) {
    $blocked = 'This site is already installed. Delete public/install.php.';
} elseif (is_file($configFile)) {
    // A config exists — if it points at a populated database, this is a live site.
    $existing = @include $configFile;

    if (is_array($existing)) {
        $db = is_array($existing['database'] ?? null) ? $existing['database'] : [];

        try {
            $probe = lrms_connect($db);
            $count = (int) $probe->query(
                "SELECT COUNT(*) FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'"
            )->fetchColumn();

            if ($count > 0) {
                $blocked = 'This site is already installed and has data. '
                    . 'Delete public/install.php. To change settings edit config/config.local.php; '
                    . 'to update the schema use database/upgrade.php.';
            }
        } catch (Throwable) {
            // Cannot connect, so nothing to protect — let the installer run.
        }
    }
}

/**
 * @param array<string, mixed> $db
 */
function lrms_connect(array $db): PDO
{
    $socket = trim((string) ($db['socket'] ?? ''));

    $dsn = $socket !== ''
        ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $socket, (string) ($db['database'] ?? ''))
        : sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            (string) ($db['host'] ?? '127.0.0.1'),
            (int) ($db['port'] ?? 3306),
            (string) ($db['database'] ?? '')
        );

    return new PDO($dsn, (string) ($db['username'] ?? ''), (string) ($db['password'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 8,
    ]);
}

/* -------------------------------------------------------------------------- */
/* Requirements                                                              */
/* -------------------------------------------------------------------------- */

$requirements = [];
$requirementsOk = true;

$addRequirement = static function (string $label, bool $ok, string $detail = '') use (&$requirements, &$requirementsOk): void {
    $requirements[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];

    if (!$ok) {
        $requirementsOk = false;
    }
};

$addRequirement('PHP 8.2 or newer', PHP_VERSION_ID >= 80200, PHP_VERSION);

foreach ([
    'pdo_mysql' => 'database access',
    'mbstring' => 'text handling',
    'gd' => 'photo watermarking',
    'zip' => 'reading .xlsx uploads',
    'fileinfo' => 'upload type checking',
    'openssl' => 'password hashing',
    'json' => 'API responses',
] as $extension => $why) {
    $addRequirement('Extension ' . $extension, extension_loaded($extension), $why);
}

foreach (['storage/uploads', 'storage/generated', 'storage/logs'] as $relative) {
    $path = LRMS_BASE . '/' . $relative;

    if (!is_dir($path)) {
        @mkdir($path, 0775, true);
    }

    $addRequirement($relative . ' writable', is_dir($path) && is_writable($path), 'chmod 775');
}

$addRequirement('database/schema.sql present', is_file($schemaFile), 'part of the upload');

// Not fatal: if config/ cannot be written the file contents are shown to paste.
$configWritable = is_writable(LRMS_BASE . '/config')
    || (is_file($configFile) && is_writable($configFile));

/* -------------------------------------------------------------------------- */
/* Install                                                                   */
/* -------------------------------------------------------------------------- */

$errors = [];
$done = false;
$manualConfig = null;
$selfDeleted = false;
$loginUrl = '';
$adminUsername = '';

/** Sensible default for the site address, which the operator can correct. */
$scheme = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off') ? 'https' : 'http';
$guessedUrl = $scheme . '://' . (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

$input = [
    'db_host' => 'localhost',
    'db_port' => '3306',
    'db_name' => '',
    'db_user' => '',
    'db_pass' => '',
    'db_socket' => '',
    'admin_name' => 'System Administrator',
    'admin_email' => '',
    'admin_username' => 'admin',
    'admin_mobile' => '',
    'admin_password' => '',
    'app_url' => $guessedUrl,
    'timezone' => 'Asia/Kolkata',
    'org_name' => '',
];

if ($blocked === null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    foreach ($input as $key => $default) {
        $input[$key] = trim((string) ($_POST[$key] ?? $default));
    }

    if (!$requirementsOk) {
        $errors[] = 'Fix the requirements above first.';
    }

    foreach (['db_name' => 'Database name', 'db_user' => 'Database user',
        'admin_email' => 'Your email', 'admin_username' => 'Admin username',
        'admin_password' => 'Admin password'] as $key => $label) {
        if ($input[$key] === '') {
            $errors[] = $label . ' is required.';
        }
    }

    if ($input['admin_email'] !== '' && filter_var($input['admin_email'], FILTER_VALIDATE_EMAIL) === false) {
        $errors[] = 'That email address is not valid.';
    }

    // Matches the application's own password policy, so the account you create
    // here can actually sign in afterwards.
    if (strlen($input['admin_password']) < 10) {
        $errors[] = 'The admin password must be at least 10 characters.';
    } elseif (
        preg_match('/[A-Za-z]/', $input['admin_password']) !== 1
        || preg_match('/\d/', $input['admin_password']) !== 1
    ) {
        $errors[] = 'The admin password must contain both letters and numbers.';
    }

    if (!in_array($input['timezone'], timezone_identifiers_list(), true)) {
        $errors[] = 'That timezone is not recognised.';
    }

    $database = [
        'host' => $input['db_host'],
        'port' => (int) ($input['db_port'] ?: 3306),
        'database' => $input['db_name'],
        'username' => $input['db_user'],
        'password' => $input['db_pass'],
    ];

    // Some hosts only accept connections over a unix socket. When one is given
    // it takes precedence over host and port, which is how lrms_connect and the
    // application's own Database class both behave.
    if ($input['db_socket'] !== '') {
        $database['socket'] = $input['db_socket'];
    }

    $pdo = null;

    if ($errors === []) {
        try {
            $pdo = lrms_connect($database);
        } catch (Throwable $e) {
            $errors[] = 'Could not connect to the database: ' . $e->getMessage();
            $errors[] = 'On most panels the database and user names are prefixed, '
                . 'e.g. "yoursite_lrms". Check the exact names in your hosting panel, '
                . 'and that the user is attached to that database.';

            if ($input['db_socket'] === '') {
                $errors[] = 'If the error mentions a socket or "No such file or directory", '
                    . 'this server does not accept TCP connections on that host. '
                    . 'Fill in the socket path field below — commonly '
                    . '/var/lib/mysql/mysql.sock or /tmp/mysql.sock.';
            }
        }
    }

    // Refuse to touch a database that already holds data.
    if ($pdo instanceof PDO) {
        $tables = (int) $pdo->query(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
        )->fetchColumn();

        if ($tables > 0) {
            $errors[] = sprintf(
                'That database already contains %d table(s). Use an empty database, '
                . 'or drop the existing tables first — this installer will not overwrite data.',
                $tables
            );
        }
    }

    if ($errors === []) {
        $config = <<<PHP
<?php

// LRMS configuration — written by the installer on <DATE>.
// Values here override config/config.php. Keep this file out of version control.

return [
    'app' => [
        'name' => 'LRMS',
        'url' => '<URL>',
        'key' => '<KEY>',
        'debug' => false,
        'timezone' => '<TZ>',
    ],
    'database' => [
        'host' => '<HOST>',
        'port' => <PORT>,
        'database' => '<DB>',
        'username' => '<USER>',
        'password' => '<PASS>',<SOCKET>
    ],
];
PHP;

        $config = str_replace(
            ['<DATE>', '<URL>', '<KEY>', '<TZ>', '<HOST>', '<PORT>', '<DB>', '<USER>', '<PASS>', '<SOCKET>'],
            [
                date('d M Y H:i'),
                addslashes(rtrim($input['app_url'], '/')),
                bin2hex(random_bytes(24)),
                addslashes($input['timezone']),
                addslashes($input['db_host']),
                (string) $database['port'],
                addslashes($input['db_name']),
                addslashes($input['db_user']),
                addslashes($input['db_pass']),
                $input['db_socket'] === ''
                    ? ''
                    : "\n        'socket' => '" . addslashes($input['db_socket']) . "',",
            ],
            $config
        );

        if ($configWritable && @file_put_contents($configFile, $config) !== false) {
            @chmod($configFile, 0640);
        } else {
            // Shared hosting sometimes has config/ read-only. Let them paste it.
            $manualConfig = $config;
            $errors[] = 'Could not write config/config.local.php. Create that file yourself '
                . 'with the contents shown below, then run this installer again.';
        }
    }

    if ($errors === []) {
        try {
            // Schema first, using the same splitter the command line installer
            // uses, so both paths behave identically.
            require LRMS_BASE . '/database/sql.php';

            $statements = lrms_split_sql((string) file_get_contents($schemaFile));

            foreach ($statements as $statement) {
                $pdo->exec($statement);
            }

            $installed = (int) $pdo->query(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
            )->fetchColumn();

            if ($installed < 40) {
                throw new RuntimeException(
                    sprintf('Only %d of the 40 tables were created.', $installed)
                );
            }

            // Now boot the application so the seed runs through the real code
            // rather than a copy of it, and create the admin from this form.
            putenv('LRMS_ADMIN_EMAIL=' . $input['admin_email']);
            putenv('LRMS_ADMIN_PASSWORD=' . $input['admin_password']);
            putenv('LRMS_ADMIN_MOBILE=' . $input['admin_mobile']);

            ob_start();
            require LRMS_BASE . '/app/bootstrap.php';
            require LRMS_BASE . '/database/seed.php';
            lrms_seed(false);
            ob_end_clean();

            // The seed always uses "admin" and forces a password change. The
            // password here was chosen by the operator, so neither applies.
            App\Core\Database::update('users', [
                'name' => $input['admin_name'] !== '' ? $input['admin_name'] : 'System Administrator',
                'username' => $input['admin_username'],
                'must_change_password' => 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'email = :email', ['email' => $input['admin_email']]);

            if ($input['org_name'] !== '') {
                App\Core\Settings::set('organisation_name', $input['org_name'], 'general');
            }

            @file_put_contents(
                $lockFile,
                'Installed ' . date('c') . ' by the browser installer.' . PHP_EOL
            );

            $adminUsername = $input['admin_username'];
            $loginUrl = rtrim($input['app_url'], '/') . '/login';
            $done = true;

            // Remove the installer immediately; report honestly if we cannot.
            $selfDeleted = @unlink(__FILE__);
        } catch (Throwable $e) {
            $errors[] = 'Installation failed: ' . $e->getMessage();
            $errors[] = 'The database may be half-built. Drop its tables before trying again.';
        }
    }
}

/* -------------------------------------------------------------------------- */
/* Page                                                                     */
/* -------------------------------------------------------------------------- */

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Install LRMS</title>
<style>
  :root { --ink:#0f172a; --muted:#64748b; --line:#e2e8f0; --brand:#1e3a5f; --ok:#15803d; --bad:#b91c1c; }
  * { box-sizing:border-box; }
  body { margin:0; background:#f1f5f9; color:var(--ink);
         font:14px/1.55 system-ui,-apple-system,"Segoe UI",sans-serif; }
  .wrap { max-width:760px; margin:32px auto; padding:0 16px; }
  .card { background:#fff; border:1px solid var(--line); border-radius:12px; padding:26px; margin-bottom:18px; }
  h1 { margin:0 0 4px; font-size:22px; }
  h2 { font-size:13px; text-transform:uppercase; letter-spacing:.06em; color:var(--muted);
       margin:26px 0 12px; padding-bottom:7px; border-bottom:1px solid var(--line); }
  h2:first-of-type { margin-top:0; }
  .sub { color:var(--muted); margin:0 0 20px; }
  .grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
  .full { grid-column:1/-1; }
  label { display:block; font-weight:600; font-size:12.5px; margin-bottom:5px; }
  input { width:100%; padding:9px 11px; border:1px solid #cbd5e1; border-radius:7px; font-size:14px; }
  input:focus { outline:2px solid #93c5fd; border-color:#60a5fa; }
  .hint { color:var(--muted); font-size:12px; margin-top:4px; }
  .btn { background:var(--brand); color:#fff; border:0; border-radius:8px;
         padding:12px 22px; font-size:15px; font-weight:600; cursor:pointer; }
  .btn:disabled { background:#94a3b8; cursor:not-allowed; }
  ul.checks { list-style:none; margin:0; padding:0; }
  ul.checks li { display:flex; gap:9px; padding:5px 0; border-bottom:1px solid #f1f5f9; }
  .tick { font-weight:700; color:var(--ok); }
  .cross { font-weight:700; color:var(--bad); }
  .detail { color:var(--muted); margin-left:auto; font-size:12px; }
  .alert { border-radius:8px; padding:14px 16px; margin-bottom:16px; }
  .alert-bad { background:#fef2f2; border:1px solid #fecaca; color:#7f1d1d; }
  .alert-ok { background:#f0fdf4; border:1px solid #bbf7d0; color:#14532d; }
  .alert-warn { background:#fffbeb; border:1px solid #fde68a; color:#78350f; }
  .alert ul { margin:8px 0 0; padding-left:20px; }
  code, pre { background:#0f172a; color:#e2e8f0; border-radius:6px; }
  code { padding:2px 6px; font-size:12.5px; }
  pre { padding:14px; overflow:auto; font-size:12px; line-height:1.5; }
  a.big { display:inline-block; background:var(--ok); color:#fff; text-decoration:none;
          padding:12px 22px; border-radius:8px; font-weight:600; }
</style>
</head>
<body>
<div class="wrap">

<?php if ($blocked !== null): ?>
  <div class="card">
    <h1>Already installed</h1>
    <div class="alert alert-warn"><?= $e($blocked) ?></div>
    <p class="sub">Nothing has been changed.</p>
  </div>

<?php elseif ($done): ?>
  <div class="card">
    <h1>LRMS is installed</h1>
    <p class="sub">Database created, baseline data loaded, your account is ready.</p>

    <div class="alert alert-ok">
      Sign in with username <strong><?= $e($adminUsername) ?></strong> and the password you chose.
    </div>

    <?php if ($selfDeleted): ?>
      <div class="alert alert-ok">The installer deleted itself.</div>
    <?php else: ?>
      <div class="alert alert-bad">
        <strong>Delete <code>public/install.php</code> now.</strong>
        It could not remove itself, and while it is there it is a way into your site.
      </div>
    <?php endif; ?>

    <p><a class="big" href="<?= $e($loginUrl) ?>">Go to sign-in</a></p>

    <h2>Do these next</h2>
    <ul>
      <li>Point the document root at <code>public_html/public</code> if you have not — then
          <code>config/config.php</code> must return 404, not download.</li>
      <li>Issue an SSL certificate and force HTTPS.</li>
      <li>Add the cron entry so deadline reminders, promise sweeping and absentee
          marking run. See <code>HOSTING-CYBERPANEL.md</code>.</li>
      <li>In <strong>Settings</strong>, set the report deadline and GPS limits, then create
          your branches and BC Supervisors.</li>
    </ul>
  </div>

<?php else: ?>
  <div class="card">
    <h1>Install LRMS</h1>
    <p class="sub">Loan Recovery Management System. No command line needed.</p>

    <?php if ($errors !== []): ?>
      <div class="alert alert-bad">
        <strong>Could not install:</strong>
        <ul><?php foreach ($errors as $message): ?><li><?= $e($message) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>

    <?php if ($manualConfig !== null): ?>
      <p>Create <code>config/config.local.php</code> with exactly this, then reload:</p>
      <pre><?= $e($manualConfig) ?></pre>
    <?php endif; ?>

    <h2>Server requirements</h2>
    <ul class="checks">
      <?php foreach ($requirements as $requirement): ?>
        <li>
          <span class="<?= $requirement['ok'] ? 'tick' : 'cross' ?>"><?= $requirement['ok'] ? '✓' : '✗' ?></span>
          <span><?= $e($requirement['label']) ?></span>
          <span class="detail"><?= $e($requirement['detail']) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>

    <?php if (!$requirementsOk): ?>
      <div class="alert alert-bad" style="margin-top:16px">
        Enable the missing extensions in your hosting panel (PHP ▸ Edit PHP Extensions),
        or fix the folder permissions, then reload this page.
      </div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
      <h2>Database</h2>
      <p class="hint" style="margin-top:-6px">
        Create an <strong>empty</strong> database in your hosting panel first. Most panels
        prefix the names — use them exactly as shown there.
      </p>
      <div class="grid">
        <div>
          <label for="db_host">Host</label>
          <input id="db_host" name="db_host" value="<?= $e($input['db_host']) ?>">
        </div>
        <div>
          <label for="db_port">Port</label>
          <input id="db_port" name="db_port" value="<?= $e($input['db_port']) ?>">
        </div>
        <div>
          <label for="db_name">Database name</label>
          <input id="db_name" name="db_name" value="<?= $e($input['db_name']) ?>" required>
        </div>
        <div>
          <label for="db_user">Database user</label>
          <input id="db_user" name="db_user" value="<?= $e($input['db_user']) ?>" required>
        </div>
        <div class="full">
          <label for="db_pass">Database password</label>
          <input id="db_pass" name="db_pass" type="password" value="<?= $e($input['db_pass']) ?>">
        </div>
        <div class="full">
          <label for="db_socket">Socket path (only if host does not work)</label>
          <input id="db_socket" name="db_socket" value="<?= $e($input['db_socket']) ?>"
                 placeholder="/var/lib/mysql/mysql.sock">
          <div class="hint">
            Leave empty on almost all hosts. Fill it in only if connecting by host fails with
            a socket error. When set, it is used instead of the host and port.
          </div>
        </div>
      </div>

      <h2>Your admin account</h2>
      <div class="grid">
        <div>
          <label for="admin_name">Full name</label>
          <input id="admin_name" name="admin_name" value="<?= $e($input['admin_name']) ?>">
        </div>
        <div>
          <label for="admin_username">Username</label>
          <input id="admin_username" name="admin_username" value="<?= $e($input['admin_username']) ?>" required>
        </div>
        <div>
          <label for="admin_email">Email</label>
          <input id="admin_email" name="admin_email" type="email" value="<?= $e($input['admin_email']) ?>" required>
          <div class="hint">You can sign in with either the username or the email.</div>
        </div>
        <div>
          <label for="admin_mobile">Mobile</label>
          <input id="admin_mobile" name="admin_mobile" value="<?= $e($input['admin_mobile']) ?>">
        </div>
        <div class="full">
          <label for="admin_password">Password</label>
          <input id="admin_password" name="admin_password" type="password" required>
          <div class="hint">At least 10 characters, with letters and numbers. Nothing default is created.</div>
        </div>
      </div>

      <h2>Site</h2>
      <div class="grid">
        <div>
          <label for="app_url">Site address</label>
          <input id="app_url" name="app_url" value="<?= $e($input['app_url']) ?>">
          <div class="hint">Used in links and PDFs. Include https://</div>
        </div>
        <div>
          <label for="timezone">Timezone</label>
          <input id="timezone" name="timezone" value="<?= $e($input['timezone']) ?>">
        </div>
        <div class="full">
          <label for="org_name">Organisation name (optional)</label>
          <input id="org_name" name="org_name" value="<?= $e($input['org_name']) ?>">
          <div class="hint">Printed on report headers.</div>
        </div>
      </div>

      <p style="margin-top:24px">
        <button class="btn" type="submit" <?= $requirementsOk ? '' : 'disabled' ?>>Install LRMS</button>
      </p>
      <p class="hint">
        Creates 40 tables, the roles, report types, settings and the four field forms.
        It will not touch a database that already has tables.
      </p>
    </form>
  </div>
<?php endif; ?>

</div>
</body>
</html>
