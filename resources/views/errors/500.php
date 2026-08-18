<?php
/** @var string|null $message */
/** @var Throwable|null $exception */
?>
<p class="error-page__code">500</p>
<h1>Something went wrong on our side</h1>
<p>
    <?php if (!empty($exception)): ?>
        <?= e($message) ?>
    <?php else: ?>
        We logged the problem and will look into it. Please try again — if it keeps happening, contact support.
    <?php endif; ?>
</p>
<div class="btn-group-dp justify-content-center">
    <a href="<?= e(url(App\Core\Auth::check() ? 'dashboard' : '/')) ?>" class="btn-dp btn-primary-dp"><?= icon('refresh', '', 17) ?> Try again</a>
    <a href="<?= e(url('contact')) ?>" class="btn-dp btn-outline-dp">Contact support</a>
</div>
<?php if (!empty($exception) && $exception instanceof Throwable): ?>
    <pre class="error-trace"><?= e($exception->getFile() . ':' . $exception->getLine()) ?>

<?= e($exception->getTraceAsString()) ?></pre>
<?php endif; ?>
