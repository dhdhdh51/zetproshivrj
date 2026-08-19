<h1><?= et('auth.sign_in') ?></h1>
<p class="muted small"><?= et('auth.intro') ?></p>

<form method="post" action="<?= e(url('/login')) ?>">
    <?= csrf_field() ?>

    <div class="field <?= has_error('login') ? 'has-error' : '' ?>">
        <label for="login"><?= et('auth.login_field') ?></label>
        <input type="text" id="login" name="login" value="<?= e(old('login')) ?>"
               autocomplete="username" autofocus required maxlength="190">
        <?php if (has_error('login')): ?><div class="error-text"><?= e(error_for('login')) ?></div><?php endif; ?>
    </div>

    <div class="field <?= has_error('password') ? 'has-error' : '' ?>">
        <label for="password"><?= et('auth.password') ?></label>
        <input type="password" id="password" name="password" autocomplete="current-password" required>
        <?php if (has_error('password')): ?><div class="error-text"><?= e(error_for('password')) ?></div><?php endif; ?>
    </div>

    <button type="submit" class="btn btn-block">
        <?= icon('lock', '', 16) ?> <?= et(!empty($otpEnabled) ? 'auth.sign_in_and_get_code' : 'auth.sign_in') ?>
    </button>
</form>

<p class="small muted" style="margin-top:16px;margin-bottom:0">
    <?= et('auth.app_only_hint') ?> <a href="<?= e(url('/app-only')) ?>"><?= et('auth.app_only_link') ?></a>.
</p>
