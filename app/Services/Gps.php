<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Settings;

/**
 * Server-side GPS validation.
 *
 * The device is never trusted: coordinates, accuracy, mock-location flags and
 * timestamps are all re-checked here before a point is accepted, and the result
 * is stored alongside the point so a reviewer can see why it passed or failed.
 */
final class Gps
{
    /**
     * Validate a raw location payload from the app or the inspection form.
     *
     * @param array<string, mixed> $point
     * @return array{
     *   valid: bool,
     *   note: string,
     *   latitude: float,
     *   longitude: float,
     *   accuracy: float|null,
     *   is_mock: int,
     *   captured_at: string|null
     * }
     */
    public static function validate(array $point, ?int $branchId = null): array
    {
        $latitude = isset($point['latitude']) ? (float) $point['latitude'] : null;
        $longitude = isset($point['longitude']) ? (float) $point['longitude'] : null;
        $accuracy = isset($point['accuracy']) && $point['accuracy'] !== '' ? (float) $point['accuracy'] : null;
        $isMock = !empty($point['is_mock']) ? 1 : 0;
        $notes = [];
        $valid = true;

        if ($latitude === null || $longitude === null) {
            return [
                'valid' => false,
                'note' => 'No coordinates were supplied.',
                'latitude' => 0.0,
                'longitude' => 0.0,
                'accuracy' => null,
                'is_mock' => $isMock,
                'captured_at' => null,
            ];
        }

        if (!self::inRange($latitude, $longitude)) {
            $valid = false;
            $notes[] = 'Coordinates are outside the valid range.';
        }

        // 0,0 is the classic "location services returned nothing" signature.
        if (abs($latitude) < 0.0001 && abs($longitude) < 0.0001) {
            $valid = false;
            $notes[] = 'Null Island coordinates (0,0) rejected.';
        }

        $maxAccuracy = Settings::float('gps_max_accuracy_metres', 200.0);

        if ($accuracy !== null && $maxAccuracy > 0 && $accuracy > $maxAccuracy) {
            $valid = false;
            $notes[] = sprintf('Accuracy %.0f m is worse than the %.0f m limit.', $accuracy, $maxAccuracy);
        }

        if ($isMock === 1 && !Settings::bool('gps_mock_location_allowed', false)) {
            $valid = false;
            $notes[] = 'Mock location detected.';
        }

        // Optional drift check against the branch centroid.
        $maxDrift = Settings::float('gps_max_drift_metres', 0.0);

        if ($maxDrift > 0 && $branchId !== null) {
            $branch = Database::selectOne(
                'SELECT latitude, longitude FROM branches WHERE id = :id',
                ['id' => $branchId]
            );

            if ($branch !== null && $branch['latitude'] !== null && $branch['longitude'] !== null) {
                $distance = self::distance(
                    $latitude,
                    $longitude,
                    (float) $branch['latitude'],
                    (float) $branch['longitude']
                );

                if ($distance > $maxDrift) {
                    $valid = false;
                    $notes[] = sprintf('%.0f m from the branch centroid exceeds the %.0f m limit.', $distance, $maxDrift);
                }
            }
        }

        $capturedAt = self::normaliseTimestamp($point['captured_at'] ?? null);

        return [
            'valid' => $valid,
            'note' => implode(' ', $notes),
            'latitude' => round($latitude, 7),
            'longitude' => round($longitude, 7),
            'accuracy' => $accuracy,
            'is_mock' => $isMock,
            'captured_at' => $capturedAt,
        ];
    }

    /**
     * Persist a validated point for a customer visit.
     */
    public static function recordVisitPoint(int $visitId, string $event, array $point, ?int $branchId = null): array
    {
        $result = self::validate($point, $branchId);

        Database::insert('visit_gps', [
            'visit_id' => $visitId,
            'event' => in_array($event, ['start', 'form', 'photo', 'submit'], true) ? $event : 'start',
            'latitude' => $result['latitude'],
            'longitude' => $result['longitude'],
            'accuracy' => $result['accuracy'],
            'altitude' => isset($point['altitude']) && $point['altitude'] !== '' ? (float) $point['altitude'] : null,
            'speed' => isset($point['speed']) && $point['speed'] !== '' ? (float) $point['speed'] : null,
            'provider' => isset($point['provider']) ? mb_substr((string) $point['provider'], 0, 40) : null,
            'is_mock' => $result['is_mock'],
            'address' => isset($point['address']) ? mb_substr((string) $point['address'], 0, 255) : null,
            'captured_at' => $result['captured_at'],
            'device_time' => self::normaliseTimestamp($point['device_time'] ?? null),
            'server_time' => now(),
            'is_valid' => $result['valid'] ? 1 : 0,
            'validation_note' => $result['note'] !== '' ? mb_substr($result['note'], 0, 255) : null,
            'created_at' => now(),
        ]);

        return $result;
    }

    /**
     * Persist a validated point for a BC Supervisor inspection. When the
     * inspection is linked to a visit, the distance between the inspector and
     * the supervisor's own recorded point is computed — that number is the core
     * evidence for the "was the visit genuine" question.
     */
    public static function recordInspectionPoint(
        int $inspectionId,
        string $event,
        array $point,
        ?int $branchId = null,
        ?int $visitId = null
    ): array {
        $result = self::validate($point, $branchId);
        $distance = null;

        if ($visitId !== null && $result['valid']) {
            $visitPoint = Database::selectOne(
                "SELECT latitude, longitude FROM visit_gps
                  WHERE visit_id = :vid AND is_valid = 1
                  ORDER BY (event = 'start') DESC, id ASC
                  LIMIT 1",
                ['vid' => $visitId]
            );

            if ($visitPoint !== null) {
                $distance = round(self::distance(
                    $result['latitude'],
                    $result['longitude'],
                    (float) $visitPoint['latitude'],
                    (float) $visitPoint['longitude']
                ), 2);
            }
        }

        Database::insert('inspection_gps', [
            'inspection_id' => $inspectionId,
            'event' => in_array($event, ['start', 'form', 'photo', 'submit'], true) ? $event : 'start',
            'latitude' => $result['latitude'],
            'longitude' => $result['longitude'],
            'accuracy' => $result['accuracy'],
            'provider' => isset($point['provider']) ? mb_substr((string) $point['provider'], 0, 40) : null,
            'is_mock' => $result['is_mock'],
            'address' => isset($point['address']) ? mb_substr((string) $point['address'], 0, 255) : null,
            'captured_at' => $result['captured_at'],
            'server_time' => now(),
            'is_valid' => $result['valid'] ? 1 : 0,
            'validation_note' => $result['note'] !== '' ? mb_substr($result['note'], 0, 255) : null,
            'distance_to_visit_metres' => $distance,
            'created_at' => now(),
        ]);

        $result['distance_to_visit_metres'] = $distance;

        return $result;
    }

    public static function inRange(float $latitude, float $longitude): bool
    {
        return $latitude >= -90 && $latitude <= 90 && $longitude >= -180 && $longitude <= 180;
    }

    /**
     * Haversine distance in metres.
     */
    public static function distance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000.0;

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Device clocks drift and can be set by hand, so a supplied timestamp is
     * only accepted when it is plausible; otherwise server time is used.
     */
    public static function normaliseTimestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            // Milliseconds since epoch (Android System.currentTimeMillis()).
            $seconds = (int) ((float) $value > 100000000000 ? (float) $value / 1000 : (float) $value);
        } else {
            $seconds = strtotime((string) $value);

            if ($seconds === false) {
                return null;
            }
        }

        // Reject anything more than a day in the future or 90 days in the past.
        if ($seconds > time() + 86400 || $seconds < time() - (90 * 86400)) {
            return null;
        }

        return date('Y-m-d H:i:s', $seconds);
    }
}
