<?php /** @var string $token */ /** @var string $email */ ?>
<h1>Choose a new password</h1>
<p class="sub">Setting a new password for <strong><?= e($email) ?></strong>.</p>

<form method="post" action="<?= e(url('password/reset')) ?>" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= e($token) ?>">

    <div class="form-row">
        <label class="form-label-dp" for="password">New password</label>
        <input type="password" id="password" name="password" required autofocus autocomplete="new-password"
               class="input-dp <?= has_error('password') ? 'is-invalid-dp' : '' ?>" placeholder="At least 8 characters">
        <?php if (has_error('password')): ?>
            <p class="field-error"><?= e(error_for('password')) ?></p>
        <?php else: ?>
            <p class="field-hint">Minimum 8 characters, including at least one letter and one number.</p>
        <?php endif; ?>
    </div>

    <div class="form-row">
        <label class="form-label-dp" for="password_confirmation">Confirm new password</label>
        <input type="password" id="password_confirmation" name="password_confirmation" required
               autocomplete="new-password" class="input-dp" placeholder="Repeat your password">
    </div>

    <button type="submit" class="btn-dp btn-primary-dp btn-block-dp btn-lg-dp"><?= icon('lock', '', 17) ?> Update password</button>
</form>
