<?php
/** @var string $message */
/** @var array $document */
/** @var array $profile */
/** @var string $share_url */
$business = trim((string) ($profile['business_name'] ?? '')) !== '' ? (string) $profile['business_name'] : app_name();
?>
<h1 style="margin:0 0 6px;font-size:21px;"><?= e(document_type_label((string) $document['document_type'])) ?> <?= e((string) $document['document_number']) ?></h1>
<p style="margin:0 0 22px;color:#64748b;font-size:14px;">From <?= e($business) ?></p>

<?php if (trim($message) !== ''): ?>
    <div style="margin:0 0 22px;white-space:pre-line;"><?= nl2br(e($message)) ?></div>
<?php endif; ?>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0;border-radius:10px;margin:0 0 22px;">
    <tr>
        <td style="padding:14px 16px;border-bottom:1px solid #e2e8f0;font-size:14px;">
            <strong><?= e((string) $document['title']) ?></strong>
        </td>
    </tr>
    <tr>
        <td style="padding:14px 16px;font-size:14px;color:#334155;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;">
                <tr>
                    <td style="padding:3px 0;color:#64748b;">Document number</td>
                    <td style="padding:3px 0;text-align:right;"><?= e((string) $document['document_number']) ?></td>
                </tr>
                <tr>
                    <td style="padding:3px 0;color:#64748b;">Date</td>
                    <td style="padding:3px 0;text-align:right;"><?= e(format_date((string) $document['issue_date'])) ?></td>
                </tr>
                <tr>
                    <td style="padding:3px 0;color:#64748b;">Total</td>
                    <td style="padding:3px 0;text-align:right;font-weight:700;"><?= e(money((float) $document['total'], (string) $document['currency'])) ?></td>
                </tr>
            </table>
        </td>
    </tr>
</table>

<p style="margin:0 0 22px;font-size:14px;">The full document is attached as a PDF.</p>

<?php if ($share_url !== ''): ?>
    <p style="margin:0 0 26px;">
        <a href="<?= e($share_url) ?>" style="display:inline-block;background:#4f46e5;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:9px;font-weight:600;">View online</a>
    </p>
<?php endif; ?>

<p style="margin:0;font-size:13px;color:#64748b;">
    <?php if (!empty($profile['phone'])): ?>Questions? Call <?= e((string) $profile['phone']) ?>. <?php endif; ?>
    <?php if (!empty($profile['email'])): ?>Or reply to this email.<?php endif; ?>
</p>
