<h1>Verify sign-in</h1>
<p class="muted small"><?= e($hint ?? 'Enter the verification code that was sent to you.') ?></p>

<form method="post" action="<?= e(url('/login/verify')) ?>">
    <?= csrf_field() ?>

    <div class="field <?= has_error('code') ? 'has-error' : '' ?>">
        <label for="code">Verification code</label>
        <input type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code"
               pattern="[0-9]*" maxlength="8" autofocus required
               style="letter-spacing:.35em;font-size:19px;text-align:center">
        <?php if (has_error('code')): ?><div class="error-text"><?= e(error_for('code')) ?></div><?php endif; ?>
    </div>

    <button type="submit" class="btn btn-block"><?= icon('check', '', 16) ?> Verify and continue</button>
</form>

<form method="post" action="<?= e(url('/login/resend')) ?>" style="margin-top:12px">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-secondary btn-block btn-sm">Send a new code</button>
</form>

<p class="small muted" style="margin-top:14px;margin-bottom:0">
    <a href="<?= e(url('/login')) ?>">Start again</a>
</p>
