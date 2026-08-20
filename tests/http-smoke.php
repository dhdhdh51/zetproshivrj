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
use App\Core\Lang;

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

/* -------------------------------------------------------------------------- */
section('Language switching');
/* -------------------------------------------------------------------------- */

// Every English key must exist in Hindi. Without this the panel silently falls
// back to English on the labels someone forgot, which looks like a bug to the
// clerk using it and is invisible to whoever added the key.
$englishKeys = Lang::keys('en');
$hindiKeys = Lang::keys('hi');
$untranslated = array_diff($englishKeys, $hindiKeys);

ok(count($englishKeys) > 80, sprintf('The English catalogue is populated (%d keys)', count($englishKeys)));
ok(
    $untranslated === [],
    $untranslated === []
        ? sprintf('Every English key has a Hindi translation (%d keys)', count($englishKeys))
        : sprintf('Keys missing from hi.php: %s', implode(', ', array_slice($untranslated, 0, 6)))
);

$orphans = array_diff($hindiKeys, $englishKeys);
ok($orphans === [], $orphans === [] ? 'hi.php has no keys that English has dropped' : 'Stale Hindi keys: ' . implode(', ', $orphans));

// A :placeholder that survives in one language but not the other renders a
// literal ':time' on the page.
$placeholderMismatch = [];

foreach ($englishKeys as $key) {
    preg_match_all('/:([a-z_]+)/', Lang::get($key, [], 'en'), $enTokens);
    preg_match_all('/:([a-z_]+)/', Lang::get($key, [], 'hi'), $hiTokens);

    if (array_diff($enTokens[1], $hiTokens[1]) !== [] || array_diff($hiTokens[1], $enTokens[1]) !== []) {
        $placeholderMismatch[] = $key;
    }
}

ok(
    $placeholderMismatch === [],
    $placeholderMismatch === []
        ? 'Placeholders match across both languages'
        : 'Placeholder mismatch in: ' . implode(', ', $placeholderMismatch)
);

$blank = array_values(array_filter($hindiKeys, static fn (string $k): bool => trim(Lang::get($k, [], 'hi')) === ''));
ok($blank === [], $blank === [] ? 'No blank Hindi strings' : 'Blank Hindi strings: ' . implode(', ', $blank));

// And the switch has to actually work over HTTP, for a visitor who has not
// signed in yet — that is exactly who needs to change the language.
$toHindi = request($base . '/locale', [
    'post' => ['_token' => csrfToken($loginPage), 'locale' => 'hi'],
]);
equals(200, $toHindi['status'], 'Switching to Hindi succeeds while signed out');

$hindiLogin = page('/login', 'Login page in Hindi');
ok(str_contains($hindiLogin, 'साइन इन'), 'The login page renders in Hindi after switching');
ok(str_contains($hindiLogin, 'BCBF'), 'The Hindi login page still names the BCBF code');
ok(str_contains($hindiLogin, 'lang="hi"'), 'The document declares lang="hi" for screen readers');

$badLocale = request($base . '/locale', [
    'post' => ['_token' => csrfToken($hindiLogin), 'locale' => 'fr'],
]);
equals(200, $badLocale['status'], 'An unsupported language code is ignored, not fatal');
ok(str_contains(page('/login', 'Login page still Hindi'), 'साइन इन'), 'An unsupported code leaves the language unchanged');

// Back to English: the assertions that follow read English labels.
$backToEnglish = request($base . '/locale', [
    'post' => ['_token' => csrfToken($hindiLogin), 'locale' => 'en'],
]);
equals(200, $backToEnglish['status'], 'Switching back to English succeeds');
$loginPage = page('/login', 'Login page back in English');
ok(str_contains($loginPage, 'Sign in'), 'The login page renders in English again');

/* -------------------------------------------------------------------------- */
section('BCBF code sign-in');
/* -------------------------------------------------------------------------- */

// A BC Supervisor knows the BCBF code from their paperwork, not a username the
// office invented, so it has to sign them in. The panel then shows them the
// app-only notice, because recovery visits are recorded in the Android app.
$bcLogin = Database::selectOne(
    'SELECT u.id, s.bc_code
       FROM bc_supervisors s
       JOIN users u ON u.id = s.user_id
      WHERE u.status = \'active\'
      ORDER BY s.id
      LIMIT 1'
);

if ($bcLogin === null) {
    ok(false, 'No BC Supervisor seeded — cannot test BCBF-code sign-in');
} else {
    Database::update('users', [
        'password' => Auth::hashPassword('SmokeTest@123'),
        'must_change_password' => 0,
    ], 'id = :id', ['id' => (int) $bcLogin['id']]);

    ok(
        str_contains($loginPage, 'BCBF code'),
        'The login form tells BC Supervisors they can use their BCBF code'
    );

    $bcSignIn = request($base . '/login', [
        'post' => [
            '_token' => csrfToken($loginPage),
            'login' => (string) $bcLogin['bc_code'],
            'password' => 'SmokeTest@123',
        ],
    ]);
    equals(200, $bcSignIn['status'], 'Sign-in with the BCBF code succeeds');
    ok(!str_contains($bcSignIn['body'], 'do not match'), 'The BCBF code is accepted as a login identifier');
    ok(str_contains($bcSignIn['body'], 'Use the LRMS Android app'), 'A BC Supervisor is sent to the app-only notice');

    $loginPage = page('/login', 'Login page after BCBF sign-in');
    $bcLower = request($base . '/login', [
        'post' => [
            '_token' => csrfToken($loginPage),
            'login' => strtolower((string) $bcLogin['bc_code']),
            'password' => 'SmokeTest@123',
        ],
    ]);
    ok(!str_contains($bcLower['body'], 'do not match'), 'The BCBF code is matched case-insensitively');
}

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
    '/admin/inspections/register' => 'Inspection register',
    '/admin/sss' => 'SSS enrolments',
    '/admin/sss/create' => 'Record SSS enrolments',
    // Sidebar shortcuts that redirect into the reports; listed so a broken
    // redirect is caught here rather than by someone clicking the nav.
    '/admin/krm-ots' => 'KRM OTS shortcut',
    '/admin/ckcc' => 'CKCC OD-2 shortcut',
    '/admin/accounts/create' => 'Add loan account',
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
section('SSS enrolments can be recorded and corrected from the panel');
/* -------------------------------------------------------------------------- */

$sssSupervisor = Database::selectOne(
    'SELECT s.id, u.name FROM bc_supervisors s JOIN users u ON u.id = s.user_id
      WHERE s.branch_id IS NOT NULL ORDER BY s.id LIMIT 1'
);

if ($sssSupervisor === null) {
    ok(false, 'Need a BC Supervisor with a branch for the SSS panel test');
} else {
    $sssSupervisorId = (int) $sssSupervisor['id'];
    // Far enough back that nothing else in the suite has written these days.
    $sssDate = date('Y-m-d', strtotime('-5 days'));
    $sssAbsurdDate = date('Y-m-d', strtotime('-6 days'));

    // Start from a known state. A day is a natural key, so re-running the suite against
    // a database that already has these days would otherwise be testing yesterday's run.
    Database::delete(
        'sss_enrolments',
        'bc_supervisor_id = :bc AND enrolment_date IN (:a, :b)',
        ['bc' => $sssSupervisorId, 'a' => $sssDate, 'b' => $sssAbsurdDate]
    );

    $sssForm = page('/admin/sss/create', 'SSS record form');
    ok(str_contains($sssForm, 'PMJJBY'), 'The form asks for all four schemes by name');
    ok(
        str_contains($sssForm, 'Pradhan Mantri Suraksha Bima Yojana'),
        'Each abbreviation is spelled out, because PMJJBY and PMSBY are one letter apart'
    );

    $sssCreate = request($base . '/admin/sss', [
        'post' => [
            '_token' => csrfToken($sssForm),
            'bc_supervisor_id' => $sssSupervisorId,
            'enrolment_date' => $sssDate,
            'apy_count' => 3,
            'pmjjby_count' => 2,
            // Left blank on purpose: a scheme with no enrolments is not typed as a zero.
            'pmsby_count' => '',
            'pmjdy_count' => 4,
            'remarks' => 'Recorded from the panel during the smoke test.',
        ],
    ]);

    equals(200, $sssCreate['status'], 'Recording enrolments from the panel succeeds');

    $sssRow = Database::selectOne(
        'SELECT * FROM sss_enrolments WHERE bc_supervisor_id = :bc AND enrolment_date = :date',
        ['bc' => $sssSupervisorId, 'date' => $sssDate]
    );

    ok($sssRow !== null, 'The panel entry was stored');
    equals(0, (int) ($sssRow['pmsby_count'] ?? -1), 'The blank scheme was stored as none');
    equals(4, (int) ($sssRow['pmjdy_count'] ?? 0), 'The typed figures were stored');
    equals('panel', (string) ($sssRow['source'] ?? ''), 'The entry records that it came from the panel, not a handset');
    ok((int) ($sssRow['recorded_by'] ?? 0) > 0, 'The entry records who typed it');

    // Recording the same day again must not silently overwrite what is already there —
    // it hands the user the correction screen so overwriting is a deliberate act.
    $sssDuplicate = request($base . '/admin/sss', [
        'post' => [
            '_token' => csrfToken($sssForm),
            'bc_supervisor_id' => $sssSupervisorId,
            'enrolment_date' => $sssDate,
            'apy_count' => 99,
        ],
    ]);

    ok(
        str_contains($sssDuplicate['body'], 'Correct SSS enrolments'),
        'Recording a day that already has figures opens the correction screen instead'
    );
    equals(
        3,
        (int) Database::scalar(
            'SELECT apy_count FROM sss_enrolments WHERE bc_supervisor_id = :bc AND enrolment_date = :date',
            ['bc' => $sssSupervisorId, 'date' => $sssDate]
        ),
        'The second attempt did not quietly overwrite the figures already recorded'
    );
    equals(
        1,
        (int) Database::scalar(
            'SELECT COUNT(*) FROM sss_enrolments WHERE bc_supervisor_id = :bc AND enrolment_date = :date',
            ['bc' => $sssSupervisorId, 'date' => $sssDate]
        ),
        'The second attempt did not create a second row for the day'
    );

    $sssEditPage = page('/admin/sss/' . (int) $sssRow['id'] . '/edit', 'SSS correction form');
    ok(
        !str_contains($sssEditPage, 'name="enrolment_date"'),
        'The date is frozen on the correction screen — a day belongs to one supervisor'
    );

    $sssUpdate = request($base . '/admin/sss/' . (int) $sssRow['id'], [
        'post' => [
            '_token' => csrfToken($sssEditPage),
            'apy_count' => 7,
            'pmjjby_count' => 0,
            'pmsby_count' => 1,
            'pmjdy_count' => 0,
            'remarks' => 'Corrected after checking the register.',
        ],
    ]);

    equals(200, $sssUpdate['status'], 'Correcting a day succeeds');
    equals(
        8,
        (int) Database::scalar(
            'SELECT apy_count + pmjjby_count + pmsby_count + pmjdy_count FROM sss_enrolments
              WHERE bc_supervisor_id = :bc AND enrolment_date = :date',
            ['bc' => $sssSupervisorId, 'date' => $sssDate]
        ),
        'The correction replaced the whole day rather than merging into it'
    );

    // The backdating window is a setting because the app is offline-first. A setting an
    // Admin cannot reach is a constant with extra steps, so the screen has to offer it.
    ok(
        str_contains(page('/admin/settings', 'Settings screen'), 'name="sss_backdate_days"'),
        'The SSS backdating window can be changed from the settings screen'
    );

    $sssList = page('/admin/sss?from=' . $sssDate . '&to=' . $sssDate, 'SSS list for the recorded day');
    ok(str_contains($sssList, 'Total enrolments'), 'The list shows the totals strip');
    ok(str_contains($sssList, (string) $sssSupervisor['name']), 'The list names the supervisor the day belongs to');

    // An absurd figure is a typing mistake, and the form should say so rather than store it.
    $sssAbsurd = request($base . '/admin/sss', [
        'post' => [
            '_token' => csrfToken($sssForm),
            'bc_supervisor_id' => $sssSupervisorId,
            'enrolment_date' => $sssAbsurdDate,
            'apy_count' => 5000,
        ],
    ]);

    ok(
        (int) Database::scalar(
            'SELECT COUNT(*) FROM sss_enrolments WHERE bc_supervisor_id = :bc AND enrolment_date = :date',
            ['bc' => $sssSupervisorId, 'date' => $sssAbsurdDate]
        ) === 0,
        'An absurd figure is refused rather than stored'
    );
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
section('Adding a loan account by hand');
/* -------------------------------------------------------------------------- */

// Not every account arrives in the monthly extract. This posts the real form, so
// a field the controller forgets to save fails here rather than turning up as a
// blank on a printed verification report.
$accountForm = page('/admin/accounts/create', 'Add loan account form');

foreach (['account_number', 'borrower_name', 'branch_id', 'father_name', 'mobile', 'village',
    'outstanding', 'overdue', 'npa_date', 'asset_classification', 'loan_category'] as $field) {
    ok(
        str_contains($accountForm, 'name="' . $field . '"'),
        'Manual account form offers the ' . $field . ' field'
    );
}

$formBranchId = (int) Database::scalar("SELECT id FROM branches WHERE status = 'active' ORDER BY id LIMIT 1");
$manualNumber = 'MANUAL-' . substr((string) time(), -6);

$created = request($base . '/admin/accounts', [
    'post' => [
        '_token' => csrfToken($accountForm),
        'account_number' => $manualNumber,
        'borrower_name' => 'Hand Entered Borrower',
        'branch_id' => (string) $formBranchId,
        'loan_category' => 'general',
        'father_name' => 'Test Father',
        'mobile' => '9876500001',
        'gender' => 'male',
        'village' => 'Testpur',
        'district' => 'Jaipur',
        'state' => 'Rajasthan',
        'loan_type' => 'KCC',
        'sanction_date' => '2020-05-01',
        'npa_date' => '2023-03-31',
        'outstanding' => '123456.78',
        'overdue' => '23456.78',
        'limit_amount' => '130000',
        'asset_classification' => 'npa',
        'allocation_mode' => 'auto',
    ],
]);

equals(200, $created['status'], 'Posting the manual account form succeeds');

$manualAccount = Database::selectOne(
    'SELECT * FROM loan_accounts WHERE account_number = :n',
    ['n' => $manualNumber]
);

ok($manualAccount !== null, 'The hand-entered account is on the loan book');

if ($manualAccount !== null) {
    equals('Hand Entered Borrower', (string) $manualAccount['borrower_name'], 'Borrower name saved');
    equals('Test Father', (string) $manualAccount['father_name'], 'Father name saved');
    equals('male', (string) $manualAccount['gender'], 'Gender saved');
    equals('2023-03-31', (string) $manualAccount['npa_date'], 'NPA date saved');
    equals('123456.78', (string) $manualAccount['outstanding'], 'Outstanding saved');
    equals('npa', (string) $manualAccount['asset_classification'], 'Asset classification saved');

    // Same defaults an imported row gets — the two routes must not diverge.
    equals('active', (string) $manualAccount['status'], 'A hand-entered account starts active');
    equals('pending', (string) $manualAccount['recovery_status'], 'A hand-entered account starts pending');
    equals(null, $manualAccount['excel_import_id'], 'No import id, which marks it as hand-entered');

    ok(
        (int) Database::scalar(
            'SELECT COUNT(*) FROM account_assignments WHERE loan_account_id = :id AND is_active = 1',
            ['id' => (int) $manualAccount['id']]
        ) === 1,
        'Auto-allocation put the new account with a supervisor'
    );
}

// The same number twice must be refused rather than creating a second row that
// splits one borrower's visits across two accounts.
$accountForm = page('/admin/accounts/create', 'Add account form reload');
$duplicate = request($base . '/admin/accounts', [
    'post' => [
        '_token' => csrfToken($accountForm),
        'account_number' => $manualNumber,
        'borrower_name' => 'Someone Else',
        'branch_id' => (string) $formBranchId,
        'loan_category' => 'general',
        'allocation_mode' => 'none',
    ],
]);

ok(
    str_contains($duplicate['body'], 'already on the loan book'),
    'A duplicate account number is refused with an explanation'
);

equals(
    1,
    (int) Database::scalar('SELECT COUNT(*) FROM loan_accounts WHERE account_number = :n', ['n' => $manualNumber]),
    'The duplicate did not create a second row'
);

$accountForm = page('/admin/accounts/create', 'Add account form reload 2');
$missing = request($base . '/admin/accounts', [
    'post' => [
        '_token' => csrfToken($accountForm),
        'account_number' => 'MANUAL-NO-NAME',
        'borrower_name' => '',
        'branch_id' => (string) $formBranchId,
        'loan_category' => 'general',
    ],
]);

equals(
    0,
    (int) Database::scalar(
        'SELECT COUNT(*) FROM loan_accounts WHERE account_number = :n',
        ['n' => 'MANUAL-NO-NAME']
    ),
    'An account with no borrower name is not created'
);

/* -------------------------------------------------------------------------- */
section('Import detail page, including the rejected rows');
/* -------------------------------------------------------------------------- */

// This page was never opened by the suite, and it carried a query that MariaDB
// refuses outright — `row_number` reads as the ROW_NUMBER() window function
// unless it is quoted. Every screen that lists rejected rows is exercised here,
// with a row present, so the query really runs.
$errorImportId = (int) Database::insert('excel_imports', [
    'user_id' => $adminId,
    'original_name' => 'smoke-rejected-rows.xlsx',
    'stored_path' => 'uploads/imports/smoke-rejected-rows.xlsx',
    'file_size' => 2048,
    'sheet_name' => 'Sheet1',
    'header_row' => 1,
    'detected_headers' => json_encode(['A/C No', 'Name']),
    'mapping' => json_encode(['account_number' => 'A/C No', 'borrower_name' => 'Name']),
    'status' => 'completed',
    'total_rows' => 3,
    'imported_rows' => 1,
    'error_rows' => 2,
    'created_at' => date('Y-m-d H:i:s'),
    'updated_at' => date('Y-m-d H:i:s'),
]);

foreach ([
    [7, 'missing_required', 'error', 'Account Number is empty.'],
    [9, 'unknown_branch', 'warning', 'Branch "ZZZ" is not set up in LRMS.'],
] as [$row, $type, $severity, $message]) {
    Database::insert('excel_import_errors', [
        'import_id' => $errorImportId,
        'row_number' => $row,
        'error_type' => $type,
        'severity' => $severity,
        'message' => $message,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}

$importDetail = page('/admin/imports/' . $errorImportId, 'Import detail with rejected rows');
ok(str_contains($importDetail, 'Account Number is empty.'), 'The rejected row and its reason are listed');
ok(str_contains($importDetail, 'not set up in LRMS'), 'A warning row is listed too');

// And the detail page of a real import from the earlier suite run, if there is one.
$realImportId = (int) Database::scalar('SELECT MAX(id) FROM excel_imports WHERE status = :s', ['s' => 'completed']);

if ($realImportId > 0 && $realImportId !== $errorImportId) {
    page('/admin/imports/' . $realImportId, 'Import detail for a completed import');
}

// Generated exports are streamed through an authorisation check rather than being
// served from the web root, and that route had no coverage either.
$export = Database::selectOne('SELECT id, file_name FROM report_exports ORDER BY id DESC LIMIT 1');

if ($export === null) {
    ok(false, 'No report export was produced earlier — cannot test authorised file download');
} else {
    $download = request($base . '/files/export/' . (int) $export['id']);
    equals(200, $download['status'], 'A generated export downloads through the authorised route');
    ok(strlen($download['body']) > 100, 'The downloaded export has content');
}

/* -------------------------------------------------------------------------- */
section('The sample import sheet downloads');
/* -------------------------------------------------------------------------- */

$sampleXlsx = request($base . '/admin/imports/sample');
equals(200, $sampleXlsx['status'], 'Sample .xlsx downloads');
ok(str_starts_with($sampleXlsx['body'], "PK"), 'Sample .xlsx really is a zip-based workbook');
ok(strlen($sampleXlsx['body']) > 1000, 'Sample .xlsx has content');

$sampleCsv = request($base . '/admin/imports/sample?format=csv');
equals(200, $sampleCsv['status'], 'Sample .csv downloads');
ok(str_contains($sampleCsv['body'], 'Account Number'), 'Sample .csv carries the column headings');
ok(str_contains($sampleCsv['body'], 'SAMPLE-0001'), 'Sample .csv carries the demo rows');

$importPage = page('/admin/imports/create', 'Upload screen offers the sample');
ok(str_contains($importPage, '/admin/imports/sample'), 'The upload screen links to the sample sheet');

/* -------------------------------------------------------------------------- */
section('BC creation form saves the full profile');
/* -------------------------------------------------------------------------- */

// The BC creation screen collects the identity the field visit verification
// report prints. This posts the real form, so a field that is not wired through
// the controller shows up here rather than as a blank on a printed report.
$createPage = page('/admin/supervisors/create', 'Add BC supervisor');

foreach (['sp_cbc_name', 'ssa', 'iibf_number', 'dra_id', 'designation', 'aadhaar_number',
    'pan_number', 'block', 'tehsil', 'district', 'state', 'pincode'] as $field) {
    ok(
        str_contains($createPage, 'name="' . $field . '"'),
        'BC creation form offers the ' . $field . ' field'
    );
}

$branchForBc = (int) Database::scalar('SELECT id FROM branches ORDER BY id LIMIT 1');
$bcCode = 'SMOKE' . random_int(100000, 999999);

$created = request($base . '/admin/supervisors', [
    'post' => [
        '_token' => csrfToken($createPage),
        'name' => 'CHANDRA SHEKHAR',
        'bc_code' => $bcCode,
        'branch_id' => (string) $branchForBc,
        'mobile' => '7417343844',
        'username' => 'smoke-bc-' . $bcCode,
        'email' => strtolower($bcCode) . '@example.test',
        'sp_cbc_name' => 'FIA TECHNOLOGY SERVICES PVT. LTD',
        'ssa' => 'PATIYALI SSA',
        'iibf_number' => '8014017889',
        'dra_id' => 'DRA-9001',
        'designation' => 'BC Agent',
        // Deliberately a full Aadhaar number: only the last four may be stored.
        'aadhaar_number' => '1234 5678 9012',
        'pan_number' => 'abcde1234f',
        'address' => 'NAGLA FULU',
        'village' => 'NAGLA FULU',
        'block' => 'PATIYALI BLOCK',
        'tehsil' => 'PATIYALI',
        'district' => 'KASGANJ',
        'state' => 'UP',
        'pincode' => '207248',
    ],
]);

ok(in_array($created['status'], [200, 302], true), 'BC creation form accepted (HTTP ' . $created['status'] . ')');

$newBc = Database::selectOne(
    'SELECT s.*, u.name, u.email FROM bc_supervisors s JOIN users u ON u.id = s.user_id WHERE s.bc_code = :code',
    ['code' => $bcCode]
);

if (ok($newBc !== null, 'BC Supervisor created through the form')) {
    equals('FIA TECHNOLOGY SERVICES PVT. LTD', (string) $newBc['sp_cbc_name'], 'SP / CBC name saved');
    equals('PATIYALI SSA', (string) $newBc['ssa'], 'SSA saved');
    equals('8014017889', (string) $newBc['iibf_number'], 'IIBF number saved');
    equals('DRA-9001', (string) $newBc['dra_id'], 'DRA ID saved');
    equals('BC Agent', (string) $newBc['designation'], 'Designation saved');
    equals('9012', (string) $newBc['aadhaar_last4'], 'Only the last four Aadhaar digits were stored');
    equals('ABCDE1234F', (string) $newBc['pan_number'], 'PAN upper-cased on save');
    equals('PATIYALI BLOCK', (string) $newBc['block'], 'Block saved');
    equals('PATIYALI', (string) $newBc['tehsil'], 'Tehsil saved');
    equals('KASGANJ', (string) $newBc['district'], 'District saved');
    equals('UP', (string) $newBc['state'], 'State saved');
    equals('207248', (string) $newBc['pincode'], 'Pincode saved');

    // The edit screen must show what was saved, including the masked Aadhaar.
    $editPage = page('/admin/supervisors/' . (int) $newBc['id'] . '/edit', 'Edit BC supervisor');

    ok(str_contains($editPage, 'FIA TECHNOLOGY SERVICES PVT. LTD'), 'Edit form shows the saved SP / CBC name');
    ok(str_contains($editPage, 'XXXX-XXXX-9012'), 'Edit form shows Aadhaar masked to the last four digits');
    ok(!str_contains($editPage, '123456789012'), 'The full Aadhaar number is never rendered');

    // Re-saving the masked value must not corrupt it.
    $updated = request($base . '/admin/supervisors/' . (int) $newBc['id'], [
        'post' => [
            '_token' => csrfToken($editPage),
            'name' => 'CHANDRA SHEKHAR',
            'bc_code' => $bcCode,
            'branch_id' => (string) $branchForBc,
            'mobile' => '7417343844',
            'username' => 'smoke-bc-' . $bcCode,
            'status' => 'active',
            'aadhaar_number' => 'XXXX-XXXX-9012',
            'pan_number' => 'ABCDE1234F',
            'sp_cbc_name' => 'FIA TECHNOLOGY SERVICES PVT. LTD',
        ],
    ]);

    ok(in_array($updated['status'], [200, 302], true), 'BC edit form accepted');
    equals(
        '9012',
        (string) Database::scalar('SELECT aadhaar_last4 FROM bc_supervisors WHERE id = :id', ['id' => (int) $newBc['id']]),
        'Re-saving the masked Aadhaar keeps the same four digits'
    );

    // A malformed PAN must be refused rather than stored.
    $badPan = request($base . '/admin/supervisors/' . (int) $newBc['id'], [
        'post' => [
            '_token' => csrfToken(page('/admin/supervisors/' . (int) $newBc['id'] . '/edit', 'Edit BC supervisor again')),
            'name' => 'CHANDRA SHEKHAR',
            'bc_code' => $bcCode,
            'branch_id' => (string) $branchForBc,
            'mobile' => '7417343844',
            'username' => 'smoke-bc-' . $bcCode,
            'status' => 'active',
            'pan_number' => 'NOTAPAN',
        ],
    ]);

    ok(in_array($badPan['status'], [200, 302], true), 'A malformed PAN is handled without an error page');
    equals(
        'ABCDE1234F',
        (string) Database::scalar('SELECT pan_number FROM bc_supervisors WHERE id = :id', ['id' => (int) $newBc['id']]),
        'A malformed PAN does not overwrite the stored one'
    );
}

/* The branch form must offer Zone, which the report header prints. */
$branchCreate = page('/admin/branches/create', 'Add branch');
ok(str_contains($branchCreate, 'name="zone"'), 'Branch form offers the zone field');

/* -------------------------------------------------------------------------- */
section('The browser installer refuses to run on an installed site');
/* -------------------------------------------------------------------------- */

// public/install.php can write the configuration and build the database, so on a
// live site it must be inert. This database is seeded, so it has to refuse.
$installer = request($base . '/install.php');

if ($installer['status'] === 200) {
    ok(
        str_contains($installer['body'], 'Already installed'),
        'Installer reports the site is already installed'
    );
    ok(
        !str_contains($installer['body'], '<form method="post"'),
        'Installer hides its form on an installed site'
    );

    // Compare the configuration before and after, rather than against a fixed
    // database name: this suite runs against whatever database the environment
    // provides, and CI's is not the same as a developer's.
    $configFile = dirname(__DIR__) . '/config/config.local.php';
    $configFile = is_file($configFile) ? $configFile : dirname(__DIR__) . '/config/config.php';
    $configBefore = is_file($configFile) ? (string) md5_file($configFile) : '';

    // A POST straight at it must not get past the guard either.
    $attack = request($base . '/install.php', [
        'post' => [
            'db_host' => 'localhost',
            'db_name' => 'attacker_db',
            'db_user' => 'attacker',
            'admin_email' => 'attacker@example.com',
            'admin_username' => 'attacker',
            'admin_password' => 'Attacker123',
            'timezone' => 'Asia/Kolkata',
        ],
    ]);

    ok(
        str_contains($attack['body'], 'Already installed'),
        'Installer refuses a POST on an installed site'
    );

    clearstatcache(true, $configFile);

    equals(
        $configBefore,
        is_file($configFile) ? (string) md5_file($configFile) : '',
        'The configuration file was not rewritten by the installer'
    );
    ok(
        !str_contains(is_file($configFile) ? (string) file_get_contents($configFile) : '', 'attacker_db'),
        'The site was not repointed at another database'
    );
    ok(
        (int) Database::scalar("SELECT COUNT(*) FROM users WHERE username = 'attacker'") === 0,
        'No account was created by the installer POST'
    );
} else {
    ok(true, 'install.php is not present (already removed) — nothing to guard');
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
        '/manager/performance' => 'Branch performance',
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
