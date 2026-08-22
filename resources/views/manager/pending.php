<div class="page-head">
    <div class="grow">
        <h1>Pending accounts</h1>
        <div class="subtitle">
            Active accounts in this branch that have never been visited — <?= number_format($total) ?> in total,
            highest overdue first.
        </div>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="<?= e(url('/manager/reports/customer_visit')) ?>"><?= icon('file-text', '', 15) ?> Visit report</a>
    </div>
</div>

<div class="card">
    <?php if ($accounts === []): ?>
        <?= view_partial('partials.empty', [
            'message' => 'Every account has been visited at least once',
            'iconName' => 'check-circle',
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr><th>Account</th><th>Borrower</th><th>Village</th><th class="right">Outstanding</th><th class="right">Overdue</th><th>NPA date</th><th>BCA</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($accounts as $account): ?>
                        <tr>
                            <td class="small">
                                <a class="mono" href="<?= e(url('/manager/accounts/' . (int) $account['id'])) ?>"><?= e($account['account_number']) ?></a>
                                <div class="tiny muted"><?= e($account['loan_type'] ?: '—') ?></div>
                            </td>
                            <td>
                                <?= e($account['borrower_name']) ?>
                                <div class="tiny muted"><?= e($account['mobile'] ? mask_mobile((string) $account['mobile']) : '—') ?></div>
                            </td>
                            <td class="small"><?= e($account['village'] ?: '—') ?></td>
                            <td class="right num"><?= e(money((float) $account['outstanding'])) ?></td>
                            <td class="right num strong"><?= e(money((float) $account['overdue'])) ?></td>
                            <td class="small"><?= e(format_date($account['npa_date'])) ?></td>
                            <td class="small">
                                <?php if ($account['supervisor_name'] !== null): ?>
                                    <?= e($account['supervisor_name']) ?>
                                    <div class="tiny muted"><?= e($account['bc_code']) ?></div>
                                <?php else: ?>
                                    <span class="badge badge-warning">not allocated</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?= view_partial('partials.pagination', [
            'page' => $page, 'lastPage' => $lastPage, 'total' => $total, 'perPage' => $perPage,
        ]) ?>
    <?php endif; ?>
</div>
