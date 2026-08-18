<?php
/**
 * Corporate template — formal, structured, bordered tables.
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
            font-size: 10.5px; color: #1f2937; line-height: 1.5;
        }
        .sheet { padding: 30px 34px 26px; }
        table { width: 100%; border-collapse: collapse; }
        .head { border-bottom: 3px double <?= e($accent) ?>; padding-bottom: 14px; }
        .head td { vertical-align: top; }
        .biz-name { font-size: 17px; font-weight: bold; color: <?= e($accent) ?>; letter-spacing: .2px; }
        .small { font-size: 9.5px; color: #4b5563; }
        .logo { max-height: 54px; max-width: 160px; }
        .doc-title {
            margin: 18px 0 14px; text-align: center; border: 1px solid #d1d5db; background: #f9fafb; padding: 8px;
            font-size: 14px; font-weight: bold; letter-spacing: 3px; text-transform: uppercase; color: #111827;
        }
        .meta-table td, .meta-table th { border: 1px solid #d1d5db; padding: 7px 9px; font-size: 9.8px; vertical-align: top; }
        .meta-table th { background: #f3f4f6; text-align: left; width: 92px; font-weight: bold; color: #374151; }
        .section-title {
            margin: 16px 0 6px; font-size: 9px; letter-spacing: 1.2px; text-transform: uppercase;
            font-weight: bold; color: #6b7280; border-bottom: 1px solid #e5e7eb; padding-bottom: 3px;
        }
        .items th {
            border: 1px solid #d1d5db; background: <?= e($accent) ?>; color: #fff; padding: 8px 9px;
            font-size: 9px; letter-spacing: .6px; text-transform: uppercase; text-align: left;
        }
        .items td { border: 1px solid #d1d5db; padding: 8px 9px; vertical-align: top; }
        .items tr:nth-child(even) td { background: #fafafa; }
        .r { text-align: right; }
        .c { text-align: center; }
        .totals td { border: 1px solid #d1d5db; padding: 7px 9px; }
        .totals .k { background: #f9fafb; text-align: right; color: #4b5563; }
        .totals .v { text-align: right; width: 118px; font-weight: bold; }
        .totals .grand td { background: <?= e($accent) ?>; color: #fff; font-size: 12.5px; }
        .terms { white-space: pre-line; font-size: 9.8px; }
        .sign-box { border: 1px solid #d1d5db; padding: 10px 12px; height: 78px; }
        .footer { margin-top: 20px; border-top: 1px solid #e5e7eb; padding-top: 9px; text-align: center; color: #9ca3af; font-size: 8.5px; }
        .kv td { padding: 1px 0; font-size: 9.6px; border: 0; }
        .kv .k { color: #6b7280; width: 100px; }
    </style>
</head>
<body>
<div class="sheet">
    <table class="head">
        <tr>
            <td style="width:62%">
                <?php if ($logo !== null): ?><img src="<?= e($logo) ?>" class="logo" alt=""><br><?php endif; ?>
                <div class="biz-name"><?= e($businessName) ?></div>
                <?php foreach ($bizLines as $line): ?><div class="small"><?= e($line) ?></div><?php endforeach; ?>
                <?php foreach ($bizContact as $line): ?><div class="small"><?= e($line) ?></div><?php endforeach; ?>
            </td>
            <td style="width:38%" class="small">
                <?php foreach ($bizTax as $line): ?>
                    <div style="text-align:right;font-weight:bold;color:#374151"><?= e($line) ?></div>
                <?php endforeach; ?>
                <div style="text-align:right">Reference: <?= e((string) $document['document_number']) ?></div>
                <div style="text-align:right">Date: <?= e($issueDate) ?></div>
            </td>
        </tr>
    </table>

    <div class="doc-title"><?= e($docLabel) ?></div>

    <table class="meta-table">
        <tr>
            <th>To</th>
            <td>
                <strong><?= e((string) ($document['client_name'] ?? '')) ?></strong>
                <?php foreach ($clientLines as $line): ?><br><?= nl2br(e($line)) ?><?php endforeach; ?>
            </td>
            <th>Number</th>
            <td>
                <?= e((string) $document['document_number']) ?><br>
                <span class="small">Date: <?= e($issueDate) ?></span>
                <?php if ($validUntil !== null): ?><br><span class="small">Valid until: <?= e($validUntil) ?></span><?php endif; ?>
            </td>
        </tr>
        <tr>
            <th>Subject</th>
            <td colspan="3">
                <strong><?= e((string) $document['title']) ?></strong>
                <?php if (!empty($document['summary'])): ?><br><span class="small"><?= nl2br(e((string) $document['summary'])) ?></span><?php endif; ?>
            </td>
        </tr>
    </table>

    <div class="section-title">Schedule of items</div>
    <table class="items">
        <thead>
        <tr>
            <th style="width:26px">Sr.</th>
            <th>Particulars</th>
            <th class="c" style="width:56px">Qty</th>
            <th style="width:60px">Unit</th>
            <th class="r" style="width:82px">Rate</th>
            <?php if ($taxColumn): ?><th class="c" style="width:50px">Tax %</th><?php endif; ?>
            <th class="r" style="width:92px">Amount</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $index => $item): ?>
            <tr>
                <td class="c"><?= $index + 1 ?></td>
                <td><?= nl2br(e((string) $item['description'])) ?></td>
                <td class="c"><?= e($number($item['quantity'])) ?></td>
                <td><?= e((string) $item['unit']) ?></td>
                <td class="r"><?= e($money($item['rate'])) ?></td>
                <?php if ($taxColumn): ?><td class="c"><?= e($number($item['tax_percent'])) ?></td><?php endif; ?>
                <td class="r"><?= e($money($item['line_subtotal'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <table style="margin-top:12px">
        <tr>
            <td style="width:52%;vertical-align:top;padding-right:12px">
                <?php if ($hasBank): ?>
                    <div class="section-title">Payment details</div>
                    <table class="kv">
                        <?php if (!empty($profile['bank_name'])): ?><tr><td class="k">Bank</td><td><?= e((string) $profile['bank_name']) ?></td></tr><?php endif; ?>
                        <?php if (!empty($profile['account_name'])): ?><tr><td class="k">Account name</td><td><?= e((string) $profile['account_name']) ?></td></tr><?php endif; ?>
                        <?php if (!empty($profile['account_number'])): ?><tr><td class="k">Account number</td><td><?= e((string) $profile['account_number']) ?></td></tr><?php endif; ?>
                        <?php if (!empty($profile['ifsc'])): ?><tr><td class="k">IFSC / SWIFT</td><td><?= e((string) $profile['ifsc']) ?></td></tr><?php endif; ?>
                    </table>
                <?php endif; ?>
            </td>
            <td style="width:48%;vertical-align:top">
                <table class="totals">
                    <tr><td class="k">Subtotal</td><td class="v"><?= e($money($document['subtotal'])) ?></td></tr>
                    <?php if ($hasDiscount): ?>
                        <tr>
                            <td class="k">Less discount<?= (string) $document['discount_type'] === 'percent' ? ' (' . e($number($document['discount_value'])) . '%)' : '' ?></td>
                            <td class="v">− <?= e($money($document['discount_total'])) ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php if ($hasTax): ?>
                        <tr><td class="k">Add tax</td><td class="v"><?= e($money($document['tax_total'])) ?></td></tr>
                    <?php endif; ?>
                    <tr class="grand">
                        <td style="text-align:right">Total payable</td>
                        <td style="text-align:right"><?= e($money($document['total'])) ?></td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <?php if (!empty($document['notes'])): ?>
        <div class="section-title">Notes</div>
        <div class="terms"><?= nl2br(e((string) $document['notes'])) ?></div>
    <?php endif; ?>

    <?php if (!empty($document['terms'])): ?>
        <div class="section-title">Terms &amp; conditions</div>
        <div class="terms"><?= nl2br(e((string) $document['terms'])) ?></div>
    <?php endif; ?>

    <table style="margin-top:22px">
        <tr>
            <td style="width:50%;padding-right:12px">
                <div class="sign-box">
                    <div class="small" style="color:#6b7280">Accepted by (client)</div>
                </div>
            </td>
            <td style="width:50%">
                <div class="sign-box">
                    <div class="small" style="color:#6b7280">For <?= e($businessName) ?></div>
                    <div style="margin-top:38px;border-top:1px solid #9ca3af;padding-top:4px" class="small">
                        <?= e($signatureName) ?> · Authorised signatory
                    </div>
                </div>
            </td>
        </tr>
    </table>

    <div class="footer">
        This is a computer-generated <?= e(strtolower(document_type_label((string) $document['document_type']))) ?>
        issued by <?= e($businessName) ?>. Generated with <?= e(app_name()) ?>.
    </div>
</div>
</body>
</html>
