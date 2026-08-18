<?php /** @var string|null $message */ ?>
<p class="error-page__code">429</p>
<h1>Slow down for a moment</h1>
<p><?= e($message !== '' && $message !== null ? $message : 'You have made too many requests in a short period. Please wait a minute and try again.') ?></p>
<div class="btn-group-dp justify-content-center">
    <a href="<?= e(url(App\Core\Auth::check() ? 'dashboard' : '/')) ?>" class="btn-dp btn-primary-dp"><?= icon('clock', '', 17) ?> Try again shortly</a>
</div>
