<h1>Reset your password</h1>
<p class="sub">Enter the email address you signed up with and we'll send you a secure reset link.</p>

<form method="post" action="<?= e(url('password/forgot')) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="form-row">
        <label class="form-label-dp" for="email">Email address</label>
        <input type="email" id="email" name="email" value="<?= e(old('email')) ?>" required autofocus
               class="input-dp <?= has_error('email') ? 'is-invalid-dp' : '' ?>" placeholder="you@company.com">
        <?php if (has_error('email')): ?><p class="field-error"><?= e(error_for('email')) ?></p><?php endif; ?>
    </div>
    <button type="submit" class="btn-dp btn-primary-dp btn-block-dp btn-lg-dp"><?= icon('mail', '', 17) ?> Send reset link</button>
</form>

<p class="text-center text-muted-2 mt-4 mb-0" style="font-size:.92rem">
    <a href="<?= e(url('login')) ?>">Back to sign in</a>
</p>
