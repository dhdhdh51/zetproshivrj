<?php
/**
 * @var array $document
 * @var array $items
 * @var array|null $owner
 */
$id = (int) $document['id'];
$currency = (string) $document['currency'];
?>
<div class="page-head">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
            <span class="badge badge-primary mono"><?= e((string) $document['document_number']) ?></span>
            <span class="<?= status_class((string) $document['status']) ?>"><?= e((string) $document['status']) ?></span>
            <span class="badge badge-muted"><?= e(document_type_label((string) $document['document_type'])) ?></span>
            <?php if ((int) $document['ai_generated'] === 1): ?><span class="badge badge-muted"><?= icon('sparkles', '', 12) ?> AI</span><?php endif; ?>
        </div>
        <h1><?= e((string) $document['title']) ?></h1>
        <p>
            Owner:
            <?php if ($owner !== null): ?>
                <a href="<?= e(url('admin/users/' . (int) $owner['id'])) ?>"><?= e((string) $owner['name']) ?> · <?= e((string) $owner['email']) ?></a>
            <?php else: ?>
                deleted user
            <?php endif; ?>
        </p>
    </div>
    <div class="btn-group-dp">
        <a href="<?= e(url('admin/documents/' . $id . '/preview')) ?>" target="_blank" rel="noopener" class="btn-dp btn-outline-dp">
            <?= icon('external', '', 17) ?> Open preview
        </a>
        <a href="<?= e(url('admin/documents')) ?>" class="btn-dp btn-ghost-dp"><?= icon('arrow-left', '', 17) ?> Back</a>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card-dp">
            <div class="card-dp__head"><h3>Line items</h3></div>
            <div class="table-wrap">
                <table class="table-dp">
                    <thead><tr><th>#</th><th>Description</th><th class="num">Qty</th><th>Unit</th><th class="num">Rate</th><th class="num">Tax %</th><th class="num">Amount</th></tr></thead>
                    <tbody>
                    <?php foreach ($items as $index => $item): ?>
                        <tr>
                            <td class="text-muted-2"><?= $index + 1 ?></td>
                            <td><?= e(str_excerpt((string) $item['description'], 60)) ?></td>
                            <td class="num"><?= e(rtrim(rtrim(number_format((float) $item['quantity'], 2), '0'), '.')) ?></td>
                            <td><?= e((string) $item['unit']) ?></td>
                            <td class="num"><?= e(money((float) $item['rate'], $currency)) ?></td>
                            <td class="num"><?= e(rtrim(rtrim(number_format((float) $item['tax_percent'], 2), '0'), '.')) ?></td>
                            <td class="num fw-650"><?= e(money((float) $item['line_subtotal'], $currency)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-dp__foot">
                <div class="row g-2" style="max-width:420px;margin-left:auto">
                    <div class="col-12">
                        <div class="totals-box">
                            <div class="totals-row"><span>Subtotal</span><span><?= e(money((float) $document['subtotal'], $currency)) ?></span></div>
                            <div class="totals-row"><span>Discount</span><span>− <?= e(money((float) $document['discount_total'], $currency)) ?></span></div>
                            <div class="totals-row"><span>Tax</span><span><?= e(money((float) $document['tax_total'], $currency)) ?></span></div>
                            <div class="totals-row grand"><span>Total</span><span><?= e(money((float) $document['total'], $currency)) ?></span></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($document['notes']) || !empty($document['terms'])): ?>
            <div class="card-dp">
                <div class="card-dp__body">
                    <?php if (!empty($document['notes'])): ?>
                        <p class="small-caps mb-1">Notes</p>
                        <p style="white-space:pre-line"><?= e((string) $document['notes']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($document['terms'])): ?>
                        <p class="small-caps mb-1 mt-3">Terms &amp; conditions</p>
                        <p class="mb-0" style="white-space:pre-line"><?= e((string) $document['terms']) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-4">
        <div class="card-dp">
            <div class="card-dp__head"><h3>Details</h3></div>
            <div class="card-dp__body">
                <dl class="kv mb-0">
                    <dt>Client</dt><dd><?= e((string) ($document['client_name'] ?? '—')) ?></dd>
                    <dt>Company</dt><dd><?= e((string) ($document['client_company'] ?? '—') ?: '—') ?></dd>
                    <dt>Email</dt><dd><?= e((string) ($document['client_email'] ?? '—') ?: '—') ?></dd>
                    <dt>Issue date</dt><dd><?= e(format_date((string) $document['issue_date'])) ?></dd>
                    <dt>Valid until</dt><dd><?= e(format_date($document['valid_until'] ?? null)) ?></dd>
                    <dt>Template</dt><dd class="text-capitalize"><?= e((string) $document['template']) ?></dd>
                    <dt>PDF</dt><dd><?= empty($document['pdf_path']) ? 'Not generated' : e(format_date((string) $document['pdf_generated_at'], 'd M Y, H:i')) ?></dd>
                    <dt>Emailed</dt><dd><?= e(format_date($document['sent_at'] ?? null, 'd M Y, H:i')) ?></dd>
                    <dt>Created</dt><dd><?= e(format_date((string) $document['created_at'], 'd M Y, H:i')) ?></dd>
                </dl>
            </div>
            <div class="card-dp__foot">
                <form method="post" action="<?= e(url('admin/documents/' . $id . '/delete')) ?>"
                      data-confirm="Permanently delete this document and its PDF?">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn-dp btn-ghost-dp btn-sm-dp" style="color:var(--dp-danger)">
                        <?= icon('trash', '', 15) ?> Delete document
                    </button>
                </form>
            </div>
        </div>

        <?php if (!empty($document['ai_prompt'])): ?>
            <div class="card-dp">
                <div class="card-dp__head"><h3>Original AI request</h3></div>
                <div class="card-dp__body">
                    <p class="mb-0 text-muted-2" style="font-size:.88rem;white-space:pre-line"><?= e((string) $document['ai_prompt']) ?></p>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
