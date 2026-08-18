<?php
/** @var array|null $supervisor */
$editing = $supervisor !== null;
$action = $editing ? '/admin/supervisors/' . (int) $supervisor['id'] : '/admin/supervisors';
$devices = $devices ?? [];

$value = static function (string $key, mixed $default = '') use ($supervisor) {
    $old = old($key, null);

    return $old !== null ? $old : ($supervisor[$key] ?? $default);
};
?>

<div class="page-head">
    <div class="grow">
        <h1><?= $editing ? 'Edit BC supervisor' : 'Add BC supervisor' ?></h1>
        <div class="subtitle">
            The BC code must match the code in your Excel sheets — accounts carrying it are
            allocated to this supervisor automatically.
        </div>
    </div>
    <div class="page-actions"><a class="btn btn-secondary" href="<?= e(url('/admin/supervisors')) ?>">Back</a></div>
</div>

<div class="card content-narrow">
    <form method="post" action="<?= e(url($action)) ?>">
        <?= csrf_field() ?>
        <div class="card-body">
            <div class="form-grid">
                <div class="field <?= has_error('name') ? 'has-error' : '' ?>">
                    <label for="name">Full name <span class="req">*</span></label>
                    <input type="text" id="name" name="name" value="<?= e($value('name')) ?>" required maxlength="160">
                    <?php if (has_error('name')): ?><div class="error-text"><?= e(error_for('name')) ?></div><?php endif; ?>
                </div>

                <div class="field <?= has_error('bc_code') ? 'has-error' : '' ?>">
                    <label for="bc_code">BC code <span class="req">*</span></label>
                    <input type="text" id="bc_code" name="bc_code" value="<?= e($value('bc_code')) ?>" required maxlength="60">
                    <?php if (has_error('bc_code')): ?><div class="error-text"><?= e(error_for('bc_code')) ?></div><?php endif; ?>
                </div>

                <div class="field <?= has_error('branch_id') ? 'has-error' : '' ?>">
                    <label for="branch_id">Branch <span class="req">*</span></label>
                    <select id="branch_id" name="branch_id" required>
                        <option value="">Select branch…</option>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?= (int) $branch['id'] ?>" <?= (int) $value('branch_id') === (int) $branch['id'] ? 'selected' : '' ?>>
                                <?= e($branch['name']) ?> (<?= e($branch['code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="help">A supervisor may only ever be allocated accounts from their own branch.</div>
                    <?php if (has_error('branch_id')): ?><div class="error-text"><?= e(error_for('branch_id')) ?></div><?php endif; ?>
                </div>

                <div class="field <?= has_error('mobile') ? 'has-error' : '' ?>">
                    <label for="mobile">Mobile <span class="req">*</span></label>
                    <input type="tel" id="mobile" name="mobile" value="<?= e($value('mobile')) ?>" required maxlength="20">
                    <div class="help">Used for OTP delivery when two-step sign-in is enabled.</div>
                    <?php if (has_error('mobile')): ?><div class="error-text"><?= e(error_for('mobile')) ?></div><?php endif; ?>
                </div>

                <div class="field <?= has_error('username') ? 'has-error' : '' ?>">
                    <label for="username">App username <span class="req">*</span></label>
                    <input type="text" id="username" name="username" value="<?= e($value('username')) ?>" required maxlength="80">
                    <?php if (has_error('username')): ?><div class="error-text"><?= e(error_for('username')) ?></div><?php endif; ?>
                </div>

                <div class="field">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="<?= e($value('email')) ?>" maxlength="190">
                </div>

                <div class="field">
                    <label for="employee_code">Employee code</label>
                    <input type="text" id="employee_code" name="employee_code" value="<?= e($value('employee_code')) ?>" maxlength="60">
                </div>

                <div class="field">
                    <label for="village">Base village</label>
                    <input type="text" id="village" name="village" value="<?= e($value('village')) ?>" maxlength="120">
                </div>

                <div class="field">
                    <label for="joined_on">Joined on</label>
                    <input type="date" id="joined_on" name="joined_on" value="<?= e($value('joined_on')) ?>">
                </div>

                <div class="field span-2">
                    <label for="address">Address</label>
                    <input type="text" id="address" name="address" value="<?= e($value('address')) ?>" maxlength="255">
                </div>

                <?php if ($editing): ?>
                    <div class="field">
                        <label for="status">Status</label>
                        <select id="status" name="status">
                            <?php foreach (['active' => 'Active', 'inactive' => 'Inactive', 'suspended' => 'Suspended'] as $key => $label): ?>
                                <option value="<?= $key ?>" <?= (string) $value('status') === $key ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="help">Inactive supervisors are excluded from automatic allocation and app sign-in.</div>
                    </div>
                <?php else: ?>
                    <div class="field <?= has_error('password') ? 'has-error' : '' ?>">
                        <label for="password">App password</label>
                        <input type="password" id="password" name="password" autocomplete="new-password">
                        <div class="help">Leave blank to generate one automatically.</div>
                        <?php if (has_error('password')): ?><div class="error-text"><?= e(error_for('password')) ?></div><?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-foot">
            <button type="submit" class="btn"><?= icon('check', '', 15) ?> <?= $editing ? 'Save changes' : 'Create account' ?></button>
            <a class="btn btn-secondary" href="<?= e(url('/admin/supervisors')) ?>">Cancel</a>
        </div>
    </form>
</div>

<?php if ($editing && $devices !== []): ?>
    <div class="card content-narrow">
        <div class="card-head"><h2>Bound devices</h2></div>
        <div class="table-wrap">
            <table class="data compact">
                <thead>
                    <tr><th>Device</th><th>App</th><th>Last seen</th><th>Last known location</th><th class="center">Status</th><th></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($devices as $device): ?>
                        <tr>
                            <td>
                                <?= e($device['model'] ?: 'Unknown model') ?>
                                <div class="tiny muted mono"><?= e(str_excerpt((string) $device['device_uuid'], 24)) ?></div>
                            </td>
                            <td class="small"><?= e($device['app_version'] ?: '—') ?><div class="tiny muted"><?= e($device['os_version'] ?: '') ?></div></td>
                            <td class="small"><?= e(time_ago($device['last_seen_at'])) ?></td>
                            <td class="small">
                                <?php $map = map_link($device['last_latitude'], $device['last_longitude']); ?>
                                <?php if ($map !== null): ?>
                                    <a href="<?= e($map) ?>" target="_blank" rel="noopener"><?= e(coordinates($device['last_latitude'], $device['last_longitude'])) ?></a>
                                    <div class="tiny muted"><?= e(time_ago($device['last_location_at'])) ?></div>
                                <?php else: ?>
                                    <span class="muted tiny">not recorded</span>
                                <?php endif; ?>
                            </td>
                            <td class="center"><span class="<?= e(badge((string) $device['status'])) ?>"><?= e(enum_label((string) $device['status'])) ?></span></td>
                            <td class="nowrap">
                                <?php if ((string) $device['status'] === 'active'): ?>
                                    <form method="post" action="<?= e(url('/admin/devices/' . (int) $device['id'] . '/reset')) ?>" style="display:inline"
                                          data-confirm="Release this device binding?">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-link btn-sm" type="submit">Release</button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" action="<?= e(url('/admin/devices/' . (int) $device['id'] . '/block')) ?>" style="display:inline"
                                      data-confirm="<?= (string) $device['status'] === 'blocked' ? 'Unblock this device?' : 'Block this device? Its tokens are revoked immediately.' ?>">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-link btn-sm" type="submit">
                                        <?= (string) $device['status'] === 'blocked' ? 'Unblock' : 'Block' ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
