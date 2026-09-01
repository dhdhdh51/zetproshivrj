<?php
/**
 * Step 3 — preview before writing anything.
 *
 * @var array $import
 * @var array $rows
 * @var array $summary
 * @var array $issues
 * @var array $mapping
 * @var array $columns
 * @var int   $total_rows
 * @var array $systemFields
 */
$importId = (int) $import['id'];
?>

<div class="page-head">
    <div class="grow">
        <h1>Import preview</h1>
        <div class="subtitle">
            <?= e($import['original_name']) ?> · <?= number_format($total_rows) ?> data row(s) in the sheet ·
            showing the first <?= count($rows) ?>
        </div>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="<?= e(url('/admin/imports/' . $importId . '/mapping')) ?>">
            <?= icon('arrow-left', '', 15) ?> Change mapping
        </a>
    </div>
</div>

<div class="steps">
    <div class="step done"><span class="n"><?= icon('check', '', 12) ?></span> Upload</div>
    <div class="step done"><span class="n"><?= icon('check', '', 12) ?></span> Map columns</div>
    <div class="step active"><span class="n">3</span> Preview</div>
    <div class="step"><span class="n">4</span> Import</div>
</div>

<div class="stat-grid">
    <div class="stat good">
        <div class="label">Ready to import</div>
        <div class="value"><?= number_format($summary['ready']) ?></div>
        <div class="meta"><?= number_format($summary['new']) ?> new · <?= number_format($summary['existing']) ?> update</div>
    </div>
    <div class="stat <?= $summary['missing_required'] > 0 ? 'bad' : '' ?>">
        <div class="label">Missing required</div>
        <div class="value"><?= number_format($summary['missing_required']) ?></div>
        <div class="meta">rows will be skipped</div>
    </div>
    <div class="stat <?= $summary['invalid_data'] > 0 ? 'warn' : '' ?>">
        <div class="label">Invalid data</div>
        <div class="value"><?= number_format($summary['invalid_data']) ?></div>
        <div class="meta">amounts or dates unreadable</div>
    </div>
    <div class="stat <?= $summary['duplicate_in_file'] > 0 ? 'warn' : '' ?>">
        <div class="label">Duplicates in file</div>
        <div class="value"><?= number_format($summary['duplicate_in_file']) ?></div>
        <div class="meta">only the first is kept</div>
    </div>
    <div class="stat <?= $summary['unknown_branch'] > 0 ? 'bad' : '' ?>">
        <div class="label">Unknown branch</div>
        <div class="value"><?= number_format($summary['unknown_branch']) ?></div>
        <div class="meta">branch must exist first</div>
    </div>
    <div class="stat <?= $summary['invalid_bc'] > 0 ? 'warn' : '' ?>">
        <div class="label">Invalid BC code</div>
        <div class="value"><?= number_format($summary['invalid_bc']) ?></div>
        <div class="meta">will be balanced by workload</div>
    </div>
</div>

<?php if ($issues !== []): ?>
    <div class="alert alert-info">
        <?= icon('info', '', 17) ?>
        <div>
            <strong>What will happen:</strong>
            <ul style="margin:6px 0 0 16px;padding:0">
                <?php foreach ($issues as $issue): ?><li><?= e($issue) ?></li><?php endforeach; ?>
            </ul>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-head">
        <h2>Mapped rows</h2>
        <div class="spacer"></div>
        <span class="small muted">Values shown are the parsed values that would be stored</span>
    </div>
    <div class="table-wrap">
        <table class="data compact">
            <thead>
                <tr>
                    <th>Row</th>
                    <th>Account</th>
                    <th>Borrower</th>
                    <th>Village</th>
                    <th>Branch</th>
                    <th class="right">Outstanding</th>
                    <th class="right">Overdue</th>
                    <th>NPA date</th>
                    <th>BC code</th>
                    <th>Result</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php $hasErrors = $row['errors'] !== []; ?>
                    <tr style="<?= $hasErrors ? 'background:#fdf6f6' : '' ?>">
                        <td class="tiny muted"><?= (int) $row['row'] ?></td>
                        <td class="mono small"><?= e($row['data']['account_number'] ?? '—') ?></td>
                        <td class="small">
                            <?= e($row['data']['borrower_name'] ?? '—') ?>
                            <?php if (!empty($row['data']['father_name'])): ?>
                                <div class="tiny muted">S/o <?= e($row['data']['father_name']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?= e($row['data']['village'] ?? '—') ?></td>
                        <td class="small"><?= e($row['branch_name'] ?? '—') ?></td>
                        <td class="right num small"><?= e(money((float) ($row['data']['outstanding'] ?? 0))) ?></td>
                        <td class="right num small"><?= e(money((float) ($row['data']['overdue'] ?? 0))) ?></td>
                        <td class="small"><?= e(format_date($row['data']['npa_date'] ?? null)) ?></td>
                        <td class="small mono">
                            <?= e($row['data']['bc_code_raw'] ?? '—') ?>
                            <?php if (!empty($row['bc_target'])): ?>
                                <div class="tiny success-text">→ <?= e($row['bc_target']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="small">
                            <?php if ($hasErrors): ?>
                                <?php foreach ($row['errors'] as $error): ?>
                                    <div class="danger-text tiny">
                                        <?= icon('x-circle', '', 12) ?> <?= e($error['message']) ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="badge <?= $row['exists'] ? 'badge-info' : 'badge-success' ?>">
                                    <?= $row['exists'] ? 'update' : 'new' ?>
                                </span>
                            <?php endif; ?>

                            <?php foreach ($row['warnings'] as $warning): ?>
                                <div class="warning-text tiny"><?= icon('alert', '', 12) ?> <?= e($warning['message']) ?></div>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card-foot">
        <form method="post" action="<?= e(url('/admin/imports/' . $importId . '/run')) ?>"
              data-confirm="Import this file now? <?= number_format($summary['ready']) ?> row(s) in the preview are ready; the whole sheet of <?= number_format($total_rows) ?> row(s) will be processed.">
            <?= csrf_field() ?>
            <div class="check" style="margin-bottom:10px">
                <input type="checkbox" id="auto_allocate" name="auto_allocate" value="1" checked>
                <label for="auto_allocate">
                    Allocate accounts automatically (BC code first, then balance by workload)
                </label>
            </div>
            <button type="submit" class="btn"><?= icon('check', '', 15) ?> Import <?= number_format($total_rows) ?> row(s)</button>
        </form>

        <form method="post" action="<?= e(url('/admin/imports/' . $importId . '/cancel')) ?>"
              data-confirm="Cancel this import? The uploaded file will be deleted.">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-secondary">Cancel</button>
        </form>
    </div>
</div>

<div class="card content-narrow">
    <div class="card-head"><h3>Mapping being used</h3></div>
    <div class="card-body">
        <div class="kv">
            <?php foreach ($systemFields as $key => $field): ?>
                <div>
                    <div class="k"><?= e($field['label']) ?></div>
                    <div class="v">
                        <?php if (isset($mapping[$key])): ?>
                            <?= e($mapping[$key]) ?>
                        <?php else: ?>
                            <span class="muted">not mapped</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
