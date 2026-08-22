<?php
/**
 * @var array $rows
 * @var int   $offlineMinutes
 * @var int   $online
 */
$isAdmin = App\Core\Auth::isAdmin();
$basePath = $isAdmin ? '/admin' : '/manager';
?>

<div class="page-head">
    <div class="grow">
        <h1>Live monitoring</h1>
        <div class="subtitle">
            Last reported position and today's activity per BCA.
            A supervisor is shown as online when their device has contacted the server
            within <?= (int) $offlineMinutes ?> minutes.
        </div>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="<?= e(url($basePath . '/monitoring')) ?>"><?= icon('refresh', '', 15) ?> Refresh</a>
    </div>
</div>

<div class="alert alert-info">
    <?= icon('info', '', 17) ?>
    <div>
        LRMS records a position each time the app reports in — when a visit is submitted, a photo is
        uploaded, or attendance is marked. It is <strong>not</strong> continuous tracking: while a device
        is offline or location permission is off, the last known point and its age are shown instead.
    </div>
</div>

<div class="stat-grid">
    <div class="stat good">
        <div class="label">Online now</div>
        <div class="value"><?= (int) $online ?></div>
    </div>
    <div class="stat">
        <div class="label">Offline</div>
        <div class="value"><?= max(0, count($rows) - (int) $online) ?></div>
    </div>
    <div class="stat accent">
        <div class="label">Visits today</div>
        <div class="value"><?= number_format(array_sum(array_map(static fn (array $r): int => (int) $r['visits_today'], $rows))) ?></div>
    </div>
    <div class="stat">
        <div class="label">Recovery today</div>
        <div class="value sm"><?= e(money_short(array_sum(array_map(static fn (array $r): float => (float) $r['recovery_today'], $rows)))) ?></div>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <h2>BCAs</h2>
        <div class="spacer"></div>
        <?php if ($isAdmin): ?>
            <form method="get" action="<?= e(url('/admin/monitoring')) ?>" class="filters">
                <div class="field">
                    <select name="branch_id" data-auto-submit aria-label="Branch">
                        <option value="">All branches</option>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?= (int) $branch['id'] ?>" <?= $branchId === (int) $branch['id'] ? 'selected' : '' ?>><?= e($branch['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($rows === []): ?>
        <?= view_partial('partials.empty', ['message' => 'No BCAs to monitor', 'iconName' => 'users']) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th class="center">State</th><th>BCA</th><th>Branch</th>
                        <th>Attendance</th><th class="center">Visits</th><th class="right">Recovery</th>
                        <th>Last visit</th><th>Last known location</th><th class="center">Report</th><th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td class="center">
                                <span class="dot <?= (int) $row['is_online'] === 1 ? 'online' : 'offline' ?>"
                                      title="<?= (int) $row['is_online'] === 1 ? 'Online' : 'Offline' ?>"></span>
                                <div class="tiny muted"><?= e(time_ago($row['last_seen_at'])) ?></div>
                            </td>
                            <td>
                                <strong><?= e($row['name']) ?></strong>
                                <div class="tiny muted">
                                    <?= e($row['bc_code']) ?> · <?= number_format((int) $row['allocated']) ?> accounts
                                    <?php if (!empty($row['model'])): ?> · <?= e($row['model']) ?><?php endif; ?>
                                </div>
                            </td>
                            <td class="small"><?= e($row['branch_name']) ?></td>
                            <td class="small">
                                <?php if ($row['check_in_at'] !== null): ?>
                                    In <?= e(format_time($row['check_in_at'])) ?>
                                    <?php if ($row['check_out_at'] !== null): ?>
                                        <div class="tiny muted">Out <?= e(format_time($row['check_out_at'])) ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge badge-muted">no check-in</span>
                                <?php endif; ?>
                            </td>
                            <td class="center num"><?= (int) $row['visits_today'] ?></td>
                            <td class="right num"><?= e(money((float) $row['recovery_today'])) ?></td>
                            <td class="small">
                                <?= e(time_ago($row['last_visit_at'])) ?>
                                <?php if (!empty($row['last_village'])): ?>
                                    <div class="tiny muted"><?= e($row['last_village']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="small">
                                <?php $map = map_link($row['last_latitude'], $row['last_longitude']); ?>
                                <?php if ($map !== null): ?>
                                    <a href="<?= e($map) ?>" target="_blank" rel="noopener"><?= e(coordinates($row['last_latitude'], $row['last_longitude'])) ?></a>
                                    <div class="tiny muted">
                                        <?= e(time_ago($row['last_location_at'])) ?>
                                        <?php if (!empty($row['last_address'])): ?> · <?= e(str_excerpt((string) $row['last_address'], 26)) ?><?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="muted tiny">not reported</span>
                                <?php endif; ?>
                            </td>
                            <td class="center">
                                <?php if ($row['report_status'] === null): ?>
                                    <span class="badge badge-muted">none</span>
                                <?php else: ?>
                                    <span class="<?= e(badge((string) $row['report_status'])) ?>"><?= e(enum_label((string) $row['report_status'])) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="nowrap">
                                <a class="btn btn-link btn-sm" href="<?= e(url($basePath . '/monitoring/route/' . (int) $row['id'])) ?>">Route</a>
                                <?php if ($isAdmin): ?>
                                    <a class="btn btn-link btn-sm" href="<?= e(url('/admin/inspections/create?bc_supervisor_id=' . (int) $row['id'])) ?>">Inspect</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
