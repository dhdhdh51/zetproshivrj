<div class="page-head">
    <div class="grow">
        <h1>Late submissions</h1>
        <div class="subtitle">
            Reports submitted after the server deadline. Approving one records who approved it, when,
            and why it was allowed.
        </div>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="<?= e(url('/admin/deadline')) ?>">Deadline settings</a>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <h2>Awaiting decision</h2>
        <div class="spacer"></div>
        <span class="small muted"><?= count($pending) ?> pending</span>
    </div>

    <?php if ($pending === []): ?>
        <?= view_partial('partials.empty', [
            'message' => 'Nothing awaiting approval',
            'hint' => 'Late submissions will appear here as they arrive.',
            'iconName' => 'check-circle',
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th>Report date</th><th>BC Supervisor</th><th>Branch</th>
                        <th>Deadline</th><th>Submitted</th><th class="center">Visits</th>
                        <th class="right">Recovery</th><th>Reason given</th><th style="width:280px">Decision</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pending as $row): ?>
                        <?php
                        $delayMinutes = (int) round((strtotime((string) $row['submitted_at']) - strtotime((string) $row['deadline_at'])) / 60);
                        ?>
                        <tr>
                            <td class="nowrap"><?= e(format_date((string) $row['report_date'])) ?></td>
                            <td><?= e($row['supervisor_name']) ?><div class="tiny muted"><?= e($row['bc_code']) ?></div></td>
                            <td class="small"><?= e($row['branch_name']) ?></td>
                            <td class="small"><?= e(format_time($row['deadline_at'])) ?></td>
                            <td class="small">
                                <?= e(format_time($row['submitted_at'])) ?>
                                <div class="tiny warning-text"><?= $delayMinutes ?> min late</div>
                            </td>
                            <td class="center num"><?= (int) $row['visits_count'] ?></td>
                            <td class="right num"><?= e(money((float) $row['recovery_amount'])) ?></td>
                            <td class="small"><?= e($row['late_reason'] ?: '— none given —') ?></td>
                            <td>
                                <form method="post" action="<?= e(url('/admin/deadline/' . (int) $row['id'] . '/decide')) ?>">
                                    <?= csrf_field() ?>
                                    <input type="text" name="remarks" placeholder="Remarks (required to reject)"
                                           maxlength="500" style="margin-bottom:6px">
                                    <div style="display:flex;gap:6px">
                                        <button type="submit" name="decision" value="approve" class="btn btn-success btn-sm">Approve</button>
                                        <button type="submit" name="decision" value="reject" class="btn btn-danger btn-sm">Reject</button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-head"><h2>Recent decisions</h2></div>
    <?php if ($decided === []): ?>
        <?= view_partial('partials.empty', ['message' => 'No decisions recorded yet', 'iconName' => 'history']) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data compact">
                <thead>
                    <tr><th>Report date</th><th>BC Supervisor</th><th>Branch</th><th class="center">Outcome</th><th>Decided</th><th>Remarks</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($decided as $row): ?>
                        <tr>
                            <td class="small nowrap"><?= e(format_date((string) $row['report_date'])) ?></td>
                            <td class="small"><?= e($row['supervisor_name']) ?> <span class="tiny muted"><?= e($row['bc_code']) ?></span></td>
                            <td class="small"><?= e($row['branch_name']) ?></td>
                            <td class="center">
                                <span class="badge <?= (string) $row['status'] === 'late_approved' ? 'badge-success' : 'badge-danger' ?>">
                                    <?= (string) $row['status'] === 'late_approved' ? 'Approved' : 'Rejected' ?>
                                </span>
                            </td>
                            <td class="small">
                                <?= e(format_datetime($row['approved_at'])) ?>
                                <div class="tiny muted">by <?= e($row['approver_name'] ?: '—') ?></div>
                            </td>
                            <td class="small"><?= e(str_excerpt((string) $row['approval_remarks'], 60)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
