<?php

use App\Core\Auth;
use App\Services\Deadline;
use App\Services\Notify;

$user = auth_user() ?? [];
$isAdmin = Auth::isAdmin();
$base = $isAdmin ? '/admin' : '/manager';
$deadline = Deadline::status();
$unread = $user === [] ? 0 : Notify::unreadCount($user);
$badges = $navBadges ?? [];
?>
<!doctype html>
<html lang="en">
<head><?= view_partial('partials.head', ['title' => $title ?? 'Dashboard']) ?></head>
<body>
<div class="app">
    <aside class="sidebar">
        <div class="sidebar-brand">
            <div class="logo">LRMS</div>
            <div>
                <div class="name"><?= e(app_name()) ?></div>
                <div class="role"><?= e($isAdmin ? 'Admin / Supervisor' : 'Branch Manager') ?></div>
            </div>
        </div>

        <nav class="nav">
            <?php if ($isAdmin): ?>
                <a class="<?= nav_active('/admin') === 'active' && (new App\Core\Request())->path() === '/admin' ? 'active' : '' ?>" href="<?= e(url('/admin')) ?>">
                    <?= icon('dashboard') ?> Dashboard
                </a>

                <div class="nav-section">Loan book</div>
                <a class="<?= nav_active('/admin/accounts') ?>" href="<?= e(url('/admin/accounts')) ?>">
                    <?= icon('database') ?> Loan accounts
                </a>
                <a class="<?= nav_active('/admin/imports') ?>" href="<?= e(url('/admin/imports')) ?>">
                    <?= icon('upload') ?> Excel import
                </a>
                <a class="<?= nav_active('/admin/allocation') ?>" href="<?= e(url('/admin/allocation')) ?>">
                    <?= icon('layers') ?> Allocation
                    <?php if (!empty($badges['unassigned'])): ?>
                        <span class="count alert"><?= (int) $badges['unassigned'] ?></span>
                    <?php endif; ?>
                </a>

                <div class="nav-section">Field work</div>
                <a class="<?= nav_active('/admin/visits') ?>" href="<?= e(url('/admin/visits')) ?>">
                    <?= icon('clipboard') ?> Customer visits
                </a>
                <a class="<?= nav_active('/admin/inspections') ?>" href="<?= e(url('/admin/inspections')) ?>">
                    <?= icon('search-check') ?> BC inspections
                </a>
                <a class="<?= nav_active('/admin/monitoring') ?>" href="<?= e(url('/admin/monitoring')) ?>">
                    <?= icon('activity') ?> Live monitoring
                </a>
                <a class="<?= nav_active('/admin/krm-ots') ?>" href="<?= e(url('/admin/krm-ots')) ?>">
                    <?= icon('rupee') ?> KRM OTS
                </a>
                <a class="<?= nav_active('/admin/ckcc') ?>" href="<?= e(url('/admin/ckcc')) ?>">
                    <?= icon('refresh') ?> CKCC OD-2
                </a>

                <div class="nav-section">Organisation</div>
                <a class="<?= nav_active('/admin/branches') ?>" href="<?= e(url('/admin/branches')) ?>">
                    <?= icon('building') ?> Branches
                </a>
                <a class="<?= nav_active('/admin/managers') ?>" href="<?= e(url('/admin/managers')) ?>">
                    <?= icon('user') ?> Branch managers
                </a>
                <a class="<?= nav_active('/admin/supervisors') ?>" href="<?= e(url('/admin/supervisors')) ?>">
                    <?= icon('users') ?> BC supervisors
                </a>
                <a class="<?= nav_active('/admin/targets') ?>" href="<?= e(url('/admin/targets')) ?>">
                    <?= icon('target') ?> Targets
                </a>

                <div class="nav-section">Reporting</div>
                <a class="<?= nav_active('/admin/reports') ?>" href="<?= e(url('/admin/reports')) ?>">
                    <?= icon('chart') ?> Reports
                </a>
                <a class="<?= nav_active('/admin/deadline') ?>" href="<?= e(url('/admin/deadline')) ?>">
                    <?= icon('clock') ?> Report deadline
                    <?php if (!empty($badges['late_pending'])): ?>
                        <span class="count alert"><?= (int) $badges['late_pending'] ?></span>
                    <?php endif; ?>
                </a>

                <div class="nav-section">Configuration</div>
                <a class="<?= nav_active('/admin/forms/visit') ?>" href="<?= e(url('/admin/forms/visit')) ?>">
                    <?= icon('list') ?> Visit form builder
                </a>
                <a class="<?= nav_active('/admin/forms/inspection') ?>" href="<?= e(url('/admin/forms/inspection')) ?>">
                    <?= icon('sliders') ?> Inspection form builder
                </a>
                <a class="<?= nav_active('/admin/settings') ?>" href="<?= e(url('/admin/settings')) ?>">
                    <?= icon('settings') ?> Settings
                </a>
                <a class="<?= nav_active('/admin/audit') ?>" href="<?= e(url('/admin/audit')) ?>">
                    <?= icon('shield') ?> Audit log
                </a>
            <?php else: ?>
                <a class="<?= (new App\Core\Request())->path() === '/manager' ? 'active' : '' ?>" href="<?= e(url('/manager')) ?>">
                    <?= icon('dashboard') ?> Dashboard
                </a>
                <a class="<?= nav_active('/manager/accounts') ?>" href="<?= e(url('/manager/accounts')) ?>">
                    <?= icon('database') ?> Accounts
                </a>
                <a class="<?= nav_active('/manager/supervisors') ?>" href="<?= e(url('/manager/supervisors')) ?>">
                    <?= icon('users') ?> BC supervisors
                </a>
                <a class="<?= nav_active('/manager/visits') ?>" href="<?= e(url('/manager/visits')) ?>">
                    <?= icon('clipboard') ?> Visits
                </a>
                <a class="<?= nav_active('/manager/recovery') ?>" href="<?= e(url('/manager/recovery')) ?>">
                    <?= icon('rupee') ?> Recovery &amp; PTP
                </a>
                <a class="<?= nav_active('/manager/pending') ?>" href="<?= e(url('/manager/pending')) ?>">
                    <?= icon('alert') ?> Pending accounts
                </a>
                <a class="<?= nav_active('/manager/performance') ?>" href="<?= e(url('/manager/performance')) ?>">
                    <?= icon('chart') ?> Performance
                </a>
                <a class="<?= nav_active('/manager/reports') ?>" href="<?= e(url('/manager/reports')) ?>">
                    <?= icon('file-text') ?> Reports
                </a>
            <?php endif; ?>

            <div class="nav-section">Account</div>
            <a class="<?= nav_active($base . '/notifications') ?>" href="<?= e(url($base . '/notifications')) ?>">
                <?= icon('bell') ?> Notifications
                <?php if ($unread > 0): ?><span class="count"><?= $unread ?></span><?php endif; ?>
            </a>
            <a class="<?= nav_active('/password') ?>" href="<?= e(url('/password/change')) ?>">
                <?= icon('lock') ?> Change password
            </a>
            <form method="post" action="<?= e(url('/logout')) ?>" style="margin:0">
                <?= csrf_field() ?>
                <button type="submit" class="nav-logout" style="all:unset;display:flex;align-items:center;gap:10px;padding:8px 12px;border-radius:6px;color:#c3d0e0;font-size:13.5px;cursor:pointer;width:100%">
                    <?= icon('log-out') ?> Sign out
                </button>
            </form>
        </nav>
    </aside>

    <div class="main">
        <header class="topbar">
            <button class="btn btn-secondary btn-sm menu-toggle" data-menu-toggle type="button" aria-label="Menu">
                <?= icon('menu', '', 16) ?>
            </button>
            <div class="page-title"><?= e($title ?? 'Dashboard') ?></div>
            <div class="spacer"></div>

            <?php if ($deadline['is_working_day']): ?>
                <span class="deadline-chip <?= $deadline['has_passed'] ? 'passed' : '' ?>" data-countdown="<?= (int) $deadline['seconds_remaining'] ?>">
                    <?= $deadline['has_passed'] ? 'Deadline passed' : 'Report deadline ' . e(format_time($deadline['deadline_at'])) ?>
                </span>
            <?php else: ?>
                <span class="deadline-chip">Non-working day</span>
            <?php endif; ?>

            <div class="avatar" title="<?= e($user['name'] ?? '') ?>"><?= e(initials($user['name'] ?? '')) ?></div>
        </header>

        <main class="content <?= e($contentClass ?? '') ?>">
            <?= view_partial('partials.flash') ?>
            <?= $content ?>
        </main>
    </div>
</div>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
