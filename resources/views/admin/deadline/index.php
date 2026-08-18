<?php
/**
 * @var array  $status
 * @var string $date
 * @var array  $submissions
 * @var array  $missing
 */
$workingDays = $status['working_days'];
$dayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
?>

<div class="page-head">
    <div class="grow">
        <h1>Report deadline</h1>
        <div class="subtitle">
            Server time is authoritative — a device with a changed clock cannot gain extra time.
            Server now: <strong><?= e(format_datetime($status['server_time'])) ?></strong> (<?= e($status['server_timezone']) ?>).
        </div>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="<?= e(url('/admin/deadline/late')) ?>"><?= icon('clock', '', 15) ?> Late submissions</a>
    </div>
</div>

<div class="stat-grid">
    <div class="stat accent">
        <div class="label">Today's deadline</div>
        <div class="value sm"><?= e($status['deadline_time']) ?></div>
        <div class="meta">
            <?php if (!$status['is_working_day']): ?>
                Non-working day
            <?php elseif ($status['has_passed']): ?>
                <span class="danger-text">passed</span>
            <?php else: ?>
                <span data-countdown="<?= (int) $status['seconds_remaining'] ?>"></span>
            <?php endif; ?>
        </div>
    </div>
    <div class="stat good">
        <div class="label">Submitted</div>
        <div class="value"><?= count(array_filter($submissions, static fn (array $s): bool => in_array((string) $s['status'], ['submitted', 'late_approved'], true))) ?></div>
    </div>
    <div class="stat warn">
        <div class="label">Late pending</div>
        <div class="value"><?= count(array_filter($submissions, static fn (array $s): bool => (string) $s['status'] === 'late_pending')) ?></div>
    </div>
    <div class="stat bad">
        <div class="label">Not submitted</div>
        <div class="value"><?= count($missing) ?></div>
        <div class="meta">active supervisors with no record</div>
    </div>
</div>

<div class="grid grid-2">
    <div class="card">
        <div class="card-head"><h2>Deadline configuration</h2></div>
        <form method="post" action="<?= e(url('/admin/deadline')) ?>">
            <?= csrf_field() ?>
            <div class="card-body">
                <div class="field" style="max-width:200px">
                    <label for="report_deadline_time">Daily deadline (24h) <span class="req">*</span></label>
                    <input type="time" id="report_deadline_time" name="report_deadline_time"
                           value="<?= e($status['deadline_time']) ?>" required>
                </div>

                <div class="field">
                    <label>Working days</label>
                    <?php foreach ($dayNames as $number => $label): ?>
                        <div class="check">
                            <input type="checkbox" id="day_<?= $number ?>" name="working_days[]" value="<?= $number ?>"
                                   <?= in_array($number, $workingDays, true) ? 'checked' : '' ?>>
                            <label for="day_<?= $number ?>"><?= $label ?></label>
                        </div>
                    <?php endforeach; ?>
                    <div class="help">The deadline and its reminders only apply on these days.</div>
                </div>

                <div class="field" style="max-width:260px">
                    <label for="report_reminder_minutes">Reminders before deadline (minutes)</label>
                    <input type="text" id="report_reminder_minutes" name="report_reminder_minutes"
                           value="<?= e(implode(',', $status['reminder_minutes'])) ?>">
                    <div class="help">Comma separated, e.g. 60,30,10.</div>
                </div>

                <div class="check">
                    <input type="checkbox" id="allow_late_submission_requests" name="allow_late_submission_requests" value="1"
                           <?= $status['late_requests_allowed'] ? 'checked' : '' ?>>
                    <label for="allow_late_submission_requests">
                        Allow late submissions with Admin/Supervisor approval
                    </label>
                </div>
                <p class="help" style="margin:0">
                    When switched off, submissions after the deadline are locked outright and the app
                    tells the supervisor to contact you.
                </p>
            </div>
            <div class="card-foot">
                <button type="submit" class="btn"><?= icon('check', '', 15) ?> Save deadline settings</button>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="card-head"><h2>How it works</h2></div>
        <div class="card-body">
            <ul class="small" style="margin:0;padding-left:18px;line-height:1.9">
                <li>The app shows a live countdown driven by the <strong>server</strong> deadline, not the device clock.</li>
                <li>Reminders are pushed at each configured threshold to supervisors who have not submitted.</li>
                <li>A submission after the deadline is stored as <span class="badge badge-warning">late pending</span> and needs your approval.</li>
                <li>Approval or rejection records the deadline, submission time, reason, approver and time of decision.</li>
                <li>Unsubmitted reports can be locked once the day is over, closing the register.</li>
            </ul>

            <form method="post" action="<?= e(url('/admin/deadline/lock')) ?>" style="margin-top:14px"
                  data-confirm="Lock all pending reports for <?= e(format_date($date)) ?>?">
                <?= csrf_field() ?>
                <input type="hidden" name="date" value="<?= e($date) ?>">
                <button type="submit" class="btn btn-secondary btn-sm"><?= icon('lock', '', 14) ?> Lock pending reports for <?= e(format_date($date, 'd M')) ?></button>
            </form>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <h2>Submissions</h2>
        <div class="spacer"></div>
        <form method="get" action="<?= e(url('/admin/deadline')) ?>" class="filters">
            <div class="field"><input type="date" name="date" value="<?= e($date) ?>" data-auto-submit aria-label="Date"></div>
        </form>
    </div>

    <?php if ($submissions === []): ?>
        <?= view_partial('partials.empty', ['message' => 'No submissions recorded for this date', 'iconName' => 'clock']) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th>BC Supervisor</th><th>Branch</th><th>Deadline</th><th>Submitted</th>
                        <th class="center">Visits</th><th class="right">Recovery</th><th class="center">Status</th><th>Reason / decision</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($submissions as $submission): ?>
                        <tr>
                            <td><?= e($submission['supervisor_name']) ?><div class="tiny muted"><?= e($submission['bc_code']) ?></div></td>
                            <td class="small"><?= e($submission['branch_name']) ?></td>
                            <td class="small"><?= e(format_time($submission['deadline_at'])) ?></td>
                            <td class="small">
                                <?= e($submission['submitted_at'] ? format_time($submission['submitted_at']) : '—') ?>
                                <?php if ((int) $submission['is_late'] === 1): ?>
                                    <div class="tiny warning-text">late</div>
                                <?php endif; ?>
                            </td>
                            <td class="center num"><?= (int) $submission['visits_count'] ?></td>
                            <td class="right num"><?= e(money((float) $submission['recovery_amount'])) ?></td>
                            <td class="center"><span class="<?= e(badge((string) $submission['status'])) ?>"><?= e(enum_label((string) $submission['status'])) ?></span></td>
                            <td class="small">
                                <?= e(str_excerpt((string) $submission['late_reason'], 50)) ?>
                                <?php if (!empty($submission['approver_name'])): ?>
                                    <div class="tiny muted">
                                        by <?= e($submission['approver_name']) ?> · <?= e(format_datetime($submission['approved_at'])) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php if ($missing !== []): ?>
    <div class="card">
        <div class="card-head">
            <h2>No record for <?= e(format_date($date)) ?></h2>
            <div class="spacer"></div>
            <span class="small muted"><?= count($missing) ?> active supervisor(s)</span>
        </div>
        <div class="table-wrap">
            <table class="data compact">
                <thead><tr><th>BC Supervisor</th><th>BC code</th><th>Branch</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($missing as $supervisor): ?>
                        <tr>
                            <td><?= e($supervisor['name']) ?></td>
                            <td class="small mono"><?= e($supervisor['bc_code']) ?></td>
                            <td class="small"><?= e($supervisor['branch_name']) ?></td>
                            <td><a class="btn btn-link btn-sm" href="<?= e(url('/admin/inspections/supervisor/' . (int) $supervisor['id'] . '?date=' . e($date))) ?>">Check work</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
