<?php
/**
 * @var array $filters
 * @var array $documents  paginator
 * @var array $clients
 * @var array $summary
 */
$query = array_filter([
    'q' => $filters['search'],
    'type' => $filters['type'],
    'status' => $filters['status'],
    'client_id' => $filters['client_id'] ?: '',
]);
$hasFilters = $query !== [];
?>
<div class="page-head">
    <div>
        <h1>Documents</h1>
        <p>
            <?= (int) $documents['total'] ?> document<?= (int) $documents['total'] === 1 ? '' : 's' ?> ·
            <?= (int) $summary['documents_used'] ?> of <?= (int) $summary['documents_limit'] ?> created this month
        </p>
    </div>
    <a href="<?= e(url('documents/create')) ?>" class="btn-dp btn-primary-dp"><?= icon('sparkles', '', 17) ?> Create document</a>
</div>

<div class="card-dp">
    <div class="card-dp__head">
        <form method="get" action="<?= e(url('documents')) ?>" class="row g-2 flex-grow-1">
            <div class="col-sm-6 col-lg-4">
                <input type="search" name="q" value="<?= e((string) $filters['search']) ?>" class="input-dp"
                       placeholder="Search number, title or client…">
            </div>
            <div class="col-6 col-lg-2">
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
            <div class="col-lg-2">
                <select name="client_id" class="select-dp" data-auto-submit>
                    <option value="">All clients</option>
                    <?php foreach ($clients as $client): ?>
                        <option value="<?= (int) $client['id'] ?>" <?= (int) $filters['client_id'] === (int) $client['id'] ? 'selected' : '' ?>>
                            <?= e(str_excerpt((string) $client['name'], 22)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 d-flex gap-2">
                <button type="submit" class="btn-dp btn-outline-dp flex-grow-1"><?= icon('search', '', 16) ?> Filter</button>
                <?php if ($hasFilters): ?>
                    <a href="<?= e(url('documents')) ?>" class="btn-dp btn-ghost-dp" title="Clear filters"><?= icon('x', '', 16) ?></a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <?php if ($documents['data'] === []): ?>
        <div class="empty-state">
            <div class="empty-state__icon"><?= icon('file-text', '', 26) ?></div>
            <h3><?= $hasFilters ? 'Nothing matches those filters' : 'No documents yet' ?></h3>
            <p>
                <?= $hasFilters
                    ? 'Try clearing the filters or searching for a different document number.'
                    : 'Create your first quotation, invoice or proposal — describe it in a sentence and AI will draft it.' ?>
            </p>
            <?php if ($hasFilters): ?>
                <a href="<?= e(url('documents')) ?>" class="btn-dp btn-outline-dp">Clear filters</a>
            <?php else: ?>
                <a href="<?= e(url('documents/create')) ?>" class="btn-dp btn-primary-dp"><?= icon('sparkles', '', 17) ?> Create document</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-dp">
                <thead>
                <tr>
                    <th>Number</th>
                    <th>Document</th>
                    <th>Client</th>
                    <th>Status</th>
                    <th class="num">Total</th>
                    <th class="num">Date</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($documents['data'] as $document): ?>
                    <?php $id = (int) $document['id']; ?>
                    <tr>
                        <td>
                            <a href="<?= e(url('documents/' . $id)) ?>" class="fw-650 mono"><?= e((string) $document['document_number']) ?></a>
                            <?php if ((int) $document['ai_generated'] === 1): ?>
                                <div class="text-muted-2" style="font-size:.75rem"><?= icon('sparkles', '', 11) ?> AI</div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="fw-650 text-ink" style="font-size:.92rem"><?= e(str_excerpt((string) $document['title'], 42)) ?></div>
                            <div class="text-muted-2" style="font-size:.8rem"><?= e(document_type_label((string) $document['document_type'])) ?></div>
                        </td>
                        <td style="font-size:.9rem">
                            <?= e((string) ($document['client_name'] ?? '—')) ?>
                            <?php if (!empty($document['client_company'])): ?>
                                <div class="text-muted-2" style="font-size:.8rem"><?= e(str_excerpt((string) $document['client_company'], 24)) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="<?= status_class((string) $document['status']) ?>"><?= e((string) $document['status']) ?></span>
                            <?php if (!empty($document['share_token']) && (int) ($document['share_active'] ?? 0) === 1): ?>
                                <div class="text-muted-2" style="font-size:.75rem"><?= icon('link', '', 11) ?> shared</div>
                            <?php endif; ?>
                        </td>
                        <td class="num fw-650"><?= e(money((float) $document['total'], (string) $document['currency'])) ?></td>
                        <td class="num text-muted-2" style="font-size:.85rem"><?= e(format_date((string) $document['issue_date'])) ?></td>
                        <td class="num">
                            <div class="btn-group-dp justify-content-end">
                                <a href="<?= e(url('documents/' . $id . '/edit')) ?>" class="btn-dp btn-outline-dp btn-sm-dp" title="Edit"><?= icon('edit', '', 15) ?></a>
                                <a href="<?= e(url('documents/' . $id . '/download')) ?>" class="btn-dp btn-outline-dp btn-sm-dp" title="Download PDF"><?= icon('download', '', 15) ?></a>
                                <form method="post" action="<?= e(url('documents/' . $id . '/duplicate')) ?>">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn-dp btn-ghost-dp btn-sm-dp" title="Duplicate"><?= icon('copy', '', 15) ?></button>
                                </form>
                                <form method="post" action="<?= e(url('documents/' . $id . '/delete')) ?>"
                                      data-confirm="Delete <?= e((string) $document['document_number']) ?>?">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn-dp btn-ghost-dp btn-sm-dp" style="color:var(--dp-danger)" title="Delete">
                                        <?= icon('trash', '', 15) ?>
                                    </button>
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
