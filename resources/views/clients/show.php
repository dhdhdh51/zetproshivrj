<?php
/**
 * @var array $client
 * @var array $documents
 */
?>
<div class="page-head">
    <div class="d-flex align-items-center gap-3">
        <span class="avatar" style="width:48px;height:48px;flex:0 0 48px;font-size:1rem"><?= e(initials((string) $client['name'])) ?></span>
        <div>
            <h1 class="mb-1"><?= e((string) $client['name']) ?></h1>
            <p><?= e((string) ($client['company'] ?? '') ?: 'Client') ?></p>
        </div>
    </div>
    <div class="btn-group-dp">
        <a href="<?= e(url('documents/create?client_id=' . (int) $client['id'])) ?>" class="btn-dp btn-primary-dp">
            <?= icon('plus', '', 17) ?> New document
        </a>
        <a href="<?= e(url('clients/' . (int) $client['id'] . '/edit')) ?>" class="btn-dp btn-outline-dp"><?= icon('edit', '', 17) ?> Edit</a>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card-dp">
            <div class="card-dp__head"><h3>Contact details</h3></div>
            <div class="card-dp__body">
                <dl class="kv mb-0">
                    <dt>Email</dt>
                    <dd><?= !empty($client['email']) ? '<a href="mailto:' . e((string) $client['email']) . '">' . e((string) $client['email']) . '</a>' : '—' ?></dd>
                    <dt>Phone</dt><dd><?= e((string) ($client['phone'] ?? '') ?: '—') ?></dd>
                    <dt>Address</dt><dd><?= nl2br(e((string) ($client['address'] ?? '') ?: '—')) ?></dd>
                    <dt>Added</dt><dd><?= e(format_date((string) $client['created_at'])) ?></dd>
                </dl>
            </div>
        </div>

        <?php if (!empty($client['notes'])): ?>
            <div class="card-dp">
                <div class="card-dp__head"><h3>Internal notes</h3></div>
                <div class="card-dp__body">
                    <p class="mb-0" style="white-space:pre-line"><?= e((string) $client['notes']) ?></p>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-8">
        <div class="card-dp">
            <div class="card-dp__head">
                <h2>Documents</h2>
                <a href="<?= e(url('documents?client_id=' . (int) $client['id'])) ?>" class="btn-dp btn-ghost-dp btn-sm-dp">
                    Filter in documents <?= icon('arrow-right', '', 15) ?>
                </a>
            </div>

            <?php if ($documents === []): ?>
                <div class="empty-state">
                    <div class="empty-state__icon"><?= icon('file-text', '', 26) ?></div>
                    <h3>No documents for this client yet</h3>
                    <p>Create a quotation, invoice or proposal — their details will be filled in automatically.</p>
                    <a href="<?= e(url('documents/create?client_id=' . (int) $client['id'])) ?>" class="btn-dp btn-primary-dp">
                        <?= icon('sparkles', '', 17) ?> Create document
                    </a>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table-dp">
                        <thead>
                        <tr><th>Number</th><th>Type</th><th>Title</th><th>Status</th><th class="num">Total</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($documents as $document): ?>
                            <tr>
                                <td><a href="<?= e(url('documents/' . (int) $document['id'])) ?>" class="fw-650 mono"><?= e((string) $document['document_number']) ?></a></td>
                                <td><?= e(document_type_label((string) $document['document_type'])) ?></td>
                                <td><?= e(str_excerpt((string) $document['title'], 40)) ?></td>
                                <td><span class="<?= status_class((string) $document['status']) ?>"><?= e((string) $document['status']) ?></span></td>
                                <td class="num"><?= e(money((float) $document['total'], (string) $document['currency'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
