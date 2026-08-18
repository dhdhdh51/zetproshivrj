<?php
/**
 * Flash messages: rendered as toast seeds for JS and as a no-JS fallback.
 */
$messages = App\Core\Session::pullFlash();

if ($messages === []) {
    return;
}
?>
<?php foreach ($messages as $message): ?>
    <div hidden data-flash="<?= e($message['type']) ?>" data-flash-message="<?= e($message['message']) ?>"></div>
<?php endforeach; ?>

<noscript>
    <?php foreach ($messages as $message): ?>
        <?php
        $class = match ($message['type']) {
            'success' => 'alert-success-dp',
            'error' => 'alert-danger-dp',
            'warning' => 'alert-warning-dp',
            default => 'alert-info-dp',
        };
        ?>
        <div class="alert-dp <?= $class ?>"><?= icon($message['type'] === 'success' ? 'check-circle' : 'info') ?><div><?= e($message['message']) ?></div></div>
    <?php endforeach; ?>
</noscript>
