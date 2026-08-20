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

    /** The supervisor has reported the day and can no longer change it. */
    public const STATUS_SUBMITTED = 'submitted';

    /** An Admin handed the day back; the next submission from the app is allowed. */
    public const STATUS_REOPENED = 'reopened';

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
            'SELECT * FROM sss_enrolments WHERE bc_supervisor_id = :bc AND enrolment_date = :date',
            ['bc' => $bcSupervisorId, 'date' => $date]
        );

        $remarks = isset($payload['remarks']) && trim((string) $payload['remarks']) !== ''
            ? mb_substr(trim((string) $payload['remarks']), 0, 500)
            : null;

        // A submitted day is closed to the handset. The figures are what a target register
        // is measured on, so the supervisor who reported them cannot quietly raise them
        // afterwards — an Admin has to hand the day back first.
        if ($existing !== null
            && $source !== 'panel'
            && (string) $existing['status'] === self::STATUS_SUBMITTED
        ) {
            // Unless nothing is being changed. The outbox delivers at least once, not
            // exactly once: a dropped response, a replayed batch or a reinstalled app can
            // all present the same figures a second time. Refusing those would show the
            // supervisor an error about a day they reported correctly and strand a queue
            // entry that was never wrong. Only an attempt to say something *different*
            // about a closed day is refused.
            if (self::sameFigures($existing, $counts, $remarks)) {
                return [
                    'id' => (int) $existing['id'],
                    'created' => false,
                    'date' => $date,
                    'total' => array_sum($counts),
                ];
            }

            // 409 rather than 422 because nothing about the request is malformed: the same
            // payload would have been accepted an hour earlier. Either way it is a 4xx,
            // which is what tells the app to stop retrying (see SyncController's
            // `retryable`), so the outbox entry stays put with this message against it
            // instead of being redelivered for ever or thrown away.
            throw new HttpException(409, sprintf(
                'The figures for %s were already submitted. Ask an Admin to re-open that day before correcting them.',
                format_date($date)
            ));
        }

        $fields = array_merge($counts, [
            'remarks' => $remarks,
            'source' => $source === 'panel' ? 'panel' : 'app',
            'recorded_by' => Auth::id(),
            // Any successful write closes the day again, including an Admin typing the
            // figures on a day they had just re-opened. Only reopen() opens one, so there
            // is no state where a day is editable because nobody got round to closing it.
            // `reopened_by` and `reopened_at` are deliberately left alone: they record that
            // the day was handed back once, which is exactly what someone auditing a
            // changed figure needs to see.
            'status' => self::STATUS_SUBMITTED,
            'submitted_at' => now(),
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

    /* ---------------------------------------------------------------------- */
    /* Targets                                                                */
    /*                                                                        */
    /* The Admin owns these; the supervisor never sends one. Achievement is    */
    /* the enrolments above, and the percentage and the gap are worked out on  */
    /* the way to the screen rather than stored — a figure nobody can write to */
    /* is a figure nobody can argue with, and there is no column to correct    */
    /* when the working days or the target change underneath it.               */
    /* ---------------------------------------------------------------------- */

    /**
     * Is this payload saying the same thing the stored row already says?
     *
     * Used to tell a redelivery apart from an edit. Remarks count: changing only the note
     * on a closed day is still changing what was reported.
     *
     * @param array<string, mixed> $existing
     * @param array<string, int> $counts
     */
    private static function sameFigures(array $existing, array $counts, ?string $remarks): bool
    {
        foreach ($counts as $column => $value) {
            if ((int) ($existing[$column] ?? 0) !== $value) {
                return false;
            }
        }

        return (string) ($existing['remarks'] ?? '') === (string) ($remarks ?? '');
    }

    /**
     * `apy_count` → `apy_target`.
     *
     * The two tables are deliberately named so this is mechanical: everything below loops
     * schemes() and derives the target column, so adding a fifth scheme stays a one-line
     * change in schemes() plus a column in each table.
     */
    public static function targetColumn(string $countColumn): string
    {
        return (string) preg_replace('/_count$/', '_target', $countColumn);
    }

    /**
     * The target columns, as column => abbreviation.
     *
     * @return array<string, string>
     */
    public static function targetSchemes(): array
    {
        $targets = [];

        foreach (self::schemes() as $countColumn => $label) {
            $targets[self::targetColumn($countColumn)] = $label;
        }

        return $targets;
    }

    /** The first of the month a date falls in, which is how a target month is stored. */
    public static function month(string $date): string
    {
        return date('Y-m-01', (int) (strtotime($date) ?: time()));
    }

    /**
     * Working days in a range, inclusive, according to the report working-day setting.
     *
     * A target is a per-day figure, so every longer period is this count times that
     * figure. Sundays are not a shortfall.
     */
    public static function workingDaysBetween(string $from, string $to): int
    {
        if ($to < $from) {
            return 0;
        }

        $days = 0;
        $cursor = (int) strtotime($from);
        $end = (int) strtotime($to);

        while ($cursor <= $end) {
            if (Deadline::isWorkingDay(date('Y-m-d', $cursor))) {
                $days++;
            }

            $cursor = (int) strtotime('+1 day', $cursor);
        }

        return $days;
    }

    /**
     * One supervisor's per-working-day target for the month a date falls in.
     *
     * Returns zeros rather than null when no target was ever set, so every caller can add
     * up without asking first. `exists` is there for the screens that want to say "no
     * target set" instead of showing a row of noughts.
     *
     * @return array{exists: bool, month: string, notes: ?string, per_day: array<string, int>, per_day_total: int}
     */
    public static function targetFor(int $bcSupervisorId, string $date): array
    {
        $month = self::month($date);

        $row = Database::selectOne(
            'SELECT * FROM sss_targets WHERE bc_supervisor_id = :bc AND target_month = :month',
            ['bc' => $bcSupervisorId, 'month' => $month]
        );

        $perDay = [];

        foreach (array_keys(self::schemes()) as $countColumn) {
            $perDay[$countColumn] = $row === null
                ? 0
                : (int) ($row[self::targetColumn($countColumn)] ?? 0);
        }

        return [
            'exists' => $row !== null,
            'month' => $month,
            'notes' => $row['notes'] ?? null,
            'per_day' => $perDay,
            'per_day_total' => array_sum($perDay),
        ];
    }

    /**
     * What a supervisor was expected to enrol across a range of dates.
     *
     * A range can straddle months and each month can carry its own target, so this walks
     * the months and multiplies each one's daily figure by that month's working days
     * *inside the range* — asking for 1–10 March counts ten days of March's target, not
     * the whole month's.
     *
     * @return array{schemes: array<string, int>, total: int, working_days: int}
     */
    public static function targetForRange(int $bcSupervisorId, string $from, string $to): array
    {
        $windows = self::monthWorkingDays($from, $to);
        $targets = self::targetRows([$bcSupervisorId], $from, $to);

        return self::applyTargets($targets[$bcSupervisorId] ?? [], $windows);
    }

    /**
     * Target, achievement, percentage and gap for every supervisor in scope: the register
     * the Admin dashboard, the ranking and the export are all built from.
     *
     * Two queries regardless of how many supervisors there are — the achievements in one
     * aggregate and every relevant target row in another — because the working-day count
     * for a month is the same for everybody and only has to be worked out once.
     *
     * Ordered by percentage, so the rows come out as the ranking.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function performance(
        string $from,
        string $to,
        ?int $branchId = null,
        ?int $bcSupervisorId = null
    ): array {
        $where = [];
        $params = ['from' => $from, 'to' => $to];

        if ($branchId !== null) {
            $where[] = 's.branch_id = :branch';
            $params['branch'] = $branchId;
        }

        if ($bcSupervisorId !== null) {
            $where[] = 's.id = :supervisor';
            $params['supervisor'] = $bcSupervisorId;
        }

        // Someone who has left still has to appear against the days they worked, or a
        // month's register would quietly lose their figures; someone still here appears
        // even with nothing recorded, because an empty row is the point of a gap report.
        $where[] = "((u.status = 'active' AND s.status = 'active') OR e.days IS NOT NULL)";

        $params['reopened'] = self::STATUS_REOPENED;

        $sums = [];

        foreach (array_keys(self::schemes()) as $column) {
            $sums[] = sprintf('COALESCE(e.`%s`, 0) AS `%s`', $column, $column);
        }

        $innerSums = [];

        foreach (array_keys(self::schemes()) as $column) {
            $innerSums[] = sprintf('SUM(`%s`) AS `%s`', $column, $column);
        }

        $rows = Database::select(
            sprintf(
                'SELECT s.id AS bc_supervisor_id, s.bc_code, u.name AS supervisor_name,
                        s.branch_id, b.name AS branch_name,
                        COALESCE(e.days, 0) AS days_reported,
                        COALESCE(e.reopened, 0) AS days_reopened,
                        %s
                   FROM bc_supervisors s
                   JOIN users u ON u.id = s.user_id
              LEFT JOIN branches b ON b.id = s.branch_id
              LEFT JOIN (
                        SELECT bc_supervisor_id, COUNT(*) AS days,
                               SUM(CASE WHEN status = :reopened THEN 1 ELSE 0 END) AS reopened, %s
                          FROM sss_enrolments
                         WHERE enrolment_date BETWEEN :from AND :to
                      GROUP BY bc_supervisor_id
                   ) e ON e.bc_supervisor_id = s.id
                  WHERE %s
               ORDER BY u.name ASC',
                implode(', ', $sums),
                implode(', ', $innerSums),
                implode(' AND ', $where)
            ),
            $params
        );

        if ($rows === []) {
            return [];
        }

        $windows = self::monthWorkingDays($from, $to);
        $targets = self::targetRows(array_map(static fn (array $r): int => (int) $r['bc_supervisor_id'], $rows), $from, $to);

        $register = [];

        foreach ($rows as $row) {
            $supervisorId = (int) $row['bc_supervisor_id'];
            $target = self::applyTargets($targets[$supervisorId] ?? [], $windows);

            $achievement = [];

            foreach (array_keys(self::schemes()) as $column) {
                $achievement[$column] = (int) ($row[$column] ?? 0);
            }

            $achieved = array_sum($achievement);
            $expected = $target['total'];

            $register[] = array_merge($row, [
                'bc_supervisor_id' => $supervisorId,
                'days_reported' => (int) $row['days_reported'],
                'days_reopened' => (int) $row['days_reopened'],
                'working_days' => $target['working_days'],
                'has_target' => $expected > 0,
                'achievement' => $achievement,
                'target' => $target['schemes'],
                'total_target' => $expected,
                'total_achievement' => $achieved,
                'percent' => percent_of($achieved, $expected),
                'gap' => max(0, $expected - $achieved),
            ]);
        }

        // Ranked on percentage, with the bigger worker ahead when two are level and
        // anybody without a target last — they are not competing, they are unconfigured.
        usort($register, static function (array $a, array $b): int {
            if ($a['has_target'] !== $b['has_target']) {
                return $a['has_target'] ? -1 : 1;
            }

            return [$b['percent'], $b['total_achievement'], $a['supervisor_name']]
                <=> [$a['percent'], $a['total_achievement'], $b['supervisor_name']];
        });

        return $register;
    }

    /**
     * One supervisor's day and month-to-date standing, for the handset.
     *
     * The app shows this and cannot change any of it: the target arrives from the server,
     * and the percentage and gap are computed here so the phone and the panel can never
     * disagree about the same day's arithmetic.
     *
     * @return array<string, mixed>
     */
    public static function progressFor(int $bcSupervisorId, string $date): array
    {
        $month = self::month($date);
        $target = self::targetFor($bcSupervisorId, $date);

        // A day off has no target, so a Sunday reads 0 of 0 rather than as a shortfall.
        $dayTarget = self::workingDaysBetween($date, $date) > 0 ? $target['per_day_total'] : 0;

        $day = self::summary($date, $date, $bcSupervisorId);
        $mtd = self::summary($month, $date, $bcSupervisorId);
        $mtdTarget = self::targetForRange($bcSupervisorId, $month, $date);

        return [
            'month' => $month,
            'target_set' => $target['exists'],
            'per_day' => $target['per_day'],
            'per_day_total' => $target['per_day_total'],
            'day' => [
                'target' => $dayTarget,
                'achievement' => $day['total'],
                'percent' => percent_of($day['total'], $dayTarget),
                'gap' => max(0, $dayTarget - $day['total']),
            ],
            'month_to_date' => [
                'target' => $mtdTarget['total'],
                'achievement' => $mtd['total'],
                'percent' => percent_of($mtd['total'], $mtdTarget['total']),
                'gap' => max(0, $mtdTarget['total'] - $mtd['total']),
                'working_days' => $mtdTarget['working_days'],
            ],
        ];
    }

    /**
     * Set or change one supervisor's per-working-day target for a month.
     *
     * @param array<string, mixed> $payload apy_target, pmjjby_target, pmsby_target, pmjdy_target
     * @return array{id: int, created: bool, month: string, per_day_total: int}
     */
    public static function saveTarget(
        int $bcSupervisorId,
        string $month,
        array $payload,
        ?string $notes = null
    ): array {
        $supervisor = Database::selectOne(
            'SELECT id FROM bc_supervisors WHERE id = :id',
            ['id' => $bcSupervisorId]
        );

        if ($supervisor === null) {
            throw new HttpException(404, 'That BC Supervisor does not exist.');
        }

        $month = self::month($month);
        $targets = self::targetCounts($payload);

        $fields = array_merge($targets, [
            'notes' => $notes !== null && trim($notes) !== '' ? mb_substr(trim($notes), 0, 255) : null,
            'created_by' => Auth::id(),
            'updated_at' => now(),
        ]);

        $existing = Database::selectOne(
            'SELECT id FROM sss_targets WHERE bc_supervisor_id = :bc AND target_month = :month',
            ['bc' => $bcSupervisorId, 'month' => $month]
        );

        if ($existing !== null) {
            Database::update('sss_targets', $fields, 'id = :id', ['id' => (int) $existing['id']]);

            return [
                'id' => (int) $existing['id'],
                'created' => false,
                'month' => $month,
                'per_day_total' => array_sum($targets),
            ];
        }

        $id = Database::insert('sss_targets', array_merge($fields, [
            'bc_supervisor_id' => $bcSupervisorId,
            'target_month' => $month,
            'created_at' => now(),
        ]));

        return ['id' => $id, 'created' => true, 'month' => $month, 'per_day_total' => array_sum($targets)];
    }

    /**
     * Hand a submitted day back to the supervisor so they can correct it.
     *
     * Returns the row as it was, for the caller's audit entry, or null when there is
     * nothing to do — a missing row or a day already open. A stale screen is not an
     * error, which is how decideLate() treats the same situation.
     *
     * @return array<string, mixed>|null
     */
    public static function reopen(int $id): ?array
    {
        $entry = Database::selectOne('SELECT * FROM sss_enrolments WHERE id = :id', ['id' => $id]);

        if ($entry === null || (string) $entry['status'] === self::STATUS_REOPENED) {
            return null;
        }

        Database::update('sss_enrolments', [
            'status' => self::STATUS_REOPENED,
            'reopened_by' => Auth::id(),
            'reopened_at' => now(),
            'updated_at' => now(),
        ], 'id = :id', ['id' => $id]);

        return $entry;
    }

    /**
     * The months a range touches, each with the working days it contributes.
     *
     * Worked out once per report rather than once per supervisor: the calendar does not
     * change between people. Public because the SSS target report builds the same
     * multiplication in SQL and must use this same count, or the screen and the export
     * would disagree about the same window.
     *
     * @return array<string, int> month (first of) => working days inside the range
     */
    public static function monthWorkingDays(string $from, string $to): array
    {
        if ($to < $from) {
            return [];
        }

        $windows = [];
        $cursor = self::month($from);
        $last = self::month($to);

        while ($cursor <= $last) {
            $monthEnd = date('Y-m-t', (int) strtotime($cursor));
            $windows[$cursor] = self::workingDaysBetween(
                max($from, $cursor),
                min($to, $monthEnd)
            );

            $cursor = date('Y-m-01', (int) strtotime($cursor . ' +1 month'));
        }

        return $windows;
    }

    /**
     * Every target row for these supervisors over the months a range touches, in one query.
     *
     * @param array<int, int> $supervisorIds
     * @return array<int, array<string, array<string, int>>> supervisor => month => per-day counts
     */
    private static function targetRows(array $supervisorIds, string $from, string $to): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $supervisorIds))));

        if ($ids === []) {
            return [];
        }

        $placeholders = [];
        $params = ['from' => self::month($from), 'to' => self::month($to)];

        foreach ($ids as $index => $id) {
            $key = 'bc' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }

        $rows = Database::select(
            sprintf(
                'SELECT * FROM sss_targets
                  WHERE bc_supervisor_id IN (%s) AND target_month BETWEEN :from AND :to',
                implode(', ', $placeholders)
            ),
            $params
        );

        $indexed = [];

        foreach ($rows as $row) {
            $perDay = [];

            foreach (array_keys(self::schemes()) as $countColumn) {
                $perDay[$countColumn] = (int) ($row[self::targetColumn($countColumn)] ?? 0);
            }

            $indexed[(int) $row['bc_supervisor_id']][(string) $row['target_month']] = $perDay;
        }

        return $indexed;
    }

    /**
     * Daily targets times working days, per scheme.
     *
     * @param array<string, array<string, int>> $monthly month => per-day counts
     * @param array<string, int> $windows month => working days
     * @return array{schemes: array<string, int>, total: int, working_days: int}
     */
    private static function applyTargets(array $monthly, array $windows): array
    {
        $perScheme = [];

        foreach (array_keys(self::schemes()) as $column) {
            $perScheme[$column] = 0;
        }

        foreach ($windows as $month => $days) {
            if ($days <= 0 || !isset($monthly[$month])) {
                continue;
            }

            foreach ($monthly[$month] as $column => $perDay) {
                $perScheme[$column] += $perDay * $days;
            }
        }

        return [
            'schemes' => $perScheme,
            'total' => array_sum($perScheme),
            'working_days' => array_sum($windows),
        ];
    }

    /**
     * The four target figures, cleaned, with the same limits the counts get.
     *
     * @param array<string, mixed> $payload
     * @return array<string, int>
     */
    private static function targetCounts(array $payload): array
    {
        $targets = [];

        foreach (self::targetSchemes() as $column => $label) {
            $raw = $payload[$column] ?? null;
            $value = is_numeric($raw) ? (int) $raw : 0;

            if ($value < 0) {
                $value = 0;
            }

            if ($value > self::MAX_PER_SCHEME) {
                throw new HttpException(422, sprintf(
                    'The %s target cannot be more than %d a day.',
                    $label,
                    self::MAX_PER_SCHEME
                ));
            }

            $targets[$column] = $value;
        }

        return $targets;
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
