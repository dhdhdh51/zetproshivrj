<?php
/**
 * @var array $config
 * @var bool  $configured
 * @var bool  $available
 * @var array $logs
 * @var array $stats
 */
$maskedPassword = (string) $config['password'] !== '' ? '••••••••••' : '';
?>
<div class="page-head">
    <div>
        <h1>Email settings</h1>
        <p>SMTP credentials used for verification links, password resets and document delivery.</p>
    </div>
    <span class="badge <?= $configured ? 'badge-success' : 'badge-danger' ?>"><?= $configured ? 'Configured' : 'Not configured' ?></span>
</div>

<?php if (!$available): ?>
    <div class="alert-dp alert-danger-dp">
        <?= icon('alert') ?>
        <div>PHPMailer is missing. Run <code>composer install</code> in the project root, then reload this page.</div>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card-dp">
            <div class="card-dp__head"><h2><?= icon('mail', '', 18) ?> SMTP</h2></div>
            <form method="post" action="<?= e(url('admin/email')) ?>">
                <?= csrf_field() ?>
                <div class="card-dp__body">
                    <div class="form-grid">
                        <div>
                            <label class="form-label-dp" for="smtp_host">SMTP host</label>
                            <input type="text" id="smtp_host" name="smtp_host" class="input-dp mono"
                                   value="<?= e((string) $config['host']) ?>" placeholder="smtp.hostinger.com">
                        </div>
                        <div>
                            <label class="form-label-dp" for="smtp_port">Port</label>
                            <input type="number" id="smtp_port" name="smtp_port" class="input-dp" value="<?= (int) $config['port'] ?>" required>
                            <p class="field-hint">587 for TLS, 465 for SSL.</p>
                        </div>
                        <div>
                            <label class="form-label-dp" for="smtp_username">Username</label>
                            <input type="text" id="smtp_username" name="smtp_username" class="input-dp"
                                   value="<?= e((string) $config['username']) ?>" autocomplete="off">
                        </div>
                        <div>
                            <label class="form-label-dp" for="smtp_password">Password</label>
                            <input type="password" id="smtp_password" name="smtp_password" class="input-dp"
                                   value="<?= e($maskedPassword) ?>" autocomplete="new-password">
                            <p class="field-hint">Leave the masked value to keep the stored password.</p>
                        </div>
                        <div>
                            <label class="form-label-dp" for="smtp_encryption">Encryption</label>
                            <select id="smtp_encryption" name="smtp_encryption" class="select-dp">
                                <?php foreach (['tls' => 'TLS (STARTTLS)', 'ssl' => 'SSL', 'none' => 'None'] as $value => $label): ?>
                                    <option value="<?= e($value) ?>" <?= (string) $config['encryption'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="form-label-dp" for="smtp_from_email">From email</label>
                            <input type="email" id="smtp_from_email" name="smtp_from_email" class="input-dp"
                                   value="<?= e((string) $config['from_email']) ?>" placeholder="documents@yourdomain.com">
                        </div>
                        <div>
                            <label class="form-label-dp" for="smtp_from_name">From name</label>
                            <input type="text" id="smtp_from_name" name="smtp_from_name" class="input-dp"
                                   value="<?= e((string) $config['from_name']) ?>" required>
                        </div>
                    </div>
                </div>
                <div class="card-dp__foot">
                    <button type="submit" class="btn-dp btn-primary-dp"><?= icon('check', '', 17) ?> Save email settings</button>
                </div>
            </form>
        </div>

        <div class="card-dp">
            <div class="card-dp__head"><h3>Send a test email</h3></div>
            <form method="post" action="<?= e(url('admin/email/test')) ?>">
                <?= csrf_field() ?>
                <div class="card-dp__body">
                    <label class="form-label-dp" for="test_email">Recipient</label>
                    <div class="d-flex gap-2">
                        <input type="email" id="test_email" name="test_email" class="input-dp" required
                               value="<?= e((string) (auth_user()['email'] ?? '')) ?>">
                        <button type="submit" class="btn-dp btn-outline-dp"><?= icon('send', '', 16) ?> Send test</button>
                    </div>
                    <p class="field-hint mb-0">The result (including the SMTP error, if any) is reported back and written to the email log.</p>
                </div>
            </form>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card-dp">
            <div class="card-dp__head"><h3>Delivery stats</h3></div>
            <div class="card-dp__body">
                <dl class="kv mb-0">
                    <dt>Total attempts</dt><dd><?= number_format((int) $stats['total']) ?></dd>
                    <dt>Delivered</dt><dd><?= number_format((int) $stats['sent']) ?></dd>
                    <dt>Failed</dt><dd><?= number_format((int) $stats['failed']) ?></dd>
                </dl>
            </div>
        </div>

        <div class="card-dp">
            <div class="card-dp__head"><h3>Recent email log</h3></div>
            <?php if ($logs === []): ?>
                <div class="card-dp__body text-muted-2">No emails sent yet.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table-dp">
                        <thead><tr><th>Recipient</th><th>Type</th><th>Status</th><th class="num">When</th></tr></thead>
                        <tbody>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td style="font-size:.84rem">
                                    <?= e(str_excerpt((string) $log['to_email'], 24)) ?>
                                    <?php if ((string) $log['status'] === 'failed' && !empty($log['error_message'])): ?>
                                        <div style="color:var(--dp-danger);font-size:.74rem"><?= e(str_excerpt((string) $log['error_message'], 44)) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge badge-muted"><?= e((string) $log['type']) ?></span></td>
                                <td><span class="<?= status_class((string) $log['status'] === 'sent' ? 'sent' : 'failed') ?>"><?= e((string) $log['status']) ?></span></td>
                                <td class="num text-muted-2" style="font-size:.78rem"><?= e(format_date((string) $log['created_at'], 'd M H:i')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
