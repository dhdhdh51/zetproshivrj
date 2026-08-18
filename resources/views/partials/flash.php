<?php

use App\Core\Session;

$messages = Session::pullFlash();
$errors = Session::errors();

if ($messages === [] && $errors === []) {
    return;
}
?>
<div class="flash">
    <?php foreach ($messages as $message): ?>
        <?php
        $type = (string) $message['type'];
        $class = match ($type) {
            'success' => 'alert-success',
            'error', 'danger' => 'alert-error',
            'warning' => 'alert-warning',
            default => 'alert-info',
        };
        $iconName = match ($type) {
            'success' => 'check-circle',
            'error', 'danger' => 'x-circle',
            'warning' => 'alert-triangle',
            default => 'info',
        };
        ?>
        <div class="alert <?= $class ?>"><?= icon($iconName, '', 17) ?><div><?= e($message['message']) ?></div></div>
    <?php endforeach; ?>

    <?php if ($errors !== [] && count($errors) > 1): ?>
        <div class="alert alert-error">
            <?= icon('alert-triangle', '', 17) ?>
            <div>
                <strong>Please correct the following:</strong>
                <ul style="margin:6px 0 0 16px;padding:0">
                    <?php foreach ($errors as $field => $message): ?>
                        <li><?= e(is_array($message) ? (string) reset($message) : (string) $message) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>
</div>
