<?php
/**
 * @var array $branch
 * @var array $summary
 * @var array $trend
 * @var array $coverage
 * @var array $supervisors
 * @var array $activity
 * @var int   $pendingAccounts
 */
$deadline = $summary['deadline'];
$maxVisits = max(1, max(array_map(static fn (array $d): int => $d['visits'], $trend)));
?>

<div class="page-head">
    <div class="grow">
        <h1><?= e($branch['name'] ?? 'Branch') ?></h1>
        <div class="subtitle">
            Branch code <?= e($branch['code'] ?? '—') ?> ·
            <?= e(format_date(today(), 'l, d F Y')) ?> ·
            <?= $deadline['is_working_day'] ? 'report deadline ' . e(format_time($deadline['deadline_at'])) : 'non-working day' ?>
        </div>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="<?= e(url('/manager/monitoring')) ?>"><?= icon('activity', '', 15) ?> Monitoring</a>
        <a class="btn btn-secondary" href="<?= e(url('/manager/reports')) ?>"><?= icon('chart', '', 15) ?> Reports</a>
    </div>
</div>

<div class="stat-grid">
    <div class="stat accent">
        <div class="label">Accounts</div>
        <div class="value"><?= number_format($summary['accounts']) ?></div>
        <div class="meta"><?= number_format($summary['accounts_assigned']) ?> allocated · <?= number_format($summary['accounts_unassigned']) ?> pending</div>
    </div>
    <div class="stat bad">
        <div class="label">Overdue</div>
        <div class="value sm"><?= e(money_short($summary['overdue'])) ?></div>
        <div class="meta">Outstanding <?= e(money_short($summary['outstanding'])) ?></div>
    </div>
    <div class="stat good">
        <div class="label">Recovery today</div>
        <div class="value sm"><?= e(money_short($summary['recovery_today'])) ?></div>
        <div class="meta">Month <?= e(money_short($summary['recovery_month'])) ?></div>
    </div>
    <div class="stat">
        <div class="label">Visits today</div>
        <div class="value"><?= number_format($summary['visits_today']) ?></div>
        <div class="meta"><?= number_format($summary['visits_month']) ?> this month</div>
    </div>
    <div class="stat warn">
        <div class="label">PTP pending</div>
        <div class="value"><?= number_format($summary['promises_pending']) ?></div>
        <div class="meta"><?= e(money_short($summary['promises_pending_amount'])) ?></div>
    </div>
    <div class="stat <?= $pendingAccounts > 0 ? 'warn' : 'good' ?>">
        <div class="label">Never visited</div>
        <div class="value"><?= number_format($pendingAccounts) ?></div>
        <div class="meta"><a href="<?= e(url('/manager/pending')) ?>">Review list</a></div>
    </div>
</div>

<div class="grid grid-2">
    <div class="card">
        <div class="card-head"><h2>BC Supervisors today</h2></div>
        <?php if ($supervisors === []): ?>
            <?= view_partial('partials.empty', ['message' => 'No BC supervisors in this branch', 'iconName' => 'users']) ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data compact">
                    <thead>
                        <tr><th>Supervisor</th><th>Check-in</th><th class="right">Accounts</th><th class="center">Visits</th><th class="right">Recovery (MTD)</th><th class="center">Report</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($supervisors as $s): ?>
                            <tr>
                                <td>
                                    <?= e($s['name']) ?>
                                    <div class="tiny muted"><?= e($s['bc_code']) ?></div>
                                </td>
                                <td class="small">
                                    <?= e($s['check_in_at'] ? format_time($s['check_in_at']) : '—') ?>
                                    <?php if ($s['check_out_at'] !== null): ?>
                                        <div class="tiny muted">out <?= e(format_time($s['check_out_at'])) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="right num"><?= number_format((int) $s['accounts']) ?></td>
                                <td class="center num"><?= (int) $s['visits_today'] ?></td>
                                <td class="right num"><?= e(money((float) $s['recovery_month'])) ?></td>
                                <td class="center">
                                    <?php if ($s['report_status'] === null): ?>
                                        <span class="badge badge-muted">—</span>
                                    <?php else: ?>
                                        <span class="<?= e(badge((string) $s['report_status'])) ?>"><?= e(enum_label((string) $s['report_status'])) ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-head"><h2>Last 14 days</h2></div>
        <div class="card-body">
            <div class="sparkline" style="margin-bottom:10px">
                <?php foreach ($trend as $day): ?>
                    <div title="<?= e(format_date($day['date'])) ?>: <?= (int) $day['visits'] ?> visits, <?= e(money($day['recovered'])) ?>">
                        <i style="height:<?= (int) round(($day['visits'] / $maxVisits) * 56) ?>px"></i>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="kv">
                <div><div class="k">Inspection coverage</div><div class="v"><?= e($coverage['coverage_percent']) ?>%</div></div>
                <div><div class="k">Inspections this month</div><div class="v"><?= number_format($coverage['inspections_submitted']) ?></div></div>
                <div><div class="k">Adverse findings</div><div class="v"><?= number_format($coverage['adverse']) ?></div></div>
                <div><div class="k">KRM OTS / CKCC open</div><div class="v"><?= number_format($summary['krm_open']) ?> / <?= number_format($summary['ckcc_open']) ?></div></div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-head"><h2>Recent branch activity</h2></div>
    <div class="card-body">
        <ul class="timeline">
            <?php foreach ($activity as $entry): ?>
                <li>
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
