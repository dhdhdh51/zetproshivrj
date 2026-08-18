<?php
/**
 * @var array $plans
 */
$features = [
    ['icon' => 'sparkles', 'title' => 'AI document generation', 'text' => 'Describe the job in one sentence. DocuPilot drafts the title, scope, line items, notes and terms for you.'],
    ['icon' => 'palette', 'title' => 'Professional templates', 'text' => 'Three client-ready designs — Modern, Corporate and Minimal — with your logo, GSTIN and bank details.'],
    ['icon' => 'download', 'title' => 'PDF export', 'text' => 'One click produces a clean, print-perfect A4 PDF you can download, archive or attach anywhere.'],
    ['icon' => 'link', 'title' => 'Client sharing', 'text' => 'Publish a secure, unguessable link so clients can view and download without signing up.'],
    ['icon' => 'mail', 'title' => 'Email delivery', 'text' => 'Send the PDF straight from your own SMTP with an AI-written covering email, and keep a delivery log.'],
    ['icon' => 'calculator', 'title' => 'Totals you can trust', 'text' => 'Quantity, rate, tax and discount maths is recalculated on the server every single time you save.'],
];

$steps = [
    ['title' => 'Enter your requirement', 'text' => '“Quotation for ABC Technologies, website development ₹40,000, 3 months maintenance.”'],
    ['title' => 'Generate with AI', 'text' => 'Line items, scope summary, notes and terms are drafted in seconds.'],
    ['title' => 'Edit your document', 'text' => 'Adjust wording, pricing, tax and discounts. You are always in control.'],
    ['title' => 'Download or send', 'text' => 'Export the PDF, share a secure link or email it to your client.'],
];

$faqs = [
    ['q' => 'Do I need to write clever AI prompts?', 'a' => 'No. One or two plain sentences is enough — DocuPilot asks the AI for structured content behind the scenes and fills the document form for you.'],
    ['q' => 'Can the AI invent prices or my GST number?', 'a' => 'No. The AI is explicitly instructed never to produce GSTIN, tax numbers, bank details, addresses or phone numbers, and it may only use amounts you supply. Every total is recalculated on our server from your line items.'],
    ['q' => 'Which documents can I create?', 'a' => 'Quotations, invoices, proposals, estimates and purchase orders, each with automatic numbering like QT-' . date('Y') . '-0001 or INV-' . date('Y') . '-0001.'],
    ['q' => 'How do I send documents to clients?', 'a' => 'Download the PDF, publish a secure share link, or email the PDF directly from the app using your own SMTP credentials on the Pro and Business plans.'],
    ['q' => 'What happens when I hit my monthly limit?', 'a' => 'Document and AI limits are checked on the server before each action. You will be prompted to upgrade, and your existing documents always stay accessible.'],
    ['q' => 'How are payments handled?', 'a' => 'Paid plans are purchased through PayU. Card details never reach our servers and a subscription is only activated after the payment signature and status are verified.'],
];
?>
<section class="hero">
    <div class="container text-center">
        <span class="eyebrow"><?= icon('sparkles', '', 15) ?> AI drafting · PDF export · Client sharing</span>
        <h1>Create professional business documents with AI.</h1>
        <p class="lead">
            Create quotations, invoices, proposals and client-ready documents in minutes —
            built for freelancers, agencies, consultants and small businesses.
        </p>
        <div class="btn-group-dp justify-content-center">
            <a href="<?= e(url('register')) ?>" class="btn-dp btn-primary-dp btn-lg-dp">Start Creating <?= icon('arrow-right', '', 18) ?></a>
            <a href="<?= e(url('pricing')) ?>" class="btn-dp btn-outline-dp btn-lg-dp">View Pricing</a>
        </div>
        <p class="text-muted-2 mt-3 mb-0" style="font-size:.88rem">
            Free plan · 5 documents and 5 AI generations every month · no card required
        </p>

        <div class="hero-visual text-start">
            <div class="hero-visual__bar"><i></i><i></i><i></i></div>
            <div class="p-3 p-md-4">
                <div class="row g-3">
                    <div class="col-md-5">
                        <div class="ai-panel h-100">
                            <h3 style="font-size:1rem"><?= icon('sparkles', '', 18) ?> What do you want to create?</h3>
                            <p class="hint mb-2">Plain English is enough.</p>
                            <div class="input-dp" style="min-height:96px;background:#fff;font-size:.88rem;color:#334155">
                                Create a professional quotation for ABC Technologies for website development
                                worth ₹40,000 including 3 months maintenance.
                            </div>
                            <span class="btn-dp btn-primary-dp btn-sm-dp mt-3"><?= icon('sparkles', '', 15) ?> Generate with AI</span>
                        </div>
                    </div>
                    <div class="col-md-7">
                        <div class="card-dp h-100">
                            <div class="card-dp__head" style="padding:12px 16px">
                                <span class="badge badge-primary mono">QT-<?= date('Y') ?>-0001</span>
                                <span class="badge badge-muted">Draft</span>
                            </div>
                            <div class="card-dp__body" style="padding:14px 16px">
                                <table class="table-dp" style="font-size:.84rem">
                                    <thead><tr><th>Description</th><th class="num">Qty</th><th class="num">Rate</th><th class="num">Amount</th></tr></thead>
                                    <tbody>
                                    <tr><td>Website design &amp; development</td><td class="num">1</td><td class="num">₹34,000.00</td><td class="num">₹34,000.00</td></tr>
                                    <tr><td>Maintenance &amp; support (3 months)</td><td class="num">3</td><td class="num">₹2,000.00</td><td class="num">₹6,000.00</td></tr>
                                    </tbody>
                                </table>
                                <div class="totals-box mt-2" style="padding:12px 14px">
                                    <div class="totals-row"><span>Subtotal</span><span>₹40,000.00</span></div>
                                    <div class="totals-row"><span>Tax (18%)</span><span>₹7,200.00</span></div>
                                    <div class="totals-row grand" style="font-size:1rem"><span>Grand total</span><span>₹47,200.00</span></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="section section--alt" id="features">
    <div class="container">
        <div class="section-head">
            <h2>Everything you need to look professional</h2>
            <p>From the first draft to the client's inbox — without leaving your browser.</p>
        </div>
        <div class="row g-3">
            <?php foreach ($features as $feature): ?>
                <div class="col-md-6 col-lg-4">
                    <div class="feature">
                        <div class="feature__icon"><?= icon($feature['icon'], '', 21) ?></div>
                        <h3><?= e($feature['title']) ?></h3>
                        <p><?= e($feature['text']) ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="section" id="how-it-works">
    <div class="container">
        <div class="section-head">
            <h2>How it works</h2>
            <p>Four steps from idea to a document your client can act on.</p>
        </div>
        <div class="row g-4">
            <?php foreach ($steps as $index => $step): ?>
                <div class="col-md-6 col-lg-3">
                    <div class="step">
                        <b><?= $index + 1 ?></b>
                        <h3 style="font-size:1rem"><?= e($step['title']) ?></h3>
                        <p class="text-muted-2 mb-0" style="font-size:.9rem"><?= e($step['text']) ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="section section--alt">
    <div class="container">
        <div class="section-head">
            <h2>Supported documents</h2>
            <p>Every type gets its own automatic numbering series.</p>
        </div>
        <div class="row g-3 justify-content-center">
            <?php foreach (document_types() as $type => $meta): ?>
                <div class="col-6 col-md-4 col-lg-2">
                    <div class="feature text-center h-100">
                        <div class="feature__icon mx-auto"><?= icon($meta['icon'], '', 21) ?></div>
                        <h3 style="font-size:.95rem"><?= e($meta['label']) ?>s</h3>
                        <p class="mono" style="font-size:.78rem"><?= e($meta['prefix']) ?>-<?= date('Y') ?>-0001</p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="section" id="pricing">
    <div class="container">
        <div class="section-head">
            <h2>Straightforward pricing</h2>
            <p>Start on Free. Upgrade the moment your document volume grows.</p>
        </div>
        <div class="row g-4">
            <?php foreach ($plans as $plan): ?>
                <div class="col-md-4">
                    <div class="price-card <?= (string) $plan['slug'] === 'pro' ? 'featured' : '' ?>">
                        <h3 class="mb-1"><?= e((string) $plan['name']) ?></h3>
                        <p class="text-muted-2" style="font-size:.9rem"><?= e((string) ($plan['description'] ?? '')) ?></p>
                        <div class="price-card__price">
                            <?= e(money((float) $plan['price'], (string) $plan['currency'])) ?><small>/month</small>
                        </div>
                        <ul>
                            <?php foreach (($plan['features_list'] !== [] ? $plan['features_list'] : []) as $feature): ?>
                                <li><?= icon('check-circle', '', 17) ?><span><?= e((string) $feature) ?></span></li>
                            <?php endforeach; ?>
                        </ul>
                        <a href="<?= e(url('register')) ?>" class="btn-dp <?= (string) $plan['slug'] === 'pro' ? 'btn-primary-dp' : 'btn-outline-dp' ?> btn-block-dp">
                            <?= $plan['is_free'] ? 'Start free' : 'Choose ' . e((string) $plan['name']) ?>
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <p class="text-center text-muted-2 mt-4 mb-0" style="font-size:.88rem">
            <?= icon('shield', '', 15) ?> Secure payments via PayU · Cancel any time · <a href="<?= e(url('pricing')) ?>">Full plan comparison</a>
        </p>
    </div>
</section>

<section class="section section--alt" id="faq">
    <div class="container" style="max-width:780px">
        <div class="section-head">
            <h2>Frequently asked questions</h2>
            <p>Everything freelancers and agencies ask us before signing up.</p>
        </div>
        <?php foreach ($faqs as $faq): ?>
            <details class="faq-item">
                <summary><?= e($faq['q']) ?></summary>
                <p><?= e($faq['a']) ?></p>
            </details>
        <?php endforeach; ?>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="card-dp text-center" style="background:linear-gradient(135deg,#4338ca,#0f172a);border:0">
            <div class="card-dp__body" style="padding:46px 24px">
                <h2 style="color:#fff">Your next quotation is two minutes away</h2>
                <p style="color:#cbd5e1;max-width:520px;margin:10px auto 24px">
                    Join freelancers, agencies and consultants who send professional documents without the formatting busywork.
                </p>
                <div class="btn-group-dp justify-content-center">
                    <a href="<?= e(url('register')) ?>" class="btn-dp btn-primary-dp btn-lg-dp" style="background:#fff;color:#312e81">
                        Start Creating <?= icon('arrow-right', '', 18) ?>
                    </a>
                    <a href="<?= e(url('pricing')) ?>" class="btn-dp btn-outline-dp btn-lg-dp" style="background:transparent;color:#fff;border-color:rgba(255,255,255,.4)">
                        View Pricing
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>
