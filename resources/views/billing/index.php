<?php
/**
 * @var array $summary
 * @var array $plans
 * @var array $payments
 * @var array $subscriptions
 * @var bool  $payu_ready
 */
$plan = $summary['plan'];
?>
<div class="page-head">
    <div>
        <h1>Billing &amp; usage</h1>
        <p>Your plan, this month's usage and every payment on record.</p>
    </div>
    <a href="<?= e(url('pricing')) ?>" class="btn-dp btn-primary-dp"><?= icon('zap', '', 17) ?> <?= $plan['is_free'] ? 'Upgrade plan' : 'Change plan' ?></a>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card-dp">
            <div class="card-dp__head">
                <h2>Current plan</h2>
                <span class="badge <?= $plan['is_free'] ? 'badge-muted' : 'badge-success' ?>"><?= e((string) $plan['name']) ?></span>
            </div>
            <div class="card-dp__body">
                <div class="d-flex align-items-baseline gap-2 mb-3">
                    <span style="font-size:2rem;font-weight:700" class="text-ink"><?= e(money((float) $plan['price'], (string) $plan['currency'])) ?></span>
                    <span class="text-muted-2">/ <?= (string) $plan['billing_interval'] === 'yearly' ? 'year' : 'month' ?></span>
                </div>

                <div class="usage-row">
                    <div class="usage-row__top">
                        <span>Documents this month</span>
                        <strong data-usage="documents"><?= (int) $summary['documents_used'] ?> / <?= (int) $summary['documents_limit'] ?></strong>
                    </div>
                    <div class="progress-dp <?= $summary['documents_percent'] >= 100 ? 'full' : ($summary['documents_percent'] >= 80 ? 'warn' : '') ?>">
                        <span style="width:<?= (float) $summary['documents_percent'] ?>%"></span>
                    </div>
                </div>

                <div class="usage-row">
                    <div class="usage-row__top">
                        <span>AI generations this month</span>
                        <strong data-usage="ai"><?= (int) $summary['ai_used'] ?> / <?= (int) $summary['ai_limit'] ?></strong>
                    </div>
                    <div class="progress-dp <?= $summary['ai_percent'] >= 100 ? 'full' : ($summary['ai_percent'] >= 80 ? 'warn' : '') ?>">
                        <span data-usage-bar="ai" style="width:<?= (float) $summary['ai_percent'] ?>%"></span>
                    </div>
                </div>

                <dl class="kv mt-3 mb-0">
                    <dt>Billing period</dt><dd><?= e(format_date($summary['period'] . '-01', 'F Y')) ?></dd>
                    <dt>Emails sent</dt><dd><?= (int) $summary['emails_used'] ?></dd>
                    <dt>Templates</dt><dd><?= $plan['all_templates'] ? 'All templates' : 'Basic template only' ?></dd>
                    <dt>Email delivery</dt><dd><?= $plan['email_enabled'] ? 'Included' : 'Not included' ?></dd>
                    <?php if (!$plan['is_free']): ?>
                        <dt>Renews on</dt><dd><?= e(format_date($summary['renews_at'] ?? null)) ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>

        <div class="card-dp">
            <div class="card-dp__head"><h3>Upgrade options</h3></div>
            <div class="card-dp__body d-grid gap-2">
                <?php foreach ($plans as $option): ?>
                    <?php if ($option['is_free'] || (string) $option['slug'] === (string) $plan['slug']) { continue; } ?>
                    <form method="post" action="<?= e(url('billing/checkout')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="plan" value="<?= e((string) $option['slug']) ?>">
                        <button type="submit" class="btn-dp btn-outline-dp btn-block-dp justify-content-between" <?= $payu_ready ? '' : 'disabled' ?>>
                            <span><?= icon('zap', '', 16) ?> <?= e((string) $option['name']) ?></span>
                            <span class="fw-650"><?= e(money((float) $option['price'], (string) $option['currency'])) ?>/mo</span>
                        </button>
                    </form>
                <?php endforeach; ?>
                <?php if (!$payu_ready): ?>
                    <p class="field-hint mb-0">PayU is not configured on this installation — <a href="<?= e(url('contact')) ?>">contact support</a> to upgrade.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card-dp">
            <div class="card-dp__head"><h2>Payment history</h2></div>

            <?php if ($payments === []): ?>
                <div class="empty-state">
                    <div class="empty-state__icon"><?= icon('credit-card', '', 26) ?></div>
                    <h3>No payments yet</h3>
                    <p>You are on the Free plan. Payments will appear here after your first upgrade.</p>
                    <a href="<?= e(url('pricing')) ?>" class="btn-dp btn-primary-dp"><?= icon('zap', '', 17) ?> View plans</a>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table-dp">
                        <thead>
                        <tr><th>Reference</th><th>Plan</th><th>Status</th><th class="num">Amount</th><th class="num">Date</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td>
                                    <span class="mono" style="font-size:.82rem"><?= e((string) $payment['txnid']) ?></span>
                                    <?php if (!empty($payment['payment_mode'])): ?>
                                        <div class="text-muted-2" style="font-size:.78rem"><?= e((string) $payment['payment_mode']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= e((string) ($payment['plan_name'] ?? '—')) ?></td>
                                <td>
                                    <span class="<?= status_class((string) $payment['status'] === 'success' ? 'paid' : (string) $payment['status']) ?>">
                                        <?= e((string) $payment['status']) ?>
                                    </span>
                                    <?php if ((string) $payment['status'] === 'failed' && !empty($payment['error_message'])): ?>
                                        <div class="text-muted-2" style="font-size:.76rem"><?= e(str_excerpt((string) $payment['error_message'], 60)) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="num fw-650"><?= e(money((float) $payment['amount'], (string) $payment['currency'])) ?></td>
                                <td class="num text-muted-2" style="font-size:.85rem"><?= e(format_date((string) $payment['created_at'], 'd M Y')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($subscriptions !== []): ?>
            <div class="card-dp">
                <div class="card-dp__head"><h3>Subscription history</h3></div>
                <div class="table-wrap">
                    <table class="table-dp">
                        <thead><tr><th>Plan</th><th>Status</th><th class="num">Started</th><th class="num">Ends</th></tr></thead>
                        <tbody>
                        <?php foreach ($subscriptions as $subscription): ?>
                            <tr>
                                <td><?= e((string) $subscription['plan_name']) ?></td>
                                <td><span class="<?= status_class((string) $subscription['status'] === 'active' ? 'paid' : 'draft') ?>"><?= e((string) $subscription['status']) ?></span></td>
                                <td class="num text-muted-2" style="font-size:.85rem"><?= e(format_date($subscription['starts_at'] ?? null)) ?></td>
                                <td class="num text-muted-2" style="font-size:.85rem"><?= e(format_date($subscription['ends_at'] ?? null)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
