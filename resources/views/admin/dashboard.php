<?php
/**
 * @var array $summary
 * @var array $trend
 * @var array $coverage
 * @var array $distribution
 * @var array $activity
 * @var array $topBranches
 * @var array $pendingLate
 */
$deadline = $summary['deadline'];
$maxVisits = max(1, max(array_map(static fn (array $d): int => $d['visits'], $trend)));
$maxRecovery = max(1.0, max(array_map(static fn (array $d): float => $d['recovered'], $trend)));
?>

<div class="page-head">
    <div class="grow">
        <h1>Recovery overview</h1>
        <div class="subtitle">
            <?= e(format_date(today(), 'l, d F Y')) ?> ·
            <?= $deadline['is_working_day'] ? 'Report deadline ' . e(format_time($deadline['deadline_at'])) . ' (server time)' : 'Non-working day' ?>
        </div>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="<?= e(url('/admin/monitoring')) ?>"><?= icon('activity', '', 15) ?> Live monitoring</a>
        <a class="btn" href="<?= e(url('/admin/imports/create')) ?>"><?= icon('upload', '', 15) ?> Upload Excel</a>
    </div>
</div>

<?php if ($summary['reports_late_pending'] > 0): ?>
    <div class="alert alert-warning">
        <?= icon('clock', '', 17) ?>
        <div>
            <strong><?= (int) $summary['reports_late_pending'] ?></strong> late report submission(s) are waiting for your approval.
            <a href="<?= e(url('/admin/deadline/late')) ?>">Review them</a>.
        </div>
    </div>
<?php endif; ?>

<?php if ($summary['accounts_unassigned'] > 0): ?>
    <div class="alert alert-info">
        <?= icon('layers', '', 17) ?>
        <div>
            <strong><?= number_format($summary['accounts_unassigned']) ?></strong> loan account(s) are not allocated to any BC Supervisor.
            <a href="<?= e(url('/admin/allocation')) ?>">Allocate now</a>.
        </div>
    </div>
<?php endif; ?>

<!-- Headline numbers -->
<div class="stat-grid">
    <div class="stat accent">
        <div class="label"><?= icon('database', '', 14) ?> Loan accounts</div>
        <div class="value"><?= number_format($summary['accounts']) ?></div>
        <div class="meta"><?= number_format($summary['accounts_assigned']) ?> allocated · <?= number_format($summary['accounts_unassigned']) ?> pending</div>
    </div>
    <div class="stat">
        <div class="label"><?= icon('rupee', '', 14) ?> Overdue book</div>
        <div class="value sm"><?= e(money_short($summary['overdue'])) ?></div>
        <div class="meta">Outstanding <?= e(money_short($summary['outstanding'])) ?></div>
    </div>
    <div class="stat good">
        <div class="label"><?= icon('rupee', '', 14) ?> Recovery today</div>
        <div class="value sm"><?= e(money_short($summary['recovery_today'])) ?></div>
        <div class="meta">This month <?= e(money_short($summary['recovery_month'])) ?></div>
    </div>
    <div class="stat">
        <div class="label"><?= icon('clipboard', '', 14) ?> Visits today</div>
        <div class="value"><?= number_format($summary['visits_today']) ?></div>
        <div class="meta"><?= number_format($summary['visits_month']) ?> this month</div>
    </div>
    <div class="stat <?= $summary['inspections_adverse'] > 0 ? 'bad' : '' ?>">
        <div class="label"><?= icon('search-check', '', 14) ?> Inspections</div>
        <div class="value"><?= number_format($summary['inspections_completed']) ?></div>
        <div class="meta">
            <?= number_format($summary['inspections_pending']) ?> draft ·
            <?= number_format($summary['inspections_adverse']) ?> adverse
        </div>
    </div>
    <div class="stat">
        <div class="label"><?= icon('users', '', 14) ?> BC Supervisors</div>
        <div class="value"><?= number_format($summary['supervisors_active']) ?></div>
        <div class="meta">
            <span class="dot online"></span> <?= (int) $summary['supervisors_online'] ?> online ·
            <span class="dot offline"></span> <?= (int) $summary['supervisors_offline'] ?> offline
        </div>
    </div>
    <div class="stat warn">
        <div class="label"><?= icon('calendar', '', 14) ?> PTP pending</div>
        <div class="value"><?= number_format($summary['promises_pending']) ?></div>
        <div class="meta"><?= e(money_short($summary['promises_pending_amount'])) ?> promised</div>
    </div>
    <div class="stat">
        <div class="label"><?= icon('building', '', 14) ?> Branches</div>
        <div class="value"><?= number_format($summary['branches']) ?></div>
        <div class="meta"><?= number_format($summary['branch_managers']) ?> branch managers</div>
    </div>
</div>

<div class="grid grid-2">
    <!-- Trend -->
    <div class="card">
        <div class="card-head">
            <h2>Last 14 days</h2>
            <div class="spacer"></div>
            <span class="small muted">Visits (bars) and recovery</span>
        </div>
        <div class="card-body">
            <div class="sparkline" style="margin-bottom:8px">
                <?php foreach ($trend as $day): ?>
                    <div title="<?= e(format_date($day['date'])) ?>: <?= (int) $day['visits'] ?> visits, <?= e(money($day['recovered'])) ?>">
                        <i style="height:<?= (int) round(($day['visits'] / $maxVisits) * 56) ?>px"></i>
                    </div>
                <?php endforeach; ?>
            </div>
            <table class="data compact">
                <thead>
                    <tr><th>Date</th><th class="center">Visits</th><th class="right">Recovery</th></tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice(array_reverse($trend), 0, 7) as $day): ?>
                        <tr>
                            <td><?= e(format_date($day['date'], 'D, d M')) ?></td>
                            <td class="center num"><?= (int) $day['visits'] ?></td>
                            <td class="right num"><?= e(money($day['recovered'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Inspection coverage: the Admin/Supervisor's own KPI -->
    <div class="card">
        <div class="card-head">
            <h2>BC Supervisor inspection coverage</h2>
            <div class="spacer"></div>
            <a class="btn btn-secondary btn-sm" href="<?= e(url('/admin/inspections')) ?>">Open inspections</a>
        </div>
        <div class="card-body">
            <p class="small muted">
                Share of BC Supervisors whose monthly inspection has been carried out. The Bank's
                form is one visit to the BC point per agent per month — Admin/Supervisor field
                activity is inspection only, customer recovery visits are the BC Supervisor's work.
            </p>

            <div style="display:flex;align-items:baseline;gap:10px;margin:10px 0 6px">
                <div style="font-size:30px;font-weight:700"><?= e($coverage['coverage_percent']) ?>%</div>
                <div class="small muted">
                    <?= number_format($coverage['supervisors_inspected']) ?> of
                    <?= number_format($coverage['supervisors']) ?> BC Supervisors inspected this month
                </div>
            </div>
            <div class="bar">
                <?php // Every supervisor is meant to be inspected, so anything short of all of
                      // them is behind — not the sampling rate the old visit measure implied. ?>
                <span class="<?= $coverage['coverage_percent'] >= 100 ? 'good' : ($coverage['coverage_percent'] >= 75 ? 'warn' : 'bad') ?>"
                      style="width:<?= min(100, (float) $coverage['coverage_percent']) ?>%"></span>
            </div>

            <?php if ($coverage['by_result'] !== []): ?>
                <table class="data compact" style="margin-top:14px">
                    <thead><tr><th>Result</th><th class="right">Count</th></tr></thead>
                    <tbody>
                        <?php foreach ($coverage['by_result'] as $row): ?>
                            <tr>
                                <td><span class="<?= e(badge((string) $row['result'])) ?>"><?= e(inspection_result_label((string) $row['result'])) ?></span></td>
                                <td class="right num"><?= (int) $row['total'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="small muted" style="margin-top:12px">No inspections submitted this month yet.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="grid grid-2">
    <!-- Work stream tiles -->
    <div class="card">
        <div class="card-head"><h2>Work streams</h2></div>
        <div class="card-body">
            <div class="kv">
                <div>
                    <div class="k">KRM OTS cases</div>
                    <div class="v">
                        <?= number_format($summary['krm_total']) ?>
                        <span class="muted small">(<?= number_format($summary['krm_open']) ?> open · <?= e(money_short($summary['krm_amount'])) ?>)</span>
                    </div>
                </div>
                <div>
                    <div class="k">CKCC OD-2 renewals</div>
                    <div class="v">
                        <?= number_format($summary['ckcc_total']) ?>
                        <span class="muted small">(<?= number_format($summary['ckcc_open']) ?> open · <?= number_format($summary['ckcc_renewed']) ?> renewed)</span>
                    </div>
                </div>
                <div>
                    <div class="k">Daily reports today</div>
                    <div class="v">
                        <?= number_format($summary['reports_submitted']) ?> submitted
                        <?php if ($summary['reports_late_pending'] > 0): ?>
                            <span class="badge badge-warning"><?= (int) $summary['reports_late_pending'] ?> late pending</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div>
                    <div class="k">PTP outcome</div>
                    <div class="v">
                        <?= number_format($summary['promises_kept']) ?> kept ·
                        <?= number_format($summary['promises_broken']) ?> broken
                    </div>
                </div>
            </div>

            <div style="display:flex;gap:8px;margin-top:14px;flex-wrap:wrap">
                <a class="btn btn-secondary btn-sm" href="<?= e(url('/admin/krm-ots')) ?>">KRM OTS register</a>
                <a class="btn btn-secondary btn-sm" href="<?= e(url('/admin/ckcc')) ?>">CKCC OD-2 register</a>
            </div>
        </div>
    </div>

    <!-- Workload distribution -->
    <div class="card">
        <div class="card-head">
            <h2>Allocation load</h2>
            <div class="spacer"></div>
            <a class="btn btn-secondary btn-sm" href="<?= e(url('/admin/allocation')) ?>">Manage</a>
        </div>
        <div class="table-wrap">
            <table class="data compact">
                <thead>
                    <tr><th>BC Supervisor</th><th>Branch</th><th class="right">Accounts</th><th class="right">Today</th></tr>
                </thead>
                <tbody>
                    <?php if ($distribution === []): ?>
                        <tr><td colspan="4"><?= view_partial('partials.empty', ['message' => 'No BC Supervisors yet', 'iconName' => 'users']) ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($distribution as $row): ?>
                            <tr>
                                <td>
                                    <a href="<?= e(url('/admin/inspections/supervisor/' . (int) $row['id'])) ?>"><?= e($row['name']) ?></a>
                                    <div class="tiny muted"><?= e($row['bc_code']) ?></div>
                                </td>
                                <td class="small"><?= e($row['branch_name']) ?></td>
                                <td class="right num"><?= number_format((int) $row['accounts']) ?></td>
                                <td class="right num"><?= number_format((int) $row['visits_today']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="grid grid-2">
    <!-- Branch leaderboard -->
    <div class="card">
        <div class="card-head">
            <h2>Branch performance this month</h2>
            <div class="spacer"></div>
            <a class="btn btn-secondary btn-sm" href="<?= e(url('/admin/reports/branch_performance')) ?>">Full report</a>
        </div>
        <div class="table-wrap">
            <table class="data compact">
                <thead><tr><th>Branch</th><th class="right">Visits</th><th class="right">Recovered</th></tr></thead>
                <tbody>
                    <?php foreach ($topBranches as $branch): ?>
                        <tr>
                            <td><?= e($branch['name']) ?> <span class="tiny muted"><?= e($branch['code']) ?></span></td>
                            <td class="right num"><?= number_format((int) $branch['visits']) ?></td>
                            <td class="right num"><?= e(money((float) $branch['recovered'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($topBranches === []): ?>
                        <tr><td colspan="3" class="muted small">No branches configured yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Audit feed -->
    <div class="card">
        <div class="card-head">
            <h2>Recent activity</h2>
            <div class="spacer"></div>
            <a class="btn btn-secondary btn-sm" href="<?= e(url('/admin/audit')) ?>">Audit log</a>
        </div>
        <div class="card-body">
            <ul class="timeline">
                <?php foreach ($activity as $entry): ?>
                    <li class="<?= str_contains((string) $entry['action'], 'failed') ? 'bad' : '' ?>">
                        <time><?= e(time_ago((string) $entry['created_at'])) ?> · <?= e($entry['user_name'] ?? 'system') ?></time>
                        <?= e($entry['description'] ?? enum_label((string) $entry['action'])) ?>
                    </li>
                <?php endforeach; ?>
                <?php if ($activity === []): ?>
                    <li class="muted small">No activity recorded yet.</li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</div>

<?php if ($pendingLate !== []): ?>
    <div class="card">
        <div class="card-head">
            <h2>Late submissions awaiting approval</h2>
            <div class="spacer"></div>
            <a class="btn btn-secondary btn-sm" href="<?= e(url('/admin/deadline/late')) ?>">Review all</a>
        </div>
        <div class="table-wrap">
            <table class="data compact">
                <thead>
                    <tr><th>Report date</th><th>BC Supervisor</th><th>Branch</th><th>Submitted</th><th>Reason</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($pendingLate as $row): ?>
                        <tr>
                            <td><?= e(format_date((string) $row['report_date'])) ?></td>
                            <td><?= e($row['supervisor_name']) ?> <span class="tiny muted"><?= e($row['bc_code']) ?></span></td>
                            <td class="small"><?= e($row['branch_name']) ?></td>
                            <td class="small"><?= e(format_datetime((string) $row['submitted_at'])) ?></td>
                            <td class="small"><?= e(str_excerpt((string) $row['late_reason'], 70)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
