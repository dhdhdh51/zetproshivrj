<?php
/**
 * A BC Supervisor's work picture, as the inspector sees it before going out.
 *
 * @var array  $supervisor
 * @var string $date
 * @var int    $assigned_accounts
 * @var array  $visits_today
 * @var int    $completed_today
 * @var int    $pending_count
 * @var array  $pending_accounts
 * @var array|null $attendance
 * @var array|null $device
 * @var float  $recovery_today
 * @var array  $inspections
 */
$supervisorId = (int) $supervisor['id'];
$offlineMinutes = (int) setting('supervisor_offline_minutes', 15);
$online = $device !== null && $device['last_seen_at'] !== null
    && strtotime((string) $device['last_seen_at']) > time() - ($offlineMinutes * 60);
?>

<div class="page-head">
    <div class="grow">
        <h1><?= e($supervisor['name']) ?></h1>
        <div class="subtitle">
            <?= e($supervisor['bc_code']) ?> · <?= e($supervisor['branch_name']) ?> (<?= e($supervisor['branch_code']) ?>)
            · <?= e($supervisor['mobile'] ?: $supervisor['user_mobile'] ?: '—') ?>
            · <span class="<?= e(badge((string) $supervisor['status'])) ?>"><?= e(enum_label((string) $supervisor['status'])) ?></span>
        </div>
    </div>
    <div class="page-actions">
        <form method="get" action="<?= e(url('/admin/inspections/supervisor/' . $supervisorId)) ?>" style="display:flex;gap:8px">
            <input type="date" name="date" value="<?= e($date) ?>" data-auto-submit>
        </form>
        <a class="btn" href="<?= e(url('/admin/inspections/create?bc_supervisor_id=' . $supervisorId)) ?>">
            <?= icon('search-check', '', 15) ?> Start inspection
        </a>
    </div>
</div>

<div class="stat-grid">
    <div class="stat accent">
        <div class="label">Allocated accounts</div>
        <div class="value"><?= number_format($assigned_accounts) ?></div>
        <div class="meta"><?= number_format($pending_count) ?> never visited</div>
    </div>
    <div class="stat">
        <div class="label">Visits on <?= e(format_date($date, 'd M')) ?></div>
        <div class="value"><?= number_format($completed_today) ?></div>
        <div class="meta"><?= number_format(count($visits_today)) ?> record(s) on the device</div>
    </div>
    <div class="stat good">
        <div class="label">Recovery on date</div>
        <div class="value sm"><?= e(money_short($recovery_today)) ?></div>
    </div>
    <div class="stat">
        <div class="label">Attendance</div>
        <div class="value sm">
            <?php if ($attendance !== null && $attendance['check_in_at'] !== null): ?>
                <?= e(format_time($attendance['check_in_at'])) ?>
            <?php else: ?>
                —
            <?php endif; ?>
        </div>
        <div class="meta">
            <?php if ($attendance !== null): ?>
                <?= e(enum_label((string) $attendance['status'])) ?>
                <?php if ($attendance['check_out_at'] !== null): ?>
                    · out <?= e(format_time($attendance['check_out_at'])) ?>
                    · <?= e(minutes_to_hours((int) $attendance['working_minutes'])) ?>
                <?php endif; ?>
            <?php else: ?>
                No check-in recorded
            <?php endif; ?>
        </div>
    </div>
    <div class="stat">
        <div class="label">Device</div>
        <div class="value sm">
            <span class="dot <?= $online ? 'online' : 'offline' ?>"></span> <?= $online ? 'Online' : 'Offline' ?>
        </div>
        <div class="meta">
            <?php if ($device !== null): ?>
                <?= e($device['model'] ?: 'device') ?> · seen <?= e(time_ago($device['last_seen_at'])) ?>
            <?php else: ?>
                No device bound
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($device !== null && $device['last_latitude'] !== null): ?>
    <div class="alert alert-info">
        <?= icon('map-pin', '', 17) ?>
        <div>
            <strong>Last known position</strong> (<?= e(time_ago($device['last_location_at'])) ?>):
            <?php $map = map_link($device['last_latitude'], $device['last_longitude']); ?>
            <?php if ($map !== null): ?>
                <a href="<?= e($map) ?>" target="_blank" rel="noopener"><?= e(coordinates($device['last_latitude'], $device['last_longitude'])) ?></a>
            <?php endif; ?>
            <?= !empty($device['last_address']) ? '· ' . e($device['last_address']) : '' ?>.
            LRMS records positions when the app reports in; it does not track continuously while the
            device is offline or location permission is off.
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-head">
        <h2>Visits recorded on <?= e(format_date($date)) ?></h2>
        <div class="spacer"></div>
        <span class="small muted">Pick one to verify in the field</span>
    </div>
    <?php if ($visits_today === []): ?>
        <?= view_partial('partials.empty', [
            'message' => 'No visits recorded on this date',
            'hint' => 'You can still inspect an allocated account below to check whether work is being done at all.',
            'iconName' => 'clipboard',
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th>Account</th><th>Borrower</th><th>Village</th><th>Status</th>
                        <th class="center">Photos</th><th class="center">GPS</th><th>Recorded location</th>
                        <th class="center">Inspected</th><th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($visits_today as $visit): ?>
                        <tr>
                            <td class="small"><a class="mono" href="<?= e(url('/admin/visits/' . (int) $visit['id'])) ?>"><?= e($visit['account_number']) ?></a></td>
                            <td class="small">
                                <?= e($visit['borrower_name']) ?>
                                <?php if (!empty($visit['mobile'])): ?>
                                    <div class="tiny muted"><?= e(mask_mobile($visit['mobile'])) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="small"><?= e($visit['village'] ?: '—') ?></td>
                            <td>
                                <span class="badge badge-muted"><?= e(visit_status_label($visit['visit_status'])) ?></span>
                                <?php if ((string) $visit['status'] === 'draft'): ?>
                                    <div class="tiny warning-text">draft on device</div>
                                <?php endif; ?>
                            </td>
                            <td class="center num <?= (int) $visit['photos'] === 0 ? 'danger-text' : '' ?>"><?= (int) $visit['photos'] ?></td>
                            <td class="center"><?= (int) $visit['gps_verified'] === 1 ? icon('check-circle', 'success-text', 15) : icon('x-circle', 'danger-text', 15) ?></td>
                            <td class="small">
                                <?php $map = map_link($visit['latitude'], $visit['longitude']); ?>
                                <?php if ($map !== null): ?>
                                    <a href="<?= e($map) ?>" target="_blank" rel="noopener"><?= e(coordinates($visit['latitude'], $visit['longitude'])) ?></a>
                                    <?php if ($visit['accuracy'] !== null): ?>
                                        <div class="tiny muted">±<?= number_format((float) $visit['accuracy'], 0) ?> m</div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="muted tiny">not captured</span>
                                <?php endif; ?>
                            </td>
                            <td class="center"><?= (int) $visit['inspections'] > 0 ? icon('check-circle', 'success-text', 15) : '<span class="muted tiny">—</span>' ?></td>
                            <td class="nowrap">
                                <a class="btn btn-sm" href="<?= e(url('/admin/inspections/create?bc_supervisor_id=' . $supervisorId . '&visit_id=' . (int) $visit['id'])) ?>">
                                    Verify
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="grid grid-2">
    <div class="card">
        <div class="card-head">
            <h2>Pending accounts (never visited)</h2>
            <div class="spacer"></div>
            <span class="small muted"><?= number_format($pending_count) ?> total</span>
        </div>
        <?php if ($pending_accounts === []): ?>
            <?= view_partial('partials.empty', ['message' => 'Every allocated account has been visited', 'iconName' => 'check-circle']) ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data compact">
                    <thead><tr><th>Account</th><th>Borrower</th><th>Village</th><th class="right">Overdue</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach (array_slice($pending_accounts, 0, 15) as $account): ?>
                            <tr>
                                <td class="small mono"><?= e($account['account_number']) ?></td>
                                <td class="small"><?= e(str_excerpt((string) $account['borrower_name'], 20)) ?></td>
                                <td class="small"><?= e($account['village'] ?: '—') ?></td>
                                <td class="right num small"><?= e(money((float) $account['overdue'])) ?></td>
                                <td>
                                    <a class="btn btn-link btn-sm"
                                       href="<?= e(url('/admin/inspections/create?bc_supervisor_id=' . $supervisorId . '&loan_account_id=' . (int) $account['id'])) ?>">
                                        Inspect
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-head"><h2>Inspection history</h2></div>
        <?php if ($inspections === []): ?>
            <?= view_partial('partials.empty', ['message' => 'This supervisor has never been inspected', 'iconName' => 'search-check']) ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data compact">
                    <thead><tr><th>Date</th><th>Inspector</th><th>Account</th><th>Result</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($inspections as $inspection): ?>
                            <tr>
                                <td class="small nowrap"><?= e(format_date((string) $inspection['inspection_date'])) ?></td>
                                <td class="small"><?= e($inspection['inspector_name']) ?></td>
                                <td class="small mono"><?= e($inspection['account_number'] ?: '—') ?></td>
                                <td>
                                    <?php if ((string) $inspection['status'] === 'draft'): ?>
                                        <span class="badge badge-warning">draft</span>
                                    <?php else: ?>
                                        <span class="<?= e(badge((string) $inspection['result'])) ?>"><?= e(inspection_result_label((string) $inspection['result'])) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a class="btn btn-link btn-sm" href="<?= e(url('/admin/inspections/' . (int) $inspection['id'])) ?>">Open</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
