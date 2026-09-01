<div class="page-head">
    <div class="grow">
        <h1>Excel imports</h1>
        <div class="subtitle">Upload history, mapping templates and row-level errors.</div>
    </div>
    <div class="page-actions">
        <a class="btn" href="<?= e(url('/admin/imports/create')) ?>"><?= icon('upload', '', 15) ?> New upload</a>
    </div>
</div>

<div class="card">
    <div class="card-head"><h2>Uploads</h2></div>
    <?php if ($imports === []): ?>
        <?= view_partial('partials.empty', [
            'message' => 'No files uploaded yet',
            'hint' => 'Upload a loan Excel sheet to create accounts and allocate them.',
            'iconName' => 'upload',
            'actionUrl' => '/admin/imports/create',
            'actionLabel' => 'Upload Excel',
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th>File</th><th>Uploaded</th><th class="center">Status</th>
                        <th class="right">Rows</th><th class="right">New</th><th class="right">Updated</th>
                        <th class="right">Skipped</th><th class="right">Allocated</th><th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($imports as $import): ?>
                        <tr>
                            <td>
                                <strong><?= e(str_excerpt((string) $import['original_name'], 40)) ?></strong>
                                <div class="tiny muted">
                                    <?= e($import['sheet_name']) ?> ·
                                    <?= e(App\Services\Excel\LoanImporter::humanSize((int) $import['file_size'])) ?>
                                    <?php if (!empty($import['template_name'])): ?> · <?= e($import['template_name']) ?><?php endif; ?>
                                </div>
                            </td>
                            <td class="small">
                                <?= e(format_datetime($import['created_at'])) ?>
                                <div class="tiny muted">by <?= e($import['uploaded_by']) ?></div>
                            </td>
                            <td class="center"><span class="<?= e(badge((string) $import['status'])) ?>"><?= e(enum_label((string) $import['status'])) ?></span></td>
                            <td class="right num"><?= number_format((int) $import['total_rows']) ?></td>
                            <td class="right num"><?= number_format((int) $import['created_accounts']) ?></td>
                            <td class="right num"><?= number_format((int) $import['updated_accounts']) ?></td>
                            <td class="right num <?= (int) $import['skipped_rows'] > 0 ? 'danger-text' : '' ?>"><?= number_format((int) $import['skipped_rows']) ?></td>
                            <td class="right num"><?= number_format((int) $import['assigned_rows']) ?></td>
                            <td class="nowrap">
                                <?php if (in_array((string) $import['status'], ['uploaded', 'mapped'], true)): ?>
                                    <a class="btn btn-sm" href="<?= e(url('/admin/imports/' . (int) $import['id'] . '/mapping')) ?>">Continue</a>
                                <?php else: ?>
                                    <a class="btn btn-link btn-sm" href="<?= e(url('/admin/imports/' . (int) $import['id'])) ?>">Details</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-head">
        <h2>Mapping templates</h2>
        <div class="spacer"></div>
        <span class="small muted">Reused automatically for the same Excel layout</span>
    </div>
    <?php if ($templates === []): ?>
        <?= view_partial('partials.empty', [
            'message' => 'No saved mappings',
            'hint' => 'Save a mapping on the mapping screen and it will be offered on your next upload.',
            'iconName' => 'sliders',
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data compact">
                <thead><tr><th>Name</th><th>Description</th><th class="center">Fields</th><th class="center">Used</th><th>Last used</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($templates as $template): ?>
                        <?php $fields = json_decode((string) $template['mapping'], true); ?>
                        <tr>
                            <td><strong><?= e($template['name']) ?></strong>
                                <div class="tiny muted">by <?= e($template['created_by_name'] ?: 'system') ?></div>
                            </td>
                            <td class="small"><?= e($template['description'] ?: '—') ?></td>
                            <td class="center num"><?= is_array($fields) ? count($fields) : 0 ?></td>
                            <td class="center num"><?= (int) $template['usage_count'] ?></td>
                            <td class="small"><?= e(time_ago($template['last_used_at'])) ?></td>
                            <td class="right">
                                <form method="post" action="<?= e(url('/admin/mapping-templates/' . (int) $template['id'] . '/delete')) ?>"
                                      data-confirm="Delete the mapping template &quot;<?= e($template['name']) ?>&quot;?">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-link btn-sm"><?= icon('trash', '', 14) ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
