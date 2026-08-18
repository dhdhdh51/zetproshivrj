<?php
/** @var string $content */
?>
<!doctype html>
<html lang="en">
<head>
    <?= view_partial('partials.head', get_defined_vars()) ?>
</head>
<body>
<div class="auth-wrap">
    <div class="auth-side">
        <a class="brand-mark" href="<?= e(url('/')) ?>" style="color:#fff">
            <span class="brand-mark__logo">DP</span>
            <span style="color:#fff"><?= e(app_name()) ?></span>
        </a>

        <div>
            <h2>Create professional business documents with AI.</h2>
            <p class="mt-3" style="color:#cbd5e1">
                Quotations, invoices, proposals, estimates and purchase orders — drafted, formatted
                and client-ready in minutes.
            </p>
            <ul>
                <li><?= icon('sparkles', '', 19) ?><span>Describe the job in plain English, AI writes the document.</span></li>
                <li><?= icon('palette', '', 19) ?><span>Three professional templates: Modern, Corporate, Minimal.</span></li>
                <li><?= icon('download', '', 19) ?><span>Instant PDF export with your logo, GST and bank details.</span></li>
                <li><?= icon('send', '', 19) ?><span>Share a secure link or email the PDF straight to your client.</span></li>
            </ul>
        </div>

        <p class="mb-0" style="color:#94a3b8;font-size:.85rem">
            &copy; <?= date('Y') ?> <?= e(app_name()) ?> · <a href="<?= e(url('privacy')) ?>" style="color:#cbd5e1">Privacy</a>
            · <a href="<?= e(url('terms')) ?>" style="color:#cbd5e1">Terms</a>
        </p>
    </div>

    <div class="auth-panel">
        <div class="auth-card">
            <?= view_partial('partials.flash') ?>
            <?= $content ?>
        </div>
    </div>
</div>

<script src="<?= e(asset('js/app.js')) ?>?v=1"></script>
</body>
</html>
