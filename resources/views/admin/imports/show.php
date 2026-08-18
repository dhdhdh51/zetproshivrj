<?php
/**
 * @var array $import
 * @var array $mapping
 * @var array $errors
 * @var array $errorCounts
 * @var array $accounts
 * @var array $systemFields
 */
?>

<div class="page-head">
    <div class="grow">
        <h1><?= e($import['original_name']) ?></h1>
        <div class="subtitle">
            Uploaded <?= e(format_datetime($import['created_at'])) ?> ·
            <span class="<?= e(badge((string) $import['status'])) ?>"><?= e(enum_label((string) $import['status'])) ?></span>
            <?php if ($import['completed_at'] !== null): ?>
                · completed <?= e(format_datetime($import['completed_at'])) ?>
            <?php endif; ?>
        </div>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="<?= e(url('/admin/imports')) ?>">Back to imports</a>
        <a class="btn btn-secondary" href="<?= e(url('/admin/accounts?search=')) ?>">Browse accounts</a>
    </div>
</div>

<?php if (!empty($import['error_message'])): ?>
    <div class="alert alert-error">
        <?= icon('x-circle', '', 17) ?>
        <div><?= e($import['error_message']) ?></div>
    </div>
<?php endif; ?>

<div class="stat-grid">
    <div class="stat accent">
        <div class="label">Rows processed</div>
        <div class="value"><?= number_format((int) $import['imported_rows']) ?></div>
        <div class="meta">of <?= number_format((int) $import['total_rows']) ?> in the sheet</div>
    </div>
    <div class="stat good">
        <div class="label">New accounts</div>
        <div class="value"><?= number_format((int) $import['created_accounts']) ?></div>
    </div>
    <div class="stat">
        <div class="label">Updated accounts</div>
        <div class="value"><?= number_format((int) $import['updated_accounts']) ?></div>
    </div>
    <div class="stat <?= (int) $import['skipped_rows'] > 0 ? 'bad' : '' ?>">
        <div class="label">Skipped rows</div>
        <div class="value"><?= number_format((int) $import['skipped_rows']) ?></div>
    </div>
    <div class="stat">
        <div class="label">Allocated</div>
        <div class="value"><?= number_format((int) $import['assigned_rows']) ?></div>
        <div class="meta">to BC Supervisors</div>
    </div>
</div>

<?php if ($errorCounts !== []): ?>
    <div class="card">
        <div class="card-head"><h2>Problem summary</h2></div>
        <div class="table-wrap">
            <table class="data compact">
                <thead><tr><th>Type</th><th class="center">Severity</th><th class="right">Rows</th><th>What it means</th></tr></thead>
                <tbody>
                    <?php
                    $explanations = [
                        'missing_required' => 'Account Number or Borrower Name was empty — the row was skipped.',
                        'invalid_data' => 'An amount, date or mobile number could not be parsed.',
                        'duplicate' => 'The same account number appeared more than once in the file.',
                        'unknown_branch' => 'The branch code/name is not set up in LRMS — create the branch and re-import.',
                        'invalid_bc' => 'The BC code did not match an active supervisor of that branch; the account was balanced by workload instead.',
                        'other' => 'See the row detail below.',
                    ];
                    ?>
                    <?php foreach ($errorCounts as $count): ?>
                        <tr>
                            <td><?= e(enum_label((string) $count['error_type'])) ?></td>
                            <td class="center">
                                <span class="badge <?= (string) $count['severity'] === 'error' ? 'badge-danger' : 'badge-warning' ?>">
                                    <?= e((string) $count['severity']) ?>
                                </span>
                            </td>
                            <td class="right num"><?= number_format((int) $count['total']) ?></td>
                            <td class="small muted"><?= e($explanations[(string) $count['error_type']] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php if ($errors !== []): ?>
    <div class="card">
        <div class="card-head">
            <h2>Row detail</h2>
            <div class="spacer"></div>
            <span class="small muted">First <?= count($errors) ?> issue(s)</span>
        </div>
        <div class="table-wrap">
            <table class="data compact">
                <thead><tr><th>Row</th><th>Account</th><th>Type</th><th>Message</th></tr></thead>
                <tbody>
                    <?php foreach ($errors as $error): ?>
                        <tr>
                            <td class="tiny muted"><?= (int) $error['row_number'] ?></td>
                            <td class="small mono"><?= e($error['account_number'] ?: '—') ?></td>
                            <td>
                                <span class="badge <?= (string) $error['severity'] === 'error' ? 'badge-danger' : 'badge-warning' ?>">
                                    <?= e(enum_label((string) $error['error_type'])) ?>
                                </span>
                            </td>
                            <td class="small"><?= e($error['message']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<div class="grid grid-2">
    <div class="card">
        <div class="card-head"><h3>Accounts from this file</h3></div>
        <?php if ($accounts === []): ?>
            <?= view_partial('partials.empty', ['message' => 'No accounts stored from this file', 'iconName' => 'database']) ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data compact">
                    <thead><tr><th>Account</th><th>Borrower</th><th class="right">Overdue</th><th>BC Supervisor</th></tr></thead>
                    <tbody>
                        <?php foreach ($accounts as $account): ?>
                            <tr>
                                <td class="small"><a class="mono" href="<?= e(url('/admin/accounts/' . (int) $account['id'])) ?>"><?= e($account['account_number']) ?></a></td>
                                <td class="small"><?= e(str_excerpt((string) $account['borrower_name'], 22)) ?></td>
                                <td class="right num small"><?= e(money((float) $account['overdue'])) ?></td>
                                <td class="small">
                                    <?= e($account['supervisor_name'] ?: '—') ?>
                                    <?php if (!empty($account['bc_code'])): ?><div class="tiny muted"><?= e($account['bc_code']) ?></div><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-head"><h3>Mapping used</h3></div>
        <div class="card-body">
            <div class="kv">
                <?php foreach ($systemFields as $key => $field): ?>
                    <div>
                        <div class="k"><?= e($field['label']) ?></div>
                        <div class="v"><?= isset($mapping[$key]) ? e($mapping[$key]) : '<span class="muted">not mapped</span>' ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
