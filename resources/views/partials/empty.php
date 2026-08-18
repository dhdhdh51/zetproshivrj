<?php
/**
 * @var string      $message
 * @var string|null $hint
 * @var string      $iconName
 */
?>
<div class="empty">
    <?= icon($iconName ?? 'database', '', 34) ?>
    <h3><?= e($message ?? 'Nothing to show yet') ?></h3>
    <?php if (!empty($hint)): ?>
        <p class="small"><?= e($hint) ?></p>
    <?php endif; ?>
    <?php if (!empty($actionUrl) && !empty($actionLabel)): ?>
        <p><a class="btn btn-sm" href="<?= e(url($actionUrl)) ?>"><?= e($actionLabel) ?></a></p>
    <?php endif; ?>
</div>
