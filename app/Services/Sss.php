<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Settings;

/**
 * Social Security Scheme enrolments: what the BC Supervisor signed people up for at the
 * outlet, by day.
 *
 * These are not field work. An enrolment happens over the counter, so there is no account,
 * no doorstep and no photograph — which is why this is its own record rather than another
 * question on the visit form.
 *
 * ONE ROW PER SUPERVISOR PER DAY
 *
 * Every write is an upsert on that pair, and the table enforces it. The app records the
 * day's figures offline and the outbox may deliver them more than once; if a retry
 * appended instead of rewriting, the day would be counted twice and so would every total
 * and target built on it. Correcting a day is the same operation as reporting it.
 *
 * WHAT IS NOT HERE
 *
 * No visit count. Visits, contacts and promises are counted from the reports that were
 * actually filed, and asking a supervisor to type them as well would produce two answers
 * to the same question — with the supervisor measured on one of them while defending the
 * other. The reference implementation this was ported from makes the same point and has a
 * test that fails if such a field ever appears.
 */
final class Sss
{
    /** Highest a single day's count may be, per scheme. */
    public const MAX_PER_SCHEME = 999;

    /**
     * The four schemes, as column => label.
     *
     * Everything loops this: the API validator, the panel form, the totals and the
     * printed report. Adding a scheme is meant to be a one-line change here plus a
     * column, not a hunt through the codebase.
     *
     * @return array<string, string>
     */
    public static function schemes(): array
    {
        return [
            'apy_count' => 'APY',
            'pmjjby_count' => 'PMJJBY',
            'pmsby_count' => 'PMSBY',
            'pmjdy_count' => 'PMJDY',
        ];
    }

    /**
     * The full name behind each abbreviation.
     *
     * PMJJBY and PMSBY differ by two letters and cover different things, so anywhere a
     * supervisor is typing figures gets the words as well as the initials.
     *
     * @return array<string, string>
     */
    public static function schemeNames(): array
    {
        return [
            'apy_count' => 'Atal Pension Yojana',
            'pmjjby_count' => 'Pradhan Mantri Jeevan Jyoti Bima Yojana',
            'pmsby_count' => 'Pradhan Mantri Suraksha Bima Yojana',
            'pmjdy_count' => 'Pradhan Mantri Jan Dhan Yojana',
        ];
    }

    /**
     * Record or correct one supervisor's figures for one day.
     *
     * @param array<string, mixed> $payload apy_count, pmjjby_count, pmsby_count,
     *                                      pmjdy_count, enrolment_date, remarks, uuid
     * @return array{id: int, created: bool, date: string, total: int}
     */
    public static function record(
        int $bcSupervisorId,
        array $payload,
        string $source = 'app',
        ?int $deviceId = null
    ): array {
        $supervisor = Database::selectOne(
            'SELECT id, branch_id FROM bc_supervisors WHERE id = :id',
            ['id' => $bcSupervisorId]
        );

        if ($supervisor === null) {
            throw new HttpException(404, 'That BC Supervisor does not exist.');
        }

        // A supervisor with no branch cannot have figures attributed anywhere, and the
        // column is NOT NULL, so this is caught here with something a person can act on
        // rather than as a constraint violation.
        if ($supervisor['branch_id'] === null) {
            throw new HttpException(422, 'That BC Supervisor has no branch, so enrolments cannot be recorded.');
        }

        $date = self::date($payload['enrolment_date'] ?? null);
        $counts = self::counts($payload);

        $existing = Database::selectOne(
            'SELECT id, uuid FROM sss_enrolments WHERE bc_supervisor_id = :bc AND enrolment_date = :date',
            ['bc' => $bcSupervisorId, 'date' => $date]
        );

        $remarks = isset($payload['remarks']) && trim((string) $payload['remarks']) !== ''
            ? mb_substr(trim((string) $payload['remarks']), 0, 500)
            : null;

        $fields = array_merge($counts, [
            'remarks' => $remarks,
            'source' => $source === 'panel' ? 'panel' : 'app',
            'recorded_by' => Auth::id(),
            'updated_at' => now(),
        ]);

        if ($existing !== null) {
            // The day is rewritten, not added to. The uuid stays whatever first created
            // the row: it identifies the record, and the outbox entry that arrives second
            // is asking for this same day to say something different.
            Database::update('sss_enrolments', $fields, 'id = :id', ['id' => (int) $existing['id']]);

            return [
                'id' => (int) $existing['id'],
                'created' => false,
                'date' => $date,
                'total' => array_sum($counts),
            ];
        }

        $id = Database::insert('sss_enrolments', array_merge($fields, [
            'uuid' => Recoveries::uuid($payload['uuid'] ?? null),
            'bc_supervisor_id' => $bcSupervisorId,
            'branch_id' => (int) $supervisor['branch_id'],
            'enrolment_date' => $date,
            'device_id' => $deviceId,
            'created_at' => now(),
        ]));

        return ['id' => $id, 'created' => true, 'date' => $date, 'total' => array_sum($counts)];
    }

    /**
     * One supervisor's figures for one day, or null when nothing was recorded.
     *
     * @return array<string, mixed>|null
     */
    public static function forDate(int $bcSupervisorId, string $date): ?array
    {
        return Database::selectOne(
            'SELECT * FROM sss_enrolments WHERE bc_supervisor_id = :bc AND enrolment_date = :date',
            ['bc' => $bcSupervisorId, 'date' => $date]
        );
    }

    /**
     * Totals over a date range, optionally for one supervisor or one branch.
     *
     * @return array{days: int, supervisors: int, total: int, schemes: array<string, int>}
     */
    public static function summary(
        string $from,
        string $to,
        ?int $bcSupervisorId = null,
        ?int $branchId = null
    ): array {
        $where = ['enrolment_date BETWEEN :from AND :to'];
        $params = ['from' => $from, 'to' => $to];

        if ($bcSupervisorId !== null) {
            $where[] = 'bc_supervisor_id = :bc';
            $params['bc'] = $bcSupervisorId;
        }

        if ($branchId !== null) {
            $where[] = 'branch_id = :branch';
            $params['branch'] = $branchId;
        }

        $sums = [];

        foreach (array_keys(self::schemes()) as $column) {
            $sums[] = sprintf('COALESCE(SUM(`%s`), 0) AS `%s`', $column, $column);
        }

        $row = Database::selectOne(
            sprintf(
                'SELECT COUNT(*) AS days, COUNT(DISTINCT bc_supervisor_id) AS supervisors, %s
                   FROM sss_enrolments WHERE %s',
                implode(', ', $sums),
                implode(' AND ', $where)
            ),
            $params
        ) ?? [];

        $schemes = [];

        foreach (array_keys(self::schemes()) as $column) {
            $schemes[$column] = (int) ($row[$column] ?? 0);
        }

        return [
            'days' => (int) ($row['days'] ?? 0),
            'supervisors' => (int) ($row['supervisors'] ?? 0),
            'total' => array_sum($schemes),
            'schemes' => $schemes,
        ];
    }

    /**
     * The date the figures belong to.
     *
     * Never the future: a day that has not happened cannot have enrolments in it, and a
     * device with a wrong clock would otherwise write one.
     *
     * How far back is a setting rather than a constant because the app is offline-first.
     * The reference implementation refuses anything older than yesterday, which works when
     * the app posts directly — here a supervisor can be out of signal for days and the
     * outbox delivers when it can, so a one-day window would throw away exactly the
     * figures that were hardest to collect. The date the app recorded is the date that
     * counts; the panel can correct anything older.
     */
    private static function date(mixed $value): string
    {
        $raw = trim((string) ($value ?? ''));
        $date = $raw === '' ? today() : $raw;
        $timestamp = strtotime($date);

        if ($timestamp === false) {
            throw new HttpException(422, 'That is not a date the system can read.');
        }

        $date = date('Y-m-d', $timestamp);

        if ($date > today()) {
            throw new HttpException(422, 'Enrolments cannot be recorded for a date in the future.');
        }

        $days = max(1, Settings::int('sss_backdate_days', 30));
        $earliest = date('Y-m-d', strtotime('-' . $days . ' days'));

        if ($date < $earliest) {
            throw new HttpException(422, sprintf(
                'That date is more than %d days old. Ask an Admin to record it from the panel.',
                $days
            ));
        }

        return $date;
    }

    /**
     * The four counts, cleaned.
     *
     * A blank field is a zero: the app sends an empty box when a scheme had no enrolments
     * that day, and "none" is a real answer. Anything unreadable is also a zero rather
     * than a refusal — the rest of the day's figures are worth keeping.
     *
     * @param array<string, mixed> $payload
     * @return array<string, int>
     */
    private static function counts(array $payload): array
    {
        $counts = [];

        foreach (array_keys(self::schemes()) as $column) {
            $raw = $payload[$column] ?? null;
            $value = is_numeric($raw) ? (int) $raw : 0;

            if ($value < 0) {
                $value = 0;
            }

            if ($value > self::MAX_PER_SCHEME) {
                throw new HttpException(422, sprintf(
                    '%s cannot be more than %d in one day.',
                    self::schemes()[$column],
                    self::MAX_PER_SCHEME
                ));
            }

            $counts[$column] = $value;
        }

        return $counts;
    }
}
