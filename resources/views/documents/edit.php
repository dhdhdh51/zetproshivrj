<?php
/**
 * @var array $document
 * @var array $items
 * @var array $profile
 * @var array $clients
 * @var array $templates
 * @var bool  $all_templates
 * @var bool  $ai_ready
 */
$documentId = (int) $document['id'];
$currency = (string) $document['currency'];
$field = static fn (string $key, string $fallback = ''): string => e((string) (old($key) !== '' ? old($key) : ($document[$key] ?? $fallback)));
?>
<div class="page-head">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <span class="badge badge-primary mono"><?= e((string) $document['document_number']) ?></span>
            <span class="<?= status_class((string) $document['status']) ?>"><?= e((string) $document['status']) ?></span>
            <?php if ((int) $document['ai_generated'] === 1): ?>
                <span class="badge badge-muted"><?= icon('sparkles', '', 12) ?> AI drafted</span>
            <?php endif; ?>
        </div>
        <h1>Edit <?= e(document_type_label((string) $document['document_type'])) ?></h1>
        <p>Change anything you like — every total is recalculated on the server when you save.</p>
    </div>
    <div class="btn-group-dp">
        <a href="<?= e(url('documents/' . $documentId)) ?>" class="btn-dp btn-outline-dp"><?= icon('eye', '', 17) ?> Preview</a>
        <a href="<?= e(url('documents')) ?>" class="btn-dp btn-ghost-dp">All documents</a>
    </div>
</div>

<form method="post" action="<?= e(url('documents/' . $documentId)) ?>" data-document-editor novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="document_type" value="<?= e((string) $document['document_type']) ?>">

    <div class="row g-3">
        <div class="col-xl-8">
            <div class="card-dp">
                <div class="card-dp__head"><h2>Document details</h2></div>
                <div class="card-dp__body">
                    <div class="form-row">
                        <label class="form-label-dp" for="title">Title</label>
                        <input type="text" id="title" name="title" required class="input-dp <?= has_error('title') ? 'is-invalid-dp' : '' ?>"
                               value="<?= $field('title') ?>">
                        <?php if (has_error('title')): ?><p class="field-error"><?= e(error_for('title')) ?></p><?php endif; ?>
                    </div>

                    <div class="form-grid">
                        <div>
                            <label class="form-label-dp" for="currency">Currency</label>
                            <select id="currency" name="currency" class="select-dp">
                                <?php foreach (currencies() as $code => $meta): ?>
                                    <option value="<?= e($code) ?>" <?= $currency === $code ? 'selected' : '' ?>>
                                        <?= e($meta['symbol'] . ' ' . $code) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="form-label-dp" for="issue_date">Document date</label>
                            <input type="date" id="issue_date" name="issue_date" class="input-dp" required
                                   value="<?= e((string) $document['issue_date']) ?>">
                        </div>
                        <div>
                            <label class="form-label-dp" for="valid_until">Valid until</label>
                            <input type="date" id="valid_until" name="valid_until" class="input-dp"
                                   value="<?= e((string) ($document['valid_until'] ?? '')) ?>">
                        </div>
                        <div>
                            <label class="form-label-dp" for="status">Status</label>
                            <select id="status" name="status" class="select-dp">
                                <?php foreach (['draft' => 'Draft', 'final' => 'Final', 'sent' => 'Sent'] as $key => $label): ?>
                                    <option value="<?= e($key) ?>" <?= (string) $document['status'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row mt-3 mb-0">
                        <label class="form-label-dp" for="summary">Summary</label>
                        <textarea id="summary" name="summary" class="textarea-dp" rows="2"><?= $field('summary') ?></textarea>
                        <?php if ($ai_ready): ?>
                            <div class="ai-tools">
                                <button type="button" class="ai-tool" data-ai-action="improve" data-ai-target="#summary" data-document-id="<?= $documentId ?>"><?= icon('sparkles', '', 14) ?> Improve writing</button>
                                <button type="button" class="ai-tool" data-ai-action="rewrite" data-ai-target="#summary" data-document-id="<?= $documentId ?>">Rewrite</button>
                                <button type="button" class="ai-tool" data-ai-action="professional" data-ai-target="#summary" data-document-id="<?= $documentId ?>">Make professional</button>
                                <button type="button" class="ai-tool" data-ai-action="shorter" data-ai-target="#summary" data-document-id="<?= $documentId ?>">Make shorter</button>
                                <button type="button" class="ai-tool" data-ai-action="expand" data-ai-target="#summary" data-document-id="<?= $documentId ?>">Expand</button>
                                <button type="button" class="ai-tool" data-ai-action="grammar" data-ai-target="#summary" data-document-id="<?= $documentId ?>">Fix grammar</button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="card-dp">
                <div class="card-dp__head"><h2>Client</h2></div>
                <div class="card-dp__body">
                    <div class="form-row">
                        <label class="form-label-dp" for="client_id">Linked client</label>
                        <select id="client_id" name="client_id" class="select-dp" data-client-select>
                            <option value="">— Not linked (manual details) —</option>
                            <?php foreach ($clients as $client): ?>
                                <option value="<?= (int) $client['id'] ?>"
                                        data-name="<?= e((string) $client['name']) ?>"
                                        data-company="<?= e((string) ($client['company'] ?? '')) ?>"
                                        data-email="<?= e((string) ($client['email'] ?? '')) ?>"
                                        data-phone="<?= e((string) ($client['phone'] ?? '')) ?>"
                                        data-address="<?= e((string) ($client['address'] ?? '')) ?>"
                                    <?= (int) ($document['client_id'] ?? 0) === (int) $client['id'] ? 'selected' : '' ?>>
                                    <?= e((string) $client['name']) ?><?= !empty($client['company']) ? ' · ' . e((string) $client['company']) : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-grid">
                        <div>
                            <label class="form-label-dp" for="client_name">Client name</label>
                            <input type="text" id="client_name" name="client_name" required class="input-dp" value="<?= $field('client_name') ?>">
                        </div>
                        <div>
                            <label class="form-label-dp" for="client_company">Company</label>
                            <input type="text" id="client_company" name="client_company" class="input-dp" value="<?= $field('client_company') ?>">
                        </div>
                        <div>
                            <label class="form-label-dp" for="client_email">Email</label>
                            <input type="email" id="client_email" name="client_email" class="input-dp" value="<?= $field('client_email') ?>">
                        </div>
                        <div>
                            <label class="form-label-dp" for="client_phone">Phone</label>
                            <input type="text" id="client_phone" name="client_phone" class="input-dp" value="<?= $field('client_phone') ?>">
                        </div>
                    </div>

                    <div class="form-row mt-3 mb-0">
                        <label class="form-label-dp" for="client_address">Address</label>
                        <textarea id="client_address" name="client_address" class="textarea-dp" rows="2"><?= $field('client_address') ?></textarea>
                    </div>
                </div>
            </div>

            <?= view_partial('partials.document-items', [
                'items' => $items,
                'currency' => $currency,
                'discount_type' => (string) $document['discount_type'],
                'discount_value' => (float) $document['discount_value'],
            ]) ?>

            <div class="card-dp">
                <div class="card-dp__head"><h2>Notes &amp; terms</h2></div>
                <div class="card-dp__body">
                    <div class="form-row">
                        <label class="form-label-dp" for="notes">Notes</label>
                        <textarea id="notes" name="notes" class="textarea-dp" rows="3"><?= $field('notes') ?></textarea>
                        <?php if ($ai_ready): ?>
                            <div class="ai-tools">
                                <button type="button" class="ai-tool" data-ai-action="improve" data-ai-target="#notes" data-document-id="<?= $documentId ?>"><?= icon('sparkles', '', 14) ?> Improve</button>
                                <button type="button" class="ai-tool" data-ai-action="grammar" data-ai-target="#notes" data-document-id="<?= $documentId ?>">Fix grammar</button>
                                <button type="button" class="ai-tool" data-ai-action="shorter" data-ai-target="#notes" data-document-id="<?= $documentId ?>">Make shorter</button>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="form-row mb-0">
                        <label class="form-label-dp" for="terms">Terms &amp; conditions</label>
                        <textarea id="terms" name="terms" class="textarea-dp" rows="6"><?= $field('terms') ?></textarea>
                        <?php if ($ai_ready): ?>
                            <div class="ai-tools">
                                <button type="button" class="ai-tool" data-ai-terms data-ai-target="#terms" data-document-id="<?= $documentId ?>"><?= icon('sparkles', '', 14) ?> Generate terms</button>
                                <button type="button" class="ai-tool" data-ai-action="professional" data-ai-target="#terms" data-document-id="<?= $documentId ?>">Make professional</button>
                                <button type="button" class="ai-tool" data-ai-action="expand" data-ai-target="#terms" data-document-id="<?= $documentId ?>">Expand</button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card-dp">
                <div class="card-dp__body">
                    <button type="submit" class="btn-dp btn-primary-dp btn-block-dp btn-lg-dp">
                        <?= icon('check', '', 17) ?> Save changes
                    </button>
                    <button type="submit" name="action" value="preview" class="btn-dp btn-outline-dp btn-block-dp mt-2">
                        <?= icon('eye', '', 17) ?> Save &amp; preview
                    </button>
                </div>
            </div>

            <div class="card-dp">
                <div class="card-dp__head"><h3>Template</h3></div>
                <div class="card-dp__body">
                    <div class="template-grid">
                        <?php foreach ($templates as $template): ?>
                            <?php $locked = !$all_templates && (int) $template['is_basic'] !== 1; ?>
                            <div class="template-card <?= $locked ? 'locked' : '' ?>">
                                <input type="radio" id="tpl-<?= e((string) $template['slug']) ?>" name="template"
                                       value="<?= e((string) $template['slug']) ?>"
                                    <?= (string) $document['template'] === (string) $template['slug'] ? 'checked' : '' ?>
                                    <?= $locked ? 'disabled' : '' ?>>
                                <label for="tpl-<?= e((string) $template['slug']) ?>">
                                    <div class="template-card__preview" style="border-top:4px solid <?= e((string) $template['accent_color']) ?>">
                                        <i style="width:44%;height:7px;background:<?= e((string) $template['accent_color']) ?>"></i>
                                        <i style="width:76%;height:5px"></i>
                                        <i style="width:64%;height:5px"></i>
                                        <i style="width:88%;height:5px"></i>
                                    </div>
                                    <div class="template-card__meta">
                                        <strong><?= e((string) $template['name']) ?></strong>
                                        <span><?= $locked ? 'Paid plans only' : e(str_excerpt((string) $template['description'], 34)) ?></span>
                                    </div>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="card-dp">
                <div class="card-dp__body">
                    <p class="small-caps mb-2">Business details on this document</p>
                    <dl class="kv mb-0">
                        <dt>Business</dt><dd><?= e((string) ($profile['business_name'] ?? '—') ?: '—') ?></dd>
                        <dt>GSTIN</dt><dd><?= e((string) ($profile['gstin'] ?? '—') ?: '—') ?></dd>
                        <dt>Bank</dt><dd><?= e((string) ($profile['bank_name'] ?? '—') ?: '—') ?></dd>
                    </dl>
                    <a href="<?= e(url('profile/business')) ?>" class="btn-dp btn-ghost-dp btn-sm-dp mt-2">
                        <?= icon('edit', '', 15) ?> Edit business profile
                    </a>
                </div>
            </div>
        </div>
    </div>
</form>

<div class="d-flex flex-wrap gap-2 mt-3">
    <form method="post" action="<?= e(url('documents/' . $documentId . '/duplicate')) ?>">
        <?= csrf_field() ?>
        <button type="submit" class="btn-dp btn-outline-dp btn-sm-dp"><?= icon('copy', '', 15) ?> Duplicate</button>
    </form>
    <form method="post" action="<?= e(url('documents/' . $documentId . '/delete')) ?>"
          data-confirm="Delete <?= e((string) $document['document_number']) ?> permanently? This cannot be undone.">
        <?= csrf_field() ?>
        <button type="submit" class="btn-dp btn-ghost-dp btn-sm-dp" style="color:var(--dp-danger)">
            <?= icon('trash', '', 15) ?> Delete document
        </button>
    </form>
</div>
