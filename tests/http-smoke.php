<?php

declare(strict_types=1);

/**
 * HTTP smoke test: boots `php -S`, signs in as each role and walks every screen,
 * asserting status codes and that no PHP error leaked into the HTML.
 *
 *   php tests/http-smoke.php [base-url]
 *
 * With no argument the script starts its own server on 127.0.0.1:8099.
 */

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

use App\Core\Auth;
use App\Core\Database;

$base = $argv[1] ?? null;
$serverProcess = null;

if ($base === null) {
    $base = 'http://127.0.0.1:8099';
    $descriptors = [1 => ['file', '/tmp/lrms-server.log', 'a'], 2 => ['file', '/tmp/lrms-server.log', 'a']];
    // LRMS_APP_URL keeps the app's generated links pointing at the test server.
    $serverProcess = proc_open(
        sprintf(
            'LRMS_APP_URL=%s php -S 127.0.0.1:8099 -t %s %s',
            escapeshellarg($base),
            escapeshellarg(base_path('public')),
            escapeshellarg(base_path('public/index.php'))
        ),
        $descriptors,
        $pipes
    );

    // Wait for the server to accept connections.
    for ($i = 0; $i < 40; $i++) {
        $socket = @fsockopen('127.0.0.1', 8099, $errno, $errstr, 0.3);

        if ($socket !== false) {
            fclose($socket);
            break;
        }

        usleep(250000);
    }
}

$cookieJar = tempnam(sys_get_temp_dir(), 'lrms-cookies');

/**
 * @return array{status:int, body:string, headers:string}
 */
function request(string $url, array $options = []): array
{
    global $cookieJar;

    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => $options['follow'] ?? true,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_HTTPHEADER => $options['headers'] ?? [],
    ]);

    if (isset($options['post'])) {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt(
            $handle,
            CURLOPT_POSTFIELDS,
            is_array($options['post']) ? http_build_query($options['post']) : $options['post']
        );
    }

    $response = (string) curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    curl_close($handle);

    return [
        'status' => $status,
        'headers' => substr($response, 0, $headerSize),
        'body' => substr($response, $headerSize),
    ];
}

function csrfToken(string $html): string
{
    return preg_match('/name="_token" value="([^"]+)"/', $html, $m) === 1 ? $m[1] : '';
}

/**
 * A page is only "ok" when the status matches AND no PHP notice/warning/fatal
 * text leaked into the response.
 */
function page(string $path, string $label, int $expected = 200): string
{
    global $base;

    $response = request($base . $path);
    $body = $response['body'];

    $leaked = preg_match('/(Fatal error|Parse error|Warning:|Notice:|Uncaught|Undefined (variable|array key|property)|SQLSTATE)/i', $body) === 1;

    if ($response['status'] !== $expected) {
        ok(false, sprintf('%s (%s) — expected HTTP %d, got %d', $label, $path, $expected, $response['status']));
    } elseif ($leaked) {
        preg_match('/(Fatal error|Parse error|Warning:|Notice:|Uncaught|Undefined [a-z ]+|SQLSTATE)[^<\n]{0,160}/i', $body, $m);
        ok(false, sprintf('%s (%s) — PHP error leaked: %s', $label, $path, trim($m[0] ?? '')));
    } else {
        ok(true, sprintf('%s (HTTP %d)', $label, $response['status']));
    }

    return $body;
}

/* -------------------------------------------------------------------------- */
section('Public endpoints');
/* -------------------------------------------------------------------------- */

$health = request($base . '/health');
$decoded = json_decode($health['body'], true);
equals(200, $health['status'], 'GET /health responds 200');
ok(is_array($decoded) && ($decoded['status'] ?? '') === 'ok', 'Health check reports ok');

$loginPage = page('/login', 'Login page');
ok(str_contains($loginPage, 'name="_token"'), 'Login form carries a CSRF token');
page('/app-only', 'BC Supervisor app-only notice');
page('/no-such-page', 'Unknown URL returns 404', 404);

// CSRF must be enforced.
$noToken = request($base . '/login', ['post' => ['login' => 'admin@lrms.local', 'password' => 'x']]);
equals(419, $noToken['status'], 'POST without a CSRF token is refused (419)');

/* -------------------------------------------------------------------------- */
section('Admin/Supervisor sign-in');
/* -------------------------------------------------------------------------- */

// Reset the seeded admin to a known password without the forced change.
$adminId = (int) Database::scalar("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.slug = 'admin' LIMIT 1");
Database::update('users', [
    'password' => Auth::hashPassword('SmokeTest@123'),
    'must_change_password' => 0,
    'failed_attempts' => 0,
    'locked_until' => null,
], 'id = :id', ['id' => $adminId]);

$adminEmail = (string) Database::scalar('SELECT email FROM users WHERE id = :id', ['id' => $adminId]);

$badLogin = request($base . '/login', [
    'post' => ['_token' => csrfToken($loginPage), 'login' => $adminEmail, 'password' => 'wrong-password'],
]);
ok(str_contains($badLogin['body'], 'do not match'), 'Wrong password is rejected');

$loginPage = page('/login', 'Login page reload');
$signIn = request($base . '/login', [
    'post' => ['_token' => csrfToken($loginPage), 'login' => $adminEmail, 'password' => 'SmokeTest@123'],
]);
equals(200, $signIn['status'], 'Admin sign-in succeeds');
ok(str_contains($signIn['body'], 'Recovery overview'), 'Admin lands on the dashboard');

/* -------------------------------------------------------------------------- */
section('Admin panel screens');
/* -------------------------------------------------------------------------- */

$screens = [
    '/admin' => 'Dashboard',
    '/admin/accounts' => 'Loan accounts',
    '/admin/accounts?allocation=unassigned' => 'Accounts filtered by allocation',
    '/admin/allocation' => 'Allocation',
    '/admin/imports' => 'Import history',
    '/admin/imports/create' => 'Upload Excel',
    '/admin/branches' => 'Branches',
    '/admin/branches/create' => 'Add branch',
    '/admin/managers' => 'Branch managers',
    '/admin/managers/create' => 'Add branch manager',
    '/admin/supervisors' => 'BC supervisors',
    '/admin/supervisors/create' => 'Add BC supervisor',
    '/admin/inspections' => 'BC inspections',
    '/admin/inspections/create' => 'Start inspection (chooser)',
    '/admin/monitoring' => 'Live monitoring',
    '/admin/targets' => 'Targets',
    '/admin/deadline' => 'Report deadline',
    '/admin/deadline/late' => 'Late submissions',
    '/admin/reports' => 'Reports index',
    '/admin/forms/visit' => 'Visit form builder',
    '/admin/forms/inspection' => 'Inspection form builder',
    '/admin/settings' => 'Settings',
    '/admin/audit' => 'Audit log',
    '/admin/notifications' => 'Notifications',
    '/password/change' => 'Change password',
];

foreach ($screens as $path => $label) {
    page($path, $label);
}

/* -------------------------------------------------------------------------- */
section('Every report renders and exports');
/* -------------------------------------------------------------------------- */

foreach (array_keys(App\Services\Reports::catalogue()) as $slug) {
    page('/admin/reports/' . $slug, 'Report: ' . $slug);
}

// Exports redirect to the download endpoint; follow through to the file.
foreach (['csv', 'excel', 'pdf'] as $format) {
    $response = request($base . '/admin/reports/customer_visit/export/' . $format);

    if ($response['status'] !== 200) {
        ok(false, sprintf('Export customer_visit as %s (HTTP %d)', $format, $response['status']));
        continue;
    }

    $length = strlen($response['body']);
    $valid = match ($format) {
        'pdf' => str_starts_with($response['body'], '%PDF-'),
        'excel' => str_starts_with($response['body'], "PK"),
        default => $length > 0,
    };

    ok($valid && $length > 100, sprintf('Export customer_visit as %s (%d bytes)', $format, $length));
}

/* -------------------------------------------------------------------------- */
section('Records: account, visit, inspection');
/* -------------------------------------------------------------------------- */

$accountId = (int) Database::scalar('SELECT id FROM loan_accounts ORDER BY id LIMIT 1');

if ($accountId > 0) {
    page('/admin/accounts/' . $accountId, 'Account detail');
}

$visitId = (int) Database::scalar("SELECT id FROM visits WHERE status <> 'draft' ORDER BY id LIMIT 1");

if ($visitId > 0) {
    page('/admin/visits/' . $visitId, 'Visit report');

    $pdf = request($base . '/admin/visits/' . $visitId . '/pdf');
    ok(
        $pdf['status'] === 200 && str_starts_with($pdf['body'], '%PDF-'),
        sprintf('Visit report PDF (%d bytes)', strlen($pdf['body']))
    );
} else {
    ok(true, 'No submitted visits to open (skipped)');
}

$inspectionId = (int) Database::scalar("SELECT id FROM inspections WHERE status = 'submitted' ORDER BY id LIMIT 1");

if ($inspectionId > 0) {
    page('/admin/inspections/' . $inspectionId, 'Inspection report');

    $pdf = request($base . '/admin/inspections/' . $inspectionId . '/pdf');
    ok(
        $pdf['status'] === 200 && str_starts_with($pdf['body'], '%PDF-'),
        sprintf('Inspection report PDF (%d bytes)', strlen($pdf['body']))
    );
} else {
    ok(true, 'No submitted inspections to open (skipped)');
}

$supervisorId = (int) Database::scalar('SELECT id FROM bc_supervisors ORDER BY id LIMIT 1');

if ($supervisorId > 0) {
    page('/admin/inspections/supervisor/' . $supervisorId, 'Supervisor work picture');
    page('/admin/inspections/create?bc_supervisor_id=' . $supervisorId, 'Start inspection (with supervisor)');
    page('/admin/monitoring/route/' . $supervisorId, 'Supervisor route');
}

/* -------------------------------------------------------------------------- */
section('Branch Manager portal and branch isolation');
/* -------------------------------------------------------------------------- */

request($base . '/logout', ['post' => ['_token' => csrfToken(page('/admin', 'Dashboard before logout'))]]);

$manager = Database::selectOne(
    "SELECT u.id, u.email, u.branch_id FROM users u JOIN roles r ON r.id = u.role_id
      WHERE r.slug = 'branch_manager' AND u.branch_id IS NOT NULL LIMIT 1"
);

if ($manager === null) {
    ok(false, 'No branch manager seeded — cannot test the portal');
} else {
    Database::update('users', [
        'password' => Auth::hashPassword('SmokeTest@123'),
        'must_change_password' => 0,
    ], 'id = :id', ['id' => (int) $manager['id']]);

    $loginPage = page('/login', 'Login page for manager');
    $signIn = request($base . '/login', [
        'post' => ['_token' => csrfToken($loginPage), 'login' => (string) $manager['email'], 'password' => 'SmokeTest@123'],
    ]);

    ok($signIn['status'] === 200, 'Branch Manager sign-in succeeds');

    foreach ([
        '/manager' => 'Branch dashboard',
        '/manager/accounts' => 'Branch accounts',
        '/manager/supervisors' => 'Branch supervisors',
        '/manager/pending' => 'Pending accounts',
        '/manager/recovery' => 'Recovery and PTP',
        '/manager/monitoring' => 'Branch monitoring',
        '/manager/reports' => 'Branch reports',
        '/manager/reports/customer_visit' => 'Branch visit report',
        '/manager/notifications' => 'Branch notifications',
    ] as $path => $label) {
        page($path, $label);
    }

    // The admin panel must be closed to a manager.
    $forbidden = request($base . '/admin');
    equals(403, $forbidden['status'], 'Branch Manager cannot open the admin panel');

    // An account from another branch must not be readable.
    $otherAccount = (int) Database::scalar(
        'SELECT id FROM loan_accounts WHERE branch_id <> :branch LIMIT 1',
        ['branch' => (int) $manager['branch_id']]
    );

    if ($otherAccount > 0) {
        $response = request($base . '/manager/accounts/' . $otherAccount);
        equals(403, $response['status'], 'Cross-branch account access is refused (403)');
    } else {
        ok(true, 'Only one branch has accounts (cross-branch check skipped)');
    }

    // Reports must be silently scoped, never leaking other branches.
    $body = page('/manager/reports/branch_performance', 'Branch performance report');
    $otherBranchName = (string) Database::scalar(
        "SELECT name FROM branches WHERE id <> :branch AND status = 'active' LIMIT 1",
        ['branch' => (int) $manager['branch_id']]
    );

    if ($otherBranchName !== '') {
        ok(
            !str_contains($body, $otherBranchName),
            'Branch performance report shows only the manager\'s own branch'
        );
    }
}

/* -------------------------------------------------------------------------- */
section('Unauthenticated access is refused');
/* -------------------------------------------------------------------------- */

request($base . '/logout', ['post' => ['_token' => csrfToken(page('/manager', 'Portal before logout'))]]);
@unlink($cookieJar);

foreach (['/admin', '/admin/accounts', '/manager', '/admin/reports/recovery'] as $path) {
    $response = request($base . $path, ['follow' => false]);
    ok(
        in_array($response['status'], [302, 401, 403], true),
        sprintf('Anonymous %s is redirected or refused (HTTP %d)', $path, $response['status'])
    );
}

if ($serverProcess !== null) {
    proc_terminate($serverProcess);
}

exit(TestRunner::summary());
