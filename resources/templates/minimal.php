<?php
/**
 * Minimal template — typography led, generous white space, no heavy colour.
 */
require __DIR__ . '/_data.php';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= e((string) $document['document_number']) ?></title>
    <style>
        @page { margin: 0; }
        body {
            margin: 0; background: #fff; font-family: "DejaVu Sans", sans-serif;
            font-size: 10.5px; color: #111827; line-height: 1.65;
        }
        .sheet { padding: 44px 46px 34px; }
        table { width: 100%; border-collapse: collapse; }
        .type {
            font-size: 9px; letter-spacing: 3.4px; text-transform: uppercase; color: #6b7280; font-weight: bold;
        }
        h1 { font-size: 20px; margin: 4px 0 2px; font-weight: normal; letter-spacing: -.3px; }
        .num { font-size: 10px; color: #6b7280; }
        .logo { max-height: 44px; max-width: 150px; }
        .rule { border-top: 1px solid #111827; margin: 22px 0 18px; }
        .rule-light { border-top: 1px solid #e5e7eb; margin: 16px 0; }
        .label { font-size: 8px; letter-spacing: 1.6px; text-transform: uppercase; color: #9ca3af; font-weight: bold; margin-bottom: 3px; }
        .party strong { font-size: 11.5px; }
        .small { font-size: 9.6px; color: #4b5563; }
        .items th {
            font-size: 8px; letter-spacing: 1.2px; text-transform: uppercase; color: #9ca3af; font-weight: bold;
            padding: 0 8px 7px; text-align: left; border-bottom: 1px solid #111827;
        }
        .items td { padding: 9px 8px; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
        .r { text-align: right; }
        .c { text-align: center; }
        .totals td { padding: 4px 8px; font-size: 10.5px; }
        .totals .k { text-align: right; color: #6b7280; }
        .totals .v { text-align: right; width: 118px; }
        .totals .grand td { border-top: 1px solid #111827; padding-top: 9px; font-size: 13.5px; }
        .block { white-space: pre-line; font-size: 10px; color: #374151; }
        .sign { margin-top: 34px; }
        .sign-line { border-top: 1px solid #9ca3af; width: 200px; padding-top: 5px; font-size: 9.4px; color: #4b5563; }
        .footer { margin-top: 28px; text-align: center; color: #9ca3af; font-size: 8.4px; }
        .kv td { padding: 1px 0; font-size: 9.6px; }
        .kv .k { color: #9ca3af; width: 104px; }
    </style>
</head>
<body>
<div class="sheet">
    <table>
        <tr>
            <td style="width:62%;vertical-align:top">
                <div class="type"><?= e($docLabel) ?></div>
                <h1><?= e((string) $document['title']) ?></h1>
                <div class="num"><?= e((string) $document['document_number']) ?> · <?= e($issueDate) ?><?php if ($validUntil !== null): ?> · valid until <?= e($validUntil) ?><?php endif; ?></div>
            </td>
            <td style="width:38%;text-align:right;vertical-align:top">
                <?php if ($logo !== null): ?><img src="<?= e($logo) ?>" class="logo" alt=""><br><?php endif; ?>
                <div style="font-size:11.5px;font-weight:bold"><?= e($businessName) ?></div>
                <?php foreach ($bizLines as $line): ?><div class="small"><?= e($line) ?></div><?php endforeach; ?>
                <?php foreach ($bizContact as $line): ?><div class="small"><?= e($line) ?></div><?php endforeach; ?>
                <?php foreach ($bizTax as $line): ?><div class="small"><?= e($line) ?></div><?php endforeach; ?>
            </td>
        </tr>
    </table>

    <div class="rule"></div>

    <table>
        <tr>
            <td style="width:52%;vertical-align:top" class="party">
                <div class="label">Prepared for</div>
                <strong><?= e((string) ($document['client_name'] ?? '')) ?></strong>
                <?php foreach ($clientLines as $line): ?><div class="small"><?= nl2br(e($line)) ?></div><?php endforeach; ?>
            </td>
            <td style="width:48%;vertical-align:top">
                <?php if (!empty($document['summary'])): ?>
                    <div class="label">Scope</div>
                    <div class="block"><?= nl2br(e((string) $document['summary'])) ?></div>
                <?php endif; ?>
            </td>
        </tr>
    </table>

    <table class="items" style="margin-top:22px">
        <thead>
        <tr>
            <th>Description</th>
            <th class="c" style="width:58px">Qty</th>
            <th style="width:62px">Unit</th>
            <th class="r" style="width:84px">Rate</th>
            <?php if ($taxColumn): ?><th class="c" style="width:52px">Tax</th><?php endif; ?>
            <th class="r" style="width:96px">Amount</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $item): ?>
            <tr>
                <td><?= nl2br(e((string) $item['description'])) ?></td>
                <td class="c"><?= e($number($item['quantity'])) ?></td>
                <td class="small"><?= e((string) $item['unit']) ?></td>
                <td class="r"><?= e($money($item['rate'])) ?></td>
                <?php if ($taxColumn): ?><td class="c"><?= e($number($item['tax_percent'])) ?>%</td><?php endif; ?>
                <td class="r"><?= e($money($item['line_subtotal'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <table class="totals" style="margin-top:12px">
        <tr>
            <td style="width:56%"></td>
            <td>
                <table>
                    <tr><td class="k">Subtotal</td><td class="v"><?= e($money($document['subtotal'])) ?></td></tr>
                    <?php if ($hasDiscount): ?>
                        <tr>
                            <td class="k">Discount<?= (string) $document['discount_type'] === 'percent' ? ' (' . e($number($document['discount_value'])) . '%)' : '' ?></td>
                            <td class="v">− <?= e($money($document['discount_total'])) ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php if ($hasTax): ?>
                        <tr><td class="k">Tax</td><td class="v"><?= e($money($document['tax_total'])) ?></td></tr>
                    <?php endif; ?>
                    <tr class="grand">
                        <td class="k" style="color:#111827">Total</td>
                        <td class="v" style="font-weight:bold"><?= e($money($document['total'])) ?></td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <?php if (!empty($document['notes'])): ?>
        <div class="rule-light"></div>
        <div class="label">Notes</div>
        <div class="block"><?= nl2br(e((string) $document['notes'])) ?></div>
    <?php endif; ?>

    <?php if ($hasBank): ?>
        <div class="rule-light"></div>
        <div class="label">Payment details</div>
        <table class="kv" style="width:auto">
            <?php if (!empty($profile['bank_name'])): ?><tr><td class="k">Bank</td><td><?= e((string) $profile['bank_name']) ?></td></tr><?php endif; ?>
            <?php if (!empty($profile['account_name'])): ?><tr><td class="k">Account name</td><td><?= e((string) $profile['account_name']) ?></td></tr><?php endif; ?>
            <?php if (!empty($profile['account_number'])): ?><tr><td class="k">Account no.</td><td><?= e((string) $profile['account_number']) ?></td></tr><?php endif; ?>
            <?php if (!empty($profile['ifsc'])): ?><tr><td class="k">IFSC / SWIFT</td><td><?= e((string) $profile['ifsc']) ?></td></tr><?php endif; ?>
        </table>
    <?php endif; ?>

    <?php if (!empty($document['terms'])): ?>
        <div class="rule-light"></div>
        <div class="label">Terms &amp; conditions</div>
        <div class="block"><?= nl2br(e((string) $document['terms'])) ?></div>
    <?php endif; ?>

    <div class="sign">
        <div class="sign-line">
            <?= e($signatureName) ?><br>
            <span style="color:#9ca3af">Authorised signatory</span>
        </div>
    </div>

    <div class="footer"><?= e($businessName) ?> · <?= e((string) $document['document_number']) ?> · Generated with <?= e(app_name()) ?></div>
</div>
</body>
</html>
