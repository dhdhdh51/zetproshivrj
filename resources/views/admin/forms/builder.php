<?php
/**
 * @var string     $kind
 * @var array      $forms
 * @var array|null $form
 * @var array      $fields
 * @var array      $fieldTypes
 * @var array      $usageCounts
 * @var array|null $editField
 */
$isInspection = $kind === App\Services\Forms::KIND_INSPECTION;
$base = '/admin/forms/' . $kind;
$formId = $form === null ? 0 : (int) $form['id'];

$fieldValue = static function (string $key, mixed $default = '') use ($editField) {
    $old = old($key, null);

    return $old !== null ? $old : ($editField[$key] ?? $default);
};
?>

<div class="page-head">
    <div class="grow">
        <h1><?= $isInspection ? 'Inspection form builder' : 'Visit form builder' ?></h1>
        <div class="subtitle">
            <?php if ($isInspection): ?>
                Questions the BC Supervisor answers when verifying BCA field work.
                Change them any time — the application does not need to be modified.
            <?php else: ?>
                The customer visit form BCAs fill in the Android app. Fields sync to devices
                automatically, including conditional visibility.
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="grid grid-2">
    <!-- Forms list -->
    <div class="card">
        <div class="card-head"><h2>Forms</h2></div>
        <?php if ($forms === []): ?>
            <?= view_partial('partials.empty', ['message' => 'No forms yet', 'iconName' => 'list']) ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data compact">
                    <thead><tr><th>Name</th><?php if (!$isInspection): ?><th>Type</th><?php endif; ?><th class="center">Fields</th><th class="center">State</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($forms as $row): ?>
                            <tr style="<?= (int) $row['id'] === $formId ? 'background:var(--brand-100)' : '' ?>">
                                <td>
                                    <a href="<?= e(url($base . '?form_id=' . (int) $row['id'])) ?>"><strong><?= e($row['name']) ?></strong></a>
                                    <div class="tiny muted">v<?= (int) $row['version'] ?> · <?= e(str_excerpt((string) $row['description'], 40)) ?></div>
                                </td>
                                <?php if (!$isInspection): ?>
                                    <td class="small"><?= e(strtoupper(str_replace('_', ' ', (string) $row['visit_type']))) ?></td>
                                <?php endif; ?>
                                <td class="center num">
                                    <?= (int) App\Core\Database::scalar(
                                        sprintf('SELECT COUNT(*) FROM `%s` WHERE form_id = :f AND is_active = 1', App\Services\Forms::tables($kind)['fields']),
                                        ['f' => (int) $row['id']]
                                    ) ?>
                                </td>
                                <td class="center">
                                    <?php if ((int) $row['is_default'] === 1): ?><span class="badge badge-success">default</span><?php endif; ?>
                                    <?php if ((int) $row['is_active'] === 0): ?><span class="badge badge-muted">inactive</span><?php endif; ?>
                                </td>
                                <td class="nowrap">
                                    <?php if ((int) $row['is_default'] !== 1): ?>
                                        <form method="post" action="<?= e(url($base . '/' . (int) $row['id'] . '/default')) ?>" style="display:inline"
                                              data-confirm="Use &quot;<?= e($row['name']) ?>&quot; for all new records?">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-link btn-sm">Make default</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="post" action="<?= e(url($base . '/' . (int) $row['id'] . '/duplicate')) ?>" style="display:inline">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-link btn-sm" title="Duplicate"><?= icon('copy', '', 14) ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Create / edit form -->
    <div class="card">
        <div class="card-head"><h2><?= $form === null ? 'Create a form' : 'Form settings' ?></h2></div>
        <form method="post" action="<?= e(url($form === null ? $base : $base . '/' . $formId)) ?>">
            <?= csrf_field() ?>
            <div class="card-body">
                <div class="form-grid">
                    <div class="field">
                        <label for="name">Form name <span class="req">*</span></label>
                        <input type="text" id="name" name="name" required maxlength="160"
                               value="<?= e(old('name', $form['name'] ?? '')) ?>">
                    </div>
                    <?php if (!$isInspection): ?>
                        <div class="field">
                            <label for="visit_type">Visit type</label>
                            <select id="visit_type" name="visit_type">
                                <?php foreach (['customer' => 'Customer recovery visit', 'krm_ots' => 'KRM OTS visit', 'ckcc_od2' => 'CKCC OD-2 visit'] as $key => $label): ?>
                                    <option value="<?= $key ?>" <?= (string) ($form['visit_type'] ?? 'customer') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <div class="field span-2">
                        <label for="description">Description</label>
                        <input type="text" id="description" name="description" maxlength="255"
                               value="<?= e(old('description', $form['description'] ?? '')) ?>">
                    </div>
                </div>
                <div class="check">
                    <input type="checkbox" id="is_active" name="is_active" value="1" <?= (int) ($form['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
                    <label for="is_active">Active</label>
                </div>
                <div class="check">
                    <input type="checkbox" id="is_default" name="is_default" value="1" <?= (int) ($form['is_default'] ?? 0) === 1 ? 'checked' : '' ?>>
                    <label for="is_default">Use for new records (default)</label>
                </div>
            </div>
            <div class="card-foot">
                <button type="submit" class="btn btn-sm"><?= icon('check', '', 14) ?> <?= $form === null ? 'Create form' : 'Save settings' ?></button>
                <?php if ($form !== null): ?>
                    <a class="btn btn-secondary btn-sm" href="<?= e(url($base)) ?>">New form</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php if ($form !== null): ?>
    <div class="grid grid-2">
        <!-- Field list -->
        <div class="card">
            <div class="card-head">
                <h2>Fields in "<?= e($form['name']) ?>"</h2>
                <div class="spacer"></div>
                <span class="small muted"><?= count($fields) ?> field(s)</span>
            </div>
            <div class="card-body">
                <?php if ($fields === []): ?>
                    <p class="small muted" style="margin:0">No fields yet. Add the first one using the panel beside this list.</p>
                <?php else: ?>
                    <form method="post" action="<?= e(url($base . '/' . $formId . '/reorder')) ?>">
                        <?= csrf_field() ?>
                        <?php foreach ($fields as $index => $field): ?>
                            <?php
                            $used = $usageCounts[(int) $field['id']] ?? 0;
                            $isSection = (string) $field['field_type'] === 'section';
                            ?>
                            <div class="form-field-row <?= (int) $field['is_active'] === 0 ? 'inactive' : '' ?> <?= $isSection ? 'section' : '' ?>">
                                <span class="handle"><?= icon('menu', '', 14) ?></span>
                                <input type="hidden" name="order[]" value="<?= (int) $field['id'] ?>">
                                <input type="number" name="_pos[]" value="<?= $index + 1 ?>" min="1" max="99"
                                       style="width:52px;padding:4px 6px" aria-label="Position" disabled>
                                <div class="info">
                                    <div class="name">
                                        <?= e($field['label']) ?>
                                        <?php if ((int) $field['is_required'] === 1): ?><span class="req" style="color:var(--danger)">*</span><?php endif; ?>
                                    </div>
                                    <div class="meta">
                                        <span class="mono"><?= e($field['field_key']) ?></span> ·
                                        <?= e($fieldTypes[(string) $field['field_type']] ?? $field['field_type']) ?>
                                        <?php if ($field['condition_field_id'] !== null): ?>
                                            · conditional
                                        <?php endif; ?>
                                        <?php if ($used > 0): ?>
                                            · <?= $used ?> answer(s) recorded
                                        <?php endif; ?>
                                        <?php if ((int) $field['is_active'] === 0): ?> · inactive<?php endif; ?>
                                    </div>
                                </div>
                                <a class="btn btn-link btn-sm" href="<?= e(url($base . '?form_id=' . $formId . '&field_id=' . (int) $field['id'])) ?>"><?= icon('edit', '', 14) ?></a>
                                <button type="submit" class="btn btn-link btn-sm"
                                        formaction="<?= e(url($base . '/' . $formId . '/fields/' . (int) $field['id'] . '/delete')) ?>"
                                        formnovalidate
                                        onclick="return confirm('<?= $used > 0 ? 'This field has recorded answers, so it will be deactivated rather than deleted. Continue?' : 'Delete this field?' ?>')">
                                    <?= icon('trash', '', 14) ?>
                                </button>
                            </div>
                        <?php endforeach; ?>

                        <p style="margin:12px 0 0">
                            <button type="submit" class="btn btn-secondary btn-sm">Save this order</button>
                            <span class="small muted">Fields are shown in this order on the form.</span>
                        </p>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Add / edit field -->
        <div class="card">
            <div class="card-head">
                <h2><?= $editField === null ? 'Add a field' : 'Edit field' ?></h2>
                <?php if ($editField !== null): ?>
                    <div class="spacer"></div>
                    <a class="btn btn-link btn-sm" href="<?= e(url($base . '?form_id=' . $formId)) ?>">Cancel edit</a>
                <?php endif; ?>
            </div>
            <form method="post" action="<?= e(url($base . '/' . $formId . '/fields')) ?>">
                <?= csrf_field() ?>
                <?php if ($editField !== null): ?>
                    <input type="hidden" name="field_id" value="<?= (int) $editField['id'] ?>">
                <?php endif; ?>

                <div class="card-body">
                    <div class="form-grid">
                        <div class="field span-2">
                            <label for="label">Field label <span class="req">*</span></label>
                            <input type="text" id="label" name="label" required maxlength="255" value="<?= e($fieldValue('label')) ?>"
                                   placeholder="<?= $isInspection ? 'Did the BCA visit the customer?' : 'Was the customer available?' ?>">
                        </div>

                        <div class="field">
                            <label for="field_type">Field type</label>
                            <select id="field_type" name="field_type">
                                <?php foreach ($fieldTypes as $key => $label): ?>
                                    <option value="<?= e($key) ?>" <?= (string) $fieldValue('field_type', 'text') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label for="field_key">Field key</label>
                            <input type="text" id="field_key" name="field_key" maxlength="80" value="<?= e($fieldValue('field_key')) ?>"
                                   placeholder="auto from the label">
                            <div class="help">Stored with every answer; leave blank to generate it.</div>
                        </div>

                        <div class="field span-2">
                            <label for="options">Choices (one per line)</label>
                            <textarea id="options" name="options" placeholder="Confirmed the visit&#10;Denied the visit&#10;Could not recall"><?= e($fieldValue('options')) ?></textarea>
                            <div class="help">Only used by dropdown, radio and checkbox fields.</div>
                        </div>

                        <div class="field span-2">
                            <label for="help_text">Help text</label>
                            <input type="text" id="help_text" name="help_text" maxlength="255" value="<?= e($fieldValue('help_text')) ?>">
                        </div>

                        <div class="field">
                            <label for="min_value">Minimum value</label>
                            <input type="text" id="min_value" name="min_value" value="<?= e($fieldValue('min_value')) ?>">
                        </div>

                        <div class="field">
                            <label for="max_value">Maximum value</label>
                            <input type="text" id="max_value" name="max_value" value="<?= e($fieldValue('max_value')) ?>">
                        </div>
                    </div>

                    <fieldset>
                        <legend>Conditional display</legend>
                        <p class="help" style="margin-top:0">Show this field only when another field has a particular answer.</p>
                        <div class="form-grid">
                            <div class="field">
                                <label for="condition_field_id">Depends on</label>
                                <select id="condition_field_id" name="condition_field_id">
                                    <option value="">Always show</option>
                                    <?php foreach ($fields as $candidate): ?>
                                        <?php if ((string) $candidate['field_type'] === 'section') { continue; } ?>
                                        <?php if ($editField !== null && (int) $candidate['id'] === (int) $editField['id']) { continue; } ?>
                                        <option value="<?= (int) $candidate['id'] ?>" <?= (int) $fieldValue('condition_field_id') === (int) $candidate['id'] ? 'selected' : '' ?>>
                                            <?= e(str_excerpt((string) $candidate['label'], 40)) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label for="condition_operator">Condition</label>
                                <select id="condition_operator" name="condition_operator">
                                    <?php foreach (['equals' => 'equals', 'not_equals' => 'does not equal', 'in' => 'is one of', 'filled' => 'is filled', 'empty' => 'is empty'] as $key => $label): ?>
                                        <option value="<?= $key ?>" <?= (string) $fieldValue('condition_operator', 'equals') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field span-2">
                                <label for="condition_value">Value</label>
                                <input type="text" id="condition_value" name="condition_value" maxlength="255" value="<?= e($fieldValue('condition_value')) ?>"
                                       placeholder="Yes">
                            </div>
                        </div>
                    </fieldset>

                    <div class="check">
                        <input type="checkbox" id="is_required" name="is_required" value="1" <?= (int) $fieldValue('is_required', 0) === 1 ? 'checked' : '' ?>>
                        <label for="is_required">Required</label>
                    </div>
                    <div class="check">
                        <input type="checkbox" id="field_is_active" name="is_active" value="1" <?= (int) $fieldValue('is_active', 1) === 1 ? 'checked' : '' ?>>
                        <label for="field_is_active">Active (shown on the form)</label>
                    </div>
                </div>

                <div class="card-foot">
                    <button type="submit" class="btn btn-sm">
                        <?= icon('check', '', 14) ?> <?= $editField === null ? 'Add field' : 'Save field' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>
