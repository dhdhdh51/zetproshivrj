<?php /** @var bool $google_enabled */ ?>
<h1>Create your account</h1>
<p class="sub">Free forever plan — 5 documents and 5 AI generations every month, no card needed.</p>

<?php if (!empty($google_enabled)): ?>
    <a href="<?= e(url('auth/google')) ?>" class="btn-dp btn-outline-dp btn-block-dp">
        <svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true">
            <path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9 3.6l6.7-6.7C35.6 2.6 30.2.5 24 .5 14.8.5 6.9 5.8 3.1 13.4l7.8 6.1C12.7 13.9 17.9 9.5 24 9.5z"/>
            <path fill="#4285F4" d="M46.5 24.5c0-1.6-.2-3.2-.5-4.7H24v9.1h12.6c-.5 2.9-2.2 5.3-4.6 6.9l7.6 5.9c4.4-4.1 6.9-10.1 6.9-17.2z"/>
            <path fill="#FBBC05" d="M10.9 28.5c-.5-1.4-.8-2.9-.8-4.5s.3-3.1.8-4.5l-7.8-6.1C1.5 16.7.5 20.2.5 24s1 7.3 2.6 10.6l7.8-6.1z"/>
            <path fill="#34A853" d="M24 47.5c6.2 0 11.5-2 15.4-5.6l-7.6-5.9c-2.1 1.4-4.8 2.3-7.8 2.3-6.1 0-11.3-4.4-13.1-10.3l-7.8 6.1C6.9 42.2 14.8 47.5 24 47.5z"/>
        </svg>
        Sign up with Google
    </a>
    <div class="auth-divider">or use your email</div>
<?php endif; ?>

<form method="post" action="<?= e(url('register')) ?>" novalidate>
    <?= csrf_field() ?>

    <div class="form-row">
        <label class="form-label-dp" for="name">Your name</label>
        <input type="text" id="name" name="name" value="<?= e(old('name')) ?>" required autocomplete="name" autofocus
               class="input-dp <?= has_error('name') ? 'is-invalid-dp' : '' ?>" placeholder="Priya Sharma">
        <?php if (has_error('name')): ?><p class="field-error"><?= e(error_for('name')) ?></p><?php endif; ?>
    </div>

    <div class="form-row">
        <label class="form-label-dp" for="email">Work email</label>
        <input type="email" id="email" name="email" value="<?= e(old('email')) ?>" required autocomplete="email"
               class="input-dp <?= has_error('email') ? 'is-invalid-dp' : '' ?>" placeholder="you@company.com">
        <?php if (has_error('email')): ?><p class="field-error"><?= e(error_for('email')) ?></p><?php endif; ?>
    </div>

    <div class="form-row">
        <label class="form-label-dp" for="password">Password</label>
        <input type="password" id="password" name="password" required autocomplete="new-password"
               class="input-dp <?= has_error('password') ? 'is-invalid-dp' : '' ?>" placeholder="At least 8 characters">
        <?php if (has_error('password')): ?>
            <p class="field-error"><?= e(error_for('password')) ?></p>
        <?php else: ?>
            <p class="field-hint">Minimum 8 characters, including at least one letter and one number.</p>
        <?php endif; ?>
    </div>

    <div class="form-row">
        <label class="form-label-dp" for="password_confirmation">Confirm password</label>
        <input type="password" id="password_confirmation" name="password_confirmation" required
               autocomplete="new-password" class="input-dp" placeholder="Repeat your password">
    </div>

    <div class="form-row">
        <label class="check-dp">
            <input type="checkbox" name="terms" value="1" required>
            <span>
                I agree to the <a href="<?= e(url('terms')) ?>" target="_blank" rel="noopener">Terms of Service</a>
                and <a href="<?= e(url('privacy')) ?>" target="_blank" rel="noopener">Privacy Policy</a>.
            </span>
        </label>
        <?php if (has_error('terms')): ?><p class="field-error"><?= e(error_for('terms')) ?></p><?php endif; ?>
    </div>

    <button type="submit" class="btn-dp btn-primary-dp btn-block-dp btn-lg-dp">
        Create free account <?= icon('arrow-right', '', 17) ?>
    </button>
</form>

<p class="text-center text-muted-2 mt-4 mb-0" style="font-size:.92rem">
    Already have an account? <a href="<?= e(url('login')) ?>">Sign in</a>
</p>
