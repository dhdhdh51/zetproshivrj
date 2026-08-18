<?php
/**
 * @var array $filters
 * @var array $payments  paginator
 * @var array $stats
 * @var int   $active_subscriptions
 * @var bool  $payu_configured
 * @var string $payu_mode
 */
$query = array_filter(['q' => $filters['search'], 'status' => $filters['status']]);
?>
<div class="page-head">
    <div>
        <h1>Payments</h1>
        <p>
            <?= e(money((float) $stats['revenue'], 'INR')) ?> collected ·
            <?= e(money((float) $stats['revenue_this_month'], 'INR')) ?> this month ·
            <?= number_format($active_subscriptions) ?> active subscriptions
        </p>
    </div>
    <a href="<?= e(url('admin/payu')) ?>" class="btn-dp btn-outline-dp">
        <?= icon('bank', '', 17) ?> PayU settings
        <span class="badge <?= $payu_configured ? ($payu_mode === 'live' ? 'badge-success' : 'badge-warning') : 'badge-danger' ?>">
            <?= $payu_configured ? e($payu_mode) : 'not set' ?>
        </span>
    </a>
</div>

<div class="stat-grid mb-3">
    <div class="stat"><div class="stat__label">Total payments</div><div class="stat__value"><?= number_format((int) $stats['total']) ?></div></div>
    <div class="stat"><div class="stat__label">Successful</div><div class="stat__value"><?= number_format((int) $stats['successful']) ?></div></div>
    <div class="stat"><div class="stat__label">Failed</div><div class="stat__value"><?= number_format((int) $stats['failed']) ?></div></div>
    <div class="stat"><div class="stat__label">Revenue</div><div class="stat__value"><?= e(money((float) $stats['revenue'], 'INR')) ?></div></div>
</div>

<div class="card-dp">
    <div class="card-dp__head">
        <form method="get" action="<?= e(url('admin/payments')) ?>" class="row g-2 flex-grow-1">
            <div class="col-sm-7 col-lg-6">
                <input type="search" name="q" value="<?= e((string) $filters['search']) ?>" class="input-dp"
                       placeholder="Search reference, PayU id or user email…">
            </div>
            <div class="col-5 col-lg-3">
                <select name="status" class="select-dp" data-auto-submit>
                    <option value="">All statuses</option>
                    <?php foreach (['pending', 'success', 'failed', 'cancelled'] as $option): ?>
                        <option value="<?= e($option) ?>" <?= (string) $filters['status'] === $option ? 'selected' : '' ?>><?= e(ucfirst($option)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-3 d-flex gap-2">
                <button type="submit" class="btn-dp btn-outline-dp flex-grow-1"><?= icon('search', '', 16) ?> Filter</button>
                <?php if ($query !== []): ?>
                    <a href="<?= e(url('admin/payments')) ?>" class="btn-dp btn-ghost-dp"><?= icon('x', '', 16) ?></a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <?php if ($payments['data'] === []): ?>
        <div class="empty-state">
            <div class="empty-state__icon"><?= icon('credit-card', '', 26) ?></div>
            <h3>No payments yet</h3>
            <p>Payments appear here as soon as a user completes a PayU checkout.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-dp">
                <thead>
                <tr><th>Reference</th><th>User</th><th>Plan</th><th>Status</th><th class="num">Amount</th><th class="num">Date</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($payments['data'] as $payment): ?>
                    <tr>
                        <td>
                            <a href="<?= e(url('admin/payments/' . (int) $payment['id'])) ?>" class="mono" style="font-size:.82rem">
                                <?= e((string) $payment['txnid']) ?>
                            </a>
                            <?php if (!empty($payment['gateway_payment_id'])): ?>
                                <div class="text-muted-2 mono" style="font-size:.74rem"><?= e((string) $payment['gateway_payment_id']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:.86rem">
                            <a href="<?= e(url('admin/users/' . (int) $payment['user_id'])) ?>"><?= e((string) $payment['user_name']) ?></a>
                            <div class="text-muted-2" style="font-size:.78rem"><?= e(str_excerpt((string) $payment['user_email'], 24)) ?></div>
                        </td>
                        <td><?= e((string) ($payment['plan_name'] ?? '—')) ?></td>
                        <td>
                            <span class="<?= status_class((string) $payment['status'] === 'success' ? 'paid' : (string) $payment['status']) ?>">
                                <?= e((string) $payment['status']) ?>
                            </span>
                            <?php if (!empty($payment['payment_mode'])): ?>
                                <div class="text-muted-2" style="font-size:.76rem"><?= e((string) $payment['payment_mode']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="num fw-650"><?= e(money((float) $payment['amount'], (string) $payment['currency'])) ?></td>
                        <td class="num text-muted-2" style="font-size:.84rem"><?= e(format_date((string) $payment['created_at'], 'd M Y H:i')) ?></td>
                        <td class="num">
                            <a href="<?= e(url('admin/payments/' . (int) $payment['id'])) ?>" class="btn-dp btn-outline-dp btn-sm-dp"><?= icon('eye', '', 15) ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card-dp__foot">
            <?= view_partial('partials.pagination', ['paginator' => $payments, 'query' => $query]) ?>
        </div>
    <?php endif; ?>
</div>
