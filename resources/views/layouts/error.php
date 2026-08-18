<!doctype html>
<html lang="en">
<head><?= view_partial('partials.head', ['title' => $title ?? 'Error']) ?></head>
<body>
<div class="error-shell">
    <div class="error-card">
        <?= $content ?>
    </div>
</div>
</body>
</html>
