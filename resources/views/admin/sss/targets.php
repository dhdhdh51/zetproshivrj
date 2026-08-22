<?php
/**
 * SSS targets: what each supervisor is expected to enrol per working day.
 *
 * @var string $month      first of the month being shown
 * @var string $monthEnd
 * @var int $workingDays   working days in that month, from the report working-day setting
 * @var int $branchId
 * @var array<int, array<string, mixed>> $branches
 * @var array<int, array<string, mixed>> $supervisors
 * @var array<string, string> $schemes        count column => abbreviation
 * @var array<string, string> $schemeNames    count column => full scheme name
 * @var array<string, string> $targetSchemes  target column => abbreviation
 * @var array<int, array<string, mixed>> $targets
 * @var int $maxPerScheme
 */

$monthLabel = date('F Y', (int) strtotime($month));
?>

<div class="page-head">
    <div class="grow">
        <h1>SSS targets</h1>
        <div class="subtitle">
            What each BCA is expected to enrol <strong>per working day</strong>, per scheme.
            Month to date and the month total are worked out from this, so you set one number and
            never have to keep the longer figures in step.
        </div>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="<?= e(url('/admin/sss')) ?>"><?= icon('shield', '', 15) ?> Enrolments</a>
        <a class="btn btn-secondary" href="<?= e(url('/admin/reports/sss_target')) ?>"><?= icon('chart', '', 15) ?> Ranking report</a>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <div class="grow">
            <h2>Set a target for <?= e($monthLabel) ?></h2>
            <div class="tiny muted">
                <?= number_format($workingDays) ?> working day(s) in <?= e($monthLabel) ?>,
                so a target of 2 a day is <?= number_format(2 * $workingDays) ?> for the month.
                Sundays and any other non-working day are not counted against anyone.
            </div>
        </div>
    </div>
    <form method="post" action="<?= e(url('/admin/sss-targets')) ?>">
        <?= csrf_field() ?>
        <div class="card-body">
            <div class="form-grid">
                <div class="field <?= has_error('month') ? 'has-error' : '' ?>">
                    <label for="month">Month</label>
                    <input type="date" id="month" name="month" value="<?= e(old('month', $month)) ?>" required>
                    <div class="help">Any date in the month; it is stored against the month itself.</div>
                    <?php if (has_error('month')): ?>
                        <div class="error-text"><?= e(error_for('month')) ?></div>
                    <?php endif; ?>
                </div>

                <?php foreach ($targetSchemes as $column => $abbreviation): ?>
                    <?php $countColumn = (string) preg_replace('/_target$/', '_count', $column); ?>
                    <div class="field <?= has_error($column) ? 'has-error' : '' ?>">
                        <label for="<?= e($column) ?>"><?= e($abbreviation) ?> a day</label>
                        <input type="number" id="<?= e($column) ?>" name="<?= e($column) ?>"
                               min="0" max="<?= (int) $maxPerScheme ?>" step="1" inputmode="numeric"
                               placeholder="0" value="<?= e(old($column, '')) ?>">
                        <div class="help"><?= e($schemeNames[$countColumn] ?? '') ?></div>
                        <?php if (has_error($column)): ?>
                            <div class="error-text"><?= e(error_for($column)) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <div class="field span-2">
                    <label for="bc_supervisor_ids">BCAs</label>
                    <select id="bc_supervisor_ids" name="bc_supervisor_ids[]" multiple size="6" required>
                        <?php foreach ($supervisors as $supervisor): ?>
                            <option value="<?= (int) $supervisor['id'] ?>">
                                <?= e($supervisor['name']) ?> (<?= e($supervisor['bc_code']) ?>) — <?= e($supervisor['branch_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="help">
                        Hold Ctrl / Cmd to select several — the same daily target is applied to each,
                        and each one is told about it.
                    </div>
                </div>

                <div class="field span-2 <?= has_error('notes') ? 'has-error' : '' ?>">
                    <label for="notes">Notes</label>
                    <input type="text" id="notes" name="notes" maxlength="255" value="<?= e(old('notes', '')) ?>">
                    <?php if (has_error('notes')): ?>
                        <div class="error-text"><?= e(error_for('notes')) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="card-foot">
            <button type="submit" class="btn"><?= icon('target', '', 15) ?> Save target</button>
        </div>
    </form>
</div>

<div class="card">
    <div class="card-head">
        <h2>Targets for <?= e($monthLabel) ?></h2>
        <div class="spacer"></div>
        <form method="get" action="<?= e(url('/admin/sss-targets')) ?>" class="filters">
            <div class="field">
                <input type="date" name="month" value="<?= e($month) ?>" data-auto-submit aria-label="Month">
            </div>
            <div class="field">
                <select name="branch_id" data-auto-submit aria-label="Branch">
                    <option value="">All branches</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= (int) $branch['id'] ?>" <?= $branchId === (int) $branch['id'] ? 'selected' : '' ?>>
                            <?= e($branch['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>

    <?php if ($targets === []): ?>
        <?= view_partial('partials.empty', [
            'message' => 'No SSS targets set for ' . $monthLabel,
            'hint' => 'Until a target is set, enrolments are still recorded and reported — they are simply not measured against anything.',
            'iconName' => 'target',
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th>BCA</th>
                        <th>Branch</th>
                        <?php foreach ($targetSchemes as $column => $abbreviation): ?>
                            <th class="right" title="<?= e($abbreviation) ?> a day"><?= e($abbreviation) ?></th>
                        <?php endforeach; ?>
                        <th class="right">A day</th>
                        <th class="right">This month</th>
                        <th>Notes</th>
                        <th>Set by</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($targets as $target): ?>
                        <tr>
                            <td>
                                <strong><?= e($target['supervisor_name']) ?></strong>
                                <div class="tiny muted mono"><?= e($target['bc_code']) ?></div>
                            </td>
                            <td class="small"><?= e($target['branch_name'] ?? '—') ?></td>
                            <?php foreach (array_keys($targetSchemes) as $column): ?>
                                <td class="right num <?= (int) $target[$column] === 0 ? 'muted' : '' ?>"><?= (int) $target[$column] ?></td>
                            <?php endforeach; ?>
                            <td class="right num"><strong><?= (int) $target['per_day_total'] ?></strong></td>
                            <td class="right num">
                                <?= number_format((int) $target['per_day_total'] * $workingDays) ?>
                                <div class="tiny muted">× <?= (int) $workingDays ?> days</div>
                            </td>
                            <td class="small"><?= e($target['notes'] ?: '—') ?></td>
                            <td class="small muted"><?= e($target['set_by_name'] ?? '—') ?></td>
                            <td>
                                <form method="post" action="<?= e(url('/admin/sss-targets/' . (int) $target['id'] . '/delete')) ?>"
                                      data-confirm="Remove this target? The enrolments already recorded are not touched.">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-link btn-sm"><?= icon('trash', '', 14) ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
