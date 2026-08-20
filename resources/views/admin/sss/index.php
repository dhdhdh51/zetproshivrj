<?php
/**
 * @var array<string, string> $schemes      column => abbreviation
 * @var array<string, string> $schemeNames  column => full scheme name
 * @var array{days:int, supervisors:int, total:int, schemes:array<string,int>} $summary
 * @var array<int, array<string, mixed>> $rows
 * @var array<int, array<string, mixed>> $perSupervisor
 */
?>

<div class="page-head">
    <div class="grow">
        <h1>SSS enrolments</h1>
        <div class="subtitle">
            Social Security Scheme sign-ups at the BC point — APY, PMJJBY, PMSBY and PMJDY —
            recorded once per supervisor per day.
        </div>
    </div>
    <div class="page-actions">
        <a class="btn" href="<?= e(url('/admin/sss/create')) ?>"><?= icon('plus', '', 15) ?> Record enrolments</a>
    </div>
</div>

<div class="stat-grid">
    <div class="stat accent">
        <div class="label"><?= icon('shield', '', 14) ?> Total enrolments</div>
        <div class="value"><?= number_format($summary['total']) ?></div>
        <div class="meta">
            <?= number_format($summary['days']) ?> day(s) reported ·
            <?= number_format($summary['supervisors']) ?> supervisor(s)
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
    <div class="card-head">
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
                <label for="bc_supervisor_id">BC Supervisor</label>
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

<?php if (count($perSupervisor) > 1): ?>
    <div class="card">
        <div class="card-head"><h2>By supervisor</h2></div>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th>BC Supervisor</th>
                        <th>Branch</th>
                        <th class="center">Days</th>
                        <?php foreach ($schemes as $column => $abbreviation): ?>
                            <th class="right" title="<?= e($schemeNames[$column] ?? '') ?>"><?= e($abbreviation) ?></th>
                        <?php endforeach; ?>
                        <th class="right">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($perSupervisor as $line): ?>
                        <tr>
                            <td>
                                <strong><?= e($line['supervisor_name']) ?></strong>
                                <div class="tiny muted mono"><?= e($line['bc_code']) ?></div>
                            </td>
                            <td class="small"><?= e($line['branch_name']) ?></td>
                            <td class="center num"><?= (int) $line['days'] ?></td>
                            <?php foreach (array_keys($schemes) as $column): ?>
                                <td class="right num"><?= number_format((int) $line[$column]) ?></td>
                            <?php endforeach; ?>
                            <td class="right num"><strong><?= number_format((int) $line['total']) ?></strong></td>
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
                        <th>BC Supervisor</th>
                        <th>Branch</th>
                        <?php foreach ($schemes as $column => $abbreviation): ?>
                            <th class="right" title="<?= e($schemeNames[$column] ?? '') ?>"><?= e($abbreviation) ?></th>
                        <?php endforeach; ?>
                        <th class="right">Total</th>
                        <th class="center">Source</th>
                        <th>Remarks</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
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
                            <td class="small"><?= e($row['remarks'] ?: '—') ?></td>
                            <td class="nowrap">
                                <a class="btn btn-link btn-sm" href="<?= e(url('/admin/sss/' . (int) $row['id'] . '/edit')) ?>" title="Correct these figures"><?= icon('edit', '', 14) ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
