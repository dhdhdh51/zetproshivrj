<?php /** @var string|null $message */ ?>
<p class="error-page__code">403</p>
<h1>You don't have access to this</h1>
<p><?= e($message !== '' && $message !== null ? $message : 'This document or page belongs to another account. If you think this is a mistake, contact support.') ?></p>
<div class="btn-group-dp justify-content-center">
    <a href="<?= e(url(App\Core\Auth::check() ? 'dashboard' : 'login')) ?>" class="btn-dp btn-primary-dp"><?= icon('arrow-left', '', 17) ?> <?= App\Core\Auth::check() ? 'Back to dashboard' : 'Sign in' ?></a>
    <a href="<?= e(url('contact')) ?>" class="btn-dp btn-outline-dp">Contact support</a>
</div>
