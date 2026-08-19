<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Database;
use App\Core\Request;
use App\Core\Settings;
use App\Services\Attendance;
use App\Services\Audit;
use App\Services\Deadline;
use App\Services\Followups;
use App\Services\Forms;
use App\Services\Notify;
use App\Services\Promises;
use App\Services\Recoveries;
use App\Services\Visits;

/**
 * Offline synchronisation.
 *
 * Pull:  everything the device needs to work with no connection — allocated
 *        accounts, the configured visit form, the rules the app must enforce and
 *        the server's authoritative deadline.
 *
 * Push:  a batch of queued records. Each item is processed independently and
 *        reported back by its client uuid, so the app can retire exactly the rows
 *        the server accepted and retry only the ones that failed. Replaying a
 *        batch is safe: uuid collisions are reported as duplicates, not errors.
 */
final class SyncController extends ApiController
{
    /**
     * GET /api/v1/sync/pull?since=ISO8601
     */
    public function pull(Request $request): void
    {
        $supervisor = $this->supervisor();
        $supervisorId = (int) $supervisor['id'];
        $since = $request->input('since');
        $sinceStamp = null;

        if (is_string($since) && $since !== '') {
            $timestamp = strtotime($since);
            $sinceStamp = $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
        }

        $params = ['bc' => $supervisorId];
        $accountWhere = ["a.status = 'active'"];

        if ($sinceStamp !== null) {
            // Only rows that changed since the device last synced.
            $accountWhere[] = '(a.updated_at > :since OR x.updated_at > :since)';
            $params['since'] = $sinceStamp;
        }

        $accounts = Database::select(
            'SELECT a.id, a.account_number, a.cif, a.borrower_name, a.father_name, a.mobile,
                    a.village, a.address, a.loan_type, a.sanction_date, a.npa_date,
                    a.limit_amount, a.outstanding, a.overdue, a.total_recovered,
                    a.loan_category, a.recovery_status, a.visit_count, a.last_visit_at,
                    a.updated_at, b.name AS branch_name, b.code AS branch_code,
                    x.assigned_at
               FROM loan_accounts a
               JOIN account_assignments x ON x.loan_account_id = a.id AND x.is_active = 1
               JOIN branches b ON b.id = a.branch_id
              WHERE x.bc_supervisor_id = :bc AND ' . implode(' AND ', $accountWhere)
            . ' ORDER BY a.overdue DESC
              LIMIT 3000',
            $params
        );

        // Accounts taken away from this supervisor must disappear from the device.
        $removed = [];

        if ($sinceStamp !== null) {
            $removed = array_map(
                static fn (array $row): int => (int) $row['loan_account_id'],
                Database::select(
                    'SELECT loan_account_id FROM account_assignments
                      WHERE bc_supervisor_id = :bc AND is_active IS NULL AND unassigned_at > :since',
                    ['bc' => $supervisorId, 'since' => $sinceStamp]
                )
            );
        }

        // Every visit type the app might need, not just the customer form. A KRM
        // OTS or CKCC OD-2 account is verified on its own 13-section form, and
        // sending only the generic one left the supervisor filling 21 questions
        // while the printed report expected 42 or 46 — the extra sections came out
        // blank with nothing on the phone to fill them.
        $visitForms = [];

        foreach (['customer', 'krm_ots', 'ckcc_od2'] as $visitType) {
            $form = Forms::defaultForm(Forms::KIND_VISIT, $visitType);

            if ($form === null || (string) $form['visit_type'] !== $visitType) {
                continue;
            }

            $visitForms[] = [
                'id' => (int) $form['id'],
                'name' => $form['name'],
                'version' => (int) $form['version'],
                'visit_type' => $visitType,
                'fields' => Forms::definitionForApp(Forms::KIND_VISIT, (int) $form['id']),
            ];
        }

        $visitForm = Forms::defaultForm(Forms::KIND_VISIT, 'customer');

        $this->ok([
            'synced_at' => now(),
            'accounts' => array_map(static function (array $account): array {
                return [
                    'id' => (int) $account['id'],
                    'account_number' => $account['account_number'],
                    'cif' => $account['cif'],
                    'borrower_name' => $account['borrower_name'],
                    'father_name' => $account['father_name'],
                    'mobile' => $account['mobile'],
                    'village' => $account['village'],
                    'address' => $account['address'],
                    'loan_type' => $account['loan_type'],
                    'sanction_date' => $account['sanction_date'],
                    'npa_date' => $account['npa_date'],
                    'limit_amount' => (float) $account['limit_amount'],
                    'outstanding' => (float) $account['outstanding'],
                    'overdue' => (float) $account['overdue'],
                    'total_recovered' => (float) $account['total_recovered'],
                    'loan_category' => $account['loan_category'],
                    'recovery_status' => $account['recovery_status'],
                    'visit_count' => (int) $account['visit_count'],
                    'last_visit_at' => $account['last_visit_at'],
                    'branch_name' => $account['branch_name'],
                    'branch_code' => $account['branch_code'],
                    'updated_at' => $account['updated_at'],
                ];
            }, $accounts),
            'removed_account_ids' => $removed,
            'visit_forms' => $visitForms,
            // Kept for handsets running an APK built before visit_forms existed:
            // dropping it would leave them with no form at all after a server
            // update, which is worse than the generic form they have today.
            'visit_form' => $visitForm === null ? null : [
                'id' => (int) $visitForm['id'],
                'name' => $visitForm['name'],
                'version' => (int) $visitForm['version'],
                'visit_type' => $visitForm['visit_type'],
                'fields' => Forms::definitionForApp(Forms::KIND_VISIT, (int) $visitForm['id']),
            ],
            'rules' => $this->rules(),
            'deadline' => Deadline::status(),
            'notifications' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'title' => $row['title'],
                'body' => $row['body'],
                'type' => $row['type'],
                'is_read' => (int) $row['is_read'] === 1,
                'created_at' => $row['created_at'],
            ], Notify::forUser(auth_user() ?? [], 40)),
        ]);
    }

    /**
     * The server-side rules the app should also enforce locally, so a supervisor
     * is told about a problem before walking away from the customer.
     *
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'min_visit_photos' => Settings::int('min_visit_photos', 1),
            'require_borrower_signature' => Settings::bool('require_borrower_signature', false),
            'gps_max_accuracy_metres' => Settings::float('gps_max_accuracy_metres', 200.0),
            'gps_mock_location_allowed' => Settings::bool('gps_mock_location_allowed', false),
            'payment_modes' => payment_modes(),
            'visit_statuses' => [
                'customer_met' => 'Customer met',
                'family_met' => 'Family met',
                'phone_contact' => 'Phone contact only',
                'house_locked' => 'House locked',
                'not_available' => 'Customer not available',
                'address_not_found' => 'Address not found',
                'deceased' => 'Deceased',
                'shifted' => 'Shifted',
                'refused' => 'Refused to pay',
                'other' => 'Other',
            ],
            'photo_types' => photo_types(),
            'recovery_possibility' => ['high' => 'High', 'medium' => 'Medium', 'low' => 'Low', 'nil' => 'Nil'],
            'max_backdate_days' => 7,
        ];
    }

    /**
     * POST /api/v1/sync/push
     *
     * Body: { batch_uuid, app_version, network_type, items: [ { type, uuid, payload } ] }
     * where type is visit | recovery | promise | followup | attendance_in |
     * attendance_out | daily_report.
     */
    public function push(Request $request): void
    {
        $supervisor = $this->supervisor();
        $supervisorId = (int) $supervisor['id'];
        $branchId = (int) $supervisor['branch_id'];
        $userId = (int) (auth_user()['id'] ?? 0);

        $items = $request->raw('items');

        if (!is_array($items)) {
            $this->fail('The sync batch contained no items.', 422, 'empty_batch');

            return;
        }

        if (count($items) > 200) {
            $this->fail('Send at most 200 items per batch.', 422, 'batch_too_large');

            return;
        }

        $batchUuid = Recoveries::uuid($request->input('batch_uuid'));

        // A replayed batch must not be counted twice.
        $existingBatch = Database::selectOne('SELECT id FROM sync_batches WHERE batch_uuid = :uuid', ['uuid' => $batchUuid]);
        $batchId = $existingBatch === null
            ? Database::insert('sync_batches', [
                'batch_uuid' => $batchUuid,
                'user_id' => $userId,
                'device_id' => $this->deviceId(),
                'direction' => 'push',
                'items_received' => count($items),
                'app_version' => $request->input('app_version'),
                'network_type' => $request->input('network_type'),
                'started_at' => now(),
                'created_at' => now(),
            ])
            : (int) $existingBatch['id'];

        $visits = new Visits();
        $attendance = new Attendance();

        $results = [];
        $accepted = 0;
        $duplicates = 0;
        $failed = 0;

        foreach ($items as $index => $item) {
            $type = is_array($item) ? (string) ($item['type'] ?? '') : '';
            $payload = is_array($item) && is_array($item['payload'] ?? null) ? $item['payload'] : [];
            $uuid = (string) ($item['uuid'] ?? ($payload['uuid'] ?? ''));

            if ($uuid !== '') {
                $payload['uuid'] = $uuid;
            }

            try {
                $outcome = match ($type) {
                    'visit' => $this->pushVisit($visits, $supervisorId, $payload, $batchId),
                    'recovery' => $this->pushRecovery($supervisorId, $branchId, $payload),
                    'promise' => $this->pushPromise($supervisorId, $branchId, $payload),
                    'followup' => $this->pushFollowup($supervisorId, $branchId, $payload),
                    'attendance_in' => ['status' => 'accepted', 'id' => (int) ($attendance->checkIn($supervisorId, $userId, $payload, $this->deviceId())['id'] ?? 0)],
                    'attendance_out' => ['status' => 'accepted', 'id' => (int) ($attendance->checkOut($supervisorId, $payload, $this->deviceId())['id'] ?? 0)],
                    'daily_report' => $this->pushDailyReport($supervisorId, $payload),
                    default => throw new \RuntimeException('Unknown item type "' . $type . '".'),
                };

                $results[] = array_merge([
                    'index' => (int) $index,
                    'type' => $type,
                    'uuid' => $uuid,
                ], $outcome);

                if (($outcome['status'] ?? '') === 'duplicate') {
                    $duplicates++;
                } else {
                    $accepted++;
                }
            } catch (\Throwable $e) {
                $failed++;

                $results[] = [
                    'index' => (int) $index,
                    'type' => $type,
                    'uuid' => $uuid,
                    'status' => 'failed',
                    // The device shows this to the supervisor, so it must be readable.
                    'message' => $e->getMessage(),
                    // Whether retrying could ever succeed: validation problems will
                    // not fix themselves, so the app should stop retrying those.
                    'retryable' => !($e instanceof \App\Core\HttpException) || $e->getStatusCode() >= 500,
                ];
            }
        }

        Database::update('sync_batches', [
            'items_received' => count($items),
            'items_accepted' => $accepted,
            'items_duplicate' => $duplicates,
            'items_failed' => $failed,
            'completed_at' => now(),
        ], 'id = :id', ['id' => $batchId]);

        Audit::log(Audit::SYNC_PUSH, [
            'entity_type' => 'sync_batch',
            'entity_id' => $batchId,
            'description' => sprintf(
                'Sync push: %d received, %d accepted, %d duplicate, %d failed.',
                count($items),
                $accepted,
                $duplicates,
                $failed
            ),
        ]);

        $this->ok([
            'batch_uuid' => $batchUuid,
            'received' => count($items),
            'accepted' => $accepted,
            'duplicates' => $duplicates,
            'failed' => $failed,
            'results' => $results,
            'deadline' => Deadline::status(),
        ]);
    }

    /**
     * A queued visit arrives complete: start + form + photos in one item.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function pushVisit(Visits $visits, int $supervisorId, array $payload, int $batchId): array
    {
        $started = $visits->start($supervisorId, $payload, $this->deviceId(), $batchId);
        $visit = $started['visit'];
        $visitId = (int) $visit['id'];

        if (!empty($payload['gps']) && is_array($payload['gps'])) {
            $this->touchLocation($payload['gps']);
        }

        // Photos travel as base64 inside the queued item.
        $photos = is_array($payload['photos'] ?? null) ? $payload['photos'] : [];
        $photoService = new \App\Services\Photos();
        $stored = 0;

        foreach ($photos as $photo) {
            if (!is_array($photo) || empty($photo['data'])) {
                continue;
            }

            $photoService->storeForVisit($visitId, (string) $photo['data'], [
                'photo_type' => $photo['photo_type'] ?? 'other',
                'caption' => $photo['caption'] ?? null,
                'latitude' => $photo['latitude'] ?? ($payload['gps']['latitude'] ?? null),
                'longitude' => $photo['longitude'] ?? ($payload['gps']['longitude'] ?? null),
                'accuracy' => $photo['accuracy'] ?? ($payload['gps']['accuracy'] ?? null),
                'address' => $photo['address'] ?? ($payload['gps']['address'] ?? null),
                'captured_at' => $photo['captured_at'] ?? null,
            ]);

            $stored++;
        }

        if ((string) $visit['status'] !== 'draft') {
            return [
                'status' => 'duplicate',
                'id' => $visitId,
                'message' => 'This visit was already submitted.',
            ];
        }

        $result = $visits->submit((string) $visit['uuid'], $supervisorId, $payload);

        return [
            'status' => $result['already_submitted'] ? 'duplicate' : 'accepted',
            'id' => $visitId,
            'photos_stored' => $stored,
            'is_late' => (bool) ($result['visit']['is_late'] ?? false),
            'recovery_id' => $result['recovery_id'],
            'promise_id' => $result['promise_id'],
            'followup_id' => $result['followup_id'],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function pushRecovery(int $supervisorId, int $branchId, array $payload): array
    {
        $accountId = (int) ($payload['loan_account_id'] ?? 0);
        (new Visits())->assignedAccount($accountId, $supervisorId);

        $existing = Database::selectOne(
            'SELECT id FROM recoveries WHERE uuid = :uuid',
            ['uuid' => (string) ($payload['uuid'] ?? '')]
        );

        $id = Recoveries::record($accountId, $branchId, $supervisorId, $payload);

        return [
            'status' => $existing === null ? 'accepted' : 'duplicate',
            'id' => $id,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function pushPromise(int $supervisorId, int $branchId, array $payload): array
    {
        $accountId = (int) ($payload['loan_account_id'] ?? 0);
        (new Visits())->assignedAccount($accountId, $supervisorId);

        $existing = Database::selectOne(
            'SELECT id FROM promises WHERE uuid = :uuid',
            ['uuid' => (string) ($payload['uuid'] ?? '')]
        );

        $id = Promises::record($accountId, $branchId, $supervisorId, $payload);

        return ['status' => $existing === null ? 'accepted' : 'duplicate', 'id' => $id];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function pushFollowup(int $supervisorId, int $branchId, array $payload): array
    {
        $accountId = (int) ($payload['loan_account_id'] ?? 0);
        (new Visits())->assignedAccount($accountId, $supervisorId);

        $existing = Database::selectOne(
            'SELECT id FROM followups WHERE uuid = :uuid',
            ['uuid' => (string) ($payload['uuid'] ?? '')]
        );

        $id = Followups::record($accountId, $branchId, $supervisorId, $payload);

        return ['status' => $existing === null ? 'accepted' : 'duplicate', 'id' => $id];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function pushDailyReport(int $supervisorId, array $payload): array
    {
        $result = Deadline::submit($supervisorId, $payload['report_date'] ?? null, array_merge($payload, [
            'device_id' => $this->deviceId(),
        ]));

        return [
            'status' => in_array($result['status'], ['submitted', 'late_pending', 'late_approved'], true) ? 'accepted' : 'failed',
            'id' => $result['submission_id'],
            'is_late' => $result['is_late'],
            'message' => $result['message'],
            'submission_status' => $result['status'],
        ];
    }

    /**
     * POST /api/v1/sync/location — a lightweight position ping.
     */
    public function location(Request $request): void
    {
        $this->supervisor();

        $gps = $request->raw('gps');
        $gps = is_array($gps) ? $gps : $request->all();

        if (($gps['latitude'] ?? '') === '' || ($gps['longitude'] ?? '') === '') {
            $this->fail('Latitude and longitude are required.', 422, 'gps_required');

            return;
        }

        $this->touchLocation($gps);

        $this->ok(['message' => 'Position recorded.']);
    }
}
