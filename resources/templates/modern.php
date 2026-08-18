<?php
/**
 * Modern template — accent header band, clean contemporary layout.
 *
 * @var array $document
 * @var array $items
 * @var array $profile
 * @var string|null $logo
 * @var string $accent
 * @var bool $for_pdf
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
        * { box-sizing: border-box; }
        body {
            margin: 0; padding: 0; background: #fff;
            font-family: "DejaVu Sans", sans-serif; font-size: 10.5px; color: #334155; line-height: 1.55;
        }
        .sheet { width: 100%; padding: 0 0 26px; }
        .band { background: <?= e($accent) ?>; padding: 26px 34px 22px; color: #fff; }
        .band td { color: #fff; vertical-align: top; }
        .biz-name { font-size: 19px; font-weight: bold; letter-spacing: -.3px; }
        .band .muted { color: rgba(255, 255, 255, .82); font-size: 9.5px; }
        .doc-type { font-size: 22px; font-weight: bold; letter-spacing: 1.2px; text-align: right; }
        .doc-meta { text-align: right; font-size: 9.5px; color: rgba(255, 255, 255, .9); }
        .logo { max-height: 52px; max-width: 170px; }
        .content { padding: 24px 34px 0; }
        .label { font-size: 8px; letter-spacing: 1.1px; text-transform: uppercase; color: #94a3b8; font-weight: bold; }
        h1.title { font-size: 15px; color: #0f172a; margin: 0 0 4px; }
        .summary { color: #475569; margin: 0; }
        table { width: 100%; border-collapse: collapse; }
        .parties td { vertical-align: top; padding-right: 18px; }
        .party-name { font-size: 12px; font-weight: bold; color: #0f172a; }
        .items { margin-top: 18px; }
        .items th {
            background: #f1f5f9; color: #475569; font-size: 8.5px; letter-spacing: .7px; text-transform: uppercase;
            padding: 9px 10px; text-align: left; border-bottom: 2px solid <?= e($accent) ?>;
        }
        .items td { padding: 9px 10px; border-bottom: 1px solid #eef1f6; vertical-align: top; }
        .items .r { text-align: right; }
        .items .c { text-align: center; }
        .desc { color: #0f172a; font-weight: 600; }
        .totals { margin-top: 14px; }
        .totals td { padding: 5px 10px; }
        .totals .k { text-align: right; color: #64748b; }
        .totals .v { text-align: right; width: 120px; color: #0f172a; font-weight: bold; }
        .grand { background: <?= e($accent) ?>; color: #fff; }
        .grand td { color: #fff; font-size: 13px; padding: 11px 10px; }
        .box { border: 1px solid #e2e8f0; border-radius: 7px; padding: 12px 14px; }
        .box h3 { margin: 0 0 5px; font-size: 9px; letter-spacing: .9px; text-transform: uppercase; color: #64748b; }
        .box p { margin: 0; white-space: pre-line; font-size: 10px; }
        .sign { margin-top: 26px; }
        .sign-line { border-top: 1px solid #94a3b8; width: 190px; padding-top: 5px; font-size: 9.5px; color: #475569; }
        .footer { margin-top: 22px; padding: 12px 34px 0; border-top: 1px solid #e2e8f0; color: #94a3b8; font-size: 8.5px; }
        .kv td { padding: 1px 0; font-size: 9.5px; }
        .kv .k { color: #64748b; width: 96px; }
    </style>
</head>
<body>
<div class="sheet">
    <div class="band">
        <table>
            <tr>
                <td style="width:58%">
                    <?php if ($logo !== null): ?>
                        <img src="<?= e($logo) ?>" class="logo" alt=""><br>
                    <?php endif; ?>
                    <div class="biz-name"><?= e($businessName) ?></div>
                    <?php foreach ($bizLines as $line): ?>
                        <div class="muted"><?= e($line) ?></div>
                    <?php endforeach; ?>
                    <?php if ($bizContact !== []): ?>
                        <div class="muted"><?= e(implode('  ·  ', $bizContact)) ?></div>
                    <?php endif; ?>
                    <?php foreach ($bizTax as $line): ?>
                        <div class="muted"><?= e($line) ?></div>
                    <?php endforeach; ?>
                </td>
                <td style="width:42%">
                    <div class="doc-type"><?= e($docLabel) ?></div>
                    <div class="doc-meta">
                        <?= e((string) $document['document_number']) ?><br>
                        Date: <?= e($issueDate) ?>
                        <?php if ($validUntil !== null): ?><br>Valid until: <?= e($validUntil) ?><?php endif; ?>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <div class="content">
        <table class="parties">
            <tr>
                <td style="width:52%">
                    <div class="label">Prepared for</div>
                    <div class="party-name"><?= e((string) ($document['client_name'] ?? '')) ?></div>
                    <?php foreach ($clientLines as $line): ?>
                        <div><?= nl2br(e($line)) ?></div>
                    <?php endforeach; ?>
                </td>
                <td style="width:48%">
                    <div class="label">Details</div>
                    <table class="kv">
                        <tr><td class="k">Document</td><td><?= e((string) $document['document_number']) ?></td></tr>
                        <tr><td class="k">Date</td><td><?= e($issueDate) ?></td></tr>
                        <?php if ($validUntil !== null): ?>
                            <tr><td class="k">Valid until</td><td><?= e($validUntil) ?></td></tr>
                        <?php endif; ?>
                        <tr><td class="k">Currency</td><td><?= e($currency) ?></td></tr>
                    </table>
                </td>
            </tr>
        </table>

        <div style="margin-top:18px">
            <h1 class="title"><?= e((string) $document['title']) ?></h1>
            <?php if (!empty($document['summary'])): ?>
                <p class="summary"><?= nl2br(e((string) $document['summary'])) ?></p>
            <?php endif; ?>
        </div>

        <table class="items">
            <thead>
            <tr>
                <th style="width:26px">#</th>
                <th>Description</th>
                <th class="c" style="width:62px">Qty</th>
                <th style="width:64px">Unit</th>
                <th class="r" style="width:84px">Rate</th>
                <?php if ($taxColumn): ?><th class="c" style="width:52px">Tax</th><?php endif; ?>
                <th class="r" style="width:92px">Amount</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $index => $item): ?>
                <tr>
                    <td style="color:#94a3b8"><?= $index + 1 ?></td>
                    <td class="desc"><?= nl2br(e((string) $item['description'])) ?></td>
                    <td class="c"><?= e($number($item['quantity'])) ?></td>
                    <td><?= e((string) $item['unit']) ?></td>
                    <td class="r"><?= e($money($item['rate'])) ?></td>
                    <?php if ($taxColumn): ?><td class="c"><?= e($number($item['tax_percent'])) ?>%</td><?php endif; ?>
                    <td class="r" style="font-weight:bold;color:#0f172a"><?= e($money($item['line_subtotal'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <table class="totals">
            <tr>
                <td style="width:55%"></td>
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
                            <td style="text-align:right">Grand total</td>
                            <td style="text-align:right;font-weight:bold"><?= e($money($document['total'])) ?></td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        <table style="margin-top:20px">
            <tr>
                <td style="width:50%;padding-right:10px;vertical-align:top">
                    <?php if (!empty($document['notes'])): ?>
                        <div class="box" style="margin-bottom:10px">
                            <h3>Notes</h3>
                            <p><?= nl2br(e((string) $document['notes'])) ?></p>
                        </div>
                    <?php endif; ?>
                    <?php if ($hasBank): ?>
                        <div class="box">
                            <h3>Payment details</h3>
                            <table class="kv">
                                <?php if (!empty($profile['bank_name'])): ?><tr><td class="k">Bank</td><td><?= e((string) $profile['bank_name']) ?></td></tr><?php endif; ?>
                                <?php if (!empty($profile['account_name'])): ?><tr><td class="k">Account name</td><td><?= e((string) $profile['account_name']) ?></td></tr><?php endif; ?>
                                <?php if (!empty($profile['account_number'])): ?><tr><td class="k">Account no.</td><td><?= e((string) $profile['account_number']) ?></td></tr><?php endif; ?>
                                <?php if (!empty($profile['ifsc'])): ?><tr><td class="k">IFSC / SWIFT</td><td><?= e((string) $profile['ifsc']) ?></td></tr><?php endif; ?>
                            </table>
                        </div>
                    <?php endif; ?>
                </td>
                <td style="width:50%;padding-left:10px;vertical-align:top">
                    <?php if (!empty($document['terms'])): ?>
                        <div class="box">
                            <h3>Terms &amp; conditions</h3>
                            <p><?= nl2br(e((string) $document['terms'])) ?></p>
                        </div>
                    <?php endif; ?>
                    <div class="sign">
                        <div class="sign-line">
                            <?= e($signatureName) ?><br>
                            <span style="color:#94a3b8">Authorised signatory</span>
                        </div>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <div class="footer">
        <?= e($businessName) ?> · <?= e((string) $document['document_number']) ?>
        <?php if (!empty($profile['email'])): ?> · <?= e((string) $profile['email']) ?><?php endif; ?>
        · Generated with <?= e(app_name()) ?>
    </div>
</div>
</body>
</html>
