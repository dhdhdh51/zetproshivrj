<?php
/**
 * @var array $groups
 * @var array $recentExports
 */
$basePath = App\Core\Auth::isAdmin() ? '/admin' : '/manager';
?>

<div class="page-head">
    <div class="grow">
        <h1>Reports</h1>
        <div class="subtitle">
            Each report has its own filters and can be exported to PDF, Excel or CSV.
            Customer visit and BC inspection reports are kept separate, as are KRM OTS and CKCC OD-2.
        </div>
    </div>
</div>

<?php foreach ($groups as $group => $reports): ?>
    <div class="card">
        <div class="card-head"><h2><?= e($group) ?></h2></div>
        <div class="card-body">
            <div class="grid grid-3" style="gap:12px">
                <?php foreach ($reports as $slug => $report): ?>
                    <a href="<?= e(url($basePath . '/reports/' . $slug)) ?>"
                       style="display:block;border:1px solid var(--line);border-radius:var(--radius);padding:14px;background:#fff;text-decoration:none;color:inherit">
                        <div style="display:flex;align-items:center;gap:9px;margin-bottom:6px">
                            <span style="color:var(--brand)"><?= icon($report['icon'], '', 18) ?></span>
                            <strong style="font-size:13.5px"><?= e($report['name']) ?></strong>
                        </div>
                        <div class="small muted"><?= e($report['description']) ?></div>
                        <div class="tiny" style="margin-top:8px;color:var(--brand-600)">
                            Open report <?= icon('arrow-right', '', 12) ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<div class="card">
    <div class="card-head">
        <h2>Recent exports</h2>
        <div class="spacer"></div>
        <span class="small muted">Exports are generated on demand and kept for download</span>
    </div>
    <?php if ($recentExports === []): ?>
        <?= view_partial('partials.empty', ['message' => 'No exports generated yet', 'iconName' => 'download']) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data compact">
                <thead><tr><th>Report</th><th>Format</th><th class="right">Rows</th><th>Size</th><th>Generated</th><th>By</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($recentExports as $export): ?>
                        <tr>
                            <td><?= e(App\Services\Reports::name((string) $export['report_slug'])) ?></td>
                            <td><span class="badge badge-muted"><?= e(strtoupper((string) $export['format'])) ?></span></td>
                            <td class="right num"><?= number_format((int) $export['row_count']) ?></td>
                            <td class="small"><?= e(App\Services\Excel\LoanImporter::humanSize((int) $export['file_size'])) ?></td>
                            <td class="small"><?= e(time_ago($export['created_at'])) ?></td>
                            <td class="small"><?= e($export['user_name']) ?></td>
                            <td>
                                <?php if ((string) $export['status'] === 'completed'): ?>
                                    <a class="btn btn-link btn-sm" href="<?= e(url('/files/export/' . (int) $export['id'])) ?>">Download</a>
                                <?php else: ?>
                                    <span class="badge badge-danger"><?= e((string) $export['status']) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
