<?php
/**
 * Shared <head> content: SEO meta, Open Graph, canonical URL and assets.
 *
 * @var string|null $title
 * @var string|null $meta_description
 * @var bool|null   $noindex
 */
$pageTitle = isset($title) && $title !== '' ? $title . ' · ' . app_name() : app_name() . ' — Create professional business documents with AI';
$description = $meta_description ?? 'Create professional quotations, invoices, proposals, estimates and purchase orders with AI in minutes. ' . app_name() . ' drafts, formats, exports and delivers client-ready documents.';
$canonical = ($request ?? null) instanceof App\Core\Request ? $request->fullUrl() : base_url() . (new App\Core\Request())->path();
$logo = site_logo_url();
?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?></title>
<meta name="description" content="<?= e(str_excerpt($description, 158)) ?>">
<link rel="canonical" href="<?= e($canonical) ?>">
<?php if (!empty($noindex)): ?>
    <meta name="robots" content="noindex, nofollow">
<?php else: ?>
    <meta name="robots" content="index, follow">
<?php endif; ?>

<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= e(app_name()) ?>">
<meta property="og:title" content="<?= e($pageTitle) ?>">
<meta property="og:description" content="<?= e(str_excerpt($description, 158)) ?>">
<meta property="og:url" content="<?= e($canonical) ?>">
<?php if ($logo !== null): ?>
    <meta property="og:image" content="<?= e($logo) ?>">
<?php endif; ?>
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= e($pageTitle) ?>">
<meta name="twitter:description" content="<?= e(str_excerpt($description, 158)) ?>">

<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="base-url" content="<?= e(base_url()) ?>">
<meta name="theme-color" content="#4f46e5">

<link rel="icon" href="data:image/svg+xml,<?= rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><rect width="32" height="32" rx="8" fill="#4f46e5"/><path d="M9 8h9l5 5v11a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2V10a2 2 0 0 1 2-2z" fill="#fff"/><path d="M12 17h8M12 21h5" stroke="#4f46e5" stroke-width="1.6" stroke-linecap="round"/></svg>') ?>">
<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>?v=1">
