<?php
/**
 * @var array $filters
 * @var array $documents  paginator
 * @var array $stats
 */
$query = array_filter(['q' => $filters['search'], 'type' => $filters['type'], 'status' => $filters['status']]);
?>
<div class="page-head">
    <div>
        <h1>Documents</h1>
        <p><?= number_format((int) $stats['total']) ?> total · <?= number_format((int) $stats['this_month']) ?> this month · <?= number_format((int) $stats['sent']) ?> sent</p>
    </div>
</div>

<div class="card-dp">
    <div class="card-dp__head">
        <form method="get" action="<?= e(url('admin/documents')) ?>" class="row g-2 flex-grow-1">
            <div class="col-sm-6 col-lg-5">
                <input type="search" name="q" value="<?= e((string) $filters['search']) ?>" class="input-dp"
                       placeholder="Search number, title or owner email…">
            </div>
            <div class="col-6 col-lg-3">
                <select name="type" class="select-dp" data-auto-submit>
                    <option value="">All types</option>
                    <?php foreach (document_types() as $key => $meta): ?>
                        <option value="<?= e($key) ?>" <?= (string) $filters['type'] === $key ? 'selected' : '' ?>><?= e($meta['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <select name="status" class="select-dp" data-auto-submit>
                    <option value="">All statuses</option>
                    <?php foreach (['draft' => 'Draft', 'final' => 'Final', 'sent' => 'Sent'] as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= (string) $filters['status'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 d-flex gap-2">
                <button type="submit" class="btn-dp btn-outline-dp flex-grow-1"><?= icon('search', '', 16) ?></button>
                <?php if ($query !== []): ?>
                    <a href="<?= e(url('admin/documents')) ?>" class="btn-dp btn-ghost-dp"><?= icon('x', '', 16) ?></a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <?php if ($documents['data'] === []): ?>
        <div class="empty-state">
            <div class="empty-state__icon"><?= icon('file-text', '', 26) ?></div>
            <h3>No documents found</h3>
            <p>No documents match the current filters.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-dp">
                <thead>
                <tr><th>Number</th><th>Owner</th><th>Title</th><th>Status</th><th class="num">Total</th><th class="num">Created</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($documents['data'] as $document): ?>
                    <?php $id = (int) $document['id']; ?>
                    <tr>
                        <td>
                            <a href="<?= e(url('admin/documents/' . $id)) ?>" class="mono fw-650"><?= e((string) $document['document_number']) ?></a>
                            <div class="text-muted-2" style="font-size:.78rem"><?= e(document_type_label((string) $document['document_type'])) ?></div>
                        </td>
                        <td style="font-size:.86rem">
                            <a href="<?= e(url('admin/users/' . (int) $document['user_id'])) ?>"><?= e((string) $document['user_name']) ?></a>
                            <div class="text-muted-2" style="font-size:.78rem"><?= e(str_excerpt((string) $document['user_email'], 26)) ?></div>
                        </td>
                        <td><?= e(str_excerpt((string) $document['title'], 32)) ?></td>
                        <td><span class="<?= status_class((string) $document['status']) ?>"><?= e((string) $document['status']) ?></span></td>
                        <td class="num fw-650"><?= e(money((float) $document['total'], (string) $document['currency'])) ?></td>
                        <td class="num text-muted-2" style="font-size:.84rem"><?= e(format_date((string) $document['created_at'])) ?></td>
                        <td class="num">
                            <div class="btn-group-dp justify-content-end">
                                <a href="<?= e(url('admin/documents/' . $id . '/preview')) ?>" target="_blank" rel="noopener"
                                   class="btn-dp btn-outline-dp btn-sm-dp" title="Preview"><?= icon('eye', '', 15) ?></a>
                                <form method="post" action="<?= e(url('admin/documents/' . $id . '/delete')) ?>"
                                      data-confirm="Permanently delete <?= e((string) $document['document_number']) ?>?">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn-dp btn-ghost-dp btn-sm-dp" style="color:var(--dp-danger)"><?= icon('trash', '', 15) ?></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card-dp__foot">
            <?= view_partial('partials.pagination', ['paginator' => $documents, 'query' => $query]) ?>
        </div>
    <?php endif; ?>
</div>
