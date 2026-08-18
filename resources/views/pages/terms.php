<?php $loggedIn = App\Core\Auth::check(); ?>
<div class="<?= $loggedIn ? '' : 'section' ?>">
    <div class="<?= $loggedIn ? '' : 'container' ?>" style="max-width:820px">
        <h1>Terms of Service</h1>
        <p class="text-muted-2">Last updated <?= e(date('d F Y')) ?></p>

        <div class="card-dp mt-3">
            <div class="card-dp__body">
                <h2>1. Your account</h2>
                <p>You are responsible for the accuracy of the information in your account and business profile, and for
                    keeping your password secure. One person or business per account.</p>

                <h2>2. Acceptable use</h2>
                <p>Use <?= e(app_name()) ?> for legitimate business documents only. Do not use it to create fraudulent
                    invoices, impersonate another business, or to send unsolicited bulk email.</p>

                <h2>3. Your content</h2>
                <p>Your documents, clients and business details remain yours. You grant us permission to store and
                    process them solely to provide the service.</p>

                <h2>4. AI-generated content</h2>
                <p>AI drafts are a starting point, not professional advice. You are responsible for reviewing every
                    document — including all amounts, taxes and legal terms — before sending it to a client. We do not
                    guarantee that generated wording is suitable, complete or compliant for your jurisdiction.</p>

                <h2>5. Plans, limits and payments</h2>
                <p>Free and paid plans include monthly document and AI generation limits that are enforced when you use
                    the service. Paid plans are billed in advance through PayU for the period shown at checkout. A plan
                    is activated only after the payment is verified.</p>

                <h2>6. Refunds</h2>
                <p>If a payment is charged but your plan is not activated, contact us and we will either activate it or
                    refund the amount.</p>

                <h2>7. Availability</h2>
                <p>We work to keep the service available but do not guarantee uninterrupted access. Scheduled
                    maintenance may take the app offline briefly.</p>

                <h2>8. Liability</h2>
                <p>The service is provided “as is”. To the fullest extent permitted by law our liability is limited to
                    the fees you paid in the previous month.</p>

                <h2>9. Termination</h2>
                <p>You may stop using the service at any time. We may suspend accounts that breach these terms.</p>

                <h2>10. Changes</h2>
                <p>We may update these terms; material changes will be announced in the app.</p>

                <p class="mt-3"><a href="<?= e(url('contact')) ?>">Questions? Contact us</a>.</p>
            </div>
        </div>
    </div>
</div>
