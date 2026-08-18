<?php
/**
 * @var array $payment
 * @var array|null $user
 * @var array $raw
 */
$id = (int) $payment['id'];
?>
<div class="page-head">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <span class="badge badge-primary mono"><?= e((string) $payment['txnid']) ?></span>
            <span class="<?= status_class((string) $payment['status'] === 'success' ? 'paid' : (string) $payment['status']) ?>">
                <?= e((string) $payment['status']) ?>
            </span>
        </div>
        <h1><?= e(money((float) $payment['amount'], (string) $payment['currency'])) ?></h1>
        <p>
            <?= e(strtoupper((string) $payment['gateway'])) ?> ·
            <?= e(format_date((string) $payment['created_at'], 'd M Y, H:i')) ?>
        </p>
    </div>
    <div class="btn-group-dp">
        <?php if ((string) $payment['status'] !== 'success'): ?>
            <form method="post" action="<?= e(url('admin/payments/' . $id . '/verify')) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn-dp btn-primary-dp">
                    <?= icon('refresh', '', 17) ?> Re-verify with PayU
                </button>
            </form>
        <?php endif; ?>
        <a href="<?= e(url('admin/payments')) ?>" class="btn-dp btn-ghost-dp"><?= icon('arrow-left', '', 17) ?> Back</a>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card-dp">
            <div class="card-dp__head"><h3>Payment</h3></div>
            <div class="card-dp__body">
                <dl class="kv mb-0">
                    <dt>Reference</dt><dd class="mono"><?= e((string) $payment['txnid']) ?></dd>
                    <dt>PayU ID</dt><dd class="mono"><?= e((string) ($payment['gateway_payment_id'] ?? '—') ?: '—') ?></dd>
                    <dt>Amount</dt><dd><?= e(money((float) $payment['amount'], (string) $payment['currency'])) ?></dd>
                    <dt>Status</dt><dd class="text-capitalize"><?= e((string) $payment['status']) ?></dd>
                    <dt>Mode</dt><dd><?= e((string) ($payment['payment_mode'] ?? '—') ?: '—') ?></dd>
                    <dt>Bank reference</dt><dd class="mono"><?= e((string) ($payment['bank_ref_num'] ?? '—') ?: '—') ?></dd>
                    <dt>Verified at</dt><dd><?= e(format_date($payment['verified_at'] ?? null, 'd M Y, H:i')) ?></dd>
                    <dt>Paid at</dt><dd><?= e(format_date($payment['paid_at'] ?? null, 'd M Y, H:i')) ?></dd>
                    <?php if (!empty($payment['error_message'])): ?>
                        <dt>Message</dt><dd style="color:var(--dp-danger)"><?= e((string) $payment['error_message']) ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>

        <div class="card-dp">
            <div class="card-dp__head"><h3>User</h3></div>
            <div class="card-dp__body">
                <?php if ($user === null): ?>
                    <p class="text-muted-2 mb-0">The user account no longer exists.</p>
                <?php else: ?>
                    <dl class="kv mb-0">
                        <dt>Name</dt><dd><a href="<?= e(url('admin/users/' . (int) $user['id'])) ?>"><?= e((string) $user['name']) ?></a></dd>
                        <dt>Email</dt><dd><?= e((string) $user['email']) ?></dd>
                        <dt>Status</dt><dd class="text-capitalize"><?= e((string) $user['status']) ?></dd>
                    </dl>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card-dp">
            <div class="card-dp__head"><h3>Gateway response</h3></div>
            <div class="card-dp__body">
                <?php if ($raw === []): ?>
                    <p class="text-muted-2 mb-0">No gateway response stored for this payment yet.</p>
                <?php else: ?>
                    <dl class="kv mb-0">
                        <?php foreach ($raw as $key => $value): ?>
                            <dt><?= e((string) $key) ?></dt>
                            <dd class="break-all"><?= e(is_scalar($value) ? (string) $value : json_encode($value)) ?></dd>
                        <?php endforeach; ?>
                    </dl>
                <?php endif; ?>
                <p class="field-hint mt-3 mb-0">
                    Card numbers and CVV are never sent to or stored by this application — only the fields above.
                </p>
            </div>
        </div>
    </div>
</div>
