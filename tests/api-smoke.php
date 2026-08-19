<?php

declare(strict_types=1);

/**
 * Android API smoke test.
 *
 * Exercises the real HTTP surface the app uses: sign-in with device binding, the
 * three-step visit flow with photographs and GPS, money entries, attendance, the
 * server-authoritative deadline, and the offline sync push (including replaying a
 * batch, which must be idempotent).
 *
 *   php tests/api-smoke.php [base-url]
 *
 * Expects a seeded database with imported accounts:
 *   php database/migrate.php --fresh --demo && php tests/test-import.php
 */

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;

$base = $argv[1] ?? null;
$serverProcess = null;

if ($base === null) {
    $base = 'http://127.0.0.1:8098';
    $descriptors = [1 => ['file', '/tmp/lrms-api-server.log', 'a'], 2 => ['file', '/tmp/lrms-api-server.log', 'a']];
    $serverProcess = proc_open(
        sprintf(
            'LRMS_APP_URL=%s php -S 127.0.0.1:8098 -t %s %s',
            escapeshellarg($base),
            escapeshellarg(base_path('public')),
            escapeshellarg(base_path('public/index.php'))
        ),
        $descriptors,
        $pipes
    );

    for ($i = 0; $i < 40; $i++) {
        $socket = @fsockopen('127.0.0.1', 8098, $errno, $errstr, 0.3);

        if ($socket !== false) {
            fclose($socket);
            break;
        }

        usleep(250000);
    }
}

$apiToken = null;
$deviceUuid = 'test-device-' . substr(sha1((string) getmypid()), 0, 12);

/**
 * @param array<string, mixed>|null $body
 * @return array{status:int, json:array<string, mixed>, raw:string}
 */
function api(string $method, string $path, ?array $body = null, array $options = []): array
{
    global $base, $apiToken, $deviceUuid;

    $headers = ['Accept: application/json'];

    if (($options['auth'] ?? true) && $apiToken !== null) {
        $headers[] = 'Authorization: Bearer ' . $apiToken;
        $headers[] = 'X-Device-Id: ' . ($options['device'] ?? $deviceUuid);
    }

    if ($body !== null && !isset($options['multipart'])) {
        $headers[] = 'Content-Type: application/json';
    }

    $handle = curl_init($base . $path);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 60,
    ]);

    if ($body !== null) {
        curl_setopt(
            $handle,
            CURLOPT_POSTFIELDS,
            isset($options['multipart']) ? $body : (string) json_encode($body)
        );
    }

    $raw = (string) curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);

    $json = json_decode($raw, true);

    return ['status' => $status, 'json' => is_array($json) ? $json : [], 'raw' => $raw];
}

/** A small valid JPEG, so the photo pipeline (GD + watermark) is really exercised. */
function samplePhoto(int $width = 640, int $height = 480): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 180, 200, 220));
    imagefilledrectangle($image, 40, 40, $width - 40, $height - 40, imagecolorallocate($image, 90, 120, 160));
    imagestring($image, 5, 60, 60, 'LRMS TEST PHOTO', imagecolorallocate($image, 255, 255, 255));

    ob_start();
    imagejpeg($image, null, 85);
    $binary = (string) ob_get_clean();
    imagedestroy($image);

    return base64_encode($binary);
}

function uuid(): string
{
    return uuid4();
}

// The sign-in throttle keeps its counters in files with a fifteen-minute decay, so
// they outlive a --fresh database. Left alone, running this suite twice inside that
// window makes the second run fail on 429s that have nothing to do with the code
// under test. Cleared here so the suite is repeatable.
$throttleDir = base_path('storage/logs/throttle');

if (is_dir($throttleDir)) {
    foreach (glob($throttleDir . '/*.json') ?: [] as $counter) {
        @unlink($counter);
    }
}

/* -------------------------------------------------------------------------- */
section('Public endpoints');
/* -------------------------------------------------------------------------- */

$ping = api('GET', '/api/v1/ping', null, ['auth' => false]);
equals(200, $ping['status'], 'GET /api/v1/ping responds 200');
ok(($ping['json']['data']['api_version'] ?? '') === 'v1', 'Ping reports the API version');
ok(!empty($ping['json']['data']['server_time']), 'Ping reports server time (for the countdown)');

$unauthorised = api('GET', '/api/v1/me', null, ['auth' => false]);
equals(401, $unauthorised['status'], 'Authenticated route without a token returns 401');
equals('unauthenticated', $unauthorised['json']['code'] ?? '', 'Unauthenticated response carries a machine code');

$badToken = api('GET', '/api/v1/me');
// No token issued yet, so this is also unauthenticated.
equals(401, $badToken['status'], 'Missing bearer token is refused');

/* -------------------------------------------------------------------------- */
section('Sign-in and device binding');
/* -------------------------------------------------------------------------- */

$supervisor = Database::selectOne(
    'SELECT s.*, u.username, u.id AS user_id FROM bc_supervisors s JOIN users u ON u.id = s.user_id ORDER BY s.id LIMIT 1'
);

if ($supervisor === null) {
    exit("No BC Supervisor seeded. Run: php database/migrate.php --fresh --demo\n");
}

// Known password for the test.
Database::update('users', [
    'password' => Auth::hashPassword('AppTest@123'),
    'must_change_password' => 0,
    'failed_attempts' => 0,
    'locked_until' => null,
], 'id = :id', ['id' => (int) $supervisor['user_id']]);

$username = (string) $supervisor['username'];

$wrong = api('POST', '/api/v1/auth/login', [
    'username' => $username,
    'password' => 'not-the-password',
    'device' => ['uuid' => $deviceUuid],
], ['auth' => false]);
equals(401, $wrong['status'], 'Wrong password returns 401');
equals('invalid_credentials', $wrong['json']['code'] ?? '', 'Wrong password reports invalid_credentials');

$noDevice = api('POST', '/api/v1/auth/login', [
    'username' => $username,
    'password' => 'AppTest@123',
], ['auth' => false]);
equals(422, $noDevice['status'], 'Sign-in without a device id is refused');

// A BC Supervisor knows their BCBF code — it is on their paperwork and in the
// bank's spreadsheets — better than a username the office invented for them, so
// the same credentials must work with either. These use the same device uuid as
// the sign-in below because only one handset may be bound at a time.
$byBcCode = api('POST', '/api/v1/auth/login', [
    'username' => $supervisor['bc_code'],
    'password' => 'AppTest@123',
    'device' => ['uuid' => $deviceUuid],
], ['auth' => false]);
equals(200, $byBcCode['status'], 'Sign-in with the BCBF code succeeds');
equals(
    $supervisor['bc_code'],
    $byBcCode['json']['data']['supervisor']['bc_code'] ?? '',
    'BCBF-code sign-in resolves the same supervisor'
);

$byLowerBcCode = api('POST', '/api/v1/auth/login', [
    'username' => strtolower((string) $supervisor['bc_code']),
    'password' => 'AppTest@123',
    'device' => ['uuid' => $deviceUuid],
], ['auth' => false]);
equals(200, $byLowerBcCode['status'], 'BCBF code is matched case-insensitively');

$unknownBcCode = api('POST', '/api/v1/auth/login', [
    'username' => 'BC-no-such-code',
    'password' => 'AppTest@123',
    'device' => ['uuid' => $deviceUuid],
], ['auth' => false]);
equals(401, $unknownBcCode['status'], 'An unknown BCBF code is refused');

$login = api('POST', '/api/v1/auth/login', [
    'username' => $username,
    'password' => 'AppTest@123',
    'device' => [
        'uuid' => $deviceUuid,
        'model' => 'Test Handset',
        'manufacturer' => 'LRMS',
        'os_version' => '14',
        'app_version' => '1.0.0-test',
    ],
], ['auth' => false]);

equals(200, $login['status'], 'BC Supervisor sign-in succeeds');
$apiToken = $login['json']['data']['token'] ?? null;
ok(is_string($apiToken) && strlen($apiToken) > 40, 'A bearer token was issued');
equals($supervisor['bc_code'], $login['json']['data']['supervisor']['bc_code'] ?? '', 'Response carries the BC code');

// Only the hash is stored.
ok(
    (int) Database::scalar('SELECT COUNT(*) FROM api_tokens WHERE token_hash = :h', ['h' => hash('sha256', (string) $apiToken)]) === 1,
    'Only the token hash is stored in the database'
);

// Device binding: the token must not work from another device id.
$otherDevice = api('GET', '/api/v1/me', null, ['device' => 'some-other-device']);
equals(401, $otherDevice['status'], 'Token presented from another device is refused (device binding)');

// A second handset cannot sign in while one is bound.
$secondDevice = api('POST', '/api/v1/auth/login', [
    'username' => $username,
    'password' => 'AppTest@123',
    'device' => ['uuid' => $deviceUuid . '-second'],
], ['auth' => false]);
equals(403, $secondDevice['status'], 'A second device cannot bind while one is active');
equals('device_not_allowed', $secondDevice['json']['code'] ?? '', 'Second device reports device_not_allowed');

// Admin credentials must be refused by the app API.
$adminUser = Database::selectOne(
    "SELECT u.* FROM users u JOIN roles r ON r.id = u.role_id WHERE r.slug = 'admin' LIMIT 1"
);
Database::update('users', ['password' => Auth::hashPassword('AdminTest@123')], 'id = :id', ['id' => (int) $adminUser['id']]);

$adminLogin = api('POST', '/api/v1/auth/login', [
    'username' => $adminUser['email'],
    'password' => 'AdminTest@123',
    'device' => ['uuid' => 'admin-device'],
], ['auth' => false]);
equals(403, $adminLogin['status'], 'Admin/Supervisor cannot sign in to the field app');
equals('wrong_role', $adminLogin['json']['code'] ?? '', 'Admin sign-in reports wrong_role');

/* -------------------------------------------------------------------------- */
section('Profile and sync pull');
/* -------------------------------------------------------------------------- */

$me = api('GET', '/api/v1/me');
equals(200, $me['status'], 'GET /me succeeds');
ok(isset($me['json']['data']['deadline']['deadline_at']), '/me includes the server deadline');
ok(isset($me['json']['data']['today']['allocated_accounts']), '/me includes allocated account count');

$pull = api('GET', '/api/v1/sync/pull');
equals(200, $pull['status'], 'GET /sync/pull succeeds');
$accounts = $pull['json']['data']['accounts'] ?? [];
ok(count($accounts) > 0, sprintf('Sync pull returned allocated accounts (%d)', count($accounts)));
ok(isset($pull['json']['data']['visit_form']['fields']), 'Sync pull includes the visit form definition');
ok(count($pull['json']['data']['visit_form']['fields'] ?? []) > 5, 'Visit form definition has fields');

// The app has to receive every form, not just the generic one: a KRM OTS or CKCC
// OD-2 account is verified on its own 13-section form, and sending only the
// customer form left the supervisor answering 21 questions while the printed
// report expected 42 or 46 — those sections came out blank with nothing on the
// phone that could have filled them.
$forms = $pull['json']['data']['visit_forms'] ?? [];
$byType = [];

foreach ($forms as $form) {
    $byType[(string) ($form['visit_type'] ?? '')] = $form;
}

equals(3, count($forms), 'Sync pull sends all three visit forms');

foreach (['customer', 'krm_ots', 'ckcc_od2'] as $visitType) {
    ok(isset($byType[$visitType]), 'Sync pull includes the ' . $visitType . ' form');
    ok(
        count($byType[$visitType]['fields'] ?? []) > 5,
        sprintf('The %s form carries its fields (%d)', $visitType, count($byType[$visitType]['fields'] ?? []))
    );
}

// The verification forms are the long ones; if they ever collapse to the size of
// the customer form, the report sections behind them are empty again.
ok(
    count($byType['krm_ots']['fields'] ?? []) > count($byType['customer']['fields'] ?? []),
    'The KRM OTS form is larger than the customer form'
);
ok(
    count($byType['ckcc_od2']['fields'] ?? []) > count($byType['customer']['fields'] ?? []),
    'The CKCC OD-2 form is larger than the customer form'
);

// Field keys must be unique within a form, because the handset stores them keyed
// by (visit type, field key).
foreach (['customer', 'krm_ots', 'ckcc_od2'] as $visitType) {
    $keys = array_map(
        static fn (array $field): string => (string) ($field['key'] ?? ''),
        $byType[$visitType]['fields'] ?? []
    );

    equals(count($keys), count(array_unique($keys)), 'The ' . $visitType . ' form has no duplicate field keys');
}

// Every form the app is told about must be one the server will accept a visit
// against, so the per-type endpoint has to agree.
foreach (['customer', 'krm_ots', 'ckcc_od2'] as $visitType) {
    $single = api('GET', '/api/v1/visit-form?visit_type=' . $visitType);
    equals(200, $single['status'], 'GET /visit-form?visit_type=' . $visitType . ' succeeds');
    equals(
        $visitType,
        $single['json']['data']['form']['visit_type'] ?? '',
        'The per-type endpoint returns the ' . $visitType . ' form'
    );
}
ok(isset($pull['json']['data']['rules']['min_visit_photos']), 'Sync pull includes the rules the app enforces');

// Every account returned must belong to this supervisor.
$leaked = 0;

foreach ($accounts as $account) {
    $owner = (int) Database::scalar(
        'SELECT bc_supervisor_id FROM account_assignments WHERE loan_account_id = :id AND is_active = 1',
        ['id' => (int) $account['id']]
    );

    if ($owner !== (int) $supervisor['id']) {
        $leaked++;
    }
}

equals(0, $leaked, 'Sync pull only returns accounts allocated to this supervisor');

$accountId = (int) $accounts[0]['id'];

$detail = api('GET', '/api/v1/accounts/' . $accountId);
equals(200, $detail['status'], 'GET /accounts/{id} succeeds for an allocated account');

// An account belonging to someone else must be refused.
$otherAccountId = (int) Database::scalar(
    'SELECT a.id FROM loan_accounts a
       JOIN account_assignments x ON x.loan_account_id = a.id AND x.is_active = 1
      WHERE x.bc_supervisor_id <> :bc LIMIT 1',
    ['bc' => (int) $supervisor['id']]
);

if ($otherAccountId > 0) {
    $forbidden = api('GET', '/api/v1/accounts/' . $otherAccountId);
    equals(403, $forbidden['status'], 'An account allocated to another supervisor is refused (403)');
} else {
    ok(true, 'No other supervisor has accounts (skipped)');
}

/* -------------------------------------------------------------------------- */
section('Visit flow: start, photo, submit');
/* -------------------------------------------------------------------------- */

// Nothing about a visit is mandatory any more, location included: a supervisor in
// a village with no fix, or inside a thick-walled house, files the report instead
// of losing the visit. What must not happen is the report *claiming* a verified
// location it does not have — so each case below is accepted and then checked to
// be recorded as unverified, with the reason kept against the point.
$verdictOf = static fn (string $uuid): array => Database::selectOne(
    'SELECT v.gps_verified, g.is_valid, g.validation_note
       FROM visits v
  LEFT JOIN visit_gps g ON g.visit_id = v.id
      WHERE v.uuid = :u
      LIMIT 1',
    ['u' => $uuid]
) ?? [];

$noGpsUuid = uuid();
$noGps = api('POST', '/api/v1/visits', ['loan_account_id' => $accountId, 'uuid' => $noGpsUuid]);
equals(201, $noGps['status'], 'A visit with no location can still be started');
equals(0, (int) ($verdictOf($noGpsUuid)['gps_verified'] ?? -1), 'A visit with no location is not marked GPS-verified');

$badAccuracyUuid = uuid();
$badAccuracy = api('POST', '/api/v1/visits', [
    'uuid' => $badAccuracyUuid,
    'loan_account_id' => $accountId,
    'gps' => ['latitude' => 25.5389, 'longitude' => 87.5719, 'accuracy' => 5000],
]);
equals(201, $badAccuracy['status'], 'A poor fix no longer refuses the visit');

$badAccuracyVerdict = $verdictOf($badAccuracyUuid);
equals(0, (int) ($badAccuracyVerdict['gps_verified'] ?? -1), 'A fix beyond the accuracy limit is recorded unverified');
equals(0, (int) ($badAccuracyVerdict['is_valid'] ?? -1), 'The point itself is stored as invalid');
ok(
    trim((string) ($badAccuracyVerdict['validation_note'] ?? '')) !== '',
    'The reason the fix was rejected is kept with it'
);

$mockUuid = uuid();
$mock = api('POST', '/api/v1/visits', [
    'uuid' => $mockUuid,
    'loan_account_id' => $accountId,
    'gps' => ['latitude' => 25.5389, 'longitude' => 87.5719, 'accuracy' => 12, 'is_mock' => true],
]);
equals(201, $mock['status'], 'A mock location no longer refuses the visit');
equals(0, (int) ($verdictOf($mockUuid)['gps_verified'] ?? -1), 'A mock location is recorded unverified, not verified');

$nullIslandUuid = uuid();
$nullIsland = api('POST', '/api/v1/visits', [
    'uuid' => $nullIslandUuid,
    'loan_account_id' => $accountId,
    'gps' => ['latitude' => 0, 'longitude' => 0, 'accuracy' => 8],
]);
equals(201, $nullIsland['status'], 'Coordinates of 0,0 no longer refuse the visit');
equals(0, (int) ($verdictOf($nullIslandUuid)['gps_verified'] ?? -1), 'Coordinates of 0,0 are recorded unverified');

// And a real fix must still come out verified, or the flag would mean nothing.
$goodUuid = uuid();
api('POST', '/api/v1/visits', [
    'uuid' => $goodUuid,
    'loan_account_id' => $accountId,
    'gps' => ['latitude' => 25.5391, 'longitude' => 87.5721, 'accuracy' => 8],
]);
equals(1, (int) ($verdictOf($goodUuid)['gps_verified'] ?? -1), 'A good fix is still marked GPS-verified');

$visitUuid = uuid();
$gps = [
    'latitude' => 25.5391234,
    'longitude' => 87.5721234,
    'accuracy' => 8.5,
    'provider' => 'gps',
    'address' => 'Test village, Katihar',
    'captured_at' => date('Y-m-d H:i:s'),
];

$start = api('POST', '/api/v1/visits', [
    'uuid' => $visitUuid,
    'loan_account_id' => $accountId,
    'visit_date' => today(),
    'gps' => $gps,
]);

equals(201, $start['status'], 'Starting a visit with a valid fix succeeds');
equals($visitUuid, $start['json']['data']['visit']['uuid'] ?? '', 'The visit keeps the client uuid');
equals('draft', $start['json']['data']['visit']['status'] ?? '', 'A started visit is a draft until submitted');

/* -------------------------------------------------------------------------- */
section('This system does not collect cash');
/* -------------------------------------------------------------------------- */

// Recovery follow-up is the work; taking money is not. The borrower pays the bank and
// the agent records the bank's reference, so no payment mode may mean "handed to me".
$offeredModes = payment_modes();

equals(
    [],
    array_values(array_filter(
        $offeredModes,
        static fn (string $mode): bool => stripos($mode, 'cash') !== false
    )),
    'No offered payment mode is cash (' . implode(', ', $offeredModes) . ')'
);

// The important half. An unrecognised mode used to be replaced by the first entry of
// the allowed list, which was 'Cash' — so a typo, or an app version older than this
// one, was filed as a cash collection that never happened. It is kept as reported now,
// which is what lets a supervisor see an old handset still reporting the old mode
// instead of the server quietly agreeing with it.
$legacyUuid = uuid();
$legacy = api('POST', '/api/v1/recoveries', [
    'uuid' => $legacyUuid,
    'loan_account_id' => $accountId,
    'amount' => 900,
    'recovery_date' => today(),
    'payment_mode' => 'Cash',
    'receipt_number' => 'LEGACY-' . random_int(100000, 999999),
]);

ok(in_array($legacy['status'], [200, 201], true), 'A repayment from an older app is still accepted');
equals(
    'Cash',
    (string) Database::scalar('SELECT payment_mode FROM recoveries WHERE uuid = :u', ['u' => $legacyUuid]),
    'What that app reported is stored as reported, not relabelled'
);

$typoUuid = uuid();
api('POST', '/api/v1/recoveries', [
    'uuid' => $typoUuid,
    'loan_account_id' => $accountId,
    'amount' => 400,
    'recovery_date' => today(),
    'payment_mode' => 'NEFT transfer',
]);

equals(
    'NEFT transfer',
    (string) Database::scalar('SELECT payment_mode FROM recoveries WHERE uuid = :u', ['u' => $typoUuid]),
    'A mode outside the list is not silently turned into a cash collection'
);

// MariaDB reports a string default with its quotes, MySQL without them, so the
// comparison strips them the same way database/upgrade.php does.
equals(
    'Other',
    trim((string) Database::scalar(
        "SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recoveries' AND COLUMN_NAME = 'payment_mode'"
    ), "'"),
    'A row inserted without a mode does not default to claiming cash'
);

/* -------------------------------------------------------------------------- */
section('The sign-in throttle does not lock out a whole team');
/* -------------------------------------------------------------------------- */

// A supervisor reported 429 on sign-in. The throttle counted five failures per IP
// address, and Indian mobile carriers put many subscribers behind one public
// address while a branch shares one office connection — so one person mistyping a
// password locked out everyone who appeared to come from the same place. The
// per-username limit is what guards an account; the IP ceiling only exists to stop
// a flood.
$ipLimit = (int) Config::get('security.login_ip_max_attempts', 50);
$userLimit = (int) Config::get('security.login_max_attempts', 5);

ok($userLimit <= 10, sprintf('The per-username limit stays strict (%d)', $userLimit));
ok($ipLimit >= 25, sprintf('The per-IP ceiling is not a team-wide lockout (%d)', $ipLimit));
ok($ipLimit > $userLimit * 3, 'The IP ceiling is far above the username limit');

// Different accounts from the same address must not consume each other's budget.
// Six distinct usernames fail here; with the old shared limit of five the sixth
// would already be throttled instead of simply refused.
$sameAddressCodes = [];

for ($i = 1; $i <= 6; $i++) {
    $attempt = api('POST', '/api/v1/auth/login', [
        'username' => sprintf('no-such-user-%d-%s', $i, bin2hex(random_bytes(3))),
        'password' => 'definitely-wrong',
        'device' => ['uuid' => 'throttle-probe-' . $i],
    ], ['auth' => false]);

    $sameAddressCodes[] = $attempt['status'];
}

equals(
    [],
    array_values(array_filter($sameAddressCodes, static fn (int $code): bool => $code === 429)),
    'Six failed sign-ins for six different accounts from one address are not throttled'
);

ok(
    array_values(array_unique($sameAddressCodes)) === [401],
    'Each is simply refused as wrong credentials (got ' . implode(',', array_unique($sameAddressCodes)) . ')'
);

// One account hammered is still stopped — that is the case the limit is for.
$victim = 'brute-target-' . bin2hex(random_bytes(3));
$victimCodes = [];

for ($i = 0; $i <= $userLimit; $i++) {
    $attempt = api('POST', '/api/v1/auth/login', [
        'username' => $victim,
        'password' => 'guess-' . $i,
        'device' => ['uuid' => 'brute-probe'],
    ], ['auth' => false]);

    $victimCodes[] = $attempt['status'];
}

ok(
    in_array(429, $victimCodes, true),
    'Repeated attempts against one username are throttled (' . implode(',', $victimCodes) . ')'
);

/* -------------------------------------------------------------------------- */
section('Case type travels from the handset to the printed report');
/* -------------------------------------------------------------------------- */

// Section 1 of the paper form ticks one of six case types, and the server has
// always accepted all six. The app never sent one, so Recovery Follow-up, Pre-NPA
// Verification and Post-NPA Verification could not be filed at all: whatever the
// account's work stream happened to be is what got printed. These lock down the
// wire contract the handset now uses.
$caseTypes = ['krm_ots', 'ckcc_od2', 'recovery_followup', 'pre_npa', 'post_npa', 'other', 'customer'];

foreach ($caseTypes as $caseType) {
    $caseUuid = uuid();
    $started = api('POST', '/api/v1/visits', [
        'uuid' => $caseUuid,
        'loan_account_id' => $accountId,
        'visit_type' => $caseType,
        'visit_date' => today(),
        'gps' => $gps,
    ]);

    equals(201, $started['status'], 'A ' . $caseType . ' visit can be started');
    equals(
        $caseType,
        (string) Database::scalar('SELECT visit_type FROM visits WHERE uuid = :u', ['u' => $caseUuid]),
        'The server stores the requested case type: ' . $caseType
    );
}

// An unknown type must not be stored as given; it falls back to the account's
// stream rather than putting a value on the form that has no box to tick.
$junkUuid = uuid();
api('POST', '/api/v1/visits', [
    'uuid' => $junkUuid,
    'loan_account_id' => $accountId,
    'visit_type' => 'not-a-case-type',
    'visit_date' => today(),
    'gps' => $gps,
]);

$fellBackTo = (string) Database::scalar('SELECT visit_type FROM visits WHERE uuid = :u', ['u' => $junkUuid]);
ok(
    in_array($fellBackTo, $caseTypes, true),
    'An unrecognised case type falls back to a real one (got "' . $fellBackTo . '")'
);

// Replaying the start must return the same visit, not create a second one.
$restart = api('POST', '/api/v1/visits', [
    'uuid' => $visitUuid,
    'loan_account_id' => $accountId,
    'gps' => $gps,
]);
equals(200, $restart['status'], 'Replaying the start is accepted');
ok(($restart['json']['data']['created'] ?? true) === false, 'Replaying the start does not create a second visit');
equals(
    1,
    (int) Database::scalar('SELECT COUNT(*) FROM visits WHERE uuid = :uuid', ['uuid' => $visitUuid]),
    'Exactly one visit row exists for that uuid'
);

// A visit with no photograph submits: a locked house with nobody to photograph is
// still a real finding, and refusing it meant the finding was never recorded.
// Checked on its own visit, because it now really does submit — running it against
// the visit the rest of this section uses would close it early.
$noPhotoUuid = uuid();
api('POST', '/api/v1/visits', [
    'uuid' => $noPhotoUuid,
    'loan_account_id' => $accountId,
    'visit_date' => today(),
    'gps' => $gps,
]);

$noPhoto = api('POST', '/api/v1/visits/' . $noPhotoUuid . '/submit', [
    'visit_status' => 'house_locked',
    'form' => ['visit_status' => 'House locked'],
]);

equals(200, $noPhoto['status'], 'A visit with no photograph and almost nothing filled in submits');
equals(
    'submitted',
    (string) Database::scalar('SELECT status FROM visits WHERE uuid = :u', ['u' => $noPhotoUuid]),
    'That visit is recorded as submitted'
);
equals(
    0,
    (int) Database::scalar(
        'SELECT COUNT(*) FROM visit_photos p JOIN visits v ON v.id = p.visit_id WHERE v.uuid = :u',
        ['u' => $noPhotoUuid]
    ),
    'It carries no photographs, and says so'
);

// A visit with no usable location anywhere in it submits too. This was the last
// place a report could still be thrown away for its location: the submit step
// counted the validated points and refused when there were none, so a supervisor in
// a village with no signal filled in the whole report and lost it at the final tap.
// It is accepted now, and the report says what the location was worth.
$noFixUuid = uuid();
api('POST', '/api/v1/visits', [
    'uuid' => $noFixUuid,
    'loan_account_id' => $accountId,
    'visit_date' => today(),
]);

$noFixSubmit = api('POST', '/api/v1/visits/' . $noFixUuid . '/submit', [
    'visit_status' => 'customer_met',
    'form' => ['visit_status' => 'Customer met'],
]);

equals(200, $noFixSubmit['status'], 'A visit with no usable location still submits');

$noFixRow = Database::selectOne(
    'SELECT status, gps_verified, gps_note FROM visits WHERE uuid = :u',
    ['u' => $noFixUuid]
) ?? [];

equals('submitted', (string) ($noFixRow['status'] ?? ''), 'It is recorded as submitted, not lost');
equals(0, (int) ($noFixRow['gps_verified'] ?? -1), 'It is not marked GPS-verified');
ok(
    trim((string) ($noFixRow['gps_note'] ?? '')) !== '',
    'The report carries the reason its location is worth nothing'
);

// The fix taken when the report is filed is kept alongside the one from when the
// visit was started. Both are real events and the panel shows both.
$twoFixUuid = uuid();
api('POST', '/api/v1/visits', [
    'uuid' => $twoFixUuid,
    'loan_account_id' => $accountId,
    'visit_date' => today(),
    'gps' => $gps,
]);

api('POST', '/api/v1/visits/' . $twoFixUuid . '/submit', [
    'visit_status' => 'customer_met',
    'form' => ['visit_status' => 'Customer met'],
    'submit_gps' => [
        'latitude' => 25.5391500,
        'longitude' => 87.5721500,
        'accuracy' => 9.0,
        'captured_at' => date('Y-m-d H:i:s'),
    ],
]);

$eventsOf = static fn (string $uuid): array => array_map(
    static fn (array $row): string => (string) $row['event'],
    Database::select(
        'SELECT g.event FROM visit_gps g JOIN visits v ON v.id = g.visit_id
          WHERE v.uuid = :u ORDER BY g.id',
        ['u' => $uuid]
    )
);

equals(['start', 'submit'], $eventsOf($twoFixUuid), 'The visit keeps both its opening and closing fix');

// Filing the report a long way from the doorstep is recorded. The visit is still
// accepted — an agent may legitimately reach signal down the road — but a reviewer
// can see that the report was not filed where the visit happened.
$driftUuid = uuid();
api('POST', '/api/v1/visits', [
    'uuid' => $driftUuid,
    'loan_account_id' => $accountId,
    'visit_date' => today(),
    'gps' => $gps,
]);

api('POST', '/api/v1/visits/' . $driftUuid . '/submit', [
    'visit_status' => 'customer_met',
    'form' => ['visit_status' => 'Customer met'],
    // Roughly 2 km north of where the visit started.
    'submit_gps' => [
        'latitude' => 25.5571234,
        'longitude' => 87.5721234,
        'accuracy' => 9.0,
        'captured_at' => date('Y-m-d H:i:s'),
    ],
]);

$driftNote = (string) Database::scalar('SELECT gps_note FROM visits WHERE uuid = :u', ['u' => $driftUuid]);
ok($driftNote !== '' && str_contains($driftNote, 'from where the visit was started'), 'A report filed far from the doorstep says so (' . $driftNote . ')');

// Picking "Other" for occupation and saying which: the answer typed is what gets
// stored, so the printed report ticks Other and prints the trade beside it. The
// column could previously only ever hold the word "Other", which left the report's
// "Occupation as recorded" line unreachable.
$occupationUuid = uuid();
api('POST', '/api/v1/visits', [
    'uuid' => $occupationUuid,
    'loan_account_id' => $accountId,
    'visit_date' => today(),
    'visit_type' => 'krm_ots',
    'gps' => $gps,
]);

api('POST', '/api/v1/visits/' . $occupationUuid . '/submit', [
    'visit_status' => 'customer_met',
    'form' => [
        'customer_available' => 'Yes',
        'occupation' => 'Other',
        'occupation_other' => 'Tailoring',
    ],
]);

equals(
    'Tailoring',
    (string) Database::scalar('SELECT occupation FROM visits WHERE uuid = :u', ['u' => $occupationUuid]),
    'An "Other" occupation is stored as the trade the agent typed'
);

// And one of the six listed trades is stored as itself, not overwritten by a stale
// free-text box the agent filled in and then changed their mind about.
$listedUuid = uuid();
api('POST', '/api/v1/visits', [
    'uuid' => $listedUuid,
    'loan_account_id' => $accountId,
    'visit_date' => today(),
    'visit_type' => 'krm_ots',
    'gps' => $gps,
]);

api('POST', '/api/v1/visits/' . $listedUuid . '/submit', [
    'visit_status' => 'customer_met',
    'form' => [
        'customer_available' => 'Yes',
        'occupation' => 'Dairy',
        'occupation_other' => 'Tailoring',
    ],
]);

equals(
    'Dairy',
    (string) Database::scalar('SELECT occupation FROM visits WHERE uuid = :u', ['u' => $listedUuid]),
    'A listed occupation is not overwritten by a leftover "other" box'
);

$photo = api('POST', '/api/v1/visits/' . $visitUuid . '/photos', [
    'data' => samplePhoto(),
    'photo_type' => 'customer',
    'caption' => 'Borrower at home',
    'latitude' => $gps['latitude'],
    'longitude' => $gps['longitude'],
    'accuracy' => $gps['accuracy'],
    'address' => $gps['address'],
    'captured_at' => date('Y-m-d H:i:s'),
]);

equals(201, $photo['status'], 'Uploading a photograph succeeds');
equals(1, $photo['json']['data']['photo_count'] ?? 0, 'The visit now has one photograph');

$visitId = (int) Database::scalar('SELECT id FROM visits WHERE uuid = :uuid', ['uuid' => $visitUuid]);
$photoRow = Database::selectOne('SELECT * FROM visit_photos WHERE visit_id = :id ORDER BY id DESC LIMIT 1', ['id' => $visitId]);

ok($photoRow !== null && (int) $photoRow['watermarked'] === 1, 'The stored photograph is watermarked');
ok($photoRow !== null && is_file(storage_path((string) $photoRow['file_path'])), 'The photograph file exists on disk');
ok($photoRow !== null && (string) $photoRow['mime_type'] === 'image/jpeg', 'The photograph was re-encoded as JPEG (EXIF stripped)');

// Re-uploading the identical image must be recognised as a duplicate.
$duplicatePhoto = api('POST', '/api/v1/visits/' . $visitUuid . '/photos', [
    'data' => $photoRow === null ? samplePhoto() : base64_encode((string) file_get_contents(storage_path((string) $photoRow['file_path']))),
    'photo_type' => 'customer',
]);
ok(
    ($duplicatePhoto['json']['data']['photo_count'] ?? 0) <= 2,
    'Re-uploading a photograph does not multiply the evidence'
);

$submit = api('POST', '/api/v1/visits/' . $visitUuid . '/submit', [
    'visit_status' => 'customer_met',
    'recovery_possibility' => 'high',
    'form' => [
        'visit_status' => 'Customer met',
        'customer_available' => 'Yes',
        'family_met' => 'Yes',
        'recovery_possibility' => 'High',
        'promise_amount' => '5000',
        'promise_date' => date('Y-m-d', strtotime('+10 days')),
        'remarks' => 'Borrower met at home; promised part payment.',
        'recommendation' => 'Follow up after the promise date.',
    ],
    'recovery' => [
        'uuid' => uuid(),
        'amount' => 1500,
        'recovery_date' => today(),
        'payment_mode' => 'UPI',
        'receipt_number' => 'RCPT-' . random_int(100000, 999999),
        'remarks' => 'Borrower paid the branch; UPI reference recorded.',
    ],
    'followup' => [
        'uuid' => uuid(),
        'followup_date' => date('Y-m-d', strtotime('+12 days')),
        'action' => 'visit',
        'notes' => 'Confirm the promised payment.',
    ],
]);

equals(200, $submit['status'], 'Submitting the visit succeeds');
equals('submitted', $submit['json']['data']['visit']['status'] ?? '', 'The visit is now submitted');
ok(($submit['json']['data']['recovery_id'] ?? null) !== null, 'The recovery recorded with the visit was created');
ok(($submit['json']['data']['promise_id'] ?? null) !== null, 'The promise from the form was created');
ok(($submit['json']['data']['followup_id'] ?? null) !== null, 'The follow-up was created');

// Server-side effects.
$visitRow = Database::selectOne('SELECT * FROM visits WHERE id = :id', ['id' => $visitId]);
equals('customer_met', (string) $visitRow['visit_status'], 'Visit status was mapped from the form');
equals(1, (int) $visitRow['gps_verified'], 'The visit is marked GPS verified');
ok((int) $visitRow['photo_count'] >= 1, 'The photo counter was updated');

$account = Database::selectOne('SELECT * FROM loan_accounts WHERE id = :id', ['id' => $accountId]);
ok((int) $account['visit_count'] >= 1, 'Account visit count rolled up');
ok((float) $account['total_recovered'] >= 1500, 'Account recovered total rolled up');
equals('partly_recovered', (string) $account['recovery_status'], 'Account recovery status reflects the payment');

ok(
    (int) Database::scalar(
        'SELECT COUNT(*) FROM visit_form_values WHERE visit_id = :id',
        ['id' => $visitId]
    ) > 5,
    'Form answers were stored against the visit'
);

// Replayed submission must be reported, not duplicated.
$resubmit = api('POST', '/api/v1/visits/' . $visitUuid . '/submit', ['visit_status' => 'customer_met']);
equals(200, $resubmit['status'], 'Replaying the submission is accepted');
ok(($resubmit['json']['data']['already_submitted'] ?? false) === true, 'Replayed submission is reported as already submitted');
equals(
    1,
    (int) Database::scalar('SELECT COUNT(*) FROM recoveries WHERE visit_id = :id', ['id' => $visitId]),
    'Replaying does not duplicate the recovery'
);

/* -------------------------------------------------------------------------- */
section('Attendance, deadline and the daily report');
/* -------------------------------------------------------------------------- */

$checkIn = api('POST', '/api/v1/attendance/check-in', [
    'uuid' => uuid(),
    'gps' => $gps,
    'selfie' => 'data:image/jpeg;base64,' . samplePhoto(320, 240),
]);
equals(201, $checkIn['status'], 'Attendance check-in succeeds');

$repeatCheckIn = api('POST', '/api/v1/attendance/check-in', ['uuid' => uuid(), 'gps' => $gps]);
equals(201, $repeatCheckIn['status'], 'A repeated check-in is idempotent');
equals(
    1,
    (int) Database::scalar(
        'SELECT COUNT(*) FROM attendance WHERE bc_supervisor_id = :bc AND attendance_date = :date',
        ['bc' => (int) $supervisor['id'], 'date' => today()]
    ),
    'Only one attendance row exists for today'
);

$deadline = api('GET', '/api/v1/deadline');
equals(200, $deadline['status'], 'GET /deadline succeeds');
ok(isset($deadline['json']['data']['deadline']['seconds_remaining']), 'Deadline response drives the countdown');
ok(($deadline['json']['data']['counts']['visits'] ?? 0) >= 1, 'Deadline response counts today\'s visits');

$report = api('POST', '/api/v1/reports/daily', ['summary' => 'Covered 1 customer, collected part payment.']);
equals(200, $report['status'], 'Daily report submission succeeds');
ok(in_array($report['json']['data']['status'] ?? '', ['submitted', 'late_pending'], true), 'Daily report status is recorded');

$submissionStatus = (string) Database::scalar(
    'SELECT status FROM report_submissions WHERE bc_supervisor_id = :bc AND report_date = :date',
    ['bc' => (int) $supervisor['id'], 'date' => today()]
);
ok(in_array($submissionStatus, ['submitted', 'late_pending'], true), 'The submission row records the outcome');

$checkOut = api('POST', '/api/v1/attendance/check-out', ['gps' => $gps]);
equals(200, $checkOut['status'], 'Attendance check-out succeeds');
ok(
    (int) Database::scalar(
        'SELECT working_minutes IS NOT NULL FROM attendance WHERE bc_supervisor_id = :bc AND attendance_date = :date',
        ['bc' => (int) $supervisor['id'], 'date' => today()]
    ) === 1,
    'Working minutes were computed on check-out'
);

/* -------------------------------------------------------------------------- */
section('Offline sync push');
/* -------------------------------------------------------------------------- */

// A second account for the queued visit.
$secondAccountId = (int) ($accounts[1]['id'] ?? 0);

if ($secondAccountId === 0) {
    ok(false, 'Need at least two allocated accounts for the sync push test');
} else {
    $batchUuid = uuid();
    $queuedVisitUuid = uuid();
    $queuedRecoveryUuid = uuid();

    $batch = [
        'batch_uuid' => $batchUuid,
        'app_version' => '1.0.0-test',
        'network_type' => 'mobile',
        'items' => [
            [
                'type' => 'visit',
                'uuid' => $queuedVisitUuid,
                'payload' => [
                    'uuid' => $queuedVisitUuid,
                    'loan_account_id' => $secondAccountId,
                    'visit_date' => today(),
                    'gps' => $gps,
                    'visit_status' => 'house_locked',
                    'form' => [
                        'visit_status' => 'House locked',
                        'customer_available' => 'No',
                        'recovery_possibility' => 'Low',
                        'remarks' => 'House locked; neighbours said the family is away.',
                    ],
                    'photos' => [
                        ['data' => samplePhoto(480, 360), 'photo_type' => 'house', 'caption' => 'Locked house'],
                    ],
                ],
            ],
            [
                'type' => 'recovery',
                'uuid' => $queuedRecoveryUuid,
                'payload' => [
                    'uuid' => $queuedRecoveryUuid,
                    'loan_account_id' => $accountId,
                    'amount' => 750,
                    'recovery_date' => today(),
                    'payment_mode' => 'UPI',
                    'receipt_number' => 'UPI-' . random_int(100000, 999999),
                ],
            ],
            [
                'type' => 'followup',
                'uuid' => uuid(),
                'payload' => [
                    'loan_account_id' => $accountId,
                    'followup_date' => date('Y-m-d', strtotime('+5 days')),
                    'action' => 'call',
                    'notes' => 'Queued offline follow-up.',
                ],
            ],
            [
                'type' => 'visit',
                'uuid' => uuid(),
                'payload' => [
                    // Deliberately invalid, to prove one bad item cannot poison the
                    // rest of the batch. The reason is an account this supervisor
                    // does not hold, which can never become valid however many times
                    // the phone retries. It used to be a missing GPS fix; that is no
                    // longer a failure, because a village with no signal is not a
                    // reason to throw a real visit away.
                    'loan_account_id' => 999999999,
                    'visit_date' => today(),
                ],
            ],
        ],
    ];

    $push = api('POST', '/api/v1/sync/push', $batch);

    equals(200, $push['status'], 'Sync push responds 200');
    equals(4, $push['json']['data']['received'] ?? 0, 'All four queued items were received');
    equals(3, $push['json']['data']['accepted'] ?? 0, 'Three valid items were accepted');
    equals(1, $push['json']['data']['failed'] ?? 0, 'The invalid item failed on its own');

    $results = $push['json']['data']['results'] ?? [];
    $failedItem = null;

    foreach ($results as $result) {
        if (($result['status'] ?? '') === 'failed') {
            $failedItem = $result;
        }
    }

    ok($failedItem !== null && !empty($failedItem['message']), 'The failed item carries a readable message');
    ok($failedItem !== null && ($failedItem['retryable'] ?? true) === false, 'A validation failure is marked as not retryable');

    ok(
        (int) Database::scalar('SELECT COUNT(*) FROM visits WHERE uuid = :uuid', ['uuid' => $queuedVisitUuid]) === 1,
        'The queued visit was stored'
    );
    equals(
        'submitted',
        (string) Database::scalar('SELECT status FROM visits WHERE uuid = :uuid', ['uuid' => $queuedVisitUuid]),
        'The queued visit was submitted, not left as a draft'
    );
    ok(
        (int) Database::scalar(
            'SELECT COUNT(*) FROM visit_photos p JOIN visits v ON v.id = p.visit_id WHERE v.uuid = :uuid',
            ['uuid' => $queuedVisitUuid]
        ) === 1,
        'The photograph queued with the visit was stored'
    );

    // Replaying the whole batch must not duplicate anything.
    $replay = api('POST', '/api/v1/sync/push', $batch);

    equals(200, $replay['status'], 'Replaying the batch responds 200');
    ok(($replay['json']['data']['duplicates'] ?? 0) >= 2, 'Replayed items are reported as duplicates');

    equals(
        1,
        (int) Database::scalar('SELECT COUNT(*) FROM visits WHERE uuid = :uuid', ['uuid' => $queuedVisitUuid]),
        'Replaying the batch created no second visit'
    );
    equals(
        1,
        (int) Database::scalar('SELECT COUNT(*) FROM recoveries WHERE uuid = :uuid', ['uuid' => $queuedRecoveryUuid]),
        'Replaying the batch created no second recovery'
    );
    equals(
        1,
        (int) Database::scalar('SELECT COUNT(*) FROM sync_batches WHERE batch_uuid = :uuid', ['uuid' => $batchUuid]),
        'The batch itself is recorded once'
    );
}

/* -------------------------------------------------------------------------- */
section('Notifications, location ping and sign-out');
/* -------------------------------------------------------------------------- */

$notifications = api('GET', '/api/v1/notifications');
equals(200, $notifications['status'], 'GET /notifications succeeds');
ok(isset($notifications['json']['data']['unread']), 'Notifications response includes the unread count');

$location = api('POST', '/api/v1/sync/location', ['gps' => $gps]);
equals(200, $location['status'], 'Location ping succeeds');
ok(
    (int) Database::scalar(
        'SELECT COUNT(*) FROM devices WHERE device_uuid = :uuid AND last_latitude IS NOT NULL',
        ['uuid' => $deviceUuid]
    ) === 1,
    'The device last known position was recorded'
);

$incremental = api('GET', '/api/v1/sync/pull?since=' . urlencode(date('Y-m-d H:i:s', strtotime('-1 hour'))));
equals(200, $incremental['status'], 'Incremental sync pull succeeds');
ok(isset($incremental['json']['data']['removed_account_ids']), 'Incremental pull reports removed allocations');

$logout = api('POST', '/api/v1/auth/logout');
equals(200, $logout['status'], 'Sign-out succeeds');

$afterLogout = api('GET', '/api/v1/me');
equals(401, $afterLogout['status'], 'The token no longer works after sign-out');

/* -------------------------------------------------------------------------- */
section('Audit trail recorded the field activity');
/* -------------------------------------------------------------------------- */

foreach ([
    'login' => 'App sign-in',
    'visit_submitted' => 'Visit submission',
    'recovery_recorded' => 'Recovery',
    'promise_recorded' => 'Promise to pay',
    'attendance_check_in' => 'Attendance check-in',
    'sync_push' => 'Sync push',
    'logout' => 'Sign-out',
] as $action => $label) {
    ok(
        (int) Database::scalar('SELECT COUNT(*) FROM audit_logs WHERE action = :a', ['a' => $action]) > 0,
        $label . ' is in the audit log'
    );
}

if ($serverProcess !== null) {
    proc_terminate($serverProcess);
}

exit(TestRunner::summary());
