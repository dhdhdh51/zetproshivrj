<?php
/** @var array|null $manager */
$editing = $manager !== null;
$action = $editing ? '/admin/managers/' . (int) $manager['id'] : '/admin/managers';

$value = static function (string $key, mixed $default = '') use ($manager) {
    $old = old($key, null);

    return $old !== null ? $old : ($manager[$key] ?? $default);
};
?>

<div class="page-head">
    <div class="grow">
        <h1><?= $editing ? 'Edit branch manager' : 'Add branch manager' ?></h1>
        <div class="subtitle">Branch Managers get read and report access to one branch only.</div>
    </div>
    <div class="page-actions"><a class="btn btn-secondary" href="<?= e(url('/admin/managers')) ?>">Back</a></div>
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
                    <?php if (has_error('branch_id')): ?><div class="error-text"><?= e(error_for('branch_id')) ?></div><?php endif; ?>
                </div>

                <div class="field <?= has_error('email') ? 'has-error' : '' ?>">
                    <label for="email">Email <span class="req">*</span></label>
                    <input type="email" id="email" name="email" value="<?= e($value('email')) ?>" required maxlength="190">
                    <?php if (has_error('email')): ?><div class="error-text"><?= e(error_for('email')) ?></div><?php endif; ?>
                </div>

                <div class="field">
                    <label for="mobile">Mobile</label>
                    <input type="tel" id="mobile" name="mobile" value="<?= e($value('mobile')) ?>" maxlength="20">
                </div>

                <div class="field <?= has_error('username') ? 'has-error' : '' ?>">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" value="<?= e($value('username')) ?>" maxlength="80">
                    <div class="help">Optional; they can also sign in with their email.</div>
                    <?php if (has_error('username')): ?><div class="error-text"><?= e(error_for('username')) ?></div><?php endif; ?>
                </div>

                <div class="field">
                    <label for="employee_code">Employee code</label>
                    <input type="text" id="employee_code" name="employee_code" value="<?= e($value('employee_code')) ?>" maxlength="60">
                </div>

                <div class="field">
                    <label for="designation">Designation</label>
                    <input type="text" id="designation" name="designation" value="<?= e($value('designation', 'Branch Manager')) ?>" maxlength="120">
                </div>

                <?php if ($editing): ?>
                    <div class="field">
                        <label for="status">Status</label>
                        <select id="status" name="status">
                            <?php foreach (['active' => 'Active', 'inactive' => 'Inactive', 'suspended' => 'Suspended'] as $key => $label): ?>
                                <option value="<?= $key ?>" <?= (string) $value('status') === $key ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="help">Suspending an account revokes its sessions immediately.</div>
                    </div>
                <?php else: ?>
                    <div class="field <?= has_error('password') ? 'has-error' : '' ?>">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" autocomplete="new-password">
                        <div class="help">Leave blank to generate a temporary password automatically.</div>
                        <?php if (has_error('password')): ?><div class="error-text"><?= e(error_for('password')) ?></div><?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-foot">
            <button type="submit" class="btn"><?= icon('check', '', 15) ?> <?= $editing ? 'Save changes' : 'Create account' ?></button>
            <a class="btn btn-secondary" href="<?= e(url('/admin/managers')) ?>">Cancel</a>
        </div>
    </form>
</div>
