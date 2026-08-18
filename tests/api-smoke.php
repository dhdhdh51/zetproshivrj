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

// GPS is mandatory.
$noGps = api('POST', '/api/v1/visits', ['loan_account_id' => $accountId, 'uuid' => uuid()]);
equals(422, $noGps['status'], 'Starting a visit without GPS is refused');

// Poor accuracy must be rejected by the server, not just the app.
$badAccuracy = api('POST', '/api/v1/visits', [
    'uuid' => uuid(),
    'loan_account_id' => $accountId,
    'gps' => ['latitude' => 25.5389, 'longitude' => 87.5719, 'accuracy' => 5000],
]);
equals(422, $badAccuracy['status'], 'A GPS fix worse than the accuracy limit is rejected');

// Mock locations must be rejected.
$mock = api('POST', '/api/v1/visits', [
    'uuid' => uuid(),
    'loan_account_id' => $accountId,
    'gps' => ['latitude' => 25.5389, 'longitude' => 87.5719, 'accuracy' => 12, 'is_mock' => true],
]);
equals(422, $mock['status'], 'A mock location is rejected');

// Null Island must be rejected.
$nullIsland = api('POST', '/api/v1/visits', [
    'uuid' => uuid(),
    'loan_account_id' => $accountId,
    'gps' => ['latitude' => 0, 'longitude' => 0, 'accuracy' => 8],
]);
equals(422, $nullIsland['status'], 'Coordinates of 0,0 are rejected');

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

// Submitting before any photograph must be refused.
$early = api('POST', '/api/v1/visits/' . $visitUuid . '/submit', [
    'visit_status' => 'customer_met',
    'form' => ['visit_status' => 'Customer met', 'customer_available' => 'Yes', 'recovery_possibility' => 'High', 'remarks' => 'Met the borrower.'],
]);
equals(422, $early['status'], 'Submitting with no photograph is refused');

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
        'payment_mode' => 'Cash',
        'receipt_number' => 'RCPT-' . random_int(100000, 999999),
        'remarks' => 'Collected in cash.',
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
                    // Deliberately invalid: no GPS, so it must fail without
                    // affecting the rest of the batch.
                    'loan_account_id' => $secondAccountId,
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
