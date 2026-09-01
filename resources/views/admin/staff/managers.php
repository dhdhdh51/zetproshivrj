<div class="page-head">
    <div class="grow">
        <h1>Branch managers</h1>
        <div class="subtitle">Each manager sees only their own branch. <?= count($managers) ?> account(s).</div>
    </div>
    <div class="page-actions">
        <a class="btn" href="<?= e(url('/admin/managers/create')) ?>"><?= icon('plus', '', 15) ?> Add branch manager</a>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <form method="get" action="<?= e(url('/admin/managers')) ?>" class="filters" style="flex:1 1 auto">
            <div class="field wide">
                <label for="search">Search</label>
                <input type="search" id="search" name="search" value="<?= e($search) ?>" placeholder="Name, email, code">
            </div>
            <div class="field">
                <label for="branch_id">Branch</label>
                <select id="branch_id" name="branch_id" data-auto-submit>
                    <option value="">All branches</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= (int) $branch['id'] ?>" <?= $branchId === (int) $branch['id'] ? 'selected' : '' ?>>
                            <?= e($branch['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="actions"><button type="submit" class="btn btn-secondary"><?= icon('search', '', 15) ?> Filter</button></div>
        </form>
    </div>

    <?php if ($managers === []): ?>
        <?= view_partial('partials.empty', [
            'message' => 'No branch managers yet',
            'hint' => 'Add a manager so a branch can monitor its own recovery work.',
            'iconName' => 'user',
            'actionUrl' => '/admin/managers/create',
            'actionLabel' => 'Add branch manager',
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th>Name</th><th>Branch</th><th>Contact</th><th>Sign-in</th>
                        <th>Last login</th><th class="center">Status</th><th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($managers as $manager): ?>
                        <tr>
                            <td>
                                <strong><?= e($manager['name']) ?></strong>
                                <div class="tiny muted"><?= e($manager['designation'] ?: 'Branch Manager') ?></div>
                            </td>
                            <td>
                                <?= e($manager['branch_name'] ?: '— not linked —') ?>
                                <?php if (!empty($manager['branch_code'])): ?>
                                    <div class="tiny muted"><?= e($manager['branch_code']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="small">
                                <?= e($manager['email']) ?>
                                <?php if (!empty($manager['mobile'])): ?>
                                    <div class="tiny muted"><?= e($manager['mobile']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="small mono"><?= e($manager['username'] ?: $manager['employee_code'] ?: '—') ?></td>
                            <td class="small"><?= e(time_ago($manager['last_login_at'])) ?></td>
                            <td class="center">
                                <span class="<?= e(badge((string) $manager['status'])) ?>"><?= e(enum_label((string) $manager['status'])) ?></span>
                                <?php if (!empty($manager['locked_until']) && strtotime((string) $manager['locked_until']) > time()): ?>
                                    <div class="tiny danger-text">locked</div>
                                <?php endif; ?>
                            </td>
                            <td class="nowrap">
                                <a class="btn btn-link btn-sm" href="<?= e(url('/admin/managers/' . (int) $manager['id'] . '/edit')) ?>" title="Edit"><?= icon('edit', '', 14) ?></a>
                                <form method="post" action="<?= e(url('/admin/users/' . (int) $manager['id'] . '/reset-password')) ?>" style="display:inline"
                                      data-confirm="Issue a new temporary password for <?= e($manager['name']) ?>?">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-link btn-sm" type="submit" title="Reset password"><?= icon('refresh', '', 14) ?></button>
                                </form>
                                <?php if (!empty($manager['locked_until'])): ?>
                                    <form method="post" action="<?= e(url('/admin/users/' . (int) $manager['id'] . '/unlock')) ?>" style="display:inline">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-link btn-sm" type="submit" title="Unlock"><?= icon('unlock', '', 14) ?></button>
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
