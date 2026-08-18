<?php
/**
 * @var array $accounts
 * @var array $filters
 * @var array $branches
 * @var array $supervisors
 */
$f = static fn (string $key, string $default = ''): string => (string) ($filters[$key] ?? $default);
$isAdmin = App\Core\Auth::isAdmin();
$basePath = $isAdmin ? '/admin' : '/manager';
?>

<div class="page-head">
    <div class="grow">
        <h1>Loan accounts</h1>
        <div class="subtitle">
            <?= number_format($total) ?> account(s) ·
            Outstanding <?= e(money($sumOutstanding)) ?> ·
            Overdue <?= e(money($sumOverdue)) ?>
        </div>
    </div>
    <div class="page-actions">
        <?php if ($isAdmin): ?>
            <a class="btn btn-secondary" href="<?= e(url('/admin/allocation')) ?>"><?= icon('layers', '', 15) ?> Allocation</a>
            <a class="btn" href="<?= e(url('/admin/imports/create')) ?>"><?= icon('upload', '', 15) ?> Upload Excel</a>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <form method="get" action="<?= e(url($basePath . '/accounts')) ?>" class="filters" style="flex:1 1 auto">
            <div class="field wide">
                <label for="search">Search</label>
                <input type="search" id="search" name="search" value="<?= e($f('search')) ?>"
                       placeholder="Account, CIF, borrower, mobile, village">
            </div>

            <?php if ($isAdmin): ?>
                <div class="field">
                    <label for="branch_id">Branch</label>
                    <select id="branch_id" name="branch_id">
                        <option value="">All</option>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?= (int) $branch['id'] ?>" <?= $f('branch_id') === (string) $branch['id'] ? 'selected' : '' ?>>
                                <?= e($branch['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <div class="field">
                <label for="bc_supervisor_id">BC Supervisor</label>
                <select id="bc_supervisor_id" name="bc_supervisor_id">
                    <option value="">All</option>
                    <?php foreach ($supervisors as $supervisor): ?>
                        <option value="<?= (int) $supervisor['id'] ?>" <?= $f('bc_supervisor_id') === (string) $supervisor['id'] ? 'selected' : '' ?>>
                            <?= e($supervisor['name']) ?> (<?= e($supervisor['bc_code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="allocation">Allocation</label>
                <select id="allocation" name="allocation">
                    <option value="">Any</option>
                    <option value="assigned" <?= $f('allocation') === 'assigned' ? 'selected' : '' ?>>Allocated</option>
                    <option value="unassigned" <?= $f('allocation') === 'unassigned' ? 'selected' : '' ?>>Not allocated</option>
                </select>
            </div>

            <div class="field">
                <label for="recovery_status">Recovery</label>
                <select id="recovery_status" name="recovery_status">
                    <option value="">Any</option>
                    <?php foreach (['pending', 'in_progress', 'ptp', 'ots', 'partly_recovered', 'recovered', 'not_traceable'] as $status): ?>
                        <option value="<?= $status ?>" <?= $f('recovery_status') === $status ? 'selected' : '' ?>><?= e(enum_label($status)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="category">Stream</label>
                <select id="category" name="category">
                    <option value="">All</option>
                    <option value="general" <?= $f('category') === 'general' ? 'selected' : '' ?>>General</option>
                    <option value="krm_ots" <?= $f('category') === 'krm_ots' ? 'selected' : '' ?>>KRM OTS</option>
                    <option value="ckcc_od2" <?= $f('category') === 'ckcc_od2' ? 'selected' : '' ?>>CKCC OD-2</option>
                </select>
            </div>

            <div class="field">
                <label for="visited">Visited</label>
                <select id="visited" name="visited">
                    <option value="">Any</option>
                    <option value="never" <?= $f('visited') === 'never' ? 'selected' : '' ?>>Never visited</option>
                    <option value="visited" <?= $f('visited') === 'visited' ? 'selected' : '' ?>>Visited</option>
                </select>
            </div>

            <div class="actions">
                <button type="submit" class="btn btn-secondary"><?= icon('filter', '', 15) ?> Apply</button>
                <a class="btn btn-link" href="<?= e(url($basePath . '/accounts')) ?>">Reset</a>
            </div>
        </form>
    </div>

    <?php if ($accounts === []): ?>
        <?= view_partial('partials.empty', [
            'message' => 'No accounts match these filters',
            'hint' => 'Upload a loan Excel sheet or widen the filters.',
            'iconName' => 'database',
        ]) ?>
    <?php else: ?>
        <form method="post" action="<?= e(url('/admin/accounts/bulk-reassign')) ?>"
              data-confirm="Reassign the selected accounts to the chosen BC Supervisor?">
            <?= csrf_field() ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr>
                            <?php if ($isAdmin): ?><th style="width:26px"><input type="checkbox" data-check-all aria-label="Select all"></th><?php endif; ?>
                            <th><a href="<?= e(query_string(['sort' => 'account', 'direction' => sort_link_direction('account', $f('sort', 'overdue'), $f('direction', 'desc'))])) ?>">Account</a></th>
                            <th><a href="<?= e(query_string(['sort' => 'borrower', 'direction' => sort_link_direction('borrower', $f('sort', 'overdue'), $f('direction', 'desc'))])) ?>">Borrower</a></th>
                            <th>Village / branch</th>
                            <th class="right"><a href="<?= e(query_string(['sort' => 'outstanding', 'direction' => sort_link_direction('outstanding', $f('sort', 'overdue'), $f('direction', 'desc'))])) ?>">Outstanding</a></th>
                            <th class="right"><a href="<?= e(query_string(['sort' => 'overdue', 'direction' => sort_link_direction('overdue', $f('sort', 'overdue'), $f('direction', 'desc'))])) ?>">Overdue</a></th>
                            <th>BC Supervisor</th>
                            <th class="center"><a href="<?= e(query_string(['sort' => 'visits', 'direction' => sort_link_direction('visits', $f('sort', 'overdue'), $f('direction', 'desc'))])) ?>">Visits</a></th>
                            <th>Recovery status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($accounts as $account): ?>
                            <tr>
                                <?php if ($isAdmin): ?>
                                    <td><input type="checkbox" name="account_ids[]" value="<?= (int) $account['id'] ?>" data-row-check aria-label="Select account"></td>
                                <?php endif; ?>
                                <td>
                                    <a class="mono" href="<?= e(url($basePath . '/accounts/' . (int) $account['id'])) ?>"><?= e($account['account_number']) ?></a>
                                    <div class="tiny muted">
                                        <?= e($account['loan_type'] ?: '—') ?>
                                        <?php if ((string) $account['loan_category'] !== 'general'): ?>
                                            · <span class="badge badge-info"><?= e(strtoupper(str_replace('_', ' ', (string) $account['loan_category']))) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <strong><?= e($account['borrower_name']) ?></strong>
                                    <div class="tiny muted">
                                        <?= e($account['father_name'] ? 'S/o ' . $account['father_name'] : '') ?>
                                        <?php if (!empty($account['mobile'])): ?> · <?= e(mask_mobile($account['mobile'])) ?><?php endif; ?>
                                    </div>
                                </td>
                                <td class="small">
                                    <?= e($account['village'] ?: '—') ?>
                                    <div class="tiny muted"><?= e($account['branch_name']) ?></div>
                                </td>
                                <td class="right num"><?= e(money((float) $account['outstanding'])) ?></td>
                                <td class="right num strong"><?= e(money((float) $account['overdue'])) ?></td>
                                <td class="small">
                                    <?php if ($account['supervisor_name'] !== null): ?>
                                        <?= e($account['supervisor_name']) ?>
                                        <div class="tiny muted">
                                            <?= e($account['bc_code']) ?> · <?= e(enum_label((string) $account['allocation_method'])) ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="badge badge-warning">Not allocated</span>
                                    <?php endif; ?>
                                </td>
                                <td class="center num">
                                    <?= (int) $account['visit_count'] ?>
                                    <?php if ($account['last_visit_at'] !== null): ?>
                                        <div class="tiny muted"><?= e(time_ago((string) $account['last_visit_at'])) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="<?= e(badge((string) $account['recovery_status'])) ?>"><?= e(enum_label((string) $account['recovery_status'])) ?></span>
                                    <?php if ((float) $account['total_recovered'] > 0): ?>
                                        <div class="tiny success-text"><?= e(money((float) $account['total_recovered'])) ?> recovered</div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($isAdmin): ?>
                <div class="card-foot">
                    <span class="small muted">Bulk action for the selected rows:</span>
                    <select name="bc_supervisor_id" style="max-width:260px">
                        <option value="">Reassign to…</option>
                        <?php foreach ($supervisors as $supervisor): ?>
                            <option value="<?= (int) $supervisor['id'] ?>">
                                <?= e($supervisor['name']) ?> (<?= e($supervisor['bc_code']) ?>) — <?= e($supervisor['branch_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="reason" placeholder="Reason (recorded in the audit log)" style="max-width:280px" required>
                    <button type="submit" class="btn btn-sm">Reassign selected</button>
                </div>
            <?php endif; ?>
        </form>

        <?= view_partial('partials.pagination', [
            'page' => $page, 'lastPage' => $lastPage, 'total' => $total, 'perPage' => $perPage,
        ]) ?>
    <?php endif; ?>
</div>
