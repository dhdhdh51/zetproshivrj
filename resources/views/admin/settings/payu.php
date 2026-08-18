<?php
/**
 * @var array $config
 * @var bool  $configured
 * @var string $success_url
 * @var string $failure_url
 */
$maskedKey = (string) $config['merchant_key'] !== '' ? '••••••' . substr((string) $config['merchant_key'], -4) : '';
$maskedSalt = (string) $config['merchant_salt'] !== '' ? '••••••••••••' : '';
?>
<div class="page-head">
    <div>
        <h1>PayU settings</h1>
        <p>Subscription payments are processed by PayU. Credentials are stored server-side and never rendered in full.</p>
    </div>
    <div class="d-flex gap-2">
        <span class="badge <?= $configured ? 'badge-success' : 'badge-danger' ?>"><?= $configured ? 'Configured' : 'Not configured' ?></span>
        <span class="badge <?= (string) $config['mode'] === 'live' ? 'badge-success' : 'badge-warning' ?>"><?= e((string) $config['mode']) ?> mode</span>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card-dp">
            <div class="card-dp__head"><h2><?= icon('bank', '', 18) ?> Merchant credentials</h2></div>
            <form method="post" action="<?= e(url('admin/payu')) ?>">
                <?= csrf_field() ?>
                <div class="card-dp__body">
                    <div class="form-grid">
                        <div>
                            <label class="form-label-dp" for="payu_mode">Mode</label>
                            <select id="payu_mode" name="payu_mode" class="select-dp">
                                <option value="test" <?= (string) $config['mode'] === 'test' ? 'selected' : '' ?>>Test (test.payu.in)</option>
                                <option value="live" <?= (string) $config['mode'] === 'live' ? 'selected' : '' ?>>Live (secure.payu.in)</option>
                            </select>
                            <p class="field-hint">Switching mode updates the payment URL automatically if you leave it blank.</p>
                        </div>
                        <div>
                            <label class="form-label-dp" for="payu_base_url">Payment URL</label>
                            <input type="text" id="payu_base_url" name="payu_base_url" class="input-dp mono"
                                   value="<?= e((string) $config['base_url']) ?>">
                        </div>
                        <div>
                            <label class="form-label-dp" for="payu_merchant_key">Merchant key</label>
                            <input type="text" id="payu_merchant_key" name="payu_merchant_key" class="input-dp mono"
                                   value="<?= e($maskedKey) ?>" autocomplete="off">
                        </div>
                        <div>
                            <label class="form-label-dp" for="payu_merchant_salt">Merchant salt</label>
                            <input type="password" id="payu_merchant_salt" name="payu_merchant_salt" class="input-dp mono"
                                   value="<?= e($maskedSalt) ?>" autocomplete="new-password">
                            <p class="field-hint">Leave masked values untouched to keep the stored credentials.</p>
                        </div>
                    </div>
                </div>
                <div class="card-dp__foot">
                    <button type="submit" class="btn-dp btn-primary-dp"><?= icon('check', '', 17) ?> Save PayU settings</button>
                </div>
            </form>
        </div>

        <div class="card-dp">
            <div class="card-dp__head"><h3>Callback URLs</h3></div>
            <div class="card-dp__body">
                <p class="text-muted-2" style="font-size:.9rem">Add these to your PayU dashboard as the success and failure URLs.</p>
                <label class="form-label-dp">Success URL</label>
                <div class="d-flex gap-2 mb-3">
                    <input type="text" class="input-dp mono" readonly value="<?= e($success_url) ?>" style="font-size:.8rem">
                    <button type="button" class="btn-dp btn-outline-dp" data-copy="<?= e($success_url) ?>"><?= icon('copy', '', 16) ?></button>
                </div>
                <label class="form-label-dp">Failure URL</label>
                <div class="d-flex gap-2">
                    <input type="text" class="input-dp mono" readonly value="<?= e($failure_url) ?>" style="font-size:.8rem">
                    <button type="button" class="btn-dp btn-outline-dp" data-copy="<?= e($failure_url) ?>"><?= icon('copy', '', 16) ?></button>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card-dp">
            <div class="card-dp__head"><h3>How verification works</h3></div>
            <div class="card-dp__body">
                <ol class="ps-3 mb-0" style="font-size:.9rem">
                    <li class="mb-2">A pending payment row is created with a unique reference before the user leaves for PayU.</li>
                    <li class="mb-2">The request hash is built as <span class="mono">sha512(key|txnid|amount|productinfo|firstname|email|udf1…udf5||||||salt)</span>.</li>
                    <li class="mb-2">On callback, the response hash is recomputed in reverse order and compared with <span class="mono">hash_equals</span>.</li>
                    <li class="mb-2">The posted amount must match the stored amount to the paisa.</li>
                    <li class="mb-2">PayU's <span class="mono">verify_payment</span> API is then queried server-to-server.</li>
                    <li>Only after all of that does the subscription become active and limits increase.</li>
                </ol>
            </div>
        </div>

        <div class="card-dp">
            <div class="card-dp__body">
                <p class="small-caps mb-2">Test mode tips</p>
                <p class="text-muted-2 mb-0" style="font-size:.88rem">
                    In test mode use PayU's sandbox credentials and test cards from their documentation.
                    Successful test payments activate real subscriptions in this installation, so use a test account.
                </p>
            </div>
        </div>
    </div>
</div>
