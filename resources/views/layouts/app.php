<?php
/**
 * Authenticated application shell.
 *
 * @var string $content
 */
$currentUser = auth_user() ?? [];
$logo = site_logo_url();
$isAdmin = App\Core\Auth::isAdmin();
?>
<!doctype html>
<html lang="en">
<head>
    <?= view_partial('partials.head', get_defined_vars()) ?>
</head>
<body>
<div class="app-shell">
    <aside class="app-sidebar" id="app-sidebar">
        <a class="app-sidebar__brand" href="<?= e(url('dashboard')) ?>">
            <?php if ($logo !== null): ?>
                <img class="app-sidebar__logo" src="<?= e($logo) ?>" alt="<?= e(app_name()) ?>">
            <?php else: ?>
                <span class="app-sidebar__logo">DP</span>
            <?php endif; ?>
            <span><?= e(app_name()) ?></span>
        </a>

        <nav class="app-nav">
            <a href="<?= e(url('dashboard')) ?>" class="<?= nav_active('/dashboard') ?>">
                <?= icon('dashboard') ?><span>Dashboard</span>
            </a>
            <a href="<?= e(url('documents')) ?>" class="<?= nav_active('/documents') ?>">
                <?= icon('file-text') ?><span>Documents</span>
            </a>
            <a href="<?= e(url('clients')) ?>" class="<?= nav_active('/clients') ?>">
                <?= icon('users') ?><span>Clients</span>
            </a>

            <div class="app-nav__label">Account</div>
            <a href="<?= e(url('profile/business')) ?>" class="<?= nav_active('/profile/business') ?>">
                <?= icon('briefcase') ?><span>Business profile</span>
            </a>
            <a href="<?= e(url('billing')) ?>" class="<?= nav_active('/billing') ?>">
                <?= icon('credit-card') ?><span>Billing &amp; usage</span>
            </a>
            <a href="<?= e(url('profile/account')) ?>" class="<?= nav_active('/profile/account') ?>">
                <?= icon('settings') ?><span>Account settings</span>
            </a>

            <?php if ($isAdmin): ?>
                <div class="app-nav__label">Administration</div>
                <a href="<?= e(url('admin')) ?>">
                    <?= icon('shield') ?><span>Admin panel</span>
                </a>
            <?php endif; ?>
        </nav>

        <div class="app-sidebar__footer">
            <a href="<?= e(url('documents/create')) ?>" class="btn-dp btn-primary-dp btn-block-dp">
                <?= icon('plus', '', 17) ?> New document
            </a>
        </div>
    </aside>

    <div class="sidebar-backdrop" id="sidebar-backdrop"></div>

    <div class="app-main">
        <header class="app-topbar">
            <button class="sidebar-toggle" type="button" aria-label="Toggle navigation"><?= icon('menu') ?></button>

            <div class="d-none d-md-flex align-items-center gap-2 text-muted-2 small">
                <?= icon('sparkles', '', 16) ?>
                <span>Create professional business documents with AI, in minutes.</span>
            </div>

            <div class="ms-auto d-flex align-items-center gap-2">
                <a href="<?= e(url('pricing')) ?>" class="btn-dp btn-ghost-dp btn-sm-dp d-none d-sm-inline-flex">
                    <?= icon('zap', '', 16) ?> Plans
                </a>

                <div class="d-flex align-items-center gap-2">
                    <span class="avatar" title="<?= e($currentUser['email'] ?? '') ?>"><?= e(initials($currentUser['name'] ?? '')) ?></span>
                    <div class="d-none d-lg-block lh-sm">
                        <div class="fw-650 text-ink" style="font-size:.88rem"><?= e($currentUser['name'] ?? 'Account') ?></div>
                        <div class="text-muted-2" style="font-size:.76rem"><?= e($currentUser['email'] ?? '') ?></div>
                    </div>
                </div>

                <form method="post" action="<?= e(url('logout')) ?>" class="d-inline">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn-dp btn-outline-dp btn-sm-dp" title="Sign out">
                        <?= icon('log-out', '', 16) ?><span class="d-none d-sm-inline">Sign out</span>
                    </button>
                </form>
            </div>
        </header>

        <?php if (App\Core\Settings::bool('require_email_verification', false) && !App\Core\Auth::isVerified()): ?>
            <div class="px-3 pt-3">
                <div class="alert-dp alert-warning-dp mb-0">
                    <?= icon('alert') ?>
                    <div>
                        Please confirm your email address to unlock document creation.
                        <a href="<?= e(url('email/verify')) ?>">Resend the confirmation email</a>.
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <main class="app-content">
            <?= view_partial('partials.flash') ?>
            <?= $content ?>
        </main>
    </div>
</div>

<script src="<?= e(asset('js/app.js')) ?>?v=1"></script>
</body>
</html>
