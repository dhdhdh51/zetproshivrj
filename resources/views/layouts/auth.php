<!doctype html>
<html lang="<?= e(current_locale()) ?>">
<head><?= view_partial('partials.head', ['title' => $title ?? __('auth.sign_in')]) ?></head>
<body>
<div class="auth-shell">
    <div style="width:100%;max-width:410px">
        <div class="auth-card">
            <div class="brand">
                <div class="logo">LRMS</div>
                <div>
                    <div style="font-weight:680"><?= e(app_name()) ?></div>
                    <div class="small muted"><?= e(org_name()) ?></div>
                </div>
            </div>

            <?= view_partial('partials.flash') ?>
            <?= $content ?>
        </div>
        <div class="auth-foot">
            <?= view_partial('partials.locale-switcher') ?>
            <div><?= et('auth.footer_notice') ?></div>
        </div>
    </div>
</div>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
