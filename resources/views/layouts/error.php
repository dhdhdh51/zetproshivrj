<?php
/** @var string $content */
$noindex = true;
?>
<!doctype html>
<html lang="en">
<head>
    <?= view_partial('partials.head', get_defined_vars()) ?>
</head>
<body>
<div class="error-page">
    <div class="container" style="max-width:760px">
        <a class="brand-mark justify-content-center mb-4" href="<?= e(url('/')) ?>">
            <span class="brand-mark__logo">DP</span>
            <span><?= e(app_name()) ?></span>
        </a>
        <?= $content ?>
    </div>
</div>
<script src="<?= e(asset('js/app.js')) ?>?v=1"></script>
</body>
</html>
