<h1><?= et('app_only.title') ?></h1>

<p class="muted small"><?= et('app_only.intro') ?></p>

<div class="alert alert-info" style="margin-top:14px">
    <?= icon('smartphone', '', 17) ?>
    <div><?= et('app_only.device_note') ?></div>
</div>

<p style="margin-top:16px">
    <a class="btn btn-secondary btn-block" href="<?= e(url('/login')) ?>"><?= et('app_only.back') ?></a>
</p>
