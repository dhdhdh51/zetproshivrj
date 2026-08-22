<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;

/**
 * BCA attendance: GPS + selfie check in, GPS check out, working hours.
 *
 * One row per supervisor per day is enforced by a unique key, so a retried
 * offline check-in updates the same row instead of creating a second one.
 */
final class Attendance
{
    /**
     * @param array<string, mixed> $payload gps{}, selfie (base64), remarks, uuid
     * @return array<string, mixed> the attendance row
     */
    public function checkIn(int $bcSupervisorId, int $userId, array $payload, ?int $deviceId = null): array
    {
        $date = today();
        $branchId = (int) Database::scalar('SELECT branch_id FROM bc_supervisors WHERE id = :id', ['id' => $bcSupervisorId]);

        $existing = Database::selectOne(
            'SELECT * FROM attendance WHERE bc_supervisor_id = :bc AND attendance_date = :date',
            ['bc' => $bcSupervisorId, 'date' => $date]
        );

        if ($existing !== null && $existing['check_in_at'] !== null) {
            // Idempotent: report the existing check-in rather than failing a retry.
            return $existing;
        }

        $gps = is_array($payload['gps'] ?? null) ? $payload['gps'] : [];

        if ($gps === []) {
            throw new HttpException(422, 'Location is required to check in.');
        }

        $check = Gps::validate($gps, $branchId);

        if (!$check['valid']) {
            throw new HttpException(422, 'Check-in location rejected: ' . ($check['note'] ?: 'invalid coordinates.'));
        }

        $selfiePath = null;

        if (!empty($payload['selfie'])) {
            $photos = new Photos();
            $stored = $photos->storeSignature((string) $payload['selfie'], 'selfie-' . $bcSupervisorId . '-' . $date);
            $selfiePath = $stored;
        }

        $data = [
            'check_in_at' => Gps::normaliseTimestamp($payload['check_in_at'] ?? null) ?? now(),
            'check_in_lat' => $check['latitude'],
            'check_in_lng' => $check['longitude'],
            'check_in_accuracy' => $check['accuracy'],
            'check_in_address' => isset($gps['address']) ? mb_substr((string) $gps['address'], 0, 255) : null,
            'selfie_path' => $selfiePath,
            'status' => 'present',
            'remarks' => isset($payload['remarks']) ? mb_substr((string) $payload['remarks'], 0, 255) : null,
            'device_id' => $deviceId,
            'updated_at' => now(),
        ];

        if ($existing !== null) {
            Database::update('attendance', $data, 'id = :id', ['id' => (int) $existing['id']]);
            $id = (int) $existing['id'];
        } else {
            $id = Database::insert('attendance', array_merge($data, [
                'uuid' => Recoveries::uuid($payload['uuid'] ?? null),
                'user_id' => $userId,
                'bc_supervisor_id' => $bcSupervisorId,
                'branch_id' => $branchId,
                'attendance_date' => $date,
                'created_at' => now(),
            ]));
        }

        $this->touchDeviceLocation($deviceId, $check, $gps);

        Audit::log(Audit::ATTENDANCE_CHECK_IN, [
            'entity_type' => 'attendance',
            'entity_id' => $id,
            'description' => sprintf('Checked in at %s.', format_time($data['check_in_at'])),
            'new' => ['lat' => $check['latitude'], 'lng' => $check['longitude']],
        ]);

        return Database::selectOne('SELECT * FROM attendance WHERE id = :id', ['id' => $id]) ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function checkOut(int $bcSupervisorId, array $payload, ?int $deviceId = null): array
    {
        $date = today();

        $row = Database::selectOne(
            'SELECT * FROM attendance WHERE bc_supervisor_id = :bc AND attendance_date = :date',
            ['bc' => $bcSupervisorId, 'date' => $date]
        );

        if ($row === null || $row['check_in_at'] === null) {
            throw new HttpException(422, 'You have not checked in today.');
        }

        if ($row['check_out_at'] !== null) {
            return $row;
        }

        $gps = is_array($payload['gps'] ?? null) ? $payload['gps'] : [];

        if ($gps === []) {
            throw new HttpException(422, 'Location is required to check out.');
        }

        $check = Gps::validate($gps, (int) $row['branch_id']);

        if (!$check['valid']) {
            throw new HttpException(422, 'Check-out location rejected: ' . ($check['note'] ?: 'invalid coordinates.'));
        }

        $checkOutAt = Gps::normaliseTimestamp($payload['check_out_at'] ?? null) ?? now();
        $minutes = (int) max(0, round((strtotime($checkOutAt) - strtotime((string) $row['check_in_at'])) / 60));

        $counts = Deadline::dayCounts($bcSupervisorId, $date);

        Database::update('attendance', [
            'check_out_at' => $checkOutAt,
            'check_out_lat' => $check['latitude'],
            'check_out_lng' => $check['longitude'],
            'check_out_accuracy' => $check['accuracy'],
            'check_out_address' => isset($gps['address']) ? mb_substr((string) $gps['address'], 0, 255) : null,
            'working_minutes' => $minutes,
            'visits_count' => $counts['visits'],
            // Under four hours in the field is recorded as a half day.
            'status' => $minutes < 240 ? 'half_day' : 'present',
            'updated_at' => now(),
        ], 'id = :id', ['id' => (int) $row['id']]);

        $this->touchDeviceLocation($deviceId, $check, $gps);

        Audit::log(Audit::ATTENDANCE_CHECK_OUT, [
            'entity_type' => 'attendance',
            'entity_id' => (int) $row['id'],
            'description' => sprintf('Checked out at %s after %s.', format_time($checkOutAt), minutes_to_hours($minutes)),
            'new' => ['working_minutes' => $minutes, 'visits' => $counts['visits']],
        ]);

        return Database::selectOne('SELECT * FROM attendance WHERE id = :id', ['id' => (int) $row['id']]) ?? [];
    }

    /**
     * Today's row for a supervisor, or null when they have not checked in.
     */
    public static function today(int $bcSupervisorId): ?array
    {
        return Database::selectOne(
            'SELECT * FROM attendance WHERE bc_supervisor_id = :bc AND attendance_date = :date',
            ['bc' => $bcSupervisorId, 'date' => today()]
        );
    }

    /**
     * Keep the device's last known position for the monitoring screen. This is a
     * last-seen position, not continuous tracking.
     *
     * @param array<string, mixed> $check
     * @param array<string, mixed> $gps
     */
    private function touchDeviceLocation(?int $deviceId, array $check, array $gps): void
    {
        if ($deviceId === null) {
            return;
        }

        Database::update('devices', [
            'last_latitude' => $check['latitude'],
            'last_longitude' => $check['longitude'],
            'last_location_at' => now(),
            'last_address' => isset($gps['address']) ? mb_substr((string) $gps['address'], 0, 255) : null,
            'last_seen_at' => now(),
            'updated_at' => now(),
        ], 'id = :id', ['id' => $deviceId]);
    }

    /**
     * Mark supervisors who never checked in on a working day as absent, so
     * attendance reports are complete rather than merely missing rows.
     */
    public static function markAbsentees(?string $date = null): int
    {
        $date ??= today();

        if (!Deadline::isWorkingDay($date)) {
            return 0;
        }

        $missing = Database::select(
            "SELECT s.id, s.user_id, s.branch_id
               FROM bc_supervisors s
               JOIN users u ON u.id = s.user_id
              WHERE s.status = 'active' AND u.status = 'active'
                AND NOT EXISTS (
                    SELECT 1 FROM attendance a
                     WHERE a.bc_supervisor_id = s.id AND a.attendance_date = :date
                )",
            ['date' => $date]
        );

        $count = 0;

        foreach ($missing as $supervisor) {
            Database::insert('attendance', [
                'uuid' => uuid4(),
                'user_id' => (int) $supervisor['user_id'],
                'bc_supervisor_id' => (int) $supervisor['id'],
                'branch_id' => (int) $supervisor['branch_id'],
                'attendance_date' => $date,
                'status' => 'absent',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $count++;
        }

        return $count;
    }
}
