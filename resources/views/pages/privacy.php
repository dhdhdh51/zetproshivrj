<?php $loggedIn = App\Core\Auth::check(); ?>
<div class="<?= $loggedIn ? '' : 'section' ?>">
    <div class="<?= $loggedIn ? '' : 'container' ?>" style="max-width:820px">
        <h1>Privacy Policy</h1>
        <p class="text-muted-2">Last updated <?= e(date('d F Y')) ?></p>

        <div class="card-dp mt-3">
            <div class="card-dp__body">
                <h2>What we collect</h2>
                <p>We store the information you give us to run the product: your name and email address, your business
                    profile (business name, logo, address, tax and bank details), your clients, the documents you create
                    and the payment records created when you upgrade a plan.</p>

                <h2>How we use it</h2>
                <p>Your data is used only to generate, store and deliver your documents, to enforce plan limits and to
                    support you. We do not sell personal data or use your documents for advertising.</p>

                <h2>AI processing</h2>
                <p>When you use an AI feature, the relevant document context — document type, business name, client
                    name, line items and your instructions — is sent to OpenRouter to generate text. We do not send your
                    bank account number, GSTIN or passwords to the AI provider, and the AI is instructed never to
                    generate such details. AI requests are made server-side; our API key is never exposed to your browser.</p>

                <h2>Payments</h2>
                <p>Card payments are processed by PayU. We never see or store card numbers, CVV codes or bank
                    credentials — only the transaction reference, amount and status returned by PayU.</p>

                <h2>Email</h2>
                <p>Documents you send are delivered through the SMTP server configured for this installation. We keep a
                    log of recipient, subject and delivery status so you can prove what was sent and when.</p>

                <h2>Security</h2>
                <p>Passwords are stored as one-way hashes. Sessions are HTTP-only and expire when idle. Every database
                    query uses prepared statements, forms are CSRF-protected, uploads are validated by content type and
                    document access is checked against the signed-in account on every request.</p>

                <h2>Retention and deletion</h2>
                <p>Your documents stay available while your account exists. Deleting a document removes it and its
                    generated PDF. If you would like your account and all associated data removed, contact us and we
                    will action it.</p>

                <h2>Contact</h2>
                <p>Questions about this policy? <a href="<?= e(url('contact')) ?>">Get in touch</a>.</p>
            </div>
        </div>
    </div>
</div>
