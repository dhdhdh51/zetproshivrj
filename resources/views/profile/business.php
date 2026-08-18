<?php
/**
 * @var array $profile
 * @var bool  $onboarding
 * @var array $templates
 * @var bool  $all_templates
 * @var string|null $logo_url
 */
$value = static fn (string $key, string $default = ''): string => e((string) (old($key) !== '' ? old($key) : ($profile[$key] ?? $default)));
?>
<div class="page-head">
    <div>
        <h1><?= $onboarding ? 'Set up your business' : 'Business profile' ?></h1>
        <p>These details appear on every document you create — logo, GSTIN, bank details and default terms.</p>
    </div>
    <?php if (!$onboarding): ?>
        <a href="<?= e(url('documents/create')) ?>" class="btn-dp btn-outline-dp"><?= icon('plus', '', 17) ?> New document</a>
    <?php endif; ?>
</div>

<?php if ($onboarding): ?>
    <div class="alert-dp alert-info-dp">
        <?= icon('sparkles') ?>
        <div>
            <strong>One quick step before your first document.</strong><br>
            Only the business name is required — you can fill in the rest later.
        </div>
    </div>
<?php endif; ?>

<form method="post" action="<?= e(url('profile/business')) ?>" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>
    <?php if ($onboarding): ?><input type="hidden" name="onboarding" value="1"><?php endif; ?>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card-dp">
                <div class="card-dp__head"><h2><?= icon('building', '', 18) ?> Business identity</h2></div>
                <div class="card-dp__body">
                    <div class="form-row">
                        <label class="form-label-dp" for="business_name">Business name</label>
                        <input type="text" id="business_name" name="business_name" required
                               class="input-dp <?= has_error('business_name') ? 'is-invalid-dp' : '' ?>"
                               value="<?= $value('business_name') ?>" placeholder="Sharma Digital Studio">
                        <?php if (has_error('business_name')): ?><p class="field-error"><?= e(error_for('business_name')) ?></p><?php endif; ?>
                    </div>

                    <div class="form-grid">
                        <div>
                            <label class="form-label-dp" for="email">Business email <span class="opt">(optional)</span></label>
                            <input type="email" id="email" name="email" class="input-dp" value="<?= $value('email') ?>" placeholder="hello@studio.com">
                        </div>
                        <div>
                            <label class="form-label-dp" for="phone">Phone <span class="opt">(optional)</span></label>
                            <input type="text" id="phone" name="phone" class="input-dp" value="<?= $value('phone') ?>" placeholder="+91 98765 43210">
                        </div>
                        <div>
                            <label class="form-label-dp" for="website">Website <span class="opt">(optional)</span></label>
                            <input type="text" id="website" name="website" class="input-dp" value="<?= $value('website') ?>" placeholder="studio.com">
                        </div>
                        <div>
                            <label class="form-label-dp" for="signature_name">Signature name <span class="opt">(optional)</span></label>
                            <input type="text" id="signature_name" name="signature_name" class="input-dp" value="<?= $value('signature_name') ?>" placeholder="Priya Sharma">
                        </div>
                    </div>

                    <div class="form-section">
                        <p class="form-section__title">Address</p>
                        <div class="form-row">
                            <label class="form-label-dp" for="address">Street address</label>
                            <input type="text" id="address" name="address" class="input-dp" value="<?= $value('address') ?>" placeholder="14, MG Road">
                        </div>
                        <div class="form-grid">
                            <div>
                                <label class="form-label-dp" for="city">City</label>
                                <input type="text" id="city" name="city" class="input-dp" value="<?= $value('city') ?>">
                            </div>
                            <div>
                                <label class="form-label-dp" for="state">State</label>
                                <input type="text" id="state" name="state" class="input-dp" value="<?= $value('state') ?>">
                            </div>
                            <div>
                                <label class="form-label-dp" for="country">Country</label>
                                <input type="text" id="country" name="country" class="input-dp" value="<?= $value('country', 'India') ?>">
                            </div>
                            <div>
                                <label class="form-label-dp" for="postal_code">Postal code</label>
                                <input type="text" id="postal_code" name="postal_code" class="input-dp" value="<?= $value('postal_code') ?>">
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <p class="form-section__title">Tax details</p>
                        <p class="form-section__hint">Printed on documents exactly as entered — AI never invents or alters these.</p>
                        <div class="form-grid">
                            <div>
                                <label class="form-label-dp" for="gstin">GSTIN</label>
                                <input type="text" id="gstin" name="gstin" class="input-dp mono" value="<?= $value('gstin') ?>" placeholder="29ABCDE1234F1Z5">
                            </div>
                            <div>
                                <label class="form-label-dp" for="tax_number">Other tax number <span class="opt">(PAN / VAT)</span></label>
                                <input type="text" id="tax_number" name="tax_number" class="input-dp mono" value="<?= $value('tax_number') ?>">
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <p class="form-section__title"><?= icon('bank', '', 17) ?> Bank details</p>
                        <p class="form-section__hint">Shown in the payment section of invoices and quotations.</p>
                        <div class="form-grid">
                            <div>
                                <label class="form-label-dp" for="bank_name">Bank name</label>
                                <input type="text" id="bank_name" name="bank_name" class="input-dp" value="<?= $value('bank_name') ?>">
                            </div>
                            <div>
                                <label class="form-label-dp" for="account_name">Account name</label>
                                <input type="text" id="account_name" name="account_name" class="input-dp" value="<?= $value('account_name') ?>">
                            </div>
                            <div>
                                <label class="form-label-dp" for="account_number">Account number</label>
                                <input type="text" id="account_number" name="account_number" class="input-dp mono" value="<?= $value('account_number') ?>">
                            </div>
                            <div>
                                <label class="form-label-dp" for="ifsc">IFSC / SWIFT</label>
                                <input type="text" id="ifsc" name="ifsc" class="input-dp mono" value="<?= $value('ifsc') ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card-dp">
                <div class="card-dp__head"><h2>Document defaults</h2></div>
                <div class="card-dp__body">
                    <div class="form-grid">
                        <div>
                            <label class="form-label-dp" for="default_currency">Default currency</label>
                            <select id="default_currency" name="default_currency" class="select-dp">
                                <?php foreach (currencies() as $code => $currency): ?>
                                    <option value="<?= e($code) ?>" <?= (string) ($profile['default_currency'] ?? 'INR') === $code ? 'selected' : '' ?>>
                                        <?= e($code . ' · ' . $currency['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="form-label-dp" for="default_template">Default template</label>
                            <select id="default_template" name="default_template" class="select-dp">
                                <?php foreach ($templates as $template): ?>
                                    <option value="<?= e((string) $template['slug']) ?>"
                                        <?= (string) ($profile['default_template'] ?? 'modern') === (string) $template['slug'] ? 'selected' : '' ?>
                                        <?= (!$all_templates && (int) $template['is_basic'] !== 1) ? 'disabled' : '' ?>>
                                        <?= e((string) $template['name']) ?><?= (!$all_templates && (int) $template['is_basic'] !== 1) ? ' (paid plans)' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row mt-3">
                        <label class="form-label-dp" for="default_terms">Default terms &amp; conditions</label>
                        <textarea id="default_terms" name="default_terms" class="textarea-dp" rows="6"
                                  placeholder="1. This quotation is valid for 15 days.&#10;2. 50% advance is payable to start work."><?= e((string) (old('default_terms') !== '' ? old('default_terms') : ($profile['default_terms'] ?? ''))) ?></textarea>
                        <p class="field-hint">Pre-filled into every new document — you can always edit it per document.</p>
                    </div>

                    <div class="form-row mb-0">
                        <label class="form-label-dp" for="default_notes">Default notes</label>
                        <textarea id="default_notes" name="default_notes" class="textarea-dp" rows="3"
                                  placeholder="Thank you for your business!"><?= e((string) (old('default_notes') !== '' ? old('default_notes') : ($profile['default_notes'] ?? ''))) ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card-dp">
                <div class="card-dp__head"><h3>Business logo</h3></div>
                <div class="card-dp__body">
                    <div class="upload-drop">
                        <?php if ($logo_url !== null): ?>
                            <img src="<?= e($logo_url) ?>" alt="Business logo">
                        <?php else: ?>
                            <div class="avatar" style="width:64px;height:64px;flex:0 0 64px;border-radius:12px"><?= icon('building', '', 26) ?></div>
                        <?php endif; ?>
                        <div class="flex-grow-1">
                            <input type="file" name="logo" accept="image/jpeg,image/png,image/webp" class="input-dp" style="padding:7px">
                            <p class="field-hint mb-0">JPG, PNG or WEBP · up to <?= e((string) round(((int) config('security.upload_max_bytes', 2097152)) / 1048576, 1)) ?> MB</p>
                        </div>
                    </div>
                </div>
                <?php if ($logo_url !== null): ?>
                    <div class="card-dp__foot">
                        <button type="submit" class="btn-dp btn-ghost-dp btn-sm-dp" formaction="<?= e(url('profile/logo/delete')) ?>"
                                formnovalidate onclick="return confirm('Remove the current logo?')">
                            <?= icon('trash', '', 15) ?> Remove logo
                        </button>
                    </div>
                <?php endif; ?>
            </div>

            <div class="card-dp">
                <div class="card-dp__body">
                    <button type="submit" class="btn-dp btn-primary-dp btn-block-dp btn-lg-dp">
                        <?= icon('check', '', 17) ?> <?= $onboarding ? 'Save and continue' : 'Save business profile' ?>
                    </button>
                    <?php if (!$onboarding): ?>
                        <a href="<?= e(url('dashboard')) ?>" class="btn-dp btn-ghost-dp btn-block-dp mt-2">Cancel</a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card-dp">
                <div class="card-dp__body">
                    <p class="small-caps mb-2">Why this matters</p>
                    <p class="text-muted-2 mb-0" style="font-size:.9rem">
                        DocuPilot AI never invents business identity data. Your GSTIN, bank details, address and
                        phone number are inserted from this profile exactly as you type them here.
                    </p>
                </div>
            </div>
        </div>
    </div>
</form>
