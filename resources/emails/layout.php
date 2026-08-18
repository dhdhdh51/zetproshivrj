<?php
/** @var string $content */
$appName = app_name();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($appName) ?></title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#0f172a;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:32px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 1px 3px rgba(15,23,42,.08);">
                <tr>
                    <td style="background:#4f46e5;padding:22px 28px;">
                        <span style="color:#ffffff;font-size:18px;font-weight:700;letter-spacing:-.2px;"><?= e($appName) ?></span>
                    </td>
                </tr>
                <tr>
                    <td style="padding:32px 28px;font-size:15px;line-height:1.65;">
                        <?= $content ?>
                    </td>
                </tr>
                <tr>
                    <td style="padding:18px 28px;background:#f8fafc;border-top:1px solid #e2e8f0;font-size:12px;color:#64748b;">
                        Sent by <?= e($appName) ?> · <a href="<?= e(url('/')) ?>" style="color:#4f46e5;text-decoration:none;"><?= e(str_replace(['https://', 'http://'], '', base_url())) ?></a>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
