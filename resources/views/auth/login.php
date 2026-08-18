<h1>Sign in</h1>
<p class="muted small">Admin/Supervisor and Branch Manager accounts.</p>

<form method="post" action="<?= e(url('/login')) ?>">
    <?= csrf_field() ?>

    <div class="field <?= has_error('login') ? 'has-error' : '' ?>">
        <label for="login">Email, username or employee code</label>
        <input type="text" id="login" name="login" value="<?= e(old('login')) ?>"
               autocomplete="username" autofocus required maxlength="190">
        <?php if (has_error('login')): ?><div class="error-text"><?= e(error_for('login')) ?></div><?php endif; ?>
    </div>

    <div class="field <?= has_error('password') ? 'has-error' : '' ?>">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" autocomplete="current-password" required>
        <?php if (has_error('password')): ?><div class="error-text"><?= e(error_for('password')) ?></div><?php endif; ?>
    </div>

    <button type="submit" class="btn btn-block">
        <?= icon('lock', '', 16) ?> Sign in<?= !empty($otpEnabled) ? ' and get code' : '' ?>
    </button>
</form>

<p class="small muted" style="margin-top:16px;margin-bottom:0">
    BC Supervisors work in the LRMS Android app — <a href="<?= e(url('/app-only')) ?>">details here</a>.
</p>
