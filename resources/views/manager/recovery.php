<div class="page-head">
    <div class="grow">
        <h1>Recovery and PTP</h1>
        <div class="subtitle">What borrowers repaid this period, and the promises still open.</div>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="<?= e(url('/manager/reports/recovery')) ?>"><?= icon('download', '', 15) ?> Recovery report</a>
        <a class="btn btn-secondary" href="<?= e(url('/manager/reports/ptp')) ?>"><?= icon('download', '', 15) ?> PTP report</a>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <form method="get" action="<?= e(url('/manager/recovery')) ?>" class="filters" style="flex:1 1 auto">
            <div class="field">
                <label for="from">From</label>
                <input type="date" id="from" name="from" value="<?= e($from) ?>">
            </div>
            <div class="field">
                <label for="to">To</label>
                <input type="date" id="to" name="to" value="<?= e($to) ?>">
            </div>
            <div class="actions"><button type="submit" class="btn btn-secondary"><?= icon('filter', '', 15) ?> Apply</button></div>
        </form>
    </div>
    <div class="card-body">
        <div class="stat-grid" style="margin:0">
            <div class="stat good">
                <div class="label">Repaid in period</div>
                <div class="value sm"><?= e(money((float) ($summary['total'] ?? 0))) ?></div>
                <div class="meta"><?= number_format((int) ($summary['entries'] ?? 0)) ?> entries</div>
            </div>
            <?php foreach (array_slice($byMode, 0, 3) as $mode): ?>
                <div class="stat">
                    <div class="label"><?= e($mode['payment_mode']) ?></div>
                    <div class="value sm"><?= e(money((float) $mode['total'])) ?></div>
                    <div class="meta"><?= number_format((int) $mode['entries']) ?> entries</div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="grid grid-2">
    <div class="card">
        <div class="card-head"><h2>Recovery by supervisor</h2></div>
        <div class="table-wrap">
            <table class="data compact">
                <thead><tr><th>Supervisor</th><th class="center">Entries</th><th class="right">Repaid</th></tr></thead>
                <tbody>
                    <?php foreach ($bySupervisor as $row): ?>
                        <tr>
                            <td><?= e($row['name']) ?> <span class="tiny muted"><?= e($row['bc_code']) ?></span></td>
                            <td class="center num"><?= number_format((int) $row['entries']) ?></td>
                            <td class="right num"><?= e(money((float) $row['total'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($bySupervisor === []): ?>
                        <tr><td colspan="3" class="muted small">No supervisors in this branch.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h2>By payment mode</h2></div>
        <div class="table-wrap">
            <table class="data compact">
                <thead><tr><th>Mode</th><th class="center">Entries</th><th class="right">Repaid</th></tr></thead>
                <tbody>
                    <?php foreach ($byMode as $row): ?>
                        <tr>
                            <td><?= e($row['payment_mode']) ?></td>
                            <td class="center num"><?= number_format((int) $row['entries']) ?></td>
                            <td class="right num"><?= e(money((float) $row['total'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($byMode === []): ?>
                        <tr><td colspan="3" class="muted small">Nothing repaid in this period.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <h2>Open promises to pay</h2>
        <div class="spacer"></div>
        <span class="small muted">Soonest first</span>
    </div>
    <?php if ($promises === []): ?>
        <?= view_partial('partials.empty', ['message' => 'No promises are pending', 'iconName' => 'calendar']) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data compact">
                <thead><tr><th>Promise date</th><th>Account</th><th>Borrower</th><th class="right">Amount</th><th>Supervisor</th><th>Remarks</th></tr></thead>
                <tbody>
                    <?php foreach ($promises as $promise): ?>
                        <?php $overdue = strtotime((string) $promise['promise_date']) < strtotime(today()); ?>
                        <tr>
                            <td class="small nowrap <?= $overdue ? 'danger-text strong' : '' ?>">
                                <?= e(format_date((string) $promise['promise_date'])) ?>
                                <?php if ($overdue): ?><div class="tiny">overdue</div><?php endif; ?>
                            </td>
                            <td class="small mono"><?= e($promise['account_number']) ?></td>
                            <td class="small"><?= e(str_excerpt((string) $promise['borrower_name'], 22)) ?></td>
                            <td class="right num"><?= e(money((float) $promise['promise_amount'])) ?></td>
                            <td class="small"><?= e($promise['supervisor_name'] ?: '—') ?></td>
                            <td class="small"><?= e(str_excerpt((string) $promise['remarks'], 40)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
