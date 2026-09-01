<?php
/**
 * Print-oriented layout used by the on-screen report views (visit report,
 * inspection report) so a browser "Print" gives a clean document even when the
 * PDF export is not used.
 */
?>
<!doctype html>
<html lang="en">
<head><?= view_partial('partials.head', ['title' => $title ?? 'Report']) ?></head>
<body style="background:#fff">
<div class="content" style="max-width:960px;margin:0 auto">
    <div class="no-print" style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
        <a class="btn btn-secondary btn-sm" href="<?= e($backUrl ?? url('/admin')) ?>"><?= icon('arrow-left', '', 15) ?> Back</a>
        <?php foreach (($exportLinks ?? []) as $label => $href): ?>
            <a class="btn btn-secondary btn-sm" href="<?= e($href) ?>"><?= icon('download', '', 15) ?> <?= e($label) ?></a>
        <?php endforeach; ?>
    </div>
    <?= view_partial('partials.flash') ?>
    <?= $content ?>
</div>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
