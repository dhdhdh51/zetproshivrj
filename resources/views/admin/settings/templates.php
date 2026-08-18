<?php
/** @var array $templates */
?>
<div class="page-head">
    <div>
        <h1>Document templates</h1>
        <p>Activate templates and choose the default used for new documents.</p>
    </div>
</div>

<div class="row g-3">
    <?php foreach ($templates as $template): ?>
        <div class="col-md-6 col-xl-4">
            <div class="card-dp h-100">
                <div class="template-card__preview" style="height:130px;border-top:5px solid <?= e((string) $template['accent_color']) ?>;border-bottom:1px solid var(--dp-line);padding:16px">
                    <i style="width:46%;height:9px;background:<?= e((string) $template['accent_color']) ?>;display:block;border-radius:2px;margin-bottom:9px"></i>
                    <i style="width:80%;height:6px;background:#cbd5e1;display:block;border-radius:2px;margin-bottom:6px"></i>
                    <i style="width:68%;height:6px;background:#cbd5e1;display:block;border-radius:2px;margin-bottom:6px"></i>
                    <i style="width:88%;height:6px;background:#cbd5e1;display:block;border-radius:2px;margin-bottom:6px"></i>
                    <i style="width:34%;height:6px;background:#cbd5e1;display:block;border-radius:2px;margin-left:auto"></i>
                </div>

                <div class="card-dp__body">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div>
                            <h3 class="mb-1"><?= e((string) $template['name']) ?></h3>
                            <span class="mono text-muted-2" style="font-size:.8rem"><?= e((string) $template['slug']) ?></span>
                        </div>
                        <div class="text-end">
                            <?php if ((int) $template['is_default'] === 1): ?><span class="badge badge-primary">default</span><?php endif; ?>
                            <?php if ((int) $template['is_basic'] === 1): ?><span class="badge badge-muted">free plan</span><?php endif; ?>
                            <span class="badge <?= (int) $template['is_active'] === 1 ? 'badge-success' : 'badge-danger' ?>">
                                <?= (int) $template['is_active'] === 1 ? 'active' : 'inactive' ?>
                            </span>
                        </div>
                    </div>
                    <p class="text-muted-2 mt-2 mb-0" style="font-size:.9rem"><?= e((string) $template['description']) ?></p>
                </div>

                <div class="card-dp__foot d-flex flex-wrap gap-2">
                    <?php if ((int) $template['is_default'] !== 1): ?>
                        <form method="post" action="<?= e(url('admin/templates/' . (int) $template['id'] . '/default')) ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn-dp btn-outline-dp btn-sm-dp"><?= icon('star', '', 15) ?> Make default</button>
                        </form>
                    <?php endif; ?>
                    <form method="post" action="<?= e(url('admin/templates/' . (int) $template['id'] . '/toggle')) ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn-dp btn-ghost-dp btn-sm-dp">
                            <?= (int) $template['is_active'] === 1 ? 'Deactivate' : 'Activate' ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="card-dp mt-3">
    <div class="card-dp__body">
        <p class="small-caps mb-2">About templates</p>
        <p class="text-muted-2 mb-0" style="font-size:.9rem">
            Templates live in <code>resources/templates/{slug}.php</code> and are rendered to PDF with Dompdf.
            Each one prints the business logo and details, client details, document number and date, line items,
            subtotal, tax, discount, grand total, notes, terms and a signature block.
            Templates marked <strong>free plan</strong> stay available to accounts on the Free plan; the others require a paid plan.
        </p>
    </div>
</div>
