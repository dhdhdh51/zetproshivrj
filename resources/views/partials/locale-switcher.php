<?php

/**
 * English / हिन्दी toggle.
 *
 * A POST form rather than a link: it writes a cookie, so it carries the CSRF
 * token like every other state-changing request in the panel. Each language is
 * labelled in its own script — someone looking for Hindi is looking for
 * "हिन्दी", not for the word "Hindi" spelled in English.
 */

$current = current_locale();
$compact = !empty($compact);
?>
<form method="post" action="<?= e(url('/locale')) ?>" class="locale-switch<?= $compact ? ' locale-switch-compact' : '' ?>">
    <?= csrf_field() ?>
    <?php if (!$compact): ?>
        <span class="locale-switch-label"><?= et('locale.label') ?></span>
    <?php endif; ?>
    <?php foreach (locale_names() as $code => $name): ?>
        <?php if ($code === $current): ?>
            <span class="locale-option active" aria-current="true"><?= e($name) ?></span>
        <?php else: ?>
            <button type="submit" name="locale" value="<?= e($code) ?>" class="locale-option"
                    title="<?= et('locale.switch') ?>"><?= e($name) ?></button>
        <?php endif; ?>
    <?php endforeach; ?>
</form>
