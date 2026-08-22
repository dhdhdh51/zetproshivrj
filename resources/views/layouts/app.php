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
<html lang="<?= e(current_locale()) ?>">
<head><?= view_partial('partials.head', ['title' => $title ?? __('nav.dashboard')]) ?></head>
<body>
<div class="app">
    <aside class="sidebar">
        <div class="sidebar-brand">
            <div class="logo">LRMS</div>
            <div>
                <div class="name"><?= e(app_name()) ?></div>
                <div class="role"><?= et($isAdmin ? 'role.admin' : 'role.branch_manager') ?></div>
            </div>
        </div>

        <nav class="nav">
            <?php if ($isAdmin): ?>
                <a class="<?= nav_active('/admin') === 'active' && (new App\Core\Request())->path() === '/admin' ? 'active' : '' ?>" href="<?= e(url('/admin')) ?>">
                    <?= icon('dashboard') ?> <?= et('nav.dashboard') ?>
                </a>

                <div class="nav-section"><?= et('nav.section.loan_book') ?></div>
                <a class="<?= nav_active('/admin/accounts') ?>" href="<?= e(url('/admin/accounts')) ?>">
                    <?= icon('database') ?> <?= et('nav.loan_accounts') ?>
                </a>
                <a class="<?= nav_active('/admin/imports') ?>" href="<?= e(url('/admin/imports')) ?>">
                    <?= icon('upload') ?> <?= et('nav.excel_import') ?>
                </a>
                <a class="<?= nav_active('/admin/allocation') ?>" href="<?= e(url('/admin/allocation')) ?>">
                    <?= icon('layers') ?> <?= et('nav.allocation') ?>
                    <?php if (!empty($badges['unassigned'])): ?>
                        <span class="count alert"><?= (int) $badges['unassigned'] ?></span>
                    <?php endif; ?>
                </a>

                <div class="nav-section"><?= et('nav.section.field_work') ?></div>
                <a class="<?= nav_active('/admin/visits') ?>" href="<?= e(url('/admin/visits')) ?>">
                    <?= icon('clipboard') ?> <?= et('nav.customer_visits') ?>
                </a>
                <a class="<?= nav_active('/admin/inspections') ?>" href="<?= e(url('/admin/inspections')) ?>">
                    <?= icon('search-check') ?> <?= et('nav.bc_inspections') ?>
                </a>
                <a class="<?= nav_active('/admin/monitoring') ?>" href="<?= e(url('/admin/monitoring')) ?>">
                    <?= icon('activity') ?> <?= et('nav.live_monitoring') ?>
                </a>
                <a class="<?= nav_active('/admin/krm-ots') ?>" href="<?= e(url('/admin/krm-ots')) ?>">
                    <?= icon('rupee') ?> <?= et('nav.krm_ots') ?>
                </a>
                <a class="<?= nav_active('/admin/ckcc') ?>" href="<?= e(url('/admin/ckcc')) ?>">
                    <?= icon('refresh') ?> <?= et('nav.ckcc') ?>
                </a>
                <a class="<?= nav_active('/admin/sss') ?>" href="<?= e(url('/admin/sss')) ?>">
                    <?= icon('shield') ?> <?= et('nav.sss') ?>
                </a>
                <a class="<?= nav_active('/admin/sss-targets') ?>" href="<?= e(url('/admin/sss-targets')) ?>">
                    <?= icon('target') ?> <?= et('nav.sss_targets') ?>
                </a>

                <div class="nav-section"><?= et('nav.section.organisation') ?></div>
                <a class="<?= nav_active('/admin/branches') ?>" href="<?= e(url('/admin/branches')) ?>">
                    <?= icon('building') ?> <?= et('nav.branches') ?>
                </a>
                <a class="<?= nav_active('/admin/managers') ?>" href="<?= e(url('/admin/managers')) ?>">
                    <?= icon('user') ?> <?= et('nav.branch_managers') ?>
                </a>
                <a class="<?= nav_active('/admin/supervisors') ?>" href="<?= e(url('/admin/supervisors')) ?>">
                    <?= icon('users') ?> <?= et('nav.bc_supervisors') ?>
                </a>
                <a class="<?= nav_active('/admin/targets') ?>" href="<?= e(url('/admin/targets')) ?>">
                    <?= icon('target') ?> <?= et('nav.targets') ?>
                </a>

                <div class="nav-section"><?= et('nav.section.reporting') ?></div>
                <a class="<?= nav_active('/admin/reports') ?>" href="<?= e(url('/admin/reports')) ?>">
                    <?= icon('chart') ?> <?= et('nav.reports') ?>
                </a>
                <a class="<?= nav_active('/admin/deadline') ?>" href="<?= e(url('/admin/deadline')) ?>">
                    <?= icon('clock') ?> <?= et('nav.report_deadline') ?>
                    <?php if (!empty($badges['late_pending'])): ?>
                        <span class="count alert"><?= (int) $badges['late_pending'] ?></span>
                    <?php endif; ?>
                </a>

                <div class="nav-section"><?= et('nav.section.configuration') ?></div>
                <a class="<?= nav_active('/admin/forms/visit') ?>" href="<?= e(url('/admin/forms/visit')) ?>">
                    <?= icon('list') ?> <?= et('nav.visit_form_builder') ?>
                </a>
                <a class="<?= nav_active('/admin/forms/inspection') ?>" href="<?= e(url('/admin/forms/inspection')) ?>">
                    <?= icon('sliders') ?> <?= et('nav.inspection_form_builder') ?>
                </a>
                <a class="<?= nav_active('/admin/settings') ?>" href="<?= e(url('/admin/settings')) ?>">
                    <?= icon('settings') ?> <?= et('nav.settings') ?>
                </a>
                <a class="<?= nav_active('/admin/audit') ?>" href="<?= e(url('/admin/audit')) ?>">
                    <?= icon('shield') ?> <?= et('nav.audit_log') ?>
                </a>
            <?php else: ?>
                <a class="<?= (new App\Core\Request())->path() === '/manager' ? 'active' : '' ?>" href="<?= e(url('/manager')) ?>">
                    <?= icon('dashboard') ?> Dashboard
                </a>
                <a class="<?= nav_active('/manager/accounts') ?>" href="<?= e(url('/manager/accounts')) ?>">
                    <?= icon('database') ?> <?= et('nav.accounts') ?>
                </a>
                <a class="<?= nav_active('/manager/supervisors') ?>" href="<?= e(url('/manager/supervisors')) ?>">
                    <?= icon('users') ?> BCAs
                </a>
                <a class="<?= nav_active('/manager/visits') ?>" href="<?= e(url('/manager/visits')) ?>">
                    <?= icon('clipboard') ?> <?= et('nav.visits') ?>
                </a>
                <a class="<?= nav_active('/manager/recovery') ?>" href="<?= e(url('/manager/recovery')) ?>">
                    <?= icon('rupee') ?> <?= et('nav.recovery_ptp') ?>
                </a>
                <a class="<?= nav_active('/manager/pending') ?>" href="<?= e(url('/manager/pending')) ?>">
                    <?= icon('alert') ?> <?= et('nav.pending_accounts') ?>
                </a>
                <a class="<?= nav_active('/manager/performance') ?>" href="<?= e(url('/manager/performance')) ?>">
                    <?= icon('chart') ?> <?= et('nav.performance') ?>
                </a>
                <a class="<?= nav_active('/manager/reports') ?>" href="<?= e(url('/manager/reports')) ?>">
                    <?= icon('file-text') ?> <?= et('nav.reports') ?>
                </a>
            <?php endif; ?>

            <div class="nav-section"><?= et('nav.section.account') ?></div>
            <a class="<?= nav_active($base . '/notifications') ?>" href="<?= e(url($base . '/notifications')) ?>">
                <?= icon('bell') ?> <?= et('nav.notifications') ?>
                <?php if ($unread > 0): ?><span class="count"><?= $unread ?></span><?php endif; ?>
            </a>
            <a class="<?= nav_active('/password') ?>" href="<?= e(url('/password/change')) ?>">
                <?= icon('lock') ?> <?= et('auth.change_password') ?>
            </a>
            <form method="post" action="<?= e(url('/logout')) ?>" style="margin:0">
                <?= csrf_field() ?>
                <button type="submit" class="nav-logout" style="all:unset;display:flex;align-items:center;gap:10px;padding:8px 12px;border-radius:6px;color:#c3d0e0;font-size:13.5px;cursor:pointer;width:100%">
                    <?= icon('log-out') ?> <?= et('auth.sign_out') ?>
                </button>
            </form>
        </nav>
    </aside>

    <div class="main">
        <header class="topbar">
            <button class="btn btn-secondary btn-sm menu-toggle" data-menu-toggle type="button" aria-label="<?= et('topbar.menu') ?>">
                <?= icon('menu', '', 16) ?>
            </button>
            <div class="page-title"><?= e($title ?? __('nav.dashboard')) ?></div>
            <div class="spacer"></div>

            <?php if ($deadline['is_working_day']): ?>
                <span class="deadline-chip <?= $deadline['has_passed'] ? 'passed' : '' ?>" data-countdown="<?= (int) $deadline['seconds_remaining'] ?>">
                    <?= $deadline['has_passed']
                        ? et('topbar.deadline_passed')
                        : et('topbar.report_deadline', ['time' => format_time($deadline['deadline_at'])]) ?>
                </span>
            <?php else: ?>
                <span class="deadline-chip"><?= et('topbar.non_working_day') ?></span>
            <?php endif; ?>

            <?= view_partial('partials.locale-switcher', ['compact' => true]) ?>

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
