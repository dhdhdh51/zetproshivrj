<?php
/**
 * @var string $date
 * @var array  $supervisors
 * @var array  $coverage
 * @var array  $drafts
 * @var array  $recent
 * @var array  $branches
 * @var int    $branchId
 */
?>

<div class="page-head">
    <div class="grow">
        <h1>BC supervisor inspections</h1>
        <div class="subtitle">
            The Bank's monthly inspection of each BC point and its agent — the board, the registers,
            the equipment, the earnings and what the villagers say. Expected once a month per
            BC Supervisor.
        </div>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="<?= e(url('/admin/reports/bc_inspection')) ?>"><?= icon('file-text', '', 15) ?> Inspection register</a>
        <a class="btn" href="<?= e(url('/admin/inspections/create')) ?>"><?= icon('plus', '', 15) ?> Start inspection</a>
    </div>
</div>

<div class="stat-grid">
    <div class="stat accent">
        <div class="label">Coverage this month</div>
        <div class="value"><?= e($coverage['coverage_percent']) ?>%</div>
        <div class="meta">
            <?= number_format($coverage['supervisors_inspected']) ?> of
            <?= number_format($coverage['supervisors']) ?> BC Supervisors inspected
        </div>
    </div>
    <div class="stat good">
        <div class="label">Inspections submitted</div>
        <div class="value"><?= number_format($coverage['inspections_submitted']) ?></div>
        <div class="meta">this month</div>
    </div>
    <div class="stat <?= $coverage['adverse'] > 0 ? 'bad' : '' ?>">
        <div class="label">Adverse findings</div>
        <div class="value"><?= number_format($coverage['adverse']) ?></div>
        <div class="meta">graded "Poor" at item 24</div>
    </div>
    <div class="stat <?= $coverage['inspections_pending'] > 0 ? 'warn' : '' ?>">
        <div class="label">Drafts open</div>
        <div class="value"><?= number_format($coverage['inspections_pending']) ?></div>
        <div class="meta">started but not submitted</div>
    </div>
</div>

<?php if ($drafts !== []): ?>
    <div class="card">
        <div class="card-head">
            <h2>Your unfinished inspections</h2>
            <div class="spacer"></div>
            <span class="small muted">Resume where you left off</span>
        </div>
        <div class="table-wrap">
            <table class="data compact">
                <thead><tr><th>Started</th><th>BC Supervisor</th><th>Account</th><th class="center">Photos</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($drafts as $draft): ?>
                        <tr>
                            <td class="small"><?= e(time_ago($draft['started_at'])) ?></td>
                            <td class="small"><?= e($draft['supervisor_name']) ?> <span class="tiny muted"><?= e($draft['bc_code']) ?></span></td>
                            <td class="small">
                                <?= e($draft['account_number'] ?: '—') ?>
                                <?php if (!empty($draft['borrower_name'])): ?>
                                    <div class="tiny muted"><?= e(str_excerpt((string) $draft['borrower_name'], 24)) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="center num"><?= (int) $draft['photo_count'] ?></td>
                            <td class="nowrap">
                                <a class="btn btn-sm" href="<?= e(url('/admin/inspections/' . (int) $draft['id'] . '/edit')) ?>">Continue</a>
                                <form method="post" action="<?= e(url('/admin/inspections/' . (int) $draft['id'] . '/delete')) ?>" style="display:inline"
                                      data-confirm="Discard this draft inspection?">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-link btn-sm" type="submit"><?= icon('trash', '', 14) ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-head">
        <h2>Who to inspect</h2>
        <div class="spacer"></div>
        <form method="get" action="<?= e(url('/admin/inspections')) ?>" class="filters">
            <div class="field">
                <label for="date">Date</label>
                <input type="date" id="date" name="date" value="<?= e($date) ?>" data-auto-submit>
            </div>
            <div class="field">
                <label for="branch_id">Branch</label>
                <select id="branch_id" name="branch_id" data-auto-submit>
                    <option value="">All branches</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= (int) $branch['id'] ?>" <?= $branchId === (int) $branch['id'] ? 'selected' : '' ?>><?= e($branch['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>

    <?php if ($supervisors === []): ?>
        <?= view_partial('partials.empty', ['message' => 'No active BC supervisors', 'iconName' => 'users']) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th>BC Supervisor</th><th>Branch</th><th class="center">Attendance</th>
                        <th class="right">Allocated</th><th class="center">Visits on date</th>
                        <th class="center">Inspected</th><th class="center">Adverse (30d)</th>
                        <th>Last inspected</th><th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($supervisors as $s): ?>
                        <tr>
                            <td><strong><?= e($s['name']) ?></strong><div class="tiny muted"><?= e($s['bc_code']) ?></div></td>
                            <td class="small"><?= e($s['branch_name']) ?></td>
                            <td class="center small">
                                <?php if ($s['check_in_at'] !== null): ?>
                                    <span class="badge badge-success"><?= e(format_time($s['check_in_at'])) ?></span>
                                <?php else: ?>
                                    <span class="badge badge-muted">no check-in</span>
                                <?php endif; ?>
                            </td>
                            <td class="right num"><?= number_format((int) $s['allocated']) ?></td>
                            <td class="center num strong"><?= (int) $s['visits_today'] ?></td>
                            <td class="center num"><?= (int) $s['inspected_today'] ?></td>
                            <td class="center num <?= (int) $s['adverse_30d'] > 0 ? 'danger-text strong' : '' ?>"><?= (int) $s['adverse_30d'] ?></td>
                            <td class="small">
                                <?= e($s['last_inspected'] ? format_date((string) $s['last_inspected']) : 'never') ?>
                            </td>
                            <td class="nowrap">
                                <a class="btn btn-link btn-sm" href="<?= e(url('/admin/inspections/supervisor/' . (int) $s['id'] . '?date=' . e($date))) ?>">Work</a>
                                <a class="btn btn-sm" href="<?= e(url('/admin/inspections/create?bc_supervisor_id=' . (int) $s['id'])) ?>">Inspect</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-head">
        <h2>Recent inspections</h2>
        <div class="spacer"></div>
        <a class="btn btn-secondary btn-sm" href="<?= e(url('/admin/reports/bc_inspection')) ?>">Full register &amp; exports</a>
    </div>
    <?php if ($recent === []): ?>
        <?= view_partial('partials.empty', ['message' => 'No inspections submitted yet', 'iconName' => 'search-check']) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data compact">
                <thead><tr><th>Date</th><th>Inspector</th><th>BC Supervisor</th><th>Account</th><th>Result</th><th class="center">Photos</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($recent as $row): ?>
                        <tr>
                            <td class="small nowrap"><?= e(format_date((string) $row['inspection_date'])) ?></td>
                            <td class="small"><?= e($row['inspector_name']) ?></td>
                            <td class="small"><?= e($row['supervisor_name']) ?> <span class="tiny muted"><?= e($row['bc_code']) ?></span></td>
                            <td class="small"><?= e($row['account_number'] ?: '—') ?></td>
                            <td><span class="<?= e(badge((string) $row['result'])) ?>"><?= e(inspection_result_label((string) $row['result'])) ?></span></td>
                            <td class="center num"><?= (int) $row['photo_count'] ?></td>
                            <td><a class="btn btn-link btn-sm" href="<?= e(url('/admin/inspections/' . (int) $row['id'])) ?>">Report</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
