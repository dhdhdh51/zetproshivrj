<?php
/**
 * SSS enrolments: target against achievement.
 *
 * @var string $from
 * @var string $to
 * @var string $period       day | mtd | month | custom
 * @var string $monthAnchor  first of the month the "full month" view is showing
 * @var int $branchId
 * @var int $supervisorId
 * @var array<int, array<string, mixed>> $branches
 * @var array<int, array<string, mixed>> $supervisors
 * @var array<string, string> $schemes      column => abbreviation
 * @var array<string, string> $schemeNames  column => full scheme name
 * @var array{days:int, supervisors:int, total:int, schemes:array<string,int>} $summary
 * @var array<int, array<string, mixed>> $rows      one per supervisor per day
 * @var array<int, array<string, mixed>> $register  ranked target vs achievement
 * @var int $workingDays
 * @var int $totalTarget
 * @var int $totalAchievement
 * @var float $totalPercent
 * @var int $totalGap
 * @var string $reopenedStatus
 */

// Carried into the quick-window links and the report link so changing the window does not
// silently drop the branch or supervisor somebody had filtered to.
$scope = array_filter([
    'branch_id' => $branchId > 0 ? $branchId : null,
    'bc_supervisor_id' => $supervisorId > 0 ? $supervisorId : null,
]);

$windows = [
    'day' => 'Today',
    'mtd' => 'Month to date',
    'month' => 'Full month',
];
?>

<div class="page-head">
    <div class="grow">
        <h1>SSS enrolments</h1>
        <div class="subtitle">
            Social Security Scheme sign-ups at the BC point — APY, PMJJBY, PMSBY and PMJDY —
            reported once per supervisor per day and measured against the target you set.
        </div>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="<?= e(url('/admin/sss-targets')) ?>"><?= icon('target', '', 15) ?> Set targets</a>
        <a class="btn" href="<?= e(url('/admin/sss/create')) ?>"><?= icon('plus', '', 15) ?> Record enrolments</a>
    </div>
</div>

<div class="stat-grid">
    <div class="stat accent">
        <div class="label"><?= icon('shield', '', 14) ?> Achievement</div>
        <div class="value"><?= number_format($totalAchievement) ?></div>
        <div class="meta">
            <?= number_format($summary['days']) ?> day(s) reported ·
            <?= number_format($summary['supervisors']) ?> supervisor(s)
        </div>
    </div>
    <div class="stat">
        <div class="label"><?= icon('target', '', 14) ?> Target</div>
        <div class="value"><?= number_format($totalTarget) ?></div>
        <div class="meta tiny">
            <?= number_format($workingDays) ?> working day(s) in this window
        </div>
    </div>
    <div class="stat">
        <div class="label">Achieved</div>
        <div class="value"><?= number_format($totalPercent, 1) ?>%</div>
        <div class="meta tiny">
            <?= $totalTarget > 0 ? 'Of the target for this window' : 'No target set for this window' ?>
        </div>
    </div>
    <div class="stat">
        <div class="label">Gap</div>
        <div class="value"><?= number_format($totalGap) ?></div>
        <div class="meta tiny">
            <?= $totalGap > 0 ? 'Still to enrol' : 'Target met' ?>
        </div>
    </div>
    <?php foreach ($schemes as $column => $abbreviation): ?>
        <div class="stat">
            <div class="label"><?= e($abbreviation) ?></div>
            <div class="value"><?= number_format($summary['schemes'][$column] ?? 0) ?></div>
            <div class="meta tiny"><?= e($schemeNames[$column] ?? '') ?></div>
        </div>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="card-head" style="flex-wrap:wrap; gap:10px">
        <div class="page-actions" style="margin:0">
            <?php foreach ($windows as $key => $label): ?>
                <a class="btn btn-sm <?= $period === $key ? '' : 'btn-secondary' ?>"
                   href="<?= e(url('/admin/sss?' . http_build_query(array_merge($scope, array_filter([
                       'period' => $key,
                       'month' => $key === 'month' ? $monthAnchor : null,
                   ]))))) ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </div>

        <form method="get" action="<?= e(url('/admin/sss')) ?>" class="filters" style="flex:1 1 auto">
            <div class="field">
                <label for="from">From</label>
                <input type="date" id="from" name="from" value="<?= e($from) ?>">
            </div>
            <div class="field">
                <label for="to">To</label>
                <input type="date" id="to" name="to" value="<?= e($to) ?>">
            </div>
            <div class="field">
                <label for="branch_id">Branch</label>
                <select id="branch_id" name="branch_id" data-auto-submit>
                    <option value="">All branches</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= (int) $branch['id'] ?>" <?= $branchId === (int) $branch['id'] ? 'selected' : '' ?>>
                            <?= e($branch['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="bc_supervisor_id">BCA</label>
                <select id="bc_supervisor_id" name="bc_supervisor_id" data-auto-submit>
                    <option value="">All supervisors</option>
                    <?php foreach ($supervisors as $supervisor): ?>
                        <option value="<?= (int) $supervisor['id'] ?>" <?= $supervisorId === (int) $supervisor['id'] ? 'selected' : '' ?>>
                            <?= e($supervisor['name']) ?> (<?= e($supervisor['bc_code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="actions">
                <button type="submit" class="btn btn-secondary"><?= icon('search', '', 15) ?> Filter</button>
            </div>
        </form>
    </div>
</div>

<?php if ($register !== []): ?>
    <div class="card">
        <div class="card-head">
            <div class="grow">
                <h2>Target vs achievement</h2>
                <div class="tiny muted">
                    Ranked by percentage. Each scheme cell reads achievement of target.
                    Targets are set per working day, so this window is
                    <?= number_format($workingDays) ?> working day(s) of the daily figure.
                </div>
            </div>
            <div class="page-actions" style="margin:0">
                <a class="btn btn-secondary btn-sm"
                   href="<?= e(url('/admin/reports/sss_target?' . http_build_query(array_merge($scope, [
                       'from' => $from,
                       'to' => $to,
                   ])))) ?>"><?= icon('chart', '', 14) ?> Open as report (PDF / CSV)</a>
            </div>
        </div>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th class="center">#</th>
                        <th>BCA</th>
                        <th>Branch</th>
                        <th class="center">Days</th>
                        <?php foreach ($schemes as $column => $abbreviation): ?>
                            <th class="center" title="<?= e($schemeNames[$column] ?? '') ?>"><?= e($abbreviation) ?></th>
                        <?php endforeach; ?>
                        <th class="right">Target</th>
                        <th class="right">Achieved</th>
                        <th class="right">%</th>
                        <th class="right">Gap</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $rank = 0; ?>
                    <?php foreach ($register as $line): ?>
                        <?php $rank += $line['has_target'] ? 1 : 0; ?>
                        <tr>
                            <td class="center num muted"><?= $line['has_target'] ? (int) $rank : '—' ?></td>
                            <td>
                                <strong><?= e($line['supervisor_name']) ?></strong>
                                <div class="tiny muted mono"><?= e($line['bc_code']) ?></div>
                            </td>
                            <td class="small"><?= e($line['branch_name'] ?? '—') ?></td>
                            <td class="center num">
                                <?= (int) $line['days_reported'] ?>
                                <?php if ((int) $line['days_reopened'] > 0): ?>
                                    <div class="tiny muted" title="Days handed back for correction">
                                        <?= (int) $line['days_reopened'] ?> re-opened
                                    </div>
                                <?php endif; ?>
                            </td>
                            <?php foreach (array_keys($schemes) as $column): ?>
                                <?php
                                $done = (int) ($line['achievement'][$column] ?? 0);
                                $want = (int) ($line['target'][$column] ?? 0);
                                ?>
                                <td class="center num <?= $done === 0 && $want === 0 ? 'muted' : '' ?>">
                                    <?= number_format($done) ?><span class="muted">/<?= number_format($want) ?></span>
                                </td>
                            <?php endforeach; ?>
                            <td class="right num"><?= number_format((int) $line['total_target']) ?></td>
                            <td class="right num"><strong><?= number_format((int) $line['total_achievement']) ?></strong></td>
                            <td class="right num">
                                <?php if ($line['has_target']): ?>
                                    <span class="badge <?= badge_for_percent((float) $line['percent']) ?>">
                                        <?= number_format((float) $line['percent'], 1) ?>%
                                    </span>
                                <?php else: ?>
                                    <span class="tiny muted">No target</span>
                                <?php endif; ?>
                            </td>
                            <td class="right num <?= (int) $line['gap'] === 0 ? 'muted' : '' ?>">
                                <?= number_format((int) $line['gap']) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-head"><h2>Daily entries</h2></div>

    <?php if ($rows === []): ?>
        <?= view_partial('partials.empty', [
            'message' => 'No enrolments in this period',
            'hint' => 'Supervisors report these from the app. You can also record a day here if a handset could not.',
            'iconName' => 'shield',
            'actionUrl' => '/admin/sss/create',
            'actionLabel' => 'Record enrolments',
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>BCA</th>
                        <th>Branch</th>
                        <?php foreach ($schemes as $column => $abbreviation): ?>
                            <th class="right" title="<?= e($schemeNames[$column] ?? '') ?>"><?= e($abbreviation) ?></th>
                        <?php endforeach; ?>
                        <th class="right">Total</th>
                        <th class="center">Source</th>
                        <th class="center">State</th>
                        <th>Remarks</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php $reopened = (string) $row['status'] === $reopenedStatus; ?>
                        <tr>
                            <td class="small nowrap"><?= e(format_date((string) $row['enrolment_date'])) ?></td>
                            <td>
                                <strong><?= e($row['supervisor_name']) ?></strong>
                                <div class="tiny muted mono"><?= e($row['bc_code']) ?></div>
                            </td>
                            <td class="small"><?= e($row['branch_name']) ?></td>
                            <?php foreach (array_keys($schemes) as $column): ?>
                                <td class="right num <?= (int) $row[$column] === 0 ? 'muted' : '' ?>"><?= (int) $row[$column] ?></td>
                            <?php endforeach; ?>
                            <td class="right num"><strong><?= (int) $row['total'] ?></strong></td>
                            <td class="center">
                                <?php if ((string) $row['source'] === 'panel'): ?>
                                    <span class="badge" title="<?= e('Typed in the panel' . ($row['recorded_by_name'] ? ' by ' . $row['recorded_by_name'] : '')) ?>">Panel</span>
                                <?php else: ?>
                                    <span class="badge badge-success" title="Reported from the app">App</span>
                                <?php endif; ?>
                            </td>
                            <td class="center">
                                <?php if ($reopened): ?>
                                    <span class="badge badge-warning" title="Handed back — the supervisor can submit this day once more from the app">Re-opened</span>
                                <?php else: ?>
                                    <span class="badge badge-success" title="<?= e('Submitted' . (!empty($row['submitted_at']) ? ' ' . format_datetime((string) $row['submitted_at']) : '') . '. The app cannot change this day.') ?>">Submitted</span>
                                <?php endif; ?>
                            </td>
                            <td class="small"><?= e($row['remarks'] ?: '—') ?></td>
                            <td class="nowrap">
                                <a class="btn btn-link btn-sm" href="<?= e(url('/admin/sss/' . (int) $row['id'] . '/edit')) ?>" title="Correct these figures yourself"><?= icon('edit', '', 14) ?></a>
                                <?php if (!$reopened): ?>
                                    <form method="post" action="<?= e(url('/admin/sss/' . (int) $row['id'] . '/reopen')) ?>"
                                          style="display:inline"
                                          data-confirm="Let the supervisor correct this day from the app? They get one more submission and the day closes again.">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-link btn-sm" title="Re-open for the supervisor"><?= icon('unlock', '', 14) ?></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
