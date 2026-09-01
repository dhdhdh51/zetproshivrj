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
page('/app-only', 'BCA app-only notice');
page('/no-such-page', 'Unknown URL returns 404', 404);

// CSRF must be enforced.
$noToken = request($base . '/login', ['post' => ['login' => 'admin@lrms.local', 'password' => 'x']]);
equals(419, $noToken['status'], 'POST without a CSRF token is refused (419)');

/* -------------------------------------------------------------------------- */
section('BC Supervisor sign-in');
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

// A BCA knows the BCBF code from their paperwork, not a username the
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
    ok(false, 'No BCA seeded — cannot test BCBF-code sign-in');
} else {
    Database::update('users', [
        'password' => Auth::hashPassword('SmokeTest@123'),
        'must_change_password' => 0,
    ], 'id = :id', ['id' => (int) $bcLogin['id']]);

    ok(
        str_contains($loginPage, 'BCBF code'),
        'The login form tells BCAs they can use their BCBF code'
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
    ok(str_contains($bcSignIn['body'], 'Use the LRMS Android app'), 'A BCA is sent to the app-only notice');

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
    '/admin/supervisors' => 'BCAs',
    '/admin/supervisors/create' => 'Add BCA',
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
    ok(false, 'Need a BCA with a branch for the SSS panel test');
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

    // The backdating window is a setting because the app is offline-first. A setting a BC
    // Supervisor cannot reach is a constant with extra steps, so the screen has to offer it.
    $settingsScreen = page('/admin/settings', 'Settings screen');
    ok(
        str_contains($settingsScreen, 'name="sss_backdate_days"'),
        'The SSS backdating window can be changed from the settings screen'
    );

    /*
     * The office printed at the foot of the inspection report. It moved from the Bhopal zonal
     * office to the Agra regional office, and an office moving must not need a developer — so
     * the screen has to offer all five lines, and saving them has to stick.
     */
    foreach (['office_name', 'office_address', 'office_phone', 'office_email', 'office_helpline'] as $key) {
        ok(str_contains($settingsScreen, 'name="' . $key . '"'), 'The settings screen offers ' . $key);
    }

    ok(
        str_contains($settingsScreen, 'Sanjay Place'),
        'And shows the office that is actually being printed, not empty boxes'
    );

    /*
     * Saving has to round-trip through the real form.
     *
     * The whole settings screen is one form and saveSettings() reads every group from it, so a
     * post that leaves a field out is a post that clears it — an absent checkbox is an
     * unticked checkbox. Posting only the office fields switched watermarking off and broke a
     * later suite. So the current values are sent back with them, which is what the browser
     * does.
     */
    $current = App\Core\Settings::all();

    $officePost = [
        '_token' => csrfToken($settingsScreen),
        'office_name' => 'Central Bank of India — Regional Office, Agra',
        'office_address' => '37/2/4, First Floor, Sanjay Place, Agra',
        'office_phone' => '0562-2521342',
        'office_email' => 'rdagraro@centralbank.bank.in',
        'office_helpline' => '1800 233 4035',
    ];

    foreach ([
        'site_name', 'organisation_name', 'default_locale', 'supervisor_offline_minutes',
        'min_visit_photos', 'min_inspection_photos', 'sss_backdate_days', 'payment_modes',
        'gps_max_accuracy_metres', 'gps_max_drift_metres', 'api_token_ttl_days',
        'sms_endpoint', 'sms_sender_id',
    ] as $key) {
        $officePost[$key] = (string) ($current[$key] ?? '');
    }

    // A checkbox is sent only when it is ticked, so anything currently on has to be named.
    foreach ([
        'maintenance_mode', 'watermark_photos', 'gps_mock_location_allowed',
        'otp_web_login', 'otp_app_login', 'device_binding', 'sms_enabled',
    ] as $key) {
        if ((string) ($current[$key] ?? '0') === '1') {
            $officePost[$key] = '1';
        }
    }

    $officeSave = request($base . '/admin/settings', ['post' => $officePost]);

    equals(200, $officeSave['status'], 'The office letterhead saves');

    App\Core\Settings::flush();
    equals(
        '0562-2521342',
        (string) setting('office_phone', ''),
        'And the saved phone number is what the form will print'
    );
    equals(
        (string) ($current['watermark_photos'] ?? '1'),
        (string) setting('watermark_photos', '1'),
        'Saving the office left the rest of the settings alone'
    );

    $sssList = page('/admin/sss?from=' . $sssDate . '&to=' . $sssDate, 'SSS list for the recorded day');
    ok(str_contains($sssList, 'Achievement'), 'The list shows the totals strip');
    // The strip is a comparison now, not a count: the figures only mean something next to
    // the target the BC Supervisor set, so both halves have to be on the screen.
    ok(str_contains($sssList, 'Target'), 'The list shows the target the figures are measured against');
    ok(str_contains($sssList, 'Gap'), 'The list shows the gap left to close');
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
section('SSS targets are the BC Supervisor\'s, and a submitted day only reopens for a BC Supervisor');
/* -------------------------------------------------------------------------- */

$targetSupervisor = Database::selectOne(
    'SELECT s.id, u.name FROM bc_supervisors s JOIN users u ON u.id = s.user_id
      WHERE s.branch_id IS NOT NULL ORDER BY s.id LIMIT 1'
);

if ($targetSupervisor === null) {
    ok(false, 'Need a BCA with a branch for the SSS target test');
} else {
    $targetSupervisorId = (int) $targetSupervisor['id'];
    $targetMonth = date('Y-m-01');
    // Its own day, so this section is not reading what the section above wrote.
    $reopenDate = date('Y-m-d', strtotime('-8 days'));

    Database::delete('sss_targets', 'bc_supervisor_id = :bc', ['bc' => $targetSupervisorId]);
    Database::delete(
        'sss_enrolments',
        'bc_supervisor_id = :bc AND enrolment_date = :date',
        ['bc' => $targetSupervisorId, 'date' => $reopenDate]
    );

    $targetForm = page('/admin/sss-targets', 'SSS targets screen');
    ok(
        str_contains($targetForm, 'per working day') || str_contains($targetForm, 'a day'),
        'The screen says the figure is a daily one, not a monthly total'
    );
    ok(str_contains($targetForm, 'name="apy_target"'), 'The screen asks for a target per scheme');

    $targetSave = request($base . '/admin/sss-targets', [
        'post' => [
            '_token' => csrfToken($targetForm),
            'month' => $targetMonth,
            'bc_supervisor_ids' => [$targetSupervisorId],
            'apy_target' => 2,
            'pmjjby_target' => 3,
            'pmsby_target' => 1,
            'pmjdy_target' => 4,
            'notes' => 'Set by the smoke test.',
        ],
    ]);

    equals(200, $targetSave['status'], 'Saving an SSS target succeeds');

    $storedTarget = Database::selectOne(
        'SELECT * FROM sss_targets WHERE bc_supervisor_id = :bc AND target_month = :month',
        ['bc' => $targetSupervisorId, 'month' => $targetMonth]
    );

    ok($storedTarget !== null, 'The target was stored against the month');

    if ($storedTarget !== null) {
        equals(2, (int) $storedTarget['apy_target'], 'The APY target is the daily figure as typed');
        equals(4, (int) $storedTarget['pmjdy_target'], 'Each scheme keeps its own target');

        // A target nobody was told about is not a target.
        ok(
            (int) Database::scalar(
                "SELECT COUNT(*) FROM notifications WHERE related_type = 'sss_target'"
            ) > 0,
            'The supervisor is told what is expected of them'
        );
    }

    // The register has to show the comparison, not just the figures.
    $registerPage = page(
        '/admin/sss?period=mtd&bc_supervisor_id=' . $targetSupervisorId,
        'SSS register filtered to one supervisor'
    );
    ok(str_contains($registerPage, 'Target vs achievement'), 'The register compares target with achievement');
    ok(str_contains($registerPage, 'Open as report'), 'The register offers the printable ranking');

    // The lock: a day is submitted the moment it exists, and only a BC Supervisor reopens it.
    $reopenForm = page('/admin/sss/create', 'SSS record form for the re-open test');
    request($base . '/admin/sss', [
        'post' => [
            '_token' => csrfToken($reopenForm),
            'bc_supervisor_id' => $targetSupervisorId,
            'enrolment_date' => $reopenDate,
            'apy_count' => 1,
            'pmjjby_count' => 1,
            'pmsby_count' => 0,
            'pmjdy_count' => 0,
        ],
    ]);

    $reopenRow = Database::selectOne(
        'SELECT * FROM sss_enrolments WHERE bc_supervisor_id = :bc AND enrolment_date = :date',
        ['bc' => $targetSupervisorId, 'date' => $reopenDate]
    );

    if ($reopenRow === null) {
        ok(false, 'Need a recorded day to re-open');
    } else {
        equals('submitted', (string) $reopenRow['status'], 'A recorded day starts closed to the app');
        ok(!empty($reopenRow['submitted_at']), 'The day carries when it was submitted');

        $listWithRow = page(
            '/admin/sss?from=' . $reopenDate . '&to=' . $reopenDate,
            'SSS list showing the day to re-open'
        );
        ok(str_contains($listWithRow, 'Submitted'), 'The list shows a day as submitted');
        ok(
            str_contains($listWithRow, '/reopen'),
            'The list offers the BC Supervisor a way to hand the day back'
        );

        $reopened = request($base . '/admin/sss/' . (int) $reopenRow['id'] . '/reopen', [
            'post' => ['_token' => csrfToken($listWithRow)],
        ]);

        equals(200, $reopened['status'], 'Re-opening a day succeeds');
        equals(
            'reopened',
            (string) Database::scalar(
                'SELECT status FROM sss_enrolments WHERE id = :id',
                ['id' => (int) $reopenRow['id']]
            ),
            'The day is open for the supervisor again'
        );
        ok(
            (int) Database::scalar(
                "SELECT COUNT(*) FROM audit_logs WHERE action = 'sss_reopened' AND entity_id = :id",
                ['id' => (int) $reopenRow['id']]
            ) > 0,
            'The re-open is in the audit log, because a figure that changed after it was reported is a question somebody asks'
        );

        // Two Admins on the same screen is not an error.
        $reopenedTwice = request($base . '/admin/sss/' . (int) $reopenRow['id'] . '/reopen', [
            'post' => ['_token' => csrfToken($listWithRow)],
        ]);
        equals(200, $reopenedTwice['status'], 'Re-opening an already open day is not an error');
    }
}

/* -------------------------------------------------------------------------- */
section('A bound device can be released from the screen that says it is bound');
/* -------------------------------------------------------------------------- */

// The action used to be an unlabelled icon in the actions column, shown only while the
// device was active — so a BC Supervisor looking at "bound" had nothing to click, and a released
// or blocked device offered no way back.
$deviceSupervisor = Database::selectOne(
    'SELECT s.id, s.user_id, u.name FROM bc_supervisors s JOIN users u ON u.id = s.user_id
      WHERE s.branch_id IS NOT NULL ORDER BY s.id LIMIT 1'
);

if ($deviceSupervisor === null) {
    ok(false, 'Need a BCA for the device binding test');
} else {
    $deviceUserId = (int) $deviceSupervisor['user_id'];
    Database::delete('devices', 'user_id = :u', ['u' => $deviceUserId]);

    $deviceId = Database::insert('devices', [
        'user_id' => $deviceUserId,
        'device_uuid' => 'smoke-device-' . $deviceUserId,
        'model' => 'Smoke Handset',
        'app_version' => '1.6.1',
        'status' => 'active',
        'bound_at' => now(),
        'last_seen_at' => now(),
        'created_at' => now(),
    ]);

    foreach ([
        'active' => ['Bound', 'Release'],
        'unbound' => ['Unbound', 'Block'],
        'blocked' => ['Blocked', 'Unblock'],
    ] as $state => $expected) {
        Database::update('devices', ['status' => $state, 'updated_at' => now()], 'id = :id', ['id' => $deviceId]);
        $page = page('/admin/supervisors', 'BCAs with a ' . $state . ' device');

        foreach ($expected as $needle) {
            ok(
                str_contains($page, '>' . $needle . '<') || str_contains($page, $needle . '</'),
                sprintf('A %s device shows "%s"', $state, $needle)
            );
        }
    }

    // Released on purpose is not a fault, so it must not be coloured like one.
    Database::update('devices', ['status' => 'unbound', 'updated_at' => now()], 'id = :id', ['id' => $deviceId]);
    $unboundPage = page('/admin/supervisors', 'BCAs with a released device');
    ok(
        str_contains($unboundPage, 'can sign in on any handset'),
        'A released device says what that means for the supervisor'
    );

    // And the release actually works from here.
    Database::update('devices', ['status' => 'active', 'updated_at' => now()], 'id = :id', ['id' => $deviceId]);
    $listPage = page('/admin/supervisors', 'BCAs before releasing');
    $released = request($base . '/admin/devices/' . $deviceId . '/reset', [
        'post' => ['_token' => csrfToken($listPage)],
    ]);

    equals(200, $released['status'], 'Releasing a device from the list succeeds');
    equals(
        'unbound',
        (string) Database::scalar('SELECT status FROM devices WHERE id = :id', ['id' => $deviceId]),
        'The device is released, so the next handset can bind'
    );

    Database::delete('devices', 'id = :id', ['id' => $deviceId]);
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
    $inspectionReport = page('/admin/inspections/' . $inspectionId, 'Inspection report');

    $pdf = request($base . '/admin/inspections/' . $inspectionId . '/pdf');
    ok(
        $pdf['status'] === 200 && str_starts_with($pdf['body'], '%PDF-'),
        sprintf('Inspection report PDF (%d bytes)', strlen($pdf['body']))
    );

    // The seeded inspection was written straight into the database rather than submitted
    // through the form, so it carries no frozen scheme figures. The screen has to render
    // without them rather than showing a row of noughts for a period nobody measured.
    $hasFrozenSchemes = (int) Database::scalar(
        'SELECT COUNT(*) FROM inspection_sss WHERE inspection_id = :id',
        ['id' => $inspectionId]
    ) > 0;

    equals(
        $hasFrozenSchemes,
        str_contains($inspectionReport, 'Social Security Scheme performance'),
        $hasFrozenSchemes
            ? 'The report shows the scheme figures it was signed against'
            : 'A report with no scheme figures shows no scheme block, and still renders'
    );
} else {
    ok(true, 'No submitted inspections to open (skipped)');
}

$supervisorId = (int) Database::scalar('SELECT id FROM bc_supervisors ORDER BY id LIMIT 1');

if ($supervisorId > 0) {
    page('/admin/inspections/supervisor/' . $supervisorId, 'Supervisor work picture');
    $startInspection = page(
        '/admin/inspections/create?bc_supervisor_id=' . $supervisorId,
        'Start inspection (with supervisor)'
    );
    page('/admin/monitoring/route/' . $supervisorId, 'Supervisor route');

    // Nothing is chosen but the supervisor. The Bank's form asks about the outlet — its
    // board, registers, equipment and earnings — none of which belong to a customer visit,
    // so a visit or an account to "verify" is the wrong question and used to be step 1.
    ok(
        !str_contains($startInspection, 'name="visit_id"'),
        'Starting an inspection no longer asks which visit is being verified'
    );
    ok(
        !str_contains($startInspection, 'name="loan_account_id"'),
        'Starting an inspection no longer asks which account is being checked'
    );
    ok(
        !str_contains($startInspection, 'Select work'),
        'The first step is the supervisor, not the work'
    );
    ok(
        str_contains($startInspection, 'once a month'),
        'The screen says the inspection is expected monthly'
    );

    // Once a month is the expectation, not a rule enforced in software: after a Poor grade a
    // second visit is legitimate. So the screen warns and offers the existing one.
    $inspectedThisMonth = Database::selectOne(
        "SELECT bc_supervisor_id FROM inspections
          WHERE inspection_date >= :month AND status IN ('draft', 'submitted')
       ORDER BY id DESC LIMIT 1",
        ['month' => date('Y-m-01')]
    );

    if ($inspectedThisMonth === null) {
        ok(true, 'No inspection recorded this month, so the monthly warning cannot be checked (skipped)');
    } else {
        $repeat = page(
            '/admin/inspections/create?bc_supervisor_id=' . (int) $inspectedThisMonth['bc_supervisor_id'],
            'Start inspection for a supervisor already inspected this month'
        );

        ok(
            str_contains($repeat, 'already has an inspection recorded for'),
            'The screen warns that this month is already accounted for'
        );
        // The affordance, not the wording: there is a way through to the existing record, and
        // the form to start another is still on the page underneath it.
        ok(
            str_contains($repeat, 'Open that inspection') || str_contains($repeat, 'Carry on with that one'),
            'It offers the existing one rather than refusing a second'
        );
        ok(
            str_contains($repeat, 'name="inspection_date"'),
            'A second inspection is still possible when it is genuinely needed'
        );
    }
}

if ($supervisorId > 0) {
    // Start one for real: the supervisor and the date are now the whole of step 1, and this
    // is the POST that used to carry a visit and an account with it.
    Database::delete(
        'inspections',
        "bc_supervisor_id = :bc AND status = 'draft' AND inspection_date = :date",
        ['bc' => $supervisorId, 'date' => today()]
    );

    $started = request($base . '/admin/inspections', [
        'post' => [
            '_token' => csrfToken($startInspection),
            'bc_supervisor_id' => $supervisorId,
            'inspection_date' => today(),
        ],
    ]);

    equals(200, $started['status'], 'An inspection starts from the supervisor and the date alone');

    $draft = Database::selectOne(
        "SELECT id, visit_id, loan_account_id FROM inspections
          WHERE bc_supervisor_id = :bc AND status = 'draft'
       ORDER BY id DESC LIMIT 1",
        ['bc' => $supervisorId]
    );

    if ($draft === null) {
        ok(false, 'The draft inspection was created');
    } else {
        ok(true, 'The draft inspection was created');
        ok(
            $draft['visit_id'] === null && $draft['loan_account_id'] === null,
            'It is tied to the BC point, not to a visit or an account'
        );

        // The assessment is item 24 of the form. It used to be asked a second time on this
        // screen, in the vocabulary of the old form — whether a customer visit had been
        // verified — on an inspection that has no single visit to verify.
        $draftPage = $started['body'];

        ok(
            !str_contains($draftPage, 'name="result"'),
            'The inspection screen does not ask for a verification result of its own'
        );
        ok(
            str_contains($draftPage, 'item 24'),
            'It points at item 24 as the assessment instead'
        );

        /*
         * The Social Security Scheme block. Item 16 asks whether the agent is aware of the
         * schemes; this is how many people they actually enrolled, over a window the inspector
         * chooses.
         *
         * The dates are inside the main form on purpose. There is no draft save on this screen —
         * it posts straight to submit — so a reload to refresh the figures would throw away
         * every answer already typed into a form this long.
         */
        ok(
            str_contains($draftPage, 'Social Security Scheme performance'),
            'The inspection screen shows the scheme performance block'
        );
        ok(
            str_contains($draftPage, 'name="sss_from"') && str_contains($draftPage, 'name="sss_to"'),
            'With a from and a to date the inspector can set'
        );
        ok(
            str_contains($draftPage, 'PMJJBY'),
            'And the four schemes by name, as the SSS register lists them'
        );
        ok(
            str_contains($draftPage, 'Open in the SSS register'),
            'And a way through to the register itself for any other window'
        );

        /*
         * The figures are read, never asked for. Sss says why in its own header: a number the
         * system already holds must not also be typed by hand, or the agent is measured on one
         * and defends the other. So no input on this page may carry a scheme count.
         */
        foreach (array_keys(\App\Services\Sss::schemes()) as $schemeColumn) {
            ok(
                !str_contains($draftPage, 'name="' . $schemeColumn . '"')
                && !str_contains($draftPage, 'name="form[' . $schemeColumn . ']"'),
                'Nothing on the screen asks anybody to type ' . $schemeColumn
            );
        }
    }
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
$createPage = page('/admin/supervisors/create', 'Add BCA');

foreach (['sp_cbc_name', 'ssa', 'iibf_number', 'dra_id', 'designation', 'aadhaar_number',
    'pan_number', 'block', 'tehsil', 'district', 'state', 'pincode'] as $field) {
    ok(
        str_contains($createPage, 'name="' . $field . '"'),
        'BC creation form offers the ' . $field . ' field'
    );
}

// And no longer offers the two it stopped issuing. A BCA signs in with the BCBF code above or
// their mobile number, so a third identifier was one more thing to invent, write down and read
// back to somebody over a bad line.
foreach (['username', 'employee_code'] as $field) {
    ok(
        !str_contains($createPage, 'name="' . $field . '"'),
        'BC creation form no longer offers the ' . $field . ' field'
    );
}

$branchForBc = (int) Database::scalar('SELECT id FROM branches ORDER BY id LIMIT 1');
$bcCode = 'SMOKE' . random_int(100000, 999999);
/*
 * Randomised, like the code and the email above it, and for the same reason now: the mobile is
 * a sign-in identifier and the form rejects one already in use. A constant passed the first run
 * against a scratch database and failed every run after it, which README documents people doing.
 */
$bcMobile = '9' . random_int(100000000, 999999999);

$created = request($base . '/admin/supervisors', [
    'post' => [
        '_token' => csrfToken($createPage),
        'name' => 'CHANDRA SHEKHAR',
        'bc_code' => $bcCode,
        'branch_id' => (string) $branchForBc,
        'mobile' => $bcMobile,
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

if (ok($newBc !== null, 'BCA created through the form')) {
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
    $editPage = page('/admin/supervisors/' . (int) $newBc['id'] . '/edit', 'Edit BCA');

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
            'mobile' => $bcMobile,
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
            '_token' => csrfToken(page('/admin/supervisors/' . (int) $newBc['id'] . '/edit', 'Edit BCA again')),
            'name' => 'CHANDRA SHEKHAR',
            'bc_code' => $bcCode,
            'branch_id' => (string) $branchForBc,
            'mobile' => $bcMobile,
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

    /* The BCA is created with no username, and an old one survives being edited ---------- */

    equals(
        null,
        Database::scalar('SELECT username FROM users WHERE id = :id', ['id' => (int) $newBc['user_id']]),
        'A BCA created through the form gets no username'
    );
    equals(
        null,
        Database::scalar('SELECT employee_code FROM users WHERE id = :id', ['id' => (int) $newBc['user_id']]),
        'Nor an employee code'
    );

    /*
     * The one guarantee updateSupervisor's omission exists to provide.
     *
     * Accounts created before the form stopped issuing usernames still have one and may still be
     * signing in with it. The form does not show it, so it must not clear it either — otherwise
     * correcting a village name silently takes away somebody's login, with nothing on screen to
     * say it happened.
     */
    $legacyUsername = 'legacy-' . strtolower($bcCode);
    Database::update('users', ['username' => $legacyUsername], 'id = :id', ['id' => (int) $newBc['user_id']]);

    $afterEdit = request($base . '/admin/supervisors/' . (int) $newBc['id'], [
        'post' => [
            '_token' => csrfToken(page('/admin/supervisors/' . (int) $newBc['id'] . '/edit', 'Edit BCA once more')),
            'name' => 'CHANDRA SHEKHAR',
            'bc_code' => $bcCode,
            'branch_id' => (string) $branchForBc,
            'mobile' => $bcMobile,
            'status' => 'active',
            'village' => 'NAGLA FULU EDITED',
        ],
    ]);

    ok(in_array($afterEdit['status'], [200, 302], true), 'Editing a BCA that still has a username is accepted');
    equals(
        $legacyUsername,
        (string) Database::scalar('SELECT username FROM users WHERE id = :id', ['id' => (int) $newBc['user_id']]),
        'And the username it was created with is left alone, because the form no longer owns it'
    );

    /* Two people cannot share the number that signs them in ----------------------------- */

    $duplicateCode = 'SMOKE' . random_int(100000, 999999);
    $duplicateMobile = request($base . '/admin/supervisors', [
        'post' => [
            '_token' => csrfToken(page('/admin/supervisors/create', 'Add BCA for the duplicate check')),
            'name' => 'SOMEBODY ELSE',
            'bc_code' => $duplicateCode,
            'branch_id' => (string) $branchForBc,
            // The same number, written differently. Comparing the stored strings would let this
            // through, and the sign-in would then have two accounts to choose between.
            'mobile' => '+91 ' . substr($bcMobile, 0, 5) . ' ' . substr($bcMobile, 5),
        ],
    ]);

    ok(in_array($duplicateMobile['status'], [200, 302], true), 'A duplicate mobile is handled without an error page');
    equals(
        null,
        Database::scalar('SELECT id FROM bc_supervisors WHERE bc_code = :code', ['code' => $duplicateCode]),
        'And the second BCA is not created, however the number was spelled'
    );

    /* A number that is not a number at all ---------------------------------------------- */

    $badMobileCode = 'SMOKE' . random_int(100000, 999999);
    request($base . '/admin/supervisors', [
        'post' => [
            '_token' => csrfToken(page('/admin/supervisors/create', 'Add BCA with an unusable number')),
            'name' => 'NO PHONE',
            'bc_code' => $badMobileCode,
            'branch_id' => (string) $branchForBc,
            'mobile' => 'N/A',
        ],
    ]);

    equals(
        null,
        Database::scalar('SELECT id FROM bc_supervisors WHERE bc_code = :code', ['code' => $badMobileCode]),
        'A BCA is not created with a mobile they could never sign in with'
    );
}

/* The branch form must offer Zone, which the report header prints. */
$branchCreate = page('/admin/branches/create', 'Add branch');
ok(str_contains($branchCreate, 'name="zone"'), 'Branch form offers the zone field');

/* -------------------------------------------------------------------------- */
section('The database can be updated from the browser, without reinstalling');
/* -------------------------------------------------------------------------- */

// This exists because the alternative people reach for is deleting the files and running
// install.php again, which drops every table. An update that needs a terminal, on hosting
// that has no terminal, is an update that gets done the destructive way instead.

$upgradePage = page('/admin/settings/upgrade', 'Database update screen');
ok(str_contains($upgradePage, 'never deletes anything'), 'The screen says what it will and will not do');
ok(
    str_contains($upgradePage, 'drops every table'),
    'The screen warns against reinstalling, where somebody about to do it will read it'
);

$accountsBefore = (int) Database::scalar('SELECT COUNT(*) FROM loan_accounts');
$usersBefore = (int) Database::scalar('SELECT COUNT(*) FROM users');

$preview = request($base . '/admin/settings/upgrade', [
    'post' => ['_token' => csrfToken($upgradePage), 'mode' => 'preview'],
]);

equals(200, $preview['status'], 'Previewing the update succeeds');
ok(str_contains($preview['body'], 'Would apply'), 'The preview reports what it would change');
ok(!str_contains($preview['body'], 'Applied:'), 'The preview does not apply anything');

$applied = request($base . '/admin/settings/upgrade', [
    'post' => ['_token' => csrfToken($upgradePage), 'mode' => 'apply'],
]);

equals(200, $applied['status'], 'Applying the update succeeds');
ok(str_contains($applied['body'], 'Applied:'), 'The update reports what it changed');
ok(
    str_contains($applied['body'], '0 failed'),
    'The update ran without failures against a database already at this schema'
);

// The whole point: an update adds, it does not replace.
equals($accountsBefore, (int) Database::scalar('SELECT COUNT(*) FROM loan_accounts'), 'The update kept every loan account');
equals($usersBefore, (int) Database::scalar('SELECT COUNT(*) FROM users'), 'The update kept every user');
ok(
    (int) Database::scalar("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()") >= 40,
    'The update left the schema whole'
);
ok(
    Database::scalar('SELECT id FROM audit_logs WHERE action = :a ORDER BY id DESC LIMIT 1', ['a' => 'schema_upgraded']) !== null,
    'Running the update is recorded in the audit log'
);

// Ordinary CLI safety must survive having a second entry point.
ok(
    str_contains(
        (string) file_get_contents(__DIR__ . '/../database/upgrade.php'),
        "PHP_SAPI !== 'cli' && !defined('LRMS_UPGRADE_IN_APP')"
    ),
    'upgrade.php still refuses to run over HTTP unless the panel invoked it'
);
ok(
    !str_starts_with((string) file_get_contents(__DIR__ . '/../database/upgrade.php'), '#!'),
    'upgrade.php carries no shebang, which would break including it'
);

/* -------------------------------------------------------------------------- */
section('A document root left on public_html says so, instead of 404ing');
/* -------------------------------------------------------------------------- */

/*
 * The root index.php is not the application: it is the page that explains the one hosting
 * mistake that produces a bare 404 on every URL. It only ever runs when something is already
 * wrong, which is exactly the code that rots unnoticed, so both branches are checked here.
 *
 * Run out-of-process with a controlled SCRIPT_NAME, because that is what the file reads to tell
 * the two mistakes apart, and it cannot be reached over HTTP from this suite — the test server's
 * document root is public/, which is the correct layout.
 */
$diagnose = static function (string $scriptName): string {
    $php = <<<'PHP'
        $_SERVER['SCRIPT_NAME'] = $argv[1];
        $_SERVER['HTTP_HOST'] = 'example.test';
        require $argv[2];
        PHP;

    $process = proc_open(
        ['php', '-r', $php, $scriptName, dirname(__DIR__) . '/index.php'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );

    if (!is_resource($process)) {
        return '';
    }

    $out = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return $out;
};

// Served from the document root, with the application beside it: the root is one level high.
$tooHigh = $diagnose('/index.php');

ok(str_contains($tooHigh, 'pointed one level too high'), 'It names the mistake');
ok(str_contains($tooHigh, 'public_html/public'), 'And the document root that fixes it');
ok(str_contains($tooHigh, 'vHost Conf'), 'With the CyberPanel setting to change');

// The exposure is the urgent half: those files are readable over the web right now.
ok(str_contains($tooHigh, 'downloadable right now'), 'It warns that the configuration is exposed');
ok(str_contains($tooHigh, '/config/config.php'), 'Naming the file, so it can be checked');
ok(
    str_contains($tooHigh, 'change the database password'),
    'And says to rotate the password, because it may already have been read'
);

// Served from a sub-folder: the archive was never flattened, and the folder in the URL is the
// wrapper it created. A different mistake with a different fix.
$nested = $diagnose('/dhdhdh51-zetpro-c94ffc5/index.php');

ok(str_contains($nested, 'not flattened'), 'A nested upload is diagnosed as its own problem');
ok(
    str_contains($nested, 'mv dhdhdh51-zetpro-c94ffc5/.[!.]* dhdhdh51-zetpro-c94ffc5/* .'),
    'With a command naming the actual folder, dotfiles included'
);
ok(
    str_contains($nested, '/dhdhdh51-zetpro-c94ffc5/config/config.php'),
    'And the exposed paths under that folder, not at the root'
);
ok(
    !str_contains($nested, 'pointed one level too high'),
    'It does not also claim the document root is wrong'
);

// It must never serve the application from the wrong root. That would put the site up with
// config/ readable, which is worse than the 404 because nobody then looks for the cause.
foreach ([$tooHigh, $nested] as $page) {
    ok(!str_contains($page, 'Sign in'), 'It does not boot the application');
    ok(!str_contains($page, 'name="_token"'), 'And renders no application form');
}

ok(
    str_contains((string) file_get_contents(__DIR__ . '/../index.php'), '503'),
    'It answers 503 rather than 200, so the outage is not indexed as a page'
);

// It has to reach the server, or none of the above matters.
ok(
    str_contains((string) file_get_contents(__DIR__ . '/../deploy/build-package.sh'), 'index.php'),
    'The deployment package ships it'
);

// Somebody whose document root is wrong is usually mid-install, so the page answers the
// question they were about to ask next.
ok(
    str_contains($tooHigh, 'came here to run the installer')
    || str_contains($tooHigh, 'Looking for the installer'),
    'It says where the installer is, since that is what a 404 was blocking'
);

/*
 * The page prints a `mv` command naming the folder the archive created, and that name comes from
 * the URL it was reached at. Escaping it for HTML is not enough: the operator copies it into a
 * root shell in their own web root.
 *
 * SCRIPT_NAME is normally the server's own resolved path, but some CGI setups build it from the
 * request and a `..` segment survives on servers that do not normalise. Unchecked, a request for
 * `/a; rm -rf ~/index.php` printed `mv a; rm -rf ~/.[!.]* ...` as the instruction — to somebody
 * already stuck and inclined to copy what they are told.
 */
$folderArgument = static function (string $page): string {
    $plain = html_entity_decode(strip_tags($page), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    return preg_match('#mv ([^/]+)/\.\[#', $plain, $m) === 1 ? trim($m[1]) : '';
};

$legitimate = $diagnose('/dhdhdh51-zetpro-c94ffc5/index.php');
equals(
    'dhdhdh51-zetpro-c94ffc5',
    $folderArgument($legitimate),
    'A folder name that looks like an archive wrapper is named in the command'
);

foreach ([
    'a shell separator' => '/a; rm -rf ~/index.php',
    'a command substitution' => '/$(id)/index.php',
    'a backquoted command' => '/x`whoami`/index.php',
    'a traversal' => '/../../etc/index.php',
    'a space and a quote' => "/it's a folder/index.php",
    'several path segments' => '/one/two/three/index.php',
] as $description => $scriptName) {
    $page = $diagnose($scriptName);
    $argument = $folderArgument($page);

    ok(
        $argument === '' || preg_match('/^[A-Za-z0-9._<>-]+$/', $argument) === 1,
        'The command stays safe against ' . $description . ' (printed "' . $argument . '")'
    );
    ok(
        !str_contains($page, 'rm -rf ~') && !str_contains($page, '$(id)') && !str_contains($page, '`whoami`'),
        'Nothing from ' . $description . ' is echoed back into the instructions'
    );
    ok(
        str_contains($page, 'not flattened'),
        'And it is still diagnosed as a nested upload rather than mistaken for a wrong root'
    );
}

/* -------------------------------------------------------------------------- */
section('Previewing the update tells the truth about what it would do');
/* -------------------------------------------------------------------------- */

/*
 * The preview has to be trustworthy or nobody presses the button after it, and it was not.
 *
 * Several columns are listed in upgrade.php's column pass *and* included in a CREATE TABLE in
 * its table pass, because they have to reach two different databases: one with no such table at
 * all, and one where the table arrived earlier without them. Applying works — the table is
 * created with its columns and the column pass finds them present. A dry run creates nothing,
 * so the column pass found the table missing and reported a failure for each column.
 *
 * On a real database a few versions behind, Preview said "4 failed — sss_enrolments table
 * missing, run migrate.php first" and then applied with 0 failed. Reproduced here by taking the
 * tables away, since this database is current and has them.
 */
$upgradeTables = ['sss_targets', 'sss_enrolments'];

foreach ($upgradeTables as $table) {
    Database::statement('SET FOREIGN_KEY_CHECKS = 0');
    Database::statement(sprintf('DROP TABLE IF EXISTS `%s`', $table));
    Database::statement('SET FOREIGN_KEY_CHECKS = 1');
}

$present = static function (string $table): bool {
    return (int) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t',
        ['t' => $table]
    ) > 0;
};

equals(false, $present('sss_enrolments'), 'With the SSS tables removed, standing in for an older database');

$previewPage = page('/admin/settings/upgrade', 'Update screen before previewing');
$preview = request($base . '/admin/settings/upgrade', [
    'post' => ['_token' => csrfToken($previewPage), 'mode' => 'preview'],
]);

equals(200, $preview['status'], 'The preview runs');
ok(str_contains($preview['body'], 'Would apply'), 'And reports what it would change');
ok(
    !str_contains($preview['body'], 'table missing'),
    'It does not report a table it is about to create as missing'
);
ok(
    str_contains($preview['body'], '0 failed'),
    'And it reports no failures for work that applies cleanly'
);

// A preview that changes the schema would be the worse bug of the two.
equals(false, $present('sss_enrolments'), 'The preview created nothing');
equals(false, $present('sss_targets'), 'Neither table appeared from a dry run');

// Now apply for real, which must both succeed and put the tables back.
$restore = request($base . '/admin/settings/upgrade', [
    'post' => ['_token' => csrfToken($previewPage), 'mode' => 'apply'],
]);

equals(200, $restore['status'], 'Applying it works');
ok(str_contains($restore['body'], '0 failed'), 'With no failures');
equals(true, $present('sss_enrolments'), 'And the table the preview promised is created');
equals(true, $present('sss_targets'), 'Along with the other one');

// A table that the upgrade does not ship a definition for is a real problem and must still be
// reported as one, or the fix above would have turned a loud failure into silence.
ok(
    str_contains(
        (string) file_get_contents(__DIR__ . '/../database/upgrade.php'),
        'table missing — run migrate.php first'
    ),
    'A genuinely missing table is still reported as a failure'
);

/* -------------------------------------------------------------------------- */
section('A 404 on install.php is told apart from the four things that cause it');
/* -------------------------------------------------------------------------- */

/*
 * The installer deletes itself the moment it succeeds, so a 404 on it is usually correct and
 * occasionally a broken upload. Four causes, one status code, and the wrong guess is expensive:
 * re-running the installer drops every table.
 *
 * preflight.php is where that gets answered, so both halves of the branch are exercised here —
 * against a throwaway root, because flipping the state of the real public/install.php in a test
 * would leave the checkout modified if the run aborted.
 */
$preflightSection = static function (bool $withInstaller) use ($base): string {
    $root = dirname(__DIR__);
    $tmp = sys_get_temp_dir() . '/lrms-preflight-' . getmypid() . ($withInstaller ? '-with' : '-without');

    @mkdir($tmp . '/deploy', 0775, true);
    @mkdir($tmp . '/public', 0775, true);

    foreach (['storage/logs', 'storage/uploads', 'storage/generated'] as $dir) {
        @mkdir($tmp . '/' . $dir, 0775, true);
    }

    // Symlinked, so this reads the real application and the real database settings.
    foreach (['app', 'config', 'database', 'resources', 'routes'] as $link) {
        @symlink($root . '/' . $link, $tmp . '/' . $link);
    }

    @copy($root . '/deploy/preflight.php', $tmp . '/deploy/preflight.php');

    if ($withInstaller) {
        @copy($root . '/public/install.php', $tmp . '/public/install.php');
    }

    $output = (string) shell_exec(sprintf('php %s 2>&1', escapeshellarg($tmp . '/deploy/preflight.php')));

    // Best effort: a temp directory left behind is untidy, not a failure.
    foreach (['app', 'config', 'database', 'resources', 'routes'] as $link) {
        @unlink($tmp . '/' . $link);
    }

    @unlink($tmp . '/deploy/preflight.php');
    @unlink($tmp . '/public/install.php');

    // Deepest first, so the directories come out empty.
    foreach ([
        'storage/logs', 'storage/uploads', 'storage/generated',
        'storage', 'deploy', 'public', '',
    ] as $dir) {
        @rmdir(rtrim($tmp . '/' . $dir, '/'));
    }

    // Just this section. A fixed-length window ran into "Scheduled tasks", whose failure about
    // a missing bin/cron.php is an artefact of the throwaway root and nothing to do with the
    // installer.
    $start = strpos($output, 'The browser installer');

    if ($start === false) {
        return $output;
    }

    $end = strpos($output, 'Scheduled tasks', $start);

    return $end === false ? substr($output, $start) : substr($output, $start, $end - $start);
};

// This database is seeded, so both cases are "already installed" — the difference is only
// whether the file is still sitting there.
$withInstaller = $preflightSection(true);

ok(
    str_contains($withInstaller, 'already installed'),
    'With the installer present on an installed site, it says to delete it'
);
ok(
    str_contains($withInstaller, 'Update the database'),
    'And points at the update screen instead of the installer'
);

$withoutInstaller = $preflightSection(false);

ok(
    str_contains($withoutInstaller, 'already removed'),
    'With it gone from an installed site, that is reported as the healthy state'
);
ok(
    str_contains($withoutInstaller, 'is correct here'),
    'Saying in as many words that the 404 is correct, so nobody re-uploads it'
);
ok(
    !str_contains($withoutInstaller, '[FAIL]'),
    'And it is not reported as a failure, because it is not one'
);

// The branch for a genuinely missing installer on an uninstalled site cannot be reached from
// this suite — it needs a database with no tables. Pin the wording so it cannot be deleted
// without noticing.
$preflightSource = (string) file_get_contents(__DIR__ . '/../deploy/preflight.php');

ok(
    str_contains($preflightSource, 'install.php is missing and the site is not installed'),
    'A missing installer on a site that never installed is still reported as a failure'
);
ok(
    str_contains($preflightSource, 'the installer is not at /install.php'),
    'And a wrong document root explains where the installer answers from instead'
);

/*
 * The update also has to carry the BCA / BC Supervisor rename, because two of the old job
 * titles live in the database rather than in the code. `roles.name` is what the panel prints
 * for a role, and it was written once when the database was created — so on an existing
 * installation the screens would keep saying "Admin / Supervisor" no matter what the code
 * says.
 *
 * The trap here is that the two names swap places. The account that monitors becomes the BC
 * Supervisor, which is the name the agent used to have. Rename them in the wrong order, or
 * match on the old name without checking which role it is, and both rows end up called "BC
 * Supervisor" — which is how this was first written, and it took a database in the old state
 * to notice.
 */
Database::update('roles', [
    'name' => 'Admin / Supervisor',
    'description' => 'Full control. Monitors and inspects BC Supervisors; does not perform customer recovery visits.',
], 'slug = :slug', ['slug' => 'admin']);
Database::update('roles', [
    'name' => 'BC Supervisor',
    'description' => 'Field officer. Performs customer recovery visits through the Android app.',
], 'slug = :slug', ['slug' => 'bc_supervisor']);
Database::update('report_types', ['name' => 'BC Supervisor Inspection Report'], 'slug = :slug', [
    'slug' => 'bc_inspection',
]);

$renamePage = page('/admin/settings/upgrade', 'Database update screen, before the rename');
$renamed = request($base . '/admin/settings/upgrade', [
    'post' => ['_token' => csrfToken($renamePage), 'mode' => 'apply'],
]);

equals(200, $renamed['status'], 'The update runs against a database with the old job titles');

$roleNames = [];

foreach (Database::select('SELECT slug, name FROM roles') as $role) {
    $roleNames[(string) $role['slug']] = (string) $role['name'];
}

equals('BC Supervisor', $roleNames['admin'] ?? '', 'The panel account is renamed to BC Supervisor');
equals('BCA', $roleNames['bc_supervisor'] ?? '', 'And the field agent to BCA');
equals(
    3,
    count(array_unique($roleNames)),
    'The three roles still have three distinct names (got: ' . implode(', ', $roleNames) . ')'
);
equals(
    'BCA Inspection Report',
    (string) Database::scalar('SELECT name FROM report_types WHERE slug = :slug', ['slug' => 'bc_inspection']),
    'The report the BC Supervisor files on a BCA is named after who it is about'
);

// Run it again: a rename that fires twice would be a rename that could undo itself.
$again = request($base . '/admin/settings/upgrade', [
    'post' => ['_token' => csrfToken($renamePage), 'mode' => 'apply'],
]);

equals(200, $again['status'], 'The update runs a second time');
equals(
    'BCA',
    (string) Database::scalar('SELECT name FROM roles WHERE slug = :slug', ['slug' => 'bc_supervisor']),
    'And leaves the already-renamed role alone'
);

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
section('The QR code on a printed PDF opens the record');
/* -------------------------------------------------------------------------- */

/*
 * The code carries `/r/inspection/{id}`, not the panel path, and where that goes is decided
 * when somebody scans it. It has to be: the same visit report is printed from the admin panel
 * and from the branch portal, and the sheet outlives the session that printed it, so a code
 * carrying the printer's own path would 403 for the person at the other end — for doing
 * exactly what the caption on the sheet told them to.
 */
$qrInspectionId = (int) Database::scalar('SELECT id FROM inspections ORDER BY id LIMIT 1');

$hop = static function (string $path) use ($base): array {
    return request($base . $path, ['follow' => false]);
};

$inspectionHop = $hop('/r/inspection/' . $qrInspectionId);
equals(302, $inspectionHop['status'], 'A record code redirects rather than rendering');
ok(
    str_contains($inspectionHop['headers'], '/admin/inspections/' . $qrInspectionId),
    'For a BC Supervisor it resolves to the admin panel'
);
page('/r/inspection/' . $qrInspectionId, 'Scanned inspection code (followed through)');

// A report code carries the filters it was printed with, so it opens the report that was on
// the paper rather than the same report with no dates on it.
$reportHop = $hop('/r/report/customer_visit?from=2026-08-01&to=2026-08-31');
equals(302, $reportHop['status'], 'A report code redirects');
ok(
    str_contains($reportHop['headers'], '/admin/reports/customer_visit'),
    'To the report in the admin panel'
);
ok(
    str_contains($reportHop['headers'], 'from=2026-08-01') && str_contains($reportHop['headers'], 'to=2026-08-31'),
    'Carrying the filters through, so the printed figures can be reproduced'
);

// A sheet whose record has since been deleted says the record is gone. Falling through to the
// panel's own 404 would read as a broken link instead of a missing record.
equals(404, $hop('/r/visit/99999999')['status'], 'A code for a record that is gone is a 404');
equals(404, $hop('/r/teapot/1')['status'], 'A code for a kind of record that does not exist is a 404');

request($base . '/logout', ['post' => ['_token' => csrfToken(page('/admin', 'Dashboard before logout'))]]);

// Signed out, a scan has to reach the sign-in page and then land on the record — being
// dropped on a dashboard after scanning is worse than typing the reference by hand.
$anonymous = $hop('/r/report/customer_visit');
equals(302, $anonymous['status'], 'A scan while signed out redirects');
ok(str_contains($anonymous['headers'], '/login'), 'To the sign-in page');

/* -------------------------------------------------------------------------- */
section('Branch Manager portal and branch isolation');
/* -------------------------------------------------------------------------- */

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

    // The same printed code, scanned by a manager, has to open the branch portal's copy — not
    // the admin path it would carry if the code named where it was printed from. This is the
    // reason /r/ exists rather than the QR holding a panel URL.
    $managerHop = request($base . '/r/report/customer_visit', ['follow' => false]);
    equals(302, $managerHop['status'], 'A manager scanning a report code is redirected');
    ok(
        str_contains($managerHop['headers'], '/manager/reports/customer_visit'),
        'To the branch portal, not to the admin panel they cannot open'
    );
    page('/r/report/customer_visit', 'Manager scanned a report code (followed through)');

    // Inspections are not in the branch portal at all. The code has to say so, because a bare
    // 403 for scanning what the page told you to scan is the thing this route exists to avoid.
    $managerInspection = request($base . '/r/inspection/' . $qrInspectionId, ['follow' => false]);
    equals(302, $managerInspection['status'], 'A manager scanning an inspection code is redirected');
    ok(
        str_contains($managerInspection['headers'], '/manager')
        && !str_contains($managerInspection['headers'], '/admin'),
        'Into the branch portal rather than at a refusal'
    );
    ok(
        str_contains(page('/manager', 'Branch dashboard after scanning an inspection'), 'does not show inspection'),
        'And told why, with the reference on the sheet offered as the way round it'
    );

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
