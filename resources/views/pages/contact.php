<?php
/** @var string $contact_email */
$loggedIn = App\Core\Auth::check();
?>
<div class="<?= $loggedIn ? '' : 'section' ?>">
    <div class="<?= $loggedIn ? '' : 'container' ?>" style="max-width:900px">
        <h1>Contact us</h1>
        <p class="text-muted-2">Questions about plans, invoices or a feature you need? Send us a message.</p>

        <div class="row g-3 mt-2">
            <div class="col-lg-7">
                <div class="card-dp">
                    <form method="post" action="<?= e(url('contact')) ?>" novalidate>
                        <?= csrf_field() ?>
                        <div class="card-dp__body">
                            <div class="form-grid">
                                <div>
                                    <label class="form-label-dp" for="name">Your name</label>
                                    <input type="text" id="name" name="name" required class="input-dp <?= has_error('name') ? 'is-invalid-dp' : '' ?>"
                                           value="<?= e(old('name') !== '' ? old('name') : (string) (auth_user()['name'] ?? '')) ?>">
                                    <?php if (has_error('name')): ?><p class="field-error"><?= e(error_for('name')) ?></p><?php endif; ?>
                                </div>
                                <div>
                                    <label class="form-label-dp" for="email">Email</label>
                                    <input type="email" id="email" name="email" required class="input-dp <?= has_error('email') ? 'is-invalid-dp' : '' ?>"
                                           value="<?= e(old('email') !== '' ? old('email') : (string) (auth_user()['email'] ?? '')) ?>">
                                    <?php if (has_error('email')): ?><p class="field-error"><?= e(error_for('email')) ?></p><?php endif; ?>
                                </div>
                            </div>
                            <div class="form-row mt-3 mb-0">
                                <label class="form-label-dp" for="message">Message</label>
                                <textarea id="message" name="message" rows="6" required
                                          class="textarea-dp <?= has_error('message') ? 'is-invalid-dp' : '' ?>"><?= e(old('message')) ?></textarea>
                                <?php if (has_error('message')): ?><p class="field-error"><?= e(error_for('message')) ?></p><?php endif; ?>
                            </div>
                        </div>
                        <div class="card-dp__foot">
                            <button type="submit" class="btn-dp btn-primary-dp"><?= icon('send', '', 17) ?> Send message</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card-dp">
                    <div class="card-dp__head"><h3>Other ways to reach us</h3></div>
                    <div class="card-dp__body">
                        <dl class="kv mb-0">
                            <dt>Email</dt>
                            <dd><?= $contact_email !== '' ? '<a href="mailto:' . e($contact_email) . '">' . e($contact_email) . '</a>' : 'Use the form' ?></dd>
                            <dt>Response time</dt><dd>Within 1–2 business days</dd>
                            <dt>Billing help</dt><dd>Include your payment reference (DP…)</dd>
                        </dl>
                    </div>
                </div>
                <div class="card-dp">
                    <div class="card-dp__body">
                        <p class="small-caps mb-2">Helpful links</p>
                        <a href="<?= e(url('pricing')) ?>" class="d-block mb-1">Plans &amp; pricing</a>
                        <a href="<?= e(url('privacy')) ?>" class="d-block mb-1">Privacy policy</a>
                        <a href="<?= e(url('terms')) ?>" class="d-block">Terms of service</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
