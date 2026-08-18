<?php
/**
 * @var array $document
 * @var array $items
 * @var array $profile
 * @var array|null $share
 * @var string|null $share_url
 * @var bool $pdf_exists
 * @var bool $pdf_available
 * @var array $emails
 * @var array{allowed:bool,message:string} $can_email
 */
$documentId = (int) $document['id'];
$currency = (string) $document['currency'];
$shareActive = $share !== null && (int) $share['is_active'] === 1;
?>
<div class="page-head">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
            <span class="badge badge-primary mono"><?= e((string) $document['document_number']) ?></span>
            <span class="<?= status_class((string) $document['status']) ?>"><?= e((string) $document['status']) ?></span>
            <span class="badge badge-muted"><?= e(document_type_label((string) $document['document_type'])) ?></span>
            <?php if ($shareActive): ?><span class="badge badge-info"><?= icon('link', '', 12) ?> Shared</span><?php endif; ?>
        </div>
        <h1><?= e((string) $document['title']) ?></h1>
        <p>
            For <?= e((string) ($document['client_name'] ?? 'client')) ?>
            <?= !empty($document['client_company']) ? '· ' . e((string) $document['client_company']) : '' ?>
            · <?= e(format_date((string) $document['issue_date'])) ?>
        </p>
    </div>
    <div class="btn-group-dp">
        <a href="<?= e(url('documents/' . $documentId . '/edit')) ?>" class="btn-dp btn-outline-dp"><?= icon('edit', '', 17) ?> Edit</a>
        <a href="<?= e(url('documents/' . $documentId . '/download')) ?>" class="btn-dp btn-primary-dp"><?= icon('download', '', 17) ?> Download PDF</a>
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-8">
        <div class="card-dp">
            <div class="card-dp__head">
                <h2>Preview</h2>
                <div class="btn-group-dp">
                    <a href="<?= e(url('documents/' . $documentId . '/preview')) ?>" target="_blank" rel="noopener"
                       class="btn-dp btn-ghost-dp btn-sm-dp"><?= icon('external', '', 15) ?> Open in new tab</a>
                    <form method="post" action="<?= e(url('documents/' . $documentId . '/pdf')) ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn-dp btn-outline-dp btn-sm-dp">
                            <?= icon('refresh', '', 15) ?> <?= $pdf_exists ? 'Regenerate PDF' : 'Generate PDF' ?>
                        </button>
                    </form>
                </div>
            </div>
            <div class="card-dp__body">
                <?php if (!$pdf_available): ?>
                    <div class="alert-dp alert-warning-dp">
                        <?= icon('alert') ?>
                        <div>Dompdf is not installed, so PDF export is unavailable. Run <code>composer install</code> on the server.</div>
                    </div>
                <?php endif; ?>
                <iframe class="preview-frame" src="<?= e(url('documents/' . $documentId . '/preview')) ?>"
                        title="Document preview" loading="lazy"></iframe>
            </div>
        </div>
    </div>

    <div class="col-xl-4">
        <div class="card-dp">
            <div class="card-dp__head"><h3>Summary</h3></div>
            <div class="card-dp__body">
                <dl class="kv mb-0">
                    <dt>Subtotal</dt><dd><?= e(money((float) $document['subtotal'], $currency)) ?></dd>
                    <?php if ((float) $document['discount_total'] > 0): ?>
                        <dt>Discount</dt>
                        <dd>− <?= e(money((float) $document['discount_total'], $currency)) ?>
                            <span class="text-muted-2">(<?= (string) $document['discount_type'] === 'percent'
                                    ? e(rtrim(rtrim(number_format((float) $document['discount_value'], 2), '0'), '.')) . '%'
                                    : 'fixed' ?>)</span>
                        </dd>
                    <?php endif; ?>
                    <dt>Tax</dt><dd><?= e(money((float) $document['tax_total'], $currency)) ?></dd>
                    <dt class="fw-650 text-ink">Total</dt>
                    <dd class="fw-650 text-ink" style="font-size:1.1rem"><?= e(money((float) $document['total'], $currency)) ?></dd>
                    <dt>Items</dt><dd><?= count($items) ?></dd>
                    <dt>Template</dt><dd class="text-capitalize"><?= e((string) $document['template']) ?></dd>
                    <?php if (!empty($document['valid_until'])): ?>
                        <dt>Valid until</dt><dd><?= e(format_date((string) $document['valid_until'])) ?></dd>
                    <?php endif; ?>
                    <?php if (!empty($document['pdf_generated_at'])): ?>
                        <dt>PDF built</dt><dd><?= e(format_date((string) $document['pdf_generated_at'], 'd M Y, H:i')) ?></dd>
                    <?php endif; ?>
                    <?php if (!empty($document['sent_at'])): ?>
                        <dt>Emailed</dt><dd><?= e(format_date((string) $document['sent_at'], 'd M Y, H:i')) ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>

        <div class="card-dp">
            <div class="card-dp__head"><h3>Send &amp; share</h3></div>
            <div class="card-dp__body d-grid gap-2">
                <?php if ($can_email['allowed']): ?>
                    <a href="<?= e(url('documents/' . $documentId . '/send')) ?>" class="btn-dp btn-primary-dp justify-content-start">
                        <?= icon('send', '', 17) ?> Send to client
                    </a>
                <?php else: ?>
                    <a href="<?= e(url('pricing')) ?>" class="btn-dp btn-soft-dp justify-content-start">
                        <?= icon('zap', '', 17) ?> Email delivery — upgrade
                    </a>
                    <p class="field-hint mb-0"><?= e($can_email['message']) ?></p>
                <?php endif; ?>

                <?php if ($shareActive && $share_url !== null): ?>
                    <div class="d-flex gap-2">
                        <input type="text" class="input-dp mono" readonly value="<?= e($share_url) ?>" style="font-size:.78rem">
                        <button type="button" class="btn-dp btn-outline-dp" data-copy="<?= e($share_url) ?>" title="Copy link"><?= icon('copy', '', 16) ?></button>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="<?= e($share_url) ?>" target="_blank" rel="noopener" class="btn-dp btn-outline-dp btn-sm-dp flex-grow-1">
                            <?= icon('eye', '', 15) ?> View public page
                        </a>
                        <form method="post" action="<?= e(url('documents/' . $documentId . '/unshare')) ?>" class="flex-grow-1">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn-dp btn-ghost-dp btn-sm-dp btn-block-dp" style="color:var(--dp-danger)">
                                <?= icon('x', '', 15) ?> Disable link
                            </button>
                        </form>
                    </div>
                    <p class="field-hint mb-0"><?= (int) $share['views'] ?> view<?= (int) $share['views'] === 1 ? '' : 's' ?> so far</p>
                <?php else: ?>
                    <form method="post" action="<?= e(url('documents/' . $documentId . '/share')) ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn-dp btn-outline-dp btn-block-dp justify-content-start">
                            <?= icon('share', '', 17) ?> Create public link
                        </button>
                    </form>
                    <p class="field-hint mb-0">Generates a secure, unguessable link your client can open without signing in.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card-dp">
            <div class="card-dp__head"><h3>Status</h3></div>
            <div class="card-dp__body">
                <form method="post" action="<?= e(url('documents/' . $documentId . '/status')) ?>" class="d-flex gap-2">
                    <?= csrf_field() ?>
                    <select name="status" class="select-dp">
                        <?php foreach (['draft' => 'Draft', 'final' => 'Final', 'sent' => 'Sent'] as $key => $label): ?>
                            <option value="<?= e($key) ?>" <?= (string) $document['status'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn-dp btn-outline-dp">Update</button>
                </form>
            </div>
            <div class="card-dp__foot d-flex flex-wrap gap-2">
                <form method="post" action="<?= e(url('documents/' . $documentId . '/duplicate')) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn-dp btn-ghost-dp btn-sm-dp"><?= icon('copy', '', 15) ?> Duplicate</button>
                </form>
                <form method="post" action="<?= e(url('documents/' . $documentId . '/delete')) ?>"
                      data-confirm="Delete <?= e((string) $document['document_number']) ?> permanently?">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn-dp btn-ghost-dp btn-sm-dp" style="color:var(--dp-danger)">
                        <?= icon('trash', '', 15) ?> Delete
                    </button>
                </form>
            </div>
        </div>

        <?php if ($emails !== []): ?>
            <div class="card-dp">
                <div class="card-dp__head"><h3>Email history</h3></div>
                <div class="card-dp__body">
                    <?php foreach ($emails as $log): ?>
                        <div class="d-flex justify-content-between gap-2 py-1" style="font-size:.86rem">
                            <div>
                                <div><?= e((string) $log['to_email']) ?></div>
                                <div class="text-muted-2"><?= e(format_date((string) $log['created_at'], 'd M Y, H:i')) ?></div>
                            </div>
                            <span class="<?= status_class((string) $log['status'] === 'sent' ? 'sent' : 'failed') ?>">
                                <?= e((string) $log['status']) ?>
                            </span>
                        </div>
                        <?php if ((string) $log['status'] === 'failed' && !empty($log['error_message'])): ?>
                            <p class="field-error mb-2"><?= e(str_excerpt((string) $log['error_message'], 120)) ?></p>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
