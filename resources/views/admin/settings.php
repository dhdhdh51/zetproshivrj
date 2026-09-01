<?php
/** @var array $stats */
?>

<div class="page-head">
    <div class="grow">
        <h1>Settings</h1>
        <div class="subtitle">
            Runtime configuration. These values override config/config.php and take effect immediately,
            including on Android devices at their next sync.
        </div>
    </div>
</div>

<form method="post" action="<?= e(url('/admin/settings')) ?>">
    <?= csrf_field() ?>

    <div class="grid grid-2">
        <div class="card">
            <div class="card-head"><h2>General</h2></div>
            <div class="card-body">
                <div class="field">
                    <label for="site_name">Application name</label>
                    <input type="text" id="site_name" name="site_name" value="<?= e(setting('site_name', 'LRMS')) ?>" maxlength="60">
                </div>
                <div class="field">
                    <label for="organisation_name">Organisation name</label>
                    <input type="text" id="organisation_name" name="organisation_name"
                           value="<?= e(setting('organisation_name', '')) ?>" maxlength="160">
                    <div class="help">Printed on every exported report.</div>
                </div>
                <div class="field" style="max-width:220px">
                    <label for="default_locale">Default language</label>
                    <select id="default_locale" name="default_locale">
                        <?php foreach (locale_names() as $code => $name): ?>
                            <option value="<?= e($code) ?>" <?= setting('default_locale', 'en') === $code ? 'selected' : '' ?>>
                                <?= e($name) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="help">
                        The language the panel opens in. Anyone can still switch it themselves from the
                        top bar, and that choice is remembered on their browser.
                    </div>
                </div>
                <div class="field" style="max-width:220px">
                    <label for="supervisor_offline_minutes">Offline after (minutes)</label>
                    <input type="number" id="supervisor_offline_minutes" name="supervisor_offline_minutes" min="1" max="720"
                           value="<?= (int) setting('supervisor_offline_minutes', 15) ?>">
                    <div class="help">How long without contact before a device is shown as offline.</div>
                </div>
                <div class="check">
                    <input type="checkbox" id="maintenance_mode" name="maintenance_mode" value="1" <?= setting('maintenance_mode') === '1' ? 'checked' : '' ?>>
                    <label for="maintenance_mode">Maintenance mode (BC Supervisor sign-in stays open)</label>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h2>Updating this site</h2></div>
            <div class="card-body">
                <p class="muted">
                    After uploading a newer version of the application, the database needs the
                    tables and columns that version added. This does it from the browser, adds only
                    what is missing and deletes nothing.
                </p>
                <p class="muted">
                    <strong>Do not delete the files and run <code>install.php</code> again.</strong>
                    The installer builds an empty system and drops every table first — every loan
                    account, visit and photograph on this site goes with them.
                </p>
                <a class="btn btn-secondary" href="<?= e(url('/admin/settings/upgrade')) ?>">
                    <?= icon('database', '', 15) ?> Update the database
                </a>
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h2>Office on printed forms</h2></div>
            <div class="card-body">
                <p class="help" style="margin-top:0">
                    The letterhead printed at the foot of the BCA inspection report, so a
                    printed copy carries the office it belongs to. Leave a line blank to leave
                    it off the page.
                </p>
                <div class="field">
                    <label for="office_name">Office</label>
                    <input type="text" id="office_name" name="office_name"
                           value="<?= e(setting('office_name', '')) ?>" maxlength="160">
                </div>
                <div class="field">
                    <label for="office_address">Address</label>
                    <input type="text" id="office_address" name="office_address"
                           value="<?= e(setting('office_address', '')) ?>" maxlength="255">
                </div>
                <div class="field">
                    <label for="office_phone">Phone number</label>
                    <input type="text" id="office_phone" name="office_phone"
                           value="<?= e(setting('office_phone', '')) ?>" maxlength="60">
                </div>
                <div class="field">
                    <label for="office_email">Email</label>
                    <input type="text" id="office_email" name="office_email"
                           value="<?= e(setting('office_email', '')) ?>" maxlength="120">
                </div>
                <div class="field">
                    <label for="office_helpline">Toll free helpline</label>
                    <input type="text" id="office_helpline" name="office_helpline"
                           value="<?= e(setting('office_helpline', '')) ?>" maxlength="60">
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h2>Field evidence</h2></div>
            <div class="card-body">
                <div class="form-grid">
                    <div class="field">
                        <label for="min_visit_photos">Minimum visit photographs</label>
                        <input type="number" id="min_visit_photos" name="min_visit_photos" min="0" max="10"
                               value="<?= (int) setting('min_visit_photos', 1) ?>">
                    </div>
                    <div class="field">
                        <label for="min_inspection_photos">Minimum inspection photographs</label>
                        <input type="number" id="min_inspection_photos" name="min_inspection_photos" min="0" max="10"
                               value="<?= (int) setting('min_inspection_photos', 1) ?>">
                    </div>
                    <div class="field">
                        <label for="sss_backdate_days">SSS backdating window (days)</label>
                        <input type="number" id="sss_backdate_days" name="sss_backdate_days" min="1" max="365"
                               value="<?= (int) setting('sss_backdate_days', 30) ?>">
                        <div class="help">
                            How far back the app may record scheme enrolments. It needs to be wider than a
                            day: a supervisor out of signal reports the day they collected the figures, not
                            the day the phone finally reached the server. Never allows a future date.
                        </div>
                    </div>
                </div>
                <div class="field">
                    <label for="payment_modes">Payment modes</label>
                    <input type="text" id="payment_modes" name="payment_modes"
                           value="<?= e(setting('payment_modes', 'UPI,Bank Transfer,Cheque,Other')) ?>">
                    <div class="help">Comma separated; offered when recording recovery.</div>
                </div>
                <div class="check">
                    <input type="checkbox" id="watermark_photos" name="watermark_photos" value="1" <?= setting('watermark_photos') === '1' ? 'checked' : '' ?>>
                    <label for="watermark_photos">Burn name, time and coordinates into photographs</label>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h2>GPS validation</h2></div>
            <div class="card-body">
                <div class="form-grid">
                    <div class="field">
                        <label for="gps_max_accuracy_metres">Reject worse accuracy than (m)</label>
                        <input type="number" id="gps_max_accuracy_metres" name="gps_max_accuracy_metres" min="0" max="5000"
                               value="<?= (int) setting('gps_max_accuracy_metres', 200) ?>">
                        <div class="help">0 disables the check.</div>
                    </div>
                    <div class="field">
                        <label for="gps_max_drift_metres">Max distance from branch (m)</label>
                        <input type="number" id="gps_max_drift_metres" name="gps_max_drift_metres" min="0" max="200000"
                               value="<?= (int) setting('gps_max_drift_metres', 0) ?>">
                        <div class="help">0 disables it. Needs branch coordinates to be set.</div>
                    </div>
                </div>
                <div class="check">
                    <input type="checkbox" id="gps_mock_location_allowed" name="gps_mock_location_allowed" value="1"
                           <?= setting('gps_mock_location_allowed') === '1' ? 'checked' : '' ?>>
                    <label for="gps_mock_location_allowed">Accept mock locations (not recommended)</label>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h2>Security</h2></div>
            <div class="card-body">
                <div class="check">
                    <input type="checkbox" id="otp_web_login" name="otp_web_login" value="1" <?= setting('otp_web_login') === '1' ? 'checked' : '' ?>>
                    <label for="otp_web_login">Require OTP for web sign-in (BC Supervisor, Branch Manager)</label>
                </div>
                <div class="check">
                    <input type="checkbox" id="otp_app_login" name="otp_app_login" value="1" <?= setting('otp_app_login') === '1' ? 'checked' : '' ?>>
                    <label for="otp_app_login">Require OTP for Android sign-in</label>
                </div>
                <div class="check">
                    <input type="checkbox" id="device_binding" name="device_binding" value="1" <?= setting('device_binding') === '1' ? 'checked' : '' ?>>
                    <label for="device_binding">Bind each BCA account to one device</label>
                </div>
                <div class="field" style="max-width:220px">
                    <label for="api_token_ttl_days">App session length (days)</label>
                    <input type="number" id="api_token_ttl_days" name="api_token_ttl_days" min="1" max="365"
                           value="<?= (int) setting('api_token_ttl_days', 30) ?>">
                </div>
                <p class="help" style="margin:0">
                    OTP delivery needs an SMS gateway below; without one, codes are written to the
                    application log so a staging environment still works.
                </p>
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h2>SMS gateway (OTP)</h2></div>
            <div class="card-body">
                <div class="check">
                    <input type="checkbox" id="sms_enabled" name="sms_enabled" value="1" <?= setting('sms_enabled') === '1' ? 'checked' : '' ?>>
                    <label for="sms_enabled">Send OTP by SMS</label>
                </div>
                <div class="field">
                    <label for="sms_endpoint">Gateway URL</label>
                    <input type="text" id="sms_endpoint" name="sms_endpoint" value="<?= e(setting('sms_endpoint', '')) ?>"
                           placeholder="https://gateway.example/send?key={api_key}&to={mobile}&text={message}">
                    <div class="help">Placeholders: {mobile} {message} {otp} {sender} {api_key}</div>
                </div>
                <div class="form-grid">
                    <div class="field">
                        <label for="sms_sender_id">Sender ID</label>
                        <input type="text" id="sms_sender_id" name="sms_sender_id" value="<?= e(setting('sms_sender_id', '')) ?>" maxlength="20">
                    </div>
                    <div class="field">
                        <label for="sms_api_key">API key</label>
                        <input type="password" id="sms_api_key" name="sms_api_key" placeholder="<?= setting('sms_api_key') ? '•••••• (unchanged)' : 'not set' ?>">
                        <div class="help">Leave blank to keep the current key.</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h2>System</h2></div>
            <div class="card-body">
                <div class="kv">
                    <div><div class="k">PHP</div><div class="v"><?= e($stats['php']) ?></div></div>
                    <div><div class="k">Database</div><div class="v"><?= e($stats['database']) ?></div></div>
                    <div><div class="k">Server time</div><div class="v"><?= e($stats['server_time']) ?></div></div>
                    <div><div class="k">Timezone</div><div class="v"><?= e($stats['timezone']) ?></div></div>
                    <div><div class="k">GD (photos)</div><div class="v"><?= $stats['gd'] ? 'available' : 'MISSING' ?></div></div>
                    <div><div class="k">Zip (Excel)</div><div class="v"><?= $stats['zip'] ? 'available' : 'MISSING' ?></div></div>
                    <div><div class="k">Storage writable</div><div class="v"><?= $stats['storage_writable'] ? 'yes' : 'NO' ?></div></div>
                    <div><div class="k">Loan accounts</div><div class="v"><?= number_format($stats['accounts']) ?></div></div>
                    <div><div class="k">Visits</div><div class="v"><?= number_format($stats['visits']) ?></div></div>
                    <div><div class="k">Inspections</div><div class="v"><?= number_format($stats['inspections']) ?></div></div>
                    <div><div class="k">Photographs</div><div class="v"><?= number_format($stats['photos']) ?></div></div>
                    <div><div class="k">Audit entries</div><div class="v"><?= number_format($stats['audit_rows']) ?></div></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-foot">
            <button type="submit" class="btn"><?= icon('check', '', 15) ?> Save settings</button>
            <span class="small muted">Every change is written to the audit log.</span>
        </div>
    </div>
</form>
