<?php
/**
 * Auto-submitting bridge form to PayU's hosted checkout.
 *
 * @var string $action
 * @var array<string, string> $fields
 * @var array $plan
 * @var string $mode
 */
?>
<div class="card-dp">
    <div class="card-dp__body text-center">
        <div class="empty-state__icon mx-auto"><?= icon('credit-card', '', 26) ?></div>
        <h1 style="font-size:1.3rem">Taking you to PayU…</h1>
        <p class="text-muted-2">
            You are paying <strong><?= e(money((float) $plan['price'], (string) $plan['currency'])) ?></strong>
            for the <strong><?= e((string) $plan['name']) ?></strong> plan.
            <?php if ($mode === 'test'): ?><br><span class="badge badge-warning mt-2">PayU test mode</span><?php endif; ?>
        </p>

        <form id="payu-form" method="post" action="<?= e($action) ?>">
            <?php foreach ($fields as $name => $value): ?>
                <input type="hidden" name="<?= e($name) ?>" value="<?= e($value) ?>">
            <?php endforeach; ?>
            <button type="submit" class="btn-dp btn-primary-dp btn-lg-dp mt-2">
                Continue to PayU <?= icon('arrow-right', '', 17) ?>
            </button>
        </form>

        <p class="field-hint mt-3 mb-0">
            If nothing happens within a few seconds, use the button above.
            <a href="<?= e(url('pricing')) ?>">Cancel and go back</a>.
        </p>
    </div>
</div>

<script>
    setTimeout(function () {
        var form = document.getElementById('payu-form');
        if (form) form.submit();
    }, 900);
</script>
