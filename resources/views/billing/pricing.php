<?php
/**
 * @var array $plans
 * @var array|null $current
 * @var array|null $summary
 * @var bool $payu_ready
 * @var string $payu_mode
 */
$loggedIn = App\Core\Auth::check();
$currentSlug = (string) ($current['slug'] ?? '');
?>
<div class="<?= $loggedIn ? '' : 'section' ?>">
    <div class="<?= $loggedIn ? '' : 'container' ?>">
        <?php if ($loggedIn): ?>
            <div class="page-head">
                <div>
                    <h1>Plans &amp; pricing</h1>
                    <p>Upgrade any time — new limits apply immediately after a successful payment.</p>
                </div>
                <a href="<?= e(url('billing')) ?>" class="btn-dp btn-outline-dp"><?= icon('credit-card', '', 17) ?> Billing history</a>
            </div>
        <?php else: ?>
            <div class="section-head">
                <h2>Simple pricing that scales with your work</h2>
                <p>Start free, upgrade when you need more documents, AI generations and email delivery. Cancel any time.</p>
            </div>
        <?php endif; ?>

        <?php if ($loggedIn && $summary !== null): ?>
            <div class="card-dp mb-3">
                <div class="card-dp__body">
                    <div class="row g-3 align-items-center">
                        <div class="col-md-4">
                            <div class="small-caps">Current plan</div>
                            <div class="d-flex align-items-center gap-2">
                                <span style="font-size:1.35rem;font-weight:700" class="text-ink"><?= e((string) $current['name']) ?></span>
                                <?php if (!$current['is_free']): ?>
                                    <span class="badge badge-success">Active</span>
                                <?php endif; ?>
                            </div>
                            <?php if (!$current['is_free']): ?>
                                <div class="text-muted-2" style="font-size:.86rem">Renews <?= e(format_date($summary['renews_at'] ?? null)) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-4">
                            <div class="usage-row mb-0">
                                <div class="usage-row__top">
                                    <span>Documents</span>
                                    <strong data-usage="documents"><?= (int) $summary['documents_used'] ?> / <?= (int) $summary['documents_limit'] ?></strong>
                                </div>
                                <div class="progress-dp <?= $summary['documents_percent'] >= 100 ? 'full' : '' ?>">
                                    <span style="width:<?= (float) $summary['documents_percent'] ?>%"></span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="usage-row mb-0">
                                <div class="usage-row__top">
                                    <span>AI generations</span>
                                    <strong data-usage="ai"><?= (int) $summary['ai_used'] ?> / <?= (int) $summary['ai_limit'] ?></strong>
                                </div>
                                <div class="progress-dp <?= $summary['ai_percent'] >= 100 ? 'full' : '' ?>">
                                    <span data-usage-bar="ai" style="width:<?= (float) $summary['ai_percent'] ?>%"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($loggedIn && !$payu_ready): ?>
            <div class="alert-dp alert-warning-dp">
                <?= icon('alert') ?>
                <div>Online payments are not configured on this installation yet, so paid plans cannot be purchased. Please contact support to upgrade.</div>
            </div>
        <?php endif; ?>

        <div class="row g-4 <?= $loggedIn ? '' : 'mt-2' ?>">
            <?php foreach ($plans as $plan): ?>
                <?php $isCurrent = $currentSlug === (string) $plan['slug']; ?>
                <div class="col-md-4">
                    <div class="price-card <?= (string) $plan['slug'] === 'pro' ? 'featured' : '' ?>">
                        <div class="d-flex justify-content-between align-items-start">
                            <h3 class="mb-0"><?= e((string) $plan['name']) ?></h3>
                            <?php if ($isCurrent): ?><span class="badge badge-primary">Your plan</span><?php endif; ?>
                        </div>
                        <p class="text-muted-2" style="font-size:.9rem"><?= e((string) ($plan['description'] ?? '')) ?></p>

                        <div class="price-card__price">
                            <?= e(money((float) $plan['price'], (string) $plan['currency'])) ?>
                            <small>/<?= (string) $plan['billing_interval'] === 'yearly' ? 'year' : 'month' ?></small>
                        </div>

                        <ul>
                            <?php
                            $features = $plan['features_list'] !== [] ? $plan['features_list'] : [
                                $plan['document_limit'] . ' documents / month',
                                $plan['ai_limit'] . ' AI generations / month',
                                $plan['all_templates'] ? 'All templates' : 'Basic template',
                                $plan['pdf_enabled'] ? 'PDF export' : 'Preview only',
                                $plan['email_enabled'] ? 'Email delivery' : 'Manual sending',
                            ];
                            ?>
                            <?php foreach ($features as $feature): ?>
                                <li><?= icon('check-circle', '', 17) ?><span><?= e((string) $feature) ?></span></li>
                            <?php endforeach; ?>
                        </ul>

                        <?php if (!$loggedIn): ?>
                            <a href="<?= e(url('register')) ?>" class="btn-dp <?= (string) $plan['slug'] === 'pro' ? 'btn-primary-dp' : 'btn-outline-dp' ?> btn-block-dp">
                                <?= $plan['is_free'] ? 'Start free' : 'Get started' ?>
                            </a>
                        <?php elseif ($isCurrent): ?>
                            <button type="button" class="btn-dp btn-outline-dp btn-block-dp" disabled>Current plan</button>
                        <?php elseif ($plan['is_free']): ?>
                            <span class="btn-dp btn-ghost-dp btn-block-dp text-muted-2" style="cursor:default">Included with every account</span>
                        <?php else: ?>
                            <form method="post" action="<?= e(url('billing/checkout')) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="plan" value="<?= e((string) $plan['slug']) ?>">
                                <button type="submit" class="btn-dp <?= (string) $plan['slug'] === 'pro' ? 'btn-primary-dp' : 'btn-dark-dp' ?> btn-block-dp"
                                    <?= $payu_ready ? '' : 'disabled' ?>>
                                    <?= icon('zap', '', 17) ?> Upgrade to <?= e((string) $plan['name']) ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="text-center mt-4">
            <p class="text-muted-2 mb-1" style="font-size:.9rem">
                <?= icon('shield', '', 15) ?> Payments are processed securely by PayU<?= $loggedIn && $payu_mode === 'test' ? ' (test mode)' : '' ?>.
                Your card details never touch our servers.
            </p>
            <p class="text-muted-2" style="font-size:.85rem">
                Limits reset on the 1st of every month. Need something bigger?
                <a href="<?= e(url('contact')) ?>">Talk to us</a>.
            </p>
        </div>
    </div>
</div>
