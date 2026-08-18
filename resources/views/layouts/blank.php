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
<div class="container" style="max-width:560px;padding:60px 16px">
    <?= view_partial('partials.flash') ?>
    <?= $content ?>
</div>
<script src="<?= e(asset('js/app.js')) ?>?v=1"></script>
</body>
</html>
