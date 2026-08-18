<?php $forced = !empty($forced); ?>

<?php if ($forced): ?>
    <h1>Set a new password</h1>
    <p class="muted small">Your account uses a temporary password. Choose a new one to continue.</p>
<?php else: ?>
    <div class="page-head">
        <div class="grow">
            <h1>Change password</h1>
            <div class="subtitle">Use at least 8 characters with letters and numbers.</div>
        </div>
    </div>
<?php endif; ?>

<?php if (!$forced): ?><div class="card content-narrow"><div class="card-body"><?php endif; ?>

<form method="post" action="<?= e(url('/password/change')) ?>">
    <?= csrf_field() ?>

    <div class="field <?= has_error('current_password') ? 'has-error' : '' ?>">
        <label for="current_password">Current password <span class="req">*</span></label>
        <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>
        <?php if (has_error('current_password')): ?>
            <div class="error-text"><?= e(error_for('current_password')) ?></div>
        <?php endif; ?>
    </div>

    <div class="field <?= has_error('password') ? 'has-error' : '' ?>">
        <label for="password">New password <span class="req">*</span></label>
        <input type="password" id="password" name="password" autocomplete="new-password" required minlength="8">
        <div class="help">Minimum 8 characters, including at least one letter and one number.</div>
        <?php if (has_error('password')): ?>
            <div class="error-text"><?= e(error_for('password')) ?></div>
        <?php endif; ?>
    </div>

    <div class="field">
        <label for="password_confirmation">Confirm new password <span class="req">*</span></label>
        <input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" required>
    </div>

    <button type="submit" class="btn <?= $forced ? 'btn-block' : '' ?>">
        <?= icon('check', '', 16) ?> Update password
    </button>
</form>

<?php if (!$forced): ?></div></div><?php endif; ?>
