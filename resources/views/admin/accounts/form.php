<?php
/**
 * Manual loan account entry.
 *
 * Inputs are generated from SystemFields — the same definition the Excel importer
 * uses — so a column added there appears here without a second edit, and the
 * maxlength on every box matches the width of the column behind it.
 *
 * @var array<string, array{label: string, required: bool, type: string, help?: string}> $fields
 * @var array<int, array<string, mixed>> $branches
 * @var array<int, array<string, mixed>> $supervisors
 */

use App\Services\Excel\SystemFields;

// Branch and BC code are resolved from dropdowns on this screen, so their
// free-text importer counterparts are not rendered.
$handled = ['account_number', 'borrower_name', 'branch_name', 'branch_code', 'bc_code'];

$groups = [
    'Borrower' => ['cif', 'father_name', 'gender', 'date_of_birth', 'aadhaar_last4', 'pan_number'],
    'Contact' => ['mobile', 'alternate_mobile'],
    'Address' => ['village', 'gram_panchayat', 'tehsil', 'district', 'state', 'pincode', 'address'],
    'Loan' => ['loan_type', 'sanction_date', 'npa_date', 'asset_classification'],
    'Amounts' => ['limit_amount', 'drawing_power', 'outstanding', 'interest_overdue', 'overdue'],
];

/** Renders one input from the shared field definition. */
$input = static function (string $key) use ($fields): void {
    if (!isset($fields[$key])) {
        return;
    }

    $field = $fields[$key];
    $options = SystemFields::options($key);
    $maxlength = SystemFields::maxlength($key);
    $value = (string) old($key, '');
    ?>
    <div class="field <?= has_error($key) ? 'has-error' : '' ?>">
        <label for="<?= e($key) ?>"><?= e($field['label']) ?></label>

        <?php if ($options !== []): ?>
            <select id="<?= e($key) ?>" name="<?= e($key) ?>">
                <option value="">— not known —</option>
                <?php foreach ($options as $stored => $label): ?>
                    <option value="<?= e($stored) ?>" <?= $value === $stored ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php elseif ($field['type'] === 'date'): ?>
            <input type="date" id="<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e($value) ?>">
        <?php elseif ($field['type'] === 'amount'): ?>
            <input type="number" step="0.01" min="0" inputmode="decimal"
                   id="<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e($value) ?>">
        <?php elseif ($key === 'address'): ?>
            <textarea id="<?= e($key) ?>" name="<?= e($key) ?>" rows="2"
                      maxlength="<?= (int) $maxlength ?>"><?= e($value) ?></textarea>
        <?php else: ?>
            <input type="text" id="<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e($value) ?>"
                   <?= $maxlength !== null ? 'maxlength="' . (int) $maxlength . '"' : '' ?>>
        <?php endif; ?>

        <?php if (!empty($field['help'])): ?>
            <div class="help"><?= e($field['help']) ?></div>
        <?php endif; ?>
        <?php if (has_error($key)): ?>
            <div class="error-text"><?= e(error_for($key)) ?></div>
        <?php endif; ?>
    </div>
    <?php
};
?>

<div class="page-head">
    <div class="grow">
        <h1>Add loan account</h1>
        <div class="subtitle">
            For accounts that are not in the Excel sheet yet — one opened after the extract, or reported by a
            branch. Bulk loading still belongs in <a href="<?= e(url('/admin/imports/create')) ?>">Excel import</a>.
        </div>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="<?= e(url('/admin/accounts')) ?>">Back to loan book</a>
    </div>
</div>

<?php if ($branches === []): ?>
    <div class="alert alert-warning">
        No active branch exists yet, and every account must belong to one.
        <a href="<?= e(url('/admin/branches/create')) ?>">Add a branch first</a>.
    </div>
<?php else: ?>

<div class="card content-narrow">
    <form method="post" action="<?= e(url('/admin/accounts')) ?>">
        <?= csrf_field() ?>

        <div class="card-body">
            <h3 class="section-title">Account</h3>
            <div class="form-grid">
                <div class="field <?= has_error('account_number') ? 'has-error' : '' ?>">
                    <label for="account_number">Account Number <span class="req">*</span></label>
                    <input type="text" id="account_number" name="account_number"
                           value="<?= e(old('account_number', '')) ?>" required autofocus
                           maxlength="<?= (int) SystemFields::textLength('account_number') ?>">
                    <div class="help">Must be unique. Re-importing a sheet later matches on this number.</div>
                    <?php if (has_error('account_number')): ?>
                        <div class="error-text"><?= e(error_for('account_number')) ?></div>
                    <?php endif; ?>
                </div>

                <div class="field <?= has_error('borrower_name') ? 'has-error' : '' ?>">
                    <label for="borrower_name">Borrower Name <span class="req">*</span></label>
                    <input type="text" id="borrower_name" name="borrower_name"
                           value="<?= e(old('borrower_name', '')) ?>" required
                           maxlength="<?= (int) SystemFields::textLength('borrower_name') ?>">
                    <?php if (has_error('borrower_name')): ?>
                        <div class="error-text"><?= e(error_for('borrower_name')) ?></div>
                    <?php endif; ?>
                </div>

                <div class="field <?= has_error('branch_id') ? 'has-error' : '' ?>">
                    <label for="branch_id">Branch <span class="req">*</span></label>
                    <select id="branch_id" name="branch_id" required>
                        <option value="">— select a branch —</option>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?= (int) $branch['id'] ?>"
                                <?= (string) old('branch_id', '') === (string) $branch['id'] ? 'selected' : '' ?>>
                                <?= e($branch['name']) ?> (<?= e($branch['code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (has_error('branch_id')): ?>
                        <div class="error-text"><?= e(error_for('branch_id')) ?></div>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label for="loan_category">Work stream <span class="req">*</span></label>
                    <select id="loan_category" name="loan_category" required>
                        <?php foreach ([
                            'general' => 'General recovery',
                            'krm_ots' => 'KRM OTS',
                            'ckcc_od2' => 'CKCC OD-2',
                        ] as $stored => $label): ?>
                            <option value="<?= e($stored) ?>"
                                <?= (string) old('loan_category', 'general') === $stored ? 'selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="help">Decides which verification report the supervisor fills in the app.</div>
                </div>
            </div>

            <?php foreach ($groups as $heading => $keys): ?>
                <h3 class="section-title"><?= e($heading) ?></h3>
                <div class="form-grid">
                    <?php foreach ($keys as $key): ?>
                        <?php $input($key); ?>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>

            <h3 class="section-title">Allocation</h3>
            <div class="form-grid">
                <div class="field">
                    <label for="allocation_mode">Allocate to</label>
                    <select id="allocation_mode" name="allocation_mode">
                        <option value="none" <?= old('allocation_mode', 'auto') === 'none' ? 'selected' : '' ?>>
                            Nobody for now
                        </option>
                        <option value="auto" <?= old('allocation_mode', 'auto') === 'auto' ? 'selected' : '' ?>>
                            The least-loaded supervisor in that branch
                        </option>
                        <option value="supervisor" <?= old('allocation_mode', 'auto') === 'supervisor' ? 'selected' : '' ?>>
                            A specific BC Supervisor
                        </option>
                    </select>
                    <div class="help">The supervisor is notified as soon as the account is allocated.</div>
                </div>

                <div class="field">
                    <label for="bc_supervisor_id">BC Supervisor</label>
                    <select id="bc_supervisor_id" name="bc_supervisor_id">
                        <option value="">— choose —</option>
                        <?php foreach ($supervisors as $supervisor): ?>
                            <option value="<?= (int) $supervisor['id'] ?>"
                                <?= (string) old('bc_supervisor_id', '') === (string) $supervisor['id'] ? 'selected' : '' ?>>
                                <?= e($supervisor['name']) ?> (<?= e($supervisor['bc_code']) ?>)
                                — <?= e($supervisor['branch_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="help">Only used when "A specific BC Supervisor" is selected above.</div>
                </div>
            </div>
        </div>

        <div class="card-foot">
            <button type="submit" class="btn"><?= icon('check', '', 16) ?> Add account</button>
            <a class="btn btn-secondary" href="<?= e(url('/admin/accounts')) ?>">Cancel</a>
        </div>
    </form>
</div>

<?php endif; ?>
