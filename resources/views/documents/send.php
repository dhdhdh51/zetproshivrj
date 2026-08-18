<?php
/**
 * @var array $document
 * @var array $profile
 * @var array{allowed:bool,message:string} $can_email
 * @var bool  $smtp_ready
 * @var bool  $ai_ready
 * @var string $default_subject
 * @var array $emails
 */
$documentId = (int) $document['id'];
$business = trim((string) ($profile['business_name'] ?? '')) !== '' ? (string) $profile['business_name'] : app_name();
$defaultMessage = old('message') !== '' ? (string) old('message') : sprintf(
    "Hi %s,\n\nPlease find attached the %s %s for your review.\n\nThe total value is %s. Let me know if you would like any changes — I'm happy to adjust the scope or timeline.\n\nThank you,\n%s\n%s",
    (string) ($document['client_name'] ?? 'there'),
    strtolower(document_type_label((string) $document['document_type'])),
    (string) $document['document_number'],
    money((float) $document['total'], (string) $document['currency']),
    trim((string) ($profile['signature_name'] ?? '')) !== '' ? (string) $profile['signature_name'] : $business,
    $business
);
?>
<div class="page-head">
    <div>
        <h1>Send to client</h1>
        <p>
            <?= e(document_type_label((string) $document['document_type'])) ?>
            <span class="mono"><?= e((string) $document['document_number']) ?></span> ·
            <?= e(money((float) $document['total'], (string) $document['currency'])) ?>
        </p>
    </div>
    <a href="<?= e(url('documents/' . $documentId)) ?>" class="btn-dp btn-ghost-dp"><?= icon('arrow-left', '', 17) ?> Back to document</a>
</div>

<?php if (!$can_email['allowed']): ?>
    <div class="card-dp">
        <div class="empty-state">
            <div class="empty-state__icon"><?= icon('mail', '', 26) ?></div>
            <h3>Email delivery is a paid feature</h3>
            <p><?= e($can_email['message']) ?></p>
            <a href="<?= e(url('pricing')) ?>" class="btn-dp btn-primary-dp"><?= icon('zap', '', 17) ?> See plans</a>
        </div>
    </div>
    <?php return; ?>
<?php endif; ?>

<?php if (!$smtp_ready): ?>
    <div class="alert-dp alert-warning-dp">
        <?= icon('alert') ?>
        <div>
            SMTP is not configured, so sending will fail. An administrator can set it up in
            <strong>Admin &rsaquo; Email Settings</strong>. You can still share a public link or download the PDF.
        </div>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card-dp">
            <div class="card-dp__head">
                <h2><?= icon('send', '', 18) ?> Compose email</h2>
                <?php if ($ai_ready): ?>
                    <button type="button" class="btn-dp btn-soft-dp btn-sm-dp" data-ai-email data-document-id="<?= $documentId ?>">
                        <?= icon('sparkles', '', 15) ?> Draft with AI
                    </button>
                <?php endif; ?>
            </div>

            <form method="post" action="<?= e(url('documents/' . $documentId . '/send')) ?>" novalidate>
                <?= csrf_field() ?>
                <div class="card-dp__body">
                    <div class="form-row">
                        <label class="form-label-dp" for="email">Recipient email</label>
                        <input type="email" id="email" name="email" required
                               class="input-dp <?= has_error('email') ? 'is-invalid-dp' : '' ?>"
                               value="<?= e(old('email') !== '' ? old('email') : (string) ($document['client_email'] ?? '')) ?>"
                               placeholder="client@company.com">
                        <?php if (has_error('email')): ?><p class="field-error"><?= e(error_for('email')) ?></p><?php endif; ?>
                    </div>

                    <div class="form-row">
                        <label class="form-label-dp" for="subject">Subject</label>
                        <input type="text" id="subject" name="subject" required
                               class="input-dp <?= has_error('subject') ? 'is-invalid-dp' : '' ?>"
                               value="<?= e(old('subject') !== '' ? old('subject') : $default_subject) ?>">
                        <?php if (has_error('subject')): ?><p class="field-error"><?= e(error_for('subject')) ?></p><?php endif; ?>
                    </div>

                    <div class="form-row mb-0">
                        <label class="form-label-dp" for="message">Message</label>
                        <textarea id="message" name="message" class="textarea-dp <?= has_error('message') ? 'is-invalid-dp' : '' ?>"
                                  rows="10" required data-counter="#message-count"><?= e($defaultMessage) ?></textarea>
                        <div class="d-flex justify-content-between">
                            <?php if (has_error('message')): ?>
                                <p class="field-error mb-0"><?= e(error_for('message')) ?></p>
                            <?php else: ?>
                                <p class="field-hint mb-0">The latest PDF is generated and attached automatically.</p>
                            <?php endif; ?>
                            <span class="field-hint" id="message-count"></span>
                        </div>
                        <?php if ($ai_ready): ?>
                            <div class="ai-tools">
                                <button type="button" class="ai-tool" data-ai-action="improve" data-ai-target="#message" data-document-id="<?= $documentId ?>"><?= icon('sparkles', '', 14) ?> Improve</button>
                                <button type="button" class="ai-tool" data-ai-action="professional" data-ai-target="#message" data-document-id="<?= $documentId ?>">Make professional</button>
                                <button type="button" class="ai-tool" data-ai-action="shorter" data-ai-target="#message" data-document-id="<?= $documentId ?>">Make shorter</button>
                                <button type="button" class="ai-tool" data-ai-action="grammar" data-ai-target="#message" data-document-id="<?= $documentId ?>">Fix grammar</button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-dp__foot d-flex flex-wrap gap-2">
                    <button type="submit" class="btn-dp btn-primary-dp"><?= icon('send', '', 17) ?> Generate PDF &amp; send</button>
                    <a href="<?= e(url('documents/' . $documentId)) ?>" class="btn-dp btn-ghost-dp">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card-dp">
            <div class="card-dp__head"><h3>What happens next</h3></div>
            <div class="card-dp__body">
                <ol class="ps-3 mb-0" style="font-size:.9rem">
                    <li class="mb-2">A fresh PDF is generated from the current document.</li>
                    <li class="mb-2">The PDF is attached to your email and sent over SMTP.</li>
                    <li class="mb-2">The result is written to the email log — success is only reported when the mail server accepts it.</li>
                    <li>The document status changes to <strong>Sent</strong>.</li>
                </ol>
            </div>
        </div>

        <div class="card-dp">
            <div class="card-dp__head"><h3>Sender</h3></div>
            <div class="card-dp__body">
                <dl class="kv mb-0">
                    <dt>From</dt><dd><?= e(App\Core\Settings::string('smtp_from_email') ?: 'Not configured') ?></dd>
                    <dt>Reply-to</dt><dd><?= e((string) ($profile['email'] ?? '') ?: '—') ?></dd>
                    <dt>Business</dt><dd><?= e($business) ?></dd>
                </dl>
            </div>
        </div>

        <?php if ($emails !== []): ?>
            <div class="card-dp">
                <div class="card-dp__head"><h3>Previous sends</h3></div>
                <div class="card-dp__body">
                    <?php foreach ($emails as $log): ?>
                        <div class="d-flex justify-content-between gap-2 py-1" style="font-size:.86rem">
                            <span><?= e((string) $log['to_email']) ?></span>
                            <span class="<?= status_class((string) $log['status'] === 'sent' ? 'sent' : 'failed') ?>"><?= e((string) $log['status']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
