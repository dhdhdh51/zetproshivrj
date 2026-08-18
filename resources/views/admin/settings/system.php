<?php
/**
 * @var array $values
 * @var string|null $logo_url
 * @var string $app_url
 * @var string $detected_url
 */
?>
<div class="page-head">
    <div>
        <h1>System settings</h1>
        <p>Site identity, registration, AI availability and maintenance mode.</p>
    </div>
</div>

<form method="post" action="<?= e(url('admin/settings')) ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card-dp">
                <div class="card-dp__head"><h2>Site identity</h2></div>
                <div class="card-dp__body">
                    <div class="form-grid">
                        <div>
                            <label class="form-label-dp" for="site_name">Site name</label>
                            <input type="text" id="site_name" name="site_name" class="input-dp" required
                                   value="<?= e((string) $values['site_name']) ?>">
                            <p class="field-hint">Used in the UI, page titles and outgoing emails.</p>
                        </div>
                        <div>
                            <label class="form-label-dp" for="contact_email">Contact email</label>
                            <input type="email" id="contact_email" name="contact_email" class="input-dp"
                                   value="<?= e((string) $values['contact_email']) ?>">
                            <p class="field-hint">Where the public contact form delivers messages.</p>
                        </div>
                        <div>
                            <label class="form-label-dp" for="default_currency">Default currency</label>
                            <select id="default_currency" name="default_currency" class="select-dp">
                                <?php foreach (currencies() as $code => $currency): ?>
                                    <option value="<?= e($code) ?>" <?= (string) $values['default_currency'] === $code ? 'selected' : '' ?>>
                                        <?= e($code . ' · ' . $currency['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-section">
                        <p class="form-section__title">Site logo</p>
                        <div class="upload-drop">
                            <?php if ($logo_url !== null): ?>
                                <img src="<?= e($logo_url) ?>" alt="Site logo">
                            <?php else: ?>
                                <div class="avatar" style="width:64px;height:64px;flex:0 0 64px;border-radius:12px"><?= icon('layers', '', 24) ?></div>
                            <?php endif; ?>
                            <div class="flex-grow-1">
                                <input type="file" name="site_logo" accept="image/jpeg,image/png,image/webp" class="input-dp" style="padding:7px">
                                <p class="field-hint mb-0">JPG, PNG or WEBP. Shown in the sidebar, marketing header and emails.</p>
                            </div>
                        </div>
                        <?php if ($logo_url !== null): ?>
                            <button type="submit" class="btn-dp btn-ghost-dp btn-sm-dp mt-2" formaction="<?= e(url('admin/settings/logo/delete')) ?>"
                                    formnovalidate onclick="return confirm('Remove the site logo?')">
                                <?= icon('trash', '', 15) ?> Remove logo
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="card-dp">
                <div class="card-dp__head"><h2>Access &amp; features</h2></div>
                <div class="card-dp__body">
                    <div class="switch-row">
                        <div class="switch-row__text">
                            <strong>Registration enabled</strong>
                            <span>Allow new users to sign up (email or Google).</span>
                        </div>
                        <label class="switch">
                            <input type="checkbox" name="registration_enabled" value="1" <?= $values['registration_enabled'] ? 'checked' : '' ?>><span></span>
                        </label>
                    </div>

                    <div class="switch-row">
                        <div class="switch-row__text">
                            <strong>Require email verification</strong>
                            <span>Users must confirm their email before creating documents. Needs working SMTP.</span>
                        </div>
                        <label class="switch">
                            <input type="checkbox" name="require_email_verification" value="1" <?= $values['require_email_verification'] ? 'checked' : '' ?>><span></span>
                        </label>
                    </div>

                    <div class="switch-row">
                        <div class="switch-row__text">
                            <strong>AI enabled</strong>
                            <span>Master switch for every AI feature (same setting as AI settings).</span>
                        </div>
                        <label class="switch">
                            <input type="checkbox" name="ai_enabled" value="1" <?= $values['ai_enabled'] ? 'checked' : '' ?>><span></span>
                        </label>
                    </div>

                    <div class="switch-row">
                        <div class="switch-row__text">
                            <strong>Maintenance mode</strong>
                            <span>Show a maintenance page to everyone except administrators.</span>
                        </div>
                        <label class="switch">
                            <input type="checkbox" name="maintenance_mode" value="1" <?= $values['maintenance_mode'] ? 'checked' : '' ?>><span></span>
                        </label>
                    </div>
                </div>
                <div class="card-dp__foot">
                    <button type="submit" class="btn-dp btn-primary-dp"><?= icon('check', '', 17) ?> Save system settings</button>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card-dp">
                <div class="card-dp__head"><h3>Installation</h3></div>
                <div class="card-dp__body">
                    <dl class="kv mb-0">
                        <dt>Configured URL</dt><dd class="break-all mono" style="font-size:.82rem"><?= e($app_url) ?></dd>
                        <dt>Detected URL</dt><dd class="break-all mono" style="font-size:.82rem"><?= e($detected_url) ?></dd>
                        <dt>PHP version</dt><dd><?= e(PHP_VERSION) ?></dd>
                        <dt>Uploads dir</dt>
                        <dd><?= is_writable(storage_path('uploads')) ? 'Writable' : '<span style="color:var(--dp-danger)">Not writable</span>' ?></dd>
                        <dt>Generated dir</dt>
                        <dd><?= is_writable(storage_path('generated')) ? 'Writable' : '<span style="color:var(--dp-danger)">Not writable</span>' ?></dd>
                        <dt>Logs dir</dt>
                        <dd><?= is_writable(storage_path('logs')) ? 'Writable' : '<span style="color:var(--dp-danger)">Not writable</span>' ?></dd>
                    </dl>
                    <p class="field-hint mt-2 mb-0">
                        Database credentials, Google OAuth keys and the app URL live in <code>config/config.php</code>.
                    </p>
                </div>
            </div>

            <div class="card-dp">
                <div class="card-dp__head"><h3>Quick links</h3></div>
                <div class="card-dp__body d-grid gap-2">
                    <a href="<?= e(url('admin/ai')) ?>" class="btn-dp btn-outline-dp justify-content-start"><?= icon('sparkles', '', 16) ?> AI settings</a>
                    <a href="<?= e(url('admin/email')) ?>" class="btn-dp btn-outline-dp justify-content-start"><?= icon('mail', '', 16) ?> Email settings</a>
                    <a href="<?= e(url('admin/payu')) ?>" class="btn-dp btn-outline-dp justify-content-start"><?= icon('bank', '', 16) ?> PayU settings</a>
                    <a href="<?= e(url('admin/templates')) ?>" class="btn-dp btn-outline-dp justify-content-start"><?= icon('palette', '', 16) ?> Templates</a>
                    <a href="<?= e(url('health')) ?>" target="_blank" rel="noopener" class="btn-dp btn-ghost-dp justify-content-start">
                        <?= icon('activity', '', 16) ?> Health check JSON
                    </a>
                </div>
            </div>
        </div>
    </div>
</form>
