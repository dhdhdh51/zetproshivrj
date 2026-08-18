<?php /** @var string|null $message */ ?>
<p class="error-page__code">404</p>
<h1>We can't find that page</h1>
<p><?= e($message !== '' && $message !== null ? $message : 'The page you were looking for has moved, been deleted, or never existed.') ?></p>
<div class="btn-group-dp justify-content-center">
    <a href="<?= e(url('/')) ?>" class="btn-dp btn-primary-dp"><?= icon('arrow-left', '', 17) ?> Back to home</a>
    <?php if (App\Core\Auth::check()): ?>
        <a href="<?= e(url('dashboard')) ?>" class="btn-dp btn-outline-dp">Go to dashboard</a>
    <?php endif; ?>
</div>
