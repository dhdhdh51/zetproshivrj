<?php
/** @var string $name */
/** @var string $link */
?>
<h1 style="margin:0 0 16px;font-size:21px;">Reset your password</h1>
<p style="margin:0 0 14px;">Hi <?= e($name) ?>,</p>
<p style="margin:0 0 22px;">We received a request to reset the password for your <?= e(app_name()) ?> account. Choose a new password using the button below.</p>
<p style="margin:0 0 26px;">
    <a href="<?= e($link) ?>" style="display:inline-block;background:#4f46e5;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:9px;font-weight:600;">Choose a new password</a>
</p>
<p style="margin:0 0 8px;font-size:13px;color:#64748b;">Or paste this link into your browser:</p>
<p style="margin:0 0 22px;font-size:13px;word-break:break-all;"><a href="<?= e($link) ?>" style="color:#4f46e5;"><?= e($link) ?></a></p>
<p style="margin:0;font-size:13px;color:#64748b;">This link expires in 60 minutes and can be used once. If you did not request a reset, no action is needed.</p>
