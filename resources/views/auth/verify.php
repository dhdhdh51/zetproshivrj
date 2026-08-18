<?php /** @var array|null $user */ ?>
<h1>Confirm your email address</h1>
<p class="sub">
    We sent a confirmation link to <strong><?= e($user['email'] ?? '') ?></strong>.
    Click the link in that email to activate every feature of your account.
</p>

<div class="alert-dp alert-info-dp">
    <?= icon('info') ?>
    <div>Can't find it? Check your spam folder, or request a new link below.</div>
</div>

<form method="post" action="<?= e(url('email/verify/resend')) ?>">
    <?= csrf_field() ?>
    <button type="submit" class="btn-dp btn-primary-dp btn-block-dp"><?= icon('refresh', '', 17) ?> Resend confirmation email</button>
</form>

<div class="d-flex gap-2 mt-3">
    <a href="<?= e(url('dashboard')) ?>" class="btn-dp btn-outline-dp btn-block-dp">Skip for now</a>
    <form method="post" action="<?= e(url('logout')) ?>" class="w-100">
        <?= csrf_field() ?>
        <button type="submit" class="btn-dp btn-ghost-dp btn-block-dp">Sign out</button>
    </form>
</div>
