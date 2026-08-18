<?php
/** @var array|null $branch */
$editing = $branch !== null;
$action = $editing ? '/admin/branches/' . (int) $branch['id'] : '/admin/branches';

$value = static function (string $key, mixed $default = '') use ($branch) {
    $old = old($key, null);

    if ($old !== null) {
        return $old;
    }

    return $branch[$key] ?? $default;
};
?>

<div class="page-head">
    <div class="grow">
        <h1><?= $editing ? 'Edit branch' : 'Add branch' ?></h1>
        <div class="subtitle">
            The branch code must match the code used in your loan Excel sheets so imports resolve automatically.
        </div>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="<?= e(url('/admin/branches')) ?>">Back to list</a>
    </div>
</div>

<div class="card content-narrow">
    <form method="post" action="<?= e(url($action)) ?>">
        <?= csrf_field() ?>

        <div class="card-body">
            <div class="form-grid">
                <div class="field <?= has_error('code') ? 'has-error' : '' ?>">
                    <label for="code">Branch code <span class="req">*</span></label>
                    <input type="text" id="code" name="code" value="<?= e($value('code')) ?>" required maxlength="40">
                    <div class="help">As printed in the Excel sheet, e.g. BR001 or the SOL ID.</div>
                    <?php if (has_error('code')): ?><div class="error-text"><?= e(error_for('code')) ?></div><?php endif; ?>
                </div>

                <div class="field <?= has_error('name') ? 'has-error' : '' ?>">
                    <label for="name">Branch name <span class="req">*</span></label>
                    <input type="text" id="name" name="name" value="<?= e($value('name')) ?>" required maxlength="160">
                    <?php if (has_error('name')): ?><div class="error-text"><?= e(error_for('name')) ?></div><?php endif; ?>
                </div>

                <div class="field">
                    <label for="region">Regional office</label>
                    <input type="text" id="region" name="region" value="<?= e($value('region')) ?>" maxlength="120">
                    <div class="help">Printed as "Regional Office" on the field visit verification report.</div>
                </div>

                <div class="field">
                    <label for="zone">Zone</label>
                    <input type="text" id="zone" name="zone" value="<?= e($value('zone')) ?>" maxlength="120">
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
                    <label for="pincode">PIN code</label>
                    <input type="text" id="pincode" name="pincode" value="<?= e($value('pincode')) ?>" maxlength="12">
                </div>

                <div class="field">
                    <label for="phone">Phone</label>
                    <input type="tel" id="phone" name="phone" value="<?= e($value('phone')) ?>" maxlength="20">
                </div>

                <div class="field">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="<?= e($value('email')) ?>" maxlength="160">
                </div>

                <div class="field span-2">
                    <label for="address">Address</label>
                    <input type="text" id="address" name="address" value="<?= e($value('address')) ?>" maxlength="255">
                </div>
            </div>

            <fieldset>
                <legend>Branch location (optional)</legend>
                <p class="help" style="margin-top:0">
                    Used by the optional GPS drift check: field points further than the configured
                    distance from this centroid are flagged for review.
                </p>
                <div class="form-grid">
                    <div class="field">
                        <label for="latitude">Latitude</label>
                        <input type="text" id="latitude" name="latitude" value="<?= e($value('latitude')) ?>" placeholder="25.5389">
                    </div>
                    <div class="field">
                        <label for="longitude">Longitude</label>
                        <input type="text" id="longitude" name="longitude" value="<?= e($value('longitude')) ?>" placeholder="87.5719">
                    </div>
                </div>
            </fieldset>

            <div class="field" style="max-width:220px">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <?php $current = (string) $value('status', 'active'); ?>
                    <option value="active" <?= $current === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $current === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
        </div>

        <div class="card-foot">
            <button type="submit" class="btn"><?= icon('check', '', 15) ?> <?= $editing ? 'Save changes' : 'Create branch' ?></button>
            <a class="btn btn-secondary" href="<?= e(url('/admin/branches')) ?>">Cancel</a>
        </div>
    </form>
</div>
