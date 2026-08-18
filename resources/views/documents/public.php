<?php
/**
 * Public (token protected) document page.
 *
 * @var array $document
 * @var array $profile
 * @var string $token
 * @var string $document_html
 */
$business = trim((string) ($profile['business_name'] ?? '')) !== '' ? (string) $profile['business_name'] : app_name();
?>
<div class="share-wrap">
    <div class="share-toolbar no-print">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                <span class="badge badge-primary mono"><?= e((string) $document['document_number']) ?></span>
                <span class="badge badge-muted"><?= e(document_type_label((string) $document['document_type'])) ?></span>
            </div>
            <h1 style="font-size:1.3rem;margin:0"><?= e((string) $document['title']) ?></h1>
            <p class="text-muted-2 mb-0" style="font-size:.9rem">
                From <?= e($business) ?> · <?= e(format_date((string) $document['issue_date'])) ?>
                · Total <strong><?= e(money((float) $document['total'], (string) $document['currency'])) ?></strong>
            </p>
        </div>
        <div class="btn-group-dp">
            <button type="button" class="btn-dp btn-outline-dp" onclick="window.print()"><?= icon('file-text', '', 17) ?> Print</button>
            <a href="<?= e(url('documents/share/' . $token . '/download')) ?>" class="btn-dp btn-primary-dp">
                <?= icon('download', '', 17) ?> Download PDF
            </a>
        </div>
    </div>

    <div class="share-doc">
        <div class="share-doc__inner">
            <?= $document_html ?>
        </div>
    </div>

    <p class="text-center text-muted-2 mt-4 mb-0 no-print" style="font-size:.85rem">
        Shared securely with <a href="<?= e(url('/')) ?>"><?= e(app_name()) ?></a> ·
        <?= !empty($profile['email']) ? 'Questions? Email ' . e((string) $profile['email']) : 'Please contact the sender with any questions.' ?>
    </p>
</div>
