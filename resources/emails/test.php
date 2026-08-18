<?php
/** @var string $when */
?>
<h1 style="margin:0 0 16px;font-size:21px;">Your SMTP settings work</h1>
<p style="margin:0 0 14px;">This is a test email from <?= e(app_name()) ?>, sent at <?= e($when) ?>.</p>
<p style="margin:0;">If you can read this, verification emails, password resets and document delivery will all work with the current SMTP configuration.</p>
