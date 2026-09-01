<?php
/** @var array|null $supervisor */
$editing = $supervisor !== null;
$action = $editing ? '/admin/supervisors/' . (int) $supervisor['id'] : '/admin/supervisors';
$devices = $devices ?? [];

$value = static function (string $key, mixed $default = '') use ($supervisor) {
    $old = old($key, null);

    return $old !== null ? $old : ($supervisor[$key] ?? $default);
};

// Only the last four Aadhaar digits are stored, so the field shows them masked.
// Re-submitting the masked value is safe: the non-digits are stripped and the
// last four survive unchanged.
$aadhaarValue = old('aadhaar_number', null)
    ?? (($supervisor['aadhaar_last4'] ?? '') !== '' ? 'XXXX-XXXX-' . $supervisor['aadhaar_last4'] : '');
?>

<div class="page-head">
    <div class="grow">
        <h1><?= $editing ? 'Edit BCA' : 'Add BCA' ?></h1>
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
            <h2 class="form-section">BC basic details</h2>
            <div class="form-grid">
                <div class="field">
                    <label for="sp_cbc_name">SP / CBC name</label>
                    <input type="text" id="sp_cbc_name" name="sp_cbc_name" value="<?= e($value('sp_cbc_name')) ?>" maxlength="190">
                    <div class="help">The service provider or corporate BC this agent works under.</div>
                </div>

                <div class="field <?= has_error('name') ? 'has-error' : '' ?>">
                    <label for="name">BC name <span class="req">*</span></label>
                    <input type="text" id="name" name="name" value="<?= e($value('name')) ?>" required maxlength="160">
                    <?php if (has_error('name')): ?><div class="error-text"><?= e(error_for('name')) ?></div><?php endif; ?>
                </div>

                <div class="field <?= has_error('bc_code') ? 'has-error' : '' ?>">
                    <label for="bc_code">BCBF code <span class="req">*</span></label>
                    <input type="text" id="bc_code" name="bc_code" value="<?= e($value('bc_code')) ?>" required maxlength="60">
                    <div class="help">
                        Must match the code in your Excel sheets — accounts carrying it are allocated
                        here automatically. This is also what the BCA signs in to the app with.
                    </div>
                    <?php if (has_error('bc_code')): ?><div class="error-text"><?= e(error_for('bc_code')) ?></div><?php endif; ?>
                </div>

                <div class="field">
                    <label for="ssa">SSA</label>
                    <input type="text" id="ssa" name="ssa" value="<?= e($value('ssa')) ?>" maxlength="160">
                </div>

                <div class="field <?= has_error('branch_id') ? 'has-error' : '' ?>">
                    <label for="branch_id">Link branch <span class="req">*</span></label>
                    <select id="branch_id" name="branch_id" required>
                        <option value="">Select branch&hellip;</option>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?= (int) $branch['id'] ?>" <?= (int) $value('branch_id') === (int) $branch['id'] ? 'selected' : '' ?>>
                                <?= e($branch['name']) ?> (<?= e($branch['code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="help">
                        Region (RO), zone and the branch district are taken from the branch record and printed on the
                        verification report. A supervisor may only ever be allocated accounts from their own branch.
                    </div>
                    <?php if (has_error('branch_id')): ?><div class="error-text"><?= e(error_for('branch_id')) ?></div><?php endif; ?>
                </div>

                <div class="field">
                    <label for="iibf_number">IIBF number</label>
                    <input type="text" id="iibf_number" name="iibf_number" value="<?= e($value('iibf_number')) ?>" maxlength="60">
                </div>

                <div class="field <?= has_error('mobile') ? 'has-error' : '' ?>">
                    <label for="mobile">Mobile number <span class="req">*</span></label>
                    <input type="tel" id="mobile" name="mobile" value="<?= e($value('mobile')) ?>" required maxlength="20">
                    <div class="help">
                        The BCA can sign in to the app with this number as well as with their BCBF
                        code, so it has to be theirs and not shared with another BCA. Also used for
                        OTP delivery when two-step sign-in is enabled.
                    </div>
                    <?php if (has_error('mobile')): ?><div class="error-text"><?= e(error_for('mobile')) ?></div><?php endif; ?>
                </div>

                <div class="field">
                    <label for="aadhaar_number">Aadhaar card number</label>
                    <input type="text" id="aadhaar_number" name="aadhaar_number" value="<?= e($aadhaarValue) ?>" maxlength="20" inputmode="numeric">
                    <div class="help">Only the last four digits are stored — that is all the report prints.</div>
                </div>

                <div class="field <?= has_error('pan_number') ? 'has-error' : '' ?>">
                    <label for="pan_number">PAN card number</label>
                    <input type="text" id="pan_number" name="pan_number" value="<?= e($value('pan_number')) ?>" maxlength="12" style="text-transform:uppercase">
                    <div class="help">Ten characters, in the form ABCDE1234F.</div>
                    <?php if (has_error('pan_number')): ?><div class="error-text"><?= e(error_for('pan_number')) ?></div><?php endif; ?>
                </div>

                <div class="field">
                    <label for="dra_id">DRA name / ID</label>
                    <input type="text" id="dra_id" name="dra_id" value="<?= e($value('dra_id')) ?>" maxlength="60">
                </div>

                <div class="field">
                    <label for="designation">Designation</label>
                    <input type="text" id="designation" name="designation" value="<?= e($value('designation')) ?>" maxlength="120">
                </div>

                <div class="field">
                    <label for="joined_on">Joined on</label>
                    <input type="date" id="joined_on" name="joined_on" value="<?= e($value('joined_on')) ?>">
                </div>
            </div>

            <h2 class="form-section">Address details</h2>
            <div class="form-grid">
                <div class="field span-2">
                    <label for="address">Address</label>
                    <input type="text" id="address" name="address" value="<?= e($value('address')) ?>" maxlength="255">
                </div>

                <div class="field">
                    <label for="village">Village</label>
                    <input type="text" id="village" name="village" value="<?= e($value('village')) ?>" maxlength="120">
                </div>

                <div class="field">
                    <label for="block">Block</label>
                    <input type="text" id="block" name="block" value="<?= e($value('block')) ?>" maxlength="120">
                </div>

                <div class="field">
                    <label for="tehsil">Tehsil</label>
                    <input type="text" id="tehsil" name="tehsil" value="<?= e($value('tehsil')) ?>" maxlength="120">
                </div>

                <div class="field">
                    <label for="district">District</label>
                    <input type="text" id="district" name="district" value="<?= e($value('district')) ?>" maxlength="120">
                </div>

                <div class="field">
                    <label for="state">State</label>
                    <input type="text" id="state" name="state" value="<?= e($value('state')) ?>" maxlength="120">
                </div>

                <div class="field">
                    <label for="pincode">Pincode</label>
                    <input type="text" id="pincode" name="pincode" value="<?= e($value('pincode')) ?>" maxlength="12" inputmode="numeric">
                </div>
            </div>

            <h2 class="form-section">Additional details and app access</h2>
            <div class="form-grid">
                <div class="field">
                    <label for="email">Email ID</label>
                    <input type="email" id="email" name="email" value="<?= e($value('email')) ?>" maxlength="190">
                </div>

<?php /*
 * There was an "App username" and an "Employee code" here.
 *
 * Both are gone. A BCA signs in with the BCBF code already entered above, or with their
 * mobile number — a third identifier invented on this screen was one more thing to make up,
 * write down, and read back to somebody over a bad line. The code is on their paperwork and
 * they know their own number.
 */ ?>
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
