<?php
/**
 * @var array $stats
 * @var array $summary
 * @var array $recent
 * @var int   $clients_count
 * @var array|null $profile
 * @var bool  $profile_complete
 * @var array $ai_recent
 * @var bool  $ai_ready
 * @var array|null $user
 */
$plan = $summary['plan'];
?>
<div class="page-head">
    <div>
        <h1>Welcome back, <?= e(explode(' ', trim((string) ($user['name'] ?? 'there')))[0]) ?></h1>
        <p>Here is what is happening in your <?= e(app_name()) ?> workspace.</p>
    </div>
    <div class="btn-group-dp">
        <a href="<?= e(url('documents/create')) ?>" class="btn-dp btn-primary-dp"><?= icon('sparkles', '', 17) ?> Create with AI</a>
        <a href="<?= e(url('clients/create')) ?>" class="btn-dp btn-outline-dp"><?= icon('plus', '', 17) ?> Add client</a>
    </div>
</div>

<?php if (!$profile_complete): ?>
    <div class="alert-dp alert-warning-dp">
        <?= icon('briefcase') ?>
        <div>
            <strong>Finish your business profile first.</strong><br>
            Your business name, logo, GSTIN and bank details are added to every document automatically.
            <a href="<?= e(url('profile/business')) ?>">Complete it now</a>
        </div>
    </div>
<?php endif; ?>

<?php if (!$ai_ready): ?>
    <div class="alert-dp alert-info-dp">
        <?= icon('sparkles') ?>
        <div>
            AI drafting is not available yet — an administrator needs to add an OpenRouter API key in
            <strong>Admin &rsaquo; AI Settings</strong>. You can still create and edit documents manually.
        </div>
    </div>
<?php endif; ?>

<div class="stat-grid">
    <div class="stat">
        <div class="stat__label">Total documents</div>
        <div class="stat__value"><?= number_format((int) $stats['total']) ?></div>
        <div class="stat__meta"><?= number_format((int) $stats['drafts']) ?> drafts · <?= number_format((int) $stats['sent']) ?> sent</div>
    </div>
    <div class="stat">
        <div class="stat__label">Documents this month</div>
        <div class="stat__value" data-usage="documents"><?= (int) $summary['documents_used'] ?> / <?= (int) $summary['documents_limit'] ?></div>
        <div class="progress-dp mt-2 <?= $summary['documents_percent'] >= 100 ? 'full' : ($summary['documents_percent'] >= 80 ? 'warn' : '') ?>">
            <span style="width: <?= (float) $summary['documents_percent'] ?>%"></span>
        </div>
    </div>
    <div class="stat">
        <div class="stat__label">AI generations used</div>
        <div class="stat__value" data-usage="ai"><?= (int) $summary['ai_used'] ?> / <?= (int) $summary['ai_limit'] ?></div>
        <div class="progress-dp mt-2 <?= $summary['ai_percent'] >= 100 ? 'full' : ($summary['ai_percent'] >= 80 ? 'warn' : '') ?>">
            <span data-usage-bar="ai" style="width: <?= (float) $summary['ai_percent'] ?>%"></span>
        </div>
    </div>
    <div class="stat">
        <div class="stat__label">Current plan</div>
        <div class="stat__value"><?= e((string) $plan['name']) ?></div>
        <div class="stat__meta">
            <?php if ($plan['is_free']): ?>
                <a href="<?= e(url('pricing')) ?>">Upgrade for more</a>
            <?php else: ?>
                Renews <?= e(format_date($summary['renews_at'] ?? null)) ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="row g-3 mt-3">
    <div class="col-lg-8">
        <div class="card-dp">
            <div class="card-dp__head">
                <h2>Recent documents</h2>
                <a href="<?= e(url('documents')) ?>" class="btn-dp btn-ghost-dp btn-sm-dp">View all <?= icon('arrow-right', '', 15) ?></a>
            </div>

            <?php if ($recent === []): ?>
                <div class="empty-state">
                    <div class="empty-state__icon"><?= icon('file-text', '', 26) ?></div>
                    <h3>No documents yet</h3>
                    <p>Describe what you need in plain English and let AI draft your first quotation, invoice or proposal.</p>
                    <a href="<?= e(url('documents/create')) ?>" class="btn-dp btn-primary-dp"><?= icon('sparkles', '', 17) ?> Create your first document</a>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table-dp">
                        <thead>
                        <tr>
                            <th>Document</th>
                            <th>Client</th>
                            <th>Status</th>
                            <th class="num">Total</th>
                            <th class="num">Created</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recent as $document): ?>
                            <tr>
                                <td>
                                    <a href="<?= e(url('documents/' . (int) $document['id'])) ?>" class="fw-650">
                                        <?= e((string) $document['document_number']) ?>
                                    </a>
                                    <div class="text-muted-2" style="font-size:.83rem"><?= e(str_excerpt((string) $document['title'], 46)) ?></div>
                                </td>
                                <td><?= e((string) ($document['client_name'] ?? '—')) ?></td>
                                <td><span class="<?= status_class((string) $document['status']) ?>"><?= e((string) $document['status']) ?></span></td>
                                <td class="num"><?= e(money((float) $document['total'], (string) $document['currency'])) ?></td>
                                <td class="num text-muted-2" style="font-size:.85rem"><?= e(format_date((string) $document['created_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card-dp">
            <div class="card-dp__head"><h3>Quick actions</h3></div>
            <div class="card-dp__body d-grid gap-2">
                <?php foreach (['quotation' => 'file-text', 'invoice' => 'receipt', 'proposal' => 'presentation'] as $type => $ico): ?>
                    <a href="<?= e(url('documents/create?type=' . $type)) ?>" class="btn-dp btn-outline-dp justify-content-start">
                        <?= icon($ico, '', 17) ?> Create <?= e(strtolower(document_type_label($type))) ?>
                    </a>
                <?php endforeach; ?>
                <a href="<?= e(url('documents/create')) ?>" class="btn-dp btn-soft-dp justify-content-start">
                    <?= icon('sparkles', '', 17) ?> Create any document
                </a>
            </div>
        </div>

        <div class="card-dp">
            <div class="card-dp__head"><h3>Workspace</h3></div>
            <div class="card-dp__body">
                <dl class="kv mb-0">
                    <dt>Business</dt>
                    <dd><?= e(trim((string) ($profile['business_name'] ?? '')) !== '' ? (string) $profile['business_name'] : 'Not set yet') ?></dd>
                    <dt>Clients</dt>
                    <dd><?= number_format($clients_count) ?> saved</dd>
                    <dt>Document value</dt>
                    <dd><?= e(money((float) $stats['value'], (string) ($profile['default_currency'] ?? 'INR'))) ?></dd>
                    <dt>Default template</dt>
                    <dd class="text-capitalize"><?= e((string) ($profile['default_template'] ?? 'modern')) ?></dd>
                </dl>
            </div>
            <div class="card-dp__foot d-flex gap-2">
                <a href="<?= e(url('profile/business')) ?>" class="btn-dp btn-outline-dp btn-sm-dp"><?= icon('edit', '', 15) ?> Edit profile</a>
                <a href="<?= e(url('clients')) ?>" class="btn-dp btn-ghost-dp btn-sm-dp">Clients</a>
            </div>
        </div>

        <?php if ($ai_recent !== []): ?>
            <div class="card-dp">
                <div class="card-dp__head"><h3>Recent AI activity</h3></div>
                <div class="card-dp__body">
                    <?php foreach ($ai_recent as $generation): ?>
                        <div class="d-flex justify-content-between align-items-center py-1" style="font-size:.88rem">
                            <span class="text-capitalize"><?= e(str_replace(['writing_', '_'], ['', ' '], (string) $generation['type'])) ?></span>
                            <span class="text-muted-2"><?= e(format_date((string) $generation['created_at'], 'd M, H:i')) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
