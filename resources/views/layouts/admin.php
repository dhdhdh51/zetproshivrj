<?php
/**
 * Admin panel shell.
 *
 * @var string $content
 */
$currentUser = auth_user() ?? [];
$noindex = true;
?>
<!doctype html>
<html lang="en">
<head>
    <?= view_partial('partials.head', get_defined_vars()) ?>
</head>
<body>
<div class="app-shell">
    <aside class="app-sidebar app-sidebar--admin" id="app-sidebar">
        <a class="app-sidebar__brand" href="<?= e(url('admin')) ?>">
            <span class="app-sidebar__logo"><?= icon('shield', '', 18) ?></span>
            <span><?= e(app_name()) ?> <span class="admin-badge">ADMIN</span></span>
        </a>

        <nav class="app-nav">
            <a href="<?= e(url('admin')) ?>" class="<?= (new App\Core\Request())->path() === '/admin' ? 'active' : '' ?>">
                <?= icon('dashboard') ?><span>Dashboard</span>
            </a>
            <a href="<?= e(url('admin/users')) ?>" class="<?= nav_active('/admin/users') ?>">
                <?= icon('users') ?><span>Users</span>
            </a>
            <a href="<?= e(url('admin/documents')) ?>" class="<?= nav_active('/admin/documents') ?>">
                <?= icon('file-text') ?><span>Documents</span>
            </a>

            <div class="app-nav__label">Monetisation</div>
            <a href="<?= e(url('admin/plans')) ?>" class="<?= nav_active('/admin/plans') ?>">
                <?= icon('layers') ?><span>Plans</span>
            </a>
            <a href="<?= e(url('admin/payments')) ?>" class="<?= nav_active('/admin/payments') ?>">
                <?= icon('credit-card') ?><span>Payments</span>
            </a>
            <a href="<?= e(url('admin/payu')) ?>" class="<?= nav_active('/admin/payu') ?>">
                <?= icon('bank') ?><span>PayU settings</span>
            </a>

            <div class="app-nav__label">Configuration</div>
            <a href="<?= e(url('admin/ai')) ?>" class="<?= nav_active('/admin/ai') ?>">
                <?= icon('sparkles') ?><span>AI settings</span>
            </a>
            <a href="<?= e(url('admin/email')) ?>" class="<?= nav_active('/admin/email') ?>">
                <?= icon('mail') ?><span>Email settings</span>
            </a>
            <a href="<?= e(url('admin/templates')) ?>" class="<?= nav_active('/admin/templates') ?>">
                <?= icon('palette') ?><span>Templates</span>
            </a>
            <a href="<?= e(url('admin/settings')) ?>" class="<?= nav_active('/admin/settings') ?>">
                <?= icon('settings') ?><span>System settings</span>
            </a>
        </nav>

        <div class="app-sidebar__footer">
            <a href="<?= e(url('dashboard')) ?>" class="btn-dp btn-outline-dp btn-block-dp btn-sm-dp">
                <?= icon('arrow-left', '', 16) ?> Back to app
            </a>
        </div>
    </aside>

    <div class="sidebar-backdrop" id="sidebar-backdrop"></div>

    <div class="app-main">
        <header class="app-topbar">
            <button class="sidebar-toggle" type="button" aria-label="Toggle navigation"><?= icon('menu') ?></button>

            <div class="d-none d-md-flex align-items-center gap-2 text-muted-2 small">
                <?= icon('shield', '', 16) ?><span>Administration area</span>
            </div>

            <div class="ms-auto d-flex align-items-center gap-2">
                <span class="avatar avatar-sm"><?= e(initials($currentUser['name'] ?? '')) ?></span>
                <span class="d-none d-sm-inline text-muted-2 small"><?= e($currentUser['email'] ?? '') ?></span>
                <form method="post" action="<?= e(url('logout')) ?>" class="d-inline">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn-dp btn-outline-dp btn-sm-dp"><?= icon('log-out', '', 16) ?></button>
                </form>
            </div>
        </header>

        <main class="app-content">
            <?= view_partial('partials.flash') ?>
            <?= $content ?>
        </main>
    </div>
</div>

<script src="<?= e(asset('js/app.js')) ?>?v=1"></script>
</body>
</html>
