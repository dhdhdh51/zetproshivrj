<?php
/** @var string $name */
/** @var string $link */
?>
<h1 style="margin:0 0 16px;font-size:21px;">Confirm your email address</h1>
<p style="margin:0 0 14px;">Hi <?= e($name) ?>,</p>
<p style="margin:0 0 22px;">Welcome to <?= e(app_name()) ?>. Please confirm your email address so we can secure your account and send you your documents.</p>
<p style="margin:0 0 26px;">
    <a href="<?= e($link) ?>" style="display:inline-block;background:#4f46e5;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:9px;font-weight:600;">Confirm email address</a>
</p>
<p style="margin:0 0 8px;font-size:13px;color:#64748b;">Or paste this link into your browser:</p>
<p style="margin:0 0 22px;font-size:13px;word-break:break-all;"><a href="<?= e($link) ?>" style="color:#4f46e5;"><?= e($link) ?></a></p>
<p style="margin:0;font-size:13px;color:#64748b;">This link expires in 48 hours. If you did not create this account you can ignore this email.</p>
