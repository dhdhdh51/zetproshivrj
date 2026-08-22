<div class="page-head">
    <div class="grow">
        <h1>Account allocation</h1>
        <div class="subtitle">
            Accounts carrying a known BC code go to that supervisor on import; the rest are
            balanced by current workload. Every change is written to the audit log.
        </div>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="<?= e(url('/admin/accounts?allocation=unassigned')) ?>">
            <?= icon('database', '', 15) ?> View unallocated
        </a>
    </div>
</div>

<div class="stat-grid">
    <div class="stat <?= $unassigned > 0 ? 'warn' : 'good' ?>">
        <div class="label">Not allocated</div>
        <div class="value"><?= number_format($unassigned) ?></div>
        <div class="meta">accounts without an owner</div>
    </div>
    <div class="stat">
        <div class="label">Active supervisors</div>
        <div class="value"><?= number_format(count($distribution)) ?></div>
        <div class="meta">receiving allocation</div>
    </div>
    <div class="stat">
        <div class="label">Average load</div>
        <div class="value">
            <?php
            $loads = array_map(static fn (array $r): int => (int) $r['accounts'], $distribution);
            echo $loads === [] ? '0' : number_format((int) round(array_sum($loads) / count($loads)));
            ?>
        </div>
        <div class="meta">accounts per supervisor</div>
    </div>
    <div class="stat">
        <div class="label">Spread</div>
        <div class="value">
            <?= $loads === [] ? '0' : number_format(max($loads) - min($loads)) ?>
        </div>
        <div class="meta">difference between highest and lowest</div>
    </div>
</div>

<?php if ($unassignedByBranch !== []): ?>
    <div class="card">
        <div class="card-head">
            <h2>Branches with unallocated accounts</h2>
            <div class="spacer"></div>
            <span class="small muted">Balancing spreads them evenly by current load</span>
        </div>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr><th>Branch</th><th class="right">Unallocated</th><th class="center">Active supervisors</th><th></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($unassignedByBranch as $branch): ?>
                        <tr>
                            <td><strong><?= e($branch['name']) ?></strong> <span class="tiny muted"><?= e($branch['code']) ?></span></td>
                            <td class="right num strong"><?= number_format((int) $branch['pending']) ?></td>
                            <td class="center num"><?= (int) $branch['supervisors'] ?></td>
                            <td class="right">
                                <?php if ((int) $branch['supervisors'] > 0): ?>
                                    <form method="post" action="<?= e(url('/admin/allocation/balance')) ?>" style="display:inline"
                                          data-confirm="Allocate <?= (int) $branch['pending'] ?> account(s) across <?= (int) $branch['supervisors'] ?> supervisor(s) of <?= e($branch['name']) ?>?">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="branch_id" value="<?= (int) $branch['id'] ?>">
                                        <button type="submit" class="btn btn-sm"><?= icon('layers', '', 14) ?> Balance now</button>
                                    </form>
                                <?php else: ?>
                                    <span class="badge badge-danger">No active supervisor</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php else: ?>
    <div class="alert alert-success">
        <?= icon('check-circle', '', 17) ?>
        <div>Every active account is allocated to a BCA.</div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-head">
        <h2>Current distribution</h2>
        <div class="spacer"></div>
        <form method="get" action="<?= e(url('/admin/allocation')) ?>" class="filters">
            <div class="field">
                <select name="branch_id" data-auto-submit aria-label="Branch">
                    <option value="">All branches</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= (int) $branch['id'] ?>" <?= $branchId === (int) $branch['id'] ? 'selected' : '' ?>>
                            <?= e($branch['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>

    <?php if ($distribution === []): ?>
        <?= view_partial('partials.empty', ['message' => 'No active BCAs', 'iconName' => 'users']) ?>
    <?php else: ?>
        <?php $maxLoad = max(1, max(array_map(static fn (array $r): int => (int) $r['accounts'], $distribution))); ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr><th>BCA</th><th>Branch</th><th class="right">Accounts</th><th style="width:180px">Load</th><th class="center">Visits today</th><th class="center">Status</th><th></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($distribution as $row): ?>
                        <tr>
                            <td><strong><?= e($row['name']) ?></strong><div class="tiny muted"><?= e($row['bc_code']) ?></div></td>
                            <td class="small"><?= e($row['branch_name']) ?></td>
                            <td class="right num strong"><?= number_format((int) $row['accounts']) ?></td>
                            <td>
                                <div class="bar"><span style="width:<?= (int) round(((int) $row['accounts'] / $maxLoad) * 100) ?>%"></span></div>
                            </td>
                            <td class="center num"><?= (int) $row['visits_today'] ?></td>
                            <td class="center"><span class="<?= e(badge((string) $row['status'])) ?>"><?= e(enum_label((string) $row['status'])) ?></span></td>
                            <td class="nowrap">
                                <a class="btn btn-link btn-sm" href="<?= e(url('/admin/accounts?bc_supervisor_id=' . (int) $row['id'])) ?>">Accounts</a>
                                <a class="btn btn-link btn-sm" href="<?= e(url('/admin/inspections/supervisor/' . (int) $row['id'])) ?>">Field work</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-head"><h2>Recent allocation changes</h2></div>
    <div class="table-wrap">
        <table class="data compact">
            <thead><tr><th>When</th><th>Account</th><th>Allocated to</th><th>Method</th><th>By</th><th>Reason</th></tr></thead>
            <tbody>
                <?php foreach ($recent as $row): ?>
                    <tr>
                        <td class="small nowrap"><?= e(time_ago($row['assigned_at'])) ?></td>
                        <td class="small">
                            <a class="mono" href="<?= e(url('/admin/accounts/' . (int) $row['loan_account_id'])) ?>"><?= e($row['account_number']) ?></a>
                            <div class="tiny muted"><?= e(str_excerpt((string) $row['borrower_name'], 26)) ?></div>
                        </td>
                        <td class="small"><?= e($row['supervisor_name']) ?> <span class="tiny muted"><?= e($row['bc_code']) ?></span></td>
                        <td><span class="badge badge-muted"><?= e(enum_label((string) $row['method'])) ?></span></td>
                        <td class="small"><?= e($row['assigned_by_name'] ?: 'system') ?></td>
                        <td class="small"><?= e(str_excerpt((string) $row['reason'], 44)) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($recent === []): ?>
                    <tr><td colspan="6" class="muted small">No allocations recorded yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
