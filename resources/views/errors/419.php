<?php /** @var string|null $message */ ?>
<p class="error-page__code">419</p>
<h1>Your session expired</h1>
<p><?= e($message !== '' && $message !== null ? $message : 'For your security we expire idle sessions. Please refresh the page and try again — your work is usually still in the form.') ?></p>
<div class="btn-group-dp justify-content-center">
    <a href="javascript:history.back()" class="btn-dp btn-primary-dp"><?= icon('arrow-left', '', 17) ?> Go back</a>
    <a href="<?= e(url('login')) ?>" class="btn-dp btn-outline-dp">Sign in again</a>
</div>
