<?php /** @var string|null $message */ ?>
<div class="code">403</div>
<h1>Access denied</h1>
<p class="muted">
    <?= e($message !== null && $message !== '' ? $message : 'You do not have permission to view this page. If you believe this is wrong, contact your BC Supervisor.') ?>
</p>
<p style="margin-top:18px">
    <a class="btn" href="<?= e(url('/')) ?>">Back to LRMS</a>
</p>
<?php if (!empty($exception)): ?>
    <pre style="text-align:left;background:#0b1220;color:#cbd5e1;padding:14px;border-radius:8px;overflow:auto;font-size:12px;margin-top:20px"><?= e($exception->getMessage()) ?>

<?= e($exception->getFile() . ':' . $exception->getLine()) ?>

<?= e($exception->getTraceAsString()) ?></pre>
<?php endif; ?>
