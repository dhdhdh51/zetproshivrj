<?php
/** @var string $content */
$logo = site_logo_url();
$loggedIn = App\Core\Auth::check();
?>
<!doctype html>
<html lang="en">
<head>
    <?= view_partial('partials.head', get_defined_vars()) ?>
    <script type="application/ld+json">
    <?= json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'SoftwareApplication',
        'name' => app_name(),
        'applicationCategory' => 'BusinessApplication',
        'operatingSystem' => 'Web',
        'description' => 'Create professional quotations, invoices, proposals and client-ready business documents with AI in minutes.',
        'url' => base_url(),
        'offers' => [
            '@type' => 'Offer',
            'price' => '0',
            'priceCurrency' => 'INR',
            'description' => 'Free plan with 5 documents and 5 AI generations per month.',
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?>
    </script>
</head>
<body>
<header class="site-header">
    <div class="container">
        <div class="site-header__inner">
            <a class="brand-mark" href="<?= e(url('/')) ?>">
                <?php if ($logo !== null): ?>
                    <img class="brand-mark__logo" src="<?= e($logo) ?>" alt="<?= e(app_name()) ?>">
                <?php else: ?>
                    <span class="brand-mark__logo">DP</span>
                <?php endif; ?>
                <span><?= e(app_name()) ?></span>
            </a>

            <nav class="site-nav d-none d-md-flex">
                <a href="<?= e(url('/#features')) ?>">Features</a>
                <a href="<?= e(url('/#how-it-works')) ?>">How it works</a>
                <a href="<?= e(url('pricing')) ?>">Pricing</a>
                <a href="<?= e(url('/#faq')) ?>">FAQ</a>
            </nav>

            <div class="d-flex align-items-center gap-2">
                <?php if ($loggedIn): ?>
                    <a href="<?= e(url('dashboard')) ?>" class="btn-dp btn-primary-dp btn-sm-dp">
                        Go to dashboard <?= icon('arrow-right', '', 16) ?>
                    </a>
                <?php else: ?>
                    <a href="<?= e(url('login')) ?>" class="btn-dp btn-ghost-dp btn-sm-dp">Sign in</a>
                    <a href="<?= e(url('register')) ?>" class="btn-dp btn-primary-dp btn-sm-dp">Start free</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</header>

<?= view_partial('partials.flash') ?>
<?= $content ?>

<footer class="site-footer">
    <div class="container">
        <div class="row g-4">
            <div class="col-lg-4">
                <a class="brand-mark" href="<?= e(url('/')) ?>" style="color:#fff">
                    <span class="brand-mark__logo">DP</span>
                    <span style="color:#fff"><?= e(app_name()) ?></span>
                </a>
                <p class="mt-3 mb-0" style="max-width:320px">
                    Create professional business documents with AI, in minutes. Built for freelancers,
                    agencies, consultants and small businesses.
                </p>
            </div>

            <div class="col-6 col-lg-2">
                <h4>Product</h4>
                <a href="<?= e(url('pricing')) ?>">Pricing</a>
                <a href="<?= e(url('/#features')) ?>">Features</a>
                <a href="<?= e(url('/#how-it-works')) ?>">How it works</a>
                <a href="<?= e(url('register')) ?>">Create account</a>
            </div>

            <div class="col-6 col-lg-2">
                <h4>Documents</h4>
                <a href="<?= e(url('register')) ?>">Quotations</a>
                <a href="<?= e(url('register')) ?>">Invoices</a>
                <a href="<?= e(url('register')) ?>">Proposals</a>
                <a href="<?= e(url('register')) ?>">Estimates &amp; POs</a>
            </div>

            <div class="col-6 col-lg-2">
                <h4>Company</h4>
                <a href="<?= e(url('contact')) ?>">Contact</a>
                <a href="<?= e(url('privacy')) ?>">Privacy</a>
                <a href="<?= e(url('terms')) ?>">Terms</a>
            </div>

            <div class="col-6 col-lg-2">
                <h4>Account</h4>
                <a href="<?= e(url('login')) ?>">Sign in</a>
                <a href="<?= e(url('password/forgot')) ?>">Reset password</a>
            </div>
        </div>

        <div class="site-footer__bottom d-flex flex-wrap gap-2 justify-content-between">
            <span>&copy; <?= date('Y') ?> <?= e(app_name()) ?>. All rights reserved.</span>
            <span>Made for freelancers, agencies &amp; small businesses.</span>
        </div>
    </div>
</footer>

<script src="<?= e(asset('js/app.js')) ?>?v=1"></script>
</body>
</html>
