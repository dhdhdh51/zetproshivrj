<?php
/**
 * Step 2 of the import wizard — the column mapping screen.
 *
 * @var array $import
 * @var array $headers      column index => caption
 * @var array $mapping      system field => caption
 * @var array $matches      system field => match metadata
 * @var array $uncertain
 * @var array $missingRequired
 * @var array $systemFields
 * @var array $sheets
 * @var array $sample
 * @var array $templates
 */
$importId = (int) $import['id'];
?>

<div class="page-head">
    <div class="grow">
        <h1>Map Excel columns</h1>
        <div class="subtitle">
            <?= e($import['original_name']) ?> · sheet <strong><?= e($import['sheet_name']) ?></strong>
            · <?= count($headers) ?> columns detected
        </div>
    </div>
    <div class="page-actions">
        <form method="post" action="<?= e(url('/admin/imports/' . $importId . '/cancel')) ?>"
              data-confirm="Cancel this upload? The file will be deleted and nothing imported.">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-secondary">Cancel upload</button>
        </form>
    </div>
</div>

<div class="steps">
    <div class="step done"><span class="n"><?= icon('check', '', 12) ?></span> Upload</div>
    <div class="step active"><span class="n">2</span> Map columns</div>
    <div class="step"><span class="n">3</span> Preview</div>
    <div class="step"><span class="n">4</span> Import</div>
</div>

<?php if ($missingRequired !== []): ?>
    <div class="alert alert-warning">
        <?= icon('alert-triangle', '', 17) ?>
        <div>
            We could not confidently find a column for:
            <strong><?= e(implode(', ', array_map(static fn (string $k): string => App\Services\Excel\SystemFields::label($k), $missingRequired))) ?></strong>.
            Choose the right column below — these fields are required.
        </div>
    </div>
<?php endif; ?>

<?php if ($uncertain !== []): ?>
    <div class="alert alert-info">
        <?= icon('info', '', 17) ?>
        <div>
            <?= count($uncertain) ?> column(s) were matched with low confidence and are marked
            <span class="badge badge-warning">confirm</span> below. Please check them before continuing.
        </div>
    </div>
<?php endif; ?>

<div class="grid grid-2">
    <!-- Sheet / header row -->
    <div class="card">
        <div class="card-head"><h3>Sheet and header row</h3></div>
        <form method="post" action="<?= e(url('/admin/imports/' . $importId . '/redetect')) ?>">
            <?= csrf_field() ?>
            <div class="card-body">
                <div class="form-grid">
                    <div class="field">
                        <label for="sheet_name">Sheet</label>
                        <select id="sheet_name" name="sheet_name">
                            <?php foreach ($sheets as $sheet): ?>
                                <option value="<?= e($sheet) ?>" <?= (string) $import['sheet_name'] === $sheet ? 'selected' : '' ?>>
                                    <?= e($sheet) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="header_row">Header row number</label>
                        <input type="number" id="header_row" name="header_row" min="1" max="20"
                               value="<?= (int) $import['header_row'] ?>">
                        <div class="help">Use this when the sheet has a title above the column names.</div>
                    </div>
                </div>
            </div>
            <div class="card-foot">
                <button type="submit" class="btn btn-secondary btn-sm"><?= icon('refresh', '', 14) ?> Re-detect columns</button>
            </div>
        </form>
    </div>

    <!-- Saved mappings -->
    <div class="card">
        <div class="card-head"><h3>Saved mappings</h3></div>
        <div class="card-body">
            <?php if ($templates === []): ?>
                <p class="small muted" style="margin:0">
                    No saved mappings yet. Once this mapping is right, save it below and it will be
                    offered automatically on your next upload of the same format.
                </p>
            <?php else: ?>
                <form method="post" action="<?= e(url('/admin/imports/' . $importId . '/apply-template')) ?>">
                    <?= csrf_field() ?>
                    <div class="field">
                        <label for="template_id">Apply a saved mapping</label>
                        <select id="template_id" name="template_id">
                            <option value="">Select…</option>
                            <?php foreach ($templates as $template): ?>
                                <option value="<?= (int) $template['id'] ?>" <?= (int) ($import['template_id'] ?? 0) === (int) $template['id'] ? 'selected' : '' ?>>
                                    <?= e($template['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-secondary btn-sm">Apply mapping</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- The mapping table -->
<form method="post" action="<?= e(url('/admin/imports/' . $importId . '/mapping')) ?>" data-mapping-form>
    <?= csrf_field() ?>

    <div class="card">
        <div class="card-head">
            <h2>System field → Excel column</h2>
            <div class="spacer"></div>
            <span class="small muted">Every field has a dropdown of the detected Excel headers</span>
        </div>

        <div class="alert alert-warning hidden" data-mapping-warning style="margin:14px 18px 0">
            <?= icon('alert-triangle', '', 17) ?>
            <div>Two system fields are pointing at the same Excel column. Fix the highlighted rows.</div>
        </div>

        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th style="width:230px">System field</th>
                        <th style="width:280px">Excel column</th>
                        <th class="center" style="width:110px">Auto match</th>
                        <th>Sample values from your file</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($systemFields as $key => $field): ?>
                        <?php
                        $match = $matches[$key] ?? ['column' => null, 'confidence' => 0, 'certain' => false];
                        $selected = $mapping[$key] ?? ($match['header'] ?? '');
                        $columnIndex = null;

                        foreach ($headers as $index => $caption) {
                            if ($caption === $selected) {
                                $columnIndex = $index;
                                break;
                            }
                        }
                        ?>
                        <tr>
                            <td>
                                <strong><?= e($field['label']) ?></strong>
                                <?php if ($field['required']): ?><span class="req" style="color:var(--danger)">*</span><?php endif; ?>
                                <div class="tiny muted"><?= e($field['help'] ?? '') ?></div>
                            </td>
                            <td>
                                <select name="mapping[<?= e($key) ?>]" data-mapping-select
                                        class="<?= $field['required'] && $selected === '' ? 'has-error' : '' ?>">
                                    <option value="">— not in this file —</option>
                                    <?php foreach ($headers as $caption): ?>
                                        <option value="<?= e($caption) ?>" <?= $selected === $caption ? 'selected' : '' ?>>
                                            <?= e($caption) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td class="center">
                                <?php if ($match['column'] === null): ?>
                                    <span class="badge badge-muted">none</span>
                                <?php elseif ($match['certain']): ?>
                                    <span class="badge badge-success"><?= (int) $match['confidence'] ?>%</span>
                                <?php else: ?>
                                    <span class="badge badge-warning">confirm <?= (int) $match['confidence'] ?>%</span>
                                <?php endif; ?>
                            </td>
                            <td class="small muted">
                                <?php if ($columnIndex !== null): ?>
                                    <?php
                                    $samples = [];

                                    foreach ($sample['rows'] as $row) {
                                        $value = $row['values'][$columnIndex] ?? '';

                                        if (trim((string) $value) !== '') {
                                            $samples[] = str_excerpt((string) $value, 24);
                                        }
                                    }
                                    ?>
                                    <?= $samples === [] ? '<em>blank in the first rows</em>' : e(implode(' · ', array_slice($samples, 0, 4))) ?>
                                <?php else: ?>
                                    <em>—</em>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="card-foot">
            <button type="submit" class="btn"><?= icon('arrow-right', '', 15) ?> Save mapping and preview</button>
            <span class="small muted">
                Branch Code or Branch Name must be mapped so each account can be linked to its branch.
            </span>
        </div>
    </div>
</form>

<!-- Save as template -->
<div class="card content-narrow">
    <div class="card-head"><h3>Save this mapping for next time</h3></div>
    <form method="post" action="<?= e(url('/admin/imports/' . $importId . '/save-template')) ?>">
        <?= csrf_field() ?>
        <div class="card-body">
            <div class="form-grid">
                <div class="field">
                    <label for="name">Mapping name <span class="req">*</span></label>
                    <input type="text" id="name" name="name" required maxlength="160"
                           placeholder="e.g. Central Bank Excel Format">
                </div>
                <div class="field">
                    <label for="description">Description</label>
                    <input type="text" id="description" name="description" maxlength="255"
                           placeholder="Which report this layout comes from">
                </div>
            </div>
            <p class="help" style="margin:0">
                Saved mappings are matched by column caption, so they keep working even if the
                columns move position between uploads.
            </p>
        </div>
        <div class="card-foot">
            <button type="submit" class="btn btn-secondary btn-sm"><?= icon('check', '', 14) ?> Save mapping</button>
        </div>
    </form>
</div>

<!-- First rows, raw -->
<div class="card">
    <div class="card-head">
        <h3>First rows of your file</h3>
        <div class="spacer"></div>
        <span class="small muted">Exactly as read from the sheet</span>
    </div>
    <div class="table-wrap">
        <table class="data compact">
            <thead>
                <tr>
                    <th>Row</th>
                    <?php foreach ($headers as $caption): ?>
                        <th><?= e(str_excerpt($caption, 18)) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sample['rows'] as $row): ?>
                    <tr>
                        <td class="muted tiny"><?= (int) $row['row'] ?></td>
                        <?php foreach (array_keys($headers) as $index): ?>
                            <td class="small"><?= e(str_excerpt((string) ($row['values'][$index] ?? ''), 20)) ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
