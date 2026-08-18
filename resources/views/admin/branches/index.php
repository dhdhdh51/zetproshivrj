<div class="page-head">
    <div class="grow">
        <h1>Branches</h1>
        <div class="subtitle"><?= count($branches) ?> branch(es). Accounts are imported against these codes.</div>
    </div>
    <div class="page-actions">
        <a class="btn" href="<?= e(url('/admin/branches/create')) ?>"><?= icon('plus', '', 15) ?> Add branch</a>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <form method="get" action="<?= e(url('/admin/branches')) ?>" class="filters" style="flex:1 1 auto">
            <div class="field wide">
                <label for="search">Search</label>
                <input type="search" id="search" name="search" value="<?= e($search) ?>" placeholder="Name, code, district">
            </div>
            <div class="field">
                <label for="status">Status</label>
                <select id="status" name="status" data-auto-submit>
                    <option value="">All</option>
                    <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <div class="actions">
                <button type="submit" class="btn btn-secondary"><?= icon('search', '', 15) ?> Filter</button>
            </div>
        </form>
    </div>

    <?php if ($branches === []): ?>
        <?= view_partial('partials.empty', [
            'message' => 'No branches match',
            'hint' => 'Create the branches your Excel sheets refer to, then import accounts.',
            'iconName' => 'building',
            'actionUrl' => '/admin/branches/create',
            'actionLabel' => 'Add branch',
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Branch</th>
                        <th>District / region</th>
                        <th class="center">Managers</th>
                        <th class="center">BC Supervisors</th>
                        <th class="right">Accounts</th>
                        <th class="right">Overdue</th>
                        <th class="center">GPS</th>
                        <th class="center">Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($branches as $branch): ?>
                        <tr>
                            <td class="mono"><?= e($branch['code']) ?></td>
                            <td>
                                <strong><?= e($branch['name']) ?></strong>
                                <?php if (!empty($branch['phone'])): ?>
                                    <div class="tiny muted"><?= e($branch['phone']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="small">
                                <?= e($branch['district'] ?: '—') ?>
                                <?php if (!empty($branch['region'])): ?>
                                    <div class="tiny muted"><?= e($branch['region']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="center num"><?= (int) $branch['managers'] ?></td>
                            <td class="center num"><?= (int) $branch['supervisors'] ?></td>
                            <td class="right num"><?= number_format((int) $branch['accounts']) ?></td>
                            <td class="right num"><?= e(money((float) $branch['overdue'])) ?></td>
                            <td class="center">
                                <?php if ($branch['latitude'] !== null): ?>
                                    <span title="<?= e(coordinates($branch['latitude'], $branch['longitude'])) ?>"><?= icon('map-pin', 'success-text', 15) ?></span>
                                <?php else: ?>
                                    <span class="muted tiny">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="center"><span class="<?= e(badge((string) $branch['status'])) ?>"><?= e(enum_label((string) $branch['status'])) ?></span></td>
                            <td class="nowrap">
                                <a class="btn btn-link btn-sm" href="<?= e(url('/admin/branches/' . (int) $branch['id'] . '/edit')) ?>"><?= icon('edit', '', 14) ?></a>
                                <form method="post" action="<?= e(url('/admin/branches/' . (int) $branch['id'] . '/toggle')) ?>" style="display:inline"
                                      data-confirm="<?= (string) $branch['status'] === 'active' ? 'Deactivate this branch? It will be excluded from allocation and new imports.' : 'Reactivate this branch?' ?>">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-link btn-sm" title="Toggle status">
                                        <?= icon((string) $branch['status'] === 'active' ? 'lock' : 'unlock', '', 14) ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
