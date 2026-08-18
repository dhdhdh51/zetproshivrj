<div class="page-head">
    <div class="grow">
        <h1>BC supervisors</h1>
        <div class="subtitle">
            Field officers who perform customer recovery visits in the Android app.
            <?= count($supervisors) ?> account(s).
        </div>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="<?= e(url('/admin/inspections')) ?>"><?= icon('search-check', '', 15) ?> Inspect field work</a>
        <a class="btn" href="<?= e(url('/admin/supervisors/create')) ?>"><?= icon('plus', '', 15) ?> Add BC supervisor</a>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <form method="get" action="<?= e(url('/admin/supervisors')) ?>" class="filters" style="flex:1 1 auto">
            <div class="field wide">
                <label for="search">Search</label>
                <input type="search" id="search" name="search" value="<?= e($search) ?>" placeholder="Name, BC code, mobile">
            </div>
            <div class="field">
                <label for="branch_id">Branch</label>
                <select id="branch_id" name="branch_id" data-auto-submit>
                    <option value="">All branches</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= (int) $branch['id'] ?>" <?= $branchId === (int) $branch['id'] ? 'selected' : '' ?>><?= e($branch['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="status">Status</label>
                <select id="status" name="status" data-auto-submit>
                    <option value="">All</option>
                    <?php foreach (['active' => 'Active', 'inactive' => 'Inactive', 'suspended' => 'Suspended'] as $key => $label): ?>
                        <option value="<?= $key ?>" <?= $status === $key ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="actions"><button type="submit" class="btn btn-secondary"><?= icon('search', '', 15) ?> Filter</button></div>
        </form>
    </div>

    <?php if ($supervisors === []): ?>
        <?= view_partial('partials.empty', [
            'message' => 'No BC supervisors yet',
            'hint' => 'Add supervisors with the BC codes used in your Excel sheets so accounts allocate automatically.',
            'iconName' => 'users',
            'actionUrl' => '/admin/supervisors/create',
            'actionLabel' => 'Add BC supervisor',
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th>BC Supervisor</th><th>Branch</th><th class="right">Accounts</th>
                        <th class="center">Today</th><th class="right">Recovery (MTD)</th>
                        <th>Device</th><th class="center">Status</th><th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($supervisors as $s): ?>
                        <?php
                        $online = $s['last_seen_at'] !== null
                            && strtotime((string) $s['last_seen_at']) > time() - (setting('supervisor_offline_minutes', 15) * 60);
                        ?>
                        <tr>
                            <td>
                                <strong><?= e($s['name']) ?></strong>
                                <div class="tiny muted">
                                    <?= e($s['bc_code']) ?> · <?= e($s['mobile'] ?: '—') ?>
                                    <?php if (!empty($s['username'])): ?> · app login <span class="mono"><?= e($s['username']) ?></span><?php endif; ?>
                                </div>
                            </td>
                            <td class="small"><?= e($s['branch_name']) ?><div class="tiny muted"><?= e($s['branch_code']) ?></div></td>
                            <td class="right num"><?= number_format((int) $s['accounts']) ?></td>
                            <td class="center num"><?= (int) $s['visits_today'] ?></td>
                            <td class="right num"><?= e(money((float) $s['recovery_month'])) ?></td>
                            <td class="small">
                                <?php if ($s['device_id'] !== null): ?>
                                    <span class="dot <?= $online ? 'online' : 'offline' ?>"></span>
                                    <?= e($s['model'] ?: 'device') ?>
                                    <div class="tiny muted">
                                        <?= e($s['app_version'] ? 'v' . $s['app_version'] : '') ?>
                                        · seen <?= e(time_ago($s['last_seen_at'])) ?>
                                        <?php if ((string) $s['device_status'] !== 'active'): ?>
                                            · <span class="danger-text"><?= e(enum_label((string) $s['device_status'])) ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="muted tiny">no device bound</span>
                                <?php endif; ?>
                            </td>
                            <td class="center"><span class="<?= e(badge((string) $s['status'])) ?>"><?= e(enum_label((string) $s['status'])) ?></span></td>
                            <td class="nowrap">
                                <a class="btn btn-link btn-sm" href="<?= e(url('/admin/inspections/supervisor/' . (int) $s['id'])) ?>" title="View field work"><?= icon('eye', '', 14) ?></a>
                                <a class="btn btn-link btn-sm" href="<?= e(url('/admin/supervisors/' . (int) $s['id'] . '/edit')) ?>" title="Edit"><?= icon('edit', '', 14) ?></a>
                                <form method="post" action="<?= e(url('/admin/users/' . (int) $s['user_id'] . '/reset-password')) ?>" style="display:inline"
                                      data-confirm="Issue a new temporary password for <?= e($s['name']) ?>? Their app will be signed out.">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-link btn-sm" type="submit" title="Reset password"><?= icon('refresh', '', 14) ?></button>
                                </form>
                                <?php if ($s['device_id'] !== null && (string) $s['device_status'] === 'active'): ?>
                                    <form method="post" action="<?= e(url('/admin/devices/' . (int) $s['device_id'] . '/reset')) ?>" style="display:inline"
                                          data-confirm="Release the device binding so this supervisor can sign in on a new handset?">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-link btn-sm" type="submit" title="Reset device binding"><?= icon('smartphone', '', 14) ?></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
