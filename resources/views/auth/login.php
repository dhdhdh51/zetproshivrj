<?php /** @var bool $google_enabled */ ?>
<h1>Welcome back</h1>
<p class="sub">Sign in to create and send your business documents.</p>

<?php if (!empty($google_enabled)): ?>
    <a href="<?= e(url('auth/google')) ?>" class="btn-dp btn-outline-dp btn-block-dp">
        <svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true">
            <path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9 3.6l6.7-6.7C35.6 2.6 30.2.5 24 .5 14.8.5 6.9 5.8 3.1 13.4l7.8 6.1C12.7 13.9 17.9 9.5 24 9.5z"/>
            <path fill="#4285F4" d="M46.5 24.5c0-1.6-.2-3.2-.5-4.7H24v9.1h12.6c-.5 2.9-2.2 5.3-4.6 6.9l7.6 5.9c4.4-4.1 6.9-10.1 6.9-17.2z"/>
            <path fill="#FBBC05" d="M10.9 28.5c-.5-1.4-.8-2.9-.8-4.5s.3-3.1.8-4.5l-7.8-6.1C1.5 16.7.5 20.2.5 24s1 7.3 2.6 10.6l7.8-6.1z"/>
            <path fill="#34A853" d="M24 47.5c6.2 0 11.5-2 15.4-5.6l-7.6-5.9c-2.1 1.4-4.8 2.3-7.8 2.3-6.1 0-11.3-4.4-13.1-10.3l-7.8 6.1C6.9 42.2 14.8 47.5 24 47.5z"/>
        </svg>
        Continue with Google
    </a>
    <div class="auth-divider">or sign in with email</div>
<?php endif; ?>

<form method="post" action="<?= e(url('login')) ?>" novalidate>
    <?= csrf_field() ?>

    <div class="form-row">
        <label class="form-label-dp" for="email">Email address</label>
        <input type="email" id="email" name="email" value="<?= e(old('email')) ?>" required autocomplete="email"
               autofocus class="input-dp <?= has_error('email') ? 'is-invalid-dp' : '' ?>" placeholder="you@company.com">
        <?php if (has_error('email')): ?><p class="field-error"><?= e(error_for('email')) ?></p><?php endif; ?>
    </div>

    <div class="form-row">
        <div class="d-flex justify-content-between align-items-center">
            <label class="form-label-dp mb-0" for="password">Password</label>
            <a href="<?= e(url('password/forgot')) ?>" style="font-size:.83rem">Forgot password?</a>
        </div>
        <input type="password" id="password" name="password" required autocomplete="current-password"
               class="input-dp mt-1 <?= has_error('password') ? 'is-invalid-dp' : '' ?>" placeholder="••••••••">
        <?php if (has_error('password')): ?><p class="field-error"><?= e(error_for('password')) ?></p><?php endif; ?>
    </div>

    <div class="form-row">
        <label class="check-dp">
            <input type="checkbox" name="remember" value="1" <?= old('remember') ? 'checked' : '' ?>>
            <span>Keep me signed in on this device</span>
        </label>
    </div>

    <button type="submit" class="btn-dp btn-primary-dp btn-block-dp btn-lg-dp">
        Sign in <?= icon('arrow-right', '', 17) ?>
    </button>
</form>

<p class="text-center text-muted-2 mt-4 mb-0" style="font-size:.92rem">
    New to <?= e(app_name()) ?>? <a href="<?= e(url('register')) ?>">Create a free account</a>
</p>
